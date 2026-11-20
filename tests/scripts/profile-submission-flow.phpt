--TEST--
scripts/test-profile-submission-flow.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-profile-submission-flow.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
