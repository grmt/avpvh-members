--TEST--
scripts/test-create-camp-visitors.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-create-camp-visitors.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
