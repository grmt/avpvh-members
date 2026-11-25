--TEST--
Tenant share request: share_form
--FILE--
<?php
$argv = [__FILE__, 'share_form'];
require __DIR__ . "/../../scripts/test-tenant-share-security.php";
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|Fatal error|Warning: |FICTIONAL DOWNLOAD CONTENT)).*Dit e-mailadres heeft geen toegang.*
