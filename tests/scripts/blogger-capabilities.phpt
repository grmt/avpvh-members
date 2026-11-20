--TEST--
scripts/test-blogger-capabilities.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-blogger-capabilities.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
