--TEST--
scripts/test-fees-templates.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-fees-templates.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
