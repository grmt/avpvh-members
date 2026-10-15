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
    function add_action($tag, $callback) { /* Not needed in this test. */ }
}
if (!function_exists('add_filter')) {
    function add_filter($tag, $callback, $priority = 10) { /* Not needed in this test. */ }
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
assert_test('contains en_GB', isset($locales['en_GB']));
assert_test('contains fr_FR', isset($locales['fr_FR']));
assert_test('contains de_DE', isset($locales['de_DE']));
assert_test('contains lb_LU', isset($locales['lb_LU']));
assert_test('contains li_NL', isset($locales['li_NL']));

// 1. GET ?lang=en -> en_US
$_GET['lang'] = 'en';
assert_test('GET lang=en resolves to en_US', $i18n->filter_determine_locale('nl_NL') === 'en_US');

// 1b. GET ?lang=en-gb -> en_GB, ?lang=en-uk -> en_GB
$_GET['lang'] = 'en-gb';
assert_test('GET lang=en-gb resolves to en_GB', $i18n->filter_determine_locale('nl_NL') === 'en_GB');
$_GET['lang'] = 'en-uk';
assert_test('GET lang=en-uk resolves to en_GB', $i18n->filter_determine_locale('nl_NL') === 'en_GB');
$_GET['lang'] = 'en_GB';
assert_test('GET lang=en_GB resolves to en_GB', $i18n->filter_determine_locale('nl_NL') === 'en_GB');

// 2. GET ?lang=fr -> fr_FR
$_GET['lang'] = 'fr';
assert_test('GET lang=fr resolves to fr_FR', $i18n->filter_determine_locale('nl_NL') === 'fr_FR');

// 3. GET ?lang=de -> de_DE
$_GET['lang'] = 'de';
assert_test('GET lang=de resolves to de_DE', $i18n->filter_determine_locale('nl_NL') === 'de_DE');

// 4. GET ?lang=nl -> nl_NL
$_GET['lang'] = 'nl';
assert_test('GET lang=nl resolves to nl_NL', $i18n->filter_determine_locale('en_US') === 'nl_NL');

// 4b. GET ?lang=lb -> lb_LU, ?lang=lu -> lb_LU
$_GET['lang'] = 'lb';
assert_test('GET lang=lb resolves to lb_LU', $i18n->filter_determine_locale('nl_NL') === 'lb_LU');
$_GET['lang'] = 'lu';
assert_test('GET lang=lu resolves to lb_LU', $i18n->filter_determine_locale('nl_NL') === 'lb_LU');

// 4c. GET ?lang=wie -> li_NL, ?lang=li -> li_NL, ?lang=weert -> li_NL
$_GET['lang'] = 'wie';
assert_test('GET lang=wie resolves to li_NL', $i18n->filter_determine_locale('nl_NL') === 'li_NL');
$_GET['lang'] = 'li';
assert_test('GET lang=li resolves to li_NL', $i18n->filter_determine_locale('nl_NL') === 'li_NL');
$_GET['lang'] = 'weert';
assert_test('GET lang=weert resolves to li_NL', $i18n->filter_determine_locale('nl_NL') === 'li_NL');

// 4b. A chosen language is recorded for the rest of the request, so the
// cookie is sent once although WordPress asks for the locale many times.
assert_test('GET lang records the chosen locale in $_COOKIE', ($_COOKIE[AVPVH_I18n::COOKIE_NAME] ?? '') === 'li_NL');
unset($_GET['lang'], $_COOKIE[AVPVH_I18n::COOKIE_NAME]);

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

// 8. Every AVPVH_I18n::name the plugin uses exists. The profile form once
// called a method and constant that were never written, which made saving
// one's own profile a fatal error.
$i18n_class = new ReflectionClass('AVPVH_I18n');
$plugin_root = dirname(__DIR__);
foreach (['avpvh-members.php', 'includes', 'admin'] as $part) {
    $path = $plugin_root . '/' . $part;
    $files = is_dir($path)
        ? iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)))
        : [new SplFileInfo($path)];
    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }
        preg_match_all('/AVPVH_I18n::([A-Za-z_]+)/', file_get_contents($file->getPathname()), $matches);
        foreach (array_unique($matches[1]) as $name) {
            $exists = $name === 'class' || $i18n_class->hasConstant($name) || $i18n_class->hasMethod($name);
            assert_test("AVPVH_I18n::$name used in " . basename($file->getPathname()) . ' exists', $exists);
        }
    }
}

echo "\nAll i18n tests passed.\n";
