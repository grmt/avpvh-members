--TEST--
scripts/test-login-attempt-query.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-login-attempt-query.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
