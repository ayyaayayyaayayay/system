const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '..', 'JsScrip', 'db-data.js'), 'utf8');
const sharedSource = source.slice(source.indexOf('const SharedData = (() => {'));
const IDLE_MS = 600000;
const createStorage = () => {
    const values = new Map();
    return {
        getItem: key => values.has(key) ? values.get(key) : null,
        setItem: (key, value) => values.set(key, String(value)),
        removeItem: key => values.delete(key),
    };
};
const flush = async () => { for (let i = 0; i < 20; i++) await Promise.resolve(); };

async function createPage(role, options = {}) {
    const clock = options.clock || { now: Date.UTC(2026, 9, 6, 4) };
    const storage = options.storage || createStorage();
    const session = options.session || {
        username: 'Idle fixture', userId: 'u1', role,
        loginTime: new Date(clock.now).toISOString(), csrfToken: 'fixture-token',
    };
    const listeners = new Map();
    const timers = new Map();
    const requests = [];
    let nextTimer = 1;
    let deferBootstrap = false;
    let releaseBootstrap;
    const registerTimer = (fn, delay, interval) => {
        const id = nextTimer++;
        timers.set(id, { fn, at: clock.now + delay, interval });
        return id;
    };
    class FakeDate extends Date {
        constructor(...args) { super(...(args.length ? args : [clock.now])); }
        static now() { return clock.now; }
    }
    const document = {
        hidden: false,
        addEventListener: (name, fn) => listeners.set(name, fn),
    };
    const window = {
        localStorage: storage, sessionStorage: createStorage(),
        location: { pathname: `/system/html/${role === 'dean' ? 'daen' : role}panel.html`, href: 'panel.html' },
        dispatchEvent() {},
        setTimeout: (fn, delay) => registerTimer(fn, delay, 0),
        clearTimeout: id => timers.delete(id),
        setInterval: (fn, delay) => registerTimer(fn, delay, delay),
        clearInterval: id => timers.delete(id),
    };
    const context = vm.createContext({
        window, document, Date: FakeDate, console,
        CustomEvent: class { constructor(name, config) { this.type = name; this.detail = config.detail; } },
        fetch: (url, config) => {
            const action = config.body ? JSON.parse(config.body).action : 'bootstrap';
            requests.push({ url, action, config });
            const data = action === 'logout' ? { success: true } : { success: true, state: {}, session };
            const response = { ok: true, status: 200, text: async () => JSON.stringify(data) };
            if (action === 'bootstrap' && deferBootstrap) {
                return new Promise(resolve => { releaseBootstrap = () => resolve(response); });
            }
            return Promise.resolve(response);
        },
    });
    vm.runInContext(sharedSource + '\nthis.data = SharedData;', context);
    await flush();
    return {
        clock, storage, session, requests, timers, document, window, data: context.data,
        fire(name) { assert.ok(listeners.has(name)); listeners.get(name)(); },
        deferBootstrap() { deferBootstrap = true; },
        releaseBootstrap() { releaseBootstrap(); },
        async advance(ms) {
            const target = clock.now + ms;
            while (true) {
                const next = [...timers.entries()].filter(([, t]) => t.at <= target)
                    .sort((a, b) => a[1].at - b[1].at)[0];
                if (!next) break;
                const [id, timer] = next;
                clock.now = Math.max(clock.now, timer.at);
                if (timer.interval) timer.at = clock.now + timer.interval;
                else timers.delete(id);
                timer.fn();
                await flush();
            }
            clock.now = target;
            await flush();
        },
    };
}

function assertLoggedOut(page) {
    assert.equal(page.data.getSession(), null);
    assert.equal(page.window.location.href, 'mainpage.html');
    assert.equal(page.requests.filter(r => r.action === 'logout').length, 1);
    assert.equal(page.timers.size, 0, 'Logout must stop both heartbeat and idle timers.');
}

async function main() {
    for (const role of ['student', 'professor', 'dean', 'procoor', 'hr', 'vpaa', 'osa']) {
        const page = await createPage(role);
        await page.advance(IDLE_MS - 1);
        assert.ok(page.data.getSession(), `${role} must remain signed in before ten minutes.`);
        await page.advance(1);
        assertLoggedOut(page);
        assert.equal(page.requests.at(-1).config.keepalive, true);
    }

    const admin = await createPage('admin');
    await admin.advance(IDLE_MS * 12);
    assert.ok(admin.data.getSession(), 'Only Admin must remain signed in when idle.');
    assert.equal(admin.requests.filter(r => r.action === 'logout').length, 0);
    assert.ok(admin.requests.filter(r => r.action === 'heartbeat').length >= 59,
        'Idle Admin panels must keep the server session alive with regular heartbeats.');

    for (const event of ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click']) {
        const page = await createPage('student');
        await page.advance(IDLE_MS - 60000);
        page.fire(event);
        await flush();
        await page.advance(IDLE_MS - 1);
        assert.ok(page.data.getSession(), `${event} must restart the inactivity window.`);
        await page.advance(1);
        assertLoggedOut(page);
    }

    for (const event of ['click', 'visibilitychange']) {
        const suspended = await createPage('hr');
        suspended.clock.now += IDLE_MS + 1; // Browser timers paused while the tab was suspended.
        suspended.fire(event);
        await flush();
        assertLoggedOut(suspended);
        assert.equal(suspended.requests.filter(r => r.action === 'heartbeat').length, 0);
    }

    const visibility = await createPage('osa');
    await visibility.advance(IDLE_MS - 60000);
    visibility.fire('visibilitychange');
    await flush();
    await visibility.advance(60000);
    assertLoggedOut(visibility);

    const background = await createPage('vpaa');
    await background.advance(IDLE_MS - 60000);
    await background.data.refreshBootstrap(true);
    await background.advance(60000);
    assertLoggedOut(background);

    const original = await createPage('professor');
    await original.advance(IDLE_MS - 60000);
    const reload = await createPage('professor', original);
    await reload.advance(59999);
    assert.ok(reload.data.getSession(), 'Reload must preserve the existing inactivity deadline.');
    await reload.advance(1);
    assertLoggedOut(reload);

    const tab = await createPage('student');
    const otherTab = await createPage('student', tab);
    await tab.advance(IDLE_MS - 60000);
    otherTab.fire('keydown');
    await flush();
    await tab.advance(60000);
    assert.ok(tab.data.getSession(), 'Activity in another tab must keep the same session active.');
    await tab.advance(IDLE_MS - 60000);
    assertLoggedOut(tab);

    const stale = await createPage('dean');
    await stale.advance(IDLE_MS - 60000);
    stale.deferBootstrap();
    const pending = stale.data.refreshBootstrap(true);
    await stale.advance(60000);
    assertLoggedOut(stale);
    stale.releaseBootstrap();
    await pending;
    assert.equal(stale.data.getSession(), null, 'A late background response must not restore the expired session.');

    const backingStorage = createStorage();
    const failedActivityStorage = {
        ...backingStorage,
        setItem(key, value) {
            if (key === 'naapSessionActivity') throw new Error('Activity storage unavailable');
            backingStorage.setItem(key, value);
        },
    };
    const fallback = await createPage('hr', { storage: failedActivityStorage });
    await fallback.advance(IDLE_MS);
    assertLoggedOut(fallback);

    const missingCsrf = await createPage('osa', {
        session: { username: 'Fixture', userId: 'u2', role: 'osa', loginTime: 'fixture-login' },
    });
    await missingCsrf.advance(IDLE_MS);
    assertLoggedOut(missingCsrf);

    const newLogin = await createPage('student');
    await newLogin.advance(IDLE_MS - 60000);
    await newLogin.data.clearSession();
    newLogin.session.loginTime = new Date(newLogin.clock.now).toISOString();
    newLogin.data.setSession(newLogin.session);
    await newLogin.advance(IDLE_MS - 1);
    assert.ok(newLogin.data.getSession(), 'A fresh login must receive its own inactivity window.');
    await newLogin.advance(1);
    assert.equal(newLogin.data.getSession(), null);
    assert.equal(newLogin.requests.filter(r => r.action === 'logout').length, 2);

    for (const file of fs.readdirSync(path.join(__dirname, '..', 'html')).filter(f => f.endsWith('panel.html'))) {
        const html = fs.readFileSync(path.join(__dirname, '..', 'html', file), 'utf8');
        assert.match(html, /db-data\.js\?[^"']*idle=20261007b/, `${file} must load the updated inactivity code.`);
    }
    console.log('Session inactivity tests passed: all seven non-admin roles, Admin exemption, activity, suspended tabs, background requests, reloads, multiple tabs, and late responses.');
}

main().catch(error => { console.error(error); process.exitCode = 1; });
