'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.resolve(__dirname, '../JsScrip/db-data.js'), 'utf8');
function between(start, end) {
    const first = source.indexOf(start);
    const last = source.indexOf(end, first);
    assert.ok(first >= 0 && last > first);
    return source.slice(first, last);
}
const changes = [];
const writes = [];
const state = {
    currentSemester: 'old-term', semesterList: [{ value: 'old-term', label: 'Old Term' }],
    users: [
        { id: 'u1', role: 'admin', status: 'active' },
        { id: 'u2', role: 'hr', status: 'active' },
        { id: 'u3', role: 'student', status: 'active', isActive: true },
        { id: 'u4', role: 'professor', status: 'active', isActive: true },
    ],
};
let requestFails = false;
const context = {
    state, usersLastSyncedAt: 100,
    KEYS: { CURRENT_SEMESTER: 'semester', USERS: 'users', SEMESTER_LIST: 'semesters' },
    startBootstrap: () => {}, deepClone: value => JSON.parse(JSON.stringify(value)),
    writeLocalFallbackJSON: (key, value) => writes.push({ key, value }),
    dispatchChange: (key, value) => changes.push({ key, value }),
    markBootstrapDatasetPartial: () => {},
    updateCachedUserRecord: user => Object.assign(state.users.find(item => item.id === user.id), user),
    removeCachedUserRecord: () => { throw new Error('Deactivation must retain the account.'); },
    syncRequest: (_, action, body) => {
        if (requestFails) throw new Error('Server rejected the save.');
        return { success: true, changed: body.value !== state.currentSemester, currentSemester: body.value, deactivatedCount: 2 };
    },
};
vm.createContext(context);
vm.runInContext([
    between('    function setCurrentSemester(', '    function getQuestionnaires('),
    between('    function addSemester(', '    function buildActorPayload('),
    between('    function applyUsersResponse(', '    function buildUsersPageResult('),
].join('\n'), context);
requestFails = true;
assert.throws(() => context.setCurrentSemester('new-term'), /rejected/);
assert.equal(state.currentSemester, 'old-term');
assert.equal(state.users[2].status, 'active');
assert.equal(changes.length, 0);
assert.equal(writes.length, 0);
assert.throws(() => context.addSemester('new-term', 'New Term'), /rejected/);
assert.equal(state.semesterList.length, 1, 'Failed additions must not create a local-only semester.');
requestFails = false;
context.addSemester('new-term', 'New Term');
assert.equal(state.semesterList.length, 2);
const result = context.setCurrentSemester('new-term');
assert.equal(result.deactivatedCount, 2);
assert.equal(state.currentSemester, 'new-term');
assert.equal(state.users[0].status, 'active');
assert.equal(state.users[1].status, 'active');
assert.equal(state.users[2].status, 'inactive');
assert.equal(state.users[3].isActive, false);
state.users[2].status = 'active';
state.users[2].isActive = true;
context.setCurrentSemester('new-term');
assert.equal(state.users[2].status, 'active', 'Saving the same semester must preserve manual reactivation.');
context.applyUsersResponse({ softDeleted: true, deactivatedUserId: 'u3', deletedUserId: 'u3' });
assert.equal(state.users.length, 4);
assert.equal(state.users[2].status, 'inactive');
console.log('Semester account activation client tests passed.');
