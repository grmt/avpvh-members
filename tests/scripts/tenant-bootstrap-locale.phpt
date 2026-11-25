--TEST--
Tenant locale setup
--FILE--
<?php
$argv = [__FILE__, "nl_NL"];
require __DIR__ . "/../../scripts/test-tenant-bootstrap.php";
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
