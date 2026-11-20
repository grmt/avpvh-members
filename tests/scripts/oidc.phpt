--TEST--
scripts/test-oidc.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-oidc.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
