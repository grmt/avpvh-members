--TEST--
scripts/test-address-matcher.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-address-matcher.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
