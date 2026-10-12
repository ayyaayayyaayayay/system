'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const { findSourceMarker } = require('./helpers/js-source');
const root = path.resolve(__dirname, '..');
function between(source, start, end) {
    const first = findSourceMarker(source, start);
    const last = findSourceMarker(source, end, first + 1);
    assert(first >= 0 && last > first);
    return source.slice(first, last);
}

async function main() {
    let resolveRequest, rejectRequest, requests = 0, filters;
    const transport = {
        startBootstrap() {}, buildActorPayload: () => ({}),
        requestJson(method, action, body) {
            requests++;
            assert.equal(method, 'POST');
            assert.equal(action, 'analyzeBiasComments');
            filters = body.filters;
            return new Promise((resolve, reject) => { resolveRequest = resolve; rejectRequest = reject; });
        },
    };
    vm.createContext(transport);
    const shared = fs.readFileSync(path.join(root, 'JsScrip/db-data.js'), 'utf8');
    vm.runInContext(between(shared, 'function analyzeBiasComments(', '\n    function listCredibilityReviews('), transport);
    const ids = ['hr-ai-bias-semester', 'hr-ai-bias-feedback', 'hr-ai-bias-summary', 'hr-ai-bias-body', 'hr-run-bias-detection-btn'];
    const elements = Object.fromEntries(ids.map(id => [id, { value: 'all', innerHTML: 'Run Bias Detection', textContent: '', disabled: false, style: {} }]));
    const loading = [];
    const context = {
        document: { getElementById: id => elements[id] },
        SharedData: { analyzeBiasComments: transport.analyzeBiasComments },
        console: { error() {} },
        window: { AppLoadingOverlay: {
            show() {}, hide() {},
            async run(message, work) {
                loading.push('show');
                assert.match(message, /bias detection/i);
                try { return await work(); } finally { loading.push('hide'); }
            },
        } },
        renderHrResponsiveTablePrompt: (body, _cols, message) => { body.innerHTML = message; },
    };
    vm.createContext(context);
    const hr = fs.readFileSync(path.join(root, 'JsScrip/hrpanel.js'), 'utf8');
    vm.runInContext(between(hr, 'function runHrWithGlobalLoading(', '\nfunction resetAiInsightsDiscrepancyPrompt('), context);
    const first = context.runAiBiasDetection();
    assert.equal(elements['hr-run-bias-detection-btn'].disabled, true);
    assert.deepEqual(loading, ['show']);
    assert.equal(requests, 1);
    assert.equal(filters.semesterId, '');
    await context.runAiBiasDetection();
    assert.equal(requests, 1, 'Duplicate clicks must not call the API.');
    resolveRequest({ success: true, items: [], summary: { total: 0 } });
    assert.equal(await first, true);
    assert.deepEqual(loading, ['show', 'hide']);
    assert.equal(elements['hr-run-bias-detection-btn'].disabled, false);
    assert.equal(elements['hr-run-bias-detection-btn'].innerHTML, 'Run Bias Detection');
    assert.match(elements['hr-ai-bias-body'].innerHTML, /No comments/);
    const failure = context.runAiBiasDetection();
    rejectRequest(new Error('Unavailable'));
    assert.equal(await failure, false);
    assert.equal(elements['hr-run-bias-detection-btn'].disabled, false);
    assert.deepEqual(loading, ['show', 'hide', 'show', 'hide']);
    assert.match(elements['hr-ai-bias-feedback'].textContent, /Unavailable/);
    const retry = context.runAiBiasDetection();
    resolveRequest({ success: true, items: [], summary: {} });
    assert.equal(await retry, true);
    console.log('Async HR bias transport, full-operation loading, duplicate prevention, error cleanup and retry tests passed.');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
