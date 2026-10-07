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
    'hr-ai-credibility-semester', 'hr-ai-credibility-feedback',
    'hr-ai-credibility-summary', 'hr-ai-credibility-body',
    'hr-run-credibility-analysis-btn',
].map(id => [id, { value: 'current', textContent: '', innerHTML: '', disabled: false }]));
elements['hr-run-credibility-analysis-btn'].innerHTML = 'Run Credibility Analysis';
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
    buildHrEvaluationContext: () => ({ evaluations: [] }),
    normalizeHrToken: value => String(value || '').trim().toLowerCase(),
    renderHrResponsiveTablePrompt: (body, _columns, message) => { body.innerHTML = message; },
    renderHrResponsiveTableCards: (body, _columns, html) => { body.innerHTML = html; },
    isHrPhoneViewport: () => false,
    escapeHrHtml: value => String(value),
};
vm.createContext(sandbox);
vm.runInContext([
    'let hrCredibilityAnalysisRequestId = 0;',
    between('function isHrEvaluationInSemester(', '\nfunction buildHrEvaluationContext('),
    between('function buildHrPersistedCredibilityAnalysis(', '\nfunction buildHrPersistedBehaviorAnalysis('),
    between('function resetAiInsightsCredibilityPrompt(', '\nfunction analyzeEvaluationCredibility('),
    between('function getAiCredibilityCategory(', '\nfunction buildAiCredibilitySummary('),
    between('function formatAiCredibilityComponentValue(', '\nfunction analyzeCrossSourceDiscrepancies('),
].join('\n'), sandbox);
function survey(id, role, score, status = 'AUTO_ACCEPTED') {
    return { id, evaluatorRole: role, evaluationType: role, status: 'submitted', semesterId: 'current',
        targetProfessor: 'Professor Example', credibilityStatus: status, credibilityScore: score,
        behaviorScore: 65, credibilityComponents: { bias:80, crossStatus:'Comparator unavailable' },
        credibilityFlags: ['Saved flag'] };
}
async function run(rows) {
    const pending = sandbox.runAiCredibilityAnalysis();
    resolveFetch(rows);
    assert.equal(await pending, true);
}
(async () => {
    const rows = [survey('db-eval-3','student',69,'ACCEPTED_BY_HR'),
        survey('db-eval-4','student',69,'PENDING_HR_REVIEW'), survey('db-eval-5','supervisor',96)];
    const pending = sandbox.runAiCredibilityAnalysis();
    assert.equal(elements['hr-run-credibility-analysis-btn'].disabled, true);
    assert.match(elements['hr-ai-credibility-body'].innerHTML, /Loading saved/);
    assert.equal(requestedFilters.semesterId, 'current');
    assert.equal(requestedFilters.limit, 0);
    assert.equal(requestedFilters.analyticsEligible, undefined, 'Pending reviews must remain visible.');
    resolveFetch(rows);
    assert.equal(await pending, true);
    assert.match(elements['hr-ai-credibility-summary'].textContent, /Total: 3 \| Highly reliable: 1 \| Acceptable: 0 \| Needs review: 2 \| Avg score: 78\.0/);
    for (const row of rows) assert.ok(elements['hr-ai-credibility-body'].innerHTML.includes(row.id));
    assert.match(elements['hr-ai-credibility-body'].innerHTML, /<td>69<\/td>/);
    assert.match(elements['hr-ai-credibility-body'].innerHTML, /<td>96<\/td>/);
    assert.match(elements['hr-ai-credibility-body'].innerHTML, /Saved flag/);
    assert.equal(elements['hr-run-credibility-analysis-btn'].disabled, false);
    assert.equal(elements['hr-run-credibility-analysis-btn'].innerHTML, 'Run Credibility Analysis');
    assert.equal(rows[1].credibilityStatus, 'PENDING_HR_REVIEW');
    assert.equal(rows[1].credibilityScore, 69, 'Loading must preserve official saved results.');

    await run([...rows, { ...rows[0], id:'draft', status:'draft' }, { ...rows[0], id:'previous', semesterId:'previous' }]);
    assert.match(elements['hr-ai-credibility-summary'].textContent, /Total: 3/);
    assert.doesNotMatch(elements['hr-ai-credibility-body'].innerHTML, /<td>draft<\/td>|<td>previous<\/td>/);
    await run([survey('historical','student',null)]);
    assert.match(elements['hr-ai-credibility-body'].innerHTML, /<td>N\/A<\/td>/);
    assert.match(elements['hr-ai-credibility-body'].innerHTML, /Historical eligibility preserved/);
    await run([]);
    assert.match(elements['hr-ai-credibility-feedback'].textContent, /No submitted evaluations found/);
    assert.match(elements['hr-ai-credibility-body'].innerHTML, /No evaluations to analyze/);

    const stale = sandbox.runAiCredibilityAnalysis();
    elements['hr-ai-credibility-semester'].value = 'previous';
    sandbox.resetAiInsightsCredibilityPrompt();
    resolveFetch(rows);
    assert.equal(await stale, false);
    assert.doesNotMatch(elements['hr-ai-credibility-body'].innerHTML, /db-eval-/);
    const failed = sandbox.runAiCredibilityAnalysis();
    rejectFetch(new Error('Unavailable'));
    assert.equal(await failed, false);
    assert.match(elements['hr-ai-credibility-feedback'].textContent, /Unable to load credibility scores/);
    assert.match(elements['hr-ai-credibility-body'].innerHTML, /could not be loaded/);

    if (process.argv.includes('--live')) {
        const php = spawnSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe', [], {
            cwd: path.join(__dirname,'..'), encoding:'utf8', input:`<?php
require 'api/db.php'; require 'api/state_helpers.php';
$hr = array_values(array_filter(buildUsersSnapshot($pdo), fn($u) => $u['role'] === 'hr'))[0];
$semester = getCurrentSemesterSnapshot($pdo);
$rows = listEvaluationsSnapshotPage($pdo, ['semesterId'=>$semester, 'includeRatings'=>false, 'includeTextResponses'=>false], $hr)['evaluations'];
echo json_encode(['semester'=>$semester, 'evaluations'=>$rows]);
` });
        assert.equal(php.status,0,php.stderr);
        const live = JSON.parse(php.stdout);
        elements['hr-ai-credibility-semester'].value = live.semester;
        await run(live.evaluations);
        console.log('Live HR credibility result:', elements['hr-ai-credibility-summary'].textContent);
        console.log(JSON.stringify(live.evaluations.map(e => ({id:e.id,score:e.credibilityScore,status:e.credibilityStatus}))));
        assert.ok(live.evaluations.length > 0, 'Current submissions should be returned.');
        for (const row of live.evaluations) assert.ok(elements['hr-ai-credibility-body'].innerHTML.includes(row.id));
    }
    console.log('HR credibility loading tests passed.');
})().catch(error => { console.error(error); process.exitCode=1; });
