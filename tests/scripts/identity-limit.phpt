--TEST--
scripts/test-identity-limit.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-identity-limit.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
