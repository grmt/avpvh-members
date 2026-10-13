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
     * "postcode|number|street" comparison key; spaces are dropped inside each
     * part so "12 a" = "12a" and "1234AB" = "1234 ab". City and country are
     * deliberately left out: postcode + house number already pin the address
     * down, and city is often missing or spelled differently ("Den Bosch").
     * Returns '' when there is no street, so empty addresses never match.
     */
    public static function match_key(array|object $address): string {
        $address = (array) $address;
        $part = static fn(string $field): string =>
            str_replace(' ', '', self::fold((string) ($address[$field] ?? '')));
        if ($part('street') === '') {
            return '';
        }
        return $part('postal_code') . '|' . $part('house_number') . '|' . $part('street');
    }
}
