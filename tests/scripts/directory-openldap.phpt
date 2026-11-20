--TEST--
scripts/test-directory-openldap.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-directory-openldap.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
