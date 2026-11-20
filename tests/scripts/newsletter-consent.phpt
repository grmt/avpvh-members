--TEST--
scripts/test-newsletter-consent.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-newsletter-consent.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
