'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { spawnSync } = require('node:child_process');
const source = fs.readFileSync(path.join(__dirname, '..', 'JsScrip', 'hrpanel.js'), 'utf8');
function between(start, end) {
    const from = source.indexOf(start);
    const to = source.indexOf(end, from + start.length);
    assert.ok(from >= 0 && to > from, start);
    return source.slice(from, to);
}
const elements = Object.fromEntries([
    'hr-ai-discrepancy-semester', 'hr-ai-discrepancy-feedback',
    'hr-ai-discrepancy-summary', 'hr-ai-discrepancy-body',
    'hr-run-discrepancy-analysis-btn',
].map(id => [id, { value: 'current', textContent: '', innerHTML: '', disabled: false }]));
elements['hr-run-discrepancy-analysis-btn'].innerHTML = 'Run Discrepancy Check';
let resolveFetch;
let rejectFetch;
let requestedFilters;
const sandbox = {
    console: { error() {} },
    document: { getElementById: id => elements[id] || null },
    SharedData: {
        fetchEvaluationsSnapshot(filters) {
            requestedFilters = filters;
            return new Promise((resolve, reject) => { resolveFetch = resolve; rejectFetch = reject; });
        },
    },
    // HR bootstrap leaves all these large datasets empty.
    buildHrEvaluationContext: () => ({ evaluations: [], professorUsers: [], offerings: [],
        professorIdSet: new Set(), professorNameMap: {}, professorEmployeeIdMap: {}, offeringsById: {} }),
    getHrProfessorStudentTotals: () => { throw new Error('Discrepancy must not require a complete overall SET.'); },
    collectHrQualitativeResponses: () => [],
    getSemesterLabel: id => id,
    clampNumber: (number, min, max) => Math.max(min, Math.min(max, number)),
    renderHrResponsiveTablePrompt: (body, _columns, message) => { body.innerHTML = message; },
    renderHrResponsiveTableCards: (body, _columns, html) => { body.innerHTML = html; },
    isHrPhoneViewport: () => false,
    escapeHrHtml: value => String(value),
};
vm.createContext(sandbox);
vm.runInContext([
    'let hrDiscrepancyAnalysisRequestId = 0;',
    between('function normalizeHrToken(', '\nfunction getHrQuestionnaireTypeCode('),
    between('function isHrEvaluationInSemester(', '\nfunction buildHrEvaluationContext('),
    between('function resolveHrProfessorIdToken(', '\nfunction buildHrQuestionSectionLookup('),
    between('function mergeHrProfessorUsersIntoContext(', '\nfunction mergeHrAnalyticsDirectoryIntoContext('),
    between('function aggregateHrEvaluationData(', '\nfunction isHrValidSubmittedStudentEvaluation('),
    between('function countHrEvaluationsByType(', '\nfunction runHrWithGlobalLoading('),
    between('function resetAiInsightsDiscrepancyPrompt(', '\nfunction resetAiInsightsCredibilityPrompt('),
    between('function analyzeCrossSourceDiscrepancies(', '\nfunction medianFromValues('),
].join('\n'), sandbox);

function survey(id, type, rating, professor = 'u10', semester = 'current') {
    return { id, evaluatorRole: type, evaluationType: type, status: 'submitted', semesterId: semester,
        targetProfessorId: professor, targetProfessor: `Professor ${professor}`, courseOfferingId: type === 'student' ? id : '',
        ratings: { 1: rating, 2: rating }, credibilityStatus: 'AUTO_ACCEPTED' };
}
async function run(rows) {
    const pending = sandbox.runAiDiscrepancyCheck();
    resolveFetch(rows);
    assert.equal(await pending, true);
}

(async () => {
    const pending = sandbox.runAiDiscrepancyCheck();
    assert.equal(elements['hr-run-discrepancy-analysis-btn'].disabled, true);
    assert.match(elements['hr-ai-discrepancy-body'].innerHTML, /Loading/);
    assert.equal(requestedFilters.semesterId, 'current');
    assert.equal(requestedFilters.analyticsEligible, true);
    assert.equal(requestedFilters.includeRatings, true);
    assert.equal(requestedFilters.limit, 0);
    const actualPattern = [survey('s1', 'student', 2.94), survey('s2', 'student', 3), survey('sup', 'supervisor', 3)];
    resolveFetch(actualPattern);
    await pending;
    assert.match(elements['hr-ai-discrepancy-summary'].textContent, /Reviewed: 1 \| Flagged: 0/);
    assert.match(elements['hr-ai-discrepancy-body'].innerHTML, /<td>2\.97<\/td>/);
    assert.match(elements['hr-ai-discrepancy-body'].innerHTML, /<td>3\.00<\/td>/);
    assert.match(elements['hr-ai-discrepancy-body'].innerHTML, /No discrepancy \(below threshold\)/);
    assert.equal(elements['hr-run-discrepancy-analysis-btn'].disabled, false);
    assert.equal(elements['hr-run-discrepancy-analysis-btn'].innerHTML, 'Run Discrepancy Check');

    await run([survey('s', 'student', 2), survey('sup', 'supervisor', 4)]);
    assert.match(elements['hr-ai-discrepancy-summary'].textContent, /Reviewed: 1 \| Flagged: 1/);
    assert.match(elements['hr-ai-discrepancy-body'].innerHTML, /Medium/);
    await run([survey('s', 'student', 1), survey('sup', 'supervisor', 5)]);
    assert.match(elements['hr-ai-discrepancy-body'].innerHTML, /High/);

    await run([survey('s', 'student', 3)]);
    assert.match(elements['hr-ai-discrepancy-feedback'].textContent, /No supervisor evaluations/);
    await run([survey('sup', 'supervisor', 4)]);
    assert.match(elements['hr-ai-discrepancy-feedback'].textContent, /No student evaluations/);
    await run([survey('s', 'student', 1), survey('sup', 'supervisor', 5, 'u20')]);
    assert.match(elements['hr-ai-discrepancy-feedback'].textContent, /no professor has both sources/);
    assert.match(elements['hr-ai-discrepancy-body'].innerHTML, /No comparable/);

    elements['hr-ai-discrepancy-semester'].value = 'all';
    await run([survey('s', 'student', 1), survey('sup', 'supervisor', 5, 'u10', 'previous')]);
    assert.match(elements['hr-ai-discrepancy-summary'].textContent, /Reviewed: 0/);
    await run([survey('s', 'student', 1), survey('sup', 'supervisor', 4),
        survey('s-prev', 'student', 4, 'u10', 'previous'), survey('sup-prev', 'supervisor', 4, 'u10', 'previous')]);
    assert.match(elements['hr-ai-discrepancy-summary'].textContent, /Reviewed: 2 \| Flagged: 1/);
    assert.match(elements['hr-ai-discrepancy-body'].innerHTML, /Professor u10 \(current\)/);

    const stale = sandbox.runAiDiscrepancyCheck();
    elements['hr-ai-discrepancy-semester'].value = 'previous';
    sandbox.resetAiInsightsDiscrepancyPrompt();
    resolveFetch(actualPattern);
    assert.equal(await stale, false);
    assert.doesNotMatch(elements['hr-ai-discrepancy-body'].innerHTML, /Professor u10/);
    const failed = sandbox.runAiDiscrepancyCheck();
    rejectFetch(new Error('Database unavailable'));
    assert.equal(await failed, false);
    assert.match(elements['hr-ai-discrepancy-feedback'].textContent, /Unable to load evaluations/);
    assert.match(elements['hr-ai-discrepancy-body'].innerHTML, /could not be loaded/);

    if (process.argv.includes('--live')) {
        // Read the same authorized list used by the browser; no production writes.
        const php = spawnSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', [], {
            cwd: path.join(__dirname, '..'), encoding: 'utf8', input: `<?php
require 'api/db.php'; require 'api/state_helpers.php';
$hr = array_values(array_filter(buildUsersSnapshot($pdo), fn($u) => $u['role'] === 'hr'))[0];
$semester = getCurrentSemesterSnapshot($pdo);
$rows = listEvaluationsSnapshotPage($pdo, ['semesterId'=>$semester, 'analyticsEligible'=>true, 'includeTextResponses'=>false], $hr)['evaluations'];
echo json_encode(['semester'=>$semester, 'evaluations'=>$rows]);
` });
        assert.equal(php.status, 0, php.stderr);
        const live = JSON.parse(php.stdout);
        elements['hr-ai-discrepancy-semester'].value = live.semester;
        await run(live.evaluations);
        console.log('Live HR discrepancy result:', elements['hr-ai-discrepancy-summary'].textContent);
        const ctx = sandbox.mergeHrProfessorUsersIntoContext(sandbox.buildHrEvaluationContext(), live.evaluations.map(e => ({ id:e.targetProfessorId, name:e.targetProfessor })));
        ctx.evaluations = live.evaluations;
        const result = sandbox.analyzeCrossSourceDiscrepancies(ctx, live.semester, 2);
        console.log(JSON.stringify(result.reviewedRows.map(r => ({ professorId:r.professorId, studentAvg:r.studentAvg, supervisorAvg:r.supervisorAvg, difference:r.supervisorDiff }))));
        assert.ok(result.reviewedCount > 0, 'Expected a comparison from the current submitted sources.');
    }
    console.log('HR discrepancy loading tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
