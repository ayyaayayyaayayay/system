<?php

const AI_COMMENT_FILTER_VERSION = 'repetitive-comments-v1';

function aiCommentRepetitionFlag(array $row): bool {
    $detail = $row['credibilityComponents']['behaviorDetails'] ?? [];
    $live = $row['behaviorRepetition'] ?? [];
    foreach ([$row['commentRepetitiveFlag'] ?? false, $detail['commentRepetitiveFlag'] ?? false,
        $live['commentRepetitiveFlag'] ?? false] as $flag) {
        if ($flag === true || $flag === 1 || $flag === '1') return true;
    }
    return false;
}

function aiCommentFingerprint(string $text): string {
    $text = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
    $text = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $text);
    return trim(preg_replace('/\s+/u', ' ', $text ?? ''));
}

function buildAiCommentItemsFromEvaluations(array $evaluations): array {
    $items = [];
    foreach ($evaluations as $index => $evaluation) {
        if (!is_array($evaluation)) continue;
        $id = (string) ($evaluation['id'] ?? $evaluation['evaluationKey'] ?? 'evaluation-' . $index);
        $texts = []; $seenGeneral = [];
        foreach (['comments','comment','feedback'] as $field) {
            $text = trim((string)($evaluation[$field] ?? ''));
            if ($text === '' || isset($seenGeneral[$text])) continue;
            $seenGeneral[$text] = true; $texts[$field] = $text;
        }
        $qualitative = is_array($evaluation['qualitative'] ?? null) ? $evaluation['qualitative'] : [];
        foreach ($qualitative as $question => $text) $texts['question-' . $question] = $text;
        if (!$qualitative) foreach ($evaluation['qualitativeResponses'] ?? [] as $question => $response) {
            $texts['question-' . $question] = is_array($response)
                ? ($response['text'] ?? $response['answer'] ?? $response['comment'] ?? $response['response'] ?? '') : $response;
        }
        foreach ($texts as $field => $text) {
            $text = trim((string) $text);
            if ($text === '') continue;
            $items[] = ['id' => $id . ':' . $field, 'submissionId' => $id, 'text' => $text,
                'commentRepetitiveFlag' => aiCommentRepetitionFlag($evaluation)];
        }
    }
    return $items;
}

// Exclude all members of a repeated group, rather than sending one representative.
// Detect before batching/limits; reference rows let a new submission be compared
// with the complete semester without treating that submission as its own duplicate.
function filterAiCommentItems(array $items, array $reference = []): array {
    $groups = []; $positions = []; $itemGroups = [];
    foreach ([['rows'=>$items, 'target'=>true], ['rows'=>$reference, 'target'=>false]] as $set) {
        foreach ($set['rows'] as $index => $item) {
            $row = is_array($item) ? $item : ['text'=>(string)$item];
            $text = trim((string) ($row['text'] ?? $row['comment'] ?? ''));
            $fingerprint = aiCommentFingerprint($text);
            if ($fingerprint === '') continue;
            $key = 'text:' . $fingerprint;
            if (!isset($positions[$key])) {
                $positions[$key] = count($groups);
                $tokens = array_values(array_unique(array_filter(explode(' ', $fingerprint), fn($t)=>strlen($t)>=3)));
                $negation = array_values(array_intersect(['not','never','no','without','hindi','wala'], explode(' ', $fingerprint)));
                $groups[] = ['counts'=>['target'=>0,'reference'=>0], 'excluded'=>false, 'tokens'=>$tokens, 'negation'=>implode('|',$negation)];
            }
            $group = $positions[$key];
            if ($set['target']) $itemGroups[$index] = $group;
            $setKey = $set['target'] ? 'target' : 'reference';
            $groups[$group]['counts'][$setKey] += max(1, (int)($row['occurrences'] ?? 1));
            if (aiCommentRepetitionFlag($row)) $groups[$group]['excluded'] = true;
        }
    }

    $postings = [];
    foreach ($groups as $index => &$group) {
        if (max($group['counts']) > 1) $group['excluded'] = true;
        if (count($group['tokens']) >= 5) foreach ($group['tokens'] as $token) $postings[$token][] = $index;
    }
    unset($group);
    foreach ($groups as $index => $group) {
        if (count($group['tokens']) < 5) continue;
        $overlaps = [];
        foreach ($group['tokens'] as $token) foreach ($postings[$token] ?? [] as $other) {
            if ($other > $index) $overlaps[$other] = ($overlaps[$other] ?? 0) + 1;
        }
        foreach ($overlaps as $other => $overlap) {
            if ($overlap >= 5 && $group['negation'] === $groups[$other]['negation']
                && $overlap / max(count($group['tokens']), count($groups[$other]['tokens'])) >= 0.9) {
                $groups[$index]['excluded'] = $groups[$other]['excluded'] = true;
            }
        }
    }

    $allowed = []; $excluded = [];
    foreach ($items as $index => $item) {
        if (!isset($itemGroups[$index])) continue;
        if ($groups[$itemGroups[$index]]['excluded']) $excluded[] = $item;
        else $allowed[] = $item;
    }
    return ['items'=>$allowed, 'excluded'=>$excluded, 'inputCount'=>count($allowed)+count($excluded),
        'excludedCount'=>count($excluded), 'version'=>AI_COMMENT_FILTER_VERSION];
}
