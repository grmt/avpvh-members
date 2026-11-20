--TEST--
scripts/test-db-upgrade-steps.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-db-upgrade-steps.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
