--TEST--
scripts/test-activity-delete.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-activity-delete.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
