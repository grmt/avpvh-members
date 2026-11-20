--TEST--
scripts/test-login-attempt-date-format.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-login-attempt-date-format.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
