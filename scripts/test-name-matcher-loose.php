<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for AVPVH_Name_Matcher::loose_key() (merge suggestions).
 *
 * Run:
 *   php scripts/test-name-matcher-loose.php
 */

define('ABSPATH', __DIR__ . '/');

// Minimal stand-in for WordPress' remove_accents() when run without WP.
if (!function_exists('remove_accents')) {
    function remove_accents(string $text): string {
        return strtr($text, ['é' => 'e', 'è' => 'e', 'ë' => 'e', 'ü' => 'u', 'ö' => 'o', 'É' => 'E', 'Ü' => 'U', 'Ö' => 'O', 'ï' => 'i']);
    }
}

require_once dirname(__DIR__) . '/includes/class-avpvh-name-matcher.php';

function same(string $label, array $a, array $b, bool $expected = true): void {
    $ka = AVPVH_Name_Matcher::loose_key(...$a);
    $kb = AVPVH_Name_Matcher::loose_key(...$b);
    $ok = ($ka !== '' && $ka === $kb) === $expected;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        echo "  $ka vs $kb" . PHP_EOL;
        exit(1);
    }
}

same('accent in surname', ['Lajos', 'Fülöp'], ['Lajos', 'Fulöp']);
same('accent in first name', ['Hélen', 'Franken'], ['Hèlen', 'Franken']);
same('capitals', ['jan', 'BERG'], ['Jan', 'Berg']);
same('v/d prefix vs van den', ['Jan', 'v/d Berg'], ['Jan', 'van den Berg']);
same('vd prefix vs van der', ['Jan', 'vd Berg'], ['Jan', 'van der Berg']);
same('v.d. prefix vs van de', ['Jan', 'v.d. Berg'], ['Jan', 'van de Berg']);
same('v. prefix vs van', ['Jan', 'v. Berg'], ['Jan', 'van Berg']);
same('glued at end', ['Jan', 'Berg v/d'], ['Jan', 'Berg']);
same('legacy comma form', ['Jan', 'Berg, van den'], ['Jan', 'Berg']);
same("'t and in 't", ['Jan', "in 't Veld"], ['Jan', 'Veld']);
same('tussenvoegsel glued on first name', ['Jan van', 'Berg'], ['Jan', 'Berg']);
same('hyphen and spacing', ['Jan-Willem', 'Berg'], ['Jan Willem', 'Berg']);
same('different surname stays apart', ['Jan', 'Berg'], ['Jan', 'Bergen'], false);
same('different first name stays apart', ['Jan', 'Berg'], ['Jaap', 'Berg'], false);
same('surname of only tussenvoegsels gives no key', ['Jan', 'van der'], ['Jan', 'van der'], false);
same('ij and y', ['Anna', 'van Dijk'], ['Anna', 'van Dyk']);
same('ij and y in the first name', ['Thijs', 'Berg'], ['Thys', 'Berg']);
same('ij and y still keep other names apart', ['Anna', 'van Dijk'], ['Anna', 'van Dijken'], false);

$compatible = fn(string $a, string $b, bool $expected, string $label) => (function () use ($a, $b, $expected, $label) {
    $ok = AVPVH_Name_Matcher::first_names_compatible($a, $b) === $expected;
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if (!$ok) {
        exit(1);
    }
})();
$compatible('Anna-M.', 'Anna Maria', true, 'abbreviated second first name');
$compatible('J.', 'Jan', true, 'initial for the whole first name');
$compatible('J.W.', 'Jan Willem', true, 'two initials');
$compatible('Jan', 'Jaap', false, 'two full first names stay apart');
$compatible('Jan', 'Jan', false, 'identical names are the other route');
$compatible('Anna-M.', 'Anna', false, 'a different number of names stays apart');
$compatible('Jo.', 'Jan', false, 'an initial must start the name');
