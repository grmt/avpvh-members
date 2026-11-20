--TEST--
scripts/test-members-section-hiding.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-members-section-hiding.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
