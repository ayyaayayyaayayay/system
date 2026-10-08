'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../JsScrip/profesorpanel.js'), 'utf8');
const start = source.indexOf('function refreshProfessorReportAccessState()');
const end = source.indexOf('\nfunction setReportsNavVisibility(', start);
assert.ok(start >= 0 && end > start);

function createPanel(initialAccess, serverAccess) {
    const savedEvaluations = [{ id: 'saved-response', ratings: { q1: 5 } }];
    const state = {
        access: initialAccess,
        serverAccess,
        evaluations: [],
        displayedEvaluations: [],
        fetches: 0,
        rebuilds: 0,
        locked: true,
    };
    const context = {
        professorReportAccessVerified: false,
        professorReportAccessRefreshPromise: null,
        console,
        getProfessorFacultyReportAccessState: () => state.access,
        clearRestrictedProfessorReportData: () => { state.displayedEvaluations = []; },
        applyReportBlackout: () => {
            state.locked = !context.professorReportAccessVerified
                || state.access.enabled === false || state.access.evaluationPeriodsComplete === false;
            if (state.locked) context.clearRestrictedProfessorReportData();
        },
        refreshProfessorPanelData: options => {
            assert.equal(options.preserveSelection, true);
            state.rebuilds++;
            state.displayedEvaluations = state.evaluations.slice();
            context.applyReportBlackout();
        },
        SharedData: {
            refreshFacultyReportAccess: async () => {
                state.access = state.serverAccess;
                // SharedData dispatches access/count changes before resolving.
                context.applyReportBlackout();
                return state.access;
            },
            refreshEvaluations: async () => {
                assert.equal(context.professorReportAccessVerified, true);
                state.fetches++;
                state.evaluations = savedEvaluations.slice();
            },
        },
    };
    vm.createContext(context);
    vm.runInContext(source.slice(start, end), context);
    return { context, state };
}

async function run() {
    const enabled = { enabled: true, evaluationPeriodsComplete: true };
    // Already closed at login: bootstrap and server both say enabled, but
    // the pending verification has cleared the report's initial results.
    const panel = createPanel(enabled, enabled);
    const first = panel.context.refreshProfessorReportAccessState();
    assert.equal(panel.context.refreshProfessorReportAccessState(), first, 'Concurrent checks must share the pending request.');
    await first;
    assert.equal(panel.state.fetches, 1, 'First verification must fetch results even without an access transition.');
    assert.equal(panel.state.locked, false);
    assert.equal(panel.state.displayedEvaluations[0].id, 'saved-response', 'Unlocked reports must restore saved results.');
    assert.equal(panel.context.professorReportAccessRefreshPromise, null);
    await panel.context.refreshProfessorReportAccessState();
    assert.equal(panel.state.fetches, 1, 'An unchanged verified access check should reuse loaded evaluations.');

    const closing = createPanel({ enabled: true, evaluationPeriodsComplete: false }, enabled);
    await closing.context.refreshProfessorReportAccessState();
    assert.equal(closing.state.displayedEvaluations.length, 1, 'Closing the evaluation period must restore results.');

    for (const denied of [
        { enabled: false, evaluationPeriodsComplete: true },
        { enabled: true, evaluationPeriodsComplete: false },
    ]) {
        panel.state.serverAccess = denied;
        await panel.context.refreshProfessorReportAccessState();
        assert.equal(panel.state.locked, true);
        assert.equal(panel.state.displayedEvaluations.length, 0, 'Restricted results must remain cleared.');
        panel.state.serverAccess = enabled;
        await panel.context.refreshProfessorReportAccessState();
        assert.equal(panel.state.displayedEvaluations.length, 1, 'Re-enabled reports must reload results.');
    }
    console.log('Professor report unlock regression tests passed.');
}

run().catch(error => { console.error(error); process.exitCode = 1; });
