--TEST--
scripts/test-ledenlijst-export.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-ledenlijst-export.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
