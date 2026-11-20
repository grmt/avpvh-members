--TEST--
scripts/test-member-account-flow.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-member-account-flow.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
