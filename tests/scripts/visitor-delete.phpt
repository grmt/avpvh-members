--TEST--
scripts/test-visitor-delete.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-visitor-delete.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
