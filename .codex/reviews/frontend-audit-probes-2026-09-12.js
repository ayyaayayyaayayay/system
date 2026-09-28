// Read-only audit probes. Synthetic browser/network only; no server or database access.
// Run: node .codex/reviews/frontend-audit-probes-2026-09-12.js
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const root = path.resolve(__dirname, '../..');
const sharedSource = fs.readFileSync(path.join(root, 'JsScrip/db-data.js'), 'utf8')
    .split('const SharedData = (() => {')[1];

function createHarness(failBootstrap = false) {
    const memory = new Map([['userSession', JSON.stringify({
        role: 'admin', username: 'synthetic', userId: 'u1',
        isAuthenticated: true, csrfToken: 'synthetic'
    })]]);
    const listeners = {};
    const requests = [];
    let bootstrapCalls = 0;
    const storage = {
        getItem: key => memory.get(key) ?? null,
        setItem: (key, value) => memory.set(key, value),
        removeItem: key => memory.delete(key)
    };
    const win = {
        localStorage: storage, sessionStorage: storage,
        addEventListener: (type, callback) => (listeners[type] ??= []).push(callback),
        dispatchEvent: event => (listeners[event.type] ?? []).forEach(callback => callback(event)),
        setInterval: () => 1, clearInterval: () => {},
        location: { href: 'http://localhost/system/html/adminpanel.html' }
    };
    const context = {
        window: win, document: { addEventListener: () => {} },
        console: { warn: () => {}, error: () => {} },
        Date, Intl, URL, URLSearchParams, setTimeout, clearTimeout,
        CustomEvent: function (type, options) { this.type = type; this.detail = options.detail; },
        XMLHttpRequest: class {
            open() {}
            setRequestHeader() {}
            send() { this.status = 500; this.responseText = '{"error":"synthetic failure"}'; }
        },
        fetch: (url, options) => {
            if (url.includes('action=bootstrap')) {
                bootstrapCalls++;
                return Promise.resolve({
                    status: failBootstrap ? 503 : 200,
                    text: () => Promise.resolve(JSON.stringify(failBootstrap
                        ? { error: 'synthetic outage' }
                        : { success: true, state: { currentSemester: 'original', users: [], evalPeriods: {}, bootstrapMeta: {} } }))
                });
            }
            return new Promise(resolve => requests.push({ url, options, resolve }));
        }
    };
    vm.createContext(context);
    vm.runInContext('const SharedData = (() => {' + sharedSource + ';globalThis.S = SharedData;', context);
    return { shared: context.S, requests, win, bootstrapCalls: () => bootstrapCalls };
}

async function sharedDataProbes() {
    const harness = createHarness();
    const shared = harness.shared;
    await shared.refreshBootstrap(false);
    let threw = false;
    try {
        shared.setEvalPeriods({ 'student-professor': { start: '2030-01-01', end: '2030-02-01' } });
    } catch (_error) { threw = true; }
    console.log(JSON.stringify({
        probe: 'failed-setting-write', threw,
        localValue: shared.getEvalPeriods()['student-professor']
    }));

    let callbackValue = '';
    shared.onDataChange((key, value) => { if (key === shared.KEYS.CURRENT_SEMESTER) callbackValue = value; });
    harness.win.dispatchEvent({ type: 'storage', key: shared.KEYS.CURRENT_SEMESTER, newValue: JSON.stringify('new-semester') });
    console.log(JSON.stringify({
        probe: 'cross-tab-setting', callbackValue, getterValue: shared.getCurrentSemester()
    }));

    const older = shared.refreshUsers({ search: 'older', limit: 1 });
    const newer = shared.refreshUsers({ search: 'newer', limit: 1 });
    const respond = (index, id) => harness.requests[index].resolve({
        status: 200,
        text: () => Promise.resolve(JSON.stringify({ users: [{ id }], total: 1, limit: 1, offset: 0, page: 1, hasMore: false }))
    });
    respond(1, 'newer-result');
    await newer;
    respond(0, 'older-result');
    await older;
    console.log(JSON.stringify({
        probe: 'out-of-order-user-query', lastRequested: 'newer-result', cached: shared.getCachedUsers()[0].id
    }));

    const failed = createHarness(true);
    const first = await failed.shared.refreshBootstrap(false);
    const second = await failed.shared.refreshBootstrap(false);
    console.log(JSON.stringify({
        probe: 'bootstrap-retry-after-outage', first, second, requests: failed.bootstrapCalls()
    }));
}

async function studentSubmissionProbe() {
    const source = fs.readFileSync(path.join(root, 'JsScrip/studentpanel.js'), 'utf8');
    const start = source.indexOf('function handleFormSubmission()');
    const end = source.indexOf('\nfunction collectFormData()', start);
    let selected = 'offering-A';
    let cleared = '';
    let resets = 0;
    const timers = [];
    const button = { textContent: 'Submit', disabled: false };
    const form = { querySelector: () => button, checkValidity: () => true, reset: () => resets++ };
    const context = {
        console, setTimeout: (callback, delay) => timers.push({ callback, delay }),
        SharedData: { isEvalPeriodOpen: () => true },
        document: { getElementById: id => id === 'evaluationForm' ? form : id === 'evaluation-target-value' ? { value: 'synthetic-target' } : null },
        enforceActiveStudentAccount: () => true, hasCurrentStudentPrivacyConsent: () => true,
        buildCurrentStudentIdentity: () => ({ primaryStudentId: 'synthetic', primarySemesterId: 'synthetic-semester' }),
        getUserSession: () => ({}),
        getSelectedTargetParts: () => ({ professorName: 'synthetic', subjectCode: 'synthetic' }),
        getSelectedCourseOfferingId: () => selected, validateSelectedEvaluationTarget: () => ({ valid: true }),
        isSubmittedEvaluation: () => false, removeAutosaveTimer: () => {}, enableAllSectionInputs: () => {},
        applyExceptionReportingRequirements: () => true,
        collectFormData: () => ({ courseOfferingId: selected }),
        submitEvaluation: () => Promise.resolve({ success: true }), showSuccessMessage: () => {},
        clearCurrentEvaluationDraft: () => { cleared = selected; return Promise.resolve(); },
        clearSelectedEvaluationTarget: () => { selected = ''; },
        updateEvaluationTargetIndicator: () => {}, loadHistory: () => {}, switchView: () => {},
        updateNavigation: () => {}, refreshEvaluationStatuses: () => {}, updateSummaryCards: () => {},
        updateEvaluationAvailabilityUi: () => {}
    };
    vm.createContext(context);
    vm.runInContext(source.slice(start, end), context);
    context.handleFormSubmission();
    timers.shift().callback();
    await Promise.resolve();
    selected = 'offering-B';
    await timers.shift().callback();
    console.log(JSON.stringify({
        probe: 'student-switches-target-while-submission-finishes',
        submitted: 'offering-A', draftCleared: cleared, currentFormReset: resets
    }));
}

(async () => {
    await sharedDataProbes();
    await studentSubmissionProbe();
})().catch(error => { console.error(error); process.exitCode = 1; });
