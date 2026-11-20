--TEST--
scripts/test-pdok.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-pdok.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
