--TEST--
scripts/test-activity-list-rich.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-activity-list-rich.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
