const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(__dirname + '/../JsScrip/adminpanel.js', 'utf8');
const elements = {};
for (const id of ['backup-upload-file', 'backup-upload-btn', 'backup-upload-review', 'backup-restore-ack',
    'backup-restore-confirmation', 'backup-restore-btn', 'backup-upload-cancel-btn', 'backup-upload-feedback',
    'backup-restore-signin', 'backup-upload-name', 'backup-upload-code', 'backup-upload-created',
    'backup-upload-contents', 'backup-restore-phrase']) {
    elements[id] = { files: [], value: '', disabled: false, checked: false, hidden: true, textContent: '',
        addEventListener(event, handler) { this[event] = handler; } };
}
const calls = [];
let expire;
let failUpload = false;
let failRestore = false;
let signedOut = false;
const review = { token: 'opaque-token', backupCode: 'BKP-UI-TEST', filename: '<script>backup</script>.naapbak',
    createdAt: '2026-10-08T08:00:00+08:00', tableCount: 8, fileCount: 1, sizeBytes: 500, testStatus: 'passed' };
class TestFormData { append(name, file) { this.name = name; this.file = file; } }
const context = {
    document: { getElementById: id => elements[id] }, FormData: TestFormData,
    backupOperationInFlight: false, setTimeout(handler) { expire = handler; return 1; }, clearTimeout() {},
    SharedData: { clearSession(options) { assert.equal(options.localOnly, true); signedOut = true; } },
    formatBackupTimestamp: value => value, formatBackupBytes: value => `${value} B`,
    async requestBackupUploadApi(action, payload) {
        calls.push({ action, payload });
        if (action === 'upload') {
            if (failUpload) throw new Error('Original encryption key does not match.');
            assert.ok(payload instanceof TestFormData);
            return { review: { ...review } };
        }
        if (action === 'restore' && failRestore) {
            const error = new Error('Safety backup failed.'); error.reviewConsumed = true; throw error;
        }
        return { success: true };
    },
};
const start = source.indexOf('function setupBackupUploadUI() {');
const end = source.indexOf('function normalizeBackupBadgeStatus(', start);
vm.runInNewContext(source.slice(start, end), context);
context.setupBackupUploadUI();
const file = elements['backup-upload-file'];
const upload = elements['backup-upload-btn'];
const restore = elements['backup-restore-btn'];
const ack = elements['backup-restore-ack'];
const confirmation = elements['backup-restore-confirmation'];
const feedback = elements['backup-upload-feedback'];
const selectFile = () => { file.files = [{ name: 'backup.naapbak', size: 500 }]; file.change(); };
const confirm = () => { ack.checked = true; ack.change(); confirmation.value = 'RESTORE BKP-UI-TEST'; confirmation.input(); };

(async () => {
    assert.equal(upload.disabled, true);
    file.files = [{ name: 'backup.sql', size: 500 }]; file.change(); await upload.click();
    assert.equal(calls.length, 0, 'Invalid extensions must not upload');
    selectFile(); await upload.click();
    assert.equal(elements['backup-upload-review'].hidden, false);
    assert.equal(elements['backup-upload-name'].textContent, review.filename, 'Filename must be rendered as text');
    assert.equal(restore.disabled, true);
    confirmation.value = 'RESTORE BKP-UI-TEST'; confirmation.input(); await restore.click();
    assert.equal(calls.length, 1, 'Typing alone must not restore');
    ack.checked = true; ack.change(); confirmation.value = 'wrong'; confirmation.input();
    assert.equal(restore.disabled, true);
    confirm(); assert.equal(restore.disabled, false);
    await restore.click();
    assert.equal(calls.at(-1).action, 'restore');
    assert.equal(calls.at(-1).payload.confirmation, 'RESTORE BKP-UI-TEST');
    assert.equal(signedOut, true);
    assert.equal(elements['backup-restore-signin'].hidden, false);
    assert.equal(elements['backup-upload-review'].hidden, true);

    selectFile(); await upload.click(); confirm(); expire(); await restore.click();
    assert.equal(restore.disabled, true, 'Expired review must disable restoration');
    assert.match(feedback.textContent, /expired/);
    await elements['backup-upload-cancel-btn'].click();
    assert.equal(calls.at(-1).action, 'cancel');
    assert.equal(elements['backup-upload-review'].hidden, true);
    failUpload = true; selectFile(); await upload.click();
    assert.match(feedback.textContent, /encryption key/);
    assert.equal(restore.disabled, true);
    failUpload = false; failRestore = true; selectFile(); await upload.click(); confirm(); await restore.click();
    assert.match(feedback.textContent, /Safety backup failed/);
    assert.equal(elements['backup-upload-review'].hidden, true, 'Consumed review must not be reusable');

    // Exercise the actual shared transport for multipart and JSON requests.
    const transportCalls = [];
    const transport = { FormData: TestFormData, SharedData: { getSession: () => ({ csrfToken: 'csrf' }) },
        async fetch(url, options) { transportCalls.push({ url, options }); return { ok: true, text: async () => '{"success":true}' }; } };
    const transportStart = source.indexOf('function getBackupSession()');
    const transportEnd = source.indexOf('function setupBackupUploadUI()', transportStart);
    vm.runInNewContext(source.slice(transportStart, transportEnd), transport);
    await transport.requestBackupUploadApi('upload', new TestFormData());
    assert.match(transportCalls[0].url, /backup_upload_api\.php\?action=upload/);
    assert.equal(transportCalls[0].options.headers['X-CSRF-Token'], 'csrf');
    assert.equal(transportCalls[0].options.headers['Content-Type'], undefined, 'Browser must provide the multipart boundary');
    assert.equal(transportCalls[0].options.credentials, 'same-origin');
    await transport.requestBackupApi('list', {});
    assert.equal(transportCalls[1].options.headers['Content-Type'], 'application/json');
    console.log('Backup upload UI: file validation, verified review, typed confirmation, expiration, cancellation, failure handling, sign-out, and CSRF transport passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
