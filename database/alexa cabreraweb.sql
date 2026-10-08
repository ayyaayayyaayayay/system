USE `izgrqywp_naap`;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @alexa_semester_slug := '2nd-semester-2026-2027';
SET @alexa_professor_email := 'professor.003@naap.edu.ph';
SET @alexa_professor_name := 'Alexa I Cabrera';
SET @alexa_minimum_submissions := 80;
SET @alexa_minimum_completion_percent := 80;
SET @alexa_seed_source := 'alexa-web-2nd-2026-2027-sample-v2';

DROP TEMPORARY TABLE IF EXISTS `tmp_alexa_classes`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alexa_candidates`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alexa_candidate_order`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alexa_seed`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alexa_ready`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alexa_comments`;

START TRANSACTION;

CREATE TEMPORARY TABLE `tmp_alexa_comments` (
    `comment_id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    `comment_text` VARCHAR(450) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `tmp_alexa_comments` (`comment_id`, `comment_text`) VALUES
    (1, 'Prof. Alexa explains each topic in a clear sequence that is easy to follow.'),
    (2, 'The examples during discussions connect the lesson to situations in the aviation industry.'),
    (3, 'The professor listens patiently when students ask questions about unfamiliar terms.'),
    (4, 'Instructions for individual activities are clear before we start working.'),
    (5, 'The course materials are organized and match the topics discussed in class.'),
    (6, 'Prof. Alexa maintains a respectful atmosphere during student discussions.'),
    (7, 'The grading rubric makes the expectations for our outputs understandable.'),
    (8, 'The practical exercises give us a chance to apply concepts from the lecture.'),
    (9, 'Feedback on written work identifies the parts that were done well and the parts with errors.'),
    (10, 'The professor arrives prepared with materials for the scheduled lesson.'),
    (11, 'The short summaries at the end of discussions help me remember the main ideas.'),
    (12, 'Group tasks give students time to exchange ideas and compare their understanding.'),
    (13, 'Prof. Alexa uses familiar situations when introducing technical vocabulary.'),
    (14, 'The classroom rules are explained consistently throughout the semester.'),
    (15, 'The professor gives students enough time to read the directions before answering.'),
    (16, 'The slides have headings that make the flow of the discussion easy to track.'),
    (17, 'The examples used in class are relevant to the subject and our program.'),
    (18, 'Prof. Alexa checks our understanding before starting a new topic.'),
    (19, 'The scheduled consultations give students a chance to discuss questions about their work.'),
    (20, 'The professor explains how each requirement contributes to our final grade.'),
    (21, 'The pacing is manageable for most lessons, especially when the topic has several steps.'),
    (22, 'The discussion includes questions that encourage us to explain our answers.'),
    (23, 'Prof. Alexa treats questions from different students with the same level of attention.'),
    (24, 'The learning activities are connected to the objectives presented at the start of class.'),
    (25, 'The professor explains the reasons behind the procedures instead of listing the steps alone.'),
    (26, 'The sample outputs show what a complete and well-organized submission looks like.'),
    (27, 'Prof. Alexa gives clear reminders about submission dates and assessment schedules.'),
    (28, 'The explanations during review sessions help me recognize mistakes in my earlier answers.'),
    (29, 'The classroom discussions give students opportunities to share relevant experiences.'),
    (30, 'The professor connects the current lesson to topics we studied earlier in the course.'),
    (31, 'I can follow the demonstrations because the professor explains each stage as it happens.'),
    (32, 'The course requirements are introduced early enough for us to plan our workload.'),
    (33, 'Prof. Alexa uses clear language when discussing difficult concepts.'),
    (34, 'The questions in our assessments are related to the lessons and activities covered in class.'),
    (35, 'The professor acknowledges student contributions during discussions.'),
    (36, 'The reference materials give context for the examples discussed during lectures.'),
    (37, 'Prof. Alexa gives balanced attention to both the concepts and their practical applications.'),
    (38, 'The activity instructions explain the required format and the expected length of our work.'),
    (39, 'The professor handles differences in student opinions calmly and respectfully.'),
    (40, 'The worked examples make the process easier to understand before we attempt the exercises.'),
    (41, 'Some lengthy discussions move quickly near the end of the session.'),
    (42, 'A few slides contain several ideas, and I spend extra time reviewing those pages after class.'),
    (43, 'The time available for questions varies depending on the length of the lesson.'),
    (44, 'Some technical topics take me longer to understand than the introductory lessons.'),
    (45, 'The group activities are useful, although coordinating the tasks sometimes takes extra time.'),
    (46, 'There are weeks when several course requirements fall close together.'),
    (47, 'The examples from actual workplace situations are the parts of the lesson I remember best.'),
    (48, 'I find the written instructions helpful when reviewing an activity outside class.'),
    (49, 'The professor explains corrections in a way that lets students see where their reasoning changed.'),
    (50, 'Overall, Prof. Alexa presents the subject clearly and keeps the class focused on the lesson.');

SET @alexa_semester_id := (
    SELECT `id` FROM `semesters` WHERE `slug` = @alexa_semester_slug LIMIT 1
);
SET @alexa_type_id := (
    SELECT `id` FROM `evaluation_types` WHERE `code` = 'student-professor' LIMIT 1
);
SET @alexa_professor_id := (
    SELECT u.`id` FROM `users` u JOIN `roles` r ON r.`id` = u.`role_id`
    WHERE (u.`email` = @alexa_professor_email OR u.`name` = @alexa_professor_name)
      AND r.`code` = 'professor' AND u.`status` = 'active' AND u.`deleted_at` IS NULL
    ORDER BY (u.`email` = @alexa_professor_email) DESC, u.`id` LIMIT 1
);
SET @alexa_questionnaire_id := (
    SELECT `id` FROM `questionnaires`
    WHERE `semester_id` = @alexa_semester_id AND `evaluation_type_id` = @alexa_type_id
      AND `status` = 'published' ORDER BY `id` DESC LIMIT 1
);

CREATE TEMPORARY TABLE `tmp_alexa_classes` (
    `course_offering_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    `subject_code` VARCHAR(255) NOT NULL,
    `section_name` VARCHAR(255) NOT NULL,
    `registered` BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `tmp_alexa_classes` (`course_offering_id`, `subject_code`, `section_name`, `registered`)
SELECT co.`id` AS `course_offering_id`, s.`subject_code`, co.`section_name`,
       COUNT(DISTINCT sce.`student_id`) AS `registered`
FROM `course_offerings` co
JOIN `subjects` s ON s.`id` = co.`subject_id`
JOIN `student_course_enrollments` sce ON sce.`course_offering_id` = co.`id`
    AND sce.`status` IN ('enrolled', 'completed')
WHERE co.`professor_id` = @alexa_professor_id AND co.`semester_id` = @alexa_semester_id
  AND co.`deleted_at` IS NULL
  AND (co.`is_active` = 1 OR EXISTS (
      SELECT 1 FROM `student_course_enrollments` historical
      WHERE historical.`course_offering_id` = co.`id` AND historical.`status` = 'completed'
  ))
GROUP BY co.`id`, s.`subject_code`, co.`section_name`;

CREATE TEMPORARY TABLE `tmp_alexa_candidates` (
    `course_offering_id` BIGINT UNSIGNED NOT NULL,
    `evaluator_user_id` BIGINT UNSIGNED NOT NULL,
    `student_rank` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`course_offering_id`, `evaluator_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `tmp_alexa_candidates` (`course_offering_id`, `evaluator_user_id`, `student_rank`)
SELECT DISTINCT c.`course_offering_id`, sce.`student_id` AS `evaluator_user_id`,
       CAST(0 AS UNSIGNED) AS `student_rank`
FROM `tmp_alexa_classes` c
JOIN `student_course_enrollments` sce ON sce.`course_offering_id` = c.`course_offering_id`
    AND sce.`status` IN ('enrolled', 'completed')
JOIN `users` u ON u.`id` = sce.`student_id` AND u.`status` = 'active'
    AND u.`deleted_at` IS NULL
JOIN `roles` r ON r.`id` = u.`role_id` AND r.`code` = 'student'
JOIN `users` professor ON professor.`id` = @alexa_professor_id
    AND professor.`campus_id` = u.`campus_id`
WHERE NOT EXISTS (
    SELECT 1 FROM `evaluations` existing
    WHERE existing.`semester_id` = @alexa_semester_id
      AND existing.`evaluation_type_id` = @alexa_type_id
      AND existing.`course_offering_id` = c.`course_offering_id`
      AND existing.`evaluator_user_id` = sce.`student_id`
      AND (
          COALESCE(JSON_UNQUOTE(JSON_EXTRACT(existing.`credibility_components`, '$.seedSource')), '')
              <> @alexa_seed_source
          OR existing.`status` <> 'submitted'
          OR COALESCE(existing.`credibility_status`, '') NOT IN ('AUTO_ACCEPTED', 'ACCEPTED_BY_HR')
          OR existing.`evaluatee_user_id` <> @alexa_professor_id
          OR NOT (existing.`questionnaire_id` <=> @alexa_questionnaire_id)
      )
);

CREATE TEMPORARY TABLE `tmp_alexa_candidate_order` (
    `course_offering_id` BIGINT UNSIGNED NOT NULL,
    `evaluator_user_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`course_offering_id`, `evaluator_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `tmp_alexa_candidate_order` (`course_offering_id`, `evaluator_user_id`)
SELECT `course_offering_id`, `evaluator_user_id` FROM `tmp_alexa_candidates`;
UPDATE `tmp_alexa_candidates` c SET c.`student_rank` = (
    SELECT COUNT(*) FROM `tmp_alexa_candidate_order` ordered
    WHERE ordered.`course_offering_id` = c.`course_offering_id`
      AND ordered.`evaluator_user_id` <= c.`evaluator_user_id`
);

CREATE TEMPORARY TABLE `tmp_alexa_seed` (
    `seed_order` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `course_offering_id` BIGINT UNSIGNED NOT NULL,
    `evaluator_user_id` BIGINT UNSIGNED NOT NULL,
    `comment_text` VARCHAR(500) NOT NULL DEFAULT '',
    UNIQUE KEY `uq_alexa_sample_pair` (`course_offering_id`, `evaluator_user_id`)
) ENGINE=InnoDB;
INSERT INTO `tmp_alexa_seed` (`course_offering_id`, `evaluator_user_id`)
SELECT `course_offering_id`, `evaluator_user_id` FROM `tmp_alexa_candidates`
ORDER BY `student_rank`, `course_offering_id`, `evaluator_user_id`;
UPDATE `tmp_alexa_seed` seed
JOIN `tmp_alexa_comments` feedback ON feedback.`comment_id` = 1 + MOD(seed.`seed_order` - 1, 50)
SET seed.`comment_text` = feedback.`comment_text`;

SET @alexa_class_count := (SELECT COUNT(*) FROM `tmp_alexa_classes`);
SET @alexa_registered_total := (SELECT COALESCE(SUM(`registered`), 0) FROM `tmp_alexa_classes`);
SET @alexa_target := GREATEST(
    @alexa_minimum_submissions,
    @alexa_class_count,
    CEIL(@alexa_registered_total * @alexa_minimum_completion_percent / 100)
);
SET @alexa_candidate_count := (SELECT COUNT(*) FROM `tmp_alexa_seed`);
SET @alexa_unseedable_classes := (
    SELECT COUNT(*) FROM `tmp_alexa_classes` c
    WHERE NOT EXISTS (
        SELECT 1 FROM `tmp_alexa_candidates` eligible
        WHERE eligible.`course_offering_id` = c.`course_offering_id`
    )
);
SET @alexa_rating_question_count := (
    SELECT COUNT(*) FROM `questions` q JOIN `question_types` qt ON qt.`id` = q.`question_type_id`
    WHERE q.`questionnaire_id` = @alexa_questionnaire_id AND qt.`code` = 'rating'
      AND q.`is_active` = 1 AND q.`deleted_at` IS NULL AND q.`rating_max` = 5
);
SET @alexa_unsupported_questions := (
    SELECT COUNT(*) FROM `questions` q JOIN `question_types` qt ON qt.`id` = q.`question_type_id`
    WHERE q.`questionnaire_id` = @alexa_questionnaire_id AND q.`is_active` = 1
      AND q.`deleted_at` IS NULL
      AND (qt.`code` NOT IN ('rating', 'qualitative') OR (qt.`code` = 'rating' AND q.`rating_max` <> 5))
);
SET @alexa_is_current := COALESCE((
    SELECT `is_current` FROM `semesters` WHERE `id` = @alexa_semester_id
), 0);

SELECT @alexa_semester_slug AS `requested_semester`, @alexa_semester_id AS `semester_id`,
       @alexa_is_current AS `semester_is_current`, @alexa_professor_id AS `professor_id`,
       @alexa_questionnaire_id AS `published_questionnaire_id`,
       @alexa_rating_question_count AS `rating_questions`,
       @alexa_unsupported_questions AS `unsupported_questions`,
       @alexa_class_count AS `enrolled_classes`, @alexa_unseedable_classes AS `classes_without_seed_candidates`,
       @alexa_registered_total AS `total_required_evaluations`,
       @alexa_minimum_completion_percent AS `target_completion_percent`,
       @alexa_candidate_count AS `available_sample_pairs`, @alexa_target AS `required_sample_pairs`;

SET @alexa_preflight_ok := (
    @alexa_semester_id IS NOT NULL AND @alexa_type_id IS NOT NULL
    AND @alexa_professor_id IS NOT NULL AND @alexa_questionnaire_id IS NOT NULL
    AND @alexa_is_current = 1 AND @alexa_rating_question_count > 0
    AND @alexa_unsupported_questions = 0 AND @alexa_class_count > 0
    AND @alexa_unseedable_classes = 0 AND @alexa_candidate_count >= @alexa_target
);
CREATE TEMPORARY TABLE `tmp_alexa_ready` (`id` TINYINT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO `tmp_alexa_ready` VALUES (1);
INSERT INTO `tmp_alexa_ready` SELECT IF(@alexa_preflight_ok, 2, 1);

INSERT INTO `evaluations` (
    `semester_id`, `questionnaire_id`, `evaluation_type_id`, `evaluator_user_id`,
    `evaluatee_user_id`, `course_offering_id`, `general_comments`, `submitted_at`,
    `status`, `submission_duplicate_key`, `behavior_meta`, `behavior_score`,
    `credibility_score`, `credibility_status`, `credibility_components`,
    `credibility_flags`, `credibility_calculated_at`
)
SELECT @alexa_semester_id, @alexa_questionnaire_id, @alexa_type_id, seed.`evaluator_user_id`,
       @alexa_professor_id, seed.`course_offering_id`,
       seed.`comment_text`,
       CURRENT_TIMESTAMP, 'submitted',
       SHA2(CONCAT('evaluation-submission-v1|student-professor|', @alexa_semester_id,
           '|', @alexa_type_id, '|', seed.`evaluator_user_id`, '|course|', seed.`course_offering_id`), 256),
       NULL, NULL, 80, 'AUTO_ACCEPTED',
       JSON_OBJECT('behavior', NULL, 'bias', 80, 'cross', NULL, 'biasSource', 'rule',
           'crossStatus', 'Comparator unavailable', 'formulaVersion', 2,
           'seedSource', @alexa_seed_source, 'synthetic', TRUE,
           'importNote', 'Synthetic sample. No browser timing or supervisor evidence supplied.'),
       JSON_ARRAY(), CURRENT_TIMESTAMP
FROM `tmp_alexa_seed` seed JOIN `tmp_alexa_ready` ready ON ready.`id` = 2
WHERE seed.`seed_order` <= @alexa_target
  AND NOT EXISTS (
      SELECT 1 FROM `evaluations` existing
      WHERE existing.`semester_id` = @alexa_semester_id AND existing.`evaluation_type_id` = @alexa_type_id
        AND existing.`evaluator_user_id` = seed.`evaluator_user_id`
        AND existing.`course_offering_id` = seed.`course_offering_id`
  )
ORDER BY seed.`seed_order`;
SET @alexa_inserted_evaluations := ROW_COUNT();

INSERT INTO `evaluation_responses` (`evaluation_id`, `question_id`, `rating_value`, `text_value`, `display_order`)
SELECT e.`id`, q.`id`,
       CASE WHEN qt.`code` = 'rating'
            THEN 4 + IF(MOD(seed.`evaluator_user_id` + seed.`course_offering_id` + q.`sort_order`, 3) = 0, 1, 0)
            ELSE NULL END,
       CASE WHEN qt.`code` = 'qualitative'
            THEN seed.`comment_text`
            ELSE NULL END,
       q.`sort_order`
FROM `tmp_alexa_seed` seed
JOIN `tmp_alexa_ready` ready ON ready.`id` = 2
JOIN `evaluations` e ON e.`semester_id` = @alexa_semester_id AND e.`evaluation_type_id` = @alexa_type_id
    AND e.`evaluatee_user_id` = @alexa_professor_id AND e.`course_offering_id` = seed.`course_offering_id`
    AND e.`evaluator_user_id` = seed.`evaluator_user_id`
    AND e.`questionnaire_id` = @alexa_questionnaire_id
    AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`, '$.seedSource')) = @alexa_seed_source
JOIN `questions` q ON q.`questionnaire_id` = @alexa_questionnaire_id
    AND q.`is_active` = 1 AND q.`deleted_at` IS NULL
JOIN `question_types` qt ON qt.`id` = q.`question_type_id` AND qt.`code` IN ('rating', 'qualitative')
WHERE seed.`seed_order` <= @alexa_target
  AND NOT EXISTS (
      SELECT 1 FROM `evaluation_responses` existing
      WHERE existing.`evaluation_id` = e.`id` AND existing.`question_id` = q.`id`
  )
ORDER BY e.`id`, q.`sort_order`, q.`id`;
SET @alexa_inserted_responses := ROW_COUNT();

UPDATE `evaluations` e
JOIN `tmp_alexa_seed` seed ON seed.`course_offering_id` = e.`course_offering_id`
    AND seed.`evaluator_user_id` = e.`evaluator_user_id`
JOIN `tmp_alexa_ready` ready ON ready.`id` = 2
SET e.`general_comments` = seed.`comment_text`
WHERE seed.`seed_order` <= @alexa_target AND e.`semester_id` = @alexa_semester_id
  AND e.`evaluation_type_id` = @alexa_type_id AND e.`evaluatee_user_id` = @alexa_professor_id
  AND e.`questionnaire_id` = @alexa_questionnaire_id AND e.`status` = 'submitted'
  AND e.`credibility_status` = 'AUTO_ACCEPTED'
  AND e.`credibility_reviewed_by` IS NULL AND e.`credibility_reviewed_at` IS NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`, '$.seedSource')) = @alexa_seed_source;

UPDATE `evaluation_responses` er
JOIN `evaluations` e ON e.`id` = er.`evaluation_id`
JOIN `tmp_alexa_seed` seed ON seed.`course_offering_id` = e.`course_offering_id`
    AND seed.`evaluator_user_id` = e.`evaluator_user_id`
JOIN `tmp_alexa_ready` ready ON ready.`id` = 2
JOIN `questions` q ON q.`id` = er.`question_id` AND q.`questionnaire_id` = @alexa_questionnaire_id
    AND q.`is_active` = 1 AND q.`deleted_at` IS NULL
JOIN `question_types` qt ON qt.`id` = q.`question_type_id` AND qt.`code` = 'qualitative'
SET er.`text_value` = e.`general_comments`
WHERE seed.`seed_order` <= @alexa_target AND e.`semester_id` = @alexa_semester_id
  AND e.`evaluation_type_id` = @alexa_type_id AND e.`evaluatee_user_id` = @alexa_professor_id
  AND e.`questionnaire_id` = @alexa_questionnaire_id AND e.`status` = 'submitted'
  AND e.`credibility_status` = 'AUTO_ACCEPTED'
  AND e.`credibility_reviewed_by` IS NULL AND e.`credibility_reviewed_at` IS NULL
  AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`, '$.seedSource')) = @alexa_seed_source;

SET @alexa_valid_completed := (
    SELECT COUNT(DISTINCT e.`course_offering_id`, e.`evaluator_user_id`)
    FROM `tmp_alexa_classes` c
    JOIN `student_course_enrollments` sce ON sce.`course_offering_id` = c.`course_offering_id`
        AND sce.`status` IN ('enrolled', 'completed')
    JOIN `evaluations` e ON e.`course_offering_id` = c.`course_offering_id`
        AND e.`semester_id` = @alexa_semester_id AND e.`evaluation_type_id` = @alexa_type_id
        AND e.`evaluatee_user_id` = @alexa_professor_id AND e.`evaluator_user_id` = sce.`student_id`
        AND e.`status` = 'submitted' AND e.`credibility_status` IN ('AUTO_ACCEPTED', 'ACCEPTED_BY_HR')
    JOIN `evaluation_responses` er ON er.`evaluation_id` = e.`id` AND er.`rating_value` BETWEEN 1 AND 5
);


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

SELECT @alexa_sample_timings_added AS `sample_timings_added`,
       MIN(`answered_count` * `seconds_per_question`) AS `minimum_simulated_duration_seconds`,
       MAX(`answered_count` * `seconds_per_question`) AS `maximum_simulated_duration_seconds`
FROM `tmp_alexa_timing_records`;
DROP TEMPORARY TABLE `tmp_alexa_timing_comparators`;
DROP TEMPORARY TABLE `tmp_alexa_timing_records`;

COMMIT;

SELECT @alexa_inserted_evaluations AS `new_sample_submissions`,
       @alexa_inserted_responses AS `new_answers`,
       @alexa_registered_total AS `total_required_evaluations`,
       @alexa_valid_completed AS `valid_accepted_completed_evaluations`,
       ROUND(100 * @alexa_valid_completed / NULLIF(@alexa_registered_total, 0), 2) AS `completion_percent`,
       COUNT(*) AS `total_sample_submissions`,
       COUNT(NULLIF(TRIM(e.`general_comments`), '')) AS `samples_with_comments`,
       COUNT(DISTINCT NULLIF(TRIM(e.`general_comments`), '')) AS `distinct_sample_comments`,
       SUM(e.`status` = 'submitted' AND e.`credibility_status` IN ('AUTO_ACCEPTED', 'ACCEPTED_BY_HR'))
           AS `analytics_eligible_samples`,
       MIN(e.`credibility_score`) AS `minimum_sample_credibility`
FROM `evaluations` e
WHERE e.`semester_id` = @alexa_semester_id AND e.`evaluatee_user_id` = @alexa_professor_id
  AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`, '$.seedSource')) = @alexa_seed_source;

SELECT c.`course_offering_id`, c.`subject_code`, c.`section_name`, c.`registered`,
       COUNT(DISTINCT CASE WHEN e.`status` = 'submitted'
           AND e.`credibility_status` IN ('AUTO_ACCEPTED', 'ACCEPTED_BY_HR')
           AND er.`rating_value` BETWEEN 1 AND 5 THEN e.`evaluator_user_id` END)
           AS `valid_accepted_submissions`
FROM `tmp_alexa_classes` c
LEFT JOIN `student_course_enrollments` sce ON sce.`course_offering_id` = c.`course_offering_id`
    AND sce.`status` IN ('enrolled', 'completed')
LEFT JOIN `evaluations` e ON e.`course_offering_id` = c.`course_offering_id`
    AND e.`semester_id` = @alexa_semester_id AND e.`evaluation_type_id` = @alexa_type_id
    AND e.`evaluatee_user_id` = @alexa_professor_id AND e.`evaluator_user_id` = sce.`student_id`
LEFT JOIN `evaluation_responses` er ON er.`evaluation_id` = e.`id`
GROUP BY c.`course_offering_id`, c.`subject_code`, c.`section_name`, c.`registered`
ORDER BY c.`course_offering_id`;

DROP TEMPORARY TABLE `tmp_alexa_ready`;
DROP TEMPORARY TABLE `tmp_alexa_seed`;
DROP TEMPORARY TABLE `tmp_alexa_candidate_order`;
DROP TEMPORARY TABLE `tmp_alexa_candidates`;
DROP TEMPORARY TABLE `tmp_alexa_classes`;
DROP TEMPORARY TABLE `tmp_alexa_comments`;
