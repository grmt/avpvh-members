<?php
defined('ABSPATH') || exit;

/**
 * Pure normalization helpers. Display values are returned unchanged (apart
 * from surrounding/repeated whitespace); folded values are only for search,
 * matching and duplicate detection.
 */
class AVPVH_Normalization {

    private const SUFFIXES = [
        'van der', 'van den', 'van de', 'ten', 'ter', 'de', 'van', 'te',
        'von', 'la', 'le', 'du', 'v/d', 'vd',
    ];

    public static function fold(string $value): string {
        $value = trim(preg_replace('/\s+/u', ' ', $value));
        if (function_exists('remove_accents')) {
            $value = remove_accents($value);
        } else {
            $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if ($converted !== false) {
                $value = $converted;
            }
        }
        $value = mb_strtolower($value, 'UTF-8');
        $value = preg_replace('/[.\-_,;:\/\\\\]+/u', ' ', $value);
        $value = preg_replace('/[^\p{L}\p{N}\']+/u', ' ', $value);
        return trim(preg_replace('/\s+/u', ' ', $value));
    }

    public static function normalize_person_name(
        string $first_name,
        string $suffix,
        string $last_name
    ): array {
        $first_name = trim(preg_replace('/\s+/u', ' ', $first_name));
        $suffix = trim(preg_replace('/\s+/u', ' ', $suffix));
        $last_name = trim(preg_replace('/\s+/u', ' ', $last_name));

        if ($suffix === '' && str_contains($last_name, ',')) {
            [$last_name, $suffix] = array_map('trim', explode(',', $last_name, 2));
        }

        if ($suffix === '') {
            $folded_last = self::fold($last_name);
            foreach (self::SUFFIXES as $candidate) {
                $folded_candidate = self::fold($candidate);
                if (str_starts_with($folded_last, $folded_candidate . ' ')) {
                    $word_count = count(preg_split('/\s+/u', $candidate));
                    $parts = preg_split('/\s+/u', $last_name);
                    $suffix = implode(' ', array_slice($parts, 0, $word_count));
                    $last_name = implode(' ', array_slice($parts, $word_count));
                    break;
                }
            }
        }

        $first_key = self::fold($first_name);
        $last_key = self::fold($last_name);

        return [
            'first_name' => $first_name,
            'suffix' => $suffix,
            'last_name' => $last_name,
            'first_key' => $first_key,
            'suffix_key' => self::fold($suffix),
            'last_key' => $last_key,
            // Suffix is deliberately omitted: legacy, separate and abbreviated
            // storage forms must resolve to the same safe candidate set. More
            // than one member for a key is reported as ambiguous, never guessed.
            'normalized_key' => $first_key . '|' . $last_key,
        ];
    }

    public static function normalize_postal_code(string $postal_code): string {
        $compact = strtoupper(preg_replace('/\s+/u', '', trim($postal_code)));
        if (preg_match('/^(\d{4})([A-Z]{2})$/', $compact, $matches)) {
            return $matches[1] . ' ' . $matches[2];
        }
        return $compact;
    }

    public static function normalize_address(
        array $address,
        array $city_aliases = [],
        array $street_aliases = []
    ): array {
        $country = trim((string) ($address['country'] ?? 'Nederland')) ?: 'Nederland';
        $postal_code = self::normalize_postal_code((string) ($address['postal_code'] ?? ''));
        $city = trim(preg_replace('/\s+/u', ' ', (string) ($address['city'] ?? '')));
        $street = trim(preg_replace('/\s+/u', ' ', (string) ($address['street'] ?? '')));
        $house_number = trim(preg_replace('/\s+/u', ' ', (string) ($address['house_number'] ?? '')));

        $country_key = self::fold($country);
        $postal_key = self::fold($postal_code);
        $city_key = self::fold($city);
        $city_lookup = $country_key . '|' . $city_key;
        if (isset($city_aliases[$city_lookup])) {
            $city = $city_aliases[$city_lookup];
            $city_key = self::fold($city);
        }

        $street_key = self::fold($street);
        $street_lookup = implode('|', [$country_key, $postal_key, $city_key, $street_key]);
        if (isset($street_aliases[$street_lookup])) {
            $street = $street_aliases[$street_lookup];
            $street_key = self::fold($street);
        }

        return [
            'street' => $street,
            'house_number' => $house_number,
            'postal_code' => $postal_code,
            'city' => $city,
            'country' => $country,
            'street_key' => $street_key,
            'house_number_key' => self::fold($house_number),
            'postal_code_key' => $postal_key,
            'city_key' => $city_key,
            'country_key' => $country_key,
            'normalized_key' => implode('|', [
                $country_key,
                $postal_key,
                $city_key,
                $street_key,
                self::fold($house_number),
            ]),
        ];
    }

    public static function classify_member_ids(array $member_ids): array {
        $member_ids = array_values(array_unique(array_map('intval', $member_ids)));
        return [
            'status' => count($member_ids) === 0
                ? 'none'
                : (count($member_ids) === 1 ? 'unique' : 'ambiguous'),
            'member_ids' => $member_ids,
        ];
    }
}
