<?php
defined('ABSPATH') || exit;

/**
 * Checks an address while it is typed, against the government register of
 * its country (AVPVH_Address_Lookup): a Dutch street and town are filled
 * in from postcode + house number, otherwise the official spelling is
 * offered to take over. Never blocking: an address the register doesn't
 * know can still be saved.
 *
 * A form opts in by marking its address fields' container with
 * data-avpvh-address (inputs named street, house_number,
 * house_number_addition, postal_code, city, country) and calling
 * enqueue(). The lookup runs on the server, so the register never sees a
 * member's IP address and the cache is shared with Adressen opschonen.
 */
class AVPVH_Address_Entry_Check {

    const ACTION = 'avpvh_address_lookup';
    const LIMIT = 60;
    const ANONYMOUS_LIMIT = 30;
    const LIMIT_WINDOW = 10 * MINUTE_IN_SECONDS;

    public static function init(): void {
        add_action('wp_ajax_' . self::ACTION, [self::class, 'handle_lookup']);
        // The public order forms (avpvh-bookkeeping) are used without login.
        add_action('wp_ajax_nopriv_' . self::ACTION, [self::class, 'handle_lookup']);
    }

    /** Script and its settings for a page with a data-avpvh-address form. */
    public static function enqueue(): void {
        wp_enqueue_script(
            'avpvh-address-check',
            plugin_dir_url(dirname(__FILE__)) . 'assets/address-check.js',
            [],
            avpvh_asset_version('assets/address-check.js'),
            true
        );
        $config = wp_json_encode([
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'action'  => self::ACTION,
            'nonce'   => wp_create_nonce(self::ACTION),
            'strings' => [
                /* translators: %s: register name, e.g. BAG */
                'filled'   => __('Straat en plaats ingevuld uit het adressenregister (%s).', 'avpvh-members'),
                /* translators: 1: register name, 2: address */
                'official' => __('Officieel volgens %1$s: %2$s', 'avpvh-members'),
                'take'     => __('Overnemen', 'avpvh-members'),
                /* translators: %s: register name */
                'ok'       => __('Gevonden in het adressenregister (%s).', 'avpvh-members'),
                'unknown'  => __('Niet eenduidig gevonden in het adressenregister. Controleer postcode, huisnummer en toevoeging; opslaan kan wel.', 'avpvh-members'),
            ],
        ]);
        add_action('wp_footer', function () use ($config) {
            wp_print_inline_script_tag($config, ['type' => 'application/json', 'id' => 'avpvh-address-check-config']);
        });
        add_action('admin_footer', function () use ($config) {
            wp_print_inline_script_tag($config, ['type' => 'application/json', 'id' => 'avpvh-address-check-config']);
        });
    }

    /**
     * {found, checked, fields, source, display}: checked is false when the
     * country has no register to ask (Brussels, Wallonia, abroad), so the
     * form stays quiet instead of warning.
     */
    public static function handle_lookup(): void {
        check_ajax_referer(self::ACTION, 'nonce');
        $user_id = get_current_user_id();
        $who = $user_id ? 'u' . $user_id : 'ip' . substr(hash('sha256', sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''))), 0, 24);
        if (!self::within_limit($who, $user_id ? self::LIMIT : self::ANONYMOUS_LIMIT)) {
            wp_send_json_error(['message' => 'limit'], 429);
        }
        $address = [];
        foreach (['street', 'house_number', 'house_number_addition', 'postal_code', 'city', 'country'] as $field) {
            $address[$field] = sanitize_text_field(wp_unslash($_POST[$field] ?? ''));
        }
        $address = AVPVH_Address_Normalizer::clean($address);
        $found = AVPVH_Address_Lookup::lookup($address);
        wp_send_json_success([
            'found'   => (bool) $found,
            'checked' => self::is_checkable($address),
            'fields'  => $found['fields'] ?? null,
            'source'  => $found['source'] ?? '',
            'display' => $found ? AVPVH_Address_Cleanup::format($found['fields']) : '',
        ]);
    }

    /** Whether a register is asked for this address at all. */
    private static function is_checkable(array $address): bool {
        $country = (string) ($address['country'] ?? '');
        return in_array($country, ['', 'Nederland', 'Luxemburg'], true)
            || ($country === 'België' && AVPVH_Address_Lookup::is_flemish_postcode((string) ($address['postal_code'] ?? '')));
    }

    /**
     * At most $limit lookups per user (or, logged out, per hashed IP
     * address) per LIMIT_WINDOW: each may reach an outside service.
     */
    private static function within_limit(string $who, int $limit): bool {
        $key = 'avpvh_addr_check_' . $who;
        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return false;
        }
        set_transient($key, $count + 1, self::LIMIT_WINDOW);
        return true;
    }
}
