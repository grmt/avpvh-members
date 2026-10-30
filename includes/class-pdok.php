<?php
defined('ABSPATH') || exit;

/**
 * Official spelling of a Dutch address from PDOK's Locatieserver (the
 * government's free BAG address service, no key needed). Only postcode
 * and house number are sent, never names. Answers are cached for 30 days;
 * when PDOK is unreachable, or the answer isn't one clear address, there's
 * simply no suggestion.
 */
class AVPVH_PDOK {

    const URL = 'https://api.pdok.nl/bzk/locatieserver/search/v3_1/free';
    const CACHE_TTL = 30 * DAY_IN_SECONDS;

    /**
     * The official address for $address's postcode, house number and
     * addition, as AVPVH_Address_Cleanup::FIELDS, or null.
     */
    public static function lookup(object|array $address): ?array {
        $query = self::query((array) $address);
        if (!$query) {
            return null;
        }
        [$postcode, $number, $want] = $query;
        $docs = self::cached_docs($postcode, $number) ?? self::fetch($postcode, $number);
        if ($docs === []) {
            // Nothing at this postcode: perhaps the postcode itself is
            // mistyped, so search by street, number and town instead.
            $docs = self::search_by_street((array) $address, $number);
        }
        $matches = array_values(array_filter(
            $docs ?? [],
            fn(array $doc) => AVPVH_Address_Normalizer::normalize_house_number((string) $doc['huisnummer'], self::addition($doc)) === $want
        ));
        if (count($matches) !== 1) {
            return null;
        }
        $doc = $matches[0];
        return [
            'street'                => (string) $doc['straatnaam'],
            'house_number'          => (string) $doc['huisnummer'],
            'house_number_addition' => AVPVH_Address_Normalizer::normalize_addition(self::addition($doc)),
            'postal_code'           => AVPVH_Address_Normalizer::normalize_postal_code((string) $doc['postcode']),
            'city'                  => (string) $doc['woonplaatsnaam'],
        ];
    }

    /**
     * Fetches every address not cached yet in parallel, so a page with many
     * suggestions waits for PDOK once instead of once per address.
     */
    public static function prefetch(array $addresses): void {
        $pending = [];
        foreach ($addresses as $address) {
            $query = self::query((array) $address);
            if ($query && self::cached_docs($query[0], $query[1]) === null) {
                $pending[$query[0] . '|' . $query[1]] = ['url' => self::url($query[0], $query[1]), 'type' => 'GET'];
            }
        }
        if (!$pending || !class_exists('\WpOrg\Requests\Requests')) {
            return;
        }
        $responses = \WpOrg\Requests\Requests::request_multiple($pending, ['timeout' => 5]);
        foreach ($responses as $id => $response) {
            if ($response instanceof \WpOrg\Requests\Response && $response->status_code === 200) {
                [$postcode, $number] = explode('|', (string) $id);
                self::store($postcode, $number, $response->body);
            }
        }
    }

    /**
     * Addresses with this house number in the stored town, found by street
     * name (PDOK's free-text search); [] when street or town is missing.
     * The caller still requires exactly one match.
     */
    private static function search_by_street(array $address, string $number): array {
        $street = trim((string) ($address['street'] ?? ''));
        $city = trim((string) ($address['city'] ?? ''));
        if ($street === '' || $city === '') {
            return [];
        }
        $url = self::URL . '?q=' . rawurlencode("{$street} {$number} {$city}") . '&fq=type:adres&fq=huisnummer:' . rawurlencode($number)
            . '&fl=straatnaam,huisnummer,huisletter,huisnummertoevoeging,postcode,woonplaatsnaam&rows=10';
        $key = 'avpvh_pdok_q_' . substr(hash('sha256', $url), 0, 32);
        $docs = get_transient($key);
        if (!is_array($docs)) {
            $response = wp_remote_get($url, ['timeout' => 5]);
            if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
                return [];
            }
            $decoded = json_decode(wp_remote_retrieve_body($response), true);
            $docs = is_array($decoded['response']['docs'] ?? null) ? $decoded['response']['docs'] : [];
            set_transient($key, $docs, self::CACHE_TTL);
        }
        $town = AVPVH_Address_Normalizer::fold($city);
        return array_values(array_filter($docs, fn(array $doc) => AVPVH_Address_Normalizer::fold((string) ($doc['woonplaatsnaam'] ?? '')) === $town));
    }

    /** [postcode, number, normalized number] for a Dutch address, or null. */
    private static function query(array $address): ?array {
        $postcode = strtoupper(str_replace(' ', '', (string) ($address['postal_code'] ?? '')));
        [$number, $addition] = AVPVH_Address_Normalizer::split_house_number(
            ($address['house_number'] ?? '') . ' ' . ($address['house_number_addition'] ?? '')
        );
        if (!preg_match('/^\d{4}[A-Z]{2}$/', $postcode) || !preg_match('/^\d+$/', $number)) {
            return null;
        }
        return [$postcode, $number, AVPVH_Address_Normalizer::normalize_house_number($number, $addition)];
    }

    /** BAG huisletter and huisnummertoevoeging as one addition ("A", "1", "A-1"). */
    private static function addition(array $doc): string {
        return implode('-', array_filter([(string) ($doc['huisletter'] ?? ''), (string) ($doc['huisnummertoevoeging'] ?? '')], 'strlen'));
    }

    private static function url(string $postcode, string $number): string {
        return self::URL . '?fq=type:adres&fq=postcode:' . rawurlencode($postcode) . '&fq=huisnummer:' . rawurlencode($number)
            . '&fl=straatnaam,huisnummer,huisletter,huisnummertoevoeging,postcode,woonplaatsnaam&rows=50';
    }

    private static function cache_key(string $postcode, string $number): string {
        return 'avpvh_pdok_' . substr(hash('sha256', $postcode . '|' . $number), 0, 32);
    }

    private static function cached_docs(string $postcode, string $number): ?array {
        $cached = get_transient(self::cache_key($postcode, $number));
        return is_array($cached) ? $cached : null;
    }

    private static function fetch(string $postcode, string $number): ?array {
        $response = wp_remote_get(self::url($postcode, $number), ['timeout' => 5]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return null;
        }
        return self::store($postcode, $number, wp_remote_retrieve_body($response));
    }

    /** Caches a PDOK answer (also "no such address"); a malformed one isn't cached. */
    private static function store(string $postcode, string $number, string $body): ?array {
        $decoded = json_decode($body, true);
        if (!is_array($decoded['response']['docs'] ?? null)) {
            return null;
        }
        $docs = $decoded['response']['docs'];
        set_transient(self::cache_key($postcode, $number), $docs, self::CACHE_TTL);
        return $docs;
    }
}
