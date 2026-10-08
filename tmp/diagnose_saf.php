<?php
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/faculty_report_helper.php';
$stmt = $pdo->prepare('SELECT u.id FROM users u JOIN staff_profiles sp ON sp.user_id=u.id WHERE sp.employee_id=?');
$stmt->execute(['PRF-2026-002']);
$id = (int) $stmt->fetchColumn();
$professor = buildUserSnapshotById($pdo, 'u'.$id, false);
echo json_encode(['professor' => array_intersect_key($professor ?? [], array_flip(['id','name','department','campusId','campusSlug']))]), PHP_EOL;
$stmt = $pdo->prepare('SELECT paper_code, professor_user_id, professor_user_token, semester_slug, status, saf_rating, updated_at FROM faculty_acknowledgement_papers WHERE professor_user_id = ?');
$stmt->execute([$id]);
echo json_encode(['papers' => $stmt->fetchAll()]), PHP_EOL;
$stmt = $pdo->prepare("SELECT e.id, s.slug AS semester, t.code AS type, r.code AS evaluator_role, e.evaluator_user_id, e.evaluatee_user_id, e.status, e.credibility_status, e.credibility_score, COUNT(er.id) AS responses, AVG(er.rating_value) AS average_rating FROM evaluations e JOIN semesters s ON s.id=e.semester_id JOIN evaluation_types t ON t.id=e.evaluation_type_id JOIN users u ON u.id=e.evaluator_user_id JOIN roles r ON r.id=u.role_id LEFT JOIN evaluation_responses er ON er.evaluation_id=e.id WHERE e.evaluatee_user_id=? GROUP BY e.id ORDER BY e.id DESC");
$stmt->execute([$id]);
echo json_encode(['evaluations' => $stmt->fetchAll()]), PHP_EOL;
foreach ($pdo->query('SELECT slug FROM semesters')->fetchAll() as $row) {
    $slug = $row['slug'];
    $filters = facultyReportBuildScopedEvaluationTableFilters($pdo,$slug,'supervisor',true,false);
    $filters = facultyReportApplyProfessorCampusScope($pdo,$professor,$filters);
    $filters['evaluateeUserId'] = $id;
    echo json_encode(['semester'=>$slug,'filters'=>$filters,'inputs'=>facultyReportFetchSupervisorEvaluationsForProfessor($pdo,$professor,$slug,true,false),'calculated_saf'=>facultyReportBuildFacultyPaperSefRating($pdo,'u'.$id,$slug)]), PHP_EOL;
}
