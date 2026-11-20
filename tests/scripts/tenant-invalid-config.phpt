--TEST--
scripts/test-tenant-invalid-config.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-tenant-invalid-config.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
