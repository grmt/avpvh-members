--TEST--
scripts/test-member-merge-execute.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-member-merge-execute.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
