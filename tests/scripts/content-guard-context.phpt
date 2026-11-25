--TEST--
Content guard requires the application context
--FILE--
<?php
$continued = false;
register_shutdown_function(static function () use (&$continued): void {
    echo $continued ? '[FAIL] unexpected continuation' : '[OK] application context required';
});
require __DIR__ . '/../../includes/class-content-guard.php';
$continued = true;
--EXPECT--
[OK] application context required
