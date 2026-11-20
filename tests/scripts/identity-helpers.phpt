--TEST--
scripts/test-identity-helpers.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-identity-helpers.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
