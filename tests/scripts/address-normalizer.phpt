--TEST--
scripts/test-address-normalizer.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-address-normalizer.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
