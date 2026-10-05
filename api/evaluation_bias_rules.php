<?php

// Existing bias rules shared by HR analysis and authoritative credibility scoring.
function normalizeBiasDetectionText($value) {
    $text = trim((string) $value);
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/\s+/', ' ', $text);
    return trim((string) $text);
}

function normalizeBiasLabel($value) {
    $raw = strtolower(trim((string) $value));
    if ($raw === 'constructive') return 'Constructive';
    if ($raw === 'biased') return 'Biased';
    return 'Neutral';
}

function getBiasLabelSeverity($value) {
    $label = normalizeBiasLabel($value);
    if ($label === 'Biased') return 3;
    if ($label === 'Neutral') return 2;
    return 1;
}

function normalizeBiasDetectionLexiconText($value) {
    $text = strtolower(normalizeBiasDetectionText($value));
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/[^a-z0-9]+/', ' ', $text);
    $text = preg_replace('/\s+/', ' ', (string) $text);
    return trim((string) $text);
}

function countBiasPhraseHits($haystack, array $phrases) {
    $count = 0;
    $text = trim((string) $haystack);
    if ($text === '') {
        return 0;
    }

    foreach ($phrases as $phrase) {
        $needle = trim((string) $phrase);
        if ($needle === '') {
            continue;
        }
        if (strpos($text, $needle) !== false) {
            $count += 1;
        }
    }

    return $count;
}

function countBiasPatternHits($haystack, array $patterns) {
    $count = 0;
    $text = trim((string) $haystack);
    if ($text === '') {
        return 0;
    }

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text) === 1) {
            $count += 1;
        }
    }

    return $count;
}

function classifyBiasCommentByRules($text) {
    $value = normalizeBiasDetectionText($text);
    if ($value === '') {
        return [
            'label' => 'Neutral',
            'reason' => 'Empty or missing feedback.',
            'source' => 'rule',
        ];
    }

    $lower = strtolower($value);
    $lexiconText = normalizeBiasDetectionLexiconText($value);
    // Targeted personal insults, rather than criticism of slides or teaching materials.
    $appearanceAttack = preg_match('/\b(?:professor|prof|teacher|instructor|sir|maam|ma am|he|she)\s+(?:(?:is|looks|look|s)\s+)?(?:(?:so|very|really|extremely|an?|such an?)\s+)*(?:ugly|hideous|unattractive|disgusting looking)\b/', $lexiconText)
        || preg_match('/\b(?:ugly|hideous|unattractive)\s+(?:professor|prof|teacher|instructor)\b/', $lexiconText);
    if ($appearanceAttack && !preg_match('/\b(?:not|never|isn t|is not)\s+(?:an?\s+)?(?:ugly|hideous|unattractive)\b/', $lexiconText)) {
        return ['label'=>'Biased', 'reason'=>'Personal attack on the instructor\'s appearance rather than teaching feedback.', 'source'=>'rule'];
    }
    $words = preg_split('/\s+/', $lower);
    $wordCount = is_array($words) ? count(array_filter($words, function ($w) { return trim((string) $w) !== ''; })) : 0;

    $hostilePhrases = [
        'sucks', 'hate', 'worst', 'stupid', 'dumb', 'useless', 'bobo', 'idiot', 'trash', 'garbage', 'awful',
        'terrible', 'pangit', 'bwisit', 'gago', 'walang kwenta', 'lazy', 'waste of time', 'no effort',
        'zero effort', 'bobo prof', 'i hate', 'we hate', 'doesn t teach anything', 'does not teach anything',
        'doesn t teach at all', 'does not teach at all', 'never teaches', 'barely teaches',
    ];
    $hostilePatterns = [
        '/\b(this|that|the)\s+(professor|teacher|instructor)\s+(sucks|is\s+(useless|lazy|terrible|awful|the worst))\b/',
        '/\b(doesn t|does not)\s+teach\s+(anything|at all)\b/',
        '/\b(never|barely)\s+teaches?\b/',
        '/\b(waste of time|no effort|zero effort)\b/',
        '/\b(useless|lazy|terrible|awful|worst|trash|garbage)\b/',
    ];
    $blanketAttackPhrases = [
        'doesn t really teach anything', 'does not really teach anything',
        'without proper guidance', 'with no proper guidance',
        'left to report the lessons', 'left to report lessons',
        'left to report on our own', 'report the lessons on our own',
        'on our own', 'not learning what we re supposed to', 'not learning what we are supposed to',
        'we re not learning', 'we are not learning',
    ];
    $blanketAttackPatterns = [
        '/\b(doesn t|does not)\s+(really\s+|even\s+|just\s+)?teach(es)?\s+(anything|at all)\b/',
        '/\b(left|forced)\s+to\s+(report|teach|learn)\b/',
        '/\b(without|with no)\s+(proper\s+)?guidance\b/',
        '/\bon\s+our\s+own\b/',
        '/\b(we re|we are|students are)\s+not\s+learning\b/',
        '/\bnot\s+learning\s+what\s+we\s+(re|are)\s+supposed\s+to\b/',
    ];
    $accusatoryPhrases = [
        'relies too much on chatgpt', 'rely too much on chatgpt',
        'uses chatgpt instead of explaining', 'using chatgpt instead of explaining',
        'instead of explaining the lessons', 'instead of explaining lessons',
        'students are the ones reporting', 'students are the one reporting',
        'students are the ones teaching', 'students are the one teaching',
        'without clear instruction', 'without clear instructions',
        'no clear instruction', 'no clear instructions',
        'basically teaching ourselves', 'basically teach ourselves',
        'teaching ourselves', 'teach ourselves',
        'we re basically teaching ourselves', 'we are basically teaching ourselves',
        'we re teaching ourselves', 'we are teaching ourselves',
        'most of the time students are the ones reporting',
    ];
    $accusatoryPatterns = [
        '/\b(rel(y|ies)\s+too\s+much\s+on\s+chatgpt)\b/',
        '/\b(chatgpt)\s+instead\s+of\s+(explaining|teaching)\b/',
        '/\bstudents?\s+are\s+the\s+ones?\s+(reporting|teaching)\b/',
        '/\bwithout\s+clear\s+instruction(s)?\b/',
        '/\bno\s+clear\s+instruction(s)?\b/',
        '/\b(basically\s+)?teach(ing)?\s+ourselves\b/',
    ];
    $negativeEmotionPhrases = [
        'frustrating', 'frustrated', 'disappointing', 'annoying', 'fed up', 'tired of',
    ];
    $teachingIssuePhrases = [
        'teach', 'teaches', 'teaching', 'lesson', 'lessons', 'class', 'classes', 'grading', 'grade', 'grades',
        'attendance', 'feedback', 'examples', 'example', 'explain', 'explains', 'explanation', 'report',
        'reports', 'reporting', 'slides', 'chatgpt', 'activity', 'activities', 'rubric', 'instructions',
        'discussion', 'discussions', 'late', 'prepared', 'unprepared',
    ];
    $constructivePhrases = [
        'should', 'should be', 'should provide', 'should explain',
        'need to', 'needs to', 'could', 'please', 'would help',
        'more examples', 'more guidance', 'clearer instruction', 'clearer instructions',
        'better pacing', 'provide guidance', 'provide feedback', 'clarify',
        'improve', 'improvement', 'be more organized', 'be more prepared',
        'less workload', 'more structured', 'more interactive',
    ];
    $neutralKeywords = ['ok', 'okay', 'fine', 'good', 'nice', 'average', 'pwede'];

    $hostileScore = (countBiasPhraseHits($lexiconText, $hostilePhrases) * 2)
        + (countBiasPatternHits($lexiconText, $hostilePatterns) * 2);
    $blanketAttackScore = countBiasPhraseHits($lexiconText, $blanketAttackPhrases)
        + countBiasPatternHits($lexiconText, $blanketAttackPatterns);
    $accusatoryScore = countBiasPhraseHits($lexiconText, $accusatoryPhrases)
        + countBiasPatternHits($lexiconText, $accusatoryPatterns);
    $negativeEmotionScore = countBiasPhraseHits($lexiconText, $negativeEmotionPhrases);
    $hasTeachingSignal = countBiasPhraseHits($lexiconText, $teachingIssuePhrases) > 0;
    $constructiveScore = countBiasPhraseHits($lexiconText, $constructivePhrases);

    if ($hostileScore >= 2) {
        return [
            'label' => 'Biased',
            'reason' => 'Contains insulting, hostile, or blanket attack language.',
            'source' => 'rule',
        ];
    }

    if ($blanketAttackScore >= 2 && $constructiveScore === 0) {
        return [
            'label' => 'Biased',
            'reason' => 'Contains blanket or absolute accusations rather than improvement-focused feedback.',
            'source' => 'rule',
        ];
    }

    if (
        (($accusatoryScore >= 2) || ($accusatoryScore >= 1 && $negativeEmotionScore >= 1))
        && $constructiveScore === 0
    ) {
        return [
            'label' => 'Biased',
            'reason' => 'Contains strong accusatory wording without a concrete improvement suggestion.',
            'source' => 'rule',
        ];
    }

    if (
        $constructiveScore > 0
        && $wordCount >= 4
        && $hostileScore === 0
        && $blanketAttackScore === 0
        && $accusatoryScore === 0
        && $negativeEmotionScore === 0
    ) {
        return [
            'label' => 'Constructive',
            'reason' => 'Contains respectful feedback with a concrete improvement suggestion.',
            'source' => 'rule',
        ];
    }

    if (
        $constructiveScore > 0
        && $wordCount >= 4
        && $hostileScore === 0
        && $blanketAttackScore === 0
        && $accusatoryScore <= 1
        && $negativeEmotionScore === 0
    ) {
        return [
            'label' => 'Constructive',
            'reason' => 'Includes an improvement suggestion and avoids strong attack language.',
            'source' => 'rule',
        ];
    }

    foreach ($neutralKeywords as $keyword) {
        if ($lower === $keyword) {
            return [
                'label' => 'Neutral',
                'reason' => 'Short non-actionable feedback without hostile tone.',
                'source' => 'rule',
            ];
        }
    }

    if ($wordCount <= 3) {
        return [
            'label' => 'Neutral',
            'reason' => 'Brief feedback without clear constructive or biased markers.',
            'source' => 'rule',
        ];
    }

    if ($hasTeachingSignal && ($blanketAttackScore > 0 || $accusatoryScore > 0 || $negativeEmotionScore > 0)) {
        return [
            'label' => 'Biased',
            'reason' => 'Teaching-related complaint is phrased as a one-sided accusation rather than a suggestion.',
            'source' => 'rule',
        ];
    }

    return [
        'label' => 'Neutral',
        'reason' => 'No strong hostile markers detected, but feedback remains vague.',
        'source' => 'rule',
    ];
}

