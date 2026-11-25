--TEST--
scripts/test-ui-actions.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-ui-actions.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
