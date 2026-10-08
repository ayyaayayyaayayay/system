'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../JsScrip/profesorpanel.js'), 'utf8');
const start = source.indexOf('function resolveEvaluationWindowGateState()');
const end = source.indexOf('function clearRestrictedProfessorReportData()', start);
let today = '2026-10-08';
let periods;
let access = { enabled: true, evaluationPeriodsComplete: true };
const context = {
    professorReportAccessVerified: true,
    parseDateBoundary: (raw, boundary) => {
        const date = new Date(`${raw}T${boundary === 'end' ? '23:59:59' : '00:00:00'}+08:00`);
        return Number.isNaN(date.getTime()) ? null : date;
    },
    SharedData: {
        getEvalPeriodDates: type => periods[type],
        getCurrentPhilippineDateYmd: () => today,
        getFacultyReportAccess: () => access,
    },
};
vm.createContext(context);
vm.runInContext(source.slice(start, end), context);
const reset = () => {
    periods = Object.fromEntries(['student-professor', 'professor-professor', 'supervisor-professor']
        .map(type => [type, { start: '2026-10-01', end: '2026-10-07' }]));
};
reset();
assert.equal(context.resolveReportsGateState().locked, false, 'All periods closed should release results.');
for (const type of Object.keys(periods)) {
    reset();
    periods[type].end = '2026-10-10';
    assert.equal(context.resolveReportsGateState().locked, true, `${type} still open must block all reports.`);
    assert.equal(context.resolveFacultyPaperGateState().locked, true, 'Faculty Paper must not expose early scores.');
    assert.equal(context.resolveReportsGateState().endDate, '2026-10-10', 'Release must use the latest end date.');
}
today = '2026-10-10';
assert.equal(context.resolveReportsGateState().locked, true, 'Results must remain locked throughout the final date.');
today = '2026-10-11';
assert.equal(context.resolveReportsGateState().locked, false, 'Results release the day after the final period.');
today = '2026-10-08';
for (const invalid of [{}, { start: '', end: '' },
    { start: 'invalid', end: '2026-10-07' }, { start: '2026-02-30', end: '2026-10-07' }]) {
    reset();
    periods['supervisor-professor'] = invalid;
    assert.equal(context.resolveReportsGateState().locked, true, 'Missing/invalid schedules must fail closed.');
    assert.equal(context.resolveReportsGateState().endDate, '', 'An incomplete schedule has no confirmed release date.');
}
periods = {
    'student-professor': { start: '2026-10-06', end: '2026-10-06' },
    'professor-professor': { start: '2026-10-08', end: '2026-08-31' },
    'supervisor-professor': { start: '2026-10-06', end: '2026-10-05' },
};
assert.equal(context.resolveReportsGateState().locked, false, 'The closed periods shown in the dashboard must release reports even with reversed start dates.');
assert.equal(context.resolveFacultyPaperGateState().locked, false, 'The same closed periods must release Faculty Paper.');
assert.equal(context.resolveReportsGateState().endDate, '2026-10-06');
periods['supervisor-professor'].end = '2026-10-09';
assert.equal(context.resolveReportsGateState().locked, true, 'A future close date must still block results.');
reset();
access.evaluationPeriodsComplete = false;
assert.equal(context.resolveReportsGateState().locked, true, 'A server lock must override stale local dates.');
access.evaluationPeriodsComplete = true;
access.enabled = false;
assert.equal(context.resolveReportsGateState().locked, true, 'The dean restriction must remain effective.');
assert.equal(context.resolveFacultyPaperGateState().locked, false, 'Faculty Paper timing remains independent of the dean report toggle.');
access.enabled = true;
context.professorReportAccessVerified = false;
assert.equal(context.resolveReportsGateState().locked, true, 'Unverified access must fail closed.');
console.log('Professor results release UI tests passed.');
