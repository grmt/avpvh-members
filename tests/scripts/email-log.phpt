--TEST--
scripts/test-email-log.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-email-log.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
