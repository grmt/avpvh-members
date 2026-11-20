--TEST--
scripts/test-tenant-directory.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-tenant-directory.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
