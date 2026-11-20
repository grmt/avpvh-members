--TEST--
scripts/test-name-matcher-loose.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-name-matcher-loose.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
