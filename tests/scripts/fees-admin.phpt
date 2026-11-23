--TEST--
scripts/test-fees-admin.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-fees-admin.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
