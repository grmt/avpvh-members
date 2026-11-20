--TEST--
scripts/test-page-permissions.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-page-permissions.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
