<?php
declare(strict_types=1);

/**
 * Ad-hoc checks for how activity texts appear on the website lists
 * (AVPVH_Activity_List::rich): editor text keeps its own <br>, plain text
 * gets line breaks, empty text shows the fallback.
 *
 * Run:
 *   php scripts/test-activity-list-rich.php
 */

define('ABSPATH', __DIR__ . '/');

function wp_kses_post(string $html): string {
    return $html;
}
function esc_html(string $text): string {
    return htmlspecialchars($text);
}

require_once dirname(__DIR__) . '/includes/class-activity-list.php';

function check(string $label, mixed $actual, mixed $expected): void {
    echo ($actual === $expected ? '[OK] ' : '[FAIL] ') . $label . PHP_EOL;
    if ($actual !== $expected) {
        echo '  expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . PHP_EOL;
        exit(1);
    }
}

$rich = new ReflectionMethod(AVPVH_Activity_List::class, 'rich');

check('editor text with <br> and a newline: no extra break', $rich->invoke(null, "Kasteel<br />\nDorpsstraat 1", 'x'), "Kasteel<br />\nDorpsstraat 1");
check('plain text: newlines become <br>', $rich->invoke(null, "Kasteel\nDorpsstraat 1", 'x'), "Kasteel<br>\nDorpsstraat 1");
check('bold and italic kept', $rich->invoke(null, '<strong>Museum</strong> en <em>wandeling</em>', 'x'), '<strong>Museum</strong> en <em>wandeling</em>');
check('empty: fallback, escaped', $rich->invoke(null, '  ', 'A & B'), 'A &amp; B');

echo "Activity list rich text tests: OK\n";
