'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const { spawnSync } = require('node:child_process');
const root = path.resolve(__dirname,'..');
const read = name => fs.readFileSync(path.join(root,name),'utf8').replace(/\r\n/g,'\n');
const shared = read('JsScrip/db-data.js');
const start = shared.indexOf('    // Mirror api/feedback_classification_policy.json.');
const end = shared.indexOf('    async function analyzeBiasComments(',start);
const context = {TextEncoder};
vm.createContext(context);
vm.runInContext(shared.slice(start,end)+'\nthis.policy=feedbackClassificationPolicy;',context);
assert.deepEqual(JSON.parse(JSON.stringify(context.policy)),JSON.parse(read('api/feedback_classification_policy.json')),'Browser policy drifted from server policy.');
const fixtures=JSON.parse(read('tests/fixtures/multilingual_feedback.json'));
const php=spawnSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe',[path.join(__dirname,'multilingual_feedback_test.php'),'--classify-json'],{input:JSON.stringify(fixtures),encoding:'utf8'});
assert.equal(php.status,0,php.stderr);
const results=JSON.parse(php.stdout);
for (let i=0;i<fixtures.length;i++) {
    const classified=context.classifyFeedbackCommentByRules(fixtures[i].text);
    assert.equal(classified.label,fixtures[i].label,fixtures[i].text);
    assert.deepEqual(JSON.parse(JSON.stringify(classified)),results[i], 'PHP/browser mismatch: '+fixtures[i].text);
}
function extract(source,name) {
    const a=source.indexOf('function '+name+'(');
    const b=source.indexOf('\nfunction ',a+1);
    assert(a>=0 && b>a,name);
    return source.slice(a,b);
}
const SharedData={classifyFeedbackCommentByRules:context.classifyFeedbackCommentByRules,getFeedbackClassificationTone:context.getFeedbackClassificationTone,detectFeedbackCommentTopics:context.detectFeedbackCommentTopics,
    prepareFeedbackSummaryComments:context.prepareFeedbackSummaryComments,buildFeedbackTeachingImprovements:context.buildFeedbackTeachingImprovements};
const comments=[0,1,2,3,4].map(i=>({text:fixtures[i].text}));
for (const [file,classifier,summary,topics] of [
    ['JsScrip/daenpanel.js','classifyDeanFeedbackCommentBiasByRules','buildDeanFeedbackAiSummary','detectDeanFeedbackTopics'],
    ['JsScrip/profesorpanel.js','classifyFeedbackCommentBiasByRules','buildStudentFeedbackAiSummary','detectStudentCommentTopics'],
]) {
    const source=read(file); const panel={SharedData}; vm.createContext(panel);
    for(const name of [classifier,topics,summary]) vm.runInContext(extract(source,name),panel);
    const result=panel[summary](comments);
    for(const key of ['constructive','positive','neutral','critical','biased']) assert.equal(result[key],1,file+' '+key);
    assert.equal(result.total,5);
    assert.equal(panel[summary]([]).total,0);
    for(const key of ['constructive','positive','neutral','critical','biased']) assert.equal(panel[summary]([])[key],0);
}
const hr={};vm.createContext(hr);
for (const name of ['normalizeAiCredibilityBiasLabel','mapAiCredibilityBiasLabelToScore']) vm.runInContext(extract(read('JsScrip/hrpanel.js'),name),hr);
for (const [label,score] of Object.entries({Constructive:100,Positive:80,Neutral:80,Critical:80,Biased:40})) {
    assert.equal(hr.normalizeAiCredibilityBiasLabel(label.toLowerCase()),label);
    assert.equal(hr.mapAiCredibilityBiasLabelToScore(label),score);
}
console.log(`PASS: ${fixtures.length} bilingual PHP/browser classifications, shared policy parity, panel summaries, and HR scores.`);
