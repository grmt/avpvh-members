--TEST--
scripts/test-email-identity-flow.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-email-identity-flow.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
