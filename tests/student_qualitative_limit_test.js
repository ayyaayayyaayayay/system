'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname,'..','JsScrip','studentpanel.js'),'utf8');
const start = source.indexOf('function renderQuestionHTML(');
const end = source.indexOf('const EXCEPTION_REPORTING_FILLER_VALUES',start);
assert.ok(start >= 0 && end > start);
const context = { escapeAttr: String, escapeHtml: String, renderManifestedRatingScaleTable: () => '' };
vm.createContext(context);
vm.runInContext(source.slice(start,end) + '\nthis.render = renderQuestionHTML;',context);
for (const configuredLimit of [undefined,500,300]) {
    const rendered = context.render({id:40,type:'qualitative',text:'Feedback',maxLength:configuredLimit},1);
    assert.match(rendered,/data-word-limit="400"/);
    assert.doesNotMatch(rendered,/maxlength=/);
    assert.match(rendered,/placeholder="Your answer\.\.\."/);
    assert.doesNotMatch(rendered,/up to \d+ characters/);
    assert.doesNotMatch(rendered,/ required /);
}
const required = context.render({id:41,type:'qualitative',text:'Required feedback',required:true,maxLength:80},2);
assert.match(required,/data-word-limit="400"/);
assert.doesNotMatch(required,/maxlength=/);
assert.match(required,/ required /);
const rating = context.render({id:42,type:'rating',text:'Rating',required:true,ratingScale:'1-5'},3);
assert.doesNotMatch(rating,/textarea|maxlength/);
assert.equal((rating.match(/type="radio"/g) || []).length,5);
const peerSource = fs.readFileSync(path.join(__dirname,'..','JsScrip','profesorpanel.js'),'utf8');
const peerStart = peerSource.indexOf('function renderPeerQuestionHTML(');
const peerEnd = peerSource.indexOf('function setupPeerSectionFlow(',peerStart);
assert.ok(peerStart >= 0 && peerEnd > peerStart);
context.escapeHTML = String;
vm.runInContext(peerSource.slice(peerStart,peerEnd) + '\nthis.renderPeer = renderPeerQuestionHTML;',context);
for (const configuredLimit of [undefined,500,300,80]) {
    const rendered = context.renderPeer({id:40,type:'qualitative',text:'Peer feedback',maxLength:configuredLimit},1);
    assert.match(rendered,/data-word-limit="400"/);
    assert.doesNotMatch(rendered,/maxlength=/);
    assert.doesNotMatch(rendered,/up to \d+ characters| required /);
}
const peerRequired = context.renderPeer({id:41,type:'qualitative',text:'Required feedback',required:true},2);
assert.match(peerRequired,/data-word-limit="400"/);
assert.match(peerRequired,/\srequired>/);
const peerRating = context.renderPeer({id:42,type:'rating',text:'Rating',required:true},3);
assert.doesNotMatch(peerRating,/textarea|maxlength/);
assert.equal((peerRating.match(/type="radio"/g) || []).length,5);
const listeners = {};
context.window = context;
context.document = { addEventListener: (type, listener) => { listeners[type] = listener; } };
vm.runInContext(fs.readFileSync(path.join(__dirname,'..','JsScrip','evaluation-text-limits.js'),'utf8'),context);
const limits = context.EvaluationTextLimits;
const atLimit = Array(400).fill('feedback').join(' ');
assert.equal(limits.countWords(atLimit),400);
assert.equal(limits.countWords('  \t\n'),0);
assert.equal(limits.countWords('Useful\tfeedback\nwith\u00a0Unicode\ufeffspaces'),5);
const field = {
    value: atLimit, disabled: false, message: '', reports: 0,
    getAttribute: name => name === 'data-word-limit' ? '400' : '0',
    matches: () => true,
    setCustomValidity(message) { this.message = message; },
    reportValidity() { this.reports++; }
};
const root = { querySelectorAll: () => [field] };
assert.equal(limits.validateAll(root,true),true);
field.value += ' extra';
assert.equal(limits.validateAll(root,true),false);
assert.match(field.message,/400 words/);
assert.equal(field.reports,1);
field.value = atLimit;
listeners.input({ target: field });
assert.equal(field.message,'');
field.value += ' extra';
listeners.change({ target: field });
assert.match(field.message,/400 words/);
field.disabled = true;
assert.equal(limits.validateAll(root),true);
field.disabled = false;
field.value = 'a'.repeat(2000);
assert.equal(limits.validateAll(root),true);
assert.equal(field.message,'');
const exceptionStart = source.indexOf('function applyExceptionReportingRequirements(');
const exceptionEnd = source.indexOf('function setupExceptionReportingTextValidation(',exceptionStart);
assert.ok(exceptionStart >= 0 && exceptionEnd > exceptionStart);
context.computeCurrentRatingAverage = () => 3;
context.validateExceptionReportingTextValue = () => '';
vm.runInContext(source.slice(exceptionStart,exceptionEnd),context);
const exceptionField = {
    ...field, value: atLimit + ' extra', message: '',
    getAttribute: name => name === 'data-word-limit' ? '400' : '0',
    setAttribute() {}, removeAttribute() {}, closest: () => null
};
const exceptionRoot = { querySelectorAll: () => [exceptionField] };
assert.equal(context.applyExceptionReportingRequirements({scopeRoot:exceptionRoot}),false);
assert.match(exceptionField.message,/400 words/);
exceptionField.value = atLimit;
assert.equal(context.applyExceptionReportingRequirements({scopeRoot:exceptionRoot}),true);
assert.equal(exceptionField.message,'');
console.log('Student and peer 400-word validation and rating rendering tests passed.');
