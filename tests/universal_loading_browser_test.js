'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const { chromium } = require('../.codex/credibility-ui-test/node_modules/playwright');
const root = path.resolve(__dirname, '..');
const shared = fs.readFileSync(path.join(root, 'JsScrip/db-data.js'), 'utf8');
const loader = shared.slice(shared.indexOf('(function initAppLoadingOverlay()'), shared.indexOf('window.StudentEvaluationReminderSettings'));
const css = fs.readFileSync(path.join(root, 'css/panel-theme.css'), 'utf8');
const visible = page => page.locator('#app-global-loading-overlay').isVisible();

async function main() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        const page = await browser.newPage({ viewport: { width: 1100, height: 850 } });
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => route.fulfill({ contentType: 'text/html', body: '<button id="start">Start</button><dialog id="review"><button id="confirm">Confirm</button></dialog><div id="bulk-register-loading"></div>' }));
        await page.goto('https://loading.test/system/html/test.html');
        await page.addStyleTag({ content: css });
        await page.evaluate(() => {
            window.fetch = function (input) {
                if (String(input).includes('reject')) return Promise.reject(new Error('Connection failed'));
                if (String(input).includes('abort')) return Promise.reject(new DOMException('Aborted', 'AbortError'));
                return Promise.resolve(new Response(new ReadableStream({ start(controller) {
                    controller.enqueue(new TextEncoder().encode('{"success":true}'));
                    setTimeout(() => controller.close(), 1200);
                } }), { headers: { 'Content-Type': 'application/json' } }));
            };
        });
        await page.addScriptTag({ content: loader });
        assert.equal(await visible(page), false);
        await page.locator('#start').focus();
        await page.evaluate(() => {
            window.first = AppLoadingOverlay.run('First task', () => new Promise(resolve => { window.finishFirst = resolve; }));
            window.second = AppLoadingOverlay.run('Second task', () => new Promise(resolve => { window.finishSecond = resolve; }));
        });
        assert.equal(await visible(page), true, 'Loading must appear immediately.');
        await page.waitForFunction(() => window.finishFirst && window.finishSecond);
        await page.evaluate(() => window.finishFirst());
        assert.equal(await visible(page), true, 'One operation must not release another operation.');
        assert.equal(await page.locator('#app-global-loading-text').textContent(), 'Second task');
        await page.keyboard.press('Escape');
        assert.equal(await visible(page), true, 'Escape must not dismiss pending work.');
        await page.evaluate(() => window.finishSecond());
        await page.waitForFunction(() => !document.getElementById('app-global-loading-overlay').open);
        assert.equal(await page.locator('#start').evaluate(el => el === document.activeElement), true, 'Focus must return.');
        assert.equal(await page.locator('body').getAttribute('aria-busy'), null);
        await page.evaluate(() => {
            const oldRelease = AppLoadingOverlay.show('Outer manual task');
            const completedRelease = AppLoadingOverlay.show('Completed manual task');
            completedRelease();
            AppLoadingOverlay.hide();
            oldRelease();
        });
        assert.equal(await visible(page), false, 'Owned releases must also leave legacy show/hide balanced.');

        await page.evaluate(() => {
            document.getElementById('review').showModal();
            document.getElementById('confirm').focus();
            window.releaseModal = AppLoadingOverlay.show('Saving decision');
        });
        assert.equal(await page.evaluate(() => document.elementFromPoint(innerWidth / 2, innerHeight / 2).closest('#app-global-loading-overlay') !== null), true, 'Loading must cover native dialogs.');
        await page.keyboard.press('Tab');
        assert.equal(await page.evaluate(() => document.activeElement.closest('#app-global-loading-overlay') !== null || document.activeElement === document.body), true, 'Keyboard focus must not enter the underlying review dialog.');
        await page.evaluate(() => {
            window.releaseModal();
            document.getElementById('review').close();
            window.releaseDedicated = AppLoadingOverlay.show('Dedicated operation');
            document.getElementById('bulk-register-loading').classList.add('active');
        });
        assert.equal(await visible(page), false, 'Dedicated progress screens must suppress the global screen.');
        await page.evaluate(() => document.getElementById('bulk-register-loading').classList.remove('active'));
        assert.equal(await visible(page), true);
        await page.evaluate(() => window.releaseDedicated());

        await page.evaluate(() => { window.bodyTask = fetch('../api/slow.php').then(response => response.json()); });
        assert.equal(await visible(page), true, 'Body consumption must remain tracked after headers.');
        await page.screenshot();
        const before = await page.locator('.hourglassContainer').evaluate(el => el.getAnimations()[0].currentTime);
        await page.waitForTimeout(120);
        const after = await page.locator('.hourglassContainer').evaluate(el => el.getAnimations()[0].currentTime);
        assert(after > before, 'The hourglass must animate while awaiting the response: ' + before + ' -> ' + after);
        await page.evaluate(() => window.bodyTask);
        assert.equal(await visible(page), false);
        for (const query of ['_background=1', '_heartbeat=1']) {
            await page.evaluate(query => { window.silent = fetch('../api/silent.php?' + query).then(r => r.json()); }, query);
            assert.equal(await visible(page), false, query + ' must remain quiet.');
            await page.evaluate(() => window.silent);
        }
        for (const url of ['reject', 'abort']) {
            const name = await page.evaluate(async url => {
                try { await fetch('../api/' + url + '.php'); } catch (error) { return error.name; }
            }, url);
            assert(name);
            assert.equal(await visible(page), false, 'Failed requests must clean up.');
        }
        assert.equal(await page.evaluate(async () => {
            try { await AppLoadingOverlay.run('Failure', () => { throw new Error('Fixture'); }); } catch (error) { return error.message; }
        }), 'Fixture');
        assert.equal(await visible(page), false);

        await page.evaluate(() => {
            document.getElementById('start').addEventListener('click', async () => {
                await fetch('../api/silent.php?_background=1').then(r => r.json());
                document.getElementById('start').textContent = 'Done';
            });
        });
        await page.locator('#start').click();
        assert.equal(await visible(page), true, 'Foreground handlers must cover silent data helpers.');
        await page.waitForFunction(() => document.getElementById('start').textContent === 'Done');
        await page.waitForFunction(() => !document.getElementById('app-global-loading-overlay').open);

        await page.evaluate(() => {
            const button = document.createElement('button');
            button.id = 'property-action';
            button.textContent = 'Property handler';
            button.onclick = async () => {
                await fetch('../api/silent.php?_background=1').then(r => r.json());
                await new Promise(resolve => { window.finishRendering = resolve; });
                button.textContent = 'Rendered';
            };
            document.body.appendChild(button);
        });
        await page.locator('#property-action').click();
        assert.equal(await visible(page), true);
        assert.equal(await page.locator('#property-action').isDisabled(), true);
        await page.waitForFunction(() => window.finishRendering);
        assert.equal(await visible(page), true, 'Loading must continue through rendering after the response body.');
        await page.evaluate(() => window.finishRendering());
        await page.waitForFunction(() => !document.getElementById('app-global-loading-overlay').open);
        assert.equal(await page.locator('#property-action').isDisabled(), false);

        await page.evaluate(() => {
            const button = document.createElement('button');
            button.id = 'legacy-action';
            button.textContent = 'Legacy handler';
            button.addEventListener('click', () => {
                window.legacyTask = fetch('../api/silent.php?_background=1').then(r => r.json());
            });
            document.body.appendChild(button);
        });
        await page.locator('#legacy-action').click();
        assert.equal(await visible(page), true, 'Explicit actions must promote normally silent snapshots.');
        await page.evaluate(() => window.legacyTask);
        assert.equal(await visible(page), false);

        await page.evaluate(() => {
            const button = document.createElement('button');
            button.id = 'inline-action';
            button.textContent = 'Inline handler';
            window.inlineTask = () => AppLoadingOverlay.run('Inline action', () => new Promise(resolve => { window.finishInline = resolve; }));
            button.setAttribute('onclick', 'return inlineTask()');
            document.body.appendChild(button);
        });
        await page.locator('#inline-action').click();
        await page.waitForFunction(() => window.finishInline);
        assert.equal(await visible(page), true);
        await page.evaluate(() => window.finishInline());
        await page.waitForFunction(() => !document.getElementById('app-global-loading-overlay').open);

        await page.evaluate(() => {
            window.releasePreview = AppLoadingOverlay.show('Loading, please wait...');
            const card = document.querySelector('.app-loading-overlay-card');
            card.innerHTML = ['compact', 'small', 'tiny'].map(size => AppHourglassMarkup(size)).join('') + '<p>Processing your request...</p>';
        });
        const artifactDir = path.join(os.tmpdir(), 'naap-loading-ui');
        fs.mkdirSync(artifactDir, { recursive: true });
        for (const time of [0, 500, 1000, 1500]) {
            await page.evaluate(time => document.querySelectorAll('.hourglassContainer').forEach(el => {
                el.getAnimations().forEach(animation => { animation.pause(); animation.currentTime = time; });
            }), time);
            assert(await page.locator('.hourglassContainer').first().evaluate(el => getComputedStyle(el.querySelector('.hourglassCapTop'), '::before').content === 'none'));
            await page.locator('.app-loading-overlay-card').screenshot({ path: path.join(artifactDir, 'hourglass-' + time + '.png') });
        }
        await page.setViewportSize({ width: 390, height: 844 });
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth));
        await page.screenshot({ path: path.join(artifactDir, 'mobile.png') });
        await page.emulateMedia({ reducedMotion: 'reduce' });
        assert.equal(await page.locator('.hourglassContainer').first().evaluate(el => getComputedStyle(el).animationName), 'none');
        assert.equal(await page.locator('.hourglassSand').first().evaluate(el => getComputedStyle(el, '::before').height), '17px');
        assert.deepEqual(errors, []);
        console.log('Universal loader browser tests passed: concurrency, failures, aborts, response bodies, dialogs, focus, background work, animation and mobile/reduced-motion layout.');
    } finally { await browser.close(); }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
