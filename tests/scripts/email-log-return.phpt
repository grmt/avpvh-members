--TEST--
scripts/test-email-log-return.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-email-log-return.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
