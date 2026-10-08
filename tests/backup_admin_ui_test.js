const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const html = fs.readFileSync(path.join(root, 'html', 'adminpanel.html'), 'utf8');
const script = fs.readFileSync(path.join(root, 'JsScrip', 'adminpanel.js'), 'utf8');

function assert(condition, message) {
    if (!condition) throw new Error(message);
}

assert(html.includes('id="backup-management-group"'), 'Backup management card is missing.');
assert(html.includes('id="backup-history-body"'), 'Backup history table is missing.');
assert(html.includes('id="backup-last-24-btn"'), 'Manual backup action is missing.');
// These are the minimum asset versions containing the backup UI, not exact
// versions: later releases must continue to pass this regression check.
function assertAssetVersion(assetPath, minimumVersion, label) {
    const baseUrl = 'http://localhost/system/html/';
    const assetUrl = new URL(assetPath, baseUrl);
    const references = Array.from(html.matchAll(/\b(?:src|href)=["']([^"']+)["']/g),
        match => new URL(match[1], baseUrl));
    const reference = references.find(url => url.pathname === assetUrl.pathname);
    const version = reference && reference.searchParams.get('v');
    assert(version && /^\d{8}[a-z0-9]*$/i.test(version)
        && version.toLowerCase() >= minimumVersion.toLowerCase(),
        `${label} cache-buster is missing or stale.`);
}

assertAssetVersion('../JsScrip/adminpanel.js', '20261006cmo', 'Admin backup JavaScript');
assertAssetVersion('../css/adminpanel.css', '20261006a', 'Admin backup CSS');

assert(script.includes("requestBackupApi('create'"), 'Manual backup button is not connected to the backend.');
assert(script.includes("requestBackupApi('test'"), 'Restoration test action is not connected to the backend.');
assert(script.includes("requestBackupApi('download-ticket'"), 'Secure download action is not connected to the backend.');
assert(script.includes("case 'system-backup':") && script.includes("document.getElementById('backup-management-group')"), 'Dashboard backup quick action does not focus the real controls.');
assert(!script.includes("alert('Backup"), 'A fake backup alert remains in the Admin UI.');
assert(script.includes('escapeHtml(backup.id') && script.includes('escapeHtml(error)'), 'Backup history values are not safely escaped.');

console.log('Admin encrypted-backup UI integration checks passed.');

