<?php

require_once __DIR__ . '/evaluation_bias_rules.php';

const EVALUATION_CREDIBILITY_THRESHOLD = 70;
const EVALUATION_ANALYTICS_STATUSES = ['AUTO_ACCEPTED', 'ACCEPTED_BY_HR'];

class EvaluationReviewConflict extends RuntimeException {}

function evaluationAnalyticsEligibilitySql(string $alias = 'e'): string {
    if (!preg_match('/^[a-z_]+$/i', $alias)) throw new InvalidArgumentException('Invalid SQL alias.');
    return "$alias.status = 'submitted' AND $alias.credibility_status IN ('AUTO_ACCEPTED', 'ACCEPTED_BY_HR')";
}

function isEvaluationEligibleForAnalytics(array $row): bool {
    return strtolower((string) ($row['status'] ?? 'submitted')) === 'submitted'
        && in_array($row['credibilityStatus'] ?? $row['credibility_status'] ?? '', EVALUATION_ANALYTICS_STATUSES, true);
}

function ensureEvaluationCredibilitySchema(PDO $pdo): void {
    $columns = [
        'behavior_score' => 'TINYINT UNSIGNED DEFAULT NULL',
        'credibility_score' => 'TINYINT UNSIGNED DEFAULT NULL',
        'credibility_components' => 'JSON DEFAULT NULL',
        'credibility_flags' => 'JSON DEFAULT NULL',
        'credibility_calculated_at' => 'DATETIME DEFAULT NULL',
        'credibility_status' => "VARCHAR(24) DEFAULT NULL",
        'credibility_reviewed_by' => 'BIGINT UNSIGNED DEFAULT NULL',
        'credibility_reviewed_at' => 'DATETIME DEFAULT NULL',
        'credibility_review_decision' => 'VARCHAR(6) DEFAULT NULL',
        'credibility_review_note' => 'VARCHAR(1000) DEFAULT NULL',
    ];
    foreach ($columns as $name => $definition) {
        if (!columnExistsInCurrentSchema($pdo, 'evaluations', $name)) {
            $pdo->exec("ALTER TABLE evaluations ADD COLUMN $name $definition");
        }
    }
    if (!indexExistsInCurrentSchema($pdo, 'evaluations', 'idx_evaluations_credibility')) {
        $pdo->exec('CREATE INDEX idx_evaluations_credibility ON evaluations (credibility_status, semester_id, evaluatee_user_id, id)');
    }
    // Grandfather historical submissions without inventing scores or evidence.
    // New submissions always write an authoritative decision in their transaction.
    $pdo->exec("UPDATE evaluations SET credibility_status = 'AUTO_ACCEPTED',
        credibility_components = '{\"legacyPreserved\":true,\"reason\":\"Historical eligibility preserved; no official historical score\"}'
        WHERE credibility_status IS NULL");
}

function credibilityEvaluationType(array $e): string {
    $v = strtolower((string) ($e['evaluationType'] ?? $e['evaluatorRole'] ?? ''));
    if (str_contains($v, 'student')) return 'student';
    if (str_contains($v, 'supervisor') || in_array($v, ['dean', 'procoor'], true)) return 'supervisor';
    return 'peer';
}

function credibilityCommentPattern(array $e): array {
    $texts = array_merge([(string) ($e['comments'] ?? '')], array_values($e['qualitative'] ?? []));
    $normalized = [];
    foreach ($texts as $text) {
        $s = trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9\s]/', ' ', strtolower((string) $text))));
        if ($s !== '') $normalized[] = $s;
    }
    $unique = array_values(array_unique($normalized));
    $tokens = [];
    foreach ($unique as $text) foreach (explode(' ', $text) as $token) {
        if (strlen($token) >= 3 && !ctype_digit($token)) $tokens[$token] = true;
    }
    sort($unique, SORT_STRING);
    return ['count' => count($normalized), 'unique' => count($unique), 'fingerprint' => implode(' || ', $unique), 'tokens' => array_keys($tokens)];
}

function credibilitySimilarity(array $a, array $b): array {
    $ra = $a['evaluation']['ratings'] ?? [];
    $rb = $b['evaluation']['ratings'] ?? [];
    $shared = array_intersect_key($ra, $rb);
    $matches = 0;
    foreach ($shared as $key => $v) if (trim((string) $v) !== '' && trim((string) $v) === trim((string) $rb[$key])) $matches++;
    $pa = $a['pattern']; $pb = $b['pattern'];
    $exact = $pa['fingerprint'] !== '' && $pa['fingerprint'] === $pb['fingerprint'];
    $overlap = count(array_intersect($pa['tokens'], $pb['tokens']));
    $denominator = max(count($pa['tokens']), count($pb['tokens']));
    return [
        'ratingOverlap' => count($shared), 'ratingSimilarity' => count($shared) ? $matches / count($shared) : 0,
        'commentOverlap' => $overlap, 'commentSimilarity' => $exact ? 1 : ($denominator ? $overlap / $denominator : 0), 'exact' => $exact,
    ];
}

// PHP port of analyzeEvaluationBehaviorRecords. Compare only the same semester/type;
// freeze each official result at submission. Missing timing is deliberately NULL.
function calculateEvaluationBehaviorRecords(array $evaluations, ?string $onlyId = null): array {
    $records = []; $seconds = [];
    foreach ($evaluations as $e) {
        $meta = $e['behaviorMeta'] ?? null;
        $spq = is_array($meta) ? ($meta['secondsPerQuestion'] ?? null) : null;
        $timed = is_array($meta) && ($meta['captureVersion'] ?? 0) >= 1 && is_numeric($spq) && $spq > 0;
        $ratings = array_values(array_filter($e['ratings'] ?? [], 'is_numeric'));
        $pattern = credibilityCommentPattern($e);
        $cohort = ($e['semesterId'] ?? '') . '|' . credibilityEvaluationType($e);
        if ($timed) $seconds[$cohort][] = (float) $spq;
        $target = !empty($e['courseOfferingId']) ? 'offering:' . $e['courseOfferingId'] : 'professor:' . ($e['targetProfessorId'] ?? $e['evaluateeUserId'] ?? '');
        $records[] = ['evaluation' => $e, 'pattern' => $pattern, 'ratings' => $ratings, 'cohort' => $cohort,
            'actor' => $e['evaluatorUserId'] ?? $e['studentUserId'] ?? $e['evaluatorId'] ?? '',
            'target' => $target, 'timed' => $timed, 'seconds' => $timed ? (float) $spq : null];
    }
    $thresholds = [];
    foreach ($seconds as $key => $values) {
        sort($values, SORT_NUMERIC); $n = count($values); $mid = intdiv($n, 2);
        $median = $n % 2 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;
        $thresholds[$key] = max(2.5, $median * .65);
    }
    foreach ($records as &$r) {
        $threshold = $thresholds[$r['cohort']] ?? 0;
        $r['fast'] = $r['timed'] && $r['seconds'] <= $threshold;
        $r['speedRisk'] = $r['timed'] ? ($r['fast'] ? max(0, min(1, 1 - $r['seconds'] / $threshold)) : 0) : null;
        $counts = array_count_values(array_map('strval', $r['ratings']));
        $n = count($r['ratings']); $share = $n ? max($counts) / $n : 0;
        $commentUniform = $r['pattern']['count'] >= 2 && $r['pattern']['unique'] === 1;
        $r['uniform'] = ($n > 0 && $share >= .9) || $commentUniform;
        $r['uniformRisk'] = max($share >= .9 ? $share : $share * .35, $commentUniform ? 1 : 0);
    }
    unset($r);
    $results = [];
    foreach ($records as $i => $r) {
        if ($onlyId !== null && (string) $r['evaluation']['id'] !== $onlyId) continue;
        $best = ['same' => ['ratingOverlap'=>0,'ratingSimilarity'=>0,'commentOverlap'=>0,'commentSimilarity'=>0,'exact'=>false],
            'cross' => ['ratingOverlap'=>0,'ratingSimilarity'=>0,'commentOverlap'=>0,'commentSimilarity'=>0,'exact'=>false]];
        foreach ($records as $j => $candidate) {
            if ($i === $j || $r['cohort'] !== $candidate['cohort']) continue;
            $same = $r['actor'] !== '' && $r['actor'] === $candidate['actor'];
            if (!$same && ($r['target'] !== $candidate['target'] || (!$r['fast'] && !$candidate['fast']))) continue;
            $key = $same ? 'same' : 'cross'; $sim = credibilitySimilarity($r, $candidate);
            foreach (['rating', 'comment'] as $kind) {
                $o = $kind . 'Overlap'; $s = $kind . 'Similarity';
                if ($sim[$o] > $best[$key][$o] || ($sim[$o] === $best[$key][$o] && $sim[$s] > $best[$key][$s])) {
                    $best[$key][$o] = $sim[$o]; $best[$key][$s] = $sim[$s];
                }
            }
            $best[$key]['exact'] = $best[$key]['exact'] || $sim['exact'];
        }
        $minOverlap = max(2, min(5, count($r['ratings'])));
        $ratingFlag = false; $commentFlag = false;
        foreach ($best as $b) {
            $ratingFlag = $ratingFlag || ($b['ratingOverlap'] >= $minOverlap && $b['ratingSimilarity'] >= .9);
            $commentFlag = $commentFlag || $b['exact'] || ($b['commentOverlap'] >= 5 && $b['commentSimilarity'] >= .9);
        }
        $rs = max($best['same']['ratingSimilarity'], $best['cross']['ratingSimilarity']);
        $cs = max($best['same']['commentSimilarity'], $best['cross']['commentSimilarity']);
        $repetition = max($ratingFlag ? $rs : $rs * .25, $commentFlag ? ($cs ?: 1) : $cs * .25);
        $flags = [];
        if ($r['fast']) $flags[] = 'Rapid completion / low seconds per question';
        if ($r['uniform']) $flags[] = 'Uniform response pattern';
        if ($ratingFlag || $commentFlag) $flags[] = 'Repetitive response pattern';
        $score = $r['timed'] ? (int) round((1 - (.40 * $r['speedRisk'] + .35 * $r['uniformRisk'] + .25 * $repetition)) * 100) : null;
        $veryRapid = $r['timed'] && count($r['ratings']) >= 5 && $r['seconds'] < 2;
        if ($veryRapid) {
            $score = min($score, 65);
            $flags[] = 'Very rapid completion: under 2 seconds per rated question';
        }
        $results[(string) $r['evaluation']['id']] = ['score' => $score, 'flags' => $flags,
            'requiresSpeedReview' => $veryRapid,
            'speedRisk' => $r['speedRisk'], 'uniformityRisk' => $r['uniformRisk'], 'repetitionRisk' => $repetition,
            'fastThreshold' => $r['timed'] ? ($thresholds[$r['cohort']] ?? null) : null];
    }
    return $results;
}

function calculateEvaluationCredibility(array $evaluation, array $cohort, array $behavior, array $biasClassifications = []): array {
    $biasScores = []; $flags = $behavior['flags']; $biasDetails = [];
    foreach (array_merge([$evaluation['comments'] ?? ''], array_values($evaluation['qualitative'] ?? [])) as $text) {
        if (trim((string) $text) === '') continue;
        $classified = $biasClassifications[count($biasScores)] ?? classifyBiasCommentByRules($text);
        $biasScores[] = ['Constructive'=>100, 'Neutral'=>80, 'Biased'=>40][$classified['label']];
        $biasDetails[] = $classified;
        if ($classified['label'] === 'Biased') $flags[] = $classified['reason'];
    }
    $bias = $biasScores ? (int) round(array_sum($biasScores) / count($biasScores)) : null;
    $supervisorRatings = [];
    foreach ($cohort as $row) {
        // The existing comparator is supervisor feedback, not a same-source comparator.
        if (credibilityEvaluationType($evaluation) === 'supervisor') break;
        if (credibilityEvaluationType($row) !== 'supervisor' || (string) $row['id'] === (string) $evaluation['id']) continue;
        if (($row['targetProfessorId'] ?? '') !== ($evaluation['targetProfessorId'] ?? '') || ($row['semesterId'] ?? '') !== ($evaluation['semesterId'] ?? '')) continue;
        foreach ($row['ratings'] ?? [] as $value) if (is_numeric($value)) $supervisorRatings[] = max(1, min(5, (float) $value));
    }
    $ratings = array_values(array_filter($evaluation['ratings'] ?? [], 'is_numeric'));
    $cross = null; $crossStatus = 'Comparator unavailable';
    // Correct the old JS Number(null) conversion: absent comparator is not zero.
    if ($ratings && $supervisorRatings) {
        $difference = round(array_sum($supervisorRatings) / count($supervisorRatings), 2) - array_sum($ratings) / count($ratings);
        $cross = $difference < 2 ? 100 : ($difference < 3 ? 60 : 35);
        $crossStatus = $difference < 2 ? 'No discrepancy' : ($difference < 3 ? 'Discrepancy Medium' : 'Discrepancy High');
        if ($difference >= 2) $flags[] = $crossStatus;
    }
    $components = ['behavior' => $behavior['score'], 'bias' => $bias, 'cross' => $cross];
    $sum = 0; $weight = 0;
    foreach (['behavior'=>.40,'bias'=>.30,'cross'=>.30] as $key => $w) {
        if ($components[$key] !== null) { $sum += $components[$key] * $w; $weight += $w; }
    }
    $score = $weight > 0 ? (int) round($sum / $weight) : 50;
    // Extremely short completion times require review even if other components are positive.
    if (!empty($behavior['requiresSpeedReview'])) $score = min($score, 69);
    return ['behaviorScore'=>$behavior['score'], 'credibilityScore'=>$score,
        'components'=>$components + ['behaviorDetails'=>$behavior, 'biasDetails'=>$biasDetails, 'biasSource'=>count(array_filter($biasDetails, fn($d)=>($d['source']??'')==='openai')) ? 'openai+rule' : 'rule', 'crossStatus'=>$crossStatus, 'formulaVersion'=>2, 'cohortSize'=>count($cohort)],
        'flags'=>array_values(array_unique($flags)), 'status'=>$score >= EVALUATION_CREDIBILITY_THRESHOLD ? 'AUTO_ACCEPTED' : 'PENDING_HR_REVIEW'];
}

function persistEvaluationCredibility(PDO $pdo, int $id, string $semester): void {
    $cohort = buildEvaluationsSnapshotFromTables($pdo, null, ['semesterId'=>$semester, '_includeBehaviorMeta'=>true]);
    $behaviors = calculateEvaluationBehaviorRecords($cohort, 'db-eval-' . $id);
    foreach ($cohort as $e) {
        if ((string) $e['id'] !== 'db-eval-' . $id) continue;
        $biasClassifications = [];
        // Reuse the existing configured OpenAI bias classifier and its rule fallback.
        // CLI/offline callers can use the identical rules without loading an HTTP controller.
        if (function_exists('classifyBiasCommentsWithGeminiBatch')) {
            $config = getGeminiRawConfig($pdo, isOpenAiEnabledForPanelRole($pdo, 'hr'));
            if (trim((string) ($config['apiKey'] ?? '')) !== '') {
                $items = [];
                foreach (array_merge([$e['comments'] ?? ''], array_values($e['qualitative'] ?? [])) as $text) {
                    if (trim((string) $text) !== '') $items[] = ['id'=>(string) count($items), 'comment'=>normalizeBiasDetectionText($text)];
                }
                if ($items) {
                    $response = classifyBiasCommentsWithGeminiBatch($items, $config['apiKey'], $config['model'], max(30000, min(60000, (int) ($config['timeoutMs'] ?? 30000))));
                    foreach ($items as $item) {
                        $classified = $response['items'][$item['id']] ?? null;
                        $biasClassifications[] = is_array($classified)
                            ? ['label'=>normalizeBiasLabel($classified['label'] ?? ''), 'reason'=>normalizeBiasDetectionText($classified['reason'] ?? ''), 'source'=>'openai']
                            : classifyBiasCommentByRules($item['comment']);
                    }
                }
            }
        }
        $result = calculateEvaluationCredibility($e, $cohort, $behaviors[$e['id']], $biasClassifications);
        $stmt = $pdo->prepare('UPDATE evaluations SET behavior_score=?, credibility_score=?, credibility_components=?, credibility_flags=?, credibility_calculated_at=CURRENT_TIMESTAMP, credibility_status=? WHERE id=? AND credibility_status IS NULL');
        $stmt->execute([$result['behaviorScore'], $result['credibilityScore'], json_encode($result['components'], JSON_THROW_ON_ERROR), json_encode($result['flags'], JSON_THROW_ON_ERROR), $result['status'], $id]);
        if ($stmt->rowCount() !== 1) throw new RuntimeException('Credibility decision could not be saved.');
        if ($result['status'] === 'PENDING_HR_REVIEW') naapAuditWrite($pdo, [
            'eventCode'=>'evaluation.credibility.flagged', 'action'=>'Evaluation Flagged for Credibility Review',
            'description'=>'Evaluation #' . $id . ' requires HR credibility review.', 'type'=>'evaluation',
            'actor'=>[], 'targetType'=>'evaluation', 'targetId'=>'db-eval-' . $id,
        ]);
        return;
    }
    throw new RuntimeException('Saved evaluation unavailable for credibility calculation.');
}

function assertEvaluationReviewHr(array $actor): void {
    if (strtolower(trim((string) ($actor['role'] ?? ''))) !== 'hr') throw new CampusAccessDeniedException('Only HR may review evaluation credibility.');
}

function reviewEvaluationCredibility(PDO $pdo, array $actor, array $ids, string $decision, string $note = ''): array {
    assertEvaluationReviewHr($actor);
    if (!in_array($decision, ['accept','reject'], true) || !$ids || count($ids) > 500 || strlen($note) > 1000) throw new InvalidArgumentException('Invalid review request.');
    $normalized = [];
    foreach ($ids as $id) {
        if (!preg_match('/^(?:db-eval-)?([1-9][0-9]*)$/', (string) $id, $m)) throw new InvalidArgumentException('Invalid evaluation reference.');
        $normalized[(int) $m[1]] = (int) $m[1];
    }
    sort($normalized, SORT_NUMERIC);
    $context = buildCampusAuthorizationContext($pdo, $actor);
    $status = $decision === 'accept' ? 'ACCEPTED_BY_HR' : 'REJECTED_BY_HR';
    $pdo->beginTransaction();
    try {
        $lock = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : '';
        $stmt = $pdo->prepare('SELECT e.id, e.evaluatee_user_id, e.status, e.credibility_status, s.is_current FROM evaluations e JOIN semesters s ON s.id=e.semester_id WHERE e.id IN (' . implode(',', array_fill(0,count($normalized),'?')) . ') ORDER BY e.id' . $lock);
        $stmt->execute($normalized); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($normalized)) throw new InvalidArgumentException('Evaluation not found.');
        foreach ($rows as $row) {
            campusAuthorizationAssertResourceAccess($pdo, $context, 'user', (int) $row['evaluatee_user_id'], 'credibility-review');
            if ((int) $row['is_current'] !== 1) throw new EvaluationReviewConflict('Previous semesters are view-only. Only current-semester evaluations may be reviewed.');
            if ($row['status'] !== 'submitted' || !in_array($row['credibility_status'], ['PENDING_HR_REVIEW','AUTO_ACCEPTED'], true)) throw new EvaluationReviewConflict('Evaluation #' . $row['id'] . ' is no longer available for review (' . $row['credibility_status'] . '). Refresh the review queue.');
        }
        $update = $pdo->prepare("UPDATE evaluations SET credibility_status=?, credibility_reviewed_by=?, credibility_reviewed_at=CURRENT_TIMESTAMP, credibility_review_decision=?, credibility_review_note=? WHERE id=? AND credibility_status IN ('PENDING_HR_REVIEW','AUTO_ACCEPTED')");
        foreach ($rows as $row) {
            $update->execute([$status, resolveStoredUserIdNumber($actor['id'] ?? ''), $decision, trim($note), $row['id']]);
            if ($update->rowCount() !== 1) throw new EvaluationReviewConflict('Evaluation was already reviewed.');
            naapAuditWrite($pdo, ['eventCode'=>'evaluation.credibility.' . $decision, 'action'=>'HR Credibility ' . ucfirst($decision),
                'description'=>'Evaluation #' . $row['id'] . ': ' . $row['credibility_status'] . ' -> ' . $status . '.',
                'type'=>'evaluation', 'actor'=>$actor, 'targetType'=>'evaluation', 'targetId'=>'db-eval-' . $row['id']]);
        }
        if (count($rows) > 1) naapAuditWrite($pdo, ['eventCode'=>'evaluation.credibility.bulk_' . $decision,
            'action'=>'HR Bulk Credibility ' . ucfirst($decision), 'description'=>count($rows) . ' evaluations changed to ' . $status . '.',
            'type'=>'evaluation', 'actor'=>$actor, 'targetType'=>'evaluation_review', 'targetId'=>'']);
        $pdo->commit();
        return ['updated'=>count($rows),'status'=>$status,'evaluationIds'=>$normalized];
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}

function listEvaluationCredibilityReviews(PDO $pdo, array $actor, array $filters): array {
    assertEvaluationReviewHr($actor);
    $context = buildCampusAuthorizationContext($pdo, $actor);
    $scope = resolveAuthorizedCampusSelection($pdo, $context, $filters['campus'] ?? '', 'credibility-review');
    $where = ["e.status = 'submitted'"]; $params = [];
    if (empty($scope['isAll'])) { $where[] = 'p.campus_id = ?'; $params[] = (int) $scope['campusId']; }
    if (!empty($filters['semesterId']) && $filters['semesterId'] !== 'all') { $where[] = 's.slug = ?'; $params[] = (string) $filters['semesterId']; }
    if (!empty($filters['evaluationType']) && $filters['evaluationType'] !== 'all') {
        $where[] = 't.code = ?'; $params[] = normalizeEvaluationTypeFilterToDatabaseCode($filters['evaluationType']);
    }
    if (trim((string) ($filters['search'] ?? '')) !== '') { $where[] = 'p.name LIKE ?'; $params[] = '%' . trim((string) $filters['search']) . '%'; }
    $from = ' FROM evaluations e JOIN users p ON p.id=e.evaluatee_user_id JOIN semesters s ON s.id=e.semester_id JOIN evaluation_types t ON t.id=e.evaluation_type_id WHERE ';
    $count = $pdo->prepare('SELECT e.credibility_status, COUNT(*) AS total' . $from . implode(' AND ', $where) . ' GROUP BY e.credibility_status');
    $count->execute($params);
    $counts = array_fill_keys(['AUTO_ACCEPTED','PENDING_HR_REVIEW','ACCEPTED_BY_HR','REJECTED_BY_HR'], 0);
    foreach ($count->fetchAll(PDO::FETCH_ASSOC) as $row) $counts[$row['credibility_status']] = (int) $row['total'];
    $status = $filters['status'] ?? 'PENDING_HR_REVIEW';
    if ($status !== 'all') {
        if (!array_key_exists($status, $counts)) throw new InvalidArgumentException('Invalid review status.');
        $where[] = 'e.credibility_status = ?'; $params[] = $status;
    }
    $limit = max(1, min(100, (int) ($filters['limit'] ?? 50)));
    $offset = max(0, (int) ($filters['offset'] ?? 0));
    $stmt = $pdo->prepare('SELECT e.id, p.name AS professor, t.code AS evaluation_type, s.slug AS semester, s.is_current,
        e.submitted_at, e.behavior_score, e.credibility_score, e.credibility_status,
        e.credibility_components, e.credibility_flags, e.credibility_calculated_at,
        e.credibility_reviewed_at, e.credibility_review_decision, e.credibility_review_note'
        . $from . implode(' AND ', $where) . ' ORDER BY e.submitted_at DESC, e.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset);
    $stmt->execute($params); $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['canReview'] = (int) $row['is_current'] === 1 && in_array($row['credibility_status'], ['PENDING_HR_REVIEW','AUTO_ACCEPTED'], true);
        foreach (['credibility_components','credibility_flags'] as $key) $row[$key] = json_decode($row[$key] ?? 'null', true);
        foreach (['behavior_score','credibility_score'] as $key) $row[$key] = $row[$key] === null ? null : (int) $row[$key];
    }
    unset($row);
    return ['items'=>$rows, 'counts'=>$counts, 'total'=>array_sum($counts), 'eligible'=>$counts['AUTO_ACCEPTED']+$counts['ACCEPTED_BY_HR'],
        'filteredTotal'=>$status === 'all' ? array_sum($counts) : $counts[$status], 'limit'=>$limit, 'offset'=>$offset];
}

function getEvaluationCredibilityDetail(PDO $pdo, array $actor, $id): array {
    assertEvaluationReviewHr($actor);
    if (!preg_match('/^(?:db-eval-)?([1-9][0-9]*)$/', (string) $id, $match)) throw new InvalidArgumentException('Invalid evaluation reference.');
    $rows = buildEvaluationsSnapshotFromTables($pdo, (int) $match[1], ['_includeBehaviorMeta'=>true]);
    if (!$rows) throw new InvalidArgumentException('Evaluation not found.');
    $row = $rows[0];
    campusAuthorizationAssertResourceAccess($pdo, buildCampusAuthorizationContext($pdo, $actor), 'user', resolveStoredUserIdNumber($row['targetProfessorId']), 'credibility-review');
    $semester = $pdo->prepare('SELECT is_current FROM semesters WHERE slug=?');
    $semester->execute([$row['semesterId']]);
    $row['canReview'] = (int) $semester->fetchColumn() === 1 && in_array($row['credibilityStatus'], ['PENDING_HR_REVIEW','AUTO_ACCEPTED'], true);
    // Explicit allowlist: evaluator identities and identifiers never leave this endpoint.
    return array_intersect_key($row, array_flip(['id','targetProfessor','evaluationType','semesterId','submittedAt','ratings','qualitative','comments',
        'behaviorMeta','behaviorScore','credibilityScore','credibilityComponents','credibilityFlags','credibilityCalculatedAt','credibilityStatus','canReview']));
}

function buildProfessorAnalyticsEligibleEvaluations(PDO $pdo, array $actor, string $professor, string $semester = 'all'): array {
    if (!in_array(strtolower($actor['role'] ?? ''), ['hr','admin','vpaa'], true)) throw new CampusAccessDeniedException('Permission denied.');
    $id = resolveStoredUserIdNumber($professor);
    if ($id <= 0) throw new InvalidArgumentException('Professor reference required.');
    campusAuthorizationAssertResourceAccess($pdo, buildCampusAuthorizationContext($pdo, $actor), 'user', $id, 'professor-analytics');
    $filters = ['evaluateeUserId'=>$id, 'semesterId'=>$semester, 'analyticsEligible'=>true];
    $rows = buildEvaluationsSnapshotWithLegacy($pdo, $filters, $filters);
    return array_values(array_filter($rows, fn($e) => isEvaluationEligibleForAnalytics($e)
        && resolveStoredUserIdNumber($e['targetProfessorId'] ?? $e['evaluateeUserId'] ?? '') === $id));
}

// Reuse the existing PHP SET implementation; this constructs inputs, not AI output.
function buildProfessorAnalyticsAuthoritativePayload(PDO $pdo, array $actor, array $request): array {
    require_once __DIR__ . '/faculty_report_helper.php';
    $professorId = (string) ($request['professor']['id'] ?? '');
    $semester = (string) ($request['semesterId'] ?? 'all');
    $evaluations = buildProfessorAnalyticsEligibleEvaluations($pdo, $actor, $professorId, $semester);
    $numericId = resolveStoredUserIdNumber($professorId);
    $panelRole = strtolower($actor['role'] ?? 'hr');
    $users = fetchUsersSnapshotByFilters($pdo, ['roles'=>['professor','dean','procoor','supervisor']], false);
    $professor = null;
    foreach ($users as $u) if (resolveStoredUserIdNumber($u['id'] ?? '') === $numericId) $professor = $u;
    if (!$professor || ($professor['role'] ?? '') !== 'professor') throw new InvalidArgumentException('Professor not found.');
    $sql = 'SELECT co.id, co.professor_id, co.section_name, sub.subject_code FROM course_offerings co JOIN subjects sub ON sub.id=co.subject_id JOIN semesters s ON s.id=co.semester_id
        WHERE co.professor_id=? AND (co.is_active=1 OR EXISTS (SELECT 1 FROM student_course_enrollments sce WHERE sce.course_offering_id=co.id AND sce.status=\'completed\'))';
    $params = [$numericId];
    if ($semester !== '' && $semester !== 'all') { $sql .= ' AND s.slug=?'; $params[] = $semester; }
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $offerings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $enrollments = facultyReportFetchEligibleEnrollmentRowsForOfferingIds($pdo, array_column($offerings,'id'));
    $student = array_values(array_filter($evaluations, fn($e) => credibilityEvaluationType($e) === 'student'));
    $set = facultyReportBuildSetSummaryRowsFromInputs($offerings, $student, $enrollments);
    $averages = ['student'=>isset($set['overall_set_rating']) ? round($set['overall_set_rating']/20, 2) : null, 'professor'=>null, 'supervisor'=>null];
    $counts = ['student'=>0, 'professor'=>0, 'supervisor'=>0];
    $raters = ['professor'=>[], 'supervisor'=>[]]; $ratings = ['professor'=>[], 'supervisor'=>[]];
    $comments = []; $dedupe = [];
    $evaluationCounts = ['student'=>0,'professor'=>0,'supervisor'=>0];
    $distributionScores = ['student'=>[],'professor'=>[],'supervisor'=>[]]; $allRatings = [];
    // Same source order, date/text deduplication, 700 character input and 240 comment cap as the existing browser builder.
    foreach (['student','peer','supervisor'] as $type) foreach ($evaluations as $e) {
        if (credibilityEvaluationType($e) !== $type) continue;
        $source = $type === 'peer' ? 'professor' : $type;
        $evaluationCounts[$source]++;
        $numericRatings = array_values(array_filter($e['ratings'] ?? [], 'is_numeric'));
        $allRatings = array_merge($allRatings, $numericRatings);
        if ($numericRatings) $distributionScores[$source][] = max(1,min(5,round(array_sum($numericRatings)/count($numericRatings))));
        if ($type !== 'student') {
            $rater = (string) ($e['studentUserId'] ?: ($e['studentId'] ?: ($e['evaluatorId'] ?? '')));
            if ($rater !== '') $raters[$source][$rater] = true;
            foreach ($e['ratings'] ?? [] as $v) if (is_numeric($v)) $ratings[$source][] = max(1,min(5,(float)$v));
        }
        $texts = array_merge(array_values($e['qualitative'] ?? []), [$e['comments'] ?? '']);
        if ($panelRole === 'admin') $texts = [implode(' | ', array_filter(array_map('trim', $texts), fn($v) => $v !== ''))];
        if ($panelRole === 'vpaa') {
            $texts = [$e['comments'] ?? '', $e['comment'] ?? '', $e['feedback'] ?? ''];
            foreach ($e['qualitativeResponses'] ?? [] as $v) $texts[] = is_array($v) ? ($v['text'] ?? $v['answer'] ?? $v['comment'] ?? $v['response'] ?? '') : $v;
            $texts = array_merge($texts, array_values($e['qualitative'] ?? []));
        }
        foreach ($texts as $text) {
            $text = trim((string) $text); if ($text === '') continue;
            $counts[$source]++;
            $text = preg_replace('/\s+/', ' ', $text);
            $length = function_exists('mb_strlen') ? mb_strlen($text) : strlen($text);
            if ($length > 700) {
                $limit = $panelRole === 'vpaa' ? 700 : 699;
                $text = function_exists('mb_substr') ? mb_substr($text,0,$limit) : substr($text,0,$limit);
                if ($panelRole !== 'vpaa') $text = rtrim($text) . '…';
            }
            $date = substr((string) ($e['submittedAt'] ?? ''),0,10);
            $key = $source . '|' . strtolower($text) . '|' . $date;
            if ($panelRole !== 'vpaa' && isset($dedupe[$key])) continue;
            $dedupe[$key] = true;
            $comments[] = ['id'=>$professorId . '_' . (count($comments)+1), 'source'=>['student'=>'Student to Professor','professor'=>'Professor to Professor','supervisor'=>'Supervisor to Professor'][$source], 'text'=>$text];
        }
    }
    foreach ($ratings as $key=>$values) if ($values) $averages[$key] = round(array_sum($values)/count($values),2);
    if ($panelRole === 'vpaa') foreach ($distributionScores as $key=>$values) $averages[$key] = $values ? round(array_sum($values)/count($values),2) : null;
    $weighted=0; $weight=0;
    foreach (['student'=>.5,'professor'=>.25,'supervisor'=>.25] as $key=>$w) if ($averages[$key] !== null && $averages[$key] > 0) { $weighted += $averages[$key]*$w; $weight += $w; }
    $combined = $weight ? round($weighted/$weight,2) : null;
    $norm = fn($v) => strtolower(trim((string) $v));
    $peers = array_filter($users, fn($u) => ($u['role'] ?? '') === 'professor' && $norm($u['status'] ?? '') !== 'inactive');
    $supervisors = array_filter($users, function($u) use($professor,$norm) {
        $campus=$norm($professor['campus'] ?? $professor['campusSlug'] ?? ''); $uc=$norm($u['campus'] ?? $u['campusSlug'] ?? '');
        $department=$norm($professor['department'] ?? $professor['institute'] ?? '');
        return in_array($u['role'] ?? '',['procoor','dean','supervisor'],true) && $norm($u['status'] ?? '') !== 'inactive'
            && (!$campus || !$uc || $campus===$uc) && $department !== '' && $department===$norm($u['department'] ?? $u['institute'] ?? '');
    });
    $coordinators=array_filter($supervisors, fn($u) => ($u['role']??'')==='procoor' && $norm($professor['programCode']??$professor['program']??'')!=='' && $norm($u['programCode']??$u['program']??'')===$norm($professor['programCode']??$professor['program']??''));
    $deans=array_filter($supervisors,fn($u)=>($u['role']??'')==='dean');
    $supervisors=$coordinators ?: ($deans ?: array_filter($supervisors,fn($u)=>($u['role']??'')==='supervisor'));
    $totalRaters=($set['total_students']??0)+max(count($peers)-1,count($raters['professor']))+max(count($supervisors),count($raters['supervisor']));
    $totalEvaluated=($set['completed_evaluations']??0)+count($raters['professor'])+count($raters['supervisor']);
    if ($panelRole === 'admin' || $panelRole === 'vpaa') {
        $totalEvaluated=($set['completed_evaluations']??0)+$evaluationCounts['professor']+$evaluationCounts['supervisor'];
        $totalRaters=($set['total_students']??0)+max(count($peers)-1,$panelRole==='admin' ? $evaluationCounts['professor'] : 0)
            +max(count($supervisors) ?: 1,$panelRole==='admin' ? $evaluationCounts['supervisor'] : 0);
    }
    $overall = $panelRole === 'vpaa' ? ($allRatings ? round(array_sum($allRatings)/count($allRatings),1) : null) : ($averages['student'] ?? $combined);
    $responseRate = $totalRaters ? round($totalEvaluated/$totalRaters*100,$panelRole==='vpaa' ? 0 : 2) : ($panelRole==='vpaa' ? 0 : null);
    if ($responseRate !== null && $responseRate > 100) $responseRate = null;
    return ['professor'=>['id'=>$professorId,'name'=>$professor['name'],'semester'=>$semester], 'semesterId'=>$semester,
        'comments'=>array_slice($comments,0,240), 'metrics'=>['overallRating'=>$overall, 'combinedAverage'=>$combined,
            'responseRate'=>$responseRate, 'totalEvaluations'=>$totalEvaluated,
            'averagesBySource'=>$averages,'countsBySource'=>$counts]];
}
