--TEST--
scripts/test-plugin-load.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-plugin-load.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
