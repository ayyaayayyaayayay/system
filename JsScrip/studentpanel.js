// Student Panel JavaScript - Dashboard Functionality

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function () {
    // Check authentication
    if (!checkAuthentication()) {
        redirectToLogin();
        return;
    }

    if (!enforceActiveStudentAccount({ inline: false })) {
        return;
    }

    // Initialize the dashboard
    initializeDashboard();
});

let evaluationSectionFlow = {
    sections: [],
    activeIndex: 0
};

let evaluationDraftState = {
    autosaveTimer: null,
    suppressAutosave: false,
    lastSavedAt: '',
    lastDraftKey: ''
};
let studentHeaderPanelsBound = false;

/**
 * Check if user is authenticated
 * @returns {boolean} - True if user is authenticated
 */
/**
 * Check if user is authenticated
 * @returns {boolean} - True if user is authenticated
 */
function checkAuthentication() {
    const session = SharedData.getSession();
    if (!session) {
        return false;
    }

    try {
        // Strict role check for student
        return session.isAuthenticated === true
            && session.role === 'student'
            && String(session.status || 'active').toLowerCase().trim() !== 'inactive';
    } catch (e) {
        return false;
    }
}

function resolveStudentAccountStatus(sessionInput) {
    const session = sessionInput || SharedData.getSession() || {};
    const matchedStudent = resolveCurrentStudentUser(session);
    const status = matchedStudent
        ? String(matchedStudent.status || 'active').toLowerCase().trim()
        : String(session.status || 'active').toLowerCase().trim();

    return {
        matchedStudent: matchedStudent,
        status: status === 'inactive' ? 'inactive' : 'active',
        isInactive: status === 'inactive'
    };
}

function enforceActiveStudentAccount(options) {
    const cfg = options || {};
    const accountStatus = resolveStudentAccountStatus();
    if (!accountStatus.isInactive) {
        return true;
    }

    const message = 'Your account is inactive. You cannot access evaluations. Please contact your administrator.';
    if (cfg.inline && typeof showErrorMessage === 'function') {
        showErrorMessage(message);
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
 * Initialize the dashboard
 */
function initializeDashboard() {
    loadUserInfo();
    setupHeaderPanels();
    renderStudentAnnouncements();
    renderAssignedEvaluationList();
    setupNavigation();
    setupLogout();
    setupEvaluationButtons();
    setupSubmitNewButton();
    refreshEvaluationStatuses();
    updateSummaryCards();
    setupEvaluationForm();
    updateEvaluationTargetIndicator();
    setupProfileActions();
    setupChangePasswordForm();
    setupPasswordToggles();
    setupHistoryView();

    SharedData.onDataChange(function (key) {
        if (key === SharedData.KEYS.USERS) {
            if (!enforceActiveStudentAccount({ inline: false })) {
                return;
            }
            renderStudentAnnouncements();
        }

        if (
            key === SharedData.KEYS.SUBJECT_MANAGEMENT ||
            key === SharedData.KEYS.EVALUATIONS ||
            key === SharedData.KEYS.CURRENT_SEMESTER
        ) {
            renderAssignedEvaluationList();
            refreshEvaluationStatuses();
            updateSummaryCards();
            renderStudentAnnouncements();
        }

        if (key === SharedData.KEYS.ANNOUNCEMENTS) {
            renderStudentAnnouncements();
        }
    });
}

function setTextById(id, value) {
    const el = document.getElementById(id);
    if (!el) return;
    const text = value == null || value === '' ? 'N/A' : String(value);
    el.textContent = text;
}

function toTitleWords(value) {
    const text = String(value || '').trim();
    if (!text) return '';
    return text
        .replace(/[-_]+/g, ' ')
        .replace(/\s+/g, ' ')
        .toLowerCase()
        .replace(/\b\w/g, function (ch) { return ch.toUpperCase(); });
}

function parseYearLevel(yearSection) {
    const raw = String(yearSection || '').trim();
    if (!raw) return '';
    const ysMatch = raw.match(/^(\d+)-\d+$/);
    if (ysMatch) {
        const yearNo = parseInt(ysMatch[1], 10);
        const mod100 = yearNo % 100;
        const suffix = (mod100 >= 11 && mod100 <= 13) ? 'th' : (yearNo % 10 === 1 ? 'st' : yearNo % 10 === 2 ? 'nd' : yearNo % 10 === 3 ? 'rd' : 'th');
        return `${yearNo}${suffix} Year`;
    }
    const firstPart = raw.split('-')[0].trim();
    return firstPart || raw;
}

function normalizeLookup(value) {
    return String(value || '').trim().toLowerCase();
}

function normalizeCompact(value) {
    return normalizeLookup(value).replace(/[^a-z0-9]/g, '');
}

function resolveCurrentStudentUser(session) {
    const users = SharedData.getUsers() || [];
    const students = users.filter(function (u) { return normalizeLookup(u && u.role) === 'student'; });
    if (!students.length) return null;

    const sessionUserId = String(session && session.userId || '').trim();
    const sessionEmail = normalizeLookup(session && session.email);
    const sessionStudentNo = normalizeLookup(session && session.studentNumber);
    const sessionUsername = normalizeLookup(session && session.username);

    if (sessionUserId) {
        const byId = students.find(function (u) {
            return String(u && u.id || '').trim() === sessionUserId;
        });
        if (byId) return byId;
    }

    if (sessionEmail) {
        const byEmail = students.find(function (u) {
            return normalizeLookup(u && u.email) === sessionEmail;
        });
        if (byEmail) return byEmail;
    }

    if (sessionStudentNo) {
        const byStudentNo = students.find(function (u) {
            return normalizeLookup(u && u.studentNumber) === sessionStudentNo;
        });
        if (byStudentNo) return byStudentNo;
    }

    if (sessionUsername) {
        const byName = students.find(function (u) {
            return normalizeLookup(u && u.name) === sessionUsername;
        });
        if (byName) return byName;

        const byEmail = students.find(function (u) {
            return normalizeLookup(u && u.email) === sessionUsername;
        });
        if (byEmail) return byEmail;
    }

    console.warn('[StudentProfile] Exact session match not found; refusing fallback to avoid cross-account data.', {
        sessionUserId: sessionUserId || null,
        sessionEmail: sessionEmail || null,
        sessionStudentNumber: sessionStudentNo || null,
        sessionUsername: sessionUsername || null,
        availableStudentIds: students.map(function (u) { return u.id; })
    });
    return null;
}

function buildCurrentStudentIdentity(session) {
    const activeSession = session || getUserSession() || {};
    const matchedStudent = resolveCurrentStudentUser(activeSession);

    const tokens = [
        activeSession.userId,
        activeSession.email,
        activeSession.studentNumber,
        activeSession.username,
        matchedStudent && matchedStudent.id,
        matchedStudent && matchedStudent.email,
        matchedStudent && matchedStudent.studentNumber
    ]
        .map(function (value) { return normalizeValue(value); })
        .filter(function (value) { return value !== ''; });

    return {
        primaryStudentUserId: String(
            (matchedStudent && matchedStudent.id)
            || activeSession.userId
            || ''
        ).trim(),
        primaryStudentId: String(
            (matchedStudent && matchedStudent.studentNumber)
            || activeSession.studentNumber
            || activeSession.username
            || ''
        ).trim(),
        primarySemesterId: getActiveSemesterId(),
        tokens: Array.from(new Set(tokens))
    };
}

function evaluationBelongsToStudent(ev, studentIdentity) {
    if (!ev || !studentIdentity || !Array.isArray(studentIdentity.tokens) || !studentIdentity.tokens.length) {
        return false;
    }

    const evRole = String(ev.evaluatorRole || ev.evaluationType || '').toLowerCase();
    if (evRole && evRole !== 'student') return false;

    const identitySet = new Set(studentIdentity.tokens);
    const candidates = [
        ev.studentUserId,
        ev.studentId,
        ev.evaluatorUsername,
        ev.evaluatorId,
        ev.userId,
        ev.studentNumber,
        ev.evaluatorEmail
    ]
        .map(function (value) { return normalizeValue(value); })
        .filter(function (value) { return value !== ''; });

    return candidates.some(function (value) { return identitySet.has(value); });
}

function extractAcademicYear(value) {
    const match = String(value || '').match(/\b\d{4}-\d{4}\b/);
    return match ? match[0] : '';
}

function extractSemesterNumber(value) {
    const text = normalizeValue(value);
    if (!text) return '';
    if (text === '1' || text.includes('1st') || text.includes('first')) return '1';
    if (text === '2' || text.includes('2nd') || text.includes('second')) return '2';
    return '';
}

function formatSubmittedAt(value) {
    const raw = String(value || '').trim();
    if (!raw) return 'N/A';
    const date = new Date(raw);
    if (Number.isNaN(date.getTime())) return raw;
    return date.toLocaleString();
}

function getStudentQuestionnaireForSemester(semesterId) {
    const questionnaires = (SharedData.getQuestionnaires && SharedData.getQuestionnaires()) || {};
    const semesterKey = String(semesterId || '').trim();
    const currentSemester = (SharedData.getCurrentSemester && SharedData.getCurrentSemester()) || '';

    let bucket = questionnaires[semesterKey];
    if (!bucket && currentSemester) {
        bucket = questionnaires[currentSemester];
    }
    if (!bucket) {
        const latestKey = Object.keys(questionnaires).sort().reverse()[0];
        if (latestKey) bucket = questionnaires[latestKey];
    }

    return (bucket && bucket['student-to-professor']) || { sections: [], questions: [] };
}

function buildHistoryQuestionMeta(questionnaire) {
    const sections = Array.isArray(questionnaire && questionnaire.sections) ? questionnaire.sections : [];
    const questions = Array.isArray(questionnaire && questionnaire.questions) ? questionnaire.questions : [];
    const sectionMap = {};
    const meta = {};
    let runningNumber = 0;

    sections.forEach(function (section) {
        sectionMap[String(section.id)] = section;
    });

    const sortedSections = sections.slice().sort(function (a, b) {
        const orderA = typeof a.order === 'number' ? a.order : Number.MAX_SAFE_INTEGER;
        const orderB = typeof b.order === 'number' ? b.order : Number.MAX_SAFE_INTEGER;
        if (orderA !== orderB) return orderA - orderB;
        return String(a.letter || '').localeCompare(String(b.letter || ''));
    });

    sortedSections.forEach(function (section) {
        const sectionTitle = String(section.title || section.letter || 'Section').trim();
        const sectionQuestions = questions
            .filter(function (q) { return String(q.sectionId || '') === String(section.id); })
            .sort(function (a, b) { return (a.order || 0) - (b.order || 0); });

        sectionQuestions.forEach(function (question) {
            runningNumber += 1;
            meta[String(question.id)] = {
                number: runningNumber,
                text: String(question.text || '').trim() || ('Question ' + runningNumber),
                sectionTitle: sectionTitle
            };
        });
    });

    const unsectionedQuestions = questions
        .filter(function (q) { return !q.sectionId; })
        .sort(function (a, b) { return (a.order || 0) - (b.order || 0); });

    unsectionedQuestions.forEach(function (question) {
        runningNumber += 1;
        meta[String(question.id)] = {
            number: runningNumber,
            text: String(question.text || '').trim() || ('Question ' + runningNumber),
            sectionTitle: 'General Questions'
        };
    });

    return {
        byQuestionId: meta,
        maxNumber: runningNumber
    };
}

function buildHistoryAnswerSections(record, questionMeta) {
    const ratings = record && typeof record.ratings === 'object' ? record.ratings : {};
    const qualitative = record && typeof record.qualitative === 'object' ? record.qualitative : {};
    const rows = [];
    const knownMeta = (questionMeta && questionMeta.byQuestionId) || {};
    let nextNumber = (questionMeta && questionMeta.maxNumber) || 0;

    function pushAnswers(sourceObj) {
        Object.keys(sourceObj || {}).forEach(function (key) {
            const value = sourceObj[key];
            const normalizedKey = String(key);
            const meta = knownMeta[normalizedKey];
            let number;
            let text;
            let sectionTitle;

            if (meta) {
                number = meta.number;
                text = meta.text;
                sectionTitle = meta.sectionTitle;
            } else {
                nextNumber += 1;
                number = nextNumber;
                text = 'Question ' + number;
                sectionTitle = 'Other Responses';
            }

            rows.push({
                number: number,
                question: text,
                sectionTitle: sectionTitle,
                answer: String(value == null ? '' : value).trim() || 'N/A'
            });
        });
    }

    pushAnswers(ratings);
    pushAnswers(qualitative);
    rows.sort(function (a, b) { return a.number - b.number; });

    const grouped = [];
    rows.forEach(function (row) {
        let section = grouped.find(function (s) { return s.title === row.sectionTitle; });
        if (!section) {
            section = { title: row.sectionTitle, items: [] };
            grouped.push(section);
        }
        section.items.push({
            number: row.number,
            question: row.question,
            answer: row.answer
        });
    });

    const comments = String((record && record.comments) || '').trim();
    if (comments) {
        grouped.push({
            title: 'General Comments',
            items: [{
                number: '',
                question: 'Comment',
                answer: comments
            }]
        });
    }

    return grouped;
}

/**
 * Load and display user information
 */
function loadUserInfo() {
    const session = SharedData.getSession();
    if (session) {
        try {
            const studentUser = resolveCurrentStudentUser(session);
            const displayName = studentUser && studentUser.name
                ? studentUser.name
                : (session.username || 'Student');
            const academicYear = (SharedData.getSettings && SharedData.getSettings().academicYear) || '2025-2026';
            const yearSection = studentUser ? (studentUser.yearSection || '') : '';
            const yearLevel = parseYearLevel(yearSection);
            const campus = toTitleWords(studentUser ? studentUser.campus : '');
            const departmentRaw = studentUser ? (studentUser.department || studentUser.institute || '') : '';
            const department = String(departmentRaw || '').trim().toUpperCase();
            const program = department || 'N/A';
            const studentId = studentUser ? (studentUser.studentNumber || session.username || '') : (session.username || '');
            const status = toTitleWords(studentUser ? (studentUser.status || 'active') : '');

            // Top-right profile button
            const userProfileSpan = document.querySelector('.user-profile span');
            if (userProfileSpan) {
                userProfileSpan.textContent = displayName;
            }

            // Header quick panel
            setTextById('profileStudentIdMini', studentId);
            setTextById('profileProgramMini', program);

            // Profile view
            setTextById('profileStudentName', displayName);
            setTextById('profileStudentId', studentId);
            setTextById('profileStudentProgram', program);
            setTextById('profileStudentEmail', studentUser ? studentUser.email : '');
            setTextById('profileStudentCampus', campus);
            setTextById('profileStudentDepartment', department);
            setTextById('profileStudentYearSection', yearSection);
            setTextById('profileStudentYearLevel', yearLevel);
            setTextById('profileStudentStatus', status);
            setTextById('profileAcademicYear', academicYear);
        } catch (e) {
            console.error('Error loading user info:', e);
        }
    }
}

function renderStudentAnnouncements() {
    const list = document.querySelector('#announcementPanel .panel-list');
    if (!list) return;

    const listItems = SharedData.getAnnouncementsForCurrentUser
        ? SharedData.getAnnouncementsForCurrentUser({ limit: 5 })
        : (SharedData.getAnnouncements ? SharedData.getAnnouncements() : []);
    const announcements = (Array.isArray(listItems) ? listItems : []).slice(0, 5).map(item => ({
        title: String(item && item.title || '').trim() || 'Announcement',
        message: String(item && item.message || '').trim() || 'No details available.',
    }));

    if (!announcements.length) {
        list.innerHTML = `
            <li>
                <div class="panel-title">No announcements</div>
                <div class="panel-meta">There are currently no posted updates.</div>
            </li>
        `;
        return;
    }

    list.innerHTML = announcements.map(item => `
        <li>
            <div class="panel-title">${escapeHtml(item.title)}</div>
            <div class="panel-meta">${escapeHtml(item.message)}</div>
        </li>
    `).join('');
}

function escapeHtml(value) {
    return String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function renderAssignedEvaluationList() {
    const listContainer = document.querySelector('.evaluations-list');
    if (!listContainer) return;

    const subjectManagement = SharedData.getSubjectManagement ? SharedData.getSubjectManagement() : null;
    const offerings = (subjectManagement && Array.isArray(subjectManagement.offerings)) ? subjectManagement.offerings : [];
    const enrollments = (subjectManagement && Array.isArray(subjectManagement.enrollments)) ? subjectManagement.enrollments : [];
    const activeSemester = getActiveSemesterId();
    const session = getUserSession() || {};
    const currentStudent = resolveCurrentStudentUser(session);

    if (!currentStudent || !currentStudent.id) {
        listContainer.innerHTML = `
            <div class="empty-state" style="text-align:center; padding:2rem 1rem;">
                <i class="fas fa-user-lock" style="font-size:2rem; color:#cbd5e1; margin-bottom:0.75rem;"></i>
                <p>Unable to resolve your student profile for evaluation assignments.</p>
            </div>
        `;
        return;
    }

    const activeOfferingById = new Map(
        offerings
            .filter(item => item && item.isActive && String(item.semesterSlug || '') === String(activeSemester))
            .map(item => [String(item.id), item])
    );

    const assignedEnrollments = enrollments.filter(item =>
        item &&
        String(item.studentUserId || '') === String(currentStudent.id) &&
        String(item.status || '').toLowerCase() === 'enrolled' &&
        activeOfferingById.has(String(item.courseOfferingId))
    );

    if (!assignedEnrollments.length) {
        listContainer.innerHTML = `
            <div class="empty-state" style="text-align:center; padding:2rem 1rem;">
                <i class="fas fa-clipboard-check" style="font-size:2rem; color:#cbd5e1; margin-bottom:0.75rem;"></i>
                <p>No assigned evaluations for the current semester yet.</p>
            </div>
        `;
        return;
    }

    const dueDates = SharedData.getEvalPeriodDates ? SharedData.getEvalPeriodDates('student-professor') : { end: '' };
    const dueText = dueDates && dueDates.end ? dueDates.end : 'Not set';

    const rows = assignedEnrollments
        .map(item => {
            const offering = activeOfferingById.get(String(item.courseOfferingId));
            if (!offering) return null;
            return {
                offeringId: offering.id,
                professorName: offering.professorName || 'Unknown Professor',
                subjectCode: offering.subjectCode || 'N/A',
                subjectName: offering.subjectName || '',
                sectionName: offering.sectionName || '',
                dueText,
            };
        })
        .filter(Boolean)
        .sort((a, b) => {
            const professorCompare = String(a.professorName).localeCompare(String(b.professorName));
            if (professorCompare !== 0) return professorCompare;
            const subjectCompare = String(a.subjectCode).localeCompare(String(b.subjectCode));
            if (subjectCompare !== 0) return subjectCompare;
            return String(a.sectionName).localeCompare(String(b.sectionName));
        });

    listContainer.innerHTML = rows.map(row => `
        <div class="evaluation-item" data-offering-id="${escapeHtml(row.offeringId)}">
            <div class="evaluation-info">
                <div class="teacher-name">${escapeHtml(row.professorName)}</div>
                <span class="status-badge pending">Pending</span>
                <div class="course-info">
                    <span class="course-code">${escapeHtml(row.subjectCode)}</span>
                    <span class="course-name">- ${escapeHtml(row.subjectName)} (${escapeHtml(row.sectionName)})</span>
                </div>
                <div class="due-date">Due: ${escapeHtml(row.dueText)}</div>
            </div>
            <button class="btn-start">Start Evaluation</button>
        </div>
    `).join('');
}

/**
 * Setup announcement and profile panels
 */
function setupHeaderPanels() {
    if (studentHeaderPanelsBound) {
        return;
    }

    const notificationBtn = document.getElementById('notificationBtn');
    const profileBtn = document.getElementById('profileBtn');
    const announcementPanel = document.getElementById('announcementPanel');
    const profilePanel = document.getElementById('profilePanel');

    if (!notificationBtn || !profileBtn || !announcementPanel || !profilePanel) {
        return;
    }

    notificationBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        renderStudentAnnouncements();
        togglePanel(announcementPanel, profilePanel);
    });

    profileBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        togglePanel(profilePanel, announcementPanel);
    });

    document.addEventListener('click', function () {
        announcementPanel.classList.remove('active');
        profilePanel.classList.remove('active');
    });

    announcementPanel.addEventListener('click', function (e) {
        e.stopPropagation();
    });

    profilePanel.addEventListener('click', function (e) {
        e.stopPropagation();
    });

    studentHeaderPanelsBound = true;
}

/**
 * Toggle a panel and close the other one
 * @param {HTMLElement} panelToToggle
 * @param {HTMLElement} panelToClose
 */
function togglePanel(panelToToggle, panelToClose) {
    const isActive = panelToToggle.classList.contains('active');
    panelToClose.classList.remove('active');
    panelToToggle.classList.toggle('active', !isActive);
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

            // Switch views
            if (view) {
                switchView(view);
            }
        });
    });
}

/**
 * Switch between dashboard and evaluation form views
 * @param {string} viewName - Name of the view to show ('dashboard' or 'evaluationForm')
 */
function switchView(viewName) {
    const dashboardView = document.getElementById('dashboardView');
    const evaluationFormView = document.getElementById('evaluationFormView');
    const profileView = document.getElementById('profileView');
    const historyView = document.getElementById('historyView');
    const pageTitle = document.getElementById('mainPageTitle');

    if (viewName === 'dashboard') {
        if (pageTitle) pageTitle.textContent = 'Student Dashboard';
        dashboardView.style.display = 'block';
        evaluationFormView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (historyView) historyView.style.display = 'none';
        renderAssignedEvaluationList();
        refreshEvaluationStatuses();
        updateSummaryCards();
    } else if (viewName === 'evaluationForm') {
        if (pageTitle) pageTitle.textContent = 'Evaluation Form';
        dashboardView.style.display = 'none';
        evaluationFormView.style.display = 'block';
        if (profileView) profileView.style.display = 'none';
        if (historyView) historyView.style.display = 'none';
        // Scroll to top
        window.scrollTo(0, 0);

        // Load dynamic questionnaire
        if (typeof loadDynamicQuestionnaire === 'function') {
            loadDynamicQuestionnaire();
        }
    } else if (viewName === 'profile') {
        if (pageTitle) pageTitle.textContent = 'Profile';
        dashboardView.style.display = 'none';
        evaluationFormView.style.display = 'none';
        if (profileView) profileView.style.display = 'block';
        if (historyView) historyView.style.display = 'none';
        window.scrollTo(0, 0);
    } else if (viewName === 'history') {
        if (pageTitle) pageTitle.textContent = 'History';
        dashboardView.style.display = 'none';
        evaluationFormView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (historyView) historyView.style.display = 'block';
        window.scrollTo(0, 0);
    }
}

/**
 * Handle navigation to different sections
 * @param {string} section - Section name
 */
function handleNavigation(section) {
    // Placeholder for future navigation functionality

    // Example: You can add logic here to show/hide different sections
    // or load different content based on the section
    switch (section) {
        case 'Home':
            // Already on home page
            break;
        case 'Evaluation Forms':
            // Load evaluation forms
            break;
        case 'History':
            // Load history
            break;
        default:
            break;
    }
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
    // You can add a toast notification here
}

/**
 * Setup evaluation action buttons
 */
function setupEvaluationButtons() {
    const evaluationsList = document.querySelector('.evaluations-list');
    if (!evaluationsList) return;

    evaluationsList.addEventListener('click', function (event) {
        const button = event.target.closest('.btn-start');
        if (!button) return;

        const evaluationItem = button.closest('.evaluation-item');
        if (!evaluationItem) return;

        const professorNode = evaluationItem.querySelector('.teacher-name') || evaluationItem.querySelector('.professor-name');
        const courseNode = evaluationItem.querySelector('.course-code');
        const professorName = professorNode ? professorNode.textContent.trim() : '';
        const courseCode = courseNode ? courseNode.textContent.trim() : '';
        const courseOfferingId = String(evaluationItem.getAttribute('data-offering-id') || '').trim();

        if (!professorName || !courseCode) {
            showErrorMessage('Could not determine selected professor/subject. Please try another evaluation item.');
            return;
        }

        handleStartEvaluation(professorName, courseCode, courseOfferingId);
    });
}

/**
 * Handle start evaluation action
 * @param {string} professorName - Professor's name
 * @param {string} courseCode - Course code
 */
function handleStartEvaluation(professorName, courseCode, courseOfferingId) {
    // ── Evaluation period gate ──
    if (!SharedData.isEvalPeriodOpen('student-professor')) {
        const dates = SharedData.getEvalPeriodDates('student-professor');
        let msg = 'The Student to Professor evaluation period is not currently open.';
        if (dates.start && dates.end) {
            msg += '\nEvaluation period: ' + dates.start + ' to ' + dates.end + '.';
        } else {
            msg += '\nNo evaluation period has been set by the administrator yet.';
        }
        alert(msg);
        return;
    }

    const session = getUserSession() || {};
    const studentIdentity = buildCurrentStudentIdentity(session);
    const studentId = studentIdentity.primaryStudentId;
    const semesterId = getActiveSemesterId();
    if (isSubmittedEvaluation(studentId, semesterId, professorName, studentIdentity, courseOfferingId, courseCode)) {
        alert('You already submitted an evaluation for this professor this semester.');
        refreshEvaluationStatuses();
        updateSummaryCards();
        return;
    }

    removeAutosaveTimer();

    // Store professor and course info for the form to use
    sessionStorage.setItem('selectedProfessor', professorName);
    sessionStorage.setItem('selectedCourse', courseCode);
    sessionStorage.setItem('selectedEvaluationTarget', `${professorName} - ${courseCode}`);
    if (courseOfferingId) {
        sessionStorage.setItem('selectedCourseOfferingId', courseOfferingId);
    } else {
        sessionStorage.removeItem('selectedCourseOfferingId');
    }

    // Switch to evaluation form view
    switchView('evaluationForm');

    // Update navigation active state
    const navLinks = document.querySelectorAll('.nav-link:not(.logout)');
    navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('data-view') === 'evaluationForm') {
            link.classList.add('active');
        }
    });

    updateEvaluationTargetIndicator();
}

/**
 * Setup submit new evaluation button
 */
function setupSubmitNewButton() {
    const submitNewBtn = document.querySelector('.btn-submit-new');

    if (submitNewBtn) {
        submitNewBtn.addEventListener('click', function () {
            handleSubmitNewEvaluation();
        });
    }
}

/**
 * Handle submit new evaluation action
 */
function handleSubmitNewEvaluation() {
    // ── Evaluation period gate ──
    if (!SharedData.isEvalPeriodOpen('student-professor')) {
        const dates = SharedData.getEvalPeriodDates('student-professor');
        let msg = 'The Student to Professor evaluation period is not currently open.';
        if (dates.start && dates.end) {
            msg += '\nEvaluation period: ' + dates.start + ' to ' + dates.end + '.';
        } else {
            msg += '\nNo evaluation period has been set by the administrator yet.';
        }
        alert(msg);
        return;
    }

    alert('Please click "Start Evaluation" on a professor card to choose who you will evaluate.');
}

/**
 * Update summary cards with dynamic data
 */
function updateSummaryCards() {
    // Count pending and completed evaluations
    const pendingCount = document.querySelectorAll('.status-badge.pending').length;
    const completedCount = document.querySelectorAll('.status-badge.completed').length;
    const totalCount = pendingCount + completedCount;

    // Update card numbers
    const pendingCard = document.querySelector('.summary-card.pending .card-number');
    const completedCard = document.querySelector('.summary-card.completed .card-number');
    const totalCard = document.querySelector('.summary-card.total .card-number');

    if (pendingCard) pendingCard.textContent = pendingCount;
    if (completedCard) completedCard.textContent = completedCount;
    if (totalCard) totalCard.textContent = totalCount;
}

/**
 * Refresh evaluation list (for future use)
 */
function refreshEvaluationList() {
    // Placeholder for future API call to refresh evaluations

    // Future: Fetch from API and update DOM
    // fetchEvaluations().then(data => {
    //     renderEvaluations(data);
    // });
}

/**
 * Setup evaluation form functionality
 */
function setupEvaluationForm() {
    const form = document.getElementById('evaluationForm');
    if (!form) return;

    setupFormSubmission();
    setupCancelButton();
    setupDraftSaveButton();
    setupDraftAutosaveListeners();
    updateDraftStatusIndicator({ state: 'idle' });
    if (typeof setupRatingInputs === 'function') {
        setupRatingInputs();
    }
}

/**
 * Load dynamic questionnaire from SharedData
 */
function loadDynamicQuestionnaire() {
    const container = document.getElementById('dynamic-questions-container');
    if (!container) return;

    const currentSemester = SharedData.getCurrentSemester();
    const questionnaires = SharedData.getQuestionnaires();

    console.log('[Student] loadDynamicQuestionnaire — semester:', JSON.stringify(currentSemester), '| available keys:', Object.keys(questionnaires));

    let data = null;
    if (currentSemester && questionnaires[currentSemester]) {
        data = questionnaires[currentSemester];
    } else {
        // Find latest available semester as fallback
        const semesters = Object.keys(questionnaires).sort().reverse();
        if (semesters.length > 0) {
            data = questionnaires[semesters[0]];
        } else {
            data = {};
        }
    }

    const questionnaire = data['student-to-professor'] || { sections: [], questions: [], header: {} };
    updateEvaluationTargetIndicator();

    // Update headers if they exist
    const dynamicTitle = document.getElementById('dynamic-form-title');
    const dynamicDesc = document.getElementById('dynamic-form-description');

    if (dynamicTitle && questionnaire.header && questionnaire.header.title) {
        dynamicTitle.textContent = questionnaire.header.title;
    } else if (dynamicTitle) {
        dynamicTitle.textContent = 'Student Professor Evaluation Form';
    }

    if (dynamicDesc && questionnaire.header && questionnaire.header.description) {
        dynamicDesc.textContent = questionnaire.header.description;
    } else if (dynamicDesc) {
        dynamicDesc.textContent = 'Please rate your Professor honestly and constructively. Your feedback helps improve the quality of education.';
    }

    if ((!questionnaire.sections || questionnaire.sections.length === 0) && (!questionnaire.questions || questionnaire.questions.length === 0)) {
        container.innerHTML = `
            <div class="empty-state" style="text-align: center; padding: 3rem 1rem;">
                <i class="fas fa-clipboard-list" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                <p>No evaluation questionnaire available for this semester.</p>
            </div>
        `;
        return;
    }

    const sortedSections = [...(questionnaire.sections || [])].sort((a, b) => (a.letter || '').localeCompare(b.letter || ''));

    let html = '';
    let globalIndex = 0;
    let sectionStepIndex = 0;

    html += `
        <div class="eval-form-progress" id="student-form-progress">
            <div class="eval-form-progress-header">
                <span class="eval-form-progress-label">Progress</span>
                <span class="eval-form-progress-meta" id="student-progress-meta">Section 1 of 1</span>
            </div>
            <div class="eval-form-progress-track">
                <div class="eval-form-progress-fill" id="student-progress-fill" style="width: 0%;"></div>
            </div>
        </div>
    `;

    sortedSections.forEach(section => {
        const sectionQuestions = (questionnaire.questions || [])
            .filter(q => q.sectionId === section.id)
            .sort((a, b) => (a.order || 0) - (b.order || 0));

        html += `
            <div class="question-section eval-step" data-step-index="${sectionStepIndex}">
                <div class="section-header">
                    <div class="section-title-group">
                        <h2 class="section-letter">${section.letter}.</h2>
                        <div class="section-title-content">
                            <h2 class="section-title">${section.title}</h2>
                            <p class="section-description">${section.description}</p>
                        </div>
                    </div>
                </div>
                <div class="section-questions">
        `;

        if (sectionQuestions.length > 0) {
            sectionQuestions.forEach(question => {
                globalIndex++;
                html += renderQuestionHTML(question, globalIndex);
            });
        }

        html += `
                </div>
            </div>
        `;
        sectionStepIndex++;
    });

    const questionsWithoutSection = (questionnaire.questions || []).filter(q => !q.sectionId).sort((a, b) => (a.order || 0) - (b.order || 0));
    if (questionsWithoutSection.length > 0) {
        html += `
            <div class="question-section eval-step" data-step-index="${sectionStepIndex}">
                <div class="section-header">
                    <div class="section-title-group">
                        <div class="section-title-content">
                            <h2 class="section-title">General Questions</h2>
                        </div>
                    </div>
                </div>
                <div class="section-questions">
        `;
        questionsWithoutSection.forEach(question => {
            globalIndex++;
            html += renderQuestionHTML(question, globalIndex);
        });
        html += `
                </div>
            </div>
        `;
        sectionStepIndex++;
    }

    html += `
        <div class="eval-form-nav" id="student-form-nav">
            <button type="button" class="btn-eval-nav btn-eval-prev" id="student-prev-btn" disabled>
                <i class="fas fa-arrow-left"></i>
                Back
            </button>
            <button type="button" class="btn-eval-nav btn-eval-next" id="student-next-btn">
                Next
                <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    `;

    container.innerHTML = html;

    setupSectionFlow();
    restoreDraftForCurrentTarget();

    if (typeof setupRatingInputs === 'function') {
        setupRatingInputs();
    }
}

function normalizeValue(value) {
    return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
}

function getActiveSemesterId() {
    const value = (SharedData.getCurrentSemester && SharedData.getCurrentSemester()) || '';
    return String(value || '').trim() || 'current';
}

function buildEvaluationKey(studentId, semesterId, targetId) {
    return [
        normalizeValue(studentId),
        normalizeValue(semesterId),
        normalizeValue(targetId)
    ].join('|');
}

function parseProfessorSubject(rawValue) {
    const value = String(rawValue || '').trim();
    if (!value) return { professorName: '', subjectCode: '' };
    const parts = value.split(' - ');
    if (parts.length < 2) return { professorName: value, subjectCode: '' };
    const subjectCode = parts.pop().trim();
    const professorName = parts.join(' - ').trim();
    return { professorName, subjectCode };
}

function getSelectedTargetParts() {
    const explicit = parseProfessorSubject(sessionStorage.getItem('selectedEvaluationTarget'));
    if (explicit.professorName && explicit.subjectCode) return explicit;

    const professorName = String(sessionStorage.getItem('selectedProfessor') || '').trim();
    const subjectCode = String(sessionStorage.getItem('selectedCourse') || '').trim();
    return { professorName, subjectCode };
}

function getSelectedCourseOfferingId() {
    return String(sessionStorage.getItem('selectedCourseOfferingId') || '').trim();
}

function escapeSelectorToken(value) {
    const token = String(value || '');
    if (window.CSS && typeof window.CSS.escape === 'function') {
        return window.CSS.escape(token);
    }
    return token.replace(/([ !"#$%&'()*+,./:;<=>?@[\\\]^`{|}~])/g, '\\$1');
}

function getCurrentQuestionnaireForDraft() {
    const currentSemester = SharedData.getCurrentSemester && SharedData.getCurrentSemester();
    const questionnaires = (SharedData.getQuestionnaires && SharedData.getQuestionnaires()) || {};

    let data = null;
    if (currentSemester && questionnaires[currentSemester]) {
        data = questionnaires[currentSemester];
    } else {
        const semesters = Object.keys(questionnaires).sort().reverse();
        data = semesters.length ? questionnaires[semesters[0]] : {};
    }

    return data && data['student-to-professor'] ? data['student-to-professor'] : { sections: [], questions: [], header: {} };
}

function buildEvaluationDraftContext() {
    const studentIdentity = buildCurrentStudentIdentity(getUserSession() || {});
    const targetParts = getSelectedTargetParts();
    const selectedOfferingId = getSelectedCourseOfferingId();
    const semesterId = String(studentIdentity.primarySemesterId || getActiveSemesterId() || '').trim();
    const studentId = String(studentIdentity.primaryStudentId || '').trim();
    const studentUserId = String(studentIdentity.primaryStudentUserId || '').trim();
    const identityBase = studentId || studentUserId;
    const targetId = selectedOfferingId || targetParts.professorName;
    const draftKey = identityBase && semesterId && targetId
        ? buildEvaluationKey(identityBase, semesterId, targetId)
        : '';

    return {
        studentId,
        studentUserId,
        semesterId,
        selectedOfferingId,
        targetProfessor: targetParts.professorName,
        targetSubjectCode: targetParts.subjectCode,
        draftKey,
        valid: Boolean(identityBase && semesterId && targetParts.professorName && targetParts.subjectCode && draftKey)
    };
}

function draftMatchesContext(draft, context) {
    if (!draft || !context) return false;
    if (normalizeValue(draft.draftKey) !== normalizeValue(context.draftKey)) return false;
    const draftUserToken = normalizeValue(draft.studentUserId);
    const draftStudentToken = normalizeValue(draft.studentId);
    const contextUserToken = normalizeValue(context.studentUserId);
    const contextStudentToken = normalizeValue(context.studentId);
    return (contextUserToken && draftUserToken && contextUserToken === draftUserToken)
        || (contextStudentToken && draftStudentToken && contextStudentToken === draftStudentToken);
}

function findCurrentEvaluationDraft(context) {
    const drafts = (SharedData.getStudentEvaluationDrafts && SharedData.getStudentEvaluationDrafts()) || [];
    return drafts.find(function (item) {
        return draftMatchesContext(item, context);
    }) || null;
}

function setQuestionFieldValue(form, questionId, value) {
    const safeId = escapeSelectorToken(questionId);
    const byName = form.querySelector(`[name="${safeId}"]`);
    const byId = document.getElementById(questionId);
    const field = byName || byId;
    if (!field) return false;
    const fieldType = String(field.type || '').toLowerCase();
    if (fieldType === 'radio' || fieldType === 'checkbox') {
        return false;
    }
    field.value = String(value || '');
    return true;
}

function applyDraftToForm(draft) {
    const form = document.getElementById('evaluationForm');
    if (!form || !draft) return;

    evaluationDraftState.suppressAutosave = true;
    try {
        const ratings = draft && typeof draft.ratings === 'object' && draft.ratings ? draft.ratings : {};
        const qualitative = draft && typeof draft.qualitative === 'object' && draft.qualitative ? draft.qualitative : {};

        Object.keys(ratings).forEach(function (questionId) {
            const value = String(ratings[questionId] || '').trim();
            if (!value) return;

            const safeName = escapeSelectorToken(questionId);
            const safeValue = escapeSelectorToken(value);
            const radio = form.querySelector(`input[type="radio"][name="${safeName}"][value="${safeValue}"]`);
            if (radio) {
                radio.checked = true;
                return;
            }

            setQuestionFieldValue(form, questionId, value);
        });

        Object.keys(qualitative).forEach(function (questionId) {
            const value = String(qualitative[questionId] || '').trim();
            setQuestionFieldValue(form, questionId, value);
        });

        const commentsField = form.querySelector('[name="comments"]');
        if (commentsField) {
            commentsField.value = String(draft.comments || '');
        }
    } finally {
        evaluationDraftState.suppressAutosave = false;
    }
}

function formatDraftSavedAt(value) {
    const text = String(value || '').trim();
    if (!text) return '';
    const date = new Date(text);
    if (Number.isNaN(date.getTime())) return text;
    return date.toLocaleString();
}

function updateDraftStatusIndicator(options) {
    const statusEl = document.getElementById('evaluation-draft-status');
    if (!statusEl) return;

    const cfg = options || {};
    const state = String(cfg.state || 'idle').trim();
    statusEl.classList.remove('is-saved', 'is-error');

    if (state === 'saved') {
        const savedAt = formatDraftSavedAt(cfg.updatedAt || evaluationDraftState.lastSavedAt);
        statusEl.textContent = savedAt ? `Last saved: ${savedAt}` : 'Draft saved.';
        statusEl.classList.add('is-saved');
        return;
    }

    if (state === 'pending') {
        statusEl.textContent = 'Unsaved changes...';
        return;
    }

    if (state === 'error') {
        statusEl.textContent = String(cfg.message || 'Unable to save draft.');
        statusEl.classList.add('is-error');
        return;
    }

    statusEl.textContent = 'Draft not saved yet.';
}

function collectDraftPayload() {
    const form = document.getElementById('evaluationForm');
    if (!form) return null;

    const context = buildEvaluationDraftContext();
    if (!context.valid) return null;

    const questionnaire = getCurrentQuestionnaireForDraft();
    const questions = Array.isArray(questionnaire.questions) ? questionnaire.questions : [];
    const ratings = {};
    const qualitative = {};

    questions.forEach(function (question) {
        const questionId = String(question && question.id || '').trim();
        if (!questionId) return;

        const questionType = String(question.type || '').trim().toLowerCase();
        if (questionType === 'rating') {
            const safeName = escapeSelectorToken(questionId);
            const checked = form.querySelector(`input[type="radio"][name="${safeName}"]:checked`);
            if (checked && String(checked.value || '').trim() !== '') {
                ratings[questionId] = String(checked.value).trim();
            }
            return;
        }

        const safeName = escapeSelectorToken(questionId);
        const field = form.querySelector(`[name="${safeName}"]`) || document.getElementById(questionId);
        if (!field) return;
        const value = String(field.value || '').trim();
        if (value === '') return;

        if (questionType === 'qualitative') {
            qualitative[questionId] = value;
        } else {
            ratings[questionId] = value;
        }
    });

    const commentsField = form.querySelector('[name="comments"]');
    const comments = commentsField ? String(commentsField.value || '').trim() : '';
    const targetInput = document.getElementById('evaluation-target-value');

    return {
        draftKey: context.draftKey,
        studentId: context.studentId,
        studentUserId: context.studentUserId,
        semesterId: context.semesterId,
        courseOfferingId: context.selectedOfferingId || '',
        targetProfessor: context.targetProfessor,
        targetSubjectCode: context.targetSubjectCode,
        professorSubject: (targetInput && targetInput.value.trim()) || `${context.targetProfessor} - ${context.targetSubjectCode}`,
        ratings: ratings,
        qualitative: qualitative,
        comments: comments,
        updatedAt: new Date().toISOString(),
        status: 'draft'
    };
}

function persistEvaluationDraft(options) {
    const cfg = options || {};
    const silent = cfg.silent === true;

    if (!enforceActiveStudentAccount({ inline: !silent })) {
        return { success: false, error: new Error('Account is inactive') };
    }

    const payload = collectDraftPayload();

    if (!payload) {
        return { success: false, skipped: true };
    }

    try {
        const response = SharedData.upsertStudentEvaluationDraft
            ? SharedData.upsertStudentEvaluationDraft(payload)
            : { success: false, error: 'Draft persistence is unavailable.' };

        if (!response || response.success !== true) {
            throw new Error(response && response.error ? response.error : 'Failed to save draft.');
        }

        const savedDraft = response.draft || payload;
        evaluationDraftState.lastSavedAt = String(savedDraft.updatedAt || payload.updatedAt || '').trim();
        evaluationDraftState.lastDraftKey = payload.draftKey;
        updateDraftStatusIndicator({ state: 'saved', updatedAt: evaluationDraftState.lastSavedAt });

        if (!silent) {
            showSuccessMessage('Draft saved.');
        }

        return { success: true, draft: savedDraft };
    } catch (error) {
        console.error('[StudentDraft] Failed to save draft.', error);
        updateDraftStatusIndicator({
            state: 'error',
            message: 'Draft save failed. Try again.'
        });
        if (!silent) {
            showErrorMessage(error && error.message ? error.message : 'Failed to save draft. Please try again.');
        }
        return { success: false, error: error };
    }
}

function removeAutosaveTimer() {
    if (evaluationDraftState.autosaveTimer) {
        clearTimeout(evaluationDraftState.autosaveTimer);
        evaluationDraftState.autosaveTimer = null;
    }
}

function queueDraftAutosave() {
    if (evaluationDraftState.suppressAutosave) return;

    const context = buildEvaluationDraftContext();
    if (!context.valid) return;

    removeAutosaveTimer();
    updateDraftStatusIndicator({ state: 'pending' });
    evaluationDraftState.autosaveTimer = setTimeout(() => {
        evaluationDraftState.autosaveTimer = null;
        persistEvaluationDraft({ silent: true, source: 'autosave' });
    }, 900);
}

function clearCurrentEvaluationDraft(options) {
    const cfg = options || {};
    const silent = cfg.silent === true;
    removeAutosaveTimer();

    if (!enforceActiveStudentAccount({ inline: !silent })) {
        return { success: false, error: new Error('Account is inactive') };
    }

    const context = buildEvaluationDraftContext();
    if (!context.valid || !context.draftKey) {
        evaluationDraftState.lastSavedAt = '';
        evaluationDraftState.lastDraftKey = '';
        updateDraftStatusIndicator({ state: 'idle' });
        return { success: true, skipped: true };
    }

    try {
        const response = SharedData.removeStudentEvaluationDraft
            ? SharedData.removeStudentEvaluationDraft(context.draftKey, {
                studentUserId: context.studentUserId,
                studentId: context.studentId
            })
            : { success: true };

        if (response && response.success === false) {
            throw new Error(response.error || 'Failed to clear draft.');
        }

        evaluationDraftState.lastSavedAt = '';
        evaluationDraftState.lastDraftKey = '';
        updateDraftStatusIndicator({ state: 'idle' });
        return { success: true };
    } catch (error) {
        console.error('[StudentDraft] Failed to clear draft.', error);
        updateDraftStatusIndicator({
            state: 'error',
            message: 'Failed to clear draft.'
        });
        if (!silent) {
            showErrorMessage(error && error.message ? error.message : 'Failed to clear draft.');
        }
        return { success: false, error: error };
    }
}

function restoreDraftForCurrentTarget() {
    const context = buildEvaluationDraftContext();
    if (!context.valid) {
        evaluationDraftState.lastSavedAt = '';
        evaluationDraftState.lastDraftKey = '';
        updateDraftStatusIndicator({ state: 'idle' });
        return;
    }

    const draft = findCurrentEvaluationDraft(context);
    evaluationDraftState.lastDraftKey = context.draftKey;

    if (!draft) {
        evaluationDraftState.lastSavedAt = '';
        updateDraftStatusIndicator({ state: 'idle' });
        return;
    }

    applyDraftToForm(draft);
    evaluationDraftState.lastSavedAt = String(draft.updatedAt || '').trim();
    updateDraftStatusIndicator({ state: 'saved', updatedAt: evaluationDraftState.lastSavedAt });
}

function handleManualDraftSave() {
    persistEvaluationDraft({ silent: false, source: 'manual' });
}

function setupDraftSaveButton() {
    const saveBtn = document.getElementById('saveDraftBtn');
    if (!saveBtn || saveBtn.dataset.bound === 'true') return;

    saveBtn.addEventListener('click', handleManualDraftSave);
    saveBtn.dataset.bound = 'true';
}

function setupDraftAutosaveListeners() {
    const form = document.getElementById('evaluationForm');
    if (!form || form.dataset.draftAutosaveBound === 'true') return;

    const queueAutosave = function (event) {
        const target = event.target;
        if (!target) return;
        const fieldName = String(target.name || '').trim();
        if (fieldName === 'evaluationTarget') return;
        queueDraftAutosave();
    };

    form.addEventListener('input', queueAutosave);
    form.addEventListener('change', queueAutosave);
    form.dataset.draftAutosaveBound = 'true';
}

function isSubmittedEvaluation(studentId, semesterId, professorName, studentIdentityOverride, courseOfferingIdOverride, subjectCodeOverride) {
    const evaluations = (SharedData.getEvaluations && SharedData.getEvaluations()) || [];
    const offeringId = String(courseOfferingIdOverride || '').trim();
    const subjectCode = String(subjectCodeOverride || '').trim();
    const offeringKey = offeringId ? buildEvaluationKey(studentId, semesterId, offeringId) : '';
    const subjectKey = subjectCode ? buildEvaluationKey(studentId, semesterId, `${professorName}|${subjectCode}`) : '';
    const studentIdentity = studentIdentityOverride || buildCurrentStudentIdentity(getUserSession() || {});
    const professorToken = normalizeValue(professorName);
    const subjectToken = normalizeValue(subjectCode);

    return evaluations.some(ev => {
        if (!evaluationBelongsToStudent(ev, studentIdentity)) return false;
        const status = String(ev && ev.status || 'submitted').toLowerCase().trim();
        if (status && status !== 'submitted') return false;

        const existingKey = String(ev.evaluationKey || '').trim();
        const keyParts = existingKey.split('|');
        const keySemester = keyParts.length >= 3 ? normalizeValue(keyParts[1]) : '';
        const evSemester = normalizeValue(ev.semesterId || keySemester);
        if (evSemester && evSemester !== normalizeValue(semesterId)) return false;

        if (offeringId) {
            if (offeringKey && existingKey && normalizeValue(existingKey) === normalizeValue(offeringKey)) {
                return true;
            }

            const evOfferingId = String(ev.courseOfferingId || '').trim();
            if (evOfferingId && normalizeValue(evOfferingId) === normalizeValue(offeringId)) {
                return true;
            }

            const existingKeyParts = existingKey.split('|');
            if (existingKeyParts.length >= 3 && normalizeValue(existingKeyParts[2]) === normalizeValue(offeringId)) {
                return true;
            }

            // If an offering ID is present, never fall back to professor-level matching.
            return false;
        }

        const targetProfessor = String(ev.targetProfessor || '').trim();
        const targetSubjectCode = String(ev.targetSubjectCode || '').trim();
        let compareProfessor = targetProfessor;
        let compareSubjectCode = targetSubjectCode;

        if (!compareProfessor) {
            const parsed = parseProfessorSubject(ev.professorSubject || '');
            compareProfessor = compareProfessor || parsed.professorName;
            compareSubjectCode = compareSubjectCode || parsed.subjectCode;
        }

        const compareProfessorToken = normalizeValue(compareProfessor);
        const compareSubjectToken = normalizeValue(compareSubjectCode);

        if (subjectToken) {
            if (compareProfessorToken !== professorToken) {
                return false;
            }

            if (compareSubjectToken && compareSubjectToken === subjectToken) {
                return true;
            }

            if (subjectKey && existingKey && normalizeValue(existingKey) === normalizeValue(subjectKey)) {
                return true;
            }

            return false;
        }

        return compareProfessorToken === professorToken;
    });
}

function refreshEvaluationStatuses() {
    const studentIdentity = buildCurrentStudentIdentity(getUserSession() || {});
    const studentId = studentIdentity.primaryStudentId;
    const semesterId = studentIdentity.primarySemesterId;
    const items = document.querySelectorAll('.evaluations-list .evaluation-item');

    items.forEach(item => {
        const professorNode = item.querySelector('.teacher-name') || item.querySelector('.professor-name');
        const courseNode = item.querySelector('.course-code');
        const statusBadge = item.querySelector('.status-badge');
        const startBtn = item.querySelector('.btn-start');
        const existingSubmitted = item.querySelector('.submitted-text');

        if (!professorNode || !courseNode || !statusBadge || !startBtn) return;

        const professorName = professorNode.textContent.trim();
        const offeringId = String(item.getAttribute('data-offering-id') || '').trim();
        const subjectCode = courseNode.textContent.trim();
        const submitted = isSubmittedEvaluation(studentId, semesterId, professorName, studentIdentity, offeringId, subjectCode);

        statusBadge.classList.toggle('pending', !submitted);
        statusBadge.classList.toggle('completed', submitted);
        statusBadge.textContent = submitted ? 'Completed' : 'Pending';

        startBtn.disabled = submitted;
        startBtn.textContent = submitted ? 'Submitted' : 'Start Evaluation';
        startBtn.setAttribute('aria-disabled', submitted ? 'true' : 'false');

        if (submitted) {
            item.classList.add('evaluation-item-completed');
            item.style.display = 'none';
            if (!existingSubmitted) {
                const submittedText = document.createElement('span');
                submittedText.className = 'submitted-text';
                submittedText.textContent = 'Submitted';
                item.appendChild(submittedText);
            }
        } else {
            item.classList.remove('evaluation-item-completed');
            item.style.display = '';
            if (existingSubmitted) {
                existingSubmitted.remove();
            }
        }
    });
}

/**
 * Render single question HTML snippet
 */
function renderQuestionHTML(question, index) {
    if (question.type === 'rating') {
        const maxRating = parseInt(question.ratingScale.split('-')[1]) || 5;
        let ratingHtml = `
            <div class="question-group">
                <label class="question-label">${index}. ${question.text}${question.required ? ' <span style="color:red">*</span>' : ''}</label>
                <div class="rating-scale">
        `;

        for (let i = 1; i <= maxRating; i++) {
            ratingHtml += `
                    <input type="radio" name="${question.id}" id="${question.id}-${i}" value="${i}" ${question.required ? 'required' : ''}>
                    <label for="${question.id}-${i}" class="rating-option">${i}</label>
            `;
        }

        ratingHtml += `
                </div>
                <p class="rating-legend">${maxRating} = Excellent, 1 = Poor</p>
            </div>
        `;
        return ratingHtml;
    } else {
        return `
            <div class="question-group">
                <label class="question-label" for="${question.id}">${index}. ${question.text}${question.required ? ' <span style="color:red">*</span>' : ''}</label>
                <textarea id="${question.id}" name="${question.id}" class="form-textarea" rows="4" placeholder="Your answer..." ${question.required ? 'required' : ''}></textarea>
            </div>
        `;
    }
}

function setupSectionFlow() {
    const steps = Array.from(document.querySelectorAll('#dynamic-questions-container .eval-step'));

    evaluationSectionFlow.sections = steps;
    evaluationSectionFlow.activeIndex = 0;

    if (!steps.length) {
        const submitBtn = document.querySelector('#evaluationForm .btn-submit');
        const progress = document.getElementById('student-form-progress');
        const nav = document.getElementById('student-form-nav');
        if (progress) progress.style.display = 'none';
        if (nav) nav.style.display = 'none';
        if (submitBtn) submitBtn.style.display = 'inline-flex';
        return;
    }

    const backBtn = document.getElementById('student-prev-btn');
    const nextBtn = document.getElementById('student-next-btn');
    if (backBtn) {
        backBtn.onclick = () => {
            persistEvaluationDraft({ silent: true, source: 'step-nav' });
            goToSectionStep(evaluationSectionFlow.activeIndex - 1);
        };
    }

    if (nextBtn) {
        nextBtn.onclick = () => {
            if (!validateCurrentStep()) return;
            persistEvaluationDraft({ silent: true, source: 'step-nav' });
            goToSectionStep(evaluationSectionFlow.activeIndex + 1);
        };
    }

    goToSectionStep(0);
}

function goToSectionStep(index) {
    const steps = evaluationSectionFlow.sections || [];
    if (!steps.length) return;

    const maxIndex = steps.length - 1;
    evaluationSectionFlow.activeIndex = Math.max(0, Math.min(index, maxIndex));

    steps.forEach((step, stepIndex) => {
        const isActive = stepIndex === evaluationSectionFlow.activeIndex;
        step.classList.toggle('is-active', isActive);
        toggleStepInputs(step, isActive);
    });

    const backBtn = document.getElementById('student-prev-btn');
    const nextBtn = document.getElementById('student-next-btn');
    const progressFill = document.getElementById('student-progress-fill');
    const progressMeta = document.getElementById('student-progress-meta');
    const submitBtn = document.querySelector('#evaluationForm .btn-submit');

    const isFirst = evaluationSectionFlow.activeIndex === 0;
    const isLast = evaluationSectionFlow.activeIndex === maxIndex;
    const progressPercent = ((evaluationSectionFlow.activeIndex + 1) / steps.length) * 100;

    if (progressFill) progressFill.style.width = `${progressPercent}%`;
    if (progressMeta) progressMeta.textContent = `Section ${evaluationSectionFlow.activeIndex + 1} of ${steps.length}`;
    if (backBtn) backBtn.disabled = isFirst;
    if (nextBtn) nextBtn.style.display = isLast ? 'none' : 'inline-flex';
    if (submitBtn) submitBtn.style.display = isLast ? 'inline-flex' : 'none';
}

function toggleStepInputs(stepElement, enabled) {
    if (!stepElement) return;
    const fields = stepElement.querySelectorAll('input, textarea, select');
    fields.forEach(field => {
        field.disabled = !enabled;
    });
}

function validateCurrentStep() {
    const currentStep = evaluationSectionFlow.sections[evaluationSectionFlow.activeIndex];
    if (!currentStep) return true;

    const requiredFields = Array.from(currentStep.querySelectorAll('input[required], textarea[required], select[required]'));
    for (const field of requiredFields) {
        if (field.type === 'radio') {
            const group = currentStep.querySelectorAll(`input[name="${field.name}"]`);
            const checked = Array.from(group).some(radio => radio.checked);
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

function enableAllSectionInputs() {
    const allFields = document.querySelectorAll('#dynamic-questions-container .eval-step input, #dynamic-questions-container .eval-step textarea, #dynamic-questions-container .eval-step select');
    allFields.forEach(field => {
        field.disabled = false;
    });
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
        showErrorMessage('Please fill out all password fields.');
        return;
    }

    if (newPassword !== confirmPassword) {
        showErrorMessage('New password and confirmation do not match.');
        return;
    }

    const payload = {
        username: getUserSession() ? getUserSession().username : '',
        currentPassword,
        newPassword
    };

    showSuccessMessage('Password update request ready for SQL connection.');

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
 * Setup history view (filters + search + read-only answers)
 */
function setupHistoryView() {
    const historyList = document.getElementById('historyList');
    const filterBtn = document.getElementById('historyFilterBtn');
    const aySelect = document.getElementById('historyAy');
    const semSelect = document.getElementById('historySem');
    const searchInput = document.getElementById('historySearch');
    const modal = document.getElementById('historyModal');
    const modalClose = document.getElementById('historyModalClose');

    if (!historyList || !filterBtn || !aySelect || !semSelect || !searchInput) {
        return;
    }

    const applyFilters = () => {
        const filters = {
            ay: aySelect.value,
            sem: semSelect.value,
            term: searchInput.value.trim()
        };
        loadHistory(filters);
    };

    filterBtn.addEventListener('click', applyFilters);
    aySelect.addEventListener('change', applyFilters);
    semSelect.addEventListener('change', applyFilters);
    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            applyFilters();
        }
    });

    if (modal && modalClose) {
        modalClose.addEventListener('click', closeHistoryModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeHistoryModal();
        });
    }

    // Initial load
    applyFilters();
}

let cachedHistoryRecords = [];

/**
 * Load history records (SQL-ready placeholder)
 */
function loadHistory(filters) {
    const studentIdentity = buildCurrentStudentIdentity(getUserSession() || {});

    fetchHistoryFromSql({
        studentIdentity,
        ay: filters.ay,
        sem: filters.sem,
        term: filters.term
    }).then(records => {
        cachedHistoryRecords = records;
        renderHistoryList(records);
    }).catch(() => {
        renderHistoryList([]);
    });
}

/**
 * SQL-ready fetch (replace with real API call)
 */
function fetchHistoryFromSql(query) {
    const evaluations = (SharedData.getEvaluations && SharedData.getEvaluations()) || [];
    const settings = (SharedData.getSettings && SharedData.getSettings()) || {};
    const currentAcademicYear = String(settings.academicYear || '').trim();

    const mapped = evaluations
        .filter(function (record) {
            const status = String(record && record.status || 'submitted').toLowerCase().trim();
            if (status && status !== 'submitted') {
                return false;
            }
            return evaluationBelongsToStudent(record, query.studentIdentity);
        })
        .map(function (record, index) {
            const professorSubject = parseProfessorSubject(record.professorSubject || '');
            const faculty = String(record.targetProfessor || professorSubject.professorName || 'Unknown Professor').trim();
            const subject = String(record.targetSubjectCode || professorSubject.subjectCode || 'N/A').trim();
            const semesterRaw = String(record.semesterId || '').trim();
            const questionnaire = getStudentQuestionnaireForSemester(semesterRaw);
            const questionMeta = buildHistoryQuestionMeta(questionnaire);
            const academicYear = extractAcademicYear(semesterRaw) || currentAcademicYear || 'N/A';
            const sem = extractSemesterNumber(semesterRaw);
            const submittedRaw = String(record.submittedAt || record.timestamp || '').trim();

            return {
                id: String(record.id || ('history_' + index)),
                ay: academicYear,
                sem: sem || 'N/A',
                faculty: faculty,
                subject: subject,
                submittedAt: formatSubmittedAt(submittedRaw),
                submittedAtRaw: submittedRaw,
                answerSections: buildHistoryAnswerSections(record, questionMeta)
            };
        });

    const term = normalizeValue(query.term);
    const filtered = mapped.filter(function (record) {
        const ayMatch = query.ay === 'all' || record.ay === query.ay;
        const semMatch = query.sem === 'all' || record.sem === query.sem;
        const termMatch = !term
            || normalizeValue(record.faculty).includes(term)
            || normalizeValue(record.subject).includes(term);
        return ayMatch && semMatch && termMatch;
    }).sort(function (a, b) {
        const dateA = Date.parse(a.submittedAtRaw || '') || 0;
        const dateB = Date.parse(b.submittedAtRaw || '') || 0;
        return dateB - dateA;
    });

    return Promise.resolve(filtered);
}

/**
 * Render history list
 */
function renderHistoryList(records) {
    const historyList = document.getElementById('historyList');
    if (!historyList) return;

    if (!records.length) {
        historyList.innerHTML = '<div class="history-empty">No submissions found for the selected filters.</div>';
        return;
    }

    historyList.innerHTML = records.map(record => `
        <div class="history-item">
            <div class="history-info">
                <div class="history-faculty">${record.faculty}</div>
                <div class="history-subject">${record.subject}</div>
                <div class="history-date">Submitted: ${record.submittedAt}</div>
            </div>
            <button class="btn-view-answers" data-id="${record.id}">View Answers</button>
        </div>
    `).join('');

    historyList.querySelectorAll('.btn-view-answers').forEach(button => {
        button.addEventListener('click', function () {
            const id = String(this.getAttribute('data-id') || '');
            const record = cachedHistoryRecords.find(item => String(item.id) === id);
            if (record) openHistoryModal(record);
        });
    });
}

/**
 * Open history modal (read-only)
 */
function openHistoryModal(record) {
    const modal = document.getElementById('historyModal');
    const modalBody = document.getElementById('historyModalBody');
    if (!modal || !modalBody) return;

    modalBody.innerHTML = `
        <div class="answer-item">
            <h4>Faculty</h4>
            <p>${record.faculty}</p>
        </div>
        <div class="answer-item">
            <h4>Subject</h4>
            <p>${record.subject}</p>
        </div>
        ${(record.answerSections || []).map(section => `
            <div class="answer-item">
                <h4>${section.title}</h4>
                <div>
                    ${section.items.map(item => `
                        <p><strong>${item.number ? (item.number + '.') : ''} ${item.question}</strong><br>${item.answer}</p>
                    `).join('')}
                </div>
            </div>
        `).join('')}
    `;

    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
}

function closeHistoryModal() {
    const modal = document.getElementById('historyModal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
}

/**
 * Setup form submission
 */
function setupFormSubmission() {
    const form = document.getElementById('evaluationForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        handleFormSubmission();
    });
}

/**
 * Handle form submission
 */
function handleFormSubmission() {
    if (!enforceActiveStudentAccount({ inline: true })) {
        return;
    }

    // ── Evaluation period gate ──
    if (!SharedData.isEvalPeriodOpen('student-professor')) {
        const dates = SharedData.getEvalPeriodDates('student-professor');
        let msg = 'The Student to Professor evaluation period is not currently open.';
        if (dates.start && dates.end) {
            msg += '\nEvaluation period: ' + dates.start + ' to ' + dates.end + '.';
        } else {
            msg += '\nNo evaluation period has been set by the administrator yet.';
        }
        showErrorMessage(msg);
        return;
    }

    const form = document.getElementById('evaluationForm');
    const submitBtn = form.querySelector('.btn-submit');
    const originalText = submitBtn.textContent;
    const targetValueInput = document.getElementById('evaluation-target-value');
    const studentIdentity = buildCurrentStudentIdentity(getUserSession() || {});
    const studentId = studentIdentity.primaryStudentId;
    const semesterId = studentIdentity.primarySemesterId;
    const targetParts = getSelectedTargetParts();
    const selectedOfferingId = getSelectedCourseOfferingId();

    if (!targetValueInput || !targetValueInput.value.trim()) {
        showErrorMessage('Please select a professor from the Dashboard first before submitting an evaluation.');
        switchView('dashboard');
        updateNavigation('dashboard');
        return;
    }

    if (!targetParts.professorName || !targetParts.subjectCode) {
        showErrorMessage('Selected evaluation target is incomplete. Please select the professor from Dashboard again.');
        switchView('dashboard');
        updateNavigation('dashboard');
        return;
    }

    if (isSubmittedEvaluation(studentId, semesterId, targetParts.professorName, studentIdentity, selectedOfferingId, targetParts.subjectCode)) {
        showErrorMessage('This evaluation was already submitted for the selected professor this semester.');
        switchView('dashboard');
        updateNavigation('dashboard');
        refreshEvaluationStatuses();
        updateSummaryCards();
        return;
    }

    removeAutosaveTimer();
    enableAllSectionInputs();

    // Validate form
    if (!form.checkValidity()) {
        const firstInvalid = form.querySelector(':invalid');
        if (firstInvalid) {
            const targetStep = firstInvalid.closest('.eval-step');
            if (targetStep) {
                const targetIndex = parseInt(targetStep.getAttribute('data-step-index'), 10);
                if (!Number.isNaN(targetIndex)) {
                    goToSectionStep(targetIndex);
                }
            }
        }
        form.reportValidity();
        return;
    }

    // Get form data
    const formData = collectFormData();

    // Show loading state
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';

    // Simulate API call (replace with actual API call)
    setTimeout(() => {
        // Submit to API
        submitEvaluation(formData)
            .then(response => {
                showSuccessMessage('Evaluation submitted successfully!');
                // Reset form after short delay
                setTimeout(() => {
                    clearCurrentEvaluationDraft({ silent: true });
                    form.reset();
                    clearSelectedEvaluationTarget();
                    updateEvaluationTargetIndicator();
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                    const ayEl = document.getElementById('historyAy');
                    const semEl = document.getElementById('historySem');
                    const termEl = document.getElementById('historySearch');
                    loadHistory({
                        ay: ayEl ? ayEl.value : 'all',
                        sem: semEl ? semEl.value : 'all',
                        term: termEl ? termEl.value.trim() : ''
                    });
                    // Switch back to dashboard
                    switchView('dashboard');
                    updateNavigation('dashboard');
                    refreshEvaluationStatuses();
                    updateSummaryCards();
                }, 2000);
            })
            .catch(error => {
                const errorMessage = error && error.message ? String(error.message) : '';
                if (errorMessage.toLowerCase().includes('inactive')) {
                    enforceActiveStudentAccount({ inline: true });
                } else {
                    showErrorMessage(errorMessage || 'Failed to submit evaluation. Please try again.');
                }
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            });
    }, 1500);
}

/**
 * Collect form data
 * @returns {Object} - Form data object
 */
function collectFormData() {
    const form = document.getElementById('evaluationForm');
    const formData = new FormData(form);

    const ratingsGroup = {};
    const qualitativeGroup = {};

    // Get current questionnaire to tell apart rating vs. qualitative types
    const currentSemester = SharedData.getCurrentSemester();
    const questionnaires = SharedData.getQuestionnaires();
    const qData = questionnaires[currentSemester] || {};
    const questionnaire = qData['student-to-professor'] || { questions: [] };
    const allQuestions = questionnaire.questions || [];
    const targetParts = getSelectedTargetParts();
    const session = getUserSession() || {};
    const studentIdentity = buildCurrentStudentIdentity(session);
    const studentId = studentIdentity.primaryStudentId;
    const studentUserId = studentIdentity.primaryStudentUserId;
    const semesterId = studentIdentity.primarySemesterId;
    const selectedOfferingId = getSelectedCourseOfferingId();
    const evaluationKey = buildEvaluationKey(studentId, semesterId, selectedOfferingId || targetParts.professorName);

    // Dynamically collect elements
    for (let [key, value] of formData.entries()) {
        if (key === 'evaluationTarget' || key === 'comments') continue;

        let questionDef = allQuestions.find(q => String(q.id) === key);
        if (questionDef && questionDef.type === 'qualitative') {
            qualitativeGroup[key] = value;
        } else {
            // Treat as rating or default
            ratingsGroup[key] = value;
        }
    }

    const data = {
        professorSubject: formData.get('evaluationTarget') || `${targetParts.professorName} - ${targetParts.subjectCode}`,
        evaluationKey,
        targetProfessor: targetParts.professorName,
        targetSubjectCode: targetParts.subjectCode,
        semesterId,
        courseOfferingId: selectedOfferingId || '',
        ratings: ratingsGroup,
        qualitative: qualitativeGroup,
        comments: formData.get('comments') || '',
        submittedAt: new Date().toISOString(),
        status: 'submitted'
    };

    // Get user session
    if (studentId) {
        data.studentId = studentId;
    }
    if (studentUserId) {
        data.studentUserId = studentUserId;
    }

    return data;
}

/**
 * Submit evaluation to API
 * @param {Object} data - Form data
 * @returns {Promise} - API response
 */
function submitEvaluation(data) {
    return new Promise((resolve, reject) => {
        try {
            // Get user session metadata
            const session = SharedData.getSession() || {};
            const evaluatorData = {
                evaluatorRole: 'student',
                evaluatorName: session.fullName || 'Anonymous Student',
                evaluatorUsername: session.username || 'unknown',
                evaluationType: 'student',
                ...data
            };

            // Save via centralized API
            SharedData.addEvaluation(evaluatorData);

            // Add to activity log
            SharedData.addActivityLogEntry({
                type: 'evaluation_submitted',
                title: 'Evaluation Submitted',
                user: evaluatorData.evaluatorName,
                role: 'student',
                date: new Date().toISOString()
            });

            setTimeout(() => {
                resolve({ success: true, message: 'Evaluation submitted successfully to local database' });
            }, 600); // UI feedback delay
        } catch (error) {
            reject(error);
        }
    });
}

/**
 * Setup cancel button
 */
function setupCancelButton() {
    const cancelBtn = document.getElementById('cancelBtn');
    if (!cancelBtn) return;

    cancelBtn.addEventListener('click', function () {
        if (confirm('Are you sure you want to cancel? All unsaved changes will be lost.')) {
            clearCurrentEvaluationDraft({ silent: true });
            const form = document.getElementById('evaluationForm');
            if (form) {
                form.reset();
            }
            switchView('dashboard');
            updateNavigation('dashboard');
        }
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

/**
 * Show selected professor/subject in Evaluation Form
 */
function updateEvaluationTargetIndicator() {
    const textEl = document.getElementById('evaluation-target-text');
    const hiddenEl = document.getElementById('evaluation-target-value');
    const submitBtn = document.querySelector('#evaluationForm .btn-submit');
    const saveDraftBtn = document.getElementById('saveDraftBtn');
    const selectedTarget = getSelectedEvaluationTarget();
    const targetParts = getSelectedTargetParts();
    const selectedOfferingId = getSelectedCourseOfferingId();
    const studentIdentity = buildCurrentStudentIdentity(getUserSession() || {});
    const studentId = studentIdentity.primaryStudentId;
    const semesterId = studentIdentity.primarySemesterId;
    const isLocked = targetParts.professorName
        ? isSubmittedEvaluation(studentId, semesterId, targetParts.professorName, studentIdentity, selectedOfferingId, targetParts.subjectCode)
        : false;

    if (textEl) {
        textEl.textContent = selectedTarget || 'No professor selected yet';
    }
    if (hiddenEl) {
        hiddenEl.value = selectedTarget || '';
    }
    if (submitBtn) {
        submitBtn.disabled = !selectedTarget || isLocked;
    }
    if (saveDraftBtn) {
        saveDraftBtn.disabled = !selectedTarget || isLocked;
    }
    if (!selectedTarget) {
        updateDraftStatusIndicator({ state: 'idle' });
    }
}

function getSelectedEvaluationTarget() {
    const explicit = (sessionStorage.getItem('selectedEvaluationTarget') || '').trim();
    if (explicit) return explicit;

    const professor = (sessionStorage.getItem('selectedProfessor') || '').trim();
    const course = (sessionStorage.getItem('selectedCourse') || '').trim();
    if (professor && course) return `${professor} - ${course}`;
    return '';
}

function clearSelectedEvaluationTarget() {
    sessionStorage.removeItem('selectedEvaluationTarget');
    sessionStorage.removeItem('selectedProfessor');
    sessionStorage.removeItem('selectedCourse');
    sessionStorage.removeItem('selectedCourseOfferingId');
}

/**
 * Setup rating inputs for better UX
 */
function setupRatingInputs() {
    const ratingInputs = document.querySelectorAll('.rating-scale input[type="radio"]');

    ratingInputs.forEach(input => {
        input.addEventListener('change', function () {
            // Add visual feedback
            const label = this.nextElementSibling;
            if (label) {
                // Animate the selected rating
                label.style.transform = 'scale(1.1)';
                setTimeout(() => {
                    label.style.transform = 'scale(1.05)';
                }, 200);
            }
        });
    });
}

/**
 * Show success message
 * @param {string} message - Success message
 */
function showSuccessMessage(message) {
    // Remove existing messages
    const existingMessage = document.querySelector('.form-message');
    if (existingMessage) {
        existingMessage.remove();
    }

    // Create success message
    const messageDiv = document.createElement('div');
    messageDiv.className = 'form-message success';
    messageDiv.textContent = message;
    messageDiv.style.cssText = `
        background-color: #d1fae5;
        color: #065f46;
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        text-align: center;
        font-weight: 600;
        border: 1px solid #10b981;
        animation: fadeIn 0.3s ease;
    `;

    const form = document.getElementById('evaluationForm');
    if (form) {
        form.insertBefore(messageDiv, form.firstChild);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            messageDiv.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => messageDiv.remove(), 300);
        }, 5000);
    }
}

/**
 * Show error message
 * @param {string} message - Error message
 */
function showErrorMessage(message) {
    // Remove existing messages
    const existingMessage = document.querySelector('.form-message');
    if (existingMessage) {
        existingMessage.remove();
    }

    // Create error message
    const messageDiv = document.createElement('div');
    messageDiv.className = 'form-message error';
    messageDiv.textContent = message;
    messageDiv.style.cssText = `
        background-color: #fee2e2;
        color: #991b1b;
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        text-align: center;
        font-weight: 600;
        border: 1px solid #ef4444;
        animation: fadeIn 0.3s ease;
    `;

    const form = document.getElementById('evaluationForm');
    if (form) {
        form.insertBefore(messageDiv, form.firstChild);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            messageDiv.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => messageDiv.remove(), 300);
        }, 5000);
    }
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
        handleStartEvaluation,
        handleSubmitNewEvaluation,
        updateSummaryCards
    };
}

