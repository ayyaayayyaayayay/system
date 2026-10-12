'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../.codex/credibility-ui-test/node_modules/playwright');
const root = path.resolve(__dirname, '..');
const panels = { admin: 'adminpanel', hr: 'hrpanel', vpaa: 'vpaapanel', dean: 'daenpanel', procoor: 'procoorpanel', professor: 'profesorpanel', student: 'studentpanel', osa: 'osapanel', login: 'mainpage' };

async function main() {
    const browser = await chromium.launch({ channel: 'chrome', headless: true });
    try {
        for (const [role, panel] of Object.entries(panels)) {
            const page = await browser.newPage();
            const errors = [];
            const requests = [];
            let slowUserRequests = false;
            let slowLoginRequests = false;
            page.on('pageerror', error => errors.push(error.message));
            page.on('console', message => { if (message.type() === 'error' && /TypeError|ReferenceError|Action failed/.test(message.text())) errors.push(message.text()); });
            page.on('dialog', dialog => dialog.dismiss());
            const session = { userId: 'u10', username: 'fixture', fullName: 'Fixture User', employeeId: 'FIXTURE', studentNumber: 'FIXTURE', role, status: 'active', isAuthenticated: true, csrfToken: 'fixture-token', department: 'ICS', campus: 'villamor', departmentCodes: ['ICS'] };
            const users = [{ id: 'u10', username: 'fixture', name: 'Fixture User', fullName: 'Fixture User', employeeId: 'FIXTURE', studentNumber: 'FIXTURE', role, status: 'active', isActive: true, department: 'ICS', departmentCode: 'ICS', campus: 'villamor', campusSlug: 'villamor', email: 'fixture@example.test' }];
            const summary = { criteriaAverages: [], breakdownRows: [], subjects: [], ratingDistribution: {}, detailedRows: [], comments: [], totals: { required: 0, received: 0, responseRate: 0, averageScore: null } };
            const state = { users, campuses: [{ id: 'villamor', slug: 'villamor', name: 'Villamor', departments: ['ICS'] }], programs: [], currentSemester: 'fixture-term', semesterList: [{ value: 'fixture-term', label: 'Fixture Semester' }], questionnaires: {}, evaluations: [], announcements: [], subjectManagement: { subjects: [], offerings: [], enrollments: [] }, settings: {}, evalPeriods: {}, facultyAcknowledgementPapers: [], facultyReportAccess: { enabled: true, evaluationPeriodsComplete: true } };
            await page.addInitScript(() => {
                class ChartStub {
                    constructor(_element, config) { this.data = config.data; this.options = config.options; this.config = config; }
                    destroy() {} update() {} resize() {}
                    static getChart() { return null; }
                    static register() {}
                }
                ChartStub.defaults = { font: {}, plugins: { legend: {}, tooltip: {} } };
                window.Chart = ChartStub;
                const nativeOpen = XMLHttpRequest.prototype.open;
                XMLHttpRequest.prototype.open = function (method, url, async) {
                    if (async === false) throw new Error('Synchronous request: ' + url);
                    return nativeOpen.apply(this, arguments);
                };
            });
            await page.route('**/*', async route => {
                const url = new URL(route.request().url());
                if (url.origin !== 'https://panels.test') {
                    return route.fulfill({ contentType: 'application/javascript', body: '' });
                }
                if (url.pathname.includes('/api/')) {
                    let body = {};
                    try { body = route.request().postDataJSON() || {}; } catch (_error) {}
                    const action = url.searchParams.get('action') || body.action || '';
                    requests.push(action);
                    const response = { success: true, authenticated: true, session, users, total: users.length, evaluations: [], items: [], counts: {}, filteredTotal: 0, programs: [], assignments: [], papers: [], access: state.facultyReportAccess, facultyReportAccess: state.facultyReportAccess, config: {}, settings: {}, subjectManagement: state.subjectManagement };
                    if (action === 'bootstrap') response.state = state;
                    if (action === 'getProfessorAnalyticsPayload') response.payload = { student: summary, professor: summary, supervisor: summary, studentSummary: summary, peerSummary: summary, supervisorSummary: summary };
                    if (action === 'getOpenAiPanelAccess') { response.enabled = true; response.role = role; }
                    if (role === 'login') {
                        response.authenticated = false;
                        response.session = null;
                        if (action === 'login') {
                            response.success = false;
                            response.error = 'Invalid fixture credentials.';
                            if (slowLoginRequests) await new Promise(resolve => setTimeout(resolve, 700));
                        }
                    }
                    if (action === 'listUsers' && ['admin', 'hr'].includes(role) && Number(body.filters && body.filters.limit) > 0) {
                        const limit = Number(body.filters.limit);
                        const requestedPage = Number(body.filters.page) || 1;
                        Object.assign(response, { total: limit * 2, limit, offset: (requestedPage - 1) * limit, page: requestedPage, hasMore: requestedPage === 1 });
                    }
                    if (action === 'listUsers' && slowUserRequests) await new Promise(resolve => setTimeout(resolve, 700));
                    return route.fulfill({ contentType: 'application/json', body: JSON.stringify(response) });
                }
                const relative = decodeURIComponent(url.pathname.replace(/^\/system\//, ''));
                const target = path.resolve(root, relative);
                if (!target.startsWith(root + path.sep) || !fs.existsSync(target) || !fs.statSync(target).isFile()) return route.fulfill({ status: 404, body: '' });
                const extension = path.extname(target);
                const contentType = { '.html': 'text/html', '.js': 'application/javascript', '.css': 'text/css', '.png': 'image/png', '.ico': 'image/x-icon' }[extension] || 'application/octet-stream';
                return route.fulfill({ contentType, body: fs.readFileSync(target) });
            });
            await page.goto('https://panels.test/system/html/' + panel + '.html', { waitUntil: 'networkidle' });
            await page.waitForFunction(() => document.getElementById('app-global-loading-overlay') && !document.getElementById('app-global-loading-overlay').open, { timeout: 15000 });
            assert(page.url().includes(panel + '.html'), role + ' must retain its authorized dashboard.');
            assert(requests.includes('session'), role + ' must validate its session asynchronously.');
            assert.deepEqual(errors, [], role + ' startup errors');
            if (role === 'login') {
                slowLoginRequests = true;
                await page.locator('#username').fill('fixture');
                await page.locator('#password').fill('fixture-password');
                assert.equal(await page.locator('#app-global-loading-overlay').isVisible(), false, 'Login typing must stay quiet.');
                for (let attempt = 0; attempt < 2; attempt += 1) {
                    await page.locator('#loginBtn').click();
                    assert.equal(await page.locator('#app-global-loading-overlay').isVisible(), true, 'Login processing must show immediate loading.');
                    assert.equal(await page.locator('#loginBtn').isDisabled(), true);
                    await page.waitForFunction(() => !document.getElementById('app-global-loading-overlay').open);
                    assert.equal(await page.locator('#loginBtn').isDisabled(), false, 'Login failures must allow retries.');
                }
                assert.deepEqual(errors, [], 'Login runtime errors');
                await page.close();
                console.log('PASS login async session, foreground loading, failure cleanup and retry.');
                continue;
            }
            // Data refreshes must not create a second blocking screen.
            await page.evaluate(() => SharedData.refreshEvaluations({}));
            assert.equal(await page.locator('#app-global-loading-overlay').isVisible(), false, role + ' background refresh must stay quiet: ' + await page.locator('#app-global-loading-text').textContent() + ' / ' + requests.slice(-8).join(', '));
            if (role === 'admin' || role === 'hr') {
                await page.locator('a.nav-link[data-view="users"]').click();
                if (role === 'admin') await page.evaluate(() => { adminUsersPageSize = 50; return loadUsersByOrganization(); });
                await page.waitForFunction(() => !document.getElementById('app-global-loading-overlay').open);
                slowUserRequests = true;
                if (role === 'admin') {
                    await page.locator('#user-role-filter').selectOption('student');
                    assert.equal(await page.locator('#app-global-loading-overlay').isVisible(), true, 'Admin filtering must show immediate loading.');
                    await page.waitForFunction(() => !document.getElementById('app-global-loading-overlay').open);
                    await page.locator('#user-search').fill('fixture');
                    assert.equal(await page.locator('#app-global-loading-overlay').isVisible(), false, 'Typing must remain uninterrupted.');
                    await page.locator('#user-search').press('Enter');
                    assert.equal(await page.locator('#app-global-loading-overlay').isVisible(), true, 'Keyboard search must show loading.');
                } else {
                    await page.locator('#professor-search-btn').click();
                    assert.equal(await page.locator('#app-global-loading-overlay').isVisible(), true, 'HR explicit search must show loading.');
                }
                await page.waitForFunction(() => !document.getElementById('app-global-loading-overlay').open);
                if (role === 'admin') {
                    await page.locator('#admin-user-next-page').click();
                    assert.equal(await page.locator('#app-global-loading-overlay').isVisible(), true, 'Admin pagination must show loading.');
                    await page.waitForFunction(() => !document.getElementById('app-global-loading-overlay').open);
                }
                assert.deepEqual(errors, [], role + ' foreground errors');
            }
            await page.close();
            console.log('PASS ' + role + ' dashboard startup, async session and quiet data refresh.');
        }
    } finally { await browser.close(); }
}

main().catch(error => { console.error(error); process.exitCode = 1; });
