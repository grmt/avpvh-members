--TEST--
Tenant share request: email_removed
--FILE--
<?php
$argv = [__FILE__, 'email_removed'];
require __DIR__ . "/../../scripts/test-tenant-share-security.php";
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|Fatal error|Warning: |FICTIONAL DOWNLOAD CONTENT)).*niet meer geldig.*
