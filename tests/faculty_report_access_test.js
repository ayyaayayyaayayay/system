'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = relativePath => fs.readFileSync(path.join(root, relativePath), 'utf8');

const deanHtml = read('html/daenpanel.html');
const coordinatorHtml = read('html/procoorpanel.html');
const professorHtml = read('html/profesorpanel.html');
const deanPanel = read('JsScrip/daenpanel.js');
const professorPanel = read('JsScrip/profesorpanel.js');
const sharedData = read('JsScrip/db-data.js');
const appState = read('api/app_state.php');
const stateHelpers = read('api/state_helpers.php');
const migrations = read('api/schema_migrations.php');
const schema = read('database/datacode.txt');

assert(deanHtml.includes('id="departmentReportAccessPanel"'), 'Dean report-access panel is missing.');
assert(deanHtml.includes('id="departmentReportAccessToggleBtn"'), 'Dean report-access toggle is missing.');
assert(!coordinatorHtml.includes('departmentReportAccessPanel'), 'Coordinator page exposes the dean-only report-access control.');
assert(deanPanel.includes("SUPERVISOR_ROLE !== 'dean'"), 'Shared supervisor script does not enforce dean-only UI access.');
assert(deanPanel.includes('SharedData.setDepartmentFacultyReportAccess(nextEnabled)'), 'Dean toggle is not connected to persistence.');

assert(schema.includes('CREATE TABLE IF NOT EXISTS `department_faculty_report_access`'), 'Canonical schema is missing the report-access table.');
assert(migrations.includes("'id' => 'department_faculty_report_access_v1'"), 'Schema migration is not registered.');
assert(stateHelpers.includes('function persistDepartmentFacultyReportAccessSnapshot('), 'Report-access persistence helper is missing.');
assert(stateHelpers.includes('function buildProfessorEvaluationCountsSnapshot('), 'Sanitized professor counts helper is missing.');
assert(stateHelpers.includes("$tableFilters['evaluatorUserId'] = $actorUserId"), 'Restricted professor evaluation queries are not limited to authored evaluations.');
assert(stateHelpers.includes("bootstrapRowHasUserToken($evaluation, ['evaluatorUserId'], $actorToken)"), 'Legacy/merged evaluation rows are not post-filtered to the author.');
assert(stateHelpers.includes("'facultyReportAccess' => $facultyReportAccess"), 'Bootstrap omits report-access state.');
assert(stateHelpers.includes("'professorEvaluationCounts' => $professorEvaluationCounts"), 'Bootstrap omits sanitized counts.');

assert(appState.includes("case 'getFacultyReportAccess':"), 'Read API action is missing.');
assert(appState.includes("case 'setDepartmentFacultyReportAccess':"), 'Write API action is missing.');
assert(appState.includes("if ($authenticatedRole !== 'dean')"), 'Write API does not enforce dean role.');
assert(appState.includes("'Department scope is derived from the authenticated dean.'"), 'Write API does not reject client-supplied department scope.');
assert(appState.includes('resolveActiveDeanScopeRow($pdo, $authenticatedDeanId)'), 'Write API does not require an active dean assignment.');
assert(appState.includes("!is_bool($body['enabled'])"), 'Write API does not validate the boolean payload.');

assert(sharedData.includes('function getFacultyReportAccess()'), 'SharedData report-access getter is missing.');
assert(sharedData.includes('function refreshFacultyReportAccess()'), 'SharedData report-access refresh is missing.');
assert(sharedData.includes('function setDepartmentFacultyReportAccess(enabled)'), 'SharedData report-access mutation is missing.');
assert(sharedData.includes('function getProfessorEvaluationCounts()'), 'SharedData sanitized-count getter is missing.');

assert(professorPanel.includes('function resolveEvaluationWindowGateState()'), 'The common evaluation-window gate is missing.');
assert.match(
    professorPanel,
    /function resolveFacultyPaperGateState\(\) \{\s*return resolveEvaluationWindowGateState\(\);\s*\}/,
    'Faculty Paper is still coupled to the report restriction.'
);
assert(professorPanel.includes('departmentRestricted'), 'Professor report gate ignores the dean restriction.');
assert(professorPanel.includes('accessPending = !professorReportAccessVerified'), 'Professor reports are not fail-closed while access is being verified.');
assert(professorPanel.includes("window.addEventListener('focus'"), 'Professor report access is not rechecked on focus.');
assert(professorPanel.includes('clearRestrictedProfessorReportData()'), 'Restricted report caches are not cleared.');
assert(professorPanel.includes('SharedData.getProfessorEvaluationCounts()'), 'Restricted dashboard does not retain sanitized counts.');
assert(professorHtml.includes('db-data.js?v=20261004a'), 'Professor SharedData cache version was not updated.');
assert(professorHtml.includes('profesorpanel.js?v=20261004a'), 'Professor panel cache version was not updated.');

console.log('Faculty report access regression tests passed.');
