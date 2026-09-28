'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const sourcePath = path.join(__dirname, '..', 'JsScrip', 'adminpanel.js');
const source = fs.readFileSync(sourcePath, 'utf8');
const sharedDataSource = fs.readFileSync(path.join(__dirname, '..', 'JsScrip', 'db-data.js'), 'utf8');

function sourceBetween(startMarker, endMarker, content = source) {
    const start = content.indexOf(startMarker);
    assert.notEqual(start, -1, `Missing source marker: ${startMarker}`);
    const end = content.indexOf(endMarker, start + startMarker.length);
    assert.notEqual(end, -1, `Missing source marker: ${endMarker}`);
    return content.slice(start, end);
}

class FakeElement {
    constructor(tagName) {
        this.tagName = String(tagName).toUpperCase();
        this.children = [];
        this.dataset = {};
        this.attributes = {};
        this.style = {};
        this.textContent = '';
        this.value = '';
        this.className = '';
    }

    appendChild(child) {
        this.children.push(child);
        return child;
    }

    replaceChildren(...children) {
        this.children = children;
    }

    setAttribute(name, value) {
        this.attributes[name] = String(value);
    }
}

const context = {
    document: {
        createElement(tagName) {
            return new FakeElement(tagName);
        }
    }
};
vm.createContext(context);
vm.runInContext([
    sourceBetween('function escapeHtml(', '\nfunction escapeAttr('),
    sourceBetween('function escapeAttr(', '\nfunction replaceSelectOptions('),
    sourceBetween('function replaceSelectOptions(', '\nfunction createProgramTableRow('),
    sourceBetween('function createProgramTableRow(', '\nfunction inlineIdLiteral('),
    sourceBetween('function escapeAnnouncementHtml(', '\n    function formatAnnouncementDateLabel(', sharedDataSource),
    'this.securityHelpers = { escapeHtml, escapeAttr, replaceSelectOptions, createProgramTableRow, escapeAnnouncementHtml };'
].join('\n'), context);

const { escapeHtml, replaceSelectOptions, createProgramTableRow, escapeAnnouncementHtml } = context.securityHelpers;
const payload = '"><img src=x onerror=globalThis.__xss=1>';

assert.equal(
    escapeHtml(payload),
    '&quot;&gt;&lt;img src=x onerror=globalThis.__xss=1&gt;',
    'HTML text and attribute metacharacters must be encoded'
);
assert.equal(
    escapeAnnouncementHtml(payload),
    '&quot;&gt;&lt;img src=x onerror=globalThis.__xss=1&gt;',
    'stored announcement text must be encoded before popup rendering'
);

const select = new FakeElement('select');
replaceSelectOptions(select, [{ value: payload, label: payload }]);
assert.equal(select.children.length, 1);
assert.equal(select.children[0].value, payload, 'option values must be assigned as properties');
assert.equal(select.children[0].textContent, payload, 'option labels must be assigned as text');
assert.equal(select.children[0].children.length, 0, 'payload must not create option descendants');

const row = createProgramTableRow({
    id: payload,
    programCode: payload,
    programName: '<svg/onload=globalThis.__xss=1>',
    campusSlug: '<script>globalThis.__xss=1</script>',
    departmentCode: '<iframe srcdoc="<script>globalThis.__xss=1</script>">'
});

assert.equal(row.children.length, 5);
assert.equal(row.children[0].textContent, payload);
assert.equal(row.children[1].textContent, '<svg/onload=globalThis.__xss=1>');
assert.equal(row.children[2].children.length, 0, 'campus payload must remain text');
assert.equal(row.children[3].children.length, 0, 'department payload must remain text');
const actions = row.children[4].children[0];
assert.equal(actions.children.length, 2);
assert.equal(actions.children[0].dataset.programId, payload, 'program id must use dataset assignment');
assert.equal(actions.children[0].attributes.onclick, undefined, 'program actions must not use inline handlers');

const renderProgramRowsSource = sourceBetween('function setupProgramManager(', '\nfunction refreshCampusSelects(');
assert.doesNotMatch(
    renderProgramRowsSource,
    /listBody\.innerHTML\s*=\s*rows\.map/,
    'program database rows must not be interpolated into innerHTML'
);

const requiredEscapedPatterns = [
    'escapeHtml(subject.subjectName',
    'escapeHtml(offering.professorName',
    'escapeHtml(student.name',
    'escapeHtml(professor.name',
    'escapeAdminAnalyticsHtml(response.text)'
];
requiredEscapedPatterns.forEach(pattern => {
    assert.ok(source.includes(pattern), `Expected stored-data encoding guard: ${pattern}`);
});
assert.ok(
    sharedDataSource.includes('escapeAnnouncementHtml(announcement.title')
        && sharedDataSource.includes('escapeAnnouncementHtml(announcement.message'),
    'stored announcement title and message must be encoded'
);

console.log('Admin DOM-XSS security tests passed.');
