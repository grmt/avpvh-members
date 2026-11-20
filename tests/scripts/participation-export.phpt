--TEST--
scripts/test-participation-export.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-participation-export.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
