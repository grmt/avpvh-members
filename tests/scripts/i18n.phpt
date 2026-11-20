--TEST--
scripts/test-i18n.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-i18n.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
