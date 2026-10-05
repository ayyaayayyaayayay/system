<?php
// Targeted, repeat-safe migration. Does not recreate any table or evaluation.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/state_helpers.php';
ensureEvaluationBehaviorMetadataSchema($pdo);
ensureEvaluationCredibilitySchema($pdo);
echo "Evaluation credibility review migration applied. Historical eligibility preserved.\n";
