--TEST--
scripts/test-media-promotion.php
--FILE--
<?php
require __DIR__ . '/../../scripts/test-media-promotion.php';
--EXPECTREGEX--
\A(?s)(?!.*(?:\[FAIL\]|\bFAIL\b|Fatal error|Warning: |Deprecated: |Notice: )).*
