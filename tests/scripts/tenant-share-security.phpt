--TEST--
scripts/test-tenant-share-security.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-tenant-share-security.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
