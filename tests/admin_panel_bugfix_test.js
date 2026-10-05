'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const panelSource = fs.readFileSync(path.join(root, 'JsScrip', 'adminpanel.js'), 'utf8');
const sharedDataSource = fs.readFileSync(path.join(root, 'JsScrip', 'db-data.js'), 'utf8');
const html = fs.readFileSync(path.join(root, 'html', 'adminpanel.html'), 'utf8');

function sourceBetween(source, startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    assert.notEqual(start, -1, `Missing source marker: ${startMarker}`);
    const end = source.indexOf(endMarker, start + startMarker.length);
    assert.notEqual(end, -1, `Missing source marker: ${endMarker}`);
    return source.slice(start, end);
}

const initializationSource = sourceBetween(
    panelSource,
    'function initializeAdminPanel()',
    'function renderProfessorDepartmentOptions()'
);
assert.doesNotMatch(initializationSource, /setupEvalPeriodSaving/, 'The obsolete evaluation-period binding must not run.');
assert.equal(
    (panelSource.match(/getElementById\('save-eval-periods-btn'\)/g) || []).length,
    1,
    'Evaluation periods must have exactly one Save-button binding.'
);

const evalSource = sourceBetween(panelSource, 'function setupEvalPeriods()', 'function setupSemesterSettings()');
const saveHandlers = [];
const savedPayloads = [];
const alerts = [];
const values = {
    'student-professor-start': '',
    'student-professor-end': '',
    'professor-professor-start': '',
    'professor-professor-end': '',
    'supervisor-professor-start': '',
    'supervisor-professor-end': '',
};
const elements = Object.fromEntries(Object.entries(values).map(([id, value]) => [id, { value }]));
elements['save-eval-periods-btn'] = {
    addEventListener(type, handler) {
        if (type === 'click') saveHandlers.push(handler);
    },
};
const context = {
    document: { getElementById: id => elements[id] || null },
    SharedData: {
        getEvalPeriods: () => ({
            'student-professor': { start: '2026-10-01', end: '2026-10-10' },
            'professor-professor': { start: '2026-10-02', end: '2026-10-11' },
            'supervisor-professor': { start: '2026-10-03', end: '2026-10-12' },
        }),
        setEvalPeriods: periods => savedPayloads.push(periods),
    },
    alert: message => alerts.push(message),
};
vm.createContext(context);
vm.runInContext(`${evalSource}\nthis.setupEvalPeriods = setupEvalPeriods;`, context);
context.setupEvalPeriods();
assert.equal(saveHandlers.length, 1);
saveHandlers[0]();
assert.equal(savedPayloads.length, 1);
assert.equal(alerts.length, 1);
assert.deepEqual(
    JSON.parse(JSON.stringify(savedPayloads[0]['supervisor-professor'])),
    { start: '2026-10-03', end: '2026-10-12' },
    'Supervisor evaluation dates must use the real supervisor-professor controls.'
);

const quickActionSource = sourceBetween(panelSource, 'function handleQuickAction(', 'function switchToView(');
assert.match(quickActionSource, /case 'manage-campus':[\s\S]*openCampusManagerModal\(\)/);
assert.doesNotMatch(quickActionSource, /Campus management coming soon/);
assert.ok(html.includes('id="manage-campuses-modal"'));

const campusSelectSource = sourceBetween(panelSource, 'function refreshCampusSelects(', 'function renderCampusList(');
assert.ok(campusSelectSource.includes("'general-main-campus'"), 'Main Campus must use the persisted campus list.');
const generalSettingsSource = sourceBetween(panelSource, 'function setupGeneralSettings()', 'function setupSecuritySettings()');
assert.ok(generalSettingsSource.includes('SharedData.KEYS.CAMPUSES'));
assert.ok(generalSettingsSource.includes('SharedData.KEYS.SETTINGS'));
assert.ok(generalSettingsSource.includes('campusInput.appendChild(option)'));

const setCampusesSource = sourceBetween(sharedDataSource, 'function setCampuses(', 'function upsertProgram(');
assert.ok(setCampusesSource.indexOf("syncRequest('POST', 'setCampuses'") < setCampusesSource.indexOf('state.campuses ='));
assert.ok(setCampusesSource.includes('return state.campuses'));
assert.doesNotMatch(setCampusesSource, /Failed to persist campuses/);

assert.ok(html.includes('db-data.js?v=20261004a'));
assert.ok(html.includes('adminpanel.js?v=20261003a'));

console.log('Admin panel bug-fix regression tests passed.');
