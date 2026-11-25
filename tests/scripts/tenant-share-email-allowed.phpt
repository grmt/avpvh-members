--TEST--
Tenant share request: email_allowed
--FILE--
<?php
$argv = [__FILE__, 'email_allowed'];
require __DIR__ . "/../../scripts/test-tenant-share-security.php";
--EXPECT--
FICTIONAL DOWNLOAD CONTENT
