--TEST--
scripts/test-media-token.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-media-token.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
