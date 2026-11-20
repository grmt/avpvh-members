--TEST--
scripts/test-ledenlijst-render.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-ledenlijst-render.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
