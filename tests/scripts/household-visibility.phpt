--TEST--
scripts/test-household-visibility.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-household-visibility.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
