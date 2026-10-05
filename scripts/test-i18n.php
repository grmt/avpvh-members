<?php
/**
 * Test AVPVH_I18n locale resolution and supported locales.
 */

// Minimal WordPress stubs for isolated CLI testing
if (!function_exists('sanitize_key')) {
    function sanitize_key($key) {
        $key = strtolower($key);
        return preg_replace('/[^a-z0-9_\-]/', '', $key);
    }
}
if (!function_exists('wp_unslash')) {
    function wp_unslash($val) {
        return stripslashes($val);
    }
}
if (!function_exists('is_ssl')) {
    function is_ssl() {
        return false;
    }
}
if (!function_exists('is_user_logged_in')) {
    function is_user_logged_in() {
        return false;
    }
}
if (!function_exists('get_current_user_id')) {
    function get_current_user_id() {
        return 0;
    }
}
if (!function_exists('get_user_meta')) {
    function get_user_meta($user_id, $key, $single = false) {
        return '';
    }
}
if (!function_exists('add_action')) {
    function add_action($tag, $callback) {}
}
if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10) {}
}

define('ABSPATH', __DIR__ . '/../');
define('AVPVH_PLUGIN_DIR', __DIR__ . '/../');

require_once __DIR__ . '/../includes/class-i18n.php';

function assert_test(string $desc, bool $condition): void {
    if (!$condition) {
        echo "[FAIL] $desc\n";
        exit(1);
    }
    echo "[OK] $desc\n";
}

echo "Testing AVPVH_I18n:\n";

$i18n = new AVPVH_I18n();

// Supported locales
$locales = AVPVH_I18n::get_supported_locales();
assert_test('contains nl_NL', isset($locales['nl_NL']));
assert_test('contains en_US', isset($locales['en_US']));
assert_test('contains fr_FR', isset($locales['fr_FR']));
assert_test('contains de_DE', isset($locales['de_DE']));

// 1. GET ?lang=en -> en_US
$_GET['lang'] = 'en';
assert_test('GET lang=en resolves to en_US', $i18n->filter_determine_locale('nl_NL') === 'en_US');

// 2. GET ?lang=fr -> fr_FR
$_GET['lang'] = 'fr';
assert_test('GET lang=fr resolves to fr_FR', $i18n->filter_determine_locale('nl_NL') === 'fr_FR');

// 3. GET ?lang=de -> de_DE
$_GET['lang'] = 'de';
assert_test('GET lang=de resolves to de_DE', $i18n->filter_determine_locale('nl_NL') === 'de_DE');

// 4. GET ?lang=nl -> nl_NL
$_GET['lang'] = 'nl';
assert_test('GET lang=nl resolves to nl_NL', $i18n->filter_determine_locale('en_US') === 'nl_NL');

// 5. Invalid GET lang falls through
$_GET['lang'] = 'es';
assert_test('Invalid GET lang falls through', $i18n->filter_determine_locale('nl_NL') === 'nl_NL');
unset($_GET['lang']);

// 6. Cookie avpvh_lang
$_COOKIE['avpvh_lang'] = 'de_DE';
assert_test('Cookie avpvh_lang=de_DE resolves to de_DE', $i18n->filter_determine_locale('nl_NL') === 'de_DE');
unset($_COOKIE['avpvh_lang']);

// 7. Fallback to passed locale
assert_test('Default fallback preserves incoming locale', $i18n->filter_determine_locale('nl_NL') === 'nl_NL');

echo "\nAll i18n tests passed.\n";
