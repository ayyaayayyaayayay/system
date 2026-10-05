'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'JsScrip', 'db-data.js'), 'utf8');

function sourceBetween(startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    assert.notEqual(start, -1, `Missing source marker: ${startMarker}`);
    const end = source.indexOf(endMarker, start + startMarker.length);
    assert.notEqual(end, -1, `Missing source marker: ${endMarker}`);
    return source.slice(start, end);
}

const context = {
    ANNOUNCEMENT_ALLOWED_ROLES: [
        'admin', 'hr', 'dean', 'procoor', 'professor', 'vpaa', 'osa', 'student'
    ],
    getNowIsoString() {
        return '2026-09-30T12:00:00+08:00';
    },
};

vm.createContext(context);
vm.runInContext([
    sourceBetween('function normalizeAnnouncementToken(', '\n    function normalizeAnnouncementList('),
    sourceBetween('function isAnnouncementReadForUser(', '\n    function isAnnouncementStudentEvaluationRecord('),
    'this.announcementTestHelpers = { decorateAnnouncementForUser };'
].join('\n'), context);

const { decorateAnnouncementForUser } = context.announcementTestHelpers;

const previouslyDismissedProjection = decorateAnnouncementForUser({
    id: 'ANN-OLD',
    title: 'Previously seen',
    message: 'This announcement was dismissed on an earlier login.',
    read: true,
    // The API deliberately does not expose other users' read receipts.
}, 'u42');
assert.equal(
    previouslyDismissedProjection.read,
    true,
    'a server-projected read announcement must stay read after client normalization'
);

const newlyPublishedProjection = decorateAnnouncementForUser({
    id: 'ANN-NEW',
    title: 'New announcement',
    message: 'This announcement has not been dismissed yet.',
    read: false,
}, 'u42');
assert.equal(
    newlyPublishedProjection.read,
    false,
    'a newly published announcement must remain unread and appear on login'
);

const adminSnapshotForCurrentUser = decorateAnnouncementForUser({
    id: 'ANN-ADMIN',
    title: 'Receipt-backed announcement',
    message: 'Admin and HR snapshots include receipt data.',
    read: false,
    readBy: {
        u42: '2026-09-30T11:00:00+08:00',
    },
}, 'u42');
assert.equal(
    adminSnapshotForCurrentUser.read,
    true,
    'admin/HR snapshots must still derive the current user read state from readBy'
);

const adminSnapshotForAnotherUser = decorateAnnouncementForUser({
    id: 'ANN-OTHER',
    title: 'Unread by current user',
    message: 'Only another user has dismissed this announcement.',
    read: false,
    readBy: {
        u99: '2026-09-30T11:00:00+08:00',
    },
}, 'u42');
assert.equal(
    adminSnapshotForAnotherUser.read,
    false,
    'another user read receipt must not hide an announcement from the current user'
);

console.log('Announcement one-time popup tests passed.');
