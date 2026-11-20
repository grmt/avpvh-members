--TEST--
scripts/test-access-hardening.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-access-hardening.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
