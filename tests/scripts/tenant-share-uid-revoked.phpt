--TEST--
Tenant share request: uid_revoked
--FILE--
<?php
$argv = [__FILE__, 'uid_revoked'];
require __DIR__ . "/../../scripts/test-tenant-share-security.php";
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|Fatal error|Warning: |FICTIONAL DOWNLOAD CONTENT)).*niet meer geldig.*
