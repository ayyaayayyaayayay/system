'use strict';
// Uses the project's existing test-only Playwright installation.
const { chromium } = require('../.codex/credibility-ui-test/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');

(async () => {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage();
        await page.route('**/*', route => route.abort());
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        for (const panel of ['hr', 'vpaa']) {
            const source = read('JsScrip/' + panel + 'panel.js');
            const prefix = panel === 'hr' ? '' : 'Vpaa';
            const sandbox = {
                trendRows: [
                    { semesterLabel: 'Term without scores', score: null, delta: null, availableSources: [] },
                    { semesterLabel: 'Student-only term', score: 4, delta: null, availableSources: ['Student'] },
                    { semesterLabel: 'Complete term', score: 4, delta: 0, availableSources: ['Student','Peer','Supervisor'] },
                ],
                escapeHrHtml: value => String(value), escapeHtml: value => String(value),
            };
            vm.createContext(sandbox);
            for (const name of ['normalize' + prefix + 'HistoricalTrendScore','format' + prefix + 'HistoricalTrendScore','format' + prefix + 'HistoricalTrendDelta']) {
                const start = source.indexOf('function ' + name + '(');
                const end = source.indexOf('\nfunction ', start + 1);
                vm.runInContext(source.slice(start, end < 0 ? source.length : end), sandbox);
            }
            const start = source.indexOf('<div class="historical-trend-table-wrap">');
            const end = source.indexOf('<div class="qualitative-responses-section">', start);
            assert(start >= 0 && end > start, 'Historical table template missing');
            const fragment = source.slice(start, end).replace(/\s*<\/div>\s*$/, '');
            const table = vm.runInContext('`' + fragment + '`', sandbox);
            const css = read('css/panel-theme.css') + read('css/hrpanel.css') + (panel === 'vpaa' ? read('css/vpaapanel.css') : '');
            for (const width of [1440, 390]) {
                await page.setViewportSize({ width, height: 900 });
                await page.setContent('<!doctype html><html><head><meta name="viewport" content="width=device-width, initial-scale=1"><style>' + css + '</style></head>'
                    + '<body class="' + panel + '-panel"><div id="professor-analytics-modal" class="modal active" style="display:flex">'
                    + '<div class="modal-content analytics-modal-content"><div class="modal-header"><h2>Professor Analytics</h2></div>'
                    + '<div id="professor-analytics-content"><div class="analytics-view"><div class="historical-trend-section">' + table + '</div></div></div></div></div></body></html>');
                await page.evaluate(() => Promise.all(document.getAnimations().map(animation => animation.finished.catch(() => {}))));
                const rows = page.locator('.historical-trend-table tbody tr');
                assert.equal(await rows.nth(0).locator('td').nth(1).textContent(), 'N/A');
                assert.equal(await rows.nth(0).locator('td').nth(3).textContent(), '-');
                assert.equal(await rows.nth(1).locator('td').nth(1).textContent(), '4.00');
                assert.equal(await rows.nth(1).locator('td').nth(2).textContent(), 'Student');
                assert.equal(await rows.nth(2).locator('td').nth(3).textContent(), '+0.00');
                assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), panel + ': table caused page overflow');
                await page.screenshot({ path: path.join(root, '.codex', 'report-trend-' + panel + '-' + width + '.png') });
            }
        }
        assert.deepEqual(errors, [], 'Historical table browser errors');
        console.log('Historical trend UI tests passed: HR/VPAA, desktop/mobile, N/A, source coverage, zero deltas and overflow.');
    } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
