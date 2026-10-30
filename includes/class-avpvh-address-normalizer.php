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
     * Address fields with surrounding/repeated whitespace removed and a Dutch
     * postcode written as "1234 AB". Keys not given are left out.
     */
    public static function clean(array $address): array {
        $clean = [];
        foreach (['street', 'house_number', 'postal_code', 'city', 'country'] as $field) {
            if (array_key_exists($field, $address)) {
                $clean[$field] = trim((string) preg_replace('/\s+/u', ' ', (string) $address[$field]));
            }
        }
        $dutch = self::fold($clean['country'] ?? '') === '' || self::fold($clean['country'] ?? '') === 'nederland';
        if (isset($clean['postal_code']) && $dutch) {
            $clean['postal_code'] = self::normalize_postal_code($clean['postal_code']);
        }
        return array_merge($address, $clean);
    }

    /**
     * House number as "number-addition" for comparison: "40 1", "40-1",
     * "40 1 hoog", "40-I" and "40 1e" all give "40-1"; "12 A" and "12a" give
     * "12-a". The number and addition stay apart, so "40-1" never equals
     * "401". "huis", "hs" and "bg" (ground floor) fold to "h".
     */
    public static function normalize_house_number(string $house_number): string {
        $folded = self::fold($house_number);
        if (!preg_match('/^0*(\d+)\s*(.*)$/', $folded, $m)) {
            return str_replace(' ', '', $folded);
        }
        $number = $m[1];
        $addition = str_replace(' ', '', $m[2]);
        $floor = '(?:hoog|hg|h|e|ste|de|etage|verdieping)?';
        $romans = ['i' => '1', 'ii' => '2', 'iii' => '3', 'iv' => '4', 'v' => '5', 'vi' => '6'];
        if (preg_match('/^0*(\d+)' . $floor . '$/', $addition, $a)) {
            $addition = $a[1];
        } elseif (preg_match('/^(i|ii|iii|iv|v|vi)' . $floor . '$/', $addition, $a)) {
            $addition = $romans[$a[1]];
        } elseif (in_array($addition, ['huis', 'hs', 'bg', 'beg', 'beganegrond', 'parterre'], true)) {
            $addition = 'h';
        }
        return $addition === '' ? $number : $number . '-' . $addition;
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
        $number = self::normalize_house_number((string) ($address['house_number'] ?? ''));
        if (preg_match('/^\d{4}[a-z]{2}$/', $part('postal_code')) && $number !== '') {
            return $part('postal_code') . '|' . $number;
        }
        return $part('postal_code') . '|' . $number . '|' . $part('street');
    }
}
