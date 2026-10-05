<?php
defined('ABSPATH') || exit;

class AVPVH_I18n {

    public const SUPPORTED_LOCALES = [
        'nl_NL' => 'Nederlands',
        'en_US' => 'English (US)',
        'en_GB' => 'English (UK)',
        'fr_FR' => 'Français',
        'de_DE' => 'Deutsch',
        'lb_LU' => 'Lëtzebuergesch',
        'li_NL' => 'Wieërts',
    ];

    public const LANG_TO_LOCALE = [
        'nl'    => 'nl_NL',
        'en'    => 'en_US',
        'fr'    => 'fr_FR',
        'de'    => 'de_DE',
        'lb'    => 'lb_LU',
        'lu'    => 'lb_LU',
        'li'    => 'li_NL',
        'wie'   => 'li_NL',
        'weert' => 'li_NL',
        'wieert'=> 'li_NL',
        'nl_nl' => 'nl_NL',
        'en_us' => 'en_US',
        'en_gb' => 'en_GB',
        'en-gb' => 'en_GB',
        'en_uk' => 'en_GB',
        'en-uk' => 'en_GB',
        'fr_fr' => 'fr_FR',
        'de_de' => 'de_DE',
        'lb_lu' => 'lb_LU',
        'li_nl' => 'li_NL',
        'nl_NL' => 'nl_NL',
        'en_US' => 'en_US',
        'en_GB' => 'en_GB',
        'fr_FR' => 'fr_FR',
        'de_DE' => 'de_DE',
        'lb_LU' => 'lb_LU',
        'li_NL' => 'li_NL',
    ];

    public function __construct() {
        add_action('init', [$this, 'load_textdomain']);
        add_filter('determine_locale', [$this, 'filter_determine_locale'], 20);
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'avpvh-members',
            false,
            dirname(plugin_basename(AVPVH_PLUGIN_DIR . 'avpvh-members.php')) . '/languages'
        );
    }

    public function filter_determine_locale(string $locale): string {
        // 1. Explicit request param (?lang=en, ?lang=fr, ?lang=de, ?lang=nl)
        if (isset($_GET['lang'])) {
            $req_lang = sanitize_key(wp_unslash($_GET['lang']));
            if (isset(self::LANG_TO_LOCALE[$req_lang])) {
                $chosen = self::LANG_TO_LOCALE[$req_lang];
                if (!headers_sent() && (!isset($_COOKIE['avpvh_lang']) || $_COOKIE['avpvh_lang'] !== $chosen)) {
                    $cookie_domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
                    $cookie_path   = defined('COOKIEPATH') ? COOKIEPATH : '/';
                    setcookie('avpvh_lang', $chosen, time() + (86400 * 365), $cookie_path, $cookie_domain, is_ssl(), true);
                }
                return $chosen;
            }
        }

        // 2. Cookie if previously chosen
        if (isset($_COOKIE['avpvh_lang'])) {
            $cookie_lang = sanitize_key(wp_unslash($_COOKIE['avpvh_lang']));
            if (isset(self::LANG_TO_LOCALE[$cookie_lang])) {
                return self::LANG_TO_LOCALE[$cookie_lang];
            }
        }

        // 3. User profile preference if logged in
        if (is_user_logged_in()) {
            $user_id = get_current_user_id();
            $user_locale = (string) get_user_meta($user_id, 'locale', true);
            if ($user_locale && isset(self::SUPPORTED_LOCALES[$user_locale])) {
                return $user_locale;
            }
        }

        return $locale;
    }

    public static function get_supported_locales(): array {
        return self::SUPPORTED_LOCALES;
    }
}
