--TEST--
scripts/test-member-merge-emergency.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-member-merge-emergency.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
