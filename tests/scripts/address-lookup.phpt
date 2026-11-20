--TEST--
scripts/test-address-lookup.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-address-lookup.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
