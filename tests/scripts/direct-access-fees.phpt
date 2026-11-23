--TEST--
includes/class-fees.php stops when loaded outside WordPress
--FILE--
<?php
require __DIR__ . '/../../includes/class-fees.php';
echo 'still running';
--EXPECT--
