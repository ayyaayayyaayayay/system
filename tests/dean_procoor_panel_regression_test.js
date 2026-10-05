const assert = require('assert');
const fs = require('fs');
const path = require('path');
const vm = require('vm');

const root = path.resolve(__dirname, '..');
const read = relativePath => fs.readFileSync(path.join(root, relativePath), 'utf8');
const panel = read('JsScrip/daenpanel.js');
const sharedData = read('JsScrip/db-data.js');
const appState = read('api/app_state.php');
const stateHelpers = read('api/state_helpers.php');
const deanHtml = read('html/daenpanel.html');
const coordinatorHtml = read('html/procoorpanel.html');

function sourceBetween(source, startMarker, endMarker) {
    const start = source.indexOf(startMarker);
    const end = source.indexOf(endMarker, start + startMarker.length);
    assert(start >= 0 && end > start, `Unable to extract ${startMarker}.`);
    return source.slice(start, end);
}

const calculationContext = {};
vm.createContext(calculationContext);
vm.runInContext(
    sourceBetween(
        panel,
        'function computeAverageRatingFromEvaluations(',
        '\nfunction formatDisplaySection('
    ),
    calculationContext
);
assert.strictEqual(calculationContext.computeAverageRatingFromEvaluations([]), null);
assert.strictEqual(calculationContext.computeAverageRatingFromEvaluations([{ ratings: {} }]), null);
assert.strictEqual(
    calculationContext.computeAverageRatingFromEvaluations([{ ratings: { q1: 4, q2: 5 } }]),
    4.5
);
assert.strictEqual(calculationContext.computeDistributionAverage({ 1: 0, 5: 0 }), null);
assert(panel.includes('averageRating: null'), 'Empty evaluation summaries still expose a numeric average.');

const tbody = { innerHTML: '' };
const table = { querySelector: selector => selector === 'tbody' ? tbody : null };
const renderContext = {
    document: {
        getElementById: id => id === 'facultyResponseCountsTable' ? table : null,
    },
};
vm.createContext(renderContext);
vm.runInContext(
    sourceBetween(panel, 'function escapeHTML(', '\nfunction ensureQuestionnairePrivacyConsentForSubmission(')
        + '\n'
        + sourceBetween(panel, 'function renderFacultyResponseTable(', '\nfunction fetchFacultyCommentsFromSql('),
    renderContext
);
const injection = '\"><img src=x onerror="globalThis.deanPanelInjected=true">';
renderContext.renderFacultyResponseTable([{
    professorId: injection,
    professorUserId: injection,
    professorName: injection,
    institute: injection,
    employmentType: injection,
    position: injection,
    received: 0,
    required: 0,
    avgScore: null,
    status: 'active',
}]);
assert(!tbody.innerHTML.includes('<img'), 'Faculty response rows still accept executable HTML.');
assert(tbody.innerHTML.includes('&lt;img'), 'Faculty response rows do not encode untrusted HTML.');
assert(tbody.innerHTML.includes('Average Score">N/A</td>'), 'No-response averages are not rendered as N/A.');

let sessionRole = 'dean';
let userDirectoryRequests = 0;
const sharedDataContext = {
    state: { users: [] },
    usersLastSyncedAt: 0,
    USERS_CACHE_TTL_MS: 0,
    startBootstrap: () => {},
    getSession: () => ({ role: sessionRole }),
    isBootstrapDatasetPartial: () => true,
    scheduleUsersRefresh: () => { userDirectoryRequests += 1; },
};
vm.createContext(sharedDataContext);
vm.runInContext(
    sourceBetween(sharedData, 'function getUsers()', '\n    function getCachedUsers()'),
    sharedDataContext
);
sharedDataContext.getUsers();
sessionRole = 'procoor';
sharedDataContext.getUsers();
assert.strictEqual(userDirectoryRequests, 0, 'Dean/coordinator panels still request the forbidden user directory.');
sessionRole = 'admin';
sharedDataContext.getUsers();
assert.strictEqual(userDirectoryRequests, 1, 'Authorized roles can no longer refresh the user directory.');

assert(
    panel.includes('SharedData[detailMethod]({ semesterId: selectedSemester })'),
    'Peer assignment details are not scoped to the selected semester.'
);
assert(
    panel.includes('professor && professor.incomingCount'),
    'Peer denominators do not use assignments received by each professor.'
);
assert(
    !panel.includes('Math.max(scopedProfessors.length - 1, 0)'),
    'The fabricated all-other-professors peer denominator is still present.'
);
assert(
    sharedData.includes("const canRequestUserDirectory = ['admin', 'hr', 'vpaa', 'osa'].includes(sessionRole)"),
    'Restricted panel roles can still trigger the privileged user-directory request.'
);
assert(
    appState.includes("$body['semesterId'] ?? ($body['semester'] ?? '')")
        && appState.includes('listDeanProgramPeerAssignmentDetailsCurrentSnapshot($pdo, $deanUserId, $programCode, $semesterId)')
        && appState.includes('listCoordinatorProgramPeerAssignmentDetailsCurrentSnapshot($pdo, $coordinatorUserId, $programCode, $semesterId)'),
    'Peer-detail API routes do not pass the selected semester to the snapshot helpers.'
);
assert(
    stateHelpers.includes('function resolvePeerAssignmentSemesterRowSnapshot(PDO $pdo, $semesterValue = \'\')')
        && stateHelpers.includes("function listDeanProgramPeerAssignmentDetailsCurrentSnapshot(PDO $pdo, $deanUserId, $programCode = '', $semesterValue = '')")
        && stateHelpers.includes("function listCoordinatorProgramPeerAssignmentDetailsCurrentSnapshot(PDO $pdo, $coordinatorUserId, $programCode = '', $semesterValue = '')"),
    'Historical peer-assignment snapshot support is missing.'
);
assert(!coordinatorHtml.includes('both professor and dean'), 'Coordinator ownership copy still identifies the dean.');
assert(!coordinatorHtml.includes('Review dean details'), 'Coordinator profile copy still identifies the dean.');
assert(!deanHtml.includes('/api/dean/peer-evaluations/submit'), 'The dean page still exposes a debug endpoint.');
assert(!coordinatorHtml.includes('/api/dean/peer-evaluations/submit'), 'The coordinator page still exposes a dean endpoint.');
assert(!panel.includes('/api/dean/supervisor-evaluations/submit'), 'Shared panel setup still exposes a dean endpoint.');

console.log('Dean/program coordinator panel regression tests passed.');
