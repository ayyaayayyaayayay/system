// Professor Panel JavaScript - Dashboard Functionality

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function () {
    // Check authentication
    if (!checkAuthentication()) {
        redirectToLogin();
        return;
    }

    if (!enforceActiveProfessorAccount({ inline: false })) {
        return;
    }

    // Initialize the dashboard
    initializeDashboard();
});

let peerSectionFlow = {
    steps: [],
    activeIndex: 0
};

const PROFESSOR_PANEL_EMPTY_SUMMARY = {
    criteriaAverages: [],
    breakdownRows: [],
    ratingDistribution: { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 },
    comments: [],
    commentBuckets: {},
    detailedRows: [],
    totals: { required: 0, received: 0, responseRate: 0, averageScore: 0 },
};

const professorPanelState = {
    context: null,
    summaryByType: {
        student: { ...PROFESSOR_PANEL_EMPTY_SUMMARY },
        professor: { ...PROFESSOR_PANEL_EMPTY_SUMMARY },
        supervisor: { ...PROFESSOR_PANEL_EMPTY_SUMMARY },
    },
    currentSelection: {
        semesterId: '',
        semesterLabel: '',
        evaluationType: 'student',
    },
    facultyPaper: {
        filter: 'active',
        records: [],
        selectedId: '',
    },
    linked: false,
};

function normalizeToken(value) {
    return String(value || '').trim().toLowerCase();
}

function normalizeUserIdToken(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^u\d+$/i.test(raw)) return 'u' + raw.replace(/^u/i, '');
    if (/^\d+$/.test(raw)) return 'u' + String(parseInt(raw, 10));
    return normalizeToken(raw);
}

function clampNumber(value, min, max) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return min;
    return Math.max(min, Math.min(max, numeric));
}

function getEvaluationTypeMeta(type) {
    if (type === 'professor') {
        return {
            id: 'professor',
            label: 'Professor Evaluation',
            questionnaireType: 'professor-to-professor',
            chartPrefix: 'peer',
            emptyComments: 'No professor feedback available.',
        };
    }
    if (type === 'supervisor') {
        return {
            id: 'supervisor',
            label: 'Supervisor Evaluation',
            questionnaireType: 'supervisor-to-professor',
            chartPrefix: 'supervisor',
            emptyComments: 'No supervisor feedback available.',
        };
    }
    return {
        id: 'student',
        label: 'Student Evaluation',
        questionnaireType: 'student-to-professor',
        chartPrefix: 'student',
        emptyComments: 'No student feedback available.',
    };
}

function formatDisplaySection(sectionName) {
    const value = String(sectionName || '').trim();
    return value || 'N/A';
}

function formatSemesterLabelFromSlug(slug) {
    const value = String(slug || '').trim();
    if (!value) return 'Selected semester';
    return value
        .split('-')
        .map(part => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

function getSemesterLabelById(semesterId, semesterList) {
    const id = String(semesterId || '').trim();
    if (!id) return 'Selected semester';
    const list = Array.isArray(semesterList) ? semesterList : [];
    const match = list.find(item => String(item && item.value || '').trim() === id);
    if (match && match.label) return match.label;
    return formatSemesterLabelFromSlug(id);
}

function findCurrentSemesterId(semesterList, currentSemester) {
    const list = Array.isArray(semesterList) ? semesterList : [];
    const current = String(currentSemester || '').trim();
    if (current) return current;
    if (list.length) return String(list[0].value || '').trim();
    return '';
}

function buildProfessorLookupMaps(users) {
    const activeProfessors = (Array.isArray(users) ? users : []).filter(user =>
        normalizeToken(user && user.role) === 'professor' &&
        normalizeToken(user && user.status) !== 'inactive'
    );

    const byId = {};
    const byName = {};
    const byEmployeeId = {};
    const byEmail = {};

    activeProfessors.forEach(user => {
        const id = normalizeUserIdToken(user && user.id);
        if (!id) return;
        byId[id] = user;

        const name = normalizeToken(user && user.name);
        if (name && !byName[name]) byName[name] = id;

        const email = normalizeToken(user && user.email);
        if (email && !byEmail[email]) byEmail[email] = id;

        const employeeId = normalizeToken(user && user.employeeId);
        if (employeeId && !byEmployeeId[employeeId]) byEmployeeId[employeeId] = id;
    });

    return {
        activeProfessors,
        byId,
        byName,
        byEmail,
        byEmployeeId,
    };
}

function resolveCurrentProfessorUser(session, users, maps) {
    const activeProfessors = maps.activeProfessors || [];
    if (!activeProfessors.length) return null;

    const sessionUserId = normalizeUserIdToken(session && session.userId);
    if (sessionUserId && maps.byId[sessionUserId]) return maps.byId[sessionUserId];

    const sessionEmail = normalizeToken(session && session.email);
    if (sessionEmail && maps.byEmail[sessionEmail]) {
        return maps.byId[maps.byEmail[sessionEmail]];
    }

    const sessionEmployeeId = normalizeToken(session && session.employeeId);
    if (sessionEmployeeId && maps.byEmployeeId[sessionEmployeeId]) {
        return maps.byId[maps.byEmployeeId[sessionEmployeeId]];
    }

    const sessionUsername = normalizeToken(session && session.username);
    if (sessionUsername && maps.byName[sessionUsername]) {
        return maps.byId[maps.byName[sessionUsername]];
    }

    return null;
}

function resolveProfessorIdToken(candidate, context) {
    const maps = context.lookupMaps;
    const asUserId = normalizeUserIdToken(candidate);
    if (asUserId && maps.byId[asUserId]) return asUserId;

    const token = normalizeToken(candidate);
    if (!token) return '';
    if (maps.byEmployeeId[token]) return maps.byEmployeeId[token];
    if (maps.byName[token]) return maps.byName[token];
    if (maps.byEmail[token]) return maps.byEmail[token];

    if (token.includes(' - ')) {
        const head = normalizeToken(token.split(' - ')[0]);
        if (maps.byName[head]) return maps.byName[head];
    }

    return '';
}

function getAggregateEvaluationType(evaluation) {
    const rawType = normalizeToken(evaluation && (evaluation.evaluationType || evaluation.evaluatorRole));
    if (rawType === 'student' || rawType === 'student-to-professor' || rawType === 'student-professor') {
        return 'student';
    }
    if (rawType === 'peer' || rawType === 'professor' || rawType === 'professor-to-professor' || rawType === 'professor-professor') {
        return 'professor';
    }
    if (rawType === 'supervisor' || rawType === 'dean' || rawType === 'hr' || rawType === 'vpaa' || rawType === 'admin' || rawType === 'supervisor-to-professor' || rawType === 'supervisor-professor') {
        return 'supervisor';
    }
    return '';
}

function isEvaluationInSemester(evaluation, semesterId) {
    const selected = String(semesterId || '').trim();
    if (!selected || selected === 'all') return true;
    const evSemester = String(evaluation && evaluation.semesterId || '').trim();
    if (!evSemester) return true;
    return evSemester === selected;
}

function resolveEvaluationTargetProfessorId(evaluation, type, context) {
    if (type === 'student') {
        const offeringId = String(evaluation && evaluation.courseOfferingId || '').trim();
        if (offeringId && context.offeringsById[offeringId]) {
            const offering = context.offeringsById[offeringId];
            const professorToken = resolveProfessorIdToken(offering.professorUserId, context);
            if (professorToken) return professorToken;
        }
    }

    const candidates = [
        evaluation && evaluation.targetProfessorId,
        evaluation && evaluation.targetId,
        evaluation && evaluation.colleagueId,
        evaluation && evaluation.professorId,
        evaluation && evaluation.professorUserId,
        evaluation && evaluation.targetProfessor,
        evaluation && evaluation.professorSubject,
    ];

    for (let index = 0; index < candidates.length; index += 1) {
        const resolved = resolveProfessorIdToken(candidates[index], context);
        if (resolved) return resolved;
    }

    return '';
}

function getQuestionnaireBucket(context, type, semesterId) {
    const questionnaires = context.questionnaires || {};
    const meta = getEvaluationTypeMeta(type);
    const desiredSemester = String(semesterId || '').trim();
    const currentSemester = String(context.currentSemester || '').trim();

    const candidates = [];
    if (desiredSemester) candidates.push(desiredSemester);
    if (currentSemester && !candidates.includes(currentSemester)) candidates.push(currentSemester);
    Object.keys(questionnaires).forEach(key => {
        if (!candidates.includes(key)) candidates.push(key);
    });

    for (let index = 0; index < candidates.length; index += 1) {
        const semesterKey = candidates[index];
        if (questionnaires[semesterKey] && questionnaires[semesterKey][meta.questionnaireType]) {
            return questionnaires[semesterKey][meta.questionnaireType];
        }
    }

    return { sections: [], questions: [] };
}

function buildQuestionMeta(context, type, semesterId) {
    const bucket = getQuestionnaireBucket(context, type, semesterId);
    const sections = Array.isArray(bucket.sections) ? bucket.sections : [];
    const questions = Array.isArray(bucket.questions) ? bucket.questions : [];

    const sectionNameById = {};
    const categoryOrder = [];

    sections
        .slice()
        .sort((a, b) => (Number(a && a.order) || 0) - (Number(b && b.order) || 0))
        .forEach(section => {
            const sectionId = normalizeToken(section && section.id);
            const title = String(section && (section.title || section.letter) || '').trim() || 'Unassigned';
            if (sectionId) sectionNameById[sectionId] = title;
            if (!categoryOrder.includes(title)) categoryOrder.push(title);
        });

    const byQuestionId = {};
    questions
        .slice()
        .sort((a, b) => (Number(a && a.order) || 0) - (Number(b && b.order) || 0))
        .forEach(question => {
            const qid = normalizeToken(question && question.id);
            if (!qid) return;
            const sectionId = normalizeToken(question && question.sectionId);
            const category = sectionNameById[sectionId] || 'Unassigned';
            if (!categoryOrder.includes(category)) categoryOrder.push(category);
            byQuestionId[qid] = {
                category,
                text: String(question && question.text || '').trim(),
                type: normalizeToken(question && question.type) || 'rating',
            };
        });

    return {
        categoryOrder,
        byQuestionId,
    };
}

function collectEvaluationComments(evaluation) {
    const items = [];
    const qualitative = evaluation && typeof evaluation.qualitative === 'object' && evaluation.qualitative
        ? evaluation.qualitative
        : {};

    Object.values(qualitative).forEach(value => {
        const text = String(value || '').trim();
        if (!text) return;
        items.push(text);
    });

    const comments = String(evaluation && evaluation.comments || '').trim();
    if (comments) items.push(comments);

    return items;
}

function computeAverageRatingFromEvaluations(evaluations) {
    let total = 0;
    let count = 0;

    (evaluations || []).forEach(item => {
        const ratings = item && typeof item.ratings === 'object' && item.ratings ? item.ratings : {};
        Object.values(ratings).forEach(value => {
            const parsed = parseFloat(value);
            if (!Number.isFinite(parsed)) return;
            total += clampNumber(parsed, 1, 5);
            count += 1;
        });
    });

    return count ? (total / count) : 0;
}

function buildProfessorPanelContext() {
    const users = SharedData.getUsers ? SharedData.getUsers() : [];
    const subjectManagement = SharedData.getSubjectManagement ? SharedData.getSubjectManagement() : { offerings: [], enrollments: [] };
    const evaluations = SharedData.getEvaluations ? SharedData.getEvaluations() : [];
    const questionnaires = SharedData.getQuestionnaires ? SharedData.getQuestionnaires() : {};
    const semesterList = SharedData.getSemesterList ? SharedData.getSemesterList() : [];
    const currentSemester = SharedData.getCurrentSemester ? SharedData.getCurrentSemester() : '';
    const announcements = SharedData.getAnnouncementsForCurrentUser
        ? SharedData.getAnnouncementsForCurrentUser({ limit: 50 })
        : (SharedData.getAnnouncements ? SharedData.getAnnouncements() : []);
    const session = getUserSession() || {};

    const lookupMaps = buildProfessorLookupMaps(users);
    const professorUser = resolveCurrentProfessorUser(session, users, lookupMaps);
    const linked = !!professorUser;
    let peerAssignmentsLoaded = false;
    let peerAssignmentsResponse = {
        currentSemester: '',
        assignments: [],
        stats: { total: 0, pending: 0, submitted: 0 }
    };

    if (linked && SharedData.listProfessorPeerAssignmentsCurrent) {
        try {
            const response = SharedData.listProfessorPeerAssignmentsCurrent({});
            if (response && typeof response === 'object') {
                peerAssignmentsResponse = Object.assign({}, peerAssignmentsResponse, response);
                peerAssignmentsLoaded = true;
            }
        } catch (error) {
            console.warn('[Professor] Unable to load peer assignments.', error);
        }
    }

    const peerAssignments = Array.isArray(peerAssignmentsResponse.assignments)
        ? peerAssignmentsResponse.assignments
        : [];
    const peerAssignmentsByTargetId = {};
    peerAssignments.forEach(item => {
        const targetId = normalizeUserIdToken(item && item.targetUserId);
        if (!targetId) return;
        peerAssignmentsByTargetId[targetId] = item;
    });
    const pendingPeerAssignments = peerAssignments.filter(item =>
        String(item && item.status || '').trim().toLowerCase() === 'pending'
    );

    const offerings = Array.isArray(subjectManagement.offerings) ? subjectManagement.offerings : [];
    const enrollments = Array.isArray(subjectManagement.enrollments) ? subjectManagement.enrollments : [];
    const offeringsById = {};
    offerings.forEach(offering => {
        const id = String(offering && offering.id || '').trim();
        if (id) offeringsById[id] = offering;
    });

    return {
        linked,
        session,
        users: Array.isArray(users) ? users : [],
        evaluations: Array.isArray(evaluations) ? evaluations : [],
        questionnaires: questionnaires || {},
        semesterList: Array.isArray(semesterList) ? semesterList : [],
        currentSemester: String(currentSemester || '').trim(),
        announcements: Array.isArray(announcements) ? announcements : [],
        offerings,
        enrollments,
        offeringsById,
        lookupMaps,
        peerAssignments,
        peerAssignmentsByTargetId,
        pendingPeerAssignments,
        peerAssignmentsStats: peerAssignmentsResponse.stats || { total: 0, pending: 0, submitted: 0 },
        peerAssignmentsLoaded,
        professor: professorUser ? {
            id: normalizeUserIdToken(professorUser.id),
            name: String(professorUser.name || '').trim(),
            email: String(professorUser.email || '').trim(),
            employeeId: String(professorUser.employeeId || '').trim(),
            department: String(professorUser.department || '').trim().toUpperCase(),
            programCode: String(professorUser.programCode || '').trim().toUpperCase(),
            position: String(professorUser.position || '').trim(),
            campus: String(professorUser.campus || '').trim(),
        } : null,
    };
}

/**
 * Check if user is authenticated and is a professor
 * @returns {boolean} - True if user is authenticated as professor
 */
function checkAuthentication() {
    const session = SharedData.getSession();
    if (!session) {
        return false;
    }

    try {
        // Check if user is authenticated and is a professor
        return session.isAuthenticated === true
            && session.role === 'professor'
            && normalizeToken(session.status || 'active') !== 'inactive';
    } catch (e) {
        return false;
    }
}

function resolveCurrentProfessorUserAnyStatus(sessionInput) {
    const session = sessionInput || getUserSession() || {};
    const users = SharedData.getUsers ? SharedData.getUsers() : [];
    const professors = (Array.isArray(users) ? users : []).filter(user => normalizeToken(user && user.role) === 'professor');
    if (!professors.length) return null;

    const sessionUserId = normalizeUserIdToken(session && session.userId);
    if (sessionUserId) {
        const byId = professors.find(user => normalizeUserIdToken(user && user.id) === sessionUserId);
        if (byId) return byId;
    }

    const sessionEmail = normalizeToken(session && session.email);
    if (sessionEmail) {
        const byEmail = professors.find(user => normalizeToken(user && user.email) === sessionEmail);
        if (byEmail) return byEmail;
    }

    const sessionEmployeeId = normalizeToken(session && session.employeeId);
    if (sessionEmployeeId) {
        const byEmployeeId = professors.find(user => normalizeToken(user && user.employeeId) === sessionEmployeeId);
        if (byEmployeeId) return byEmployeeId;
    }

    const sessionUsername = normalizeToken(session && session.username);
    if (sessionUsername) {
        const byName = professors.find(user => normalizeToken(user && user.name) === sessionUsername);
        if (byName) return byName;
        const byEmailName = professors.find(user => normalizeToken(user && user.email) === sessionUsername);
        if (byEmailName) return byEmailName;
    }

    const sessionFullName = normalizeToken(session && session.fullName);
    if (sessionFullName) {
        const byFullName = professors.find(user => normalizeToken(user && user.name) === sessionFullName);
        if (byFullName) return byFullName;
    }

    return null;
}

function enforceActiveProfessorAccount(options = {}) {
    const cfg = options || {};
    const context = cfg.context || professorPanelState.context || buildProfessorPanelContext();
    const matchedUser = resolveCurrentProfessorUserAnyStatus(context.session || getUserSession() || {});
    const isInactive = normalizeToken(matchedUser && matchedUser.status) === 'inactive';
    const isLinkedActive = !!(context.linked && context.professor);

    if (isLinkedActive && !isInactive) {
        return true;
    }

    const form = cfg.form || document.getElementById('peerEvaluationForm');
    const message = isInactive
        ? 'Your account is inactive. You cannot access evaluations. Please contact your administrator.'
        : 'Your login session is not linked to an active professor account.';

    if (cfg.inline && form && typeof showFormMessage === 'function') {
        showFormMessage(form, message, 'error');
    } else {
        alert(message);
    }

    clearUserSession();
    redirectToLogin();
    return false;
}

/**
 * Redirect to login page if not authenticated
 */
function redirectToLogin() {
    window.location.href = 'mainpage.html';
}

/**
 * Initialize the professor dashboard
 */
function initializeDashboard() {
    setupNavigation();
    setupLogout();
    setupHeaderPanels();
    applyReportBlackout();
    setupReportGateSync();
    setupActionButtons();
    setupTableActions();
    setupSemesterFilter();
    setupProfessorSubjectComments();
    setupProfilePhotoUpload();
    setupPeerEvaluationForm();
    setupProfileActions();
    setupChangeEmailForm();
    setupChangePasswordForm();
    setupPasswordToggles();
    setupFacultyPaperWorkflow();
    initializeReports();
    setupProfessorDataSync();
    refreshProfessorPanelData({ preserveSelection: false });
}

/**
 * Load and display user information
 */
function loadUserInfo(context) {
    const session = context && context.session ? context.session : (SharedData.getSession() || {});
    const profileName = context && context.professor && context.professor.name
        ? context.professor.name
        : (session.username || 'Professor');

    document.querySelectorAll('.user-profile span').forEach(span => {
        span.textContent = profileName || 'Professor';
    });
}

function setProfileFieldValue(fieldKey, value) {
    const finalValue = String(value || '').trim() || 'N/A';
    document.querySelectorAll(`[data-prof-field="${fieldKey}"]`).forEach(el => {
        el.textContent = finalValue;
    });
}

function renderAnnouncementPanels(context) {
    const listItems = Array.isArray(context && context.announcements) ? context.announcements : [];
    const announcements = listItems.slice(0, 5).map(item => ({
        title: String(item && item.title || '').trim() || 'Announcement',
        message: String(item && item.message || '').trim() || 'No details available.',
    }));

    document.querySelectorAll('.js-announcement-panel .panel-list').forEach(panelList => {
        if (!announcements.length) {
            panelList.innerHTML = `
                <li>
                    <div class="panel-title">No announcements</div>
                    <div class="panel-meta">There are currently no posted updates.</div>
                </li>
            `;
            return;
        }

        panelList.innerHTML = announcements.map(item => `
            <li>
                <div class="panel-title">${escapeHTML(item.title)}</div>
                <div class="panel-meta">${escapeHTML(item.message)}</div>
            </li>
        `).join('');
    });
}

function renderProfileViewModel(context, semesterLabel) {
    const professor = context && context.professor ? context.professor : null;
    const linked = !!(context && context.linked && professor);

    const department = linked ? (professor.department || 'N/A') : 'Unlinked';
    const position = linked ? (professor.position || 'Professor') : 'Unavailable';
    const facultyId = linked ? (professor.employeeId || professor.id || 'N/A') : 'Unavailable';
    const fullName = linked ? (professor.name || 'Professor') : (context.session && context.session.username ? context.session.username : 'Professor');
    const email = linked ? (professor.email || '') : '';
    const semLabel = semesterLabel || 'Selected semester';

    setProfileFieldValue('facultyId', facultyId);
    setProfileFieldValue('department', department);
    setProfileFieldValue('position', position);
    setProfileFieldValue('semesterLabel', semLabel);
    setProfileFieldValue('fullName', fullName);
    setProfileFieldValue('email', email || 'N/A');

    const profileEmail = document.getElementById('profileEmail');
    if (profileEmail) profileEmail.textContent = email || 'N/A';

    const currentEmail = document.getElementById('currentEmail');
    if (currentEmail) currentEmail.value = email || '';

    const profilePhotoPlaceholder = document.getElementById('profilePhotoPlaceholder');
    if (profilePhotoPlaceholder) {
        profilePhotoPlaceholder.textContent = buildInitials(fullName) || 'PP';
    }
}

function formatCountdownDistance(targetDate, now) {
    const distanceMs = Math.max(targetDate.getTime() - now.getTime(), 0);
    const totalMinutes = Math.floor(distanceMs / (1000 * 60));
    const days = Math.floor(totalMinutes / (60 * 24));
    const hours = Math.floor((totalMinutes % (60 * 24)) / 60);
    const minutes = totalMinutes % 60;

    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${minutes}m`;
    return `${minutes}m`;
}

function getEvaluationPeriodCountdown(typeKey, label) {
    const dates = SharedData.getEvalPeriodDates(typeKey) || { start: '', end: '' };
    const startDate = parseDateBoundary(dates.start, 'start');
    const endDate = parseDateBoundary(dates.end, 'end');
    const now = new Date();

    if (!startDate || !endDate) {
        return {
            label,
            value: 'Not scheduled',
            meta: 'Administrator has not configured this period yet.'
        };
    }

    if (now < startDate) {
        return {
            label,
            value: `Starts in ${formatCountdownDistance(startDate, now)}`,
            meta: `${dates.start} to ${dates.end}`
        };
    }

    if (now <= endDate) {
        return {
            label,
            value: `Ends in ${formatCountdownDistance(endDate, now)}`,
            meta: `${dates.start} to ${dates.end}`
        };
    }

    return {
        label,
        value: 'Closed',
        meta: `Ended on ${formatDisplayDate(dates.end)}`
    };
}

function buildPendingProfessorTargets(context) {
    if (!context || !context.linked || !context.professor) return [];
    const pendingAssignments = Array.isArray(context.pendingPeerAssignments)
        ? context.pendingPeerAssignments
        : [];

    return pendingAssignments.map(item => ({
        id: normalizeUserIdToken(item && item.targetUserId),
        name: String(item && item.targetName || 'Professor').trim(),
        department: String(item && item.targetDepartment || '').trim().toUpperCase()
    })).filter(item => !!item.id);
}

function renderDashboardDeadlineCountdown() {
    const listEl = document.getElementById('deadline-countdown-list');
    if (!listEl) return;

    const periodItems = [
        getEvaluationPeriodCountdown('student-professor', 'Student to Professor'),
        getEvaluationPeriodCountdown('professor-professor', 'Professor to Professor'),
        getEvaluationPeriodCountdown('supervisor-professor', 'Supervisor to Professor'),
    ];

    listEl.innerHTML = periodItems.map(item => `
        <div class="dashboard-widget-item">
            <div class="dashboard-widget-item-top">
                <span class="dashboard-widget-item-title">${escapeHTML(item.label)}</span>
                <span class="dashboard-widget-item-value">${escapeHTML(item.value)}</span>
            </div>
            <div class="dashboard-widget-item-meta">${escapeHTML(item.meta)}</div>
        </div>
    `).join('');
}

function openPeerEvaluationForTarget(targetProfessorId) {
    const targetId = normalizeUserIdToken(targetProfessorId);

    switchView('peerEvaluation');
    updateNavigation('peerEvaluation');

    const context = professorPanelState.context || buildProfessorPanelContext();
    populatePeerProfessorOptions(context);

    const select = document.getElementById('peerProfessor');
    if (!select) return;

    if (targetId) {
        const hasTargetOption = Array.from(select.options).some(option =>
            normalizeUserIdToken(option.value) === targetId
        );
        if (hasTargetOption) {
            select.value = targetId;
        }
    }

    select.dispatchEvent(new Event('change', { bubbles: true }));
    refreshPeerTargetLockState();
}

function renderDashboardPendingTasks(context) {
    const listEl = document.getElementById('pending-tasks-list');
    const badgeEl = document.getElementById('pending-tasks-count');
    if (!listEl || !badgeEl) return;

    if (!context || !context.linked || !context.professor) {
        badgeEl.textContent = '0';
        listEl.innerHTML = '<p class="dashboard-widget-empty">Your login is not linked to an active professor account.</p>';
        return;
    }

    const peerPeriodOpen = SharedData.isEvalPeriodOpen('professor-professor');
    const pendingTargets = buildPendingProfessorTargets(context);
    const visiblePending = peerPeriodOpen ? pendingTargets : [];
    badgeEl.textContent = String(visiblePending.length);

    if (!peerPeriodOpen) {
        const dates = SharedData.getEvalPeriodDates('professor-professor') || { start: '', end: '' };
        const scheduleText = dates.start && dates.end
            ? `Peer evaluation period: ${dates.start} to ${dates.end}.`
            : 'Peer evaluation period is not scheduled yet.';
        listEl.innerHTML = `<p class="dashboard-widget-empty">${escapeHTML(scheduleText)}</p>`;
        return;
    }

    if (!visiblePending.length) {
        listEl.innerHTML = '<p class="dashboard-widget-empty">All peer evaluation tasks are completed for this semester.</p>';
        return;
    }

    const pendingSlice = visiblePending.slice(0, 8);
    listEl.innerHTML = pendingSlice.map((task, index) => `
        <div class="dashboard-widget-item">
            <div class="dashboard-widget-item-top">
                <span class="dashboard-widget-item-title">${escapeHTML(task.name)}</span>
                <span class="dashboard-widget-item-value">Pending</span>
            </div>
            <div class="dashboard-widget-item-meta">${escapeHTML(task.department || 'No department')}</div>
            <button type="button" class="dashboard-task-btn" data-task-action="peerEvaluation" data-task-index="${index}">Open Peer Evaluation</button>
        </div>
    `).join('');

    listEl.querySelectorAll('[data-task-action="peerEvaluation"]').forEach(button => {
        button.addEventListener('click', () => {
            const index = Number(button.getAttribute('data-task-index'));
            const task = Number.isInteger(index) && index >= 0 ? pendingSlice[index] : null;
            openPeerEvaluationForTarget(task && task.id ? task.id : '');
        });
    });
}

function renderDashboardSupportWidgets(context) {
    renderDashboardDeadlineCountdown();
    renderDashboardPendingTasks(context || professorPanelState.context || buildProfessorPanelContext());
}

function setProfessorActionsEnabled(enabled) {
    const isEnabled = !!enabled;
    const peerSelect = document.getElementById('peerProfessor');
    const peerSubmit = document.querySelector('#peerEvaluationForm .btn-submit');
    const evalFilter = document.getElementById('evaluationTypeFilter');
    const semFilter = document.getElementById('semesterFilter');
    const reportBtn = document.querySelector('.action-card .btn-action');
    const createDraftBtn = document.getElementById('facultyPaperCreateDraftBtn');

    if (peerSelect) peerSelect.disabled = !isEnabled;
    if (peerSubmit) peerSubmit.disabled = !isEnabled;
    if (evalFilter) evalFilter.disabled = !isEnabled;
    if (semFilter) semFilter.disabled = !isEnabled;
    if (reportBtn && !isEnabled) reportBtn.disabled = true;
    if (createDraftBtn) createDraftBtn.disabled = !isEnabled;
}

function refreshProfessorPanelData(options = {}) {
    const preserveSelection = !!options.preserveSelection;
    const context = buildProfessorPanelContext();
    professorPanelState.context = context;
    professorPanelState.linked = !!context.linked;

    if (!enforceActiveProfessorAccount({ inline: false, context })) {
        return;
    }

    const semesterId = preserveSelection && professorPanelState.currentSelection.semesterId
        ? professorPanelState.currentSelection.semesterId
        : findCurrentSemesterId(context.semesterList, context.currentSemester);
    const semesterLabel = getSemesterLabelById(semesterId, context.semesterList);
    const evaluationType = preserveSelection && professorPanelState.currentSelection.evaluationType
        ? professorPanelState.currentSelection.evaluationType
        : 'student';

    professorPanelState.currentSelection = {
        semesterId,
        semesterLabel,
        evaluationType,
    };

    loadUserInfo(context);
    renderAnnouncementPanels(context);
    renderProfileViewModel(context, semesterLabel);
    populateSemesterFilterOptions(context, semesterId);
    const evalFilter = document.getElementById('evaluationTypeFilter');
    if (evalFilter && Array.from(evalFilter.options).some(opt => opt.value === evaluationType)) {
        evalFilter.value = evaluationType;
    }
    populatePeerProfessorOptions(context);
    loadFacultySummary({ semesterId, evaluationType });
    initializeReports();
    setProfessorActionsEnabled(context.linked);
    applyReportBlackout();
    renderDashboardSupportWidgets(context);
    renderProfessorFacultyPaperList();

    const peerView = document.getElementById('peerEvaluationView');
    if (peerView && peerView.style.display === 'block') {
        loadDynamicPeerQuestionnaire();
    }
    refreshPeerTargetLockState();
}

/**
 * Setup announcement and profile panels
 */
function setupHeaderPanels() {
    const headerBlocks = document.querySelectorAll('.user-info');
    if (!headerBlocks.length) return;

    headerBlocks.forEach(block => {
        const notificationBtn = block.querySelector('.js-notification-btn');
        const profileBtn = block.querySelector('.js-profile-btn');
        const announcementPanel = block.querySelector('.js-announcement-panel');
        const profilePanel = block.querySelector('.js-profile-panel');

        if (!notificationBtn || !profileBtn || !announcementPanel || !profilePanel) {
            return;
        }

        notificationBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            togglePanel(announcementPanel);
        });

        profileBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            togglePanel(profilePanel);
        });

        announcementPanel.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        profilePanel.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    });

    document.addEventListener('click', function () {
        closeAllPanels();
    });
}

/**
 * Toggle a panel and close the rest
 * @param {HTMLElement} panelToToggle
 */
function togglePanel(panelToToggle) {
    const isActive = panelToToggle.classList.contains('active');
    closeAllPanels();
    if (!isActive) {
        panelToToggle.classList.add('active');
    }
}

/**
 * Close all open dropdown panels
 */
function closeAllPanels() {
    document.querySelectorAll('.dropdown-panel').forEach(panel => {
        panel.classList.remove('active');
    });
}

/**
 * Setup navigation links
 */
function setupNavigation() {
    const navLinks = document.querySelectorAll('.nav-link:not(.logout)');

    navLinks.forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault();

            // Get view to show
            const view = this.getAttribute('data-view');

            // Remove active class from all links
            navLinks.forEach(l => l.classList.remove('active'));

            // Add active class to clicked link
            this.classList.add('active');

            // Handle navigation (for future implementation)
            if (view) {
                handleNavigation(view);
            }
        });
    });
}

/**
 * Handle navigation to different sections
 * @param {string} section - Section name
 */
function handleNavigation(section) {
    switch (section) {
        case 'dashboard':
            switchView('dashboard');
            break;
        case 'peerEvaluation':
            switchView('peerEvaluation');
            break;
        case 'reports':
            switchView('reports');
            break;
        case 'facultyPaper':
            switchView('facultyPaper');
            break;
        case 'profile':
            switchView('profile');
            break;
        default:
            break;
    }
}

/**
 * Switch between dashboard and reports views
 * @param {string} viewName - Name of the view to show ('dashboard' or 'reports')
 */
function switchView(viewName) {
    const dashboardView = document.getElementById('dashboardView');
    const peerEvaluationView = document.getElementById('peerEvaluationView');
    const reportsView = document.getElementById('reportsView');
    const facultyPaperView = document.getElementById('facultyPaperView');
    const profileView = document.getElementById('profileView');

    if (viewName === 'dashboard') {
        if (dashboardView) dashboardView.style.display = 'block';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (reportsView) reportsView.style.display = 'none';
        if (facultyPaperView) facultyPaperView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        closeAllPanels();
    } else if (viewName === 'peerEvaluation') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'block';
        if (reportsView) reportsView.style.display = 'none';
        if (facultyPaperView) facultyPaperView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';

        loadDynamicPeerQuestionnaire();

        window.scrollTo(0, 0);
        closeAllPanels();
    } else if (viewName === 'reports') {
        const reportGate = resolveReportsGateState();
        if (reportGate.locked) {
            if (dashboardView) dashboardView.style.display = 'block';
            if (peerEvaluationView) peerEvaluationView.style.display = 'none';
            if (reportsView) reportsView.style.display = 'none';
            if (facultyPaperView) facultyPaperView.style.display = 'none';
            if (profileView) profileView.style.display = 'none';
            updateNavigation('dashboard');
            closeAllPanels();
            return;
        }

        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (reportsView) reportsView.style.display = 'block';
        if (facultyPaperView) facultyPaperView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        // Scroll to top
        window.scrollTo(0, 0);
        closeAllPanels();
    } else if (viewName === 'facultyPaper') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (reportsView) reportsView.style.display = 'none';
        if (facultyPaperView) facultyPaperView.style.display = 'block';
        if (profileView) profileView.style.display = 'none';
        renderProfessorFacultyPaperList();
        window.scrollTo(0, 0);
        closeAllPanels();
    } else if (viewName === 'profile') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (reportsView) reportsView.style.display = 'none';
        if (facultyPaperView) facultyPaperView.style.display = 'none';
        if (profileView) profileView.style.display = 'block';
        window.scrollTo(0, 0);
        closeAllPanels();
    }
}

/**
 * Initialize reports view with charts
 */
function initializeReports() {
    setTimeout(() => {
        initializeStudentCharts();
        initializePeerCharts();
        initializeSupervisorCharts();
    }, 50);
}

function initializeStudentCharts() {
    initializeEvaluationCharts('student');
}

function initializePeerCharts() {
    initializeEvaluationCharts('professor');
}

function initializeSupervisorCharts() {
    initializeEvaluationCharts('supervisor');
}

function initializeEvaluationCharts(type) {
    const meta = getEvaluationTypeMeta(type);
    const summary = professorPanelState.summaryByType[type] || PROFESSOR_PANEL_EMPTY_SUMMARY;
    const barCanvasId = `${meta.chartPrefix}BarChart`;
    const pieCanvasId = `${meta.chartPrefix}PieChart`;
    const barInstanceKey = `${meta.chartPrefix}BarChartInstance`;
    const pieInstanceKey = `${meta.chartPrefix}PieChartInstance`;

    const categories = Array.isArray(summary.criteriaAverages) ? summary.criteriaAverages : [];
    const labels = categories.length ? categories.map(item => item.name) : ['No Data'];
    const values = categories.length ? categories.map(item => Number(item.average || 0).toFixed ? Number(item.average.toFixed(2)) : Number(item.average || 0)) : [0];

    const barCtx = document.getElementById(barCanvasId);
    if (barCtx) {
        if (window[barInstanceKey]) window[barInstanceKey].destroy();
        window[barInstanceKey] = new Chart(barCtx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Average Score',
                    data: values,
                    backgroundColor: '#667eea',
                    borderColor: '#4752c4',
                    borderWidth: 1,
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, max: 5, ticks: { stepSize: 1 } }
                },
                plugins: { legend: { display: true, position: 'bottom' } }
            }
        });
    }

    const pieValues = [
        Number(summary.ratingDistribution[5] || 0),
        Number(summary.ratingDistribution[4] || 0),
        Number(summary.ratingDistribution[3] || 0),
        Number(summary.ratingDistribution[2] || 0),
        Number(summary.ratingDistribution[1] || 0),
    ];

    const pieCtx = document.getElementById(pieCanvasId);
    if (pieCtx) {
        if (window[pieInstanceKey]) window[pieInstanceKey].destroy();
        window[pieInstanceKey] = new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: ['5 Stars', '4 Stars', '3 Stars', '2 Stars', '1 Star'],
                datasets: [{
                    data: pieValues,
                    backgroundColor: ['#10b981', '#34d399', '#fbbf24', '#f59e0b', '#ef4444'],
                    borderWidth: 2,
                    borderColor: '#ffffff',
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true, position: 'bottom' } }
            }
        });
    }
}
/**
 * Initialize bar chart
 */
function initializeBarChart() {
    initializeStudentCharts();
}

/**
 * Initialize pie chart
 */
function initializePieChart() {
    initializeStudentCharts();
}

/**
 * Setup logout functionality
 */
function setupLogout() {
    const logoutLink = document.querySelector('.nav-link.logout');

    if (logoutLink) {
        logoutLink.addEventListener('click', function (e) {
            e.preventDefault();
            handleLogout();
        });
    }
}

/**
 * Handle logout process
 */
function handleLogout() {
    // Clear session data
    clearUserSession();

    // Show logout message
    showLogoutMessage();

    // Redirect to login page after short delay
    setTimeout(() => {
        window.location.href = 'mainpage.html';
    }, 500);
}

/**
 * Clear user session from localStorage
 */
function clearUserSession() {
    SharedData.clearSession();
}

/**
 * Show logout message
 */
function showLogoutMessage() {
    console.log('Logging out...');
}

/**
 * Setup action buttons
 */
function setupActionButtons() {
    const actionButtons = document.querySelectorAll('.btn-action');

    actionButtons.forEach(button => {
        button.addEventListener('click', function () {
            const actionCard = this.closest('.action-card');
            const titleEl = actionCard ? actionCard.querySelector('h3') : null;
            const actionTitle = titleEl ? titleEl.textContent.trim() : '';
            handleActionButton(actionTitle);
        });
    });
}

/**
 * Handle action button click
 * @param {string} actionTitle - Title of the action
 */
function handleActionButton(actionTitle) {
    // Placeholder for future action functionality
    console.log(`Action clicked: ${actionTitle}`);

    if (!professorPanelState.linked) {
        alert('Your session is not linked to an active professor account.');
        return;
    }

    if (actionTitle === 'View Reports' || actionTitle === 'Generate Paper') {
        const reportGate = resolveReportsGateState();
        if (reportGate.locked) {
            const unlockText = reportGate.endDate
                ? formatDisplayDate(reportGate.endDate)
                : 'after the Student to Professor evaluation end date is configured';
            alert('Evaluation reports are not available yet. Reports will unlock on ' + unlockText + '.');
            return;
        }
        openProfessorReportPdf();
        return;
    }
}

/**
 * Convert 1-5 average score into percentage (0-100).
 */
function toPaperRatingPercent(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return 'N/A';
    const percent = numeric * 20;
    if (!Number.isFinite(percent)) return 'N/A';
    return percent.toFixed(2);
}

/**
 * Build payload for faculty acknowledgement paper.
 */
function buildFacultyPaperData() {
    const context = professorPanelState.context || buildProfessorPanelContext();
    if (!context || !context.linked || !context.professor) return null;

    const selectedSemesterLabel = String(professorPanelState.currentSelection && professorPanelState.currentSelection.semesterLabel || '').trim();
    const semesterLabel = selectedSemesterLabel || getSemesterLabelById(
        professorPanelState.currentSelection && professorPanelState.currentSelection.semesterId,
        context.semesterList
    );

    const studentSummary = professorPanelState.summaryByType.student || PROFESSOR_PANEL_EMPTY_SUMMARY;
    const supervisorSummary = professorPanelState.summaryByType.supervisor || PROFESSOR_PANEL_EMPTY_SUMMARY;

    return {
        faculty_name: String(context.professor.name || '').trim() || 'N/A',
        department: String(context.professor.department || '').trim() || 'N/A',
        rank: String(context.professor.position || '').trim() || 'N/A',
        semester_label: String(semesterLabel || '').trim() || 'N/A',
        set_rating: toPaperRatingPercent(studentSummary.totals && studentSummary.totals.averageScore),
        saf_rating: toPaperRatingPercent(supervisorSummary.totals && supervisorSummary.totals.averageScore),
    };
}

/**
 * Open dynamically generated professor acknowledgement PDF.
 */
let professorPdfPreviewBlobUrl = '';
let professorPdfPreviewFilename = 'faculty_acknowledgement.pdf';

function closeProfessorPdfPreviewModal() {
    const modal = document.getElementById('profPdfPreviewModal');
    const frame = document.getElementById('profPdfPreviewFrame');
    if (frame) frame.src = 'about:blank';
    if (modal) modal.classList.remove('active');
    if (professorPdfPreviewBlobUrl) {
        URL.revokeObjectURL(professorPdfPreviewBlobUrl);
        professorPdfPreviewBlobUrl = '';
    }
}

function ensureProfessorPdfPreviewModal() {
    let modal = document.getElementById('profPdfPreviewModal');
    if (modal) return modal;

    modal = document.createElement('div');
    modal.id = 'profPdfPreviewModal';
    modal.className = 'pdf-preview-modal';
    modal.innerHTML = `
        <div class="pdf-preview-dialog" role="dialog" aria-modal="true" aria-label="Faculty Paper PDF Preview">
            <div class="pdf-preview-toolbar">
                <h3>Faculty Paper Preview</h3>
                <div class="pdf-preview-actions">
                    <button type="button" class="btn-submit pdf-preview-download-btn" id="profPdfPreviewDownloadBtn">Download</button>
                    <button type="button" class="btn-cancel pdf-preview-close-btn" id="profPdfPreviewCloseBtn">Close</button>
                </div>
            </div>
            <iframe id="profPdfPreviewFrame" class="pdf-preview-frame" title="Faculty Paper PDF Preview"></iframe>
        </div>
    `;
    document.body.appendChild(modal);

    const closeBtn = document.getElementById('profPdfPreviewCloseBtn');
    const downloadBtn = document.getElementById('profPdfPreviewDownloadBtn');

    if (closeBtn) {
        closeBtn.addEventListener('click', closeProfessorPdfPreviewModal);
    }

    if (downloadBtn) {
        downloadBtn.addEventListener('click', function () {
            if (!professorPdfPreviewBlobUrl) return;
            const anchor = document.createElement('a');
            anchor.href = professorPdfPreviewBlobUrl;
            anchor.download = professorPdfPreviewFilename || 'faculty_acknowledgement.pdf';
            document.body.appendChild(anchor);
            anchor.click();
            document.body.removeChild(anchor);
        });
    }

    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeProfessorPdfPreviewModal();
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('active')) {
            closeProfessorPdfPreviewModal();
        }
    });

    return modal;
}

function showProfessorPdfPreview(pdfBlob, downloadFilename) {
    const modal = ensureProfessorPdfPreviewModal();
    const frame = document.getElementById('profPdfPreviewFrame');
    if (!frame) {
        alert('Unable to open PDF preview modal.');
        return;
    }

    if (professorPdfPreviewBlobUrl) {
        URL.revokeObjectURL(professorPdfPreviewBlobUrl);
        professorPdfPreviewBlobUrl = '';
    }

    professorPdfPreviewFilename = downloadFilename || 'faculty_acknowledgement.pdf';
    professorPdfPreviewBlobUrl = URL.createObjectURL(pdfBlob);
    frame.src = `${professorPdfPreviewBlobUrl}#toolbar=1&navpanes=0&scrollbar=1`;
    modal.classList.add('active');
}

async function openProfessorStoredPaperPdf(paper, actorUserId, versionNo) {
    const paperId = String(paper && paper.id || '').trim();
    const actorId = normalizeUserIdToken(actorUserId);
    if (!paperId || !actorId) {
        throw new Error('Unable to resolve stored paper context.');
    }

    const params = new URLSearchParams({
        paper_id: paperId,
        actor_role: 'professor',
        actor_user_id: actorId
    });
    if (Number.isInteger(versionNo) && versionNo > 0) {
        params.set('version_no', String(versionNo));
    }

    let response;
    try {
        response = await fetch(`../api/faculty_paper_file.php?${params.toString()}`, {
            method: 'GET'
        });
    } catch (_error) {
        throw new Error('Unable to connect to stored PDF service.');
    }

    if (!response.ok) {
        let message = 'Failed to load stored PDF file.';
        try {
            const data = await response.json();
            if (data && data.error) message = String(data.error);
        } catch (_error) {
            // Ignore non-JSON error body.
        }
        throw new Error(message);
    }

    const pdfBlob = await response.blob();
    const filename = String(paper.latest_file_name || `${paperId}.pdf`).trim() || `${paperId}.pdf`;
    showProfessorPdfPreview(pdfBlob, filename);
}

async function openFacultyAcknowledgementPdf(payload, downloadFilename) {
    let response;
    try {
        response = await fetch('../api/generate_faculty_acknowledgement.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify(payload)
        });
    } catch (error) {
        console.error('[ProfessorPanel] Failed to request generated paper.', error);
        alert('Unable to connect to the PDF generator endpoint. Please try again.');
        return;
    }

    if (!response.ok) {
        let errorMessage = 'Failed to generate faculty acknowledgement paper.';
        try {
            const errorData = await response.json();
            if (errorData && errorData.error) errorMessage = errorData.error;
        } catch (_error) {
            // Ignore non-JSON error body and keep generic error message.
        }
        alert(errorMessage);
        return;
    }

    const pdfBlob = await response.blob();
    showProfessorPdfPreview(pdfBlob, downloadFilename || 'faculty_acknowledgement.pdf');
}

async function openProfessorReportPdf() {
    const payload = buildFacultyPaperData();
    if (!payload) {
        alert('Unable to generate paper because your session is not linked to an active professor account.');
        return;
    }
    await openFacultyAcknowledgementPdf(payload, 'faculty_acknowledgement.pdf');
}

function getProfessorPaperActor() {
    const context = professorPanelState.context || buildProfessorPanelContext();
    const professor = context && context.professor ? context.professor : null;
    const actorUserId = normalizeUserIdToken(professor && professor.id);
    return {
        role: 'professor',
        actorUserId,
        context,
    };
}

function buildFacultyPaperDraftPayload() {
    const actor = getProfessorPaperActor();
    const context = actor.context;
    const professor = context && context.professor ? context.professor : null;
    if (!context || !context.linked || !professor || !actor.actorUserId) return null;

    const paperData = buildFacultyPaperData();
    if (!paperData) return null;
    const selectedPaper = (professorPanelState.facultyPaper.records || []).find(item =>
        String(item && item.id || '') === String(professorPanelState.facultyPaper.selectedId || '')
    );
    const selectedDraftId = selectedPaper && normalizeToken(selectedPaper.status) === 'draft'
        ? String(selectedPaper.id || '').trim()
        : '';

    return {
        actor_role: actor.role,
        actor_user_id: actor.actorUserId,
        paper: {
            id: selectedDraftId,
            professor_name: paperData.faculty_name,
            department: paperData.department,
            rank: paperData.rank,
            semester_id: String(
                (professorPanelState.currentSelection && professorPanelState.currentSelection.semesterId)
                || (context && context.currentSemester)
                || 'current'
            ).trim(),
            semester_label: paperData.semester_label,
            set_rating: paperData.set_rating,
            saf_rating: paperData.saf_rating,
        }
    };
}

function normalizePaperTimestamp(value) {
    const raw = String(value || '').trim();
    if (!raw) return 'N/A';
    const date = new Date(raw);
    if (Number.isNaN(date.getTime())) return raw;
    return date.toLocaleString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function isPaperInCurrentFilter(paper, filter) {
    const status = normalizeToken(paper && paper.status);
    if (filter === 'archive') {
        return status === 'archived';
    }
    return status !== 'archived';
}

function resolvePaperStatusLabel(status) {
    const token = normalizeToken(status);
    if (token === 'draft') return 'Draft';
    if (token === 'archived') return 'Archived';
    if (token === 'sent') return 'Sent';
    if (token === 'completed') return 'Completed';
    return 'Unknown';
}

function renderProfessorFacultyPaperDetail(paper) {
    const card = document.getElementById('facultyPaperDetailCard');
    const meta = document.getElementById('facultyPaperDetailMeta');
    const previewBtn = document.getElementById('fpDetailPreviewBtn');
    const saveSectionCBtn = document.getElementById('fpDetailSaveSectionCBtn');
    const sendBtn = document.getElementById('fpDetailSendBtn');
    const archiveBtn = document.getElementById('fpDetailArchiveBtn');
    const areasInput = document.getElementById('fpSectionCAreasInput');
    const activitiesInput = document.getElementById('fpSectionCActivitiesInput');
    const actionPlanInput = document.getElementById('fpSectionCActionPlanInput');

    if (!card) return;

    if (!paper) {
        card.style.display = 'none';
        if (previewBtn) previewBtn.onclick = null;
        if (saveSectionCBtn) saveSectionCBtn.onclick = null;
        if (sendBtn) sendBtn.onclick = null;
        if (archiveBtn) archiveBtn.onclick = null;
        return;
    }

    card.style.display = 'block';
    if (meta) {
        meta.textContent = `Updated: ${normalizePaperTimestamp(paper.updated_at)}${paper.sent_at ? ` | Sent: ${normalizePaperTimestamp(paper.sent_at)}` : ''}`;
    }

    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = String(value || 'N/A');
    };

    setText('fpDetailId', paper.id || 'N/A');
    setText('fpDetailStatus', resolvePaperStatusLabel(paper.status));
    setText('fpDetailFacultyName', paper.professor_name || 'N/A');
    setText('fpDetailDepartment', paper.department || 'N/A');
    setText('fpDetailRank', paper.rank || 'N/A');
    setText('fpDetailSemester', paper.semester_label || 'N/A');
    setText('fpDetailSetRating', paper.set_rating || 'N/A');
    setText('fpDetailSafRating', paper.saf_rating || 'N/A');

    if (areasInput) areasInput.value = String(paper.section_c_areas || '');
    if (activitiesInput) activitiesInput.value = String(paper.section_c_activities || '');
    if (actionPlanInput) actionPlanInput.value = String(paper.section_c_action_plan || '');

    const draftStatus = normalizeToken(paper.status) === 'draft';
    const archivedStatus = normalizeToken(paper.status) === 'archived';
    if (areasInput) areasInput.disabled = archivedStatus;
    if (activitiesInput) activitiesInput.disabled = archivedStatus;
    if (actionPlanInput) actionPlanInput.disabled = archivedStatus;
    if (saveSectionCBtn) saveSectionCBtn.disabled = archivedStatus;
    if (sendBtn) sendBtn.disabled = !draftStatus;
    if (archiveBtn) archiveBtn.disabled = !draftStatus;

    if (previewBtn) {
        previewBtn.onclick = async () => {
            const statusToken = normalizeToken(paper.status);
            const actor = getProfessorPaperActor();
            const shouldUseStored = (statusToken === 'sent' || statusToken === 'completed')
                && String(paper.latest_file_path || '').trim() !== ''
                && !!actor.actorUserId;

            if (shouldUseStored) {
                try {
                    await openProfessorStoredPaperPdf(paper, actor.actorUserId);
                    return;
                } catch (error) {
                    console.warn('[ProfessorPanel] Falling back to live PDF generation.', error);
                }
            }

            await openFacultyAcknowledgementPdf({
                faculty_name: paper.professor_name || 'N/A',
                department: paper.department || 'N/A',
                rank: paper.rank || 'N/A',
                semester_label: paper.semester_label || 'N/A',
                set_rating: paper.set_rating || 'N/A',
                saf_rating: paper.saf_rating || 'N/A',
                section_c_areas: areasInput ? areasInput.value : (paper.section_c_areas || ''),
                section_c_activities: activitiesInput ? activitiesInput.value : (paper.section_c_activities || ''),
                section_c_action_plan: actionPlanInput ? actionPlanInput.value : (paper.section_c_action_plan || ''),
            }, `${paper.id || 'faculty_ack'}.pdf`);
        };
    }

    if (saveSectionCBtn) {
        saveSectionCBtn.onclick = async () => {
            const actor = getProfessorPaperActor();
            if (!actor.actorUserId) {
                alert('Unable to resolve your professor account.');
                return;
            }
            try {
                const response = SharedData.saveFacultyPaperSectionC({
                    actor_role: actor.role,
                    actor_user_id: actor.actorUserId,
                    paper_id: paper.id,
                    section_c: {
                        areas: areasInput ? areasInput.value : '',
                        activities: activitiesInput ? activitiesInput.value : '',
                        action_plan: actionPlanInput ? actionPlanInput.value : '',
                    }
                });
                if (!response || response.success === false) {
                    throw new Error((response && response.error) || 'Failed to save Section C.');
                }
                await renderProfessorFacultyPaperList();
                alert('Section C saved successfully.');
            } catch (error) {
                alert(error && error.message ? error.message : 'Failed to save Section C.');
            }
        };
    }

    if (sendBtn) {
        sendBtn.onclick = async () => {
            const actor = getProfessorPaperActor();
            if (!actor.actorUserId) {
                alert('Unable to resolve your professor account.');
                return;
            }
            try {
                const response = SharedData.sendFacultyPaper({
                    actor_role: actor.role,
                    actor_user_id: actor.actorUserId,
                    paper_id: paper.id,
                });
                if (!response || response.success === false) {
                    throw new Error((response && response.error) || 'Failed to send paper.');
                }
                await renderProfessorFacultyPaperList();
                alert('Paper sent to dean successfully.');
            } catch (error) {
                alert(error && error.message ? error.message : 'Failed to send paper.');
            }
        };
    }

    if (archiveBtn) {
        archiveBtn.onclick = async () => {
            const actor = getProfessorPaperActor();
            if (!actor.actorUserId) {
                alert('Unable to resolve your professor account.');
                return;
            }
            try {
                const response = SharedData.archiveFacultyPaper({
                    actor_role: actor.role,
                    actor_user_id: actor.actorUserId,
                    paper_id: paper.id,
                });
                if (!response || response.success === false) {
                    throw new Error((response && response.error) || 'Failed to archive paper.');
                }
                await renderProfessorFacultyPaperList();
                alert('Paper archived.');
            } catch (error) {
                alert(error && error.message ? error.message : 'Failed to archive paper.');
            }
        };
    }
}

async function renderProfessorFacultyPaperList() {
    const tableBody = document.getElementById('facultyPaperTableBody');
    const detailCard = document.getElementById('facultyPaperDetailCard');
    if (!tableBody) return;

    const actor = getProfessorPaperActor();
    if (!actor.context || !actor.context.linked || !actor.actorUserId) {
        tableBody.innerHTML = '<tr><td colspan="6">Your login is not linked to an active professor account.</td></tr>';
        if (detailCard) detailCard.style.display = 'none';
        return;
    }

    let records = [];
    try {
        records = SharedData.listFacultyPapers(actor.role, actor.actorUserId);
    } catch (error) {
        tableBody.innerHTML = '<tr><td colspan="6">Failed to load faculty papers.</td></tr>';
        if (detailCard) detailCard.style.display = 'none';
        return;
    }

    const filter = professorPanelState.facultyPaper.filter;
    const visible = records.filter(record => isPaperInCurrentFilter(record, filter));
    professorPanelState.facultyPaper.records = records;

    if (!visible.length) {
        tableBody.innerHTML = `<tr><td colspan="6">No ${filter === 'archive' ? 'archived' : 'active'} faculty papers.</td></tr>`;
        professorPanelState.facultyPaper.selectedId = '';
        renderProfessorFacultyPaperDetail(null);
        return;
    }

    if (!visible.some(item => item.id === professorPanelState.facultyPaper.selectedId)) {
        professorPanelState.facultyPaper.selectedId = visible[0].id || '';
    }

    tableBody.innerHTML = visible.map(paper => {
        const isSelected = professorPanelState.facultyPaper.selectedId === paper.id;
        const statusLabel = resolvePaperStatusLabel(paper.status);
        const recipient = sanitizePaperTextValueClient(paper.recipient_dean_name || '');
        const recipientText = recipient || '-';
        return `
            <tr data-paper-id="${escapeHTML(String(paper.id || ''))}" class="${isSelected ? 'faculty-paper-row-active' : ''}">
                <td>${escapeHTML(String(paper.id || 'N/A'))}</td>
                <td>${escapeHTML(String(paper.semester_label || 'N/A'))}</td>
                <td>${escapeHTML(statusLabel)}</td>
                <td>${escapeHTML(recipientText)}</td>
                <td>${escapeHTML(normalizePaperTimestamp(paper.updated_at))}</td>
                <td><button type="button" class="btn-submit faculty-paper-open-btn" data-paper-open="${escapeHTML(String(paper.id || ''))}">Open</button></td>
            </tr>
        `;
    }).join('');

    tableBody.querySelectorAll('[data-paper-open]').forEach(button => {
        button.addEventListener('click', () => {
            professorPanelState.facultyPaper.selectedId = button.getAttribute('data-paper-open') || '';
            const selected = records.find(item => String(item.id || '') === professorPanelState.facultyPaper.selectedId) || null;
            renderProfessorFacultyPaperDetail(selected);
            renderProfessorFacultyPaperList();
        });
    });

    const selectedPaper = records.find(item => String(item.id || '') === professorPanelState.facultyPaper.selectedId) || null;
    renderProfessorFacultyPaperDetail(selectedPaper);
}

function sanitizePaperTextValueClient(value) {
    return String(value || '').trim();
}

function setupFacultyPaperWorkflow() {
    const createDraftBtn = document.getElementById('facultyPaperCreateDraftBtn');
    const tabActive = document.getElementById('facultyPaperTabActive');
    const tabArchive = document.getElementById('facultyPaperTabArchive');

    if (tabActive) {
        tabActive.addEventListener('click', () => {
            professorPanelState.facultyPaper.filter = 'active';
            tabActive.classList.add('active');
            if (tabArchive) tabArchive.classList.remove('active');
            renderProfessorFacultyPaperList();
        });
    }

    if (tabArchive) {
        tabArchive.addEventListener('click', () => {
            professorPanelState.facultyPaper.filter = 'archive';
            tabArchive.classList.add('active');
            if (tabActive) tabActive.classList.remove('active');
            renderProfessorFacultyPaperList();
        });
    }

    if (createDraftBtn) {
        createDraftBtn.addEventListener('click', () => {
            const payload = buildFacultyPaperDraftPayload();
            if (!payload) {
                alert('Unable to create draft because your session is not linked to an active professor account.');
                return;
            }

            try {
                const response = SharedData.upsertFacultyPaperDraft(payload);
                if (!response || response.success === false || !response.paper) {
                    throw new Error((response && response.error) || 'Failed to create draft.');
                }

                professorPanelState.facultyPaper.filter = 'active';
                professorPanelState.facultyPaper.selectedId = response.paper.id || '';
                if (tabActive) tabActive.classList.add('active');
                if (tabArchive) tabArchive.classList.remove('active');
                renderProfessorFacultyPaperList();
                alert('Draft paper is ready.');
            } catch (error) {
                alert(error && error.message ? error.message : 'Failed to create draft paper.');
            }
        });
    }
}


/**
 * Setup table actions
 */
function setupTableActions() {
    const viewAllBtn = document.querySelector('.btn-view-all');
    const viewDetailsLinks = document.querySelectorAll('.view-details-link');

    // View All button
    if (viewAllBtn) {
        viewAllBtn.addEventListener('click', function () {
            handleViewAll();
        });
    }

    // View Details links
    viewDetailsLinks.forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            const row = this.closest('tr');
            const subjectCell = row.querySelector('td:first-child');
            // Extract subject name (remove the evaluation progress text)
            const subjectText = subjectCell.textContent;
            const subject = subjectText.split(' student evaluated')[0].trim();
            handleViewDetails(subject);
        });
    });
}

/**
 * Handle view all submissions
 */
function handleViewAll() {
    // Placeholder for future functionality
    console.log('Viewing all submissions...');
    alert('View All feature will be implemented soon!');
}

/**
 * Handle view details action
 * @param {string} subject - Subject name
 */
function handleViewDetails(subject) {
    // Placeholder for future functionality
    console.log(`Viewing details for ${subject}`);
    alert(`View Details for ${subject}\n\nThis feature will be implemented soon!`);
}

/**
 * Update summary cards with dynamic data
 */
function updateSummaryCards(overrideTotals) {
    const stats = overrideTotals || getFacultySummaryTotals();

    // Update card numbers
    const evaluationsCard = document.querySelector('.summary-card.evaluations .card-number');
    const scoreCard = document.querySelector('.summary-card.score .score-text');
    const responseCard = document.querySelector('.summary-card.response .card-number');

    if (evaluationsCard) evaluationsCard.textContent = `${stats.received}/${stats.required}`;
    if (scoreCard) {
        scoreCard.dataset.averageScore = String(Number(stats.averageScore || 0));
    }
    refreshDashboardAverageScoreVisibility();
    if (responseCard) responseCard.textContent = `${stats.responseRate}%`;
}

function refreshDashboardAverageScoreVisibility() {
    const scoreSummaryCard = document.querySelector('.summary-card.score');
    const scoreCard = document.querySelector('.summary-card.score .score-text');
    if (!scoreCard || !scoreSummaryCard) return;

    const gate = resolveReportsGateState();
    const locked = gate.locked || !professorPanelState.linked;
    const numericScore = Number(scoreCard.dataset.averageScore || 0);
    const safeScore = Number.isFinite(numericScore) ? numericScore : 0;

    if (locked) {
        scoreSummaryCard.style.display = 'none';
        scoreCard.textContent = '';
        return;
    }

    scoreSummaryCard.style.display = '';
    scoreCard.textContent = `${safeScore.toFixed(1)}/5.0`;
}

/**
 * Setup peer evaluation form functionality
 */
function setupPeerEvaluationForm() {
    const form = document.getElementById('peerEvaluationForm');
    const cancelBtn = document.getElementById('cancelPeerBtn');
    const peerSelect = document.getElementById('peerProfessor');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        handlePeerEvaluation();
    });

    if (peerSelect) {
        peerSelect.addEventListener('change', function () {
            refreshPeerTargetLockState();
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            form.reset();
            switchView('dashboard');
            updateNavigation('dashboard');
        });
    }

    populatePeerProfessorOptions(professorPanelState.context || buildProfessorPanelContext());
    refreshPeerTargetLockState();
}

function populatePeerProfessorOptions(context) {
    const select = document.getElementById('peerProfessor');
    if (!select) return;

    const previous = String(select.value || '').trim();
    const pendingAssignments = context && Array.isArray(context.pendingPeerAssignments)
        ? context.pendingPeerAssignments
        : [];
    const peers = pendingAssignments
        .map(item => ({
            id: normalizeUserIdToken(item && item.targetUserId),
            name: String(item && item.targetName || 'Professor').trim(),
            department: String(item && item.targetDepartment || '').trim().toUpperCase(),
        }))
        .filter(item => !!item.id)
        .sort((a, b) => String(a && a.name || '').localeCompare(String(b && b.name || '')));

    if (!peers.length) {
        select.innerHTML = '<option value="">No assigned peer targets this semester</option>';
        select.value = '';
        return;
    }

    select.innerHTML = '<option value="">Choose assigned professor to evaluate</option>';
    peers.forEach(user => {
        const option = document.createElement('option');
        option.value = normalizeUserIdToken(user && user.id);
        const dept = String(user && user.department || '').trim().toUpperCase();
        option.textContent = dept ? `${user.name} - ${dept}` : user.name;
        select.appendChild(option);
    });

    if (previous && peers.some(user => normalizeUserIdToken(user.id) === previous)) {
        select.value = previous;
    }
}

/**
 * Dynamically load and render the Peer Evaluation Questionnaire
 */
function loadDynamicPeerQuestionnaire() {
    const container = document.getElementById('dynamic-peer-questions-container');
    if (!container) return;

    if (!professorPanelState.linked) {
        container.innerHTML = `
            <div class="empty-state" style="text-align: center; padding: 3rem 1rem;">
                <i class="fas fa-user-lock" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                <p>This session is not linked to an active professor account.</p>
            </div>
        `;
        return;
    }

    // Fetch active questionnaires
    const allData = SharedData.getQuestionnaires();
    const currentSemester = SharedData.getCurrentSemester();

    console.log('[Professor] loadDynamicPeerQuestionnaire — semester:', JSON.stringify(currentSemester), '| available keys:', Object.keys(allData));

    // Safety check - we fallback to an empty format if nothing is active
    let dataToUse = null;
    if (currentSemester && allData[currentSemester]) {
        dataToUse = allData[currentSemester];
    } else {
        // Find latest available semester as fallback
        const semesters = Object.keys(allData).sort().reverse();
        if (semesters.length > 0) {
            dataToUse = allData[semesters[0]];
        }
    }

    const peerData = (dataToUse && dataToUse['professor-to-professor']) || { sections: [], questions: [], header: {} };

    // Update Header
    const dynamicTitle = document.getElementById('dynamic-form-title');
    const dynamicDesc = document.getElementById('dynamic-form-description');

    if (dynamicTitle && peerData.header && peerData.header.title) {
        dynamicTitle.textContent = peerData.header.title;
    } else if (dynamicTitle) {
        dynamicTitle.textContent = 'Professor Peer Evaluation Form';
    }

    if (dynamicDesc && peerData.header && peerData.header.description) {
        dynamicDesc.textContent = peerData.header.description;
    } else if (dynamicDesc) {
        dynamicDesc.textContent = 'Evaluate a colleague based on workplace conduct, collaboration, and professionalism.';
    }

    // Render questions
    const sections = peerData.sections || [];
    const questions = peerData.questions || [];

    if (sections.length === 0 && questions.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="text-align: center; padding: 3rem 1rem;">
                <i class="fas fa-info-circle" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                <p>No questions configured for peer evaluation yet.</p>
            </div>`;
        return;
    }

    let html = '';
    let stepIndex = 0;

    html += `
        <div class="eval-form-progress" id="peer-form-progress">
            <div class="eval-form-progress-header">
                <span class="eval-form-progress-label">Progress</span>
                <span class="eval-form-progress-meta" id="peer-progress-meta">Section 1 of 1</span>
            </div>
            <div class="eval-form-progress-track">
                <div class="eval-form-progress-fill" id="peer-progress-fill" style="width: 0%;"></div>
            </div>
        </div>
    `;

    // Group questions by section ID
    const groupedQuestions = {};
    sections.forEach(sec => groupedQuestions[sec.id] = []);
    questions.forEach(q => {
        if (groupedQuestions[q.sectionId]) {
            groupedQuestions[q.sectionId].push(q);
        } else {
            if (!groupedQuestions['unassigned']) groupedQuestions['unassigned'] = [];
            groupedQuestions['unassigned'].push(q);
        }
    });

    let globalQuestionIndex = 1;

    // Render unassigned questions first if any
    if (groupedQuestions['unassigned'] && groupedQuestions['unassigned'].length > 0) {
        html += `
            <div class="question-section eval-step" data-step-index="${stepIndex}">
                <div class="section-header">
                    <div class="section-title-group">
                        <div class="section-title-content">
                            <h3 class="section-title">General Questions</h3>
                        </div>
                    </div>
                </div>
                <div class="section-questions">
        `;
        groupedQuestions['unassigned'].forEach(q => {
            html += renderPeerQuestionHTML(q, globalQuestionIndex++);
        });
        html += `
                </div>
            </div>
        `;
        stepIndex++;
    }

    // Render sections
    sections.forEach(section => {
        const sectionQuestions = groupedQuestions[section.id] || [];
        if (sectionQuestions.length === 0) return;

        html += `
            <div class="question-section eval-step" data-step-index="${stepIndex}">
                <div class="section-header">
                    <div class="section-title-group">
                        ${section.letter ? `<h2 class="section-letter">${escapeHTML(section.letter)}.</h2>` : ''}
                        <div class="section-title-content">
                            <h3 class="section-title">${escapeHTML(section.title)}</h3>
                            ${section.description ? `<p class="section-description">${escapeHTML(section.description)}</p>` : ''}
                        </div>
                    </div>
                </div>
                <div class="section-questions">
        `;
        sectionQuestions.forEach(q => {
            html += renderPeerQuestionHTML(q, globalQuestionIndex++);
        });
        html += `
                </div>
            </div>
        `;
        stepIndex++;
    });
    html += `
        <div class="eval-form-nav" id="peer-form-nav">
            <button type="button" class="btn-eval-nav btn-eval-prev" id="peer-prev-btn" disabled>
                <i class="fas fa-arrow-left"></i>
                Back
            </button>
            <button type="button" class="btn-eval-nav btn-eval-next" id="peer-next-btn">
                Next
                <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    `;
    container.innerHTML = html;
    setupPeerSectionFlow();
    refreshPeerTargetLockState();
}

function normalizePeerLockValue(value) {
    return String(value || '').trim().toLowerCase();
}

function getPeerSemesterId() {
    const semester = (SharedData.getCurrentSemester && SharedData.getCurrentSemester()) || '';
    return String(semester || '').trim() || 'current';
}

function buildPeerEvaluationKey(evaluatorId, semesterId, targetId) {
    return [
        normalizePeerLockValue(evaluatorId),
        normalizePeerLockValue(semesterId),
        normalizePeerLockValue(targetId)
    ].join('|');
}

function isPeerTargetLocked(targetId) {
    const normalizedTargetId = normalizeUserIdToken(targetId);
    if (!normalizedTargetId) return true;

    const context = professorPanelState.context || buildProfessorPanelContext();
    const evaluatorId = context && context.professor
        ? context.professor.id
        : '';
    if (!evaluatorId) return true;

    const assignmentByTarget = context && context.peerAssignmentsByTargetId
        ? context.peerAssignmentsByTargetId
        : {};
    if (assignmentByTarget[normalizedTargetId]) {
        return String(assignmentByTarget[normalizedTargetId].status || '').trim().toLowerCase() !== 'pending';
    }
    if (context && context.peerAssignmentsLoaded) {
        return true;
    }

    const semesterId = getPeerSemesterId();
    const key = buildPeerEvaluationKey(evaluatorId, semesterId, normalizedTargetId);
    const evaluations = (SharedData.getEvaluations && SharedData.getEvaluations()) || [];

    return evaluations.some(ev => {
        const role = String(ev.evaluatorRole || ev.evaluationType || '').toLowerCase();
        if (role && role !== 'professor' && role !== 'peer') return false;

        const evEvaluator = normalizePeerLockValue(ev.evaluatorId || ev.evaluatorUsername);
        if (!evEvaluator || evEvaluator !== normalizePeerLockValue(evaluatorId)) return false;

        const evSemester = normalizePeerLockValue(ev.semesterId);
        if (evSemester && evSemester !== normalizePeerLockValue(semesterId)) return false;

        const existingKey = normalizePeerLockValue(ev.evaluationKey);
        if (existingKey && existingKey === normalizePeerLockValue(key)) return true;

        const evTarget = normalizePeerLockValue(ev.targetProfessorId || ev.colleagueId || ev.targetId);
        return !!evTarget && evTarget === normalizePeerLockValue(normalizedTargetId);
    });
}

function refreshPeerTargetLockState() {
    const form = document.getElementById('peerEvaluationForm');
    const select = document.getElementById('peerProfessor');
    const submitBtn = form ? form.querySelector('.btn-submit') : null;
    if (!form || !select || !submitBtn) return;

    if (!professorPanelState.linked) {
        submitBtn.disabled = true;
        return;
    }

    const targetId = String(select.value || '').trim();
    if (!targetId) {
        const context = professorPanelState.context || buildProfessorPanelContext();
        const hasPendingAssignments = Array.isArray(context && context.pendingPeerAssignments) && context.pendingPeerAssignments.length > 0;
        submitBtn.disabled = !hasPendingAssignments;
        return;
    }

    const locked = isPeerTargetLocked(targetId);
    submitBtn.disabled = locked;

    if (locked) {
        showFormMessage(form, 'You already submitted a peer evaluation for this target this semester.', 'error');
    }
}

/**
 * Generate HTML snippet for a single question based on type
 * @param {Object} question - The question object
 * @param {Number} index - The global count for IDs
 */
function renderPeerQuestionHTML(question, index) {
    const isRequired = question.required ? 'required' : '';
    const qid = String(question.id);

    // Qualitative Text Area
    if (question.type === 'qualitative') {
        return `
            <div class="question-group" style="margin-bottom: 24px;">
                <label class="question-label" for="q-${qid}">${escapeHTML(question.text)} ${question.required ? '<span style="color:#ef4444">*</span>' : ''}</label>
                <div class="form-group" style="margin-top: 8px;">
                    <textarea 
                        id="q-${qid}" 
                        name="${qid}" 
                        class="form-textarea" 
                        rows="4" 
                        placeholder="Type your response here..." 
                        ${isRequired}></textarea>
                </div>
            </div>
        `;
    }

    // Rating Scale (Radio Buttons)
    return `
        <div class="question-group">
            <label class="question-label" for="q-${qid}">${escapeHTML(question.text)} ${question.required ? '<span style="color:#ef4444">*</span>' : ''}</label>
            <div class="rating-scale">
                <input type="radio" name="${qid}" id="q${qid}-1" value="1" ${isRequired}>
                <label for="q${qid}-1" class="rating-option">1</label>
                <input type="radio" name="${qid}" id="q${qid}-2" value="2" ${isRequired}>
                <label for="q${qid}-2" class="rating-option">2</label>
                <input type="radio" name="${qid}" id="q${qid}-3" value="3" ${isRequired}>
                <label for="q${qid}-3" class="rating-option">3</label>
                <input type="radio" name="${qid}" id="q${qid}-4" value="4" ${isRequired}>
                <label for="q${qid}-4" class="rating-option">4</label>
                <input type="radio" name="${qid}" id="q${qid}-5" value="5" ${isRequired}>
                <label for="q${qid}-5" class="rating-option">5</label>
            </div>
            <p class="rating-legend">5 = Excellent, 1 = Poor</p>
        </div>
    `;
}

function setupPeerSectionFlow() {
    const steps = Array.from(document.querySelectorAll('#dynamic-peer-questions-container .eval-step'));
    const prevBtn = document.getElementById('peer-prev-btn');
    const nextBtn = document.getElementById('peer-next-btn');

    peerSectionFlow.steps = steps;
    peerSectionFlow.activeIndex = 0;

    if (!steps.length) {
        const submitBtn = document.querySelector('#peerEvaluationForm .btn-submit');
        const progress = document.getElementById('peer-form-progress');
        const nav = document.getElementById('peer-form-nav');
        if (progress) progress.style.display = 'none';
        if (nav) nav.style.display = 'none';
        if (submitBtn) submitBtn.style.display = 'inline-flex';
        return;
    }

    if (prevBtn) {
        prevBtn.onclick = () => goToPeerStep(peerSectionFlow.activeIndex - 1);
    }
    if (nextBtn) {
        nextBtn.onclick = () => {
            if (!validatePeerCurrentStep()) return;
            goToPeerStep(peerSectionFlow.activeIndex + 1);
        };
    }

    goToPeerStep(0);
}

function goToPeerStep(index) {
    const steps = peerSectionFlow.steps || [];
    if (!steps.length) return;

    const maxIndex = steps.length - 1;
    peerSectionFlow.activeIndex = Math.max(0, Math.min(index, maxIndex));

    steps.forEach((step, idx) => {
        const isActive = idx === peerSectionFlow.activeIndex;
        step.classList.toggle('is-active', isActive);
        togglePeerStepInputs(step, isActive);
    });

    const prevBtn = document.getElementById('peer-prev-btn');
    const nextBtn = document.getElementById('peer-next-btn');
    const progressFill = document.getElementById('peer-progress-fill');
    const progressMeta = document.getElementById('peer-progress-meta');
    const submitBtn = document.querySelector('#peerEvaluationForm .btn-submit');

    const isFirst = peerSectionFlow.activeIndex === 0;
    const isLast = peerSectionFlow.activeIndex === maxIndex;
    const progressPercent = ((peerSectionFlow.activeIndex + 1) / steps.length) * 100;

    if (progressFill) progressFill.style.width = `${progressPercent}%`;
    if (progressMeta) progressMeta.textContent = `Section ${peerSectionFlow.activeIndex + 1} of ${steps.length}`;
    if (prevBtn) prevBtn.disabled = isFirst;
    if (nextBtn) nextBtn.style.display = isLast ? 'none' : 'inline-flex';
    if (submitBtn) submitBtn.style.display = isLast ? 'inline-flex' : 'none';
}

function togglePeerStepInputs(stepElement, enabled) {
    if (!stepElement) return;
    const fields = stepElement.querySelectorAll('input, textarea, select');
    fields.forEach(field => {
        field.disabled = !enabled;
    });
}

function validatePeerCurrentStep() {
    const current = peerSectionFlow.steps[peerSectionFlow.activeIndex];
    if (!current) return true;
    const requiredFields = Array.from(current.querySelectorAll('input[required], textarea[required], select[required]'));

    for (const field of requiredFields) {
        if (field.type === 'radio') {
            const radios = current.querySelectorAll(`input[name="${field.name}"]`);
            const checked = Array.from(radios).some(r => r.checked);
            if (!checked) {
                field.reportValidity();
                return false;
            }
            continue;
        }

        if (!field.checkValidity()) {
            field.reportValidity();
            return false;
        }
    }
    return true;
}

function enableAllPeerStepInputs() {
    const fields = document.querySelectorAll('#dynamic-peer-questions-container .eval-step input, #dynamic-peer-questions-container .eval-step textarea, #dynamic-peer-questions-container .eval-step select');
    fields.forEach(field => {
        field.disabled = false;
    });
}

/**
 * Basic HTML escaping to prevent XSS in dynamic titles/questions
 */
function escapeHTML(str) {
    if (!str) return '';
    return str.replace(/[&<>'"]/g,
        tag => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        }[tag] || tag)
    );
}

/**
 * Handle Peer Evaluation Submission (Dynamic Data Extraction)
 */
function handlePeerEvaluation() {
    const form = document.getElementById('peerEvaluationForm');
    if (!form) return;

    if (!enforceActiveProfessorAccount({ inline: true, form })) {
        return;
    }

    const context = professorPanelState.context || buildProfessorPanelContext();

    if (!context.linked || !context.professor) {
        showFormMessage(form, 'Your login session is not linked to an active professor account.', 'error');
        return;
    }

    // ── Evaluation period gate ──
    if (!SharedData.isEvalPeriodOpen('professor-professor')) {
        const dates = SharedData.getEvalPeriodDates('professor-professor');
        let msg = 'The Professor to Professor evaluation period is not currently open.';
        if (dates.start && dates.end) {
            msg += '\nEvaluation period: ' + dates.start + ' to ' + dates.end + '.';
        } else {
            msg += '\nNo evaluation period has been set by the administrator yet.';
        }
        showFormMessage(form, msg, 'error');
        return;
    }

    const peerSelect = document.getElementById('peerProfessor');
    const selectedTargetId = String(peerSelect ? peerSelect.value : '').trim();
    if (selectedTargetId && isPeerTargetLocked(selectedTargetId)) {
        showFormMessage(form, 'You already submitted a peer evaluation for this target this semester.', 'error');
        refreshPeerTargetLockState();
        return;
    }

    enableAllPeerStepInputs();

    if (!form.checkValidity()) {
        const firstInvalid = form.querySelector(':invalid');
        if (firstInvalid) {
            const targetStep = firstInvalid.closest('.eval-step');
            if (targetStep) {
                const targetIndex = parseInt(targetStep.getAttribute('data-step-index'), 10);
                if (!Number.isNaN(targetIndex)) {
                    goToPeerStep(targetIndex);
                }
            }
        }
        form.reportValidity();
        return;
    }

    const formData = new FormData(form);

    // Get definition from SharedData to separate ratings vs qualitative
    const allData = SharedData.getQuestionnaires();
    const currentSemester = SharedData.getCurrentSemester();
    let dataToUse = null;
    if (currentSemester && allData[currentSemester]) {
        dataToUse = allData[currentSemester];
    } else {
        const semesters = Object.keys(allData).sort().reverse();
        if (semesters.length > 0) {
            dataToUse = allData[semesters[0]];
        }
    }
    const peerData = (dataToUse && dataToUse['professor-to-professor']) || { questions: [] };
    const allQuestions = peerData.questions || [];

    const ratingsGroup = {};
    const qualitativeGroup = {};

    for (let [key, value] of formData.entries()) {
        if (key === 'peerProfessor' || key === 'peerComments') continue;

        let questionDef = allQuestions.find(q => String(q.id) === key);
        if (questionDef && questionDef.type === 'qualitative') {
            qualitativeGroup[key] = value;
        } else {
            // Default to rating if not found or explicitly rating
            ratingsGroup[key] = value;
        }
    }

    const session = getUserSession() || {};
    const semesterId = getPeerSemesterId();
    const targetProfessorId = formData.get('peerProfessor') || '';
    const evaluationKey = buildPeerEvaluationKey(context.professor.id, semesterId, targetProfessorId);
    const payload = {
        evaluatorId: context.professor.id,
        evaluatorName: context.professor.name || session.username || 'Anonymous Professor',
        evaluatorEmail: context.professor.email || '',
        evaluatorRole: 'professor',
        evaluationType: 'peer',
        colleagueId: formData.get('peerProfessor'),
        targetProfessorId: targetProfessorId,
        semesterId: semesterId,
        evaluationKey: evaluationKey,
        ratings: ratingsGroup,
        qualitative: qualitativeGroup,
        comments: formData.get('peerComments') || '',
        submittedAt: new Date().toISOString()
    };

    try {
        // Save via centralized API
        SharedData.addEvaluation(payload);

        // Add to activity log
        SharedData.addActivityLogEntry({
            type: 'evaluation_submitted',
            title: 'Peer Evaluation Submitted',
            user: payload.evaluatorName,
            role: 'professor',
            date: new Date().toISOString()
        });
    } catch (error) {
        const message = String(error && error.message || '');
        if (message.toLowerCase().includes('inactive')) {
            enforceActiveProfessorAccount({ inline: true, form });
            return;
        }
        showFormMessage(form, message || 'Failed to submit peer evaluation. Please try again.', 'error');
        return;
    }

    console.log('Peer evaluation submitted to local database:', payload);
    showFormMessage(form, 'Peer evaluation submitted successfully to local database.', 'success');

    // Small delay to let the user see the success message
    setTimeout(() => {
        form.reset();
        refreshPeerTargetLockState();
        switchView('dashboard');
        updateNavigation('dashboard');
    }, 1500);
}

/**
 * Show a form message
 */
function showFormMessage(form, message, type) {
    const existing = form.querySelector('.form-message');
    if (existing) existing.remove();

    const messageDiv = document.createElement('div');
    messageDiv.className = `form-message ${type}`;
    messageDiv.textContent = message;
    messageDiv.style.cssText = `
        background-color: ${type === 'error' ? '#fee2e2' : '#d1fae5'};
        color: ${type === 'error' ? '#991b1b' : '#065f46'};
        padding: 14px 18px;
        border-radius: 12px;
        margin-bottom: 20px;
        text-align: center;
        font-weight: 600;
        border: 1px solid ${type === 'error' ? '#ef4444' : '#10b981'};
        animation: fadeIn 0.3s ease;
    `;

    form.insertBefore(messageDiv, form.firstChild);

    setTimeout(() => {
        if (messageDiv.parentNode) {
            messageDiv.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => messageDiv.remove(), 300);
        }
    }, 4000);
}

/**
 * Build summary context for current professor/evaluation type
 */
function loadFacultySummary(selection = {}) {
    const context = professorPanelState.context || buildProfessorPanelContext();
    const semesterId = String(selection.semesterId || professorPanelState.currentSelection.semesterId || context.currentSemester || '').trim();
    const evaluationType = getEvaluationTypeMeta(selection.evaluationType || professorPanelState.currentSelection.evaluationType || 'student').id;
    const semesterLabel = getSemesterLabelById(semesterId, context.semesterList);
    const evalMeta = getEvaluationTypeMeta(evaluationType);

    professorPanelState.currentSelection = {
        semesterId,
        semesterLabel,
        evaluationType,
    };

    updateSemesterLabels(semesterLabel, evalMeta.label);

    if (!context.linked || !context.professor) {
        const unlinkedSummary = { ...PROFESSOR_PANEL_EMPTY_SUMMARY };
        professorPanelState.summaryByType = {
            student: { ...PROFESSOR_PANEL_EMPTY_SUMMARY },
            professor: { ...PROFESSOR_PANEL_EMPTY_SUMMARY },
            supervisor: { ...PROFESSOR_PANEL_EMPTY_SUMMARY },
        };
        professorPanelState.summaryByType[evaluationType] = unlinkedSummary;
        renderCriteriaSummary([]);
        renderBreakdownTable([], evaluationType);
        renderEvaluationCount([], unlinkedSummary.totals);
        renderDetailedSummaryTable([], evaluationType);
        renderCommentsSummary([], evaluationType);
        updateSummaryCards(unlinkedSummary.totals);
        return;
    }

    professorPanelState.summaryByType.student = fetchFacultySummaryFromSql({ semesterId, evaluationType: 'student' });
    professorPanelState.summaryByType.professor = fetchFacultySummaryFromSql({ semesterId, evaluationType: 'professor' });
    professorPanelState.summaryByType.supervisor = fetchFacultySummaryFromSql({ semesterId, evaluationType: 'supervisor' });

    const activeSummary = professorPanelState.summaryByType[evaluationType] || { ...PROFESSOR_PANEL_EMPTY_SUMMARY };
    renderCriteriaSummary(activeSummary.criteriaAverages);
    renderBreakdownTable(activeSummary.breakdownRows, evaluationType);
    renderEvaluationCount(activeSummary.breakdownRows, activeSummary.totals);
    renderDetailedSummaryTable(activeSummary.detailedRows, evaluationType);
    renderCommentsSummary(activeSummary.comments, evaluationType);
    updateSummaryCards(activeSummary.totals);
}

/**
 * Setup semester filter for faculty summary
 */
function setupSemesterFilter() {
    const filter = document.getElementById('semesterFilter');
    const evalFilter = document.getElementById('evaluationTypeFilter');
    if (!filter) return;

    const applySelection = () => {
        const selectedOption = filter.options[filter.selectedIndex];
        const value = String(filter.value || '').trim();
        const label = selectedOption ? selectedOption.textContent.trim() : '';
        const evalValue = evalFilter ? String(evalFilter.value || 'student').trim() : 'student';
        const evalLabel = evalFilter
            ? (evalFilter.options[evalFilter.selectedIndex]?.textContent || '').trim()
            : 'Student Evaluation';

        updateSemesterLabels(label, evalLabel);
        loadFacultySummary({ semesterId: value, evaluationType: evalValue });
    };

    filter.addEventListener('change', applySelection);
    if (evalFilter) {
        evalFilter.addEventListener('change', applySelection);
    }
}

function populateSemesterFilterOptions(context, selectedSemesterId) {
    const filter = document.getElementById('semesterFilter');
    if (!filter) return;

    const list = Array.isArray(context && context.semesterList) ? context.semesterList : [];
    const fallbackId = findCurrentSemesterId(list, context && context.currentSemester);
    const choices = list.length ? list : (fallbackId ? [{ value: fallbackId, label: getSemesterLabelById(fallbackId, list) }] : []);

    filter.innerHTML = '';
    choices.forEach(item => {
        const value = String(item && item.value || '').trim();
        if (!value) return;
        const option = document.createElement('option');
        option.value = value;
        option.textContent = String(item && item.label || '').trim() || formatSemesterLabelFromSlug(value);
        filter.appendChild(option);
    });

    const preferred = String(selectedSemesterId || fallbackId || '').trim();
    if (preferred && Array.from(filter.options).some(opt => opt.value === preferred)) {
        filter.value = preferred;
    } else if (filter.options.length) {
        filter.selectedIndex = 0;
    }
}

/**
 * Update semester labels in the UI
 */
function updateSemesterLabels(label, evaluationLabel) {
    const display = document.getElementById('selectedSemester');
    const summary = document.getElementById('summarySemester');
    const evalDisplay = document.getElementById('selectedEvalType');

    if (display) {
        display.textContent = label || 'Selected semester';
    }

    if (summary) {
        summary.textContent = label || 'Selected semester';
    }

    if (evalDisplay) {
        evalDisplay.textContent = evaluationLabel || 'Student Evaluation';
    }
}

/**
 * Setup profile photo upload and preview
 */
function setupProfilePhotoUpload() {
    const input = document.getElementById('profilePhotoInput');
    const preview = document.getElementById('profilePhotoPreview');
    const placeholder = document.getElementById('profilePhotoPlaceholder');

    if (!input || !preview || !placeholder) return;

    const fullName = getProfileFullName();
    placeholder.textContent = buildInitials(fullName) || 'PP';

    const storedPhoto = SharedData.getProfilePhoto('professor');
    if (storedPhoto) {
        preview.src = storedPhoto;
        preview.classList.add('active');
        placeholder.style.display = 'none';
    }

    input.addEventListener('change', function () {
        const file = input.files && input.files[0];
        if (!file) return;

        if (!file.type.startsWith('image/')) {
            alert('Please select a valid image file.');
            input.value = '';
            return;
        }

        const reader = new FileReader();
        reader.onload = function () {
            preview.src = reader.result;
            preview.classList.add('active');
            placeholder.style.display = 'none';
            SharedData.setProfilePhoto('professor', reader.result);
        };
        reader.readAsDataURL(file);
    });
}

function getProfileFullName() {
    const items = document.querySelectorAll('#profileView .profile-item');
    for (const item of items) {
        const label = item.querySelector('.profile-label');
        if (label && label.textContent.trim() === 'Full Name') {
            const value = item.querySelector('.profile-value');
            return value ? value.textContent.trim() : '';
        }
    }
    return '';
}

function buildInitials(name) {
    if (!name) return '';
    const parts = name.split(' ').filter(Boolean);
    if (!parts.length) return '';
    const first = parts[0][0] || '';
    const last = parts.length > 1 ? parts[parts.length - 1][0] : '';
    return (first + last).toUpperCase();
}

function fetchFacultySummaryFromSql(query) {
    const context = professorPanelState.context || buildProfessorPanelContext();
    const semesterId = String(query && query.semesterId || professorPanelState.currentSelection.semesterId || context.currentSemester || '').trim();
    const evaluationType = getEvaluationTypeMeta(query && query.evaluationType || 'student').id;
    const questionMeta = buildQuestionMeta(context, evaluationType, semesterId);
    const professorId = context && context.professor ? context.professor.id : '';

    const categoryStats = {};
    questionMeta.categoryOrder.forEach(category => {
        categoryStats[category] = { sum: 0, count: 0, responses: 0, excellent: 0, good: 0, fair: 0, poor: 0, veryPoor: 0 };
    });

    const ratingDistribution = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };
    const matchedEvaluations = [];
    const allComments = [];

    (context.evaluations || []).forEach(evaluation => {
        const typeKey = getAggregateEvaluationType(evaluation);
        if (typeKey !== evaluationType) return;
        if (!isEvaluationInSemester(evaluation, semesterId)) return;
        const targetProfessorId = resolveEvaluationTargetProfessorId(evaluation, evaluationType, context);
        if (!targetProfessorId || targetProfessorId !== professorId) return;

        matchedEvaluations.push(evaluation);

        const ratings = evaluation && typeof evaluation.ratings === 'object' && evaluation.ratings ? evaluation.ratings : {};
        Object.keys(ratings).forEach(questionId => {
            const parsed = parseFloat(ratings[questionId]);
            if (!Number.isFinite(parsed)) return;
            const ratingValue = clampNumber(parsed, 1, 5);
            const rounded = clampNumber(Math.round(ratingValue), 1, 5);
            ratingDistribution[rounded] += 1;

            const questionToken = normalizeToken(questionId);
            const category = questionMeta.byQuestionId[questionToken]
                ? questionMeta.byQuestionId[questionToken].category
                : 'Unassigned';
            if (!categoryStats[category]) {
                categoryStats[category] = { sum: 0, count: 0, responses: 0, excellent: 0, good: 0, fair: 0, poor: 0, veryPoor: 0 };
            }

            categoryStats[category].sum += ratingValue;
            categoryStats[category].count += 1;
            categoryStats[category].responses += 1;
            if (rounded === 5) categoryStats[category].excellent += 1;
            if (rounded === 4) categoryStats[category].good += 1;
            if (rounded === 3) categoryStats[category].fair += 1;
            if (rounded === 2) categoryStats[category].poor += 1;
            if (rounded === 1) categoryStats[category].veryPoor += 1;
        });

        collectEvaluationComments(evaluation).forEach(text => {
            allComments.push({
                text,
                submittedAt: String(evaluation && (evaluation.submittedAt || evaluation.timestamp) || '').trim(),
                evaluator: String(evaluation && (evaluation.evaluatorName || evaluation.evaluatorUsername || evaluation.evaluatorId) || 'Anonymous').trim() || 'Anonymous',
            });
        });
    });

    let breakdownRows = [];
    let commentBuckets = {};
    let requiredTotal = 0;
    let receivedTotal = 0;

    if (evaluationType === 'student') {
        const offeringRows = [];
        const localBuckets = {};
        const professorOfferings = (context.offerings || []).filter(offering =>
            normalizeUserIdToken(offering && offering.professorUserId) === professorId &&
            !!(offering && offering.isActive) &&
            (!semesterId || String(offering && offering.semesterSlug || '').trim() === semesterId)
        );

        professorOfferings.forEach(offering => {
            const offeringId = String(offering && offering.id || '').trim();
            if (!offeringId) return;

            const required = (context.enrollments || []).filter(item =>
                String(item && item.courseOfferingId || '').trim() === offeringId &&
                normalizeToken(item && item.status) === 'enrolled'
            ).length;

            const offeringEvaluations = matchedEvaluations.filter(item =>
                String(item && item.courseOfferingId || '').trim() === offeringId
            );

            const received = offeringEvaluations.length;
            const avgRating = computeAverageRatingFromEvaluations(offeringEvaluations);
            const subject = offering.subjectCode
                ? `${offering.subjectCode} - ${offering.subjectName}`
                : String(offering.subjectName || '').trim();
            const rowKey = `student|offering|${offeringId}`;
            localBuckets[rowKey] = offeringEvaluations.flatMap(item => collectEvaluationComments(item)).map(text => ({ text }));

            offeringRows.push({
                rowKey,
                subject: subject || 'Unknown Subject',
                section: formatDisplaySection(offering.sectionName),
                required,
                received,
                avgRating,
            });
        });

        offeringRows.sort((a, b) => (a.subject + a.section).localeCompare(b.subject + b.section));
        breakdownRows = offeringRows;
        commentBuckets = localBuckets;
        requiredTotal = offeringRows.reduce((sum, item) => sum + item.required, 0);
        receivedTotal = offeringRows.reduce((sum, item) => sum + item.received, 0);
    } else {
        const grouped = {};
        const localBuckets = {};

        matchedEvaluations.forEach((evaluation, index) => {
            const evaluatorTokenCandidates = [
                evaluation && evaluation.evaluatorId,
                evaluation && evaluation.evaluatorUsername,
                evaluation && evaluation.evaluatorName,
            ];
            let evaluatorKey = '';
            for (let cursor = 0; cursor < evaluatorTokenCandidates.length; cursor += 1) {
                const token = evaluatorTokenCandidates[cursor];
                if (!token) continue;
                evaluatorKey = normalizeUserIdToken(token) || normalizeToken(token);
                if (evaluatorKey) break;
            }
            if (!evaluatorKey) evaluatorKey = `evaluator-${index + 1}`;

            if (!grouped[evaluatorKey]) grouped[evaluatorKey] = [];
            grouped[evaluatorKey].push(evaluation);
        });

        breakdownRows = Object.keys(grouped).map(groupKey => {
            const evaluations = grouped[groupKey];
            const avgRating = computeAverageRatingFromEvaluations(evaluations);
            const userRef = context.lookupMaps.byId[normalizeUserIdToken(groupKey)] || null;
            const employeeId = userRef
                ? (userRef.employeeId || userRef.id || groupKey)
                : groupKey.toUpperCase();
            const rowKey = `${evaluationType}|evaluator|${groupKey}`;
            localBuckets[rowKey] = evaluations.flatMap(item => collectEvaluationComments(item)).map(text => ({ text }));

            return {
                rowKey,
                employeeId,
                required: 1,
                received: evaluations.length,
                avgRating,
            };
        }).sort((a, b) => String(a.employeeId || '').localeCompare(String(b.employeeId || '')));

        commentBuckets = localBuckets;

        if (evaluationType === 'professor') {
            requiredTotal = Math.max(Number(context && context.peerAssignmentsStats && context.peerAssignmentsStats.total || 0), 0);
        } else {
            const supervisorRoles = new Set(['dean', 'hr', 'vpaa', 'admin']);
            requiredTotal = (context.users || []).filter(user =>
                supervisorRoles.has(normalizeToken(user && user.role)) &&
                normalizeToken(user && user.status) !== 'inactive'
            ).length;
        }
        receivedTotal = matchedEvaluations.length;
    }

    const categories = questionMeta.categoryOrder.concat(
        Object.keys(categoryStats).filter(category => !questionMeta.categoryOrder.includes(category))
    );

    const criteriaAverages = categories.map(category => {
        const stat = categoryStats[category] || { sum: 0, count: 0 };
        return {
            name: category,
            average: stat.count ? (stat.sum / stat.count) : 0,
        };
    }).filter(item => item.name);

    const detailedRows = categories.map(category => {
        const stat = categoryStats[category] || { sum: 0, count: 0, responses: 0, excellent: 0, good: 0, fair: 0, poor: 0, veryPoor: 0 };
        return {
            category,
            avgScore: stat.count ? (stat.sum / stat.count) : 0,
            responses: stat.responses || 0,
            excellent: stat.excellent || 0,
            good: stat.good || 0,
            fair: stat.fair || 0,
            poor: stat.poor || 0,
            veryPoor: stat.veryPoor || 0,
        };
    }).filter(item => item.category);

    const averageScore = computeAverageRatingFromEvaluations(matchedEvaluations);
    const responseRate = requiredTotal ? Math.round((receivedTotal / requiredTotal) * 100) : 0;

    return {
        criteriaAverages,
        breakdownRows,
        ratingDistribution,
        comments: allComments,
        commentBuckets,
        detailedRows,
        totals: {
            required: requiredTotal,
            received: receivedTotal,
            responseRate,
            averageScore,
        },
    };
}

/**
 * Render average rating per criteria
 */
function renderCriteriaSummary(criteria) {
    const list = document.getElementById('criteriaList');
    if (!list) return;

    if (!criteria.length) {
        list.innerHTML = '<li><span>No data available</span><strong>-</strong></li>';
        return;
    }

    list.innerHTML = criteria.map(item => `
        <li><span>${item.name}</span><strong>${item.average.toFixed(1)}</strong></li>
    `).join('');
}

/**
 * Render breakdown table per subject/section
 */
function renderBreakdownTable(rows, evaluationType = 'student') {
    const table = document.getElementById('facultyBreakdownTable');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const thead = table.querySelector('thead');
    if (!tbody || !thead) return;

    if (evaluationType !== 'student') {
        thead.innerHTML = `
            <tr>
                <th>Employee Number</th>
                <th>Avg Rating</th>
                <th>Comments</th>
            </tr>
        `;
    } else {
        thead.innerHTML = `
            <tr>
                <th>Subject</th>
                <th>Section</th>
                <th>Evaluations Received</th>
                <th>Response Rate</th>
                <th>Avg Rating</th>
                <th>Comments</th>
            </tr>
        `;
    }

    const colCount = evaluationType === 'student' ? 6 : 3;

    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="${colCount}">No evaluation data available.</td></tr>`;
        return;
    }

    tbody.innerHTML = rows.map(item => {
        const responseRate = item.required ? Math.round((item.received / item.required) * 100) : 0;

        if (evaluationType !== 'student') {
            return `
                <tr data-required="${item.required || 0}" data-received="${item.received || 0}" data-avg="${item.avgRating}" data-comment-key="${item.rowKey || ''}">
                    <td>${item.employeeId}</td>
                    <td>${item.avgRating.toFixed(1)}</td>
                    <td><button type="button" class="btn-submit faculty-comments-btn js-prof-comments" data-eval-type="${evaluationType}" data-comment-key="${item.rowKey || ''}" data-subject="${item.employeeId}">View</button></td>
                </tr>
            `;
        }

        return `
            <tr data-required="${item.required}" data-received="${item.received}" data-avg="${item.avgRating}" data-comment-key="${item.rowKey || ''}">
                <td>${item.subject}</td>
                <td>${item.section}</td>
                <td><span class="count-pill">${item.received}/${item.required}</span></td>
                <td>${responseRate}%</td>
                <td>${item.avgRating.toFixed(1)}</td>
                <td><button type="button" class="btn-submit faculty-comments-btn js-prof-comments" data-eval-type="student" data-comment-key="${item.rowKey || ''}" data-subject="${item.subject}" data-section="${item.section}">View</button></td>
            </tr>
        `;
    }).join('');
}

/**
 * Setup section feedback summary per subject/section
 */
function setupProfessorSubjectComments() {
    const table = document.getElementById('facultyBreakdownTable');
    const panel = document.getElementById('profSubjectCommentsPanel');
    const title = document.getElementById('profSubjectCommentsTitle');
    const meta = document.getElementById('profSubjectCommentsMeta');
    const list = document.getElementById('profSubjectCommentsList');
    const closeBtn = document.getElementById('profSubjectCommentsClose');

    if (!table || !panel || !title || !meta || !list || !closeBtn) return;

    closeBtn.addEventListener('click', function () {
        panel.classList.remove('active');
    });

    table.addEventListener('click', function (e) {
        const target = e.target;
        if (!target || !target.classList || !target.classList.contains('js-prof-comments')) return;

        const subject = target.getAttribute('data-subject');
        const section = target.getAttribute('data-section');
        const evalType = target.getAttribute('data-eval-type') || 'student';
        const commentKey = target.getAttribute('data-comment-key') || '';
        if (!subject) return;

        if (evalType === 'professor') {
            title.textContent = 'Professor Feedback Summary';
        } else if (evalType === 'supervisor') {
            title.textContent = 'Supervisor Feedback Summary';
        } else {
            title.textContent = 'Anonymous Feedback';
        }
        const scope = section ? `${subject} • ${section}` : subject;
        meta.textContent = evalType === 'professor'
            ? (scope || subject || 'Professor feedback')
            : evalType === 'supervisor'
                ? (scope || subject || 'Supervisor feedback')
                : (scope ? `${scope} — student comments are anonymized.` : 'Anonymized comments');

        fetchSectionSummaryFromSql({
            commentKey,
            subject,
            section,
            evaluationType: evalType
        }).then(summaries => {
            if (!summaries.length) {
                list.innerHTML = '<li class="faculty-comments-empty">No anonymized feedback available.</li>';
            } else {
                list.innerHTML = summaries.map(item =>
                    '<li>' +
                    '<div class="faculty-comment-text">' + item.text + '</div>' +
                    '</li>'
                ).join('');
            }
            panel.classList.add('active');
        }).catch(() => {
            list.innerHTML = '<li class="faculty-comments-empty">Unable to load anonymized feedback.</li>';
            panel.classList.add('active');
        });
    });
}

/**
 * SQL-ready placeholder for section feedback summaries
 */
function fetchSectionSummaryFromSql(query) {
    const evalType = getEvaluationTypeMeta(query && query.evaluationType || 'student').id;
    const commentKey = String(query && query.commentKey || '').trim();
    const summary = professorPanelState.summaryByType[evalType] || PROFESSOR_PANEL_EMPTY_SUMMARY;
    const bucket = summary.commentBuckets && commentKey ? summary.commentBuckets[commentKey] : [];
    return Promise.resolve(Array.isArray(bucket) ? bucket : []);
}

/**
 * Render evaluations received count
 */
function renderEvaluationCount(rows, totals) {
    const countEl = document.getElementById('evaluationCount');
    if (!countEl) return;
    const fallback = computeTotals(rows);
    const stats = totals || fallback;
    countEl.textContent = `${stats.received}/${stats.required}`;
}

/**
 * Compute totals for summary cards
 */
function computeTotals(subjects) {
    return subjects.reduce((acc, item) => {
        acc.required += item.required;
        acc.received += item.received;
        return acc;
    }, { required: 0, received: 0 });
}

/**
 * Get totals for summary cards (uses mock data if needed)
 */
function getFacultySummaryTotals() {
    const activeType = professorPanelState.currentSelection.evaluationType || 'student';
    const summary = professorPanelState.summaryByType[activeType] || PROFESSOR_PANEL_EMPTY_SUMMARY;
    return summary.totals || { required: 0, received: 0, responseRate: 0, averageScore: 0 };
}

function renderDetailedSummaryTable(rows, evaluationType) {
    const tbody = document.getElementById('detailedSummaryTableBody');
    if (!tbody) return;

    const data = Array.isArray(rows) ? rows : [];
    if (!data.length) {
        tbody.innerHTML = '<tr><td colspan="8">No data available.</td></tr>';
        return;
    }

    tbody.innerHTML = data.map(item => `
        <tr>
            <td>${escapeHTML(item.category)}</td>
            <td><span class="avg-score">${Number(item.avgScore || 0).toFixed(1)}</span></td>
            <td>${Number(item.responses || 0)}</td>
            <td><span class="count excellent">${Number(item.excellent || 0)}</span></td>
            <td><span class="count good">${Number(item.good || 0)}</span></td>
            <td><span class="count fair">${Number(item.fair || 0)}</span></td>
            <td><span class="count poor">${Number(item.poor || 0)}</span></td>
            <td><span class="count very-poor">${Number(item.veryPoor || 0)}</span></td>
        </tr>
    `).join('');
}

function renderCommentsSummary(items, evaluationType) {
    const list = document.getElementById('studentCommentsSummaryList');
    if (!list) return;

    const comments = Array.isArray(items) ? items.slice(0, 5) : [];
    if (!comments.length) {
        const emptyText = getEvaluationTypeMeta(evaluationType).emptyComments;
        list.innerHTML = `
            <div class="comment-card">
                <div class="comment-icon"><i class="fas fa-quote-left"></i></div>
                <p class="comment-text">${escapeHTML(emptyText)}</p>
                <p class="comment-author">- System</p>
            </div>
        `;
        return;
    }

    list.innerHTML = comments.map(item => `
        <div class="comment-card">
            <div class="comment-icon"><i class="fas fa-quote-left"></i></div>
            <p class="comment-text">${escapeHTML(item.text || '')}</p>
            <p class="comment-author">- ${escapeHTML(item.evaluator || 'Anonymous')}</p>
        </div>
    `).join('');
}

/**
 * Setup profile view actions for toggling account forms
 */
function setupProfileActions() {
    const toggleButtons = document.querySelectorAll('.js-toggle-account-form');
    const closeButtons = document.querySelectorAll('.js-close-account-form');
    if (!toggleButtons.length && !closeButtons.length) return;

    toggleButtons.forEach(button => {
        button.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            if (!targetId) return;
            hideAccountActionCards();
            const targetCard = document.getElementById(targetId);
            if (targetCard) {
                targetCard.style.display = 'block';
                targetCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });
    });

    closeButtons.forEach(button => {
        button.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            const targetCard = targetId ? document.getElementById(targetId) : null;
            if (targetCard) {
                const form = targetCard.querySelector('form');
                if (form) form.reset();
                targetCard.style.display = 'none';
            }
        });
    });
}

function hideAccountActionCards() {
    document.querySelectorAll('.account-action-card').forEach(card => {
        card.style.display = 'none';
    });
}

/**
 * Setup change email form functionality
 */
function setupChangeEmailForm() {
    const form = document.getElementById('changeEmailForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        handleChangeEmail();
    });
}

/**
 * Placeholder change email handler (SQL-ready)
 */
function handleChangeEmail() {
    const currentEmail = document.getElementById('currentEmail').value.trim();
    const newEmail = document.getElementById('newEmail').value.trim();
    const confirmEmail = document.getElementById('confirmEmail').value.trim();

    if (!newEmail || !confirmEmail) {
        alert('Please fill out all email fields.');
        return;
    }

    if (newEmail !== confirmEmail) {
        alert('New email and confirmation do not match.');
        return;
    }

    if (currentEmail && newEmail.toLowerCase() === currentEmail.toLowerCase()) {
        alert('New email must be different from the current email.');
        return;
    }

    const payload = {
        username: getUserSession() ? getUserSession().username : '',
        currentEmail,
        newEmail
    };

    console.log('Ready for SQL integration: /api/professor/change-email', payload);
    alert('Email update request ready for SQL connection.');

    const form = document.getElementById('changeEmailForm');
    if (form) form.reset();
}

/**
 * Setup change password form functionality
 */
function setupChangePasswordForm() {
    const form = document.getElementById('changePasswordForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        handleChangePassword();
    });
}

/**
 * Placeholder change password handler (SQL-ready)
 */
function handleChangePassword() {
    const currentPassword = document.getElementById('currentPassword').value.trim();
    const newPassword = document.getElementById('newPassword').value.trim();
    const confirmPassword = document.getElementById('confirmPassword').value.trim();

    if (!currentPassword || !newPassword || !confirmPassword) {
        alert('Please fill out all password fields.');
        return;
    }

    if (newPassword !== confirmPassword) {
        alert('New password and confirmation do not match.');
        return;
    }

    const payload = {
        username: getUserSession() ? getUserSession().username : '',
        currentPassword,
        newPassword
    };

    console.log('Ready for SQL integration: /api/professor/change-password', payload);
    alert('Password update request ready for SQL connection.');

    const form = document.getElementById('changePasswordForm');
    if (form) form.reset();
}

/**
 * Setup password visibility toggles
 */
function setupPasswordToggles() {
    const toggleButtons = document.querySelectorAll('.toggle-password');
    if (!toggleButtons.length) return;

    toggleButtons.forEach(button => {
        button.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            const input = document.getElementById(targetId);
            const icon = this.querySelector('i');
            if (!input || !icon) return;

            const isHidden = input.type === 'password';
            input.type = isHidden ? 'text' : 'password';
            icon.classList.toggle('fa-eye', !isHidden);
            icon.classList.toggle('fa-eye-slash', isHidden);
            this.setAttribute('aria-label', isHidden ? 'Hide password' : 'Show password');
        });
    });
}

/**
 * Update navigation active state
 * @param {string} viewName - Name of the active view
 */
function updateNavigation(viewName) {
    const navLinks = document.querySelectorAll('.nav-link:not(.logout)');
    navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('data-view') === viewName) {
            link.classList.add('active');
        }
    });
}

function parseDateBoundary(dateString, boundary) {
    if (!dateString) return null;
    const timePart = boundary === 'end' ? 'T23:59:59' : 'T00:00:00';
    const date = new Date(dateString + timePart);
    return Number.isNaN(date.getTime()) ? null : date;
}

function resolveReportsGateState() {
    const studentPeriod = SharedData.getEvalPeriodDates('student-professor') || { start: '', end: '' };
    const startDate = parseDateBoundary(studentPeriod.start, 'start');
    const endDate = parseDateBoundary(studentPeriod.end, 'end');
    const hasValidDates = !!(startDate && endDate);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const isPeriodOpen = hasValidDates && SharedData.isEvalPeriodOpen('student-professor');
    const hasPeriodEnded = hasValidDates && today > endDate;
    const locked = !hasValidDates || isPeriodOpen || !hasPeriodEnded;

    return {
        locked,
        hasValidDates,
        startDate: studentPeriod.start || '',
        endDate: studentPeriod.end || '',
    };
}

function setReportsNavVisibility(locked) {
    const reportsLink = document.querySelector('.nav-link[data-view="reports"]');
    if (!reportsLink) return;
    reportsLink.style.display = locked ? 'none' : '';
}

function setDashboardReportActionVisibility(locked) {
    const reportActionCard = Array.from(document.querySelectorAll('.action-card')).find(card => {
        const titleEl = card.querySelector('h3');
        return titleEl && titleEl.textContent.trim() === 'View Reports';
    });

    if (!reportActionCard) return;

    const actionBtn = reportActionCard.querySelector('.btn-action');
    reportActionCard.style.display = locked ? 'none' : '';
    if (actionBtn) {
        actionBtn.disabled = locked;
        actionBtn.setAttribute('aria-disabled', locked ? 'true' : 'false');
    }
}

function setupProfessorDataSync() {
    if (!SharedData || typeof SharedData.onDataChange !== 'function' || !SharedData.KEYS) return;

    const refreshKeys = new Set([
        SharedData.KEYS.EVALUATIONS,
        SharedData.KEYS.SUBJECT_MANAGEMENT,
        SharedData.KEYS.USERS,
        SharedData.KEYS.QUESTIONNAIRES,
        SharedData.KEYS.CURRENT_SEMESTER,
        SharedData.KEYS.SEMESTER_LIST,
        SharedData.KEYS.ANNOUNCEMENTS,
    ]);

    SharedData.onDataChange(function (key) {
        if (refreshKeys.has(key)) {
            refreshProfessorPanelData({ preserveSelection: true });
            return;
        }
    });
}

function setupReportGateSync() {
    if (!SharedData || typeof SharedData.onDataChange !== 'function') return;

    SharedData.onDataChange(function (key) {
        if (key === SharedData.KEYS.EVAL_PERIODS) {
            applyReportBlackout();
            renderDashboardSupportWidgets();
        }
    });
}

/**
 * Apply report availability based on Student to Professor evaluation period
 */
function applyReportBlackout() {
    const gate = resolveReportsGateState();
    const locked = gate.locked || !professorPanelState.linked;
    const blackoutEl = document.getElementById('reportsBlackout');
    const contentEl = document.getElementById('reportsContent');
    const unlockDateEl = document.getElementById('reportUnlockDate');
    const reportsView = document.getElementById('reportsView');

    setReportsNavVisibility(locked);
    setDashboardReportActionVisibility(locked);
    refreshDashboardAverageScoreVisibility();

    if (unlockDateEl) {
        unlockDateEl.textContent = !professorPanelState.linked
            ? 'N/A'
            : gate.endDate
            ? formatDisplayDate(gate.endDate)
            : 'a configured date';
    }

    if (blackoutEl && contentEl) {
        blackoutEl.style.display = locked ? 'block' : 'none';
        contentEl.style.display = locked ? 'none' : 'block';
    }

    if (locked && reportsView && reportsView.style.display === 'block') {
        switchView('dashboard');
        updateNavigation('dashboard');
    }
}

function formatDisplayDate(dateString) {
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) return dateString;
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

/**
 * Get user session data
 * @returns {Object|null} - User session data or null
 */
function getUserSession() {
    return SharedData.getSession();
}

/**
 * Check if session is expired (for future use)
 * @returns {boolean} - True if session is expired
 */
function isSessionExpired() {
    const session = getUserSession();
    if (!session || !session.loginTime) {
        return true;
    }

    const loginTime = new Date(session.loginTime);
    const now = new Date();
    const hoursDiff = (now - loginTime) / (1000 * 60 * 60);

    // Session expires after 8 hours
    return hoursDiff > 8;
}

// Export functions for future use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        checkAuthentication,
        handleLogout,
        clearUserSession,
        getUserSession,
        handleActionButton,
        handleViewDetails,
        updateSummaryCards
    };
}
