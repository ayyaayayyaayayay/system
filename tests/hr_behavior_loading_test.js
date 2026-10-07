'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '..', 'JsScrip', 'hrpanel.js'), 'utf8');
function between(start, end) {
    const from = source.indexOf(start);
    const to = source.indexOf(end, from + start.length);
    assert.ok(from >= 0 && to > from);
    return source.slice(from, to);
}

const elements = Object.fromEntries([
    'hr-ai-behavior-semester', 'hr-ai-behavior-feedback',
    'hr-ai-student-aggregate-body', 'hr-ai-submission-body',
    'hr-run-behavior-analysis-btn',
].map(id => [id, { value: '1st-semester-2026-2027', textContent: '', innerHTML: '', disabled: false }]));
elements['hr-run-behavior-analysis-btn'].innerHTML = 'Run Behavior Analysis';
let resolveFetch;
let rejectFetch;
let requestedFilters;
let printCount = 0;
const sandbox = {
    console: { error() {} },
    document: { getElementById: id => elements[id] || null },
    SharedData: {
        fetchHrBehaviorAnalysis(filters) {
            requestedFilters = filters;
            return new Promise((resolve, reject) => { resolveFetch = resolve; rejectFetch = reject; });
        },
    },
    // Matches the empty/partial HR bootstrap, regardless of existing DB surveys.
    buildHrEvaluationContext: () => ({ evaluations: [] }),
    renderHrResponsiveTablePrompt: (body, _columns, message) => { body.innerHTML = message; },
    renderHrResponsiveTableCards: (body, _columns, html) => { body.innerHTML = html; },
    isHrPhoneViewport: () => false,
    escapeHrHtml: value => String(value),
    getBehaviorRiskLevel: score => score < 70 ? 'High' : 'Low',
    runAiBiasDetection: async () => {},
    runAiDiscrepancyCheck: async () => true,
    runAiCredibilityAnalysis: async () => true,
    printHrAiInsightsSummary: () => { printCount++; },
    alert: () => assert.fail('Unexpected print error'),
};
vm.createContext(sandbox);
vm.runInContext([
    'let hrBehaviorAnalysisHasRun = false; let hrBehaviorAnalysisSnapshot = null; let hrBehaviorAnalysisRequestId = 0;',
    between('function normalizeHrToken(', '\nfunction getHrQuestionnaireTypeCode('),
    between('function isHrEvaluationInSemester(', '\nfunction buildHrEvaluationContext('),
    between('function resetAiInsightsBehaviorAnalysisPrompt(', '\nfunction cleanHrAiInsightsReportText('),
    between('function buildHrPersistedBehaviorAnalysis(', '\nfunction resolveAiInsightsStudentNumber('),
    between('async function runAllAiInsightsThenPrint(', '\nfunction populateAiInsightsSemesterFilters('),
    'this.hasRun = () => hrBehaviorAnalysisHasRun;',
].join('\n'), sandbox);

const surveys = [65, 64].map((behaviorScore, index) => ({
    id: `db-eval-${index + 3}`,
    evaluatorRole: 'student',
    semesterId: '1st-semester-2026-2027',
    evaluatorUserId: 'u10',
    behaviorScore,
    behaviorRepetition: { ratingRepetitiveFlag: false, commentRepetitiveFlag: true, repetitiveFlag: true },
    credibilityComponents: { behaviorDetails: { flags: ['Rapid completion / low seconds per question'] } },
}));

(async () => {
    const pending = sandbox.runAiBehaviorAnalysis();
    assert.equal(elements['hr-run-behavior-analysis-btn'].disabled, true);
    assert.equal(sandbox.hasRun(), false, 'Do not report an empty cache as completed analysis.');
    assert.match(elements['hr-ai-behavior-feedback'].textContent, /Loading/);
    assert.equal(requestedFilters.semesterId, '1st-semester-2026-2027');
    assert.equal(requestedFilters.evaluationType, 'student-to-professor');
    assert.equal(requestedFilters.limit, 0, 'Do not truncate the semester to a single page.');
    resolveFetch(surveys);
    assert.equal(await pending, true);
    assert.equal(elements['hr-run-behavior-analysis-btn'].disabled, false);
    assert.equal(elements['hr-run-behavior-analysis-btn'].innerHTML, 'Run Behavior Analysis');
    assert.match(elements['hr-ai-behavior-feedback'].textContent, /Saved behavior scores: 2/);
    assert.match(elements['hr-ai-submission-body'].innerHTML, /db-eval-3/);
    assert.match(elements['hr-ai-submission-body'].innerHTML, /db-eval-4/);
    assert.match(elements['hr-ai-submission-body'].innerHTML, /<td>65<\/td>/);
    assert.match(elements['hr-ai-submission-body'].innerHTML, /<td>64<\/td>/);
    assert.match(elements['hr-ai-submission-body'].innerHTML, /Yes \(comments\)/);
    assert.doesNotMatch(elements['hr-ai-submission-body'].innerHTML, /Yes \(ratings/);
    assert.match(elements['hr-ai-student-aggregate-body'].innerHTML, /<td>2<\/td>/);
    sandbox.renderAiInsightsBehaviorAnalysis({ silent: true });
    assert.match(elements['hr-ai-submission-body'].innerHTML, /db-eval-4/, 'Rerender must retain the fetched snapshot.');

    const stale = sandbox.runAiBehaviorAnalysis();
    elements['hr-ai-behavior-semester'].value = 'previous-semester';
    sandbox.resetAiInsightsBehaviorAnalysisPrompt();
    resolveFetch(surveys);
    assert.equal(await stale, false);
    assert.doesNotMatch(elements['hr-ai-submission-body'].innerHTML, /db-eval-/);

    const failed = sandbox.runAiBehaviorAnalysis();
    rejectFetch(new Error('Unavailable'));
    assert.equal(await failed, false);
    assert.match(elements['hr-ai-behavior-feedback'].textContent, /Unable to load/);
    assert.match(elements['hr-ai-submission-body'].innerHTML, /could not be loaded/);

    const historical = sandbox.runAiBehaviorAnalysis();
    resolveFetch([{ ...surveys[0], semesterId: 'previous-semester', behaviorScore: null }]);
    await historical;
    assert.match(elements['hr-ai-behavior-feedback'].textContent, /Saved behavior scores: 0\. Unavailable: 1/);
    assert.match(elements['hr-ai-submission-body'].innerHTML, /N\/A/);

    const empty = sandbox.runAiBehaviorAnalysis();
    resolveFetch([]);
    await empty;
    assert.match(elements['hr-ai-submission-body'].innerHTML, /No submission analysis/);

    elements['hr-ai-behavior-semester'].value = '1st-semester-2026-2027';
    const printing = sandbox.runAllAiInsightsThenPrint();
    assert.equal(printCount, 0, 'Printing must await behavior data.');
    resolveFetch(surveys);
    await printing;
    assert.equal(printCount, 1);
    assert.match(elements['hr-ai-submission-body'].innerHTML, /db-eval-4/);
    sandbox.runAiDiscrepancyCheck = async () => false;
    const failedDiscrepancyPrint = sandbox.runAllAiInsightsThenPrint();
    resolveFetch(surveys);
    await failedDiscrepancyPrint;
    assert.equal(printCount, 1, 'Do not print when discrepancy loading fails.');
    sandbox.runAiDiscrepancyCheck = async () => true;
    sandbox.runAiCredibilityAnalysis = async () => false;
    const failedCredibilityPrint = sandbox.runAllAiInsightsThenPrint();
    resolveFetch(surveys);
    await failedCredibilityPrint;
    assert.equal(printCount, 1, 'Do not print when credibility loading fails.');
    const html = fs.readFileSync(path.join(__dirname, '..', 'html', 'hrpanel.html'), 'utf8');
    const aggregate = html.slice(html.indexOf('<h3>Student Aggregate Scores</h3>'), html.indexOf('<h3>Submission-Level Analysis</h3>'));
    assert.match(aggregate, /<th>Anonymous Student Group<\/th>/);
    assert.doesNotMatch(aggregate, /Student Number/);
    console.log('HR behavior loading tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
