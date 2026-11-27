--TEST--
scripts/test-wp-identity-hint.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-wp-identity-hint.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
