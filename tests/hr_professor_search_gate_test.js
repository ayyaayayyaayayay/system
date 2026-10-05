'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const source = fs.readFileSync(path.join(root, 'JsScrip', 'hrpanel.js'), 'utf8');
const css = fs.readFileSync(path.join(root, 'css', 'hrpanel.css'), 'utf8');

function sourceBetween(text, startMarker, endMarker) {
    const start = text.indexOf(startMarker);
    assert.notEqual(start, -1, `Missing source marker: ${startMarker}`);
    const end = text.indexOf(endMarker, start + startMarker.length);
    assert.notEqual(end, -1, `Missing source marker: ${endMarker}`);
    return text.slice(start, end);
}

const loadGate = sourceBetween(
    source,
    'function shouldLoadHrProfessorList()',
    '\nfunction refreshHrProfessorListForCurrentFilters('
);
assert.match(loadGate, /return\s+hasProfessorSearchRun\s*;/);
assert.doesNotMatch(loadGate, /hasActiveHrProfessorFilters\s*\(/);

const searchHandler = sourceBetween(
    source,
    'function runProfessorSearch()',
    '\nfunction clearProfessorSearchFilters()'
);
assert.match(searchHandler, /hasProfessorSearchRun\s*=\s*true\s*;/);
assert.doesNotMatch(searchHandler, /submittedTerm\s*!==\s*['"]{2}/);

const elements = {
    'professor-search': { value: '' },
    'professor-campus-filter': { value: 'all' },
};
let refreshCount = 0;
const context = {
    document: { getElementById: id => elements[id] || null },
};
vm.createContext(context);
vm.runInContext(`
    let currentProfessorCampusFilter = 'all';
    let lastProfessorSearchTerm = '';
    let hasProfessorSearchRun = false;
    let hrProfessorPage = 4;
    function normalizeHrToken(value) { return String(value || '').trim().toLowerCase(); }
    function refreshHrProfessorListForCurrentFilters() { refreshCount += 1; }
    let refreshCount = 0;
    ${loadGate}
    ${searchHandler}
    this.shouldLoad = shouldLoadHrProfessorList;
    this.runSearch = runProfessorSearch;
    this.readState = () => ({ hasProfessorSearchRun, lastProfessorSearchTerm, hrProfessorPage, refreshCount });
`, context);

assert.equal(context.shouldLoad(), false, 'Professor rows must be gated on initial load.');
context.runSearch();
assert.equal(context.shouldLoad(), true, 'Submitting an empty search must open the full professor list.');
assert.deepEqual(
    JSON.parse(JSON.stringify(context.readState())),
    { hasProfessorSearchRun: true, lastProfessorSearchTerm: '', hrProfessorPage: 1, refreshCount: 1 }
);

elements['professor-search'].value = '  PRF-2026-002  ';
context.runSearch();
assert.equal(context.readState().lastProfessorSearchTerm, 'prf-2026-002');

const setup = sourceBetween(
    source,
    'function setupProfessorManagement()',
    '\nfunction loadUserManagement()'
);
assert.match(setup, /addEventListener\(['"]keydown['"][\s\S]*event\.key\s*===\s*['"]Enter['"][\s\S]*runProfessorSearch\(\)/);

assert.match(css, /\.professor-table-wrap\s*\{[^}]*max-height:\s*min\(56vh,\s*520px\)[^}]*overflow:\s*auto/s);
assert.match(css, /\.professor-table th\s*\{[^}]*position:\s*sticky[^}]*top:\s*0/s);

console.log('HR professor search gate and height regression tests passed.');
