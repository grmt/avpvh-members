--TEST--
scripts/test-tenant-sso.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-tenant-sso.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
