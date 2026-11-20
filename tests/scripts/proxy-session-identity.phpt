--TEST--
scripts/test-proxy-session-identity.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-proxy-session-identity.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
