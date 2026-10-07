'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const read = relativePath => fs.readFileSync(path.join(root, relativePath), 'utf8');
const professorSource = read('JsScrip/profesorpanel.js');
const sharedDataSource = read('JsScrip/db-data.js');
const stateHelperSource = read('api/state_helpers.php');
const professorHtml = read('html/profesorpanel.html');

function sourceBetween(source, startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    assert.notEqual(start, -1, `Missing source marker: ${startMarker}`);
    const end = source.indexOf(endMarker, start + startMarker.length);
    assert.notEqual(end, -1, `Missing source marker: ${endMarker}`);
    return source.slice(start, end);
}

const averageContext = { clampNumber: value => Math.max(1, Math.min(5, Number(value))) };
vm.createContext(averageContext);
vm.runInContext(
    sourceBetween(
        professorSource,
        'function computeAverageRatingFromEvaluations(',
        '\nfunction buildProfessorPanelContext('
    ) + '\nthis.computeAverage = computeAverageRatingFromEvaluations;',
    averageContext
);
assert.equal(averageContext.computeAverage([]), null, 'An empty rating set must be unavailable, not zero.');
assert.equal(averageContext.computeAverage([{ ratings: { q1: 5, q2: 3 } }]), 4);

const snapshotContext = { professorPanelState: { semesterSnapshots: {} } };
vm.createContext(snapshotContext);
vm.runInContext(
    sourceBetween(
        professorSource,
        'function cacheProfessorSemesterSnapshot(',
        '\nasync function refreshProfessorTrendSnapshots('
    ) + '\nthis.cacheSnapshot = cacheProfessorSemesterSnapshot; this.buildContext = buildProfessorContextForSemester;',
    snapshotContext
);
snapshotContext.cacheSnapshot('current-term', {
    subjects: [],
    offerings: [{ id: '101', semesterSlug: 'current-term' }],
    enrollments: [{ courseOfferingId: '101', studentUserId: 'u201' }],
}, [{ id: 'current-evaluation', semesterId: 'current-term' }]);
snapshotContext.cacheSnapshot('old-term', {
    subjects: [],
    offerings: [{ id: '102', semesterSlug: 'old-term', isActive: false }],
    enrollments: [{ courseOfferingId: '102', studentUserId: 'u202', status: 'completed' }],
}, [{ id: 'old-evaluation', semesterId: 'old-term' }]);

const baseContext = { currentSemester: 'current-term', users: [], lookupMaps: {} };
const currentContext = snapshotContext.buildContext(baseContext, 'current-term');
const oldContext = snapshotContext.buildContext(baseContext, 'old-term');
assert.equal(currentContext.offerings[0].id, '101');
assert.equal(oldContext.offerings[0].id, '102');
assert.equal(oldContext.evaluations[0].id, 'old-evaluation');
assert.equal(oldContext.offeringsById['102'].semesterSlug, 'old-term');

const selectionSource = sourceBetween(
    professorSource,
    'function setupSemesterFilter()',
    '\nfunction populateSemesterFilterOptions('
);
assert.match(selectionSource, /const requestId = \+\+professorReportSelectionRequestId/);
assert.match(selectionSource, /professorPanelState\.currentSelection = \{[\s\S]*?semesterId: value/);
assert.ok(
    selectionSource.indexOf('professorPanelState.currentSelection = {') < selectionSource.indexOf('await Promise.all'),
    'The selected semester must be committed before asynchronous report loading starts.'
);
assert.match(selectionSource, /requestId !== professorReportSelectionRequestId/);
assert.match(selectionSource, /fetchSubjectManagementSnapshot/);
assert.match(selectionSource, /fetchEvaluationsSnapshot/);

assert.match(professorSource, /semesterSnapshots:\s*\{\}/);
assert.match(professorSource, /buildProfessorContextForSemester\(context, id\)/);
assert.match(professorSource, /peerAverage === null \? 'N\/A'/);
assert.match(professorSource, /supervisorAverage === null \? 'N\/A'/);
assert.match(professorSource, /requiredTotal = 1;/);
assert.match(professorSource, /includeInactiveOfferings:\s*Boolean\(/);

const evaluationFetchSource = sourceBetween(
    sharedDataSource,
    'function fetchEvaluationsSnapshot(',
    '\n    function scheduleEvaluationsRefresh('
);
assert.doesNotMatch(evaluationFetchSource, /applyEvaluationsResponse/);
assert.match(evaluationFetchSource, /return deepClone/);

const subjectFetchSource = sourceBetween(
    sharedDataSource,
    'function fetchSubjectManagementSnapshot(',
    '\n    function scheduleSubjectManagementRefresh('
);
assert.doesNotMatch(subjectFetchSource, /applySubjectManagementSnapshot/);
assert.match(subjectFetchSource, /return deepClone/);

assert.match(stateHelperSource, /\$includeInactiveOfferings = !empty\(\$filters\['includeInactiveOfferings'\]\)/);
assert.match(stateHelperSource, /\$semesterSlug !== \$currentSemesterSlug/);
assert.match(stateHelperSource, /if \(!\$includeInactiveOfferings\) \{\s*\$where\[\] = 'co\.is_active = 1';/);

assert.ok(professorHtml.includes('db-data.js?v=20261004a'));
assert.ok(professorHtml.includes('profesorpanel.js?v=20261006cmo'));

console.log('Professor report state regression tests passed.');
