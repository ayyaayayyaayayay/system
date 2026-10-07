'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

let assertions = 0;
function check(actual, expected, message) { assertions++; assert.deepEqual(actual, expected, message); }
for (const panel of ['hr', 'vpaa']) {
    const source = fs.readFileSync(path.join(__dirname, '../JsScrip', panel + 'panel.js'), 'utf8');
    const prefix = panel === 'hr' ? '' : 'Vpaa';
    const names = panel === 'hr'
        ? ['normalizeHistoricalTrendScore', 'formatHistoricalTrendScore', 'resolveHistoricalTrendSourceAverage', 'formatHistoricalTrendSignedPercent', 'formatHistoricalTrendDelta', 'computeHistoricalTrendSummary', 'buildProfessorHistoricalTrend']
        : ['normalizeVpaaHistoricalTrendScore', 'formatVpaaHistoricalTrendScore', 'resolveVpaaHistoricalTrendSourceAverage', 'formatVpaaHistoricalTrendSignedPercent', 'formatVpaaHistoricalTrendDelta', 'computeVpaaHistoricalTrendSummary', 'buildVpaaProfessorHistoricalTrend'];
    let records = [];
    const context = {
        normalizeHrUserIdToken: x => x, normalizeVpaaProfessorUserId: x => x,
        getHrLatestSemestersForTrend: () => records.map((_, i) => ({ id: String(i), label: 'Term ' + i })),
        getVpaaLatestSemestersForTrend: () => records.map((_, i) => ({ id: String(i), label: 'Term ' + i })),
        getHrProfessorStudentTotals: (_, __, term) => ({ evaluatedPairs: records[Number(term)].student == null ? 0 : 1, averageRating: records[Number(term)].student }),
        getVpaaProfessorStudentTotals: (_, __, term) => ({ evaluatedPairs: records[Number(term)].student == null ? 0 : 1, averageRating: records[Number(term)].student }),
    };
    const aggregate = ({ typeKey, semesterId }) => {
        const value = records[Number(semesterId)][typeKey === 'professor' ? 'peer' : typeKey];
        return { totalEvaluations: value == null ? 0 : 1, averageRating: value };
    };
    context.aggregateHrEvaluationData = aggregate;
    context.aggregateVpaaEvaluationData = aggregate;
    vm.createContext(context);
    for (const name of names) {
        const start = source.indexOf('function ' + name + '(');
        assert(start >= 0, 'Missing function: ' + name);
        const end = source.indexOf('\nfunction ', start + 1);
        vm.runInContext(source.slice(start, end < 0 ? source.length : end), context);
    }
    const build = context[panel === 'hr' ? 'buildProfessorHistoricalTrend' : 'buildVpaaProfessorHistoricalTrend'];
    const summarize = context['compute' + prefix + 'HistoricalTrendSummary'];
    const formatScore = context['format' + prefix + 'HistoricalTrendScore'];
    const formatPercent = context['format' + prefix + 'HistoricalTrendSignedPercent'];
    const formatDelta = context['format' + prefix + 'HistoricalTrendDelta'];
    const plain = x => JSON.parse(JSON.stringify(x));
    for (const [student, peer, supervisor, expected] of [
        [4, null, null, 4], [null, 3, null, 3], [null, null, 5, 5],
        [4, 3, null, 3.67], [4, null, 5, 4.33], [null, 3, 5, 4], [4, 3, 5, 4],
        [null, null, null, null],
    ]) {
        records = [{ student, peer, supervisor }];
        check(build('u42', { evaluations: [] }).points[0].score, expected, panel + ': missing-source weighting changed.');
    }
    records = [{ student: '4', peer: '', supervisor: undefined }];
    check(build('u42', { evaluations: [] }).points[0].score, 4, panel + ': blank values became zero.');
    records = [{ student: Infinity, peer: NaN, supervisor: null }];
    check(build('u42', { evaluations: [] }).points[0].score, null, panel + ': non-finite scores became an available result.');
    records = [{ student: 4 }, {}, { student: 3 }, { student: 2 }];
    const trend = build('u42', { evaluations: [] });
    check(plain(trend.points.map(p => p.delta)), [null, null, null, -1], panel + ': a missing semester fabricated an adjacent delta.');
    check(trend.summary.declinePatternDetected, false, panel + ': decline detection crossed a missing semester.');
    check(trend.summary.semesterCount, 3, panel + ': missing semester counted as a score.');
    check(trend.summary.percentChange, -50, panel + ': overall change did not use actual first and last scores.');
    check(summarize([{ score: null }, { score: null }]).hasSufficientData, false, panel + ': missing history was classified as stable.');
    check(summarize([{ score: null }, { score: 4 }]).hasSufficientData, false, panel + ': one actual score is not enough for a trend.');
    check(summarize([{ score: 5 }, { score: 4 }, { score: 3 }]).declinePatternDetected, true, panel + ': real consecutive declines were lost.');
    check(summarize([{ score: 4 }, { score: 4 }]).isConsistent, true, panel + ': valid stable history changed.');
    for (const missing of [null, undefined, '']) {
        check(formatScore(missing), 'N/A', panel + ': missing score displayed as zero.');
        check(formatPercent(missing), 'N/A', panel + ': unavailable change displayed as 0%.');
        check(formatDelta(missing), '-', panel + ': unavailable delta displayed as zero.');
    }
    check(formatScore(4), '4.00', panel + ': actual score formatting changed.');
    check(formatPercent(0), '+0.0%', panel + ': real zero change became unavailable.');
    check(formatDelta(0), '+0.00', panel + ': real zero delta became unavailable.');
    check(plain(trend.points[0].availableSources), ['Student'], panel + ': the source coverage was lost.');
}
console.log(`Historical trend missing-score tests passed (${assertions} assertions across HR and VPAA).`);
