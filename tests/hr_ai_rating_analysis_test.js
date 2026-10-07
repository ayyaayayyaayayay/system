'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'JsScrip', 'hrpanel.js'), 'utf8');
const html = fs.readFileSync(path.join(__dirname, '..', 'html', 'hrpanel.html'), 'utf8');

function sourceBetween(startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    assert.notEqual(start, -1, `Missing source marker: ${startMarker}`);
    const end = source.indexOf(endMarker, start + startMarker.length);
    assert.notEqual(end, -1, `Missing source marker: ${endMarker}`);
    return source.slice(start, end);
}

const context = {
    snapshots: {},
    HR_AI_WORD_FREQUENCY_STOP_WORDS: new Set(['the', 'and', 'with', 'was', 'were', 'this', 'that']),
    buildHrEvaluationContext() {
        return {};
    },
    getSemesterLabel(value) {
        return String(value);
    },
    sanitizeHrAiAnalyticsText(value, maxLength) {
        return String(value == null ? '' : value).trim().slice(0, maxLength);
    },
    normalizeHrAiAnalyticsSourceLabel(value) {
        const token = String(value || '').toLowerCase();
        if (token.includes('student')) return 'Student to Professor';
        if (token.includes('peer') || token.includes('professor')) return 'Professor to Professor';
        if (token.includes('supervisor')) return 'Supervisor to Professor';
        return 'General';
    },
    normalizeHrAiAnalyticsTone(value) {
        const token = String(value || '').toLowerCase();
        return token === 'positive' || token === 'negative' ? token : 'neutral';
    },
    normalizeHrAiAnalyticsJudgmentLabel(value) {
        const token = String(value || '').toLowerCase();
        if (token === 'excellent') return 'Excellent';
        if (token === 'good') return 'Good';
        if (token.includes('critical')) return 'Critical Concern';
        return 'Needs Improvement';
    },
};
context.getHrProfessorEvaluationSnapshot = function (_professorId, _semesterId, type) {
    return context.snapshots[type];
};

vm.createContext(context);
vm.runInContext([
    sourceBetween('function normalizeHrAiCommentTokens(', '\nfunction buildHrProfessorAiAnalyticsPayload('),
    sourceBetween('function buildHrProfessorAiAnalyticsPayload(', '\nfunction normalizeHrAiInsightData('),
    'this.hooks = { buildHrProfessorAiAnalyticsPayload, hasHrAiAnalyticsEvidence, buildHrLocalAiExplainabilityInsight };'
].join('\n'), context);

const { buildHrProfessorAiAnalyticsPayload, hasHrAiAnalyticsEvidence, buildHrLocalAiExplainabilityInsight } = context.hooks;

function snapshot(averageRating, evaluatedCount, totalRaters, comments = []) {
    return {
        averageRating,
        evaluatedCount,
        totalRaters,
        qualitativeResponses: comments.map((text, index) => ({ text, date: `2026-09-${index + 1}` })),
    };
}

context.snapshots = {
    student: snapshot(4.5, 20, 25, ['Excellent and clear teaching.']),
    peer: snapshot(3, 4, 5, ['Good peer support.']),
    supervisor: snapshot(5, 1, 1, ['Excellent professionalism.']),
};
const weightedPayload = buildHrProfessorAiAnalyticsPayload({ id: 'u42', name: 'Professor Test' }, '1st-2026', {});
assert.equal(weightedPayload.metrics.combinedAverage, 4.25, 'Combined rating must use 50/25/25 source weights.');
assert.equal(weightedPayload.metrics.averagesBySource.student, 4.5);
assert.equal(weightedPayload.metrics.averagesBySource.professor, 3);
assert.equal(weightedPayload.metrics.averagesBySource.supervisor, 5);

context.snapshots = {
    student: snapshot(null, 0, 25),
    peer: snapshot(3, 4, 5),
    supervisor: snapshot(5, 1, 1),
};
const missingSourcePayload = buildHrProfessorAiAnalyticsPayload({ id: 'u42', name: 'Professor Test' }, '1st-2026', {});
assert.equal(missingSourcePayload.metrics.combinedAverage, 4, 'Available weights must be renormalized when Student ratings are absent.');

const ratingOnly = buildHrLocalAiExplainabilityInsight({
    comments: [],
    metrics: {
        combinedAverage: 4.5,
        overallRating: 4.5,
        averagesBySource: { student: 4.5, professor: null, supervisor: null },
        responseRate: 80,
        totalEvaluations: 10,
        countsBySource: {},
    },
});
assert.equal(ratingOnly.keywords.length, 0);
assert.match(ratingOnly.ratingReview, /quantitative-only/i);
assert.ok(ratingOnly.judgment.confidence <= 75, 'Rating-only confidence must be capped.');
assert.equal(hasHrAiAnalyticsEvidence({ comments: [], metrics: { combinedAverage: 4.5 } }), true);

const highRatingNegativeComments = buildHrLocalAiExplainabilityInsight({
    comments: [
        { text: 'bad poor unclear confusing', source: 'Student to Professor' },
        { text: 'worst unfair boring', source: 'Professor to Professor' },
    ],
    metrics: {
        combinedAverage: 4.8,
        averagesBySource: { student: 4.8, professor: 4.8, supervisor: 4.8 },
        responseRate: 90,
        totalEvaluations: 20,
        countsBySource: { student: 1, professor: 1, supervisor: 0 },
    },
});
assert.match(highRatingNegativeComments.ratingReview, /differ significantly/i);
assert.match(highRatingNegativeComments.judgment.rationale, /differ significantly/i);

const lowRatingPositiveComments = buildHrLocalAiExplainabilityInsight({
    comments: [{ text: 'excellent clear helpful organized engaging', source: 'Student to Professor' }],
    metrics: {
        combinedAverage: 1.5,
        averagesBySource: { student: 1.5, professor: null, supervisor: null },
        responseRate: 60,
        totalEvaluations: 5,
        countsBySource: { student: 1, professor: 0, supervisor: 0 },
    },
});
assert.match(lowRatingPositiveComments.ratingReview, /differ significantly/i);

const commentsOnly = buildHrLocalAiExplainabilityInsight({
    comments: [{ text: 'excellent clear helpful', source: 'Student to Professor' }],
    metrics: { combinedAverage: null, averagesBySource: {}, countsBySource: { student: 1 } },
});
assert.match(commentsOnly.ratingReview, /No valid numeric rating/i);
assert.ok(commentsOnly.judgment.confidence <= 70, 'Comments-only confidence must be capped.');
assert.equal(hasHrAiAnalyticsEvidence({ comments: [], metrics: { combinedAverage: null, averagesBySource: {} } }), false);

const behaviorContext = {
    getHrEvaluationTypeKey(evaluation) {
        return String(evaluation && evaluation.evaluationType || '');
    },
    isHrEvaluationInSemester(evaluation, semesterId) {
        return semesterId === 'all' || String(evaluation && evaluation.semesterId || '') === semesterId;
    },
    normalizeHrToken(value) {
        return String(value || '').trim().toLowerCase();
    },
    resolveAiInsightsStudentNumber(evaluation) {
        return String(evaluation && evaluation.studentNumber || 'N/A');
    },
    extractBehaviorCommentPattern() {
        return { count: 0, uniqueCount: 0, fingerprint: '', tokens: [] };
    },
    buildBehaviorRepetitionTargetKey() {
        return '';
    },
    medianFromValues(values) {
        const sorted = values.slice().sort((a, b) => a - b);
        const middle = Math.floor(sorted.length / 2);
        return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
    },
    clampNumber(value, min, max) {
        return Math.min(max, Math.max(min, Number(value)));
    },
    getBehaviorRepetitionMinOverlap() {
        return 2;
    },
    computeBehaviorAnswerSimilarity() {
        return { overlapCount: 0, similarity: 0 };
    },
    computeBehaviorCommentSimilarity() {
        return { overlapCount: 0, similarity: 0, exactMatch: false };
    },
};
vm.createContext(behaviorContext);
vm.runInContext([
    sourceBetween('function analyzeEvaluationBehaviorRecords(', '\nfunction computeBehaviorAnswerSimilarity('),
    'this.analyze = analyzeEvaluationBehaviorRecords;'
].join('\n'), behaviorContext);

const behaviorAnalysis = behaviorContext.analyze({
    evaluations: [
        {
            id: 'fast-persisted',
            evaluationType: 'student',
            semesterId: '1st-2026',
            studentNumber: 'S-FAST',
            ratings: { 10: 5, 20: 4 },
            behaviorMeta: {
                captureVersion: 1,
                durationSeconds: 2,
                questionCount: 2,
                answeredCount: 2,
                secondsPerQuestion: 1,
            },
        },
        {
            id: 'normal-persisted',
            evaluationType: 'student',
            semesterId: '1st-2026',
            studentNumber: 'S-NORMAL',
            ratings: { 10: 4, 20: 5 },
            behaviorMeta: {
                captureVersion: 1,
                durationSeconds: 20,
                questionCount: 2,
                answeredCount: 2,
                secondsPerQuestion: 10,
            },
        },
        {
            id: 'legacy-no-timing',
            evaluationType: 'student',
            semesterId: '1st-2026',
            studentNumber: 'S-LEGACY',
            ratings: { 10: 3, 20: 4 },
        },
    ],
}, '1st-2026');
const fastRecord = behaviorAnalysis.records.find(record => record.submissionId === 'fast-persisted');
const normalRecord = behaviorAnalysis.records.find(record => record.submissionId === 'normal-persisted');
const legacyRecord = behaviorAnalysis.records.find(record => record.submissionId === 'legacy-no-timing');
assert.equal(behaviorAnalysis.timedRecordsCount, 2, 'Persisted timing metadata was not counted.');
assert.equal(behaviorAnalysis.legacyRecordsCount, 1, 'A historical record was not treated as timing-unavailable.');
assert.equal(fastRecord.fastFlag, true, 'Rapid persisted completion was not flagged.');
assert.equal(normalRecord.fastFlag, false, 'Normal persisted completion was incorrectly flagged.');
assert.equal(legacyRecord.timingAvailable, false, 'Missing historical timing was treated as available.');
assert.equal(legacyRecord.fastFlag, false, 'Missing historical timing was treated as suspiciously rapid.');

assert.ok(source.includes('Analyzing all eligible ratings and comments...'));
assert.ok(source.includes('Professor Rating Review'));
assert.ok(source.includes('escapeHrHtml(ratingReview)'), 'The model-generated rating review must be HTML-escaped.');
assert.match(html, /hrpanel\.css\?v=\d{8}[a-z0-9]*["']/);
assert.ok(html.includes('db-data.js?v=20261006c'), 'The shared data cache version was not updated.');
assert.ok(html.includes('hrpanel.js?v=20261007behavior'), 'The HR analytics script cache version was not updated.');

console.log('HR AI rating analysis tests passed.');
