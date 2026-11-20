--TEST--
scripts/test-archive-access.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-archive-access.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
