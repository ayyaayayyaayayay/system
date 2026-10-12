'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const {spawnSync} = require('node:child_process');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8').replace(/\r\n/g, '\n');
const shared = read('JsScrip/db-data.js');
const start = shared.indexOf('    // Mirror api/feedback_classification_policy.json.');
const end = shared.indexOf('    async function analyzeBiasComments(', start);
const context = {TextEncoder}; vm.createContext(context);
vm.runInContext(shared.slice(start, end), context);
context.SharedData = Object.fromEntries(['classifyFeedbackCommentByRules','detectFeedbackCommentTopics','getFeedbackClassificationTone',
    'prepareFeedbackSummaryComments','buildFeedbackTeachingImprovements'].map(name=>[name,context[name]]));
const professor = read('JsScrip/profesorpanel.js');
for (const name of ['buildStudentFeedbackAiSummary','classifyFeedbackCommentBiasByRules','detectStudentCommentTopics']) {
    const a = professor.indexOf('function '+name+'(');
    const b = professor.indexOf('\nfunction ', a+1);
    assert(a >= 0 && b > a, 'Missing function '+name);
    vm.runInContext(professor.slice(a, b), context);
}
const evidence = [
    {text:'Please provide more worked examples.'},
    {text:'Sana po mas malinaw ang paliwanag.'},
    {text:'The class pacing is too fast.'},
    {text:'Thank you for the helpful learning materials.'},
    {text:'Bobo ka sir.'},
];
const near = [
    {text:'please explain important aviation topics carefully using practical classroom examples today'},
    {text:'please explain important aviation topics carefully using practical classroom examples tomorrow'},
];
const cases = [[], [evidence[0]], evidence, [evidence[3]], [evidence[4]],
    [...evidence, evidence[0]], near, [...near,{text:'please do not explain important aviation topics carefully using practical classroom examples tomorrow'}],
    [{text:'Sana po mas mabagal ang discussion.',commentRepetitiveFlag:true}],
    Array.from({length:170},(_,i)=>({text:'Unique'+i})),
];
for (const items of cases) {
    const local = context.buildStudentFeedbackAiSummary(items);
    const php = spawnSync('C:/xampp/php/php.exe',[path.join(__dirname,'professor_feedback_intelligence_test.php'),'--summary-json'],{
        input:JSON.stringify(items),encoding:'utf8',
    });
    assert.equal(php.status,0,php.stderr);
    const server = JSON.parse(php.stdout);
    for (const key of ['total','constructive','positive','neutral','critical','biased','teachingImprovements','excludedRepetitiveComments']) {
        assert.deepEqual(JSON.parse(JSON.stringify(local[key])),server[key],'Server/local mismatch: '+key+' '+JSON.stringify(items.slice(0,2)));
    }
    assert.equal(local.availableComments,items.length);
}
assert.equal(context.buildStudentFeedbackAiSummary(evidence).teachingImprovements.length,3);
assert.equal(context.buildStudentFeedbackAiSummary([evidence[3],evidence[4]]).teachingImprovements.length,0);
assert.equal(context.buildStudentFeedbackAiSummary(near).total,0);
console.log('PASS: browser/server parity for advice, bilingual concerns, praise, insults, exact/near repetition, flags, empty/single feedback, and limits.');
