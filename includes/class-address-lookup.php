<?php
defined('ABSPATH') || exit;

/**
 * Official spelling of an address from the government register of its
 * country, where one can be asked free of charge and without a key:
 * Netherlands (PDOK/BAG, see AVPVH_PDOK), Luxembourg (geoportail.lu,
 * national address register CACLR) and Flanders (Adressenregister;
 * Brussels and Wallonia keep their own registers and aren't asked).
 * Only street, house number, postcode and city are sent, never names.
 * Answers are cached for 30 days; no clear answer means no suggestion.
 */
class AVPVH_Address_Lookup {

    const FLANDERS_URL = 'https://api.basisregisters.vlaanderen.be/v2/adresmatch';
    const LUXEMBOURG_URL = 'https://apiv3.geoportail.lu/geocode/search';
    const CACHE_TTL = 30 * DAY_IN_SECONDS;

    /**
     * ['fields' => AVPVH_Address_Cleanup::FIELDS, 'source' => register name]
     * for $address, or null.
     */
    public static function lookup(object|array $address): ?array {
        $address = AVPVH_Address_Normalizer::clean((array) $address);
        $country = (string) ($address['country'] ?? '');
        if ($country === '' || $country === 'Nederland') {
            $fields = AVPVH_PDOK::lookup($address);
            $source = 'BAG';
        } elseif ($country === 'Luxemburg') {
            $fields = self::luxembourg($address);
            $source = 'geoportail.lu';
        } elseif ($country === 'België' && self::is_flemish_postcode((string) ($address['postal_code'] ?? ''))) {
            $fields = self::flanders($address);
            $source = 'Adressenregister Vlaanderen';
        } else {
            $fields = null;
        }
        return $fields ? ['fields' => $fields, 'source' => $source] : null;
    }

    /** Dutch addresses are fetched in parallel (AVPVH_PDOK::prefetch()); the few others one by one when shown. */
    public static function prefetch(array $addresses): void {
        AVPVH_PDOK::prefetch(array_filter($addresses, function ($address) {
            $country = AVPVH_Address_Normalizer::canonical_country((string) (((array) $address)['country'] ?? ''));
            return $country === '' || $country === 'Nederland';
        }));
    }

    /** Flemish postcodes: 1500–3999 and 8000–9999 (Brussels is 1000–1299, Wallonia 1300–1499 and 4000–7999). */
    public static function is_flemish_postcode(string $postcode): bool {
        if (!preg_match('/^\d{4}$/', $postcode)) {
            return false;
        }
        $code = (int) $postcode;
        return ($code >= 1500 && $code <= 3999) || $code >= 8000;
    }

    private static function flanders(array $address): ?array {
        if ($address['street'] === '' || !preg_match('/^\d+$/', (string) $address['house_number'])) {
            return null;
        }
        [$letter, $bus] = self::belgian_addition((string) $address['house_number_addition']);
        $query = array_filter([
            'postcode'   => $address['postal_code'],
            'straatnaam' => $address['street'],
            'huisnummer' => $address['house_number'] . $letter,
            'busnummer'  => $bus,
        ], 'strlen');
        $data = self::get_json(self::FLANDERS_URL . '?' . http_build_query($query));
        $want = AVPVH_Address_Normalizer::normalize_house_number((string) $address['house_number'], $letter);
        // A floor note ("2e v.") isn't in the register; it stays as stored.
        $kept_addition = $letter === '' && $bus === '' ? (string) $address['house_number_addition'] : '';
        $found = [];
        foreach ($data['adresMatches'] ?? [] as $match) {
            if (!self::flemish_match_fits($match, (string) $address['postal_code'], $want, $bus)) {
                continue;
            }
            [$official_number, $official_letter] = AVPVH_Address_Normalizer::split_house_number((string) $match['huisnummer']);
            $addition = $official_letter !== '' ? $official_letter : $kept_addition;
            if ($bus !== '') {
                $addition = 'BUS' . strtoupper($bus);
            }
            $fields = [
                'street'                => (string) ($match['straatnaam']['straatnaam']['geografischeNaam']['spelling'] ?? ''),
                'house_number'          => $official_number,
                'house_number_addition' => $addition,
                'postal_code'           => (string) ($match['postinfo']['objectId'] ?? ''),
                // The register names the municipality ("Antwerpen"); the
                // post uses the town ("Berchem"), so a stored town stays.
                'city'                  => $address['city'] !== '' ? (string) $address['city'] : (string) ($match['gemeente']['gemeentenaam']['geografischeNaam']['spelling'] ?? ''),
            ];
            $found[implode("\0", $fields)] = $fields;
        }
        return count($found) === 1 && reset($found)['street'] !== '' ? reset($found) : null;
    }

    /**
     * An address in use, scored well, with this postcode and house number
     * (the fuzzy match also offers the same number in other streets and
     * postcodes), and the same box number when both have one.
     */
    private static function flemish_match_fits(array $match, string $postcode, string $want, string $bus): bool {
        $match_bus = (string) ($match['busnummer'] ?? '');
        return ($match['adresStatus'] ?? '') === 'inGebruik'
            && ($match['score'] ?? 0) >= 80
            && (string) ($match['postinfo']['objectId'] ?? '') === $postcode
            && AVPVH_Address_Normalizer::normalize_house_number((string) ($match['huisnummer'] ?? '')) === $want
            && ($bus === '' || $match_bus === '' || strcasecmp($match_bus, $bus) === 0);
    }

    private static function luxembourg(array $address): ?array {
        if ($address['street'] === '' || $address['house_number'] === '') {
            return null;
        }
        $number = AVPVH_Address_Normalizer::format_house_number((string) $address['house_number'], (string) $address['house_number_addition']);
        $data = self::get_json(self::LUXEMBOURG_URL . '?' . http_build_query([
            'queryString' => trim("{$number} {$address['street']} {$address['postal_code']} {$address['city']}"),
        ]));
        $want = AVPVH_Address_Normalizer::normalize_house_number((string) $address['house_number'], (string) $address['house_number_addition']);
        $found = [];
        foreach ($data['results'] ?? [] as $result) {
            $details = $result['AddressDetails'] ?? [];
            // accuracy 8: an exact building, not just the street.
            if ((int) ($result['accuracy'] ?? 0) !== 8 || AVPVH_Address_Normalizer::normalize_house_number((string) ($details['postnumber'] ?? '')) !== $want) {
                continue;
            }
            [$official_number, $official_addition] = AVPVH_Address_Normalizer::split_house_number((string) $details['postnumber']);
            $fields = [
                'street'                => (string) ($details['street'] ?? ''),
                'house_number'          => $official_number,
                'house_number_addition' => $official_addition,
                'postal_code'           => (string) ($details['zip'] ?? ''),
                'city'                  => (string) ($details['locality'] ?? ''),
            ];
            $found[implode("\0", $fields)] = $fields;
        }
        return count($found) === 1 && reset($found)['street'] !== '' ? reset($found) : null;
    }

    /** A Belgian addition as [huisletter, busnummer]: "A" -> ['A', ''], "BUS3" -> ['', '3']. */
    private static function belgian_addition(string $addition): array {
        if (preg_match('/^BUS(\w+)$/u', $addition, $m)) {
            return ['', $m[1]];
        }
        return [preg_match('/^\p{L}$/u', $addition) ? $addition : '', ''];
    }

    /** Decoded JSON from $url, cached; null when the service can't be reached (not cached). */
    private static function get_json(string $url): ?array {
        $key = 'avpvh_addr_' . substr(hash('sha256', $url), 0, 32);
        $cached = get_transient($key);
        if (is_array($cached)) {
            return $cached;
        }
        $response = wp_remote_get($url, ['timeout' => 5, 'headers' => ['Accept' => 'application/json']]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return null;
        }
        set_transient($key, $data, self::CACHE_TTL);
        return $data;
    }
}
