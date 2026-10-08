USE `izgrqywp_naap`;
SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;

SET @alexa_label_semester := '2nd-semester-2026-2027';
SET @alexa_label_source := 'alexa-web-2nd-2026-2027-sample-v2';
SET @alexa_label_prefix := '[SYNTHETIC SAMPLE]';
SET @alexa_label_professor := (
    SELECT u.`id` FROM `users` u JOIN `roles` r ON r.`id` = u.`role_id`
    WHERE r.`code` = 'professor'
      AND (u.`email` = 'professor.003@naap.edu.ph' OR u.`name` = 'Alexa I Cabrera')
    ORDER BY (u.`email` = 'professor.003@naap.edu.ph') DESC, u.`id` LIMIT 1
);

DROP TEMPORARY TABLE IF EXISTS `tmp_alexa_label_targets`;
START TRANSACTION;

CREATE TEMPORARY TABLE `tmp_alexa_label_targets` (
    `evaluation_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY
) ENGINE=InnoDB;
INSERT INTO `tmp_alexa_label_targets` (`evaluation_id`)
SELECT e.`id` AS `evaluation_id`
FROM `evaluations` e
JOIN `semesters` semester ON semester.`id` = e.`semester_id`
JOIN `evaluation_types` et ON et.`id` = e.`evaluation_type_id`
WHERE semester.`slug` = @alexa_label_semester AND et.`code` = 'student-professor'
  AND e.`evaluatee_user_id` = @alexa_label_professor
  AND JSON_UNQUOTE(JSON_EXTRACT(e.`credibility_components`, '$.seedSource')) = @alexa_label_source;

UPDATE `evaluations` e
JOIN `tmp_alexa_label_targets` target ON target.`evaluation_id` = e.`id`
SET e.`general_comments` = LTRIM(SUBSTRING(e.`general_comments`, CHAR_LENGTH(@alexa_label_prefix) + 1))
WHERE LEFT(e.`general_comments`, CHAR_LENGTH(@alexa_label_prefix)) = @alexa_label_prefix;
SET @alexa_labels_removed := ROW_COUNT();

UPDATE `evaluation_responses` er
JOIN `tmp_alexa_label_targets` target ON target.`evaluation_id` = er.`evaluation_id`
JOIN `questions` q ON q.`id` = er.`question_id`
JOIN `question_types` qt ON qt.`id` = q.`question_type_id` AND qt.`code` = 'qualitative'
SET er.`text_value` = LTRIM(SUBSTRING(er.`text_value`, CHAR_LENGTH(@alexa_label_prefix) + 1))
WHERE er.`rating_value` IS NULL
  AND LEFT(er.`text_value`, CHAR_LENGTH(@alexa_label_prefix)) = @alexa_label_prefix;
SET @alexa_answer_labels_removed := ROW_COUNT();

COMMIT;
SELECT @alexa_labels_removed AS `comment_labels_removed`,
       @alexa_answer_labels_removed AS `answer_labels_removed`;
DROP TEMPORARY TABLE `tmp_alexa_label_targets`;
