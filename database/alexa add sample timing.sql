USE `izgrqywp_naap`;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @alexa_timing_semester := '2nd-semester-2026-2027';
SET @alexa_timing_source := 'alexa-web-2nd-2026-2027-sample-v2';
SET @alexa_timing_professor := (
    SELECT u.`id` FROM `users` u JOIN `roles` r ON r.`id` = u.`role_id`
    WHERE r.`code` = 'professor'
      AND (u.`email` = 'professor.003@naap.edu.ph' OR u.`name` = 'Alexa I Cabrera')
    ORDER BY (u.`email` = 'professor.003@naap.edu.ph') DESC, u.`id` LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_alexa_timing_records`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alexa_timing_comparators`;
START TRANSACTION;

CREATE TEMPORARY TABLE `tmp_alexa_timing_records` (
    `evaluation_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    `evaluator_user_id` BIGINT UNSIGNED NOT NULL,
    `general_comments` LONGTEXT NULL,
    `submitted_at` DATETIME NOT NULL,
    `seconds_per_question` SMALLINT UNSIGNED NOT NULL,
    `question_count` INT UNSIGNED NOT NULL,
    `answered_count` INT UNSIGNED NOT NULL,
    `rating_count` INT UNSIGNED NOT NULL,
    `fingerprint` LONGTEXT NULL,
    `uniform_share` DECIMAL(10,8) NOT NULL,
    `comment_uniform` TINYINT UNSIGNED NOT NULL,
    `uniform_risk` DECIMAL(8,6) NOT NULL,
    `repetition_risk` DECIMAL(8,6) NOT NULL,
    `rating_repetitive` TINYINT UNSIGNED NOT NULL,
    `comment_repetitive` TINYINT UNSIGNED NOT NULL,
    `behavior_score` TINYINT UNSIGNED NOT NULL,
    `behavior_flags` LONGTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `tmp_alexa_timing_records` (
    `evaluation_id`, `evaluator_user_id`, `general_comments`, `submitted_at`,
    `seconds_per_question`, `question_count`, `answered_count`, `rating_count`,
    `fingerprint`, `uniform_share`, `comment_uniform`, `uniform_risk`,
    `repetition_risk`, `rating_repetitive`, `comment_repetitive`, `behavior_score`, `behavior_flags`
)
SELECT e.`id` AS `evaluation_id`, e.`evaluator_user_id`, e.`general_comments`, e.`submitted_at`,
       12 + MOD(e.`evaluator_user_id` + e.`course_offering_id`, 9) AS `seconds_per_question`,
       (SELECT COUNT(*) FROM `questions` q WHERE q.`questionnaire_id` = e.`questionnaire_id`
           AND q.`is_active` = 1 AND q.`deleted_at` IS NULL) AS `question_count`,
       answers.`answered_count`, answers.`rating_count`, answers.`fingerprint`,
       CASE WHEN answers.`matching_comments` > 0 AND TRIM(COALESCE(e.`general_comments`, '')) <> ''
            THEN 1.0
            WHEN answers.`rating_count` > 0 THEN
                GREATEST(answers.`ones`, answers.`twos`, answers.`threes`, answers.`fours`, answers.`fives`)
                / answers.`rating_count`
            ELSE 0.0 END AS `uniform_share`,
       CASE WHEN answers.`matching_comments` > 0 AND TRIM(COALESCE(e.`general_comments`, '')) <> ''
            THEN 1 ELSE 0 END AS `comment_uniform`,
       CAST(0 AS DECIMAL(8,6)) AS `uniform_risk`,
       CAST(0 AS DECIMAL(8,6)) AS `repetition_risk`,
       0 AS `rating_repetitive`, 0 AS `comment_repetitive`, 0 AS `behavior_score`,
       JSON_ARRAY() AS `behavior_flags`
FROM `evaluations` e
JOIN `semesters` semester ON semester.`id` = e.`semester_id`
JOIN `evaluation_types` et ON et.`id` = e.`evaluation_type_id`
JOIN (
    SELECT er.`evaluation_id`,
           COUNT(DISTINCT CASE WHEN er.`rating_value` BETWEEN 1 AND 5
               OR TRIM(COALESCE(er.`text_value`, '')) <> '' THEN er.`question_id` END) AS `answered_count`,
           COUNT(DISTINCT CASE WHEN er.`rating_value` BETWEEN 1 AND 5 THEN er.`question_id` END) AS `rating_count`,
           SUM(er.`rating_value` = 1) AS `ones`, SUM(er.`rating_value` = 2) AS `twos`,
           SUM(er.`rating_value` = 3) AS `threes`, SUM(er.`rating_value` = 4) AS `fours`,
           SUM(er.`rating_value` = 5) AS `fives`,
           SUM(TRIM(COALESCE(er.`text_value`, '')) <> ''
               AND TRIM(er.`text_value`) = TRIM(target.`general_comments`)) AS `matching_comments`,
           GROUP_CONCAT(CASE WHEN er.`rating_value` BETWEEN 1 AND 5
               THEN CONCAT(er.`question_id`, ':', er.`rating_value`) END
               ORDER BY er.`question_id` SEPARATOR '|') AS `fingerprint`
    FROM `evaluation_responses` er
    JOIN `evaluations` target ON target.`id` = er.`evaluation_id`
    JOIN `questions` q ON q.`id` = er.`question_id`
        AND q.`questionnaire_id` = target.`questionnaire_id` AND q.`is_active` = 1 AND q.`deleted_at` IS NULL
    GROUP BY er.`evaluation_id`
) answers ON answers.`evaluation_id` = e.`id`
WHERE semester.`slug` = @alexa_timing_semester AND et.`code` = 'student-professor'
  AND e.`evaluatee_user_id` = @alexa_timing_professor AND e.`status` = 'submitted'
  AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`, '$.seedSource')) = @alexa_timing_source
  AND answers.`answered_count` > 0;

CREATE TEMPORARY TABLE `tmp_alexa_timing_comparators` (
    `evaluation_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    `evaluator_user_id` BIGINT UNSIGNED NOT NULL,
    `general_comments` LONGTEXT NULL,
    `fingerprint` LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `tmp_alexa_timing_comparators` (`evaluation_id`, `evaluator_user_id`, `general_comments`, `fingerprint`)
SELECT `evaluation_id`, `evaluator_user_id`, `general_comments`, `fingerprint`
FROM `tmp_alexa_timing_records`;

UPDATE `tmp_alexa_timing_records` timing
SET timing.`uniform_risk` = CASE WHEN timing.`uniform_share` >= 0.9
        THEN timing.`uniform_share` ELSE timing.`uniform_share` * 0.35 END,
    timing.`comment_repetitive` = EXISTS (
        SELECT 1 FROM `tmp_alexa_timing_comparators` other
        WHERE other.`evaluation_id` <> timing.`evaluation_id`
          AND TRIM(COALESCE(timing.`general_comments`, '')) <> ''
          AND TRIM(other.`general_comments`) = TRIM(timing.`general_comments`)
    ),
    timing.`rating_repetitive` = EXISTS (
        SELECT 1 FROM `tmp_alexa_timing_comparators` other
        WHERE other.`evaluation_id` <> timing.`evaluation_id`
          AND other.`evaluator_user_id` = timing.`evaluator_user_id`
          AND timing.`fingerprint` IS NOT NULL AND other.`fingerprint` = timing.`fingerprint`
    );
UPDATE `tmp_alexa_timing_records` timing
SET timing.`repetition_risk` = IF(timing.`comment_repetitive` OR timing.`rating_repetitive`, 1, 0);
UPDATE `tmp_alexa_timing_records` timing
SET timing.`behavior_score` = ROUND(100 * (1 - 0.35 * timing.`uniform_risk` - 0.25 * timing.`repetition_risk`)),
    timing.`behavior_flags` = JSON_MERGE_PRESERVE(
        IF(timing.`comment_uniform` = 1 OR timing.`uniform_share` >= 0.9,
            JSON_ARRAY('Uniform response pattern'), JSON_ARRAY()),
        IF(timing.`repetition_risk` > 0, JSON_ARRAY('Repetitive response pattern'), JSON_ARRAY())
    );

UPDATE `evaluations` e
JOIN `tmp_alexa_timing_records` timing ON timing.`evaluation_id` = e.`id`
SET e.`behavior_meta` = JSON_OBJECT(
        'captureVersion', 1,
        'startedAt', CONCAT(DATE_FORMAT(DATE_SUB(e.`submitted_at`,
            INTERVAL (timing.`answered_count` * timing.`seconds_per_question`) SECOND), '%Y-%m-%dT%H:%i:%s'), '+08:00'),
        'submittedAt', CONCAT(DATE_FORMAT(e.`submitted_at`, '%Y-%m-%dT%H:%i:%s'), '+08:00'),
        'durationSeconds', timing.`answered_count` * timing.`seconds_per_question`,
        'questionCount', timing.`question_count`, 'answeredCount', timing.`answered_count`,
        'secondsPerQuestion', timing.`seconds_per_question`,
        'synthetic', TRUE, 'timingSource', 'simulated-sample-v1'
    ),
    e.`behavior_score` = timing.`behavior_score`,
    e.`credibility_components` = JSON_SET(e.`credibility_components`,
        '$.timingSource', 'simulated-sample-v1',
        '$.timingNote', 'Simulated sample timing, not observed browser activity. Original import credibility decision retained.',
        '$.frozenCredibilityPreserved', TRUE,
        '$.behaviorDetails', JSON_OBJECT(
            'score', timing.`behavior_score`, 'flags', JSON_EXTRACT(timing.`behavior_flags`, '$'),
            'ratingRepetitiveFlag', JSON_EXTRACT(IF(timing.`rating_repetitive`, 'true', 'false'), '$'),
            'commentRepetitiveFlag', JSON_EXTRACT(IF(timing.`comment_repetitive`, 'true', 'false'), '$'),
            'repetitiveFlag', JSON_EXTRACT(IF(timing.`repetition_risk` > 0, 'true', 'false'), '$'),
            'requiresSpeedReview', FALSE, 'speedRisk', 0,
            'uniformityRisk', timing.`uniform_risk`, 'repetitionRisk', timing.`repetition_risk`,
            'fastThreshold', NULL, 'source', 'simulated-sample-v1'
        )
    )
WHERE e.`behavior_meta` IS NULL AND e.`behavior_score` IS NULL
  AND timing.`question_count` >= timing.`answered_count`
  AND e.`credibility_reviewed_by` IS NULL AND e.`credibility_reviewed_at` IS NULL
  AND e.`credibility_status` = 'AUTO_ACCEPTED';
SET @alexa_sample_timings_added := ROW_COUNT();

COMMIT;
SELECT @alexa_sample_timings_added AS `sample_timings_added`,
       MIN(`answered_count` * `seconds_per_question`) AS `minimum_simulated_duration_seconds`,
       MAX(`answered_count` * `seconds_per_question`) AS `maximum_simulated_duration_seconds`
FROM `tmp_alexa_timing_records`;
DROP TEMPORARY TABLE `tmp_alexa_timing_comparators`;
DROP TEMPORARY TABLE `tmp_alexa_timing_records`;
