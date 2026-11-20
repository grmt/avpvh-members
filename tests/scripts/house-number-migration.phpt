--TEST--
scripts/test-house-number-migration.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-house-number-migration.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
