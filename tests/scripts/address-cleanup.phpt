--TEST--
scripts/test-address-cleanup.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-address-cleanup.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
