--TEST--
scripts/test-account-access.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-account-access.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
