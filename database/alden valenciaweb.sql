USE `izgrqywp_naap`;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @alden_semester_slug := '2nd-semester-2026-2027';
SET @alden_employee_id := 'PRF-2026-002';
SET @alden_random_seed := 'PRF-2026-002-samples-v1';
SET @alden_import_time := DATE_ADD(UTC_TIMESTAMP(), INTERVAL 8 HOUR);
SET @alden_professor_name := 'Alden B Valencia';
SET @alden_minimum_submissions := 80;
SET @alden_minimum_completion_percent := 80;
SET @alden_seed_source := 'alden-web-2nd-2026-2027-sample-v1';

DROP TEMPORARY TABLE IF EXISTS `tmp_alden_sorted_times`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_behavior`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_comment_similarity`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_comment_compare`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_token_compare`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_comment_tokens`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_word_positions`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_rating_similarity`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_rating_compare`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_seed_compare`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_ratings`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_ready`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_available_comments`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_new_pairs`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_seed`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_candidate_order`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_candidates`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_classes`;
DROP TEMPORARY TABLE IF EXISTS `tmp_alden_comments`;

START TRANSACTION;

CREATE TEMPORARY TABLE `tmp_alden_comments` (
    `comment_id` TINYINT UNSIGNED NOT NULL PRIMARY KEY,
    `tone` VARCHAR(20) NOT NULL,
    `comment_text` VARCHAR(500) NOT NULL,
    `normalized_text` VARCHAR(500) NOT NULL DEFAULT '',
    `token_count` INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `tmp_alden_comments` (`comment_id`, `tone`, `comment_text`) VALUES
    (1, 'positive', 'Alden uses short checkpoints during demonstrations, which help me notice errors before finishing an exercise.'),
    (2, 'positive', 'I understand the purpose of each procedure because the explanation starts with the problem it solves.'),
    (3, 'positive', 'Questions from quieter classmates receive thoughtful answers rather than being passed over.'),
    (4, 'positive', 'The opening recap makes it easier to reconnect with the topic after a week between meetings.'),
    (5, 'positive', 'Our project discussions connect classroom concepts with the decisions that employees make at work.'),
    (6, 'positive', 'When an answer is incorrect, Alden discusses the reasoning without embarrassing the student who gave it.'),
    (7, 'positive', 'The visual demonstrations are especially useful when a process is difficult to picture from the textbook.'),
    (8, 'positive', 'I can prepare for assessments because the learning targets are explained before the practice activities.'),
    (9, 'positive', 'Examples from recent industry situations make the course feel relevant to the career I am considering.'),
    (10, 'positive', 'The professor notices confusion in the room and pauses to explain an unfamiliar term in simpler language.'),
    (11, 'positive', 'Small practice tasks give me a useful way to check my understanding before a graded submission.'),
    (12, 'positive', 'Alden distinguishes essential steps from optional details, so the main procedure remains easy to remember.'),
    (13, 'positive', 'The consultation discussion helped me identify a specific weakness in my project rather than guessing what went wrong.'),
    (14, 'positive', 'Different approaches to solving an exercise are welcomed when students can explain why their method works.'),
    (15, 'positive', 'The connection between lesson objectives and the final output is clear throughout the activity.'),
    (16, 'positive', 'I appreciate the calm response to unexpected questions, especially when the topic is outside the prepared example.'),
    (17, 'positive', 'The comparison of two sample solutions shows how a small decision can change the final result.'),
    (18, 'positive', 'The class has helped me speak more confidently about technical ideas during team discussions.'),
    (19, 'negative', 'Several discussions end before the most complicated example has been completed, leaving the last steps uncertain.'),
    (20, 'negative', 'Some instructions use terms that have not yet been introduced, so I spend time guessing their meaning.'),
    (21, 'negative', 'Written feedback sometimes arrives after the next task is due, which limits its usefulness for that submission.'),
    (22, 'negative', 'The workload becomes difficult to manage when multiple major requirements are scheduled in the same week.'),
    (23, 'negative', 'A few assessment items cover details that received little attention during the practice exercises.'),
    (24, 'negative', 'The transitions between topics can feel abrupt, and I lose track of how the ideas are connected.'),
    (25, 'negative', 'Some presentation pages are crowded enough that reading them competes with listening to the explanation.'),
    (26, 'negative', 'During longer activities, the criteria for a successful output are not always clear to our group.'),
    (27, 'negative', 'Updates to requirements are occasionally announced late, leaving less time to adjust work that has already started.'),
    (28, 'negative', 'The available consultation period is sometimes too short for students with detailed questions about their projects.'),
    (29, 'negative', 'Certain exercises require resources that are difficult for some students to access outside the classroom.'),
    (30, 'negative', 'I find it difficult to judge the quality of my work when the returned score has no explanation attached.'),
    (31, 'constructive', 'Please post a brief worked solution after each practice exercise so students can compare their reasoning independently.'),
    (32, 'constructive', 'A short glossary could introduce unfamiliar technical terms before they appear in activity instructions.'),
    (33, 'constructive', 'Please reserve the final five minutes for questions about the most demanding part of the lesson.'),
    (34, 'constructive', 'A shared calendar of major deadlines would help us plan around overlapping course requirements.'),
    (35, 'constructive', 'Consider showing an annotated sample project that explains how each criterion contributes to the mark.'),
    (36, 'constructive', 'Please divide lengthy demonstrations into smaller sections with a practice question between each stage.'),
    (37, 'constructive', 'Offering one low-resource alternative for take-home exercises could make participation more accessible.'),
    (38, 'constructive', 'A brief explanation beside each deducted mark would help students understand the next step in their revision.'),
    (39, 'constructive', 'Please announce requirement changes in one written location so the entire class can check the same instructions.'),
    (40, 'constructive', 'An optional review checklist could highlight the specific skills that students are expected to demonstrate in assessments.');

SET @alden_semester_id := (
    SELECT `id` FROM `semesters` WHERE `slug` = @alden_semester_slug LIMIT 1
);
SET @alden_type_id := (
    SELECT `id` FROM `evaluation_types` WHERE `code` = 'student-professor' LIMIT 1
);
SET @alden_professor_id := (
    SELECT u.`id` FROM `users` u
    JOIN `roles` r ON r.`id` = u.`role_id` AND r.`code` = 'professor'
    JOIN `staff_profiles` sp ON sp.`user_id` = u.`id`
    WHERE sp.`employee_id` = @alden_employee_id
      AND LOWER(REPLACE(REPLACE(TRIM(u.`name`), '.', ''), ' ', ''))
          = LOWER(REPLACE(REPLACE(TRIM(@alden_professor_name), '.', ''), ' ', ''))
      AND u.`status` = 'active' AND u.`deleted_at` IS NULL
      AND sp.`is_active` = 1 AND sp.`deleted_at` IS NULL
    LIMIT 1
);
SET @alden_questionnaire_id := (
    SELECT `id` FROM `questionnaires`
    WHERE `semester_id` = @alden_semester_id AND `evaluation_type_id` = @alden_type_id
      AND `status` = 'published' ORDER BY `id` DESC LIMIT 1
);

CREATE TEMPORARY TABLE `tmp_alden_classes` (
    `course_offering_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    `subject_code` VARCHAR(255) NOT NULL,
    `section_name` VARCHAR(255) NOT NULL,
    `registered` BIGINT UNSIGNED NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `tmp_alden_classes` (`course_offering_id`, `subject_code`, `section_name`, `registered`)
SELECT co.`id` AS `course_offering_id`, s.`subject_code`, co.`section_name`,
       COUNT(DISTINCT sce.`student_id`) AS `registered`
FROM `course_offerings` co
JOIN `subjects` s ON s.`id` = co.`subject_id`
JOIN `student_course_enrollments` sce ON sce.`course_offering_id` = co.`id`
    AND sce.`status` IN ('enrolled', 'completed')
WHERE co.`professor_id` = @alden_professor_id AND co.`semester_id` = @alden_semester_id
  AND co.`deleted_at` IS NULL
  AND (co.`is_active` = 1 OR EXISTS (
      SELECT 1 FROM `student_course_enrollments` historical
      WHERE historical.`course_offering_id` = co.`id` AND historical.`status` = 'completed'
  ))
GROUP BY co.`id`, s.`subject_code`, co.`section_name`;

CREATE TEMPORARY TABLE `tmp_alden_candidates` (
    `course_offering_id` BIGINT UNSIGNED NOT NULL,
    `evaluator_user_id` BIGINT UNSIGNED NOT NULL,
    `student_rank` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`course_offering_id`, `evaluator_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `tmp_alden_candidates` (`course_offering_id`, `evaluator_user_id`, `student_rank`)
SELECT DISTINCT c.`course_offering_id`, sce.`student_id` AS `evaluator_user_id`,
       CAST(0 AS UNSIGNED) AS `student_rank`
FROM `tmp_alden_classes` c
JOIN `student_course_enrollments` sce ON sce.`course_offering_id` = c.`course_offering_id`
    AND sce.`status` IN ('enrolled', 'completed')
JOIN `users` u ON u.`id` = sce.`student_id` AND u.`status` = 'active'
    AND u.`deleted_at` IS NULL
JOIN `roles` r ON r.`id` = u.`role_id` AND r.`code` = 'student'
JOIN `users` professor ON professor.`id` = @alden_professor_id
    AND professor.`campus_id` = u.`campus_id`
WHERE NOT EXISTS (
    SELECT 1 FROM `evaluations` existing
    WHERE existing.`semester_id` = @alden_semester_id
      AND existing.`evaluation_type_id` = @alden_type_id
      AND existing.`course_offering_id` = c.`course_offering_id`
      AND existing.`evaluator_user_id` = sce.`student_id`
      AND (
          COALESCE(JSON_UNQUOTE(JSON_EXTRACT(existing.`credibility_components`, '$.seedSource')), '')
              <> @alden_seed_source
          OR existing.`status` <> 'submitted'
          OR COALESCE(existing.`credibility_status`, '') NOT IN ('AUTO_ACCEPTED', 'ACCEPTED_BY_HR')
          OR existing.`evaluatee_user_id` <> @alden_professor_id
          OR NOT (existing.`questionnaire_id` <=> @alden_questionnaire_id)
      )
);

CREATE TEMPORARY TABLE `tmp_alden_candidate_order` (
    `course_offering_id` BIGINT UNSIGNED NOT NULL,
    `evaluator_user_id` BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (`course_offering_id`, `evaluator_user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `tmp_alden_candidate_order` (`course_offering_id`, `evaluator_user_id`)
SELECT `course_offering_id`, `evaluator_user_id` FROM `tmp_alden_candidates`;
UPDATE `tmp_alden_candidates` c SET c.`student_rank` = (
    SELECT COUNT(*) FROM `tmp_alden_candidate_order` ordered
    WHERE ordered.`course_offering_id` = c.`course_offering_id`
      AND ordered.`evaluator_user_id` <= c.`evaluator_user_id`
);

CREATE TEMPORARY TABLE `tmp_alden_seed` (
    `seed_order` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `course_offering_id` BIGINT UNSIGNED NOT NULL,
    `evaluator_user_id` BIGINT UNSIGNED NOT NULL,
    `comment_text` VARCHAR(500) NULL,
    `comment_id` TINYINT UNSIGNED NULL,
    `tone` VARCHAR(20) NOT NULL DEFAULT '',
    `submitted_at` DATETIME NULL,
    `seconds_per_question` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY `uq_alden_sample_pair` (`course_offering_id`, `evaluator_user_id`)
) ENGINE=InnoDB;
INSERT INTO `tmp_alden_seed` (`course_offering_id`, `evaluator_user_id`)
SELECT `course_offering_id`, `evaluator_user_id` FROM `tmp_alden_candidates`
ORDER BY `student_rank`, `course_offering_id`, `evaluator_user_id`;
UPDATE `tmp_alden_seed` seed
SET seed.`tone` = CASE
        WHEN MOD(CRC32(CONCAT(@alden_random_seed, '|tone|', seed.`evaluator_user_id`, '|', seed.`course_offering_id`)), 100) < 45 THEN 'positive'
        WHEN MOD(CRC32(CONCAT(@alden_random_seed, '|tone|', seed.`evaluator_user_id`, '|', seed.`course_offering_id`)), 100) < 75 THEN 'negative'
        ELSE 'constructive' END,
    seed.`submitted_at` = DATE_SUB(@alden_import_time, INTERVAL
        MOD(CRC32(CONCAT(@alden_random_seed, '|date|', seed.`evaluator_user_id`, '|', seed.`course_offering_id`)), 86400) SECOND),
    seed.`seconds_per_question` = 12 + MOD(CRC32(CONCAT(@alden_random_seed, '|time|', seed.`evaluator_user_id`, '|', seed.`course_offering_id`)), 9);

UPDATE `tmp_alden_seed` seed
JOIN `evaluations` e ON e.`semester_id` = @alden_semester_id AND e.`evaluation_type_id` = @alden_type_id
    AND e.`evaluatee_user_id` = @alden_professor_id AND e.`course_offering_id` = seed.`course_offering_id`
    AND e.`evaluator_user_id` = seed.`evaluator_user_id`
    AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`, '$.seedSource')) = @alden_seed_source
SET seed.`tone` = JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`, '$.ratingTone')),
    seed.`submitted_at` = e.`submitted_at`,
    seed.`seconds_per_question` = JSON_EXTRACT(e.`behavior_meta`, '$.secondsPerQuestion'),
    seed.`comment_text` = e.`general_comments`,
    seed.`comment_id` = NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`, '$.commentId')), 'null');

SET @alden_class_count := (SELECT COUNT(*) FROM `tmp_alden_classes`);
SET @alden_registered_total := (SELECT COALESCE(SUM(`registered`), 0) FROM `tmp_alden_classes`);
SET @alden_target := GREATEST(
    @alden_minimum_submissions,
    @alden_class_count,
    CEIL(@alden_registered_total * @alden_minimum_completion_percent / 100)
);
SET @alden_candidate_count := (SELECT COUNT(*) FROM `tmp_alden_seed`);
SET @alden_unseedable_classes := (
    SELECT COUNT(*) FROM `tmp_alden_classes` c
    WHERE NOT EXISTS (
        SELECT 1 FROM `tmp_alden_candidates` eligible
        WHERE eligible.`course_offering_id` = c.`course_offering_id`
    )
);
SET @alden_rating_question_count := (
    SELECT COUNT(*) FROM `questions` q JOIN `question_types` qt ON qt.`id` = q.`question_type_id`
    WHERE q.`questionnaire_id` = @alden_questionnaire_id AND qt.`code` = 'rating'
      AND q.`is_active` = 1 AND q.`deleted_at` IS NULL AND q.`rating_max` = 5
);
SET @alden_unsupported_questions := (
    SELECT COUNT(*) FROM `questions` q JOIN `question_types` qt ON qt.`id` = q.`question_type_id`
    WHERE q.`questionnaire_id` = @alden_questionnaire_id AND q.`is_active` = 1
      AND q.`deleted_at` IS NULL
      AND (qt.`code` NOT IN ('rating', 'qualitative') OR (qt.`code` = 'rating' AND q.`rating_max` <> 5) OR (qt.`code` = 'qualitative' AND q.`is_required` = 1))
);
SET @alden_is_current := COALESCE((
    SELECT `is_current` FROM `semesters` WHERE `id` = @alden_semester_id
), 0);

SELECT @alden_semester_slug AS `requested_semester`, @alden_semester_id AS `semester_id`,
       @alden_is_current AS `semester_is_current`, @alden_professor_id AS `professor_id`,
       @alden_questionnaire_id AS `published_questionnaire_id`,
       @alden_rating_question_count AS `rating_questions`,
       @alden_unsupported_questions AS `unsupported_questions`,
       @alden_class_count AS `enrolled_classes`, @alden_unseedable_classes AS `classes_without_seed_candidates`,
       @alden_registered_total AS `total_required_evaluations`,
       @alden_minimum_completion_percent AS `target_completion_percent`,
       @alden_candidate_count AS `available_sample_pairs`, @alden_target AS `required_sample_pairs`;

CREATE TEMPORARY TABLE `tmp_alden_new_pairs` (
    `new_order` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `seed_order` INT UNSIGNED NOT NULL UNIQUE
) ENGINE=InnoDB;
INSERT INTO `tmp_alden_new_pairs` (`seed_order`)
SELECT seed.`seed_order` FROM `tmp_alden_seed` seed
WHERE seed.`seed_order` <= @alden_target AND NOT EXISTS (
    SELECT 1 FROM `evaluations` e WHERE e.`semester_id` = @alden_semester_id
      AND e.`evaluation_type_id` = @alden_type_id AND e.`course_offering_id` = seed.`course_offering_id`
      AND e.`evaluator_user_id` = seed.`evaluator_user_id`
)
ORDER BY CRC32(CONCAT(@alden_random_seed, '|comment-placement|', seed.`evaluator_user_id`, '|', seed.`course_offering_id`)), seed.`seed_order`;

CREATE TEMPORARY TABLE `tmp_alden_available_comments` (
    `comment_slot` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `comment_id` TINYINT UNSIGNED NOT NULL UNIQUE
) ENGINE=InnoDB;
INSERT INTO `tmp_alden_available_comments` (`comment_id`)
SELECT c.`comment_id` FROM `tmp_alden_comments` c WHERE NOT EXISTS (
    SELECT 1 FROM `evaluations` e WHERE e.`semester_id` = @alden_semester_id
      AND e.`evaluatee_user_id` = @alden_professor_id AND e.`evaluation_type_id` = @alden_type_id
      AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`, '$.seedSource')) = @alden_seed_source
      AND CAST(NULLIF(JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`, '$.commentId')), 'null') AS UNSIGNED) = c.`comment_id`
)
ORDER BY CRC32(CONCAT(@alden_random_seed, '|comment-order|', c.`comment_id`)), c.`comment_id`;
SET @alden_missing_comments := (SELECT COUNT(*) FROM `tmp_alden_available_comments`);
SET @alden_new_count := (SELECT COUNT(*) FROM `tmp_alden_new_pairs`);
UPDATE `tmp_alden_seed` seed
JOIN `tmp_alden_new_pairs` fresh ON fresh.`seed_order` = seed.`seed_order`
JOIN `tmp_alden_available_comments` available ON available.`comment_slot` = fresh.`new_order`
JOIN `tmp_alden_comments` c ON c.`comment_id` = available.`comment_id`
SET seed.`comment_id` = c.`comment_id`, seed.`comment_text` = c.`comment_text`, seed.`tone` = c.`tone`;

SELECT @alden_employee_id AS `employee_id`, @alden_missing_comments AS `comments_to_assign`,
       @alden_new_count AS `new_sample_pairs`, @alden_unsupported_questions AS `unsupported_or_required_comment_questions`;

SET @alden_preflight_ok := (
    @alden_semester_id IS NOT NULL AND @alden_type_id IS NOT NULL
    AND @alden_professor_id IS NOT NULL AND @alden_questionnaire_id IS NOT NULL
    AND @alden_is_current = 1 AND @alden_rating_question_count > 0
    AND @alden_unsupported_questions = 0 AND @alden_class_count > 0
    AND @alden_unseedable_classes = 0 AND @alden_candidate_count >= @alden_target
    AND @alden_new_count >= @alden_missing_comments
);
CREATE TEMPORARY TABLE `tmp_alden_ready` (`id` TINYINT NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO `tmp_alden_ready` VALUES (1);
INSERT INTO `tmp_alden_ready` SELECT IF(@alden_preflight_ok, 2, 1);

CREATE TEMPORARY TABLE `tmp_alden_ratings` (
    `seed_order` INT UNSIGNED NOT NULL,
    `question_id` BIGINT UNSIGNED NOT NULL,
    `display_order` INT NOT NULL,
    `rating_value` TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (`seed_order`, `question_id`)
) ENGINE=InnoDB;
INSERT INTO `tmp_alden_ratings` (`seed_order`, `question_id`, `display_order`, `rating_value`)
SELECT seed.`seed_order`, q.`id`, q.`sort_order`,
       CASE seed.`tone`
           WHEN 'positive' THEN 4 + MOD(CRC32(CONCAT(@alden_random_seed, '|rating|', seed.`evaluator_user_id`, '|', seed.`course_offering_id`, '|', q.`id`)), 2)
           WHEN 'negative' THEN 1 + MOD(CRC32(CONCAT(@alden_random_seed, '|rating|', seed.`evaluator_user_id`, '|', seed.`course_offering_id`, '|', q.`id`)), 3)
           ELSE 2 + MOD(CRC32(CONCAT(@alden_random_seed, '|rating|', seed.`evaluator_user_id`, '|', seed.`course_offering_id`, '|', q.`id`)), 3) END
FROM `tmp_alden_seed` seed JOIN `tmp_alden_ready` ready ON ready.`id` = 2
JOIN `questions` q ON q.`questionnaire_id` = @alden_questionnaire_id AND q.`is_active` = 1 AND q.`deleted_at` IS NULL
JOIN `question_types` qt ON qt.`id` = q.`question_type_id` AND qt.`code` = 'rating'
WHERE seed.`seed_order` <= @alden_target;

UPDATE `tmp_alden_ratings` rating
JOIN `tmp_alden_seed` seed ON seed.`seed_order` = rating.`seed_order`
JOIN `evaluations` e ON e.`semester_id` = @alden_semester_id AND e.`evaluation_type_id` = @alden_type_id
    AND e.`course_offering_id` = seed.`course_offering_id` AND e.`evaluator_user_id` = seed.`evaluator_user_id`
    AND e.`evaluatee_user_id` = @alden_professor_id
    AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`, '$.seedSource')) = @alden_seed_source
JOIN `evaluation_responses` er ON er.`evaluation_id` = e.`id` AND er.`question_id` = rating.`question_id`
SET rating.`rating_value` = er.`rating_value`;

CREATE TEMPORARY TABLE `tmp_alden_seed_compare` (
    `seed_order` INT UNSIGNED NOT NULL PRIMARY KEY,
    `evaluator_user_id` BIGINT UNSIGNED NOT NULL,
    `comment_id` TINYINT UNSIGNED NULL
) ENGINE=InnoDB;
INSERT INTO `tmp_alden_seed_compare` SELECT `seed_order`, `evaluator_user_id`, `comment_id`
FROM `tmp_alden_seed` WHERE `seed_order` <= @alden_target;
CREATE TEMPORARY TABLE `tmp_alden_rating_compare` (
    `seed_order` INT UNSIGNED NOT NULL,
    `question_id` BIGINT UNSIGNED NOT NULL,
    `rating_value` TINYINT UNSIGNED NOT NULL,
    PRIMARY KEY (`seed_order`, `question_id`)
) ENGINE=InnoDB;
INSERT INTO `tmp_alden_rating_compare` SELECT `seed_order`, `question_id`, `rating_value` FROM `tmp_alden_ratings`;
CREATE TEMPORARY TABLE `tmp_alden_rating_similarity` (
    `seed_order` INT UNSIGNED NOT NULL,
    `other_order` INT UNSIGNED NOT NULL,
    `similarity` DECIMAL(12,10) NOT NULL,
    PRIMARY KEY (`seed_order`, `other_order`)
) ENGINE=InnoDB;
INSERT INTO `tmp_alden_rating_similarity`
SELECT own.`seed_order`, other.`seed_order`, SUM(a.`rating_value` = b.`rating_value`) / COUNT(*)
FROM `tmp_alden_seed` own
JOIN `tmp_alden_seed_compare` other ON other.`evaluator_user_id` = own.`evaluator_user_id`
    AND other.`seed_order` <> own.`seed_order`
JOIN `tmp_alden_ratings` a ON a.`seed_order` = own.`seed_order`
JOIN `tmp_alden_rating_compare` b ON b.`seed_order` = other.`seed_order` AND b.`question_id` = a.`question_id`
WHERE own.`seed_order` <= @alden_target GROUP BY own.`seed_order`, other.`seed_order`;

UPDATE `tmp_alden_comments` SET `normalized_text` = TRIM(REPLACE(REPLACE(
    LOWER(REPLACE(REPLACE(REPLACE(REPLACE(`comment_text`, '.', ' '), ',', ' '), '-', ' '), CHAR(39), ' ')), '  ', ' '), '  ', ' '));
CREATE TEMPORARY TABLE `tmp_alden_word_positions` (`position` INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB;
INSERT INTO `tmp_alden_word_positions` VALUES (1),(2),(3),(4),(5),(6),(7),(8),(9),(10),(11),(12),(13),(14),(15),(16),(17),(18),(19),(20),(21),(22),(23),(24),(25),(26),(27),(28),(29),(30),(31),(32),(33),(34),(35),(36),(37),(38),(39),(40),(41),(42),(43),(44),(45),(46),(47),(48),(49),(50),(51),(52),(53),(54),(55),(56),(57),(58),(59),(60),(61),(62),(63),(64);
CREATE TEMPORARY TABLE `tmp_alden_comment_tokens` (
    `comment_id` TINYINT UNSIGNED NOT NULL,
    `token` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`comment_id`, `token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `tmp_alden_comment_tokens`
SELECT DISTINCT c.`comment_id`, SUBSTRING_INDEX(SUBSTRING_INDEX(c.`normalized_text`, ' ', p.`position`), ' ', -1)
FROM `tmp_alden_comments` c JOIN `tmp_alden_word_positions` p
    ON p.`position` <= 1 + LENGTH(c.`normalized_text`) - LENGTH(REPLACE(c.`normalized_text`, ' ', ''))
WHERE CHAR_LENGTH(SUBSTRING_INDEX(SUBSTRING_INDEX(c.`normalized_text`, ' ', p.`position`), ' ', -1)) >= 3;
UPDATE `tmp_alden_comments` c SET c.`token_count` = (
    SELECT COUNT(*) FROM `tmp_alden_comment_tokens` t WHERE t.`comment_id` = c.`comment_id`
);
CREATE TEMPORARY TABLE `tmp_alden_token_compare` (
    `comment_id` TINYINT UNSIGNED NOT NULL, `token` VARCHAR(100) NOT NULL,
    PRIMARY KEY (`comment_id`, `token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT INTO `tmp_alden_token_compare` SELECT `comment_id`, `token` FROM `tmp_alden_comment_tokens`;
CREATE TEMPORARY TABLE `tmp_alden_comment_compare` (
    `comment_id` TINYINT UNSIGNED NOT NULL PRIMARY KEY, `token_count` INT UNSIGNED NOT NULL
) ENGINE=InnoDB;
INSERT INTO `tmp_alden_comment_compare` SELECT `comment_id`, `token_count` FROM `tmp_alden_comments`;
CREATE TEMPORARY TABLE `tmp_alden_comment_similarity` (
    `comment_id` TINYINT UNSIGNED NOT NULL, `other_id` TINYINT UNSIGNED NOT NULL,
    `overlap` INT UNSIGNED NOT NULL, `similarity` DECIMAL(12,10) NOT NULL,
    PRIMARY KEY (`comment_id`, `other_id`)
) ENGINE=InnoDB;
INSERT INTO `tmp_alden_comment_similarity`
SELECT own.`comment_id`, other.`comment_id`, COUNT(b.`token`),
    COUNT(b.`token`) / GREATEST(own.`token_count`, other.`token_count`)
FROM `tmp_alden_comments` own JOIN `tmp_alden_comment_compare` other ON other.`comment_id` <> own.`comment_id`
LEFT JOIN `tmp_alden_comment_tokens` a ON a.`comment_id` = own.`comment_id`
LEFT JOIN `tmp_alden_token_compare` b ON b.`comment_id` = other.`comment_id` AND b.`token` = a.`token`
GROUP BY own.`comment_id`, other.`comment_id`, own.`token_count`, other.`token_count`;

CREATE TEMPORARY TABLE `tmp_alden_behavior` (
    `seed_order` INT UNSIGNED NOT NULL PRIMARY KEY,
    `rating_share` DECIMAL(12,10) NOT NULL,
    `rating_similarity` DECIMAL(12,10) NOT NULL DEFAULT 0,
    `same_overlap` INT UNSIGNED NOT NULL DEFAULT 0,
    `cross_overlap` INT UNSIGNED NOT NULL DEFAULT 0,
    `same_similarity` DECIMAL(12,10) NOT NULL DEFAULT 0,
    `cross_similarity` DECIMAL(12,10) NOT NULL DEFAULT 0,
    `comment_similarity` DECIMAL(12,10) NOT NULL DEFAULT 0,
    `rating_repetitive` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `comment_repetitive` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `uniform_risk` DECIMAL(12,10) NOT NULL DEFAULT 0,
    `repetition_risk` DECIMAL(12,10) NOT NULL DEFAULT 0,
    `speed_risk` DECIMAL(12,10) NOT NULL DEFAULT 0,
    `behavior_score` TINYINT UNSIGNED NOT NULL DEFAULT 0,
    `flags` LONGTEXT NOT NULL
) ENGINE=InnoDB;
INSERT INTO `tmp_alden_behavior` (`seed_order`, `rating_share`, `flags`)
SELECT `seed_order`, GREATEST(SUM(`rating_value`=1),SUM(`rating_value`=2),SUM(`rating_value`=3),SUM(`rating_value`=4),SUM(`rating_value`=5)) / COUNT(*), JSON_ARRAY()
FROM `tmp_alden_ratings` GROUP BY `seed_order`;
UPDATE `tmp_alden_behavior` b JOIN `tmp_alden_seed` seed ON seed.`seed_order` = b.`seed_order`
SET b.`rating_similarity` = COALESCE((SELECT MAX(r.`similarity`) FROM `tmp_alden_rating_similarity` r WHERE r.`seed_order`=b.`seed_order`),0),
    b.`same_overlap` = COALESCE((SELECT MAX(c.`overlap`) FROM `tmp_alden_comment_similarity` c
        JOIN `tmp_alden_seed_compare` other ON other.`comment_id`=c.`other_id` AND other.`evaluator_user_id`=seed.`evaluator_user_id`
        WHERE c.`comment_id`=seed.`comment_id`),0),
    b.`cross_overlap` = COALESCE((SELECT MAX(c.`overlap`) FROM `tmp_alden_comment_similarity` c
        JOIN `tmp_alden_seed_compare` other ON other.`comment_id`=c.`other_id` AND other.`evaluator_user_id`<>seed.`evaluator_user_id`
        WHERE c.`comment_id`=seed.`comment_id`),0);
UPDATE `tmp_alden_behavior` b JOIN `tmp_alden_seed` seed ON seed.`seed_order`=b.`seed_order`
SET b.`same_similarity`=COALESCE((SELECT MAX(c.`similarity`) FROM `tmp_alden_comment_similarity` c
        JOIN `tmp_alden_seed_compare` other ON other.`comment_id`=c.`other_id` AND other.`evaluator_user_id`=seed.`evaluator_user_id`
        WHERE c.`comment_id`=seed.`comment_id` AND c.`overlap`=b.`same_overlap`),0),
    b.`cross_similarity`=COALESCE((SELECT MAX(c.`similarity`) FROM `tmp_alden_comment_similarity` c
        JOIN `tmp_alden_seed_compare` other ON other.`comment_id`=c.`other_id` AND other.`evaluator_user_id`<>seed.`evaluator_user_id`
        WHERE c.`comment_id`=seed.`comment_id` AND c.`overlap`=b.`cross_overlap`),0);
UPDATE `tmp_alden_behavior` b
SET b.`comment_similarity`=GREATEST(b.`same_similarity`,b.`cross_similarity`),
    b.`rating_repetitive`=(@alden_rating_question_count >= 2 AND b.`rating_similarity` >= 0.9),
    b.`comment_repetitive`=((b.`same_overlap` >= 5 AND b.`same_similarity` >= 0.9) OR (b.`cross_overlap` >= 5 AND b.`cross_similarity` >= 0.9)),
    b.`uniform_risk`=IF(b.`rating_share` >= 0.9,b.`rating_share`,b.`rating_share`*0.35);

CREATE TEMPORARY TABLE `tmp_alden_sorted_times` (
    `time_order` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY, `seconds_per_question` SMALLINT UNSIGNED NOT NULL
) ENGINE=InnoDB;
INSERT INTO `tmp_alden_sorted_times` (`seconds_per_question`)
SELECT `seconds_per_question` FROM `tmp_alden_seed` WHERE `seed_order`<=@alden_target ORDER BY `seconds_per_question`, `seed_order`;
SET @alden_fast_threshold := (SELECT GREATEST(2.5,AVG(`seconds_per_question`)*0.65)
    FROM `tmp_alden_sorted_times` WHERE `time_order` IN(FLOOR((@alden_target+1)/2),FLOOR((@alden_target+2)/2)));
UPDATE `tmp_alden_behavior` b JOIN `tmp_alden_seed` seed ON seed.`seed_order`=b.`seed_order`
SET b.`repetition_risk`=GREATEST(IF(b.`rating_repetitive`,b.`rating_similarity`,b.`rating_similarity`*0.25),
        IF(b.`comment_repetitive`,b.`comment_similarity`,b.`comment_similarity`*0.25)),
    b.`speed_risk`=IF(seed.`seconds_per_question`<=@alden_fast_threshold,1-seed.`seconds_per_question`/@alden_fast_threshold,0);
UPDATE `tmp_alden_behavior` b JOIN `tmp_alden_seed` seed ON seed.`seed_order`=b.`seed_order`
SET b.`behavior_score`=ROUND(100*(1-0.40*b.`speed_risk`-0.35*b.`uniform_risk`-0.25*b.`repetition_risk`)),
    b.`flags`=JSON_MERGE_PRESERVE(
        IF(seed.`seconds_per_question`<=@alden_fast_threshold,JSON_ARRAY('Rapid completion / low seconds per question'),JSON_ARRAY()),
        IF(b.`rating_share`>=0.9,JSON_ARRAY('Uniform response pattern'),JSON_ARRAY()),
        IF(b.`rating_repetitive` OR b.`comment_repetitive`,JSON_ARRAY('Repetitive response pattern'),JSON_ARRAY()));

SET @alden_question_count := (SELECT COUNT(*) FROM `questions` WHERE `questionnaire_id`=@alden_questionnaire_id AND `is_active`=1 AND `deleted_at` IS NULL);
INSERT INTO `evaluations` (
    `semester_id`, `questionnaire_id`, `evaluation_type_id`, `evaluator_user_id`, `evaluatee_user_id`, `course_offering_id`,
    `general_comments`, `submitted_at`, `status`, `submission_duplicate_key`, `behavior_meta`, `behavior_score`,
    `credibility_score`, `credibility_status`, `credibility_components`, `credibility_flags`, `credibility_calculated_at`
)
SELECT @alden_semester_id, @alden_questionnaire_id, @alden_type_id, seed.`evaluator_user_id`, @alden_professor_id, seed.`course_offering_id`,
    seed.`comment_text`, seed.`submitted_at`, 'submitted',
    SHA2(CONCAT('evaluation-submission-v1|student-professor|',@alden_semester_id,'|',@alden_type_id,'|',seed.`evaluator_user_id`,'|course|',seed.`course_offering_id`),256),
    JSON_OBJECT('captureVersion',1,
        'startedAt',CONCAT(DATE_FORMAT(DATE_SUB(seed.`submitted_at`,INTERVAL (@alden_rating_question_count*seed.`seconds_per_question`) SECOND),'%Y-%m-%dT%H:%i:%s'),'+08:00'),
        'submittedAt',CONCAT(DATE_FORMAT(seed.`submitted_at`,'%Y-%m-%dT%H:%i:%s'),'+08:00'),
        'durationSeconds',@alden_rating_question_count*seed.`seconds_per_question`,
        'questionCount',@alden_question_count,'answeredCount',@alden_rating_question_count,'secondsPerQuestion',seed.`seconds_per_question`,
        'synthetic',TRUE,'timingSource','simulated-sample-v1'),
    b.`behavior_score`,80,'AUTO_ACCEPTED',
    JSON_OBJECT('behavior',NULL,'bias',80,'cross',NULL,'biasSource','fixture','crossStatus','Comparator unavailable',
        'formulaVersion',2,'seedSource',@alden_seed_source,'synthetic',TRUE,'fixtureCredibilityScore',80,
        'credibilityPolicy','accepted-sample-fixture','employeeId',@alden_employee_id,
        'ratingTone',seed.`tone`,'commentId',seed.`comment_id`,'commentTone',IF(seed.`comment_id` IS NULL,NULL,seed.`tone`),
        'randomSeed',@alden_random_seed,'timingSource','simulated-sample-v1',
        'timingNote','Simulated sample timing, not observed browser activity. Fixture credibility is separate from sample performance.',
        'behaviorDetails',JSON_OBJECT('score',b.`behavior_score`,'flags',JSON_EXTRACT(b.`flags`,'$'),
            'ratingRepetitiveFlag',JSON_EXTRACT(IF(b.`rating_repetitive`,'true','false'),'$'),
            'commentRepetitiveFlag',JSON_EXTRACT(IF(b.`comment_repetitive`,'true','false'),'$'),
            'repetitiveFlag',JSON_EXTRACT(IF(b.`rating_repetitive` OR b.`comment_repetitive`,'true','false'),'$'),
            'requiresSpeedReview',JSON_EXTRACT('false','$'),'speedRisk',b.`speed_risk`,'uniformityRisk',b.`uniform_risk`,
            'repetitionRisk',b.`repetition_risk`,'fastThreshold',@alden_fast_threshold,'source','simulated-sample-v1')),
    JSON_ARRAY(),@alden_import_time
FROM `tmp_alden_new_pairs` fresh JOIN `tmp_alden_seed` seed ON seed.`seed_order`=fresh.`seed_order`
JOIN `tmp_alden_behavior` b ON b.`seed_order`=seed.`seed_order` JOIN `tmp_alden_ready` ready ON ready.`id`=2
WHERE NOT EXISTS(SELECT 1 FROM `evaluations` e WHERE e.`semester_id`=@alden_semester_id AND e.`evaluation_type_id`=@alden_type_id
    AND e.`course_offering_id`=seed.`course_offering_id` AND e.`evaluator_user_id`=seed.`evaluator_user_id`)
ORDER BY seed.`seed_order`;
SET @alden_inserted_evaluations := ROW_COUNT();

INSERT INTO `evaluation_responses` (`evaluation_id`,`question_id`,`rating_value`,`text_value`,`display_order`)
SELECT e.`id`,r.`question_id`,r.`rating_value`,NULL,r.`display_order`
FROM `tmp_alden_new_pairs` fresh JOIN `tmp_alden_seed` seed ON seed.`seed_order`=fresh.`seed_order`
JOIN `tmp_alden_ratings` r ON r.`seed_order`=seed.`seed_order` JOIN `tmp_alden_ready` ready ON ready.`id`=2
JOIN `evaluations` e ON e.`semester_id`=@alden_semester_id AND e.`evaluation_type_id`=@alden_type_id
    AND e.`evaluatee_user_id`=@alden_professor_id AND e.`course_offering_id`=seed.`course_offering_id`
    AND e.`evaluator_user_id`=seed.`evaluator_user_id` AND e.`questionnaire_id`=@alden_questionnaire_id
    AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`,'$.seedSource'))=@alden_seed_source
WHERE NOT EXISTS(SELECT 1 FROM `evaluation_responses` old WHERE old.`evaluation_id`=e.`id` AND old.`question_id`=r.`question_id`)
ORDER BY e.`id`,r.`display_order`,r.`question_id`;
SET @alden_inserted_answers := ROW_COUNT();

SET @alden_completed := (
    SELECT COUNT(DISTINCT e.`course_offering_id`,e.`evaluator_user_id`)
    FROM `tmp_alden_classes` c
    JOIN `student_course_enrollments` sce ON sce.`course_offering_id`=c.`course_offering_id` AND sce.`status` IN('enrolled','completed')
    JOIN `evaluations` e ON e.`course_offering_id`=c.`course_offering_id` AND e.`evaluator_user_id`=sce.`student_id`
        AND e.`semester_id`=@alden_semester_id AND e.`evaluation_type_id`=@alden_type_id AND e.`evaluatee_user_id`=@alden_professor_id
        AND e.`status`='submitted' AND e.`credibility_status` IN('AUTO_ACCEPTED','ACCEPTED_BY_HR')
    JOIN `evaluation_responses` r ON r.`evaluation_id`=e.`id` AND r.`rating_value` BETWEEN 1 AND 5
);
COMMIT;
SELECT @alden_employee_id AS `employee_id`,@alden_semester_slug AS `semester`,@alden_inserted_evaluations AS `new_samples`,
       @alden_inserted_answers AS `new_rating_answers`,@alden_registered_total AS `required_evaluations`,@alden_completed AS `completed_evaluations`,
       ROUND(100*@alden_completed/NULLIF(@alden_registered_total,0),2) AS `completion_percent`;
SELECT COUNT(*) AS `total_samples`,COUNT(NULLIF(TRIM(e.`general_comments`),'')) AS `commented_samples`,
       COUNT(DISTINCT NULLIF(TRIM(e.`general_comments`),'')) AS `distinct_comments`,
       SUM(e.`general_comments` IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`,'$.commentTone'))='positive') AS `positive_comments`,
       SUM(e.`general_comments` IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`,'$.commentTone'))='negative') AS `negative_comments`,
       SUM(e.`general_comments` IS NOT NULL AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`,'$.commentTone'))='constructive') AS `constructive_comments`,
       COUNT(e.`behavior_score`) AS `numeric_behavior_scores`
FROM `evaluations` e WHERE e.`semester_id`=@alden_semester_id AND e.`evaluatee_user_id`=@alden_professor_id
    AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`,'$.seedSource'))=@alden_seed_source;
SELECT c.`course_offering_id`,c.`subject_code`,c.`section_name`,c.`registered`,
       COUNT(DISTINCT CASE WHEN e.`status`='submitted' AND e.`credibility_status` IN('AUTO_ACCEPTED','ACCEPTED_BY_HR')
           AND r.`rating_value` BETWEEN 1 AND 5 THEN e.`evaluator_user_id` END) AS `accepted_raters`
FROM `tmp_alden_classes` c
LEFT JOIN `student_course_enrollments` sce ON sce.`course_offering_id`=c.`course_offering_id` AND sce.`status` IN('enrolled','completed')
LEFT JOIN `evaluations` e ON e.`course_offering_id`=c.`course_offering_id` AND e.`evaluator_user_id`=sce.`student_id`
    AND e.`semester_id`=@alden_semester_id AND e.`evaluation_type_id`=@alden_type_id AND e.`evaluatee_user_id`=@alden_professor_id
LEFT JOIN `evaluation_responses` r ON r.`evaluation_id`=e.`id`
GROUP BY c.`course_offering_id`,c.`subject_code`,c.`section_name`,c.`registered` ORDER BY c.`course_offering_id`;

DROP TEMPORARY TABLE `tmp_alden_sorted_times`;
DROP TEMPORARY TABLE `tmp_alden_behavior`;
DROP TEMPORARY TABLE `tmp_alden_comment_similarity`;
DROP TEMPORARY TABLE `tmp_alden_comment_compare`;
DROP TEMPORARY TABLE `tmp_alden_token_compare`;
DROP TEMPORARY TABLE `tmp_alden_comment_tokens`;
DROP TEMPORARY TABLE `tmp_alden_word_positions`;
DROP TEMPORARY TABLE `tmp_alden_rating_similarity`;
DROP TEMPORARY TABLE `tmp_alden_rating_compare`;
DROP TEMPORARY TABLE `tmp_alden_seed_compare`;
DROP TEMPORARY TABLE `tmp_alden_ratings`;
DROP TEMPORARY TABLE `tmp_alden_ready`;
DROP TEMPORARY TABLE `tmp_alden_available_comments`;
DROP TEMPORARY TABLE `tmp_alden_new_pairs`;
DROP TEMPORARY TABLE `tmp_alden_seed`;
DROP TEMPORARY TABLE `tmp_alden_candidate_order`;
DROP TEMPORARY TABLE `tmp_alden_candidates`;
DROP TEMPORARY TABLE `tmp_alden_classes`;
DROP TEMPORARY TABLE `tmp_alden_comments`;
