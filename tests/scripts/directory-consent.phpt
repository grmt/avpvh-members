--TEST--
scripts/test-directory-consent.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-directory-consent.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
