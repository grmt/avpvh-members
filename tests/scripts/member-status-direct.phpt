--TEST--
scripts/test-member-status-direct.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-member-status-direct.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
