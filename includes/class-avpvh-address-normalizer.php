<?php
defined('ABSPATH') || exit;

/**
 * Pure address-normalization logic — no DB access. One definition of "the
 * same address" for every place that compares addresses (household check,
 * member merge, profile save), replacing the slightly different inline
 * comparisons each of those had.
 *
 * Display values are only tidied (whitespace, Dutch postcode spacing); the
 * folded match key is for comparison only and is never stored.
 */
class AVPVH_Address_Normalizer {

    /** Lowercase, accent-free, punctuation as spaces, whitespace collapsed. */
    public static function fold(string $value): string {
        $value = trim((string) preg_replace('/\s+/u', ' ', $value));
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        }
        $value = mb_strtolower($value, 'UTF-8');
        $value = (string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value);
        return trim((string) preg_replace('/\s+/u', ' ', $value));
    }

    /** "1234ab" / "1234  AB" -> "1234 AB"; anything else only trimmed. */
    public static function normalize_postal_code(string $postal_code): string {
        $compact = strtoupper((string) preg_replace('/\s+/u', '', $postal_code));
        if (preg_match('/^(\d{4})([A-Z]{2})$/', $compact, $m)) {
            return $m[1] . ' ' . $m[2];
        }
        return trim($postal_code);
    }

    /**
     * Address fields with surrounding/repeated whitespace removed, a Dutch
     * postcode written as "1234 AB", and the house number split into number
     * and addition (see split_house_number()): an addition typed into the
     * number field ("40-1 hoog", or a caller that only knows one field)
     * moves to house_number_addition. Keys not given are left out.
     */
    public static function clean(array $address): array {
        $clean = [];
        foreach (['street', 'house_number', 'house_number_addition', 'postal_code', 'city', 'country'] as $field) {
            if (array_key_exists($field, $address)) {
                $clean[$field] = trim((string) preg_replace('/\s+/u', ' ', (string) $address[$field]));
            }
        }
        if (isset($clean['house_number'])) {
            [$number, $addition] = self::split_house_number($clean['house_number'] . ' ' . ($clean['house_number_addition'] ?? ''));
            $clean['house_number'] = $number;
            $clean['house_number_addition'] = $addition;
        } elseif (isset($clean['house_number_addition'])) {
            $clean['house_number_addition'] = self::normalize_addition($clean['house_number_addition']);
        }
        $dutch = self::fold($clean['country'] ?? '') === '' || self::fold($clean['country'] ?? '') === 'nederland';
        if (isset($clean['postal_code']) && $dutch) {
            $clean['postal_code'] = self::normalize_postal_code($clean['postal_code']);
        }
        return array_merge($address, $clean);
    }

    /**
     * "40-1 hoog" -> ['40', '1'], "19a" -> ['19', 'A'], "40" -> ['40', ''].
     * Without leading digits ("ong.") the whole value stays the number, so
     * nothing is lost; leading zeros are dropped ("040" -> "40").
     */
    public static function split_house_number(string $house_number): array {
        $house_number = trim((string) preg_replace('/\s+/u', ' ', $house_number));
        if (!preg_match('/^0*(\d+)\s*[-\/.,]?\s*(.*)$/u', $house_number, $m)) {
            return [$house_number, ''];
        }
        return [$m[1], self::normalize_addition($m[2])];
    }

    /**
     * An addition in one stored form, uppercase as in the BAG: floor
     * notations ("1 hoog", "1e", "1-hg", "I", "01") become the digit, "huis",
     * "hs" and "bg" (ground floor) become "H", letters and words are kept
     * ("a" -> "A", "bis" -> "BIS").
     */
    public static function normalize_addition(string $addition): string {
        $folded = str_replace(' ', '', self::fold($addition));
        $floor = '(?:hoog|hg|h|e|ste|de|etage|verdieping)?';
        $romans = ['i' => '1', 'ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5', 'vi' => '6'];
        if (preg_match('/^0*(\d+)' . $floor . '$/', $folded, $a)) {
            return $a[1];
        }
        if (preg_match('/^(i|ii|iii|iv|v|vi)' . $floor . '$/', $folded, $a)) {
            return $romans[$a[1]];
        }
        if (in_array($folded, ['huis', 'hs', 'bg', 'beg', 'beganegrond', 'parterre'], true)) {
            return 'H';
        }
        return mb_strtoupper($folded, 'UTF-8');
    }

    /** "19" + "A" -> "19A"; "40" + "1" -> "40-1"; "7" + "BIS" -> "7-BIS". */
    public static function format_house_number(string $number, string $addition): string {
        if ($addition === '') {
            return $number;
        }
        return preg_match('/^\p{L}$/u', $addition) ? $number . $addition : $number . '-' . $addition;
    }

    /** Street and house number of an address row, e.g. "Kerkstraat 40-1". */
    public static function format_street_line(object|array $address): string {
        $address = (array) $address;
        return trim(($address['street'] ?? '') . ' ' . self::format_house_number(
            (string) ($address['house_number'] ?? ''),
            (string) ($address['house_number_addition'] ?? '')
        ));
    }

    /**
     * House number as "number-addition" for comparison: "40 1", "40-1",
     * "40 1 hoog", "40-I" and "40 1e" all give "40-1"; "12 A" and "12a" give
     * "12-a". The number and addition stay apart, so "40-1" never equals
     * "401". "huis", "hs" and "bg" (ground floor) fold to "h". $addition is
     * the separate field; an addition still inside $house_number counts too.
     */
    public static function normalize_house_number(string $house_number, string $addition = ''): string {
        [$number, $split_addition] = self::split_house_number($house_number . ' ' . $addition);
        if (!preg_match('/^\d+$/', $number)) {
            return str_replace(' ', '', self::fold($number));
        }
        return $split_addition === '' ? $number : $number . '-' . mb_strtolower($split_addition, 'UTF-8');
    }

    /**
     * Comparison key. With a Dutch postcode, "postcode|number": postcode and
     * house number pin the address down, so street spellings ("3e
     * Weteringdwarsstr" vs "...straat") don't matter. Otherwise
     * "postcode|number|street", with spaces dropped inside the postcode and
     * street. City and country are left out in both cases: city is often
     * missing or spelled differently ("Den Bosch", "A'dam"). Returns '' when
     * there is no street, so empty addresses never match.
     */
    public static function match_key(array|object $address): string {
        $address = (array) $address;
        $part = static fn(string $field): string =>
            str_replace(' ', '', self::fold((string) ($address[$field] ?? '')));
        if ($part('street') === '') {
            return '';
        }
        $number = self::normalize_house_number((string) ($address['house_number'] ?? ''), (string) ($address['house_number_addition'] ?? ''));
        if (preg_match('/^\d{4}[a-z]{2}$/', $part('postal_code')) && $number !== '') {
            return $part('postal_code') . '|' . $number;
        }
        return $part('postal_code') . '|' . $number . '|' . $part('street');
    }
}
