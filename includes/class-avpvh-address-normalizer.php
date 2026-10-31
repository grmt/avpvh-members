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

    /** Folded spellings of a country => the one name stored. */
    const COUNTRIES = [
        'nederland' => 'Nederland', 'nl' => 'Nederland', 'netherlands' => 'Nederland', 'the netherlands' => 'Nederland', 'holland' => 'Nederland',
        'belgie' => 'België', 'belgium' => 'België', 'belgique' => 'België', 'belgien' => 'België', 'be' => 'België',
        'luxemburg' => 'Luxemburg', 'luxembourg' => 'Luxemburg', 'lu' => 'Luxemburg',
        'duitsland' => 'Duitsland', 'germany' => 'Duitsland', 'deutschland' => 'Duitsland', 'de' => 'Duitsland',
        'frankrijk' => 'Frankrijk', 'france' => 'Frankrijk', 'fr' => 'Frankrijk',
        'italie' => 'Italië', 'italy' => 'Italië', 'italia' => 'Italië', 'it' => 'Italië',
        'spanje' => 'Spanje', 'spain' => 'Spanje', 'espana' => 'Spanje', 'es' => 'Spanje',
        'zweden' => 'Zweden', 'sweden' => 'Zweden', 'sverige' => 'Zweden', 'se' => 'Zweden',
        'polen' => 'Polen', 'poland' => 'Polen', 'polska' => 'Polen', 'pl' => 'Polen',
        'roemenie' => 'Roemenië', 'romania' => 'Roemenië', 'ro' => 'Roemenië',
        'zwitserland' => 'Zwitserland', 'switzerland' => 'Zwitserland', 'schweiz' => 'Zwitserland', 'suisse' => 'Zwitserland', 'ch' => 'Zwitserland',
        'oostenrijk' => 'Oostenrijk', 'austria' => 'Oostenrijk', 'osterreich' => 'Oostenrijk', 'at' => 'Oostenrijk',
        'denemarken' => 'Denemarken', 'denmark' => 'Denemarken', 'danmark' => 'Denemarken', 'dk' => 'Denemarken',
        'verenigd koninkrijk' => 'Verenigd Koninkrijk', 'united kingdom' => 'Verenigd Koninkrijk', 'uk' => 'Verenigd Koninkrijk', 'gb' => 'Verenigd Koninkrijk', 'engeland' => 'Verenigd Koninkrijk', 'england' => 'Verenigd Koninkrijk',
        'verenigde staten' => 'Verenigde Staten', 'united states' => 'Verenigde Staten', 'united states of america' => 'Verenigde Staten', 'usa' => 'Verenigde Staten', 'us' => 'Verenigde Staten', 'vs' => 'Verenigde Staten',
    ];

    /** European countries whose postcodes are digits, sometimes written with a country prefix ("B-2000"). */
    const PREFIXED_POSTCODE_COUNTRIES = ['België', 'Luxemburg', 'Duitsland', 'Frankrijk', 'Italië', 'Spanje', 'Zweden', 'Zwitserland', 'Oostenrijk', 'Denemarken'];

    /** "Belgium", "belgie", "BE" -> "België"; an unknown country is only trimmed. */
    public static function canonical_country(string $country): string {
        return self::COUNTRIES[self::fold($country)] ?? trim($country);
    }

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
     * moves to house_number_addition. The country gets its one stored name;
     * a European postcode loses its country prefix ("B-2000" -> "2000");
     * a house number still inside the street ("Kerkstraat 12 bus 3",
     * "12, rue Haute") moves to its own field when that is empty — in the
     * Netherlands only one of up to three digits, as a street may end in a
     * year.
     * Keys not given are left out.
     */
    public static function clean(array $address): array {
        $clean = [];
        foreach (['street', 'house_number', 'house_number_addition', 'postal_code', 'city', 'country'] as $field) {
            if (array_key_exists($field, $address)) {
                $clean[$field] = trim((string) preg_replace('/\s+/u', ' ', (string) $address[$field]));
            }
        }
        if (isset($clean['country'])) {
            $clean['country'] = self::canonical_country($clean['country']);
        }
        $country = $clean['country'] ?? '';
        if (isset($clean['street'], $clean['house_number']) && $clean['house_number'] === '') {
            [$clean['street'], $clean['house_number']] = self::number_out_of_street($clean['street'], $country);
        }
        if (isset($clean['house_number'])) {
            [$number, $addition] = self::split_house_number($clean['house_number'] . ' ' . ($clean['house_number_addition'] ?? ''));
            $clean['house_number'] = $number;
            $clean['house_number_addition'] = $addition;
        } elseif (isset($clean['house_number_addition'])) {
            $clean['house_number_addition'] = self::normalize_addition($clean['house_number_addition']);
        }
        if (isset($clean['postal_code']) && in_array($country, ['', 'Nederland'], true)) {
            $clean['postal_code'] = self::normalize_postal_code($clean['postal_code']);
        } elseif (isset($clean['postal_code']) && in_array($country, self::PREFIXED_POSTCODE_COUNTRIES, true)) {
            $clean['postal_code'] = (string) preg_replace('/^[A-Za-z]{1,2}\s*-?\s*(?=\d)/', '', $clean['postal_code']);
        }
        return array_merge($address, $clean);
    }

    /**
     * number_from_street(), except that in the Netherlands a street can end
     * in a year ("Plein 1940"): there only a number of one to three digits
     * is taken as the house number.
     */
    private static function number_out_of_street(string $street, string $country): array {
        [$rest, $number] = self::number_from_street($street);
        $taken = $number !== '' && (in_array($country, ['België', 'Luxemburg'], true) || preg_match('/^\d{1,3}(?!\d)/', $number));
        return $taken ? [$rest, $number] : [$street, ''];
    }

    /**
     * [street, number] from a street that still holds its number: at the
     * end ("Kerkstraat 12", "Kerkstraat 12 bus 3", "Lei 19 (2e v.)") or in
     * front, French style ("12, rue Haute"). Unchanged with no number found.
     */
    public static function number_from_street(string $street): array {
        $note = '';
        if (preg_match('/^(.*\S)\s*(\([^)]*\))$/u', $street, $m)) {
            [$street, $note] = [$m[1], ' ' . $m[2]];
        }
        if (preg_match('/^(.*?\D)\s+(\d+\w*(?:\s*(?:bus|bte|boite|boîte)\s*\w+)?)$/iu', $street, $m)) {
            return [trim($m[1], ' ,'), $m[2] . $note];
        }
        $street .= $note;
        if (preg_match('/^(\d+\w?)\s*,?\s+(\D.*)$/u', $street, $m)) {
            return [trim($m[2]), $m[1]];
        }
        return [$street, ''];
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
        $floor = '(?:hoog|hg|h|e|ste|de|etage|verdieping|ev|everd|everdieping)?';
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
        // Belgian box number: "bus 3", "bte 3", "boîte 3".
        if (preg_match('/^(?:bus|bte|boite|box)(\w+)$/', $folded, $a)) {
            return 'BUS' . mb_strtoupper($a[1], 'UTF-8');
        }
        return mb_strtoupper($folded, 'UTF-8');
    }

    /** "19" + "A" -> "19A"; "40" + "1" -> "40-1"; "7" + "BIS" -> "7-BIS"; "12" + "BUS3" -> "12 bus 3". */
    public static function format_house_number(string $number, string $addition): string {
        if ($addition === '') {
            return $number;
        }
        if (preg_match('/^BUS(\w+)$/u', $addition, $m)) {
            return $number . ' bus ' . $m[1];
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
     * house number pin the address down, so street spellings ("2e
     * Molendwarsstr" vs "...straat") don't matter. Otherwise
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
