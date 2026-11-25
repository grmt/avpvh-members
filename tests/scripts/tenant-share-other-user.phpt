--TEST--
Tenant share request: other_user
--FILE--
<?php
$argv = [__FILE__, 'other_user'];
require __DIR__ . "/../../scripts/test-tenant-share-security.php";
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|Fatal error|Warning: |FICTIONAL DOWNLOAD CONTENT)).*niet meer geldig.*
