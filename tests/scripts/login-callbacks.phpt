--TEST--
scripts/test-login-callbacks.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-login-callbacks.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
