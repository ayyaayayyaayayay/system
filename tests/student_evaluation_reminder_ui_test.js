const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

const source = fs.readFileSync(__dirname + '/../JsScrip/db-data.js', 'utf8');
const start = source.indexOf('window.StudentEvaluationReminderSettings =');
const end = source.indexOf('window.AppChartDesign =', start);
const elements = {};
for (const id of ['student-eval-reminder-enabled', 'reminder-freq', 'student-eval-reminder-time',
    'student-eval-reminder-subject', 'student-eval-reminder-body', 'student-eval-reminder-reset-btn',
    'student-eval-reminder-save-btn', 'student-eval-reminder-status']) {
    elements[id] = { value: '', classList: { add() {}, remove() {} },
        addEventListener(event, handler) { this[event] = handler; } };
}
let saved;
let onChange;
const context = {
    window: {}, document: { getElementById: id => elements[id] },
    SharedData: {
        KEYS: { STUDENT_EVAL_REMINDER_CONFIG: 'reminders' },
        getStudentEvaluationReminderConfig: () => ({ enabled: true, frequencyDays: 3, sendTime: '08:00' }),
        onDataChange(handler) { onChange = handler; },
        async updateStudentEvaluationReminderConfigAsync(config) { saved = config; return config; },
    },
};
vm.runInNewContext(source.slice(start, end), context);
context.window.StudentEvaluationReminderSettings.setup();

(async () => {
    const time = elements['student-eval-reminder-time'];
    assert.equal(time.value, '08:00');
    time.value = '14:30';
    await elements['student-eval-reminder-save-btn'].click();
    assert.equal(saved.sendTime, '14:30');
    assert.equal(saved.frequencyDays, 3);
    saved = null;
    time.value = '';
    await elements['student-eval-reminder-save-btn'].click();
    assert.equal(saved, null);
    assert.match(elements['student-eval-reminder-status'].textContent, /valid reminder send time/);
    elements['student-eval-reminder-reset-btn'].click();
    assert.equal(time.value, '07:00');
    onChange('reminders', { sendTime: '23:59' });
    assert.equal(time.value, '23:59');
    console.log('Student reminder UI tests passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
