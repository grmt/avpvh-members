--TEST--
Session capabilities require an available session provider
--FILE--
<?php
define('ABSPATH', __DIR__ . '/');
function is_user_logged_in(): bool { return true; }
require __DIR__ . '/../../includes/class-access.php';
echo AVPVH_Access::current_session_has_2fa() ? '[FAIL] unexpected capability' : '[OK] session provider required';
--EXPECT--
[OK] session provider required
