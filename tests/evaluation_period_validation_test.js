const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');

for (const panel of ['adminpanel', 'hrpanel']) {
    const source = fs.readFileSync(`${__dirname}/../JsScrip/${panel}.js`, 'utf8');
    const start = source.indexOf('function setupEvalPeriods() {');
    const end = source.indexOf('function setupSemesterSettings()', start);
    const elements = {};
    const types = ['student-professor', 'professor-professor', 'supervisor-professor'];
    let saves = 0;
    let alerts = 0;
    for (const type of types) {
        for (const field of ['start', 'end']) {
            elements[`${type}-${field}`] = {
                value: '', min: '', max: '', message: '', reports: 0,
                addEventListener(event, handler) { this[event] = handler; },
                setCustomValidity(message) { this.message = message; },
                get validity() {
                    return { valid: !this.message && (!this.value ||
                        ((!this.min || this.value >= this.min) && (!this.max || this.value <= this.max))) };
                },
                reportValidity() { this.reports++; },
                focus() {},
            };
        }
    }
    elements['save-eval-periods-btn'] = { addEventListener(event, handler) { this[event] = handler; } };
    const context = {
        document: { getElementById: id => elements[id] },
        SharedData: { getEvalPeriods: () => ({}), setEvalPeriods() { saves++; } },
        alert() { alerts++; },
    };
    vm.runInNewContext(source.slice(start, end), context);
    context.setupEvalPeriods();
    const save = () => elements['save-eval-periods-btn'].click();
    for (const type of types) {
        const startInput = elements[`${type}-start`];
        const endInput = elements[`${type}-end`];
        const before = saves;
        startInput.value = '2026-01-10';
        startInput.input();
        assert.equal(endInput.min, '2026-01-10');
        endInput.value = '2026-01-01';
        endInput.change();
        assert.match(endInput.message, /End date cannot be earlier/);
        assert.equal(endInput.reports, 1);
        save();
        assert.equal(saves, before, `${panel}: reversed ${type} must not save`);
        assert.equal(alerts, saves, 'Invalid ranges must not announce success');
        endInput.value = '2026-01-10';
        endInput.input();
        save();
        assert.equal(saves, before + 1, 'Same-day periods are allowed');
        startInput.value = '2026-01-11';
        save();
        assert.equal(saves, before + 1, 'Save revalidates even without an input event');
        startInput.value = '2025-12-31';
        startInput.input();
        assert.equal(endInput.message, '');
        save();
        assert.equal(saves, before + 2, 'Corrected cross-year periods are allowed');
        endInput.value = '';
        endInput.input();
        assert.equal(startInput.max, '', 'Clearing a date removes its constraint');
    }
}
console.log('Evaluation period validation UI tests passed.');
