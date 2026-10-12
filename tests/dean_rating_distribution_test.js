'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const SetCalculation = require('../JsScrip/set-calculation.js');

const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8').replace(/\r\n/g, '\n');
const panel = read('JsScrip/daenpanel.js');
const shared = read('JsScrip/db-data.js');

function extract(name) {
    const start = panel.indexOf(`function ${name}(`);
    assert(start >= 0, `Missing ${name}`);
    const next = /\n(?:async )?function /.exec(panel.slice(start + 1));
    assert(next, `Missing boundary for ${name}`);
    return panel.slice(start, start + 1 + next.index);
}

const canvas = {};
class Chart {
    static getChart(target) { return target.chart; }
    constructor(target, config) {
        this.config = config;
        this.options = config.options;
        this.data = config.data;
        target.chart = this;
    }
    update(mode) { this.updateMode = mode; }
}
let summary;
const sandbox = {
    window: { SetCalculation }, Chart,
    document: { getElementById: id => id === 'studentPieChart' ? canvas : null },
    getDeanSummaryForType: () => summary,
    buildDeanQuestionMeta: () => ({ categoryOrder: ['Section A'], categoryByQuestionId: { q1: 'Section A', q2: 'Section A' } }),
    resolveEvaluationTypeToken: row => row.evaluatorRole,
    isEvaluationInSemester: (row, semester) => row.semesterId === semester,
    resolveDeanTargetProfessorId: row => row.evaluateeUserId,
    collectEvaluationComments: () => [],
};
vm.createContext(sandbox);
const chartStart = shared.indexOf('window.AppChartDesign =');
const chartEnd = shared.indexOf('\n})();', chartStart);
assert(chartStart >= 0 && chartEnd > chartStart);
vm.runInContext(shared.slice(chartStart, chartEnd + '\n})();'.length), sandbox);
vm.runInContext([
    'computeAverageRatingFromEvaluations', 'computeDistributionAverage', 'formatDisplaySection',
    'isSemesterTokenMatch', 'createCategoryStatBucket', 'buildDeanEvaluationAggregates', 'initializeStudentCharts',
].map(extract).join('\n'), sandbox);

const context = {
    scopedProfessors: [{ id: 'u500' }], professorById: { u500: { id: 'u500' } },
    offerings: [101, 102].map(id => ({
        id, professorUserId: 'u500', subjectCode: `TEST${id}`, subjectName: 'Test',
        sectionName: '1/1', semesterSlug: 'term1', isActive: true,
    })),
    enrollments: [101, 102].flatMap(courseOfferingId => [1, 2].map(id => ({
        courseOfferingId, studentUserId: `u${id}`, status: 'enrolled',
    }))),
    evaluations: [4.25, 4.75].map((score, index) => ({
        id: index + 1, evaluatorRole: 'student', evaluatorUserId: `u${index + 1}`,
        evaluateeUserId: 'u500', courseOfferingId: 101, semesterId: 'term1',
        status: 'submitted', ratings: { q1: score, q2: score },
    })),
};

// Missing section responses keep overall SET unavailable, but the chart shows submitted ratings.
summary = sandbox.buildDeanEvaluationAggregates(context, 'student', 'term1');
assert.equal(summary.averageRating, null);
assert.equal(summary.ratingDistributionAverage, 4.5);
sandbox.initializeStudentCharts();
const center = () => canvas.chart.options.plugins.appRatingDistributionCenterText;
assert.equal(center().title, '4.50');
assert.equal(center().subtitle, '2 ratings');
assert.equal(center().muted, false);

// Empty semester clears the previous average; new data updates the existing chart.
summary = sandbox.buildDeanEvaluationAggregates(context, 'student', 'term2');
sandbox.initializeStudentCharts();
assert.equal(center().title, 'No data');
assert.equal(center().subtitle, '0 ratings');
assert.equal(center().muted, true);
assert.equal(canvas.chart.updateMode, 'none');

summary = sandbox.buildDeanEvaluationAggregates({
    ...context, offerings: context.offerings.slice(0, 1), enrollments: context.enrollments.slice(0, 2),
}, 'student', 'term1');
assert.equal(summary.averageRating, 4.5);
sandbox.initializeStudentCharts();
assert.equal(center().title, '4.50');
assert.equal(center().subtitle, '2 ratings');

console.log('Dean rating distribution regression tests passed.');
