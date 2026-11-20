--TEST--
scripts/test-admin-email-return.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-admin-email-return.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
