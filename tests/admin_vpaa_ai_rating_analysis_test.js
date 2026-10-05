'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');

function sourceBetween(source, startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    assert.notEqual(start, -1, `Missing source marker: ${startMarker}`);
    const end = source.indexOf(endMarker, start + startMarker.length);
    assert.notEqual(end, -1, `Missing source marker: ${endMarker}`);
    return source.slice(start, end);
}

function createCommonContext(prefix) {
    const context = {
        snapshots: {},
        [`${prefix}_AI_WORD_FREQUENCY_STOP_WORDS`]: new Set(['the', 'and', 'with', 'was', 'were', 'this', 'that']),
        getSemesterLabel(value) {
            return String(value);
        },
    };
    return context;
}

function snapshot(averageRating, evaluatedCount, totalRaters, comments = []) {
    return {
        averageRating,
        evaluatedCount,
        totalRaters,
        qualitativeResponses: comments.map((text, index) => ({ text, date: `2026-09-${index + 1}` })),
    };
}

const adminSource = fs.readFileSync(path.join(root, 'JsScrip', 'adminpanel.js'), 'utf8');
const adminContext = createCommonContext('ADMIN');
Object.assign(adminContext, {
    sanitizeAdminAiAnalyticsText(value, maxLength) {
        return String(value == null ? '' : value).trim().slice(0, maxLength);
    },
    normalizeAdminAiAnalyticsSourceLabel(value) {
        const token = String(value || '').toLowerCase();
        if (token.includes('student')) return 'Student to Professor';
        if (token.includes('peer') || token.includes('professor')) return 'Professor to Professor';
        if (token.includes('supervisor')) return 'Supervisor to Professor';
        return 'General';
    },
    normalizeAdminAiAnalyticsTone(value) {
        const token = String(value || '').toLowerCase();
        return token === 'positive' || token === 'negative' ? token : 'neutral';
    },
    normalizeAdminAiAnalyticsJudgmentLabel(value) {
        const token = String(value || '').toLowerCase();
        if (token === 'excellent') return 'Excellent';
        if (token === 'good') return 'Good';
        if (token.includes('critical')) return 'Critical Concern';
        return 'Needs Improvement';
    },
});
adminContext.getEvaluationSnapshotForType = function (_professor, _semesterId, type) {
    return adminContext.snapshots[type];
};
vm.createContext(adminContext);
vm.runInContext([
    sourceBetween(adminSource, 'function normalizeAdminAiCommentTokens(', '\nfunction buildAdminProfessorAiAnalyticsPayload('),
    sourceBetween(adminSource, 'function buildAdminProfessorAiAnalyticsPayload(', '\nfunction normalizeAdminAiInsightData('),
    'this.hooks = { buildAdminProfessorAiAnalyticsPayload, hasAdminAiAnalyticsEvidence, buildAdminLocalAiExplainabilityInsight };'
].join('\n'), adminContext);

adminContext.snapshots = {
    student: snapshot(4.5, 20, 25),
    peer: snapshot(3, 4, 5),
    supervisor: snapshot(5, 1, 1),
};
const adminPayload = adminContext.hooks.buildAdminProfessorAiAnalyticsPayload({ id: 'u7', name: 'Admin Test' }, '1st-2026');
assert.equal(adminPayload.metrics.combinedAverage, 4.25, 'Admin must use 50/25/25 source weights.');

adminContext.snapshots = {
    student: snapshot(null, 0, 25),
    peer: snapshot(3, 4, 5),
    supervisor: snapshot(5, 1, 1),
};
const adminMissingSource = adminContext.hooks.buildAdminProfessorAiAnalyticsPayload({ id: 'u7', name: 'Admin Test' }, '1st-2026');
assert.equal(adminMissingSource.metrics.combinedAverage, 4, 'Admin must renormalize missing source weights.');

const adminRatingOnly = adminContext.hooks.buildAdminLocalAiExplainabilityInsight({
    comments: [],
    metrics: {
        combinedAverage: 4.5,
        averagesBySource: { student: 4.5, professor: null, supervisor: null },
        responseRate: 80,
        totalEvaluations: 10,
        countsBySource: {},
    },
});
assert.match(adminRatingOnly.ratingReview, /quantitative-only/i);
assert.ok(adminRatingOnly.judgment.confidence <= 75);
const adminConflict = adminContext.hooks.buildAdminLocalAiExplainabilityInsight({
    comments: [{ text: 'bad poor unclear confusing', source: 'Student to Professor' }],
    metrics: {
        combinedAverage: 4.8,
        averagesBySource: { student: 4.8, professor: 4.8, supervisor: 4.8 },
        responseRate: 90,
        totalEvaluations: 20,
        countsBySource: { student: 1, professor: 0, supervisor: 0 },
    },
});
assert.match(adminConflict.ratingReview, /differ significantly/i);
assert.equal(adminContext.hooks.hasAdminAiAnalyticsEvidence({ comments: [], metrics: { combinedAverage: 4.5 } }), true);
assert.equal(adminContext.hooks.hasAdminAiAnalyticsEvidence({ comments: [], metrics: { combinedAverage: null } }), false);
assert.ok(adminSource.includes('Professor Rating Review'));
assert.ok(adminSource.includes('escapeAdminAnalyticsHtml(ratingReview)'));

const vpaaSource = fs.readFileSync(path.join(root, 'JsScrip', 'vpaapanel.js'), 'utf8');
const vpaaContext = {
    sanitizeAiAnalyticsText(value, maxLength) {
        return String(value == null ? '' : value).trim().slice(0, maxLength);
    },
    normalizeAiAnalyticsSourceLabel(value) {
        const token = String(value || '').toLowerCase();
        if (token.includes('student')) return 'Student to Professor';
        if (token.includes('peer') || token.includes('professor')) return 'Professor to Professor';
        if (token.includes('supervisor')) return 'Supervisor to Professor';
        return 'General';
    },
    normalizeAiAnalyticsTone(value) {
        const token = String(value || '').toLowerCase();
        return token === 'positive' || token === 'negative' ? token : 'neutral';
    },
    normalizeAiAnalyticsJudgmentLabel(value) {
        const token = String(value || '').toLowerCase();
        if (token === 'excellent') return 'Excellent';
        if (token === 'good') return 'Good';
        if (token.includes('critical')) return 'Critical Concern';
        return 'Needs Improvement';
    },
    buildCombinedCommentEntries(professor) {
        return Array.isArray(professor && professor.comments) ? professor.comments : [];
    },
    computeTopWordFrequency(texts, limit) {
        const counts = new Map();
        texts.forEach((text) => String(text).toLowerCase().split(/\s+/).forEach((term) => {
            if (term) counts.set(term, (counts.get(term) || 0) + 1);
        }));
        return Array.from(counts, ([label, count]) => ({ label, count })).sort((a, b) => b.count - a.count).slice(0, limit);
    },
};
vm.createContext(vpaaContext);
vm.runInContext([
    sourceBetween(vpaaSource, 'function computeAiAverageFromDistribution(', '\nfunction normalizeAiInsightData('),
    'this.hooks = { buildProfessorAiAnalyticsMetrics, hasAiAnalyticsEvidence, buildLocalAiExplainabilityInsight };'
].join('\n'), vpaaContext);

const vpaaProfessor = {
    overall: 4.1,
    responseRate: 80,
    evaluations: 25,
    analyticsByType: {
        student: { ratingDistribution: { 4: 1, 5: 1 } },
        professor: { ratingDistribution: { 3: 1 } },
        supervisor: { ratingDistribution: { 5: 1 } },
    },
};
const vpaaMetrics = vpaaContext.hooks.buildProfessorAiAnalyticsMetrics(vpaaProfessor);
assert.equal(vpaaMetrics.combinedAverage, 4.25, 'VPAA must use 50/25/25 source weights.');

vpaaProfessor.analyticsByType.student.ratingDistribution = {};
const vpaaMissingSource = vpaaContext.hooks.buildProfessorAiAnalyticsMetrics(vpaaProfessor);
assert.equal(vpaaMissingSource.combinedAverage, 4, 'VPAA must renormalize missing source weights.');

const vpaaRatingOnly = vpaaContext.hooks.buildLocalAiExplainabilityInsight({
    comments: [],
    metrics: {
        combinedAverage: 4.5,
        averagesBySource: { student: 4.5, professor: null, supervisor: null },
        responseRate: 80,
        totalEvaluations: 10,
        countsBySource: {},
    },
});
assert.match(vpaaRatingOnly.ratingReview, /quantitative-only/i);
assert.ok(vpaaRatingOnly.judgment.confidence <= 75);
const vpaaCommentsOnly = vpaaContext.hooks.buildLocalAiExplainabilityInsight({
    comments: [{ text: 'excellent clear helpful', source: 'Student to Professor' }],
    metrics: { combinedAverage: null, averagesBySource: {}, countsBySource: { student: 1 } },
});
assert.match(vpaaCommentsOnly.ratingReview, /No valid numeric rating/i);
assert.ok(vpaaCommentsOnly.judgment.confidence <= 70);
assert.equal(vpaaContext.hooks.hasAiAnalyticsEvidence({ comments: [], metrics: { combinedAverage: 4.5 } }), true);
assert.equal(vpaaContext.hooks.hasAiAnalyticsEvidence({ comments: [], metrics: { combinedAverage: null } }), false);
assert.ok(vpaaSource.includes('Professor Rating Review'));
assert.ok(vpaaSource.includes('escapeHtml(ratingReview)'));

const adminHtml = fs.readFileSync(path.join(root, 'html', 'adminpanel.html'), 'utf8');
const vpaaHtml = fs.readFileSync(path.join(root, 'html', 'vpaapanel.html'), 'utf8');
assert.ok(adminHtml.includes('adminpanel.css?v=20261004a'));
assert.ok(adminHtml.includes('db-data.js?v=20261004a'));
assert.ok(adminHtml.includes('adminpanel.js?v=20261003a'));
assert.ok(vpaaHtml.includes('vpaapanel.css?v=20260930c'));
assert.ok(vpaaHtml.includes('db-data.js?v=20261006a'));
assert.ok(vpaaHtml.includes('vpaapanel.js?v=20261006b'));

console.log('Admin and VPAA AI rating analysis tests passed.');
