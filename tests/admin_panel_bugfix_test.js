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

assert.match(html, /db-data\.js\?[^"']*idle=20261007b/);
assert.ok(html.includes('adminpanel.js?v=20261006cmo'));

const userFilterSource = sourceBetween(panelSource, 'function setSelectOptions(', 'function getUserSearchTerm()');
const filterElements = Object.fromEntries([
    'campus-filter-select', 'user-role-filter', 'user-department-filter', 'user-status-filter', 'user-program-filter',
].map(id => [id, {
    value: '',
    options: [],
    handlers: {},
    set innerHTML(markup) {
        this.options = Array.from(markup.matchAll(/<option value="([^"]*)">([^<]*)<\/option>/g), match => ({
            value: match[1], label: match[2],
        }));
        this.value = this.options[0]?.value || '';
    },
    addEventListener(type, handler) { this.handlers[type] = handler; },
}]));
filterElements['campus-filter-select'].value = 'all';
const organizationData = { campuses: [], departments: [], programs: [] };
const filterDataListeners = [];
const userFilterLoads = [];
const userFilterContext = {
    adminUsersPage: 3,
    adminUsersPageSize: 'all',
    ADMIN_USERS_COMPLETE_CHUNK_SIZE: 500,
    document: { getElementById: id => filterElements[id] || null },
    escapeAttr: value => String(value),
    escapeHtml: value => String(value),
    normalizeProgramCode: value => String(value || '').trim().toUpperCase(),
    normalizeCampusCode: value => String(value || '').trim().toLowerCase(),
    normalizeDepartmentCode: value => String(value || '').trim().toUpperCase(),
    refreshCampusSelects: () => {},
    SharedData: {
        KEYS: { CAMPUSES: 'campuses', PROGRAMS: 'programs', USERS: 'users' },
        getAllDepartments: () => organizationData.departments,
        getCampuses: () => organizationData.campuses,
        getPrograms: () => organizationData.programs,
        onDataChange: listener => filterDataListeners.push(listener),
    },
    loadUsersByOrganization: campus => userFilterLoads.push(JSON.parse(JSON.stringify(
        userFilterContext.buildAdminUserFilters(campus)
    ))),
};
vm.createContext(userFilterContext);
vm.runInContext([
    sourceBetween(panelSource, 'function getAdminUserFilterValue(', 'function buildAdminUserFilterScope('),
    sourceBetween(panelSource, 'function setupCampusFilter()', 'function openCampusManagerModal()'),
    userFilterSource,
].join('\n'), userFilterContext);
userFilterContext.setupCampusFilter();
userFilterContext.setupUserManagementFilters();
assert.equal(filterElements['user-status-filter'].value, 'all', 'User Management must show inactive accounts by default.');
assert.equal(userFilterContext.buildAdminUserFilters().status, 'all');
filterElements['user-status-filter'].value = '';
assert.equal(userFilterContext.buildAdminUserFilters().status, 'all', 'Uninitialized status controls must include both statuses.');
filterElements['user-status-filter'].value = 'all';
assert.equal(filterElements['user-program-filter'].options.length, 1);
organizationData.departments = ['ICS', 'ILAS', 'INET'];
organizationData.campuses = [
    { id: 'villamor', departments: ['ICS', 'ILAS'] },
    { id: 'mactan', departments: ['INET', 'ICS'] },
];
organizationData.programs = [{
    programCode: 'BSIT', programName: 'Information Technology', campusSlug: 'villamor', departmentCode: 'ics',
}];
filterDataListeners.forEach(listener => listener('programs'));
filterDataListeners.forEach(listener => listener('campuses'));
assert.deepEqual(
    filterElements['user-department-filter'].options.map(option => option.value),
    ['all', 'ICS', 'ILAS', 'INET'],
    'Department options must refresh when asynchronous campus data arrives.'
);
assert.deepEqual(
    filterElements['user-program-filter'].options.map(option => option.value),
    ['all', 'BSIT'],
    'Program options must refresh when asynchronous program data arrives.'
);
filterElements['user-department-filter'].value = 'ICS';
filterElements['user-program-filter'].value = 'BSIT';
filterElements['user-status-filter'].value = 'inactive';
organizationData.programs.push({
    programCode: 'BSAIS', programName: 'Accounting Information Systems', campusSlug: 'villamor', departmentCode: 'ics',
});
filterDataListeners.forEach(listener => listener('programs'));
assert.equal(filterElements['user-program-filter'].options.length, 3, 'Saved programs must become available in the filter.');
assert.equal(filterElements['user-department-filter'].value, 'ICS');
assert.equal(filterElements['user-program-filter'].value, 'BSIT');
assert.equal(filterElements['user-status-filter'].value, 'inactive', 'Data refresh must preserve the selected filters.');
filterElements['user-program-filter'].handlers.change();
assert.equal(userFilterContext.adminUsersPage, 1);
assert.deepEqual(userFilterLoads, [{ limit: 500, page: 1, department: 'ICS', program: 'BSIT', status: 'inactive' }]);

organizationData.programs.push(
    { programCode: 'BSAVTOUR', campusSlug: 'villamor', departmentCode: 'ilas' },
    { programCode: 'BSAMT', campusSlug: 'mactan', departmentCode: 'inet' },
    { programCode: 'BSCS', campusSlug: 'mactan', departmentCode: 'ics' },
);
filterDataListeners.forEach(listener => listener('programs'));
assert.deepEqual(
    filterElements['user-program-filter'].options.map(option => option.value),
    ['all', 'BSIT', 'BSAIS', 'BSCS'],
    'ICS must exclude programs from ILAS and INET while allowing ICS across all campuses.'
);
filterElements['user-department-filter'].value = 'all';
filterElements['user-department-filter'].handlers.change();
filterElements['user-program-filter'].value = 'BSAVTOUR';
filterElements['user-department-filter'].value = 'ICS';
filterElements['user-department-filter'].handlers.change();
assert.equal(filterElements['user-program-filter'].value, 'all', 'Changing departments must clear an incompatible program.');
assert.equal(userFilterLoads.at(-1).department, 'ICS');
assert.equal(userFilterLoads.at(-1).program, undefined, 'User requests must omit the incompatible program.');
filterElements['user-program-filter'].value = 'BSAIS';
filterElements['user-program-filter'].handlers.change();
assert.equal(userFilterLoads.at(-1).program, 'BSAIS', 'The program filter must narrow the requested users.');

filterElements['campus-filter-select'].value = 'villamor';
filterElements['campus-filter-select'].handlers.change();
assert.deepEqual(filterElements['user-department-filter'].options.map(option => option.value), ['all', 'ICS', 'ILAS']);
assert.deepEqual(filterElements['user-program-filter'].options.map(option => option.value), ['all', 'BSIT', 'BSAIS']);
assert.equal(filterElements['user-program-filter'].value, 'BSAIS', 'A compatible program must survive a campus change.');
filterElements['user-department-filter'].value = 'ILAS';
filterElements['user-department-filter'].handlers.change();
filterElements['user-program-filter'].value = 'BSAVTOUR';
filterElements['campus-filter-select'].value = 'mactan';
filterElements['campus-filter-select'].handlers.change();
assert.deepEqual(filterElements['user-department-filter'].options.map(option => option.value), ['all', 'INET', 'ICS']);
assert.equal(filterElements['user-department-filter'].value, 'all');
assert.equal(filterElements['user-program-filter'].value, 'all');
assert.equal(userFilterLoads.at(-1).campus, 'mactan');
assert.equal(userFilterLoads.at(-1).department, undefined);
assert.equal(userFilterLoads.at(-1).program, undefined);

filterElements['campus-filter-select'].value = 'all';
filterElements['campus-filter-select'].handlers.change();
filterElements['user-department-filter'].value = 'ILAS';
filterElements['user-department-filter'].handlers.change();
assert.deepEqual(filterElements['user-program-filter'].options.map(option => option.value), ['all', 'BSAVTOUR']);
filterElements['user-department-filter'].value = 'all';
filterElements['user-department-filter'].handlers.change();
assert.equal(filterElements['user-program-filter'].options.length, 6, 'All Departments must restore all program choices.');

let displayedDepartments = [];
Object.assign(userFilterContext, {
    adminUsers: [],
    normalizeRoleCode: value => String(value || '').toLowerCase(),
    getUserSearchTerm: () => '',
    renderUserSearchResults: () => {},
    buildUserManagementSemesterActiveContext: () => ({}),
    getDepartmentsForCampus: campus => campus === 'all' ? organizationData.departments
        : organizationData.campuses.find(item => item.id === campus)?.departments || [],
    renderDepartmentSections: departments => { displayedDepartments = Array.from(departments); },
    slugifyDepartmentName: value => String(value).toLowerCase(),
    renderAdminUsersPagination: () => {},
});
vm.runInContext(sourceBetween(panelSource, 'function renderOrganizationView(', 'function renderUserSearchResults('), userFilterContext);
filterElements['user-department-filter'].value = 'ICS';
userFilterContext.renderOrganizationView('all');
assert.deepEqual(displayedDepartments, ['ICS'], 'The organization view must show only the selected department.');
filterElements['user-department-filter'].value = 'all';
userFilterContext.renderOrganizationView('all');
assert.deepEqual(displayedDepartments, ['ICS', 'ILAS', 'INET'], 'Clearing Department must restore all department groups.');
filterElements['hr-user-list'] = { innerHTML: '' };
Object.assign(userFilterContext, {
    adminUsers: [{ id: 'u2', name: 'Inactive HR', role: 'hr', campus: 'villamor', status: 'inactive' }],
    createUserCard: user => `${user.id}:${user.status}`,
    createEmptyState: message => message,
});
userFilterContext.renderOrganizationView('all');
assert.equal(filterElements['hr-user-list'].innerHTML, 'u2:inactive', 'Inactive accounts must still render in their organization group.');

console.log('Admin panel bug-fix regression tests passed.');
