<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** Every scripts/test-*.php runs under PHPUnit through its own tests/scripts/*.phpt. */
final class ScriptTestsTest extends TestCase {
    public function test_every_script_has_a_phpt(): void {
        $root = dirname(__DIR__);
        $missing = [];
        foreach (glob($root . '/scripts/test-*.php') as $script) {
            $phpt = $root . '/tests/scripts/' . substr(basename($script, '.php'), strlen('test-')) . '.phpt';
            if (!is_file($phpt)) {
                $missing[] = basename($script);
            }
        }
        $this->assertSame([], $missing, 'Run bin/make-script-tests to add the missing .phpt files.');
    }
}
