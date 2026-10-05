'use strict';
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const assert = require('assert');
const { spawnSync } = require('child_process');
const source = fs.readFileSync(path.join(__dirname, '../JsScrip/hrpanel.js'), 'utf8');
function extract(name) {
    const start = source.indexOf('function ' + name + '(');
    assert(start >= 0, name);
    const end = source.indexOf('\nfunction ', start + 1);
    return source.slice(start, end < 0 ? source.length : end);
}
const sandbox = {
    console, normalizeHrToken: v => String(v || '').trim().toLowerCase(),
    getHrEvaluationTypeKey: e => e.evaluationType,
    isHrEvaluationInSemester: () => true,
    resolveAiInsightsStudentNumber: () => 'Anonymous',
    resolveHrEvaluationTargetProfessorId: e => e.targetProfessorId,
    clampNumber: (n,lo,hi) => Math.max(lo,Math.min(hi,n)),
    medianFromValues: values => { values = values.slice().sort((a,b)=>a-b); const mid=Math.floor(values.length/2); return values.length%2 ? values[mid] : (values[mid-1]+values[mid])/2; },
};
vm.createContext(sandbox);
['normalizeBehaviorCommentText','tokenizeBehaviorCommentText','extractBehaviorCommentPattern','buildBehaviorRepetitionTargetKey',
 'getBehaviorRepetitionMinOverlap','computeBehaviorAnswerSimilarity','computeBehaviorCommentSimilarity','analyzeEvaluationBehaviorRecords'].forEach(name => vm.runInContext(extract(name), sandbox));
let seed=17;
const rand = max => { seed=(seed*16807)%2147483647; return seed%max; };
let assertions=0;
for(let batch=0;batch<12;batch++) {
    const cohort=Array.from({length:18},(_,i)=>({
        id:'db-eval-'+i, evaluationType:'student', semesterId:'semester', evaluatorUserId:'u'+(i%6), studentUserId:'u'+(i%6),
        targetProfessorId:'u99', courseOfferingId:String(1+i%3), ratings:Object.fromEntries(Array.from({length:5+rand(4)},(_,j)=>[String(j+1),String(batch%3===0 ? 5 : 1+rand(5))])),
        comments:i%3===0?'Please provide more detailed worked examples':i%3===1?'Clear helpful lessons':'',
        qualitative:i%4===0?{q:'Please provide more detailed worked examples'}:{},
        behaviorMeta:{captureVersion:1,questionCount:8,answeredCount:8,durationSeconds:8*(1+rand(80))/10,secondsPerQuestion:(1+rand(80))/10},
    }));
    const js=sandbox.analyzeEvaluationBehaviorRecords({evaluations:cohort},'all').records;
    const php=spawnSync(process.env.PHP_BINARY || 'C:/xampp/php/php.exe',[path.join(__dirname,'credibility_score_cli.php')],{input:JSON.stringify(cohort),encoding:'utf8'});
    assert.strictEqual(php.status,0,php.stderr);
    const result=JSON.parse(php.stdout);
    for(const row of js) {
        assert.strictEqual(result[row.submissionId].score,row.behaviorScore,`Formula mismatch batch ${batch}, ${row.submissionId}`);
        for(const [a,b] of [['speedRisk','speedRisk'],['uniformityRisk','uniformityRisk'],['repetitionRisk','repetitionRisk']]) assert(Math.abs(result[row.submissionId][a]-row[b])<1e-9);
        assertions+=4;
    }
}
console.log(`PASS: PHP behavior formula parity with existing JavaScript (${assertions} comparisons).`);
