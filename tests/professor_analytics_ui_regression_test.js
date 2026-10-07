'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const read = (relativePath) => fs.readFileSync(path.join(root, relativePath), 'utf8');

const hrSource = read('JsScrip/hrpanel.js');
const adminSource = read('JsScrip/adminpanel.js');
const vpaaSource = read('JsScrip/vpaapanel.js');
const backendSource = read('api/app_state.php');
const hrCss = read('css/hrpanel.css');
const adminCss = read('css/adminpanel.css');
const vpaaCss = read('css/vpaapanel.css');
const hrHtml = read('html/hrpanel.html');
const adminHtml = read('html/adminpanel.html');
const vpaaHtml = read('html/vpaapanel.html');

function sourceBetween(source, startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    assert.notEqual(start, -1, `Missing source marker: ${startMarker}`);
    const end = source.indexOf(endMarker, start + startMarker.length);
    assert.notEqual(end, -1, `Missing source marker: ${endMarker}`);
    return source.slice(start, end);
}

function buildSnapshots(peerKey = 'peer') {
    return {
        student: {
            totalRaters: 25,
            evaluatedCount: 20,
            notEvaluatedCount: 5,
            averageRating: 4.5,
            qualitativeResponses: [{ text: 'Student comment' }],
        },
        [peerKey]: {
            totalRaters: 5,
            evaluatedCount: 4,
            notEvaluatedCount: 1,
            averageRating: 3,
            qualitativeResponses: [{ text: 'Peer comment' }],
        },
        supervisor: {
            totalRaters: 1,
            evaluatedCount: 1,
            notEvaluatedCount: 0,
            averageRating: 5,
            qualitativeResponses: [{ text: 'Supervisor comment' }],
        },
    };
}

for (const [panel, source] of [
    ['HR', hrSource],
    ['Admin', adminSource],
    ['VPAA', vpaaSource],
]) {
    assert.doesNotMatch(source, /rationale[^\n]*, 320\)/, `${panel} still truncates the AI rationale at 320 characters.`);
    assert.match(source, /rationale[^\n]*, 900\)/, `${panel} does not retain the complete AI rationale.`);
    assert.match(source, /label:\s*["']All Evaluations["']/, `${panel} is missing the combined evaluation type.`);
    assert.doesNotMatch(source, /const setCoverageLabel/, `${panel} still renders the section-coverage badge beside Average Rating.`);
}

const hrContext = { getEvaluationTypeMeta: () => ({ id: 'all' }) };
vm.createContext(hrContext);
vm.runInContext(
    sourceBetween(hrSource, 'function combineHrProfessorEvaluationSnapshots(', '\nfunction getHrProfessorEvaluationSnapshot(')
        + '\nthis.combine = combineHrProfessorEvaluationSnapshots;',
    hrContext
);
const hrCombined = hrContext.combine(buildSnapshots());
assert.equal(hrCombined.averageRating, 4.25);
assert.equal(hrCombined.totalRaters, 31);
assert.equal(hrCombined.evaluatedCount, 25);
assert.deepEqual(Array.from(hrCombined.qualitativeResponses, (row) => row.evaluationLabel), [
    'Student Evaluation', 'Peer Evaluation', 'Supervisor Evaluation'
]);

const adminContext = { getEvaluationTypeMeta: () => ({ id: 'all' }) };
vm.createContext(adminContext);
vm.runInContext(
    sourceBetween(adminSource, 'function combineAdminProfessorEvaluationSnapshots(', '\nfunction getEvaluationSnapshotForType(')
        + '\nthis.combine = combineAdminProfessorEvaluationSnapshots;',
    adminContext
);
const adminCombined = adminContext.combine(buildSnapshots());
assert.equal(adminCombined.averageRating, 4.25);
assert.equal(adminCombined.totalRaters, 31);
assert.equal(adminCombined.evaluatedCount, 25);

const vpaaContext = { getVpaaEvaluationTypeMeta: () => ({ id: 'all' }) };
vm.createContext(vpaaContext);
vm.runInContext(
    sourceBetween(vpaaSource, 'function combineVpaaProfessorEvaluationSnapshots(', '\nfunction getVpaaProfessorEvaluationSnapshot(')
        + '\nthis.combine = combineVpaaProfessorEvaluationSnapshots;',
    vpaaContext
);
const vpaaCombined = vpaaContext.combine(buildSnapshots('professor'));
assert.equal(vpaaCombined.averageRating, 4.25);
assert.equal(vpaaCombined.totalRaters, 31);
assert.equal(vpaaCombined.evaluatedCount, 25);

const missingStudent = buildSnapshots();
missingStudent.student = { totalRaters: 25, evaluatedCount: 0, notEvaluatedCount: 25, averageRating: null, qualitativeResponses: [] };
assert.equal(hrContext.combine(missingStudent).averageRating, 4, 'Missing rating sources must renormalize the remaining weights.');

assert.match(backendSource, /rationale[^\n]*, 900\)/, 'The backend still truncates the AI rationale too aggressively.');
assert.match(
    backendSource,
    /no more than three complete sentences or 700 characters, never end mid-sentence/,
    'The AI prompt must require a complete, bounded rationale.'
);

assert.match(hrSource, /const requestId = \+\+hrProfessorAnalyticsRequestId/);
assert.match(hrSource, /requestId !== hrProfessorAnalyticsRequestId/);
assert.match(hrSource, /hrProfessorAnalyticsRequestId \+= 1/);
assert.match(hrSource, /Updating analytics for the selected filters/);
assert.match(
    hrSource,
    /refreshSubjectManagement\(\{ semesterId: 'all' \}\)/,
    'HR analytics must load cross-semester subject data before building overall and historical metrics.'
);
assert.match(
    hrSource,
    /fetchEvaluationsSnapshot\(\{ semesterId: 'all', evaluateeUserId: professor.id, analyticsEligible: true \}\)/,
    'HR analytics must load cross-semester evaluations before building overall and historical metrics.'
);
assert.match(
    hrSource,
    /roles: \['professor', 'dean', 'procoor', 'hr', 'supervisor'\]/,
    'HR analytics must use the complete paginated professor/supervisor directory.'
);
assert.doesNotMatch(
    hrSource,
    /getHrApplicableSupervisorUsersForProfessor\([^\n]+\)\.length \|\| 1/,
    'HR analytics must not invent a supervisor when no applicable supervisor exists.'
);

const hrProfessorRenderingSource = [
    sourceBetween(hrSource, 'function renderProfessorRanking(', '\nfunction getRankingIcon('),
    sourceBetween(hrSource, 'function buildProfessorTableMarkup(', '\nfunction bindProfessorActionButtons('),
    sourceBetween(hrSource, 'function viewProfessorDetails(', '\n/**\n * Close professor details modal'),
    sourceBetween(hrSource, 'async function viewProfessorAnalytics(', '\n/**\n * Generate star rating display'),
].join('\n');
assert.doesNotMatch(hrProfessorRenderingSource, /\$\{professor\.name\}/, 'Professor names must not be written to innerHTML without encoding.');
assert.doesNotMatch(hrProfessorRenderingSource, /\$\{prof\.name\}/, 'Ranking names must not be written to innerHTML without encoding.');
assert.match(hrProfessorRenderingSource, /escapeHrHtml\(response\.text \|\| ''\)/, 'Qualitative feedback must be HTML encoded.');
assert.match(hrProfessorRenderingSource, /escapeHrHtml\(response\.studentName \|\| evaluatorLabel\)/, 'Evaluator names must be HTML encoded.');
assert.doesNotMatch(hrProfessorRenderingSource, /onclick="closeProfessorDetailsModal/, 'Professor actions must not interpolate identifiers into inline handlers.');

assert.match(adminSource, /const requestId = \+\+adminProfessorAnalyticsRequestId/);
assert.match(adminSource, /requestId !== adminProfessorAnalyticsRequestId/);
assert.match(adminSource, /adminProfessorAnalyticsRequestId \+= 1/);
assert.match(adminSource, /Updating analytics for the selected filters/);

assert.match(vpaaSource, /const renderId = \+\+vpaaProfessorAnalyticsRenderId/);
assert.match(vpaaSource, /renderId !== vpaaProfessorAnalyticsRenderId/);
assert.match(vpaaSource, /scheduleVpaaProfessorAnalyticsRender\(currentVpaaAnalyticsProfessorId\)/);
assert.match(vpaaSource, /vpaaProfessorAnalyticsRenderId \+= 1/);

for (const [panel, css] of [
    ['HR', hrCss],
    ['Admin', adminCss],
    ['VPAA', vpaaCss],
]) {
    assert.match(css, /professor-analytics-loading/, `${panel} is missing the analytics refresh indicator.`);
    assert.match(css, /overflow-wrap: anywhere/, `${panel} can still clip long AI explanation text.`);
}

assert.match(hrHtml, /professor-analytics-modal[^>]+aria-hidden="true"/);
assert.match(adminHtml, /professor-analytics-modal[^>]+aria-hidden="true"/);
for (const [panel, html] of [['hr', hrHtml], ['admin', adminHtml], ['vpaa', vpaaHtml]]) {
    for (const extension of ['css', 'js']) {
        assert.match(
            html,
            new RegExp(`${panel}panel\\.${extension}\\?v=\\d{8}[a-z0-9]*["']`),
            `${panel} analytics ${extension} must retain its cache-busting version.`
        );
    }
}

console.log('Professor analytics UI regression tests passed.');
