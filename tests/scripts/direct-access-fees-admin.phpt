--TEST--
includes/class-fees-admin.php stops when loaded outside WordPress
--FILE--
<?php
require __DIR__ . '/../../includes/class-fees-admin.php';
echo 'still running';
--EXPECT--
