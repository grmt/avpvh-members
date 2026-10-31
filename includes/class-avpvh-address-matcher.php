<?php
defined('ABSPATH') || exit;

/**
 * Whether two address rows are the same address, in one place for Adressen
 * opschonen, Leden samenvoegen, the profile form and the ledenlijst import.
 * Pure logic, no DB access; rows are objects or arrays with the
 * avm_addresses columns.
 *
 * The same address: an equal AVPVH_Address_Normalizer::match_key() (a
 * Dutch postcode and number pin it down; abroad the street is part of
 * the key), or recognizably the same with a typo (probably_same()): a
 * respelled or abbreviated street, a postcode one character off.
 */
class AVPVH_Address_Matcher {

    /** The same address: equal match_key(), a typo of it, or (no street) the same text. */
    public static function same(array|object $left, array|object $right): bool {
        $left = (object) $left;
        $right = (object) $right;
        $keys = [AVPVH_Address_Normalizer::match_key($left), AVPVH_Address_Normalizer::match_key($right)];
        if ($keys[0] === '' && $keys[1] === '') {
            return self::text($left) === self::text($right);
        }
        return $keys[0] === $keys[1] || self::probably_same($left, $right);
    }

    /**
     * $rows grouped per address, keyed by the first row's match_key(); rows
     * without a street are left out. Each group's rows in id order.
     */
    public static function group(array $rows): array {
        $by_key = [];
        foreach ($rows as $row) {
            $key = AVPVH_Address_Normalizer::match_key($row);
            if ($key !== '') {
                $by_key[$key][] = $row;
            }
        }
        return self::join_respelled($by_key);
    }

    /**
     * Two rows that differ only by a typo: same house number, and the same
     * Dutch postcode (that pins the address down), or the same city or
     * postcode or a postcode one character off, with the street names
     * mostly alike ("J. van Lindenweg 3, 1234 AC" vs "Johan van Lindeweg
     * 3, 1234 AB"). A postcode slip alone isn't enough: neighbouring
     * postcodes are often the next street, and the import and the profile
     * form take a match without asking.
     */
    public static function probably_same(object $left, object $right): bool {
        $number = fn(object $a): string => AVPVH_Address_Normalizer::normalize_house_number((string) ($a->house_number ?? ''), (string) ($a->house_number_addition ?? ''));
        $compact = fn(string $v): string => str_replace(' ', '', AVPVH_Address_Normalizer::fold($v));
        [$left_pc, $right_pc] = [$compact((string) ($left->postal_code ?? '')), $compact((string) ($right->postal_code ?? ''))];
        $no_numbers = $number($left) === '' && $number($right) === '';
        $same_number = $number($left) !== '' && $number($left) === $number($right);
        $same_postcode = $left_pc !== '' && $left_pc === $right_pc;
        $dutch_postcode = $same_postcode && preg_match('/^\d{4}[a-z]{2}$/', $left_pc);
        $postcode_slip = strlen($left_pc) === 6 && strlen($right_pc) === 6 && levenshtein($left_pc, $right_pc) === 1;
        $same_city = $compact((string) ($left->city ?? '')) !== '' && $compact((string) ($left->city ?? '')) === $compact((string) ($right->city ?? ''));
        $street_alike = self::streets_alike((string) ($left->street ?? ''), (string) ($right->street ?? ''));

        // No number on either side: only a respelled street at the same
        // postcode. Otherwise the same number, and the same Dutch postcode
        // (that pins it down) or a town/postcode match with the street alike.
        return $no_numbers
            ? $same_postcode && $street_alike
            : $same_number && ($dutch_postcode || (($same_city || $same_postcode || $postcode_slip) && $street_alike));
    }

    /**
     * Joins groups whose keys differ only by the street's spelling. Outside
     * the Netherlands a postcode is a whole town, so match_key() keeps the
     * street, and "B. Lindelaan 12" and "Burgemeester Lindelaan 12"
     * at one postcode would otherwise stay two addresses.
     */
    private static function join_respelled(array $by_key): array {
        $by_place = [];
        foreach (array_keys($by_key) as $key) {
            $parts = explode('|', (string) $key);
            if (count($parts) === 3 && $parts[0] !== '' && $parts[1] !== '') {
                $by_place[$parts[0] . '|' . $parts[1]][] = $key;
            }
        }
        foreach (array_filter($by_place, fn($keys) => count($keys) > 1) as $keys) {
            $targets = [];
            foreach ($keys as $key) {
                $target = current(array_filter($targets, fn($t) => self::probably_same($by_key[$t][0], $by_key[$key][0])));
                if ($target === false) {
                    $targets[] = $key;
                    continue;
                }
                $by_key[$target] = array_merge($by_key[$target], $by_key[$key]);
                usort($by_key[$target], fn($a, $b) => (int) $a->id <=> (int) $b->id);
                unset($by_key[$key]);
            }
        }
        return $by_key;
    }

    /**
     * Street names mostly alike (75%). With a word abbreviated on either
     * side ("B. Lindelaan", "Prof. v.d. Bergstraat") every word but
     * the last is cut to its initial on both sides first.
     */
    private static function streets_alike(string $left, string $right): bool {
        $compact = fn(string $v): string => str_replace(' ', '', AVPVH_Address_Normalizer::fold($v));
        similar_text($compact($left), $compact($right), $percent);
        if ($percent < 75 && preg_match('/\b\p{L}{1,4}\./u', $left . ' ' . $right)) {
            $initials = static function (string $street): string {
                $words = explode(' ', AVPVH_Address_Normalizer::fold($street));
                $last = array_pop($words);
                return implode('', array_map(fn($w) => mb_substr($w, 0, 1), $words)) . $last;
            };
            similar_text($initials($left), $initials($right), $percent);
        }
        return $percent >= 75;
    }

    private static function text(object $address): string {
        $parts = array_map(fn($field) => (string) ($address->$field ?? ''), ['street', 'house_number', 'house_number_addition', 'postal_code', 'city', 'country']);
        return AVPVH_Address_Normalizer::fold(implode(' ', $parts));
    }
}
