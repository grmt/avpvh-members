--TEST--
scripts/test-household-rights.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-household-rights.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
