--TEST--
scripts/test-member-merge-email.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-member-merge-email.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
