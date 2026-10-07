'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const read = file => fs.readFileSync(path.join(__dirname, '..', 'JsScrip', file), 'utf8');
function between(source, start, end) {
    const first = source.indexOf(start);
    const last = source.indexOf(end, first + start.length);
    assert.ok(first >= 0 && last > first, `Missing function: ${start}`);
    return source.slice(first, last);
}

for (const [file, name, toggle, stateName, stepsKey, prefix] of [
    ['studentpanel.js', 'goToSectionStep', 'toggleStepInputs', 'evaluationSectionFlow', 'sections', 'student'],
    ['profesorpanel.js', 'goToPeerStep', 'togglePeerStepInputs', 'peerSectionFlow', 'steps', 'peer'],
    ['daenpanel.js', 'goToSupervisorStep', 'toggleSupervisorStepInputs', 'supervisorSectionFlow', 'steps', 'supervisor'],
]) {
    let scrolls = 0;
    const steps = Array.from({ length: 3 }, () => ({
        active: false, enabled: false,
        classList: { toggle(_name, active) { this.owner.active = active; } },
    }));
    steps.forEach(step => { step.classList.owner = step; });
    const elements = Object.fromEntries(['prev-btn', 'next-btn', 'progress-fill', 'progress-meta']
        .map(id => [`${prefix}-${id}`, { style: {} }]));
    const submit = { style: {} };
    const context = {
        [stateName]: { [stepsKey]: steps, activeIndex: 0 },
        [toggle]: (step, enabled) => { step.enabled = enabled; },
        document: { getElementById: id => elements[id], querySelector: () => submit },
        window: { scrollTo: (x, y) => { assert.equal(x, 0); assert.equal(y, 0); scrolls++; } },
    };
    vm.createContext(context);
    vm.runInContext(between(read(file), `function ${name}(`, `function ${toggle}(`), context);
    context[name](0);
    assert.equal(scrolls, 0, 'Initialization must not interrupt scrolling.');
    context[name](1);
    assert.equal(scrolls, 1);
    assert.equal(steps[1].active, true);
    assert.equal(steps[1].enabled, true);
    assert.equal(steps[0].enabled, false);
    context[name](2);
    assert.equal(submit.style.display, 'inline-flex');
    context[name](1);
    assert.equal(scrolls, 3, 'Previous section must also return to the top.');
    assert.equal(submit.style.display, 'none');
    context[name](1);
    assert.equal(scrolls, 3, 'Selecting the same section must not move the page.');
}

async function testSubmission(file, supervisor) {
    const submit = { disabled: false, textContent: 'Submit evaluation' };
    const form = {
        dataset: {}, resets: 0,
        querySelector: () => submit,
        querySelectorAll: () => [],
        checkValidity: () => true,
        reset() { this.resets++; },
    };
    const target = { value: 'faculty-2' };
    const dashboard = {};
    let resolveSave;
    let rejectSave;
    let requests = 0;
    let timingClears = 0;
    const views = [];
    const messages = [];
    const pending = () => new Promise((resolve, reject) => { resolveSave = resolve; rejectSave = reject; });
    let save = pending();
    const context = {
        document: {
            getElementById: id => ({ peerEvaluationForm: form, peerProfessor: target, dashboardView: dashboard, evaluationType: {} })[id],
            addEventListener() {},
        },
        FormData: class {
            entries() { return [['peerProfessor', target.value], ['1', '5']][Symbol.iterator](); }
            get(key) { return key === 'peerProfessor' ? target.value : ''; }
        },
        SharedData: {
            isEvalPeriodOpen: () => true,
            getQuestionnaires: () => ({ semester: {
                'professor-to-professor': { questions: [{ id: 1, type: 'rating' }] },
                'supervisor-to-professor': { questions: [{ id: 1, type: 'rating' }] },
            } }),
            getCurrentSemester: () => 'semester',
            getSession: () => ({ username: 'evaluator', fullName: 'Evaluator' }),
            getNowIsoString: () => '2026-10-07T01:00:00.000Z',
            buildEvaluationTiming: () => ({ durationSeconds: 0.2 }),
            addEvaluationAsync(payload) {
                requests++;
                assert.equal(payload.ratings['1'], '5');
                assert.equal(payload.behaviorMeta.durationSeconds, 0.2);
                return save;
            },
            clearEvaluationTiming: () => { timingClears++; },
        },
        professorPanelState: { context: { professor: { id: 'faculty-1', name: 'Evaluator' } } },
        SUPERVISOR_ROLE: 'dean',
        enforceActiveProfessorAccount: () => true,
        enforceActiveDeanAccount: () => true,
        resolveActiveProfessorAccount: context => ({ linked: true, context }),
        isPeerTargetLocked: () => false,
        isSupervisorTargetLocked: () => false,
        enableAllPeerStepInputs() {}, enableAllSupervisorStepInputs() {},
        getUserSession: () => ({}),
        getPeerSemesterId: () => 'semester', getSupervisorSemesterId: () => 'semester',
        ensureQuestionnairePrivacyConsentForSubmission: () => true,
        buildPeerEvaluationKey: () => 'peer-key', buildSupervisorEvaluationKey: () => 'supervisor-key',
        getSupervisorAnonymousLabel: () => 'Supervisor',
        refreshPeerTargetLockState: () => { submit.disabled = false; },
        refreshSupervisorTargetLockState: () => { submit.disabled = false; },
        populatePeerProfessorOptions() {}, syncSupervisorTargetFromInput() {},
        switchView: view => views.push(view), updateNavigation() {},
        showFormMessage: (element, message, tone) => messages.push({ element, message, tone }),
        window: { scrollTo() {} },
        setTimeout() { throw new Error('Submission must not introduce a timer delay.'); },
    };
    vm.createContext(context);
    vm.runInContext(read('evaluation-text-limits.js'), context);
    context.EvaluationTextLimits = context.window.EvaluationTextLimits;
    vm.runInContext(between(read(file), 'async function handlePeerEvaluation(', '\n}\n') + '\n}', context);
    let task = context.handlePeerEvaluation();
    assert.equal(submit.disabled, true);
    assert.equal(submit.textContent, 'Submitting...');
    assert.equal(requests, 1);
    assert.equal(form.resets, 0);
    assert.equal(views.length, 0);
    await context.handlePeerEvaluation();
    assert.equal(requests, 1, 'Repeated clicks must not create a second request.');
    rejectSave(new Error('Connection failed'));
    await task;
    assert.equal(form.resets, 0, 'Failure must preserve answers.');
    assert.equal(timingClears, 0, 'Failure must preserve the original timing.');
    assert.equal(submit.disabled, false);
    assert.equal(submit.textContent, 'Submit evaluation');
    assert.equal(form.dataset.submitting, undefined);
    assert.equal(messages.at(-1).tone, 'error');

    save = pending();
    task = context.handlePeerEvaluation();
    assert.equal(requests, 2, 'The user must be able to retry a failed request.');
    resolveSave({ id: 'saved-evaluation' });
    await task;
    assert.equal(form.resets, 1);
    assert.equal(timingClears, 1);
    assert.equal(views.at(-1), supervisor ? 'peerEvaluation' : 'dashboard');
    assert.equal(messages.at(-1).tone, 'success');
    assert.equal(messages.at(-1).element, supervisor ? form : dashboard);
    assert.equal(submit.disabled, false);
    assert.equal(submit.textContent, 'Submit evaluation');
    assert.equal(form.dataset.submitting, undefined);
}

(async () => {
    await testSubmission('profesorpanel.js', false);
    await testSubmission('daenpanel.js', true);
    console.log('Evaluation navigation, immediate async saves, duplicate prevention, and failure/retry tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
