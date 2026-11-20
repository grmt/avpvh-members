--TEST--
scripts/test-tenant-bootstrap.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-tenant-bootstrap.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
