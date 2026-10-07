'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const read = name => fs.readFileSync(path.join(root, 'JsScrip', name), 'utf8').replace(/\r\n/g, '\n');
function between(source, start, end) {
    const first = source.indexOf(start);
    const last = source.indexOf(end, first + start.length);
    assert.ok(first >= 0 && last > first, `Missing function boundaries: ${start}`);
    return source.slice(first, last);
}

async function main() {
    let sent;
    const transport = {
        startBootstrap() {}, buildActorPayload: () => ({}),
        requestJson: async (method, action, body, options) => {
            sent = { method, action, body, options };
            return { success: true, source: 'openai', insight: { stats: { totalComments: 904 } } };
        },
    };
    vm.createContext(transport);
    vm.runInContext(between(read('db-data.js'), 'async function analyzeEvaluationExplainability(', '\n    function generateFacultyPaperSectionCRecommendations(')
        + '\nthis.analyze = analyzeEvaluationExplainability;', transport);
    const response = await transport.analyze({
        professor: { id: 'u42' }, semesterId: 'history',
        comments: Array(1000).fill({ text: 'Do not upload these browser copies.' }), metrics: { combinedAverage: 1 },
    }, {});
    assert.equal(response.insight.stats.totalComments, 904);
    assert.equal(sent.action, 'analyzeEvaluationExplainability');
    assert.equal(sent.method, 'POST');
    assert.equal(sent.body.payload.professor.id, 'u42');
    assert.equal(sent.body.payload.semesterId, 'history');
    assert.equal(sent.body.payload.comments, undefined, 'The server builds comments; do not upload a second dataset.');
    assert.equal(sent.body.payload.metrics, undefined);

    const panels = [
        ['hrpanel.js', 'runHrAiAnalyticsForProfessor', '\nfunction generateEmployeeId(', 'renderHrAiInsightState', 'renderHrAiInsightResult', 'normalizeHrAiInsightData', 'getHrAiAnalyticsActorIdentity', 'normalizeHrAiAnalyticsSourceLabel', 'innerHTML'],
        ['adminpanel.js', 'runAdminAiAnalyticsForProfessor', '\n/**\n * Load and display reports', 'renderAdminAiInsightState', 'renderAdminAiInsightResult', 'normalizeAdminAiInsightData', 'getAdminAiAnalyticsActorIdentity', 'normalizeAdminAiAnalyticsSourceLabel', 'innerHTML'],
        ['vpaapanel.js', 'runAiAnalyticsForProfessor', '\nfunction sanitizePhotoSource(', 'renderAiInsightState', 'renderAiInsightResult', 'normalizeAiInsightData', 'getAiAnalyticsActorIdentity', 'normalizeAiAnalyticsSourceLabel', 'textContent'],
    ];
    for (const [file, fn, end, state, result, normalize, actor, sourceLabel, buttonProp] of panels) {
        const states = [], results = [], overlays = [], requests = [];
        let resolveRequest;
        let pending = new Promise(resolve => { resolveRequest = resolve; });
        const professor = { id: 'u42', userId: 'u42', name: 'Fixture', semester: 'history' };
        const context = {
            professorsData: [professor], allProfessorData: [professor],
            normalizeVpaaProfessorUserId: id => id,
            console: { error() {} },
            window: { AppLoadingOverlay: { show: () => overlays.push('show'), hide: () => overlays.push('hide') } },
            SharedData: {
                analyzeEvaluationExplainability: (scope, identity) => { requests.push({scope, identity}); return pending; },
                getProfessorAnalyticsPayload: () => { throw new Error('Do not fetch the same dataset twice.'); },
            },
            [state]: (_output, status, message) => states.push({status, message}),
            [result]: (_output, insight, source) => results.push({insight, source}),
            [normalize]: insight => insight,
            [actor]: () => ({userId:'u4'}),
        };
        vm.createContext(context);
        const source = read(file);
        vm.runInContext(between(source, 'async function ' + fn + '(', end) + '\nthis.run = ' + fn + ';', context);
        vm.runInContext(between(source, 'function ' + sourceLabel + '(', '\n}') + '\n}\nthis.sourceLabel = ' + sourceLabel + ';', context);
        assert.equal(context.sourceLabel('Supervisor to Professor'), 'Supervisor to Professor');
        assert.equal(context.sourceLabel('Admin to Professor'), 'Supervisor to Professor');

        const button = { disabled:false, innerHTML:'Original button', textContent:'Original button' };
        const call = () => file === 'vpaapanel.js'
            ? context.run('u42', {}, button)
            : context.run('u42', 'history', {}, button);
        const task = call();
        assert.equal(states[0].status, 'loading', `${file} must show loading before the request completes.`);
        assert.equal(button.disabled, true);
        assert.equal(results.length, 0, `${file} must wait for the asynchronous result.`);
        await call();
        assert.equal(requests.length, 1, `${file} must suppress duplicate clicks and use a single request.`);
        assert.equal(requests[0].scope.professor.id, 'u42');
        assert.equal(requests[0].scope.semesterId, 'history');
        resolveRequest({ success:true, source:'openai', insight: { stats: { totalComments:904, combinedAverage:4 } } });
        await task;
        assert.equal(results[0].insight.stats.totalComments, 904);
        assert.equal(button.disabled, false);
        assert.equal(button[buttonProp], 'Original button');
        assert.deepEqual(overlays, ['show','hide']);

        pending = Promise.resolve({ success:true, source:'rule', insight: { stats: { totalComments:0, combinedAverage:null } } });
        await call();
        assert.equal(states.at(-1).status, 'empty');
        context.SharedData.analyzeEvaluationExplainability = async () => { throw new Error('Fixture connection failure'); };
        await call();
        assert.equal(states.at(-1).status, 'error');
        assert.equal(button.disabled, false, `${file} must allow retries after an error.`);
        assert.equal(button[buttonProp], 'Original button');
    }
    console.log('Asynchronous AI analytics transport and all three panel flows passed.');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
