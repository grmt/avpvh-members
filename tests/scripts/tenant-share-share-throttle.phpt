--TEST--
Tenant share request: share_throttle
--FILE--
<?php
$argv = [__FILE__, 'share_throttle'];
require __DIR__ . "/../../scripts/test-tenant-share-security.php";
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|Fatal error|Warning: |FICTIONAL DOWNLOAD CONTENT)).*Te veel pogingen.*
