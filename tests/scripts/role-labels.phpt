--TEST--
scripts/test-role-labels.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-role-labels.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
