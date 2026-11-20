--TEST--
scripts/test-address-entry-check.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-address-entry-check.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
