--TEST--
scripts/test-fees.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-fees.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
