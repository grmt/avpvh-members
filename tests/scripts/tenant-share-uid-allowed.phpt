--TEST--
Tenant share request: uid_allowed
--FILE--
<?php
$argv = [__FILE__, 'uid_allowed'];
require __DIR__ . "/../../scripts/test-tenant-share-security.php";
--EXPECT--
FICTIONAL DOWNLOAD CONTENT
