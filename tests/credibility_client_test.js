'use strict';
const assert = require('assert');
const fs = require('fs');
const vm = require('vm');
const path = require('path');
function extract(file, name) {
    const source = fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
    const start = source.indexOf('function ' + name + '(');
    assert(start >= 0, name);
    const end = source.indexOf('\nfunction ', start + 1);
    return source.slice(start, end < 0 ? source.length : end);
}
const saved = new Map();
const storage = { getItem: key => saved.get(key) || null, setItem: (key,v) => saved.set(key,v), removeItem: key => saved.delete(key) };
const now = new Date().toISOString();
const began = new Date(Date.now()-60000).toISOString();
saved.set('evaluationBehavior:u1|semester|target', began);
function studentPage() {
    const context = { sessionStorage: storage, SharedData: { getNowIsoString: () => now },
        evaluationBehaviorCapture: { captureKey: '', startedAt: '' },
        getSelectedEvaluationTarget: () => 'target', buildEvaluationBehaviorCaptureKey: () => 'u1|semester|target' };
    vm.createContext(context);
    for (const name of ['resetEvaluationBehaviorCapture','startEvaluationBehaviorCapture','buildEvaluationBehaviorMeta']) vm.runInContext(extract('JsScrip/studentpanel.js', name), context);
    return context;
}
let page = studentPage();
page.startEvaluationBehaviorCapture(true);
assert.strictEqual(page.evaluationBehaviorCapture.startedAt, began, 'Navigation reset the persisted timer.');
page = studentPage(); // A reload loses JS variables but preserves session storage.
page.startEvaluationBehaviorCapture(false);
assert.strictEqual(page.evaluationBehaviorCapture.startedAt, began, 'Reload lost original start time.');
const first = page.buildEvaluationBehaviorMeta({submittedAt:now,ratings:{1:'5',2:'4'},qualitative:{3:'Useful examples'}},[1,2,3]);
assert.strictEqual(first.startedAt,began);
assert.strictEqual(first.answeredCount,3);
assert(first.durationSeconds>=59.9);
assert(first.secondsPerQuestion>=19.9);
const retry = page.buildEvaluationBehaviorMeta({submittedAt:now,ratings:{1:'5',2:'4'},qualitative:{3:'Useful examples'}},[1,2,3]);
assert.deepStrictEqual(first,retry,'Retry reset behavior evidence.');
const format = { Number };
vm.createContext(format);
vm.runInContext(extract('JsScrip/hrpanel.js','formatAiCredibilityComponentValue'),format);
assert.strictEqual(format.formatAiCredibilityComponentValue(null),'N/A');
assert.strictEqual(format.formatAiCredibilityComponentValue(undefined),'N/A');
assert.strictEqual(format.formatAiCredibilityComponentValue(0),'0');
assert.strictEqual(format.formatAiCredibilityComponentValue(65),'65');
console.log('PASS: timer navigation/reload/retry persistence and NULL versus zero behavior display (11 assertions).');
