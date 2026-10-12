'use strict';
// Run after changing api/feedback_classification_policy.json; parity tests verify the browser mirror.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = path.resolve(__dirname, '../..');
const policy = JSON.parse(fs.readFileSync(path.join(root, 'api/feedback_classification_policy.json'), 'utf8'));
const file = path.join(root, 'JsScrip/db-data.js');
const source = fs.readFileSync(file, 'utf8').replace(/\r\n/g, '\n');
const marker = '    const feedbackClassificationPolicy = ';
const start = source.indexOf(marker);
const end = source.indexOf(';\n\n    function classifyFeedbackCommentByRules(', start);
assert(start >= 0 && end > start, 'SharedData policy boundaries are missing.');
fs.writeFileSync(file, source.slice(0, start) + marker + JSON.stringify(policy, null, 4) + source.slice(end), 'utf8');
console.log('Updated the SharedData feedback policy mirror.');
