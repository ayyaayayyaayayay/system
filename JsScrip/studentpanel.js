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
let evaluationBehaviorCapture = {
    captureKey: '',
    startedAt: ''
};
let studentHeaderPanelsBound = false;
let studentProofModalBound = false;
let studentMobileDrawerBound = false;
let studentMobileHeaderScrollBound = false;
let studentLastHeaderScrollY = 0;

/**
 * Check if user is authenticated
 * @returns {boolean} - True if user is authenticated
 */
/**
 * Check if user is authenticated
 * @returns {boolean} - True if user is authenticated
 */
function checkAuthentication() {
    const session = SharedData.requireSession('student');
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
    setupMobileDrawer();
    setupHeaderPanels();
    setupMobileHeaderScrollBehavior();
    renderStudentAnnouncements();
    renderAssignedEvaluationList();
    updateEvaluationAvailabilityUi();
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
    setupStudentProofModal();
    refreshStudentProofRequirement();
    setupStudentHeroActions();

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
            key === SharedData.KEYS.CURRENT_SEMESTER ||
            key === SharedData.KEYS.EVAL_PERIODS ||
            key === SharedData.KEYS.STUDENT_EVAL_PROOF_REQUESTS
        ) {
            renderAssignedEvaluationList();
            refreshEvaluationStatuses();
            updateSummaryCards();
            updateEvaluationAvailabilityUi();
            renderStudentAnnouncements();
            refreshStudentProofRequirement();
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

function getStudentEvaluationAssignmentState() {
    const session = getUserSession() || {};
    const currentStudent = resolveCurrentStudentUser(session);
    const studentIdentity = buildCurrentStudentIdentity(session);
    const semesterId = getActiveSemesterId();
    const periodOpen = SharedData.isEvalPeriodOpen
        ? SharedData.isEvalPeriodOpen('student-professor')
        : false;
    const periodDates = SharedData.getEvalPeriodDates
        ? SharedData.getEvalPeriodDates('student-professor')
        : { start: '', end: '' };

    if (!currentStudent || !currentStudent.id) {
        return {
            ready: false,
            isPeriodOpen: periodOpen,
            periodDates,
            assignedRows: [],
            pendingRows: [],
            completedRows: [],
            completedCount: 0,
            totalAssigned: 0,
            canAccessEvaluationForm: false,
            message: 'Unable to resolve your student profile for evaluation assignments.'
        };
    }

    const subjectManagement = SharedData.getSubjectManagement ? SharedData.getSubjectManagement() : null;
    const offerings = (subjectManagement && Array.isArray(subjectManagement.offerings)) ? subjectManagement.offerings : [];
    const enrollments = (subjectManagement && Array.isArray(subjectManagement.enrollments)) ? subjectManagement.enrollments : [];
    const dueText = periodDates && periodDates.end ? periodDates.end : 'Not set';
    const activeOfferingById = new Map(
        offerings
            .filter(item => item && item.isActive && String(item.semesterSlug || '') === String(semesterId))
            .map(item => [String(item.id), item])
    );

    const assignedRows = enrollments
        .filter(item =>
            item &&
            String(item.studentUserId || '') === String(currentStudent.id) &&
            String(item.status || '').toLowerCase() === 'enrolled' &&
            activeOfferingById.has(String(item.courseOfferingId || ''))
        )
        .map(item => {
            const offeringId = String(item.courseOfferingId || '').trim();
            const offering = activeOfferingById.get(offeringId);
            if (!offering) return null;

            const professorName = String(offering.professorName || '').trim();
            const subjectCode = String(offering.subjectCode || '').trim();
            if (!professorName || !subjectCode) return null;

            const row = {
                offeringId: String(offering.id || offeringId).trim(),
                professorName,
                subjectCode,
                subjectName: String(offering.subjectName || '').trim(),
                sectionName: String(offering.sectionName || '').trim(),
                dueText,
                offering
            };
            row.submitted = isSubmittedEvaluation(
                studentIdentity.primaryStudentId,
                studentIdentity.primarySemesterId,
                row.professorName,
                studentIdentity,
                row.offeringId,
                row.subjectCode
            );
            return row;
        })
        .filter(Boolean)
        .sort((a, b) => {
            const professorCompare = String(a.professorName).localeCompare(String(b.professorName));
            if (professorCompare !== 0) return professorCompare;
            const subjectCompare = String(a.subjectCode).localeCompare(String(b.subjectCode));
            if (subjectCompare !== 0) return subjectCompare;
            return String(a.sectionName).localeCompare(String(b.sectionName));
        });

    const completedRows = assignedRows.filter(row => row.submitted);
    const pendingRows = assignedRows.filter(row => !row.submitted);
    const totalAssigned = assignedRows.length;
    const completedCount = completedRows.length;
    const canAccessEvaluationForm = periodOpen && totalAssigned > 0 && pendingRows.length > 0;

    let message = '';
    if (!periodOpen) {
        if (periodDates && periodDates.start && periodDates.end) {
            message = `The Student to Professor evaluation period is closed. Evaluation period: ${periodDates.start} to ${periodDates.end}.`;
        } else {
            message = 'The Student to Professor evaluation period is not currently open.';
        }
    } else if (totalAssigned === 0) {
        message = 'No assigned evaluations for the current semester yet.';
    } else if (!pendingRows.length) {
        message = 'You have completed all assigned professor evaluations for this semester.';
    } else {
        message = 'Select a pending professor evaluation from the Dashboard.';
    }

    return {
        ready: true,
        isPeriodOpen: periodOpen,
        periodDates,
        assignedRows,
        pendingRows,
        completedRows,
        completedCount,
        totalAssigned,
        canAccessEvaluationForm,
        message,
        currentStudent,
        studentIdentity,
        semesterId
    };
}

function validateSelectedEvaluationTarget(options) {
    const cfg = options || {};
    const state = cfg.assignmentState || getStudentEvaluationAssignmentState();
    const targetParts = getSelectedTargetParts();
    const selectedOfferingId = getSelectedCourseOfferingId();

    if (!state.ready) {
        return { valid: false, state, row: null, message: state.message };
    }
    if (!state.isPeriodOpen) {
        return { valid: false, state, row: null, message: state.message };
    }
    if (!selectedOfferingId) {
        return {
            valid: false,
            state,
            row: null,
            message: 'Please select an active pending professor evaluation from the Dashboard.'
        };
    }

    const row = state.assignedRows.find(item => String(item.offeringId) === String(selectedOfferingId));
    if (!row) {
        return {
            valid: false,
            state,
            row: null,
            message: 'This evaluation is no longer assigned to you. Please select an active pending professor evaluation from the Dashboard.'
        };
    }

    if (
        normalizeValue(row.professorName) !== normalizeValue(targetParts.professorName) ||
        normalizeValue(row.subjectCode) !== normalizeValue(targetParts.subjectCode)
    ) {
        return {
            valid: false,
            state,
            row,
            message: 'Selected evaluation target no longer matches your assigned professor and subject. Please select it from the Dashboard again.'
        };
    }

    if (row.submitted) {
        return {
            valid: false,
            state,
            row,
            message: 'This evaluation was already submitted for the selected professor this semester.'
        };
    }

    return { valid: true, state, row, message: '' };
}

function updateEvaluationAvailabilityUi(stateInput) {
    const state = stateInput || getStudentEvaluationAssignmentState();
    const formLink = document.querySelector('.nav-link[data-view="evaluationForm"]');
    const activeView = getCurrentStudentViewName();

    if (formLink) {
        formLink.hidden = !state.canAccessEvaluationForm;
        formLink.setAttribute('aria-hidden', state.canAccessEvaluationForm ? 'false' : 'true');
        formLink.tabIndex = state.canAccessEvaluationForm ? 0 : -1;
        formLink.classList.toggle('is-disabled', !state.canAccessEvaluationForm);
        formLink.title = state.canAccessEvaluationForm ? '' : state.message;
        if (!state.canAccessEvaluationForm && formLink.classList.contains('active')) {
            formLink.classList.remove('active');
        }
    }

    if (!state.canAccessEvaluationForm && activeView === 'evaluationForm') {
        clearUnavailableEvaluationContext();
        updateEvaluationTargetIndicator();
        switchView('dashboard', { reason: state.message });
        updateNavigation('dashboard');
        showErrorMessage(state.message || 'Evaluation forms are not available right now.');
    }
}

function getCurrentStudentViewName() {
    const dashboardView = document.getElementById('dashboardView');
    const evaluationFormView = document.getElementById('evaluationFormView');
    const profileView = document.getElementById('profileView');
    const historyView = document.getElementById('historyView');

    if (evaluationFormView && evaluationFormView.style.display !== 'none') return 'evaluationForm';
    if (profileView && profileView.style.display !== 'none') return 'profile';
    if (historyView && historyView.style.display !== 'none') return 'history';
    if (dashboardView && dashboardView.style.display !== 'none') return 'dashboard';
    return 'dashboard';
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
    const formatted = SharedData.formatDateTimeInPhilippines(raw);
    return formatted || raw;
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

    const state = getStudentEvaluationAssignmentState();

    if (!state.ready) {
        listContainer.innerHTML = `
            <div class="empty-state" style="text-align:center; padding:2rem 1rem;">
                <i class="fas fa-user-lock" style="font-size:2rem; color:#cbd5e1; margin-bottom:0.75rem;"></i>
                <p>Unable to resolve your student profile for evaluation assignments.</p>
            </div>
        `;
        updateEvaluationAvailabilityUi(state);
        return;
    }

    if (!state.assignedRows.length) {
        listContainer.innerHTML = `
            <div class="empty-state" style="text-align:center; padding:2rem 1rem;">
                <i class="fas fa-clipboard-check" style="font-size:2rem; color:#cbd5e1; margin-bottom:0.75rem;"></i>
                <p>${escapeHtml(state.message)}</p>
            </div>
        `;
        updateEvaluationAvailabilityUi(state);
        return;
    }

    if (!state.isPeriodOpen) {
        listContainer.innerHTML = `
            <div class="empty-state" style="text-align:center; padding:2rem 1rem;">
                <i class="fas fa-calendar-xmark" style="font-size:2rem; color:#f59e0b; margin-bottom:0.75rem;"></i>
                <p>${escapeHtml(state.message)}</p>
            </div>
        `;
        updateEvaluationAvailabilityUi(state);
        return;
    }

    if (!state.pendingRows.length) {
        listContainer.innerHTML = `
            <div class="empty-state" style="text-align:center; padding:2rem 1rem;">
                <i class="fas fa-check-circle" style="font-size:2rem; color:#22c55e; margin-bottom:0.75rem;"></i>
                <p>${escapeHtml(state.message)}</p>
            </div>
        `;
        updateEvaluationAvailabilityUi(state);
        return;
    }

    listContainer.innerHTML = state.pendingRows.map(row => `
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

    updateEvaluationAvailabilityUi(state);
}

function normalizeProofUserId(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    const prefixed = raw.match(/^u(\d+)$/i);
    if (prefixed) return `u${prefixed[1]}`;
    const numeric = raw.match(/^\d+$/);
    if (numeric) return `u${String(parseInt(raw, 10))}`;
    return raw.toLowerCase();
}

function getStudentEvaluationPeriodStateForProof() {
    const dates = SharedData.getEvalPeriodDates
        ? SharedData.getEvalPeriodDates('student-professor')
        : { start: '', end: '' };
    const endRaw = String(dates && dates.end || '').trim();
    if (!endRaw) {
        return { hasEndDate: false, isClosed: false, endDate: '' };
    }
    const todayYmd = SharedData.getCurrentPhilippineDateYmd();
    const isClosed = todayYmd !== '' && todayYmd > endRaw;
    return { hasEndDate: true, isClosed, endDate: endRaw };
}

function buildCurrentStudentCompletionSnapshotForProof() {
    const session = getUserSession() || {};
    const currentStudent = resolveCurrentStudentUser(session);
    if (!currentStudent || !currentStudent.id) {
        return { ready: false, hasIncomplete: false, totalAssigned: 0, completedCount: 0 };
    }

    const subjectManagement = SharedData.getSubjectManagement ? SharedData.getSubjectManagement() : null;
    const offerings = (subjectManagement && Array.isArray(subjectManagement.offerings)) ? subjectManagement.offerings : [];
    const enrollments = (subjectManagement && Array.isArray(subjectManagement.enrollments)) ? subjectManagement.enrollments : [];
    const semesterId = getActiveSemesterId();
    const studentIdentity = buildCurrentStudentIdentity(session);
    const activeOfferingById = new Map(
        offerings
            .filter(function (item) {
                return item && item.isActive && String(item.semesterSlug || '') === String(semesterId);
            })
            .map(function (item) {
                return [String(item.id), item];
            })
    );

    const assignedEnrollments = enrollments.filter(function (item) {
        return item
            && String(item.studentUserId || '') === String(currentStudent.id)
            && String(item.status || '').toLowerCase() === 'enrolled'
            && activeOfferingById.has(String(item.courseOfferingId || ''));
    });

    let completedCount = 0;
    assignedEnrollments.forEach(function (enrollment) {
        const offeringId = String(enrollment.courseOfferingId || '').trim();
        const offering = activeOfferingById.get(offeringId);
        if (!offering) return;
        const professorName = String(offering.professorName || '').trim();
        const subjectCode = String(offering.subjectCode || '').trim();
        if (!professorName) return;
        const submitted = isSubmittedEvaluation(
            studentIdentity.primaryStudentId,
            studentIdentity.primarySemesterId,
            professorName,
            studentIdentity,
            offeringId,
            subjectCode
        );
        if (submitted) {
            completedCount += 1;
        }
    });

    const totalAssigned = assignedEnrollments.length;
    const hasIncomplete = totalAssigned > 0 && completedCount < totalAssigned;

    return {
        ready: true,
        hasIncomplete,
        totalAssigned,
        completedCount,
        semesterId: studentIdentity.primarySemesterId,
        studentUserId: normalizeProofUserId(currentStudent.id),
        studentNumber: String(currentStudent.studentNumber || '').trim(),
        studentName: String(currentStudent.name || '').trim() || String(session.fullName || session.username || 'Student').trim(),
        studentEmail: String(currentStudent.email || session.email || '').trim(),
        studentUsername: String(session.username || '').trim(),
    };
}

function findLatestStudentProofRecordForCurrentStudent(snapshot) {
    const rows = SharedData.getStudentEvaluationProofRequests
        ? SharedData.getStudentEvaluationProofRequests()
        : [];
    if (!Array.isArray(rows) || !rows.length || !snapshot || !snapshot.ready) {
        return null;
    }

    const semesterToken = normalizeValue(snapshot.semesterId);
    const studentUserToken = normalizeValue(snapshot.studentUserId);
    const studentNumberToken = normalizeValue(snapshot.studentNumber);

    const filtered = rows.filter(function (row) {
        if (!row) return false;
        const rowSemester = normalizeValue(row.semesterId);
        if (semesterToken && rowSemester !== semesterToken) return false;

        const rowUser = normalizeValue(normalizeProofUserId(row.studentUserId));
        const rowNumber = normalizeValue(row.studentNumber);
        const userMatch = studentUserToken && rowUser && studentUserToken === rowUser;
        const numberMatch = studentNumberToken && rowNumber && studentNumberToken === rowNumber;
        return userMatch || numberMatch;
    });

    if (!filtered.length) return null;
    filtered.sort(function (a, b) {
        const aTime = Date.parse(String(a && (a.submittedAt || a.reviewedAt) || '')) || 0;
        const bTime = Date.parse(String(b && (b.submittedAt || b.reviewedAt) || '')) || 0;
        return bTime - aTime;
    });
    return filtered[0];
}

function isValidStudentDriveProofLink(value) {
    const urlText = String(value || '').trim();
    if (!urlText) return false;
    try {
        const parsed = new URL(urlText);
        const host = String(parsed.hostname || '').toLowerCase().replace(/^www\./, '');
        return (parsed.protocol === 'https:' || parsed.protocol === 'http:')
            && (host === 'drive.google.com' || host === 'docs.google.com');
    } catch (_error) {
        return false;
    }
}

function setStudentProofStatusMessage(type, message) {
    const statusEl = document.getElementById('studentProofStatusMessage');
    if (!statusEl) return;
    const text = String(message || '').trim();
    statusEl.className = 'proof-status-message';
    statusEl.textContent = '';
    if (!text) return;
    statusEl.classList.add(type === 'error' ? 'error' : (type === 'success' ? 'success' : 'info'));
    statusEl.textContent = text;
}

function closeStudentProofModal() {
    const modal = document.getElementById('studentProofModal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
}

function openStudentProofModal(options) {
    const cfg = options || {};
    const modal = document.getElementById('studentProofModal');
    const lead = document.getElementById('studentProofModalLead');
    const form = document.getElementById('studentProofForm');
    const pendingBox = document.getElementById('studentProofPendingBox');
    const reasonEl = document.getElementById('studentProofReason');
    const linkEl = document.getElementById('studentProofDriveLink');
    const submitBtn = document.getElementById('studentProofSubmitBtn');
    const titleEl = document.getElementById('studentProofModalTitle');
    if (!modal || !lead || !form || !pendingBox || !reasonEl || !linkEl || !submitBtn || !titleEl) return;

    const completion = cfg.completionSnapshot || { completedCount: 0, totalAssigned: 0 };
    const periodState = cfg.periodState || { endDate: '' };
    const proofRecord = cfg.record || null;
    const mode = cfg.mode === 'pending' ? 'pending' : 'submit';

    titleEl.textContent = mode === 'pending'
        ? 'Proof Under OSA Review'
        : 'Evaluation Proof Requirement';
    lead.textContent = `You completed ${completion.completedCount}/${completion.totalAssigned} evaluations. `
        + `The Student-to-Professor evaluation period ended on ${periodState.endDate || 'N/A'}. `
        + `Please provide your reason and Google Drive proof for OSA review.`;

    if (mode === 'pending') {
        form.style.display = 'none';
        pendingBox.style.display = 'block';
        const submittedText = proofRecord && proofRecord.submittedAt
            ? ` Submitted on ${SharedData.formatDateTimeInPhilippines(proofRecord.submittedAt)}.`
            : '';
        pendingBox.textContent = `Your submitted proof is under OSA review.${submittedText}`;
        setStudentProofStatusMessage('info', '');
    } else {
        form.style.display = '';
        pendingBox.style.display = 'none';
        reasonEl.value = proofRecord && proofRecord.status === 'rejected'
            ? String(proofRecord.reason || '')
            : '';
        linkEl.value = proofRecord && proofRecord.status === 'rejected'
            ? String(proofRecord.proofDriveLink || '')
            : '';
        reasonEl.disabled = false;
        linkEl.disabled = false;
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Proof';

        if (proofRecord && String(proofRecord.status || '').toLowerCase() === 'rejected') {
            const reviewNote = String(proofRecord.reviewNote || '').trim();
            const rejectedMessage = reviewNote
                ? `Your previous proof was rejected by OSA: ${reviewNote}`
                : 'Your previous proof was rejected by OSA. Please submit an updated reason and proof link.';
            setStudentProofStatusMessage('error', rejectedMessage);
        } else {
            setStudentProofStatusMessage('info', 'Submit your explanation and a valid Google Drive proof link.');
        }
    }

    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
}

function handleStudentProofFormSubmit(event) {
    event.preventDefault();

    const reasonEl = document.getElementById('studentProofReason');
    const linkEl = document.getElementById('studentProofDriveLink');
    const submitBtn = document.getElementById('studentProofSubmitBtn');
    if (!reasonEl || !linkEl || !submitBtn) return;

    const reason = String(reasonEl.value || '').trim();
    const proofDriveLink = String(linkEl.value || '').trim();
    if (!reason) {
        setStudentProofStatusMessage('error', 'Reason is required.');
        reasonEl.focus();
        return;
    }
    if (!proofDriveLink) {
        setStudentProofStatusMessage('error', 'Google Drive proof link is required.');
        linkEl.focus();
        return;
    }
    if (!isValidStudentDriveProofLink(proofDriveLink)) {
        setStudentProofStatusMessage('error', 'Please provide a valid Google Drive link.');
        linkEl.focus();
        return;
    }

    const periodState = getStudentEvaluationPeriodStateForProof();
    const completion = buildCurrentStudentCompletionSnapshotForProof();
    if (!periodState.isClosed || !completion.hasIncomplete) {
        closeStudentProofModal();
        return;
    }

    try {
        submitBtn.disabled = true;
        submitBtn.textContent = 'Submitting...';

        if (!SharedData.submitStudentEvaluationProof) {
            throw new Error('Proof submission service is unavailable.');
        }

        const response = SharedData.submitStudentEvaluationProof({
            studentUserId: completion.studentUserId,
            studentNumber: completion.studentNumber,
            studentName: completion.studentName,
            studentEmail: completion.studentEmail,
            studentUsername: completion.studentUsername,
            semesterId: completion.semesterId,
            reason: reason,
            proofDriveLink: proofDriveLink,
        });

        if (!response || response.success !== true) {
            throw new Error((response && response.error) || 'Failed to submit proof request.');
        }

        const savedRecord = response.record || {
            status: 'pending',
            reason: reason,
            proofDriveLink: proofDriveLink,
            submittedAt: SharedData.getNowIsoString(),
        };

        setStudentProofStatusMessage('success', 'Proof submitted. Waiting for OSA review.');
        openStudentProofModal({
            mode: 'pending',
            completionSnapshot: completion,
            periodState: periodState,
            record: savedRecord,
        });
    } catch (error) {
        setStudentProofStatusMessage('error', error && error.message ? error.message : 'Failed to submit proof request.');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Submit Proof';
    }
}

function setupStudentProofModal() {
    if (studentProofModalBound) return;
    const modal = document.getElementById('studentProofModal');
    const closeBtn = document.getElementById('studentProofModalClose');
    const laterBtn = document.getElementById('studentProofLaterBtn');
    const form = document.getElementById('studentProofForm');
    if (!modal || !closeBtn || !laterBtn || !form) return;

    closeBtn.addEventListener('click', closeStudentProofModal);
    laterBtn.addEventListener('click', closeStudentProofModal);
    form.addEventListener('submit', handleStudentProofFormSubmit);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeStudentProofModal();
        }
    });

    studentProofModalBound = true;
}

function refreshStudentProofRequirement() {
    const dashboardView = document.getElementById('dashboardView');
    if (!dashboardView || dashboardView.style.display === 'none') {
        return;
    }

    const periodState = getStudentEvaluationPeriodStateForProof();
    const completion = buildCurrentStudentCompletionSnapshotForProof();
    if (!completion.ready || !periodState.isClosed || !completion.hasIncomplete) {
        closeStudentProofModal();
        return;
    }

    const latestProof = findLatestStudentProofRecordForCurrentStudent(completion);
    const status = String(latestProof && latestProof.status || '').toLowerCase().trim();

    if (status === 'approved') {
        closeStudentProofModal();
        return;
    }

    if (status === 'pending') {
        openStudentProofModal({
            mode: 'pending',
            completionSnapshot: completion,
            periodState: periodState,
            record: latestProof,
        });
        return;
    }

    openStudentProofModal({
        mode: 'submit',
        completionSnapshot: completion,
        periodState: periodState,
        record: latestProof,
    });
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
    showStudentMobileHeader();
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
            if (view === 'evaluationForm') {
                const state = getStudentEvaluationAssignmentState();
                updateEvaluationAvailabilityUi(state);
                if (!state.canAccessEvaluationForm) {
                    showErrorMessage(state.message || 'Evaluation forms are not available right now.');
                    switchView('dashboard');
                    updateNavigation('dashboard');
                    return;
                }

                const targetValidation = validateSelectedEvaluationTarget({ assignmentState: state });
                if (!targetValidation.valid) {
                    clearUnavailableEvaluationContext();
                    updateEvaluationTargetIndicator();
                    showErrorMessage('Please select an active pending professor evaluation from the Dashboard.');
                    switchView('dashboard');
                    updateNavigation('dashboard');
                    return;
                }
            }

            // Remove active class from all links
            navLinks.forEach(l => l.classList.remove('active'));

            // Add active class to clicked link
            this.classList.add('active');

            // Switch views
            if (view) {
                switchView(view);
                closeMobileDrawer();
            }
        });
    });
}

/**
 * Switch between dashboard and evaluation form views
 * @param {string} viewName - Name of the view to show ('dashboard' or 'evaluationForm')
 */
function switchView(viewName, options) {
    const cfg = options || {};
    closeMobileDrawer();
    const dashboardView = document.getElementById('dashboardView');
    const evaluationFormView = document.getElementById('evaluationFormView');
    const profileView = document.getElementById('profileView');
    const historyView = document.getElementById('historyView');
    const pageTitle = document.getElementById('mainPageTitle');

    if (viewName === 'dashboard') {
        if (pageTitle) pageTitle.textContent = 'Student Dashboard';
        setPageContextText('Track evaluation progress and complete your forms on time.');
        dashboardView.style.display = 'block';
        evaluationFormView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (historyView) historyView.style.display = 'none';
        renderAssignedEvaluationList();
        refreshEvaluationStatuses();
        updateSummaryCards();
        refreshStudentProofRequirement();
    } else if (viewName === 'evaluationForm') {
        const assignmentState = getStudentEvaluationAssignmentState();
        const targetValidation = validateSelectedEvaluationTarget({ assignmentState });
        if (!assignmentState.canAccessEvaluationForm || !targetValidation.valid) {
            clearUnavailableEvaluationContext();
            updateEvaluationTargetIndicator();
            if (pageTitle) pageTitle.textContent = 'Student Dashboard';
            setPageContextText('Track evaluation progress and complete your forms on time.');
            dashboardView.style.display = 'block';
            evaluationFormView.style.display = 'none';
            if (profileView) profileView.style.display = 'none';
            if (historyView) historyView.style.display = 'none';
            renderAssignedEvaluationList();
            refreshEvaluationStatuses();
            updateSummaryCards();
            updateEvaluationAvailabilityUi(assignmentState);
            if (cfg.reason || targetValidation.message) {
                showErrorMessage(cfg.reason || targetValidation.message);
            }
            window.scrollTo(0, 0);
            return;
        }
        if (pageTitle) pageTitle.textContent = 'Evaluation Form';
        setPageContextText('Review each section carefully before submitting your evaluation.');
        dashboardView.style.display = 'none';
        evaluationFormView.style.display = 'block';
        if (profileView) profileView.style.display = 'none';
        if (historyView) historyView.style.display = 'none';
        closeStudentProofModal();
        // Scroll to top
        window.scrollTo(0, 0);

        // Load dynamic questionnaire
        if (typeof loadDynamicQuestionnaire === 'function') {
            loadDynamicQuestionnaire();
        }
        startEvaluationBehaviorCapture(false);
    } else if (viewName === 'profile') {
        if (pageTitle) pageTitle.textContent = 'Profile';
        setPageContextText('Review your student profile and update your account password.');
        dashboardView.style.display = 'none';
        evaluationFormView.style.display = 'none';
        if (profileView) profileView.style.display = 'block';
        if (historyView) historyView.style.display = 'none';
        closeStudentProofModal();
        window.scrollTo(0, 0);
    } else if (viewName === 'history') {
        if (pageTitle) pageTitle.textContent = 'History';
        setPageContextText('Browse your submitted evaluations and view past answers.');
        dashboardView.style.display = 'none';
        evaluationFormView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (historyView) historyView.style.display = 'block';
        closeStudentProofModal();
        window.scrollTo(0, 0);
    }
}

function setPageContextText(text) {
    const pageContext = document.getElementById('pageContextText');
    if (pageContext) {
        pageContext.textContent = String(text || '').trim();
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
    const logoutLink = document.getElementById('studentLogoutBtn');

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

function setupMobileDrawer() {
    if (studentMobileDrawerBound) return;

    const toggleBtn = document.getElementById('studentMenuToggle');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (!toggleBtn || !backdrop) return;

    toggleBtn.addEventListener('click', function () {
        const isOpen = document.body.classList.contains('student-sidebar-open');
        if (isOpen) {
            closeMobileDrawer();
        } else {
            openMobileDrawer();
        }
    });

    backdrop.addEventListener('click', closeMobileDrawer);
    window.addEventListener('resize', function () {
        if (window.innerWidth > 900) {
            closeMobileDrawer();
        }
    });

    studentMobileDrawerBound = true;
}

function openMobileDrawer() {
    document.body.classList.add('student-sidebar-open');
    showStudentMobileHeader();
    const toggleBtn = document.getElementById('studentMenuToggle');
    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
}

function closeMobileDrawer() {
    document.body.classList.remove('student-sidebar-open');
    const toggleBtn = document.getElementById('studentMenuToggle');
    if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
}

function setupMobileHeaderScrollBehavior() {
    if (studentMobileHeaderScrollBound) return;

    const header = document.querySelector('.student-panel .top-header');
    if (!header) return;

    studentLastHeaderScrollY = window.scrollY || 0;

    const handleScroll = function () {
        updateStudentMobileHeaderVisibility();
    };

    const handleResize = function () {
        if (!isStudentMobileHeaderMode()) {
            showStudentMobileHeader();
        }
        studentLastHeaderScrollY = window.scrollY || 0;
    };

    window.addEventListener('scroll', handleScroll, { passive: true });
    window.addEventListener('resize', handleResize);
    studentMobileHeaderScrollBound = true;
}

function isStudentMobileHeaderMode() {
    return window.innerWidth <= 1000;
}

function isStudentHeaderPanelOpen() {
    const announcementPanel = document.getElementById('announcementPanel');
    const profilePanel = document.getElementById('profilePanel');
    return document.body.classList.contains('student-sidebar-open') ||
        (announcementPanel && announcementPanel.classList.contains('active')) ||
        (profilePanel && profilePanel.classList.contains('active'));
}

function showStudentMobileHeader() {
    const header = document.querySelector('.student-panel .top-header');
    if (!header) return;
    header.classList.remove('student-header-hidden');
    studentLastHeaderScrollY = window.scrollY || 0;
}

function updateStudentMobileHeaderVisibility() {
    const header = document.querySelector('.student-panel .top-header');
    if (!header) return;

    const currentY = Math.max(0, window.scrollY || 0);
    const delta = currentY - studentLastHeaderScrollY;
    const threshold = 16;

    if (!isStudentMobileHeaderMode() || currentY <= 4 || isStudentHeaderPanelOpen()) {
        header.classList.remove('student-header-hidden');
        studentLastHeaderScrollY = currentY;
        return;
    }

    if (Math.abs(delta) < threshold) {
        return;
    }

    if (delta > 0 && currentY > 120) {
        header.classList.add('student-header-hidden');
    } else if (delta < 0) {
        header.classList.remove('student-header-hidden');
    }

    studentLastHeaderScrollY = currentY;
}

function setupStudentHeroActions() {
    const openEvaluationBtn = document.getElementById('heroOpenEvaluationBtn');
    const openHistoryBtn = document.getElementById('heroOpenHistoryBtn');
    const dashboardOpenEvaluationBtn = document.getElementById('dashboardOpenEvaluationBtn');

    const focusEvaluationList = function () {
        switchView('dashboard');
        updateNavigation('dashboard');
        const state = getStudentEvaluationAssignmentState();
        updateEvaluationAvailabilityUi(state);
        if (!state.canAccessEvaluationForm) {
            showErrorMessage(state.message || 'No pending evaluation forms are available right now.');
        }
        const evaluationsSection = document.querySelector('.evaluations-section');
        if (evaluationsSection) {
            const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
            evaluationsSection.scrollIntoView({ behavior: prefersReducedMotion ? 'auto' : 'smooth', block: 'start' });
        }
    };

    if (openEvaluationBtn) {
        openEvaluationBtn.addEventListener('click', focusEvaluationList);
    }

    if (openHistoryBtn) {
        openHistoryBtn.addEventListener('click', function () {
            switchView('history');
            updateNavigation('history');
        });
    }

    if (dashboardOpenEvaluationBtn) {
        dashboardOpenEvaluationBtn.addEventListener('click', focusEvaluationList);
    }
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
    const assignmentState = getStudentEvaluationAssignmentState();
    const assignedRow = assignmentState.pendingRows.find(row =>
        String(row.offeringId) === String(courseOfferingId || '') &&
        normalizeValue(row.professorName) === normalizeValue(professorName) &&
        normalizeValue(row.subjectCode) === normalizeValue(courseCode)
    );

    if (!assignmentState.canAccessEvaluationForm || !assignedRow) {
        clearUnavailableEvaluationContext();
        renderAssignedEvaluationList();
        refreshEvaluationStatuses();
        updateSummaryCards();
        updateEvaluationAvailabilityUi(assignmentState);
        showErrorMessage(assignmentState.message || 'This evaluation is no longer assigned to you. Please select an active pending professor evaluation from the Dashboard.');
        return;
    }

    if (isSubmittedEvaluation(studentId, semesterId, professorName, studentIdentity, courseOfferingId, courseCode)) {
        alert('You already submitted an evaluation for this professor this semester.');
        refreshEvaluationStatuses();
        updateSummaryCards();
        updateEvaluationAvailabilityUi();
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
    startEvaluationBehaviorCapture(true);

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
    const state = getStudentEvaluationAssignmentState();
    if (!state.canAccessEvaluationForm) {
        showErrorMessage(state.message || 'No pending evaluation forms are available right now.');
        switchView('dashboard');
        updateNavigation('dashboard');
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
        alert(msg);
        return;
    }

    alert('Please click "Start Evaluation" on a professor card to choose who you will evaluate.');
}

/**
 * Update summary cards with dynamic data
 */
function updateSummaryCards() {
    const state = getStudentEvaluationAssignmentState();
    const pendingCount = state.ready ? state.pendingRows.length : 0;
    const completedCount = state.ready ? state.completedCount : 0;
    const totalCount = state.ready ? state.totalAssigned : 0;

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
    setupExceptionReportingTextValidation();
    applyExceptionReportingRequirements();
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

function resetEvaluationBehaviorCapture() {
    evaluationBehaviorCapture.captureKey = '';
    evaluationBehaviorCapture.startedAt = '';
}

function buildEvaluationBehaviorCaptureKey() {
    const target = String(getSelectedEvaluationTarget() || '').trim();
    const offeringId = String(getSelectedCourseOfferingId() || '').trim();
    const session = getUserSession() || {};
    const identity = buildCurrentStudentIdentity(session);
    const studentUserId = String(identity.primaryStudentUserId || session.userId || '').trim();
    const semesterId = String(identity.primarySemesterId || getActiveSemesterId() || '').trim();
    return [studentUserId, semesterId, offeringId || target].join('|').toLowerCase();
}

function startEvaluationBehaviorCapture(force) {
    const selectedTarget = String(getSelectedEvaluationTarget() || '').trim();
    if (!selectedTarget) {
        resetEvaluationBehaviorCapture();
        return;
    }

    const captureKey = buildEvaluationBehaviorCaptureKey();
    const shouldReset = force === true
        || evaluationBehaviorCapture.captureKey !== captureKey
        || String(evaluationBehaviorCapture.startedAt || '').trim() === '';

    if (shouldReset) {
        evaluationBehaviorCapture.captureKey = captureKey;
        evaluationBehaviorCapture.startedAt = SharedData.getNowIsoString();
    }
}

function buildEvaluationBehaviorMeta(payload, questionDefinitions) {
    const submittedAt = String(payload && payload.submittedAt || '').trim() || SharedData.getNowIsoString();
    const startedAt = String(evaluationBehaviorCapture.startedAt || '').trim() || submittedAt;
    const startedTs = Date.parse(startedAt);
    const submittedTs = Date.parse(submittedAt);

    const durationSeconds = (Number.isFinite(startedTs) && Number.isFinite(submittedTs) && submittedTs >= startedTs)
        ? Math.max((submittedTs - startedTs) / 1000, 0)
        : 0;

    const questions = Array.isArray(questionDefinitions) ? questionDefinitions : [];
    const questionCount = questions.length;
    const ratings = payload && typeof payload.ratings === 'object' && payload.ratings ? payload.ratings : {};
    const qualitative = payload && typeof payload.qualitative === 'object' && payload.qualitative ? payload.qualitative : {};

    const answeredIds = new Set();
    Object.keys(ratings).forEach(function (key) {
        const value = String(ratings[key] == null ? '' : ratings[key]).trim();
        if (value !== '') answeredIds.add(String(key));
    });
    Object.keys(qualitative).forEach(function (key) {
        const value = String(qualitative[key] == null ? '' : qualitative[key]).trim();
        if (value !== '') answeredIds.add(String(key));
    });
    const answeredCount = answeredIds.size;
    const secondsPerQuestion = answeredCount > 0
        ? (durationSeconds / answeredCount)
        : (questionCount > 0 ? durationSeconds / questionCount : 0);

    return {
        captureVersion: 1,
        startedAt,
        submittedAt,
        durationSeconds: parseFloat(durationSeconds.toFixed(3)),
        questionCount,
        answeredCount,
        secondsPerQuestion: parseFloat(secondsPerQuestion.toFixed(6)),
    };
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
    const formatted = SharedData.formatDateTimeInPhilippines(text);
    return formatted || text;
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
        updatedAt: SharedData.getNowIsoString(),
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
        const scale = String(question.ratingScale || '1-5');
        const maxRating = parseInt(scale.split('-')[1], 10) || 5;
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
        const isBaseRequired = !!question.required;
        const hasExceptionReporting = !!question.exceptionReporting;
        const isInitiallyRequired = isBaseRequired;
        const requiredMarker = `<span class="question-required-star" style="color:red;${isInitiallyRequired ? '' : ' display:none;'}">*</span>`;
        return `
            <div class="question-group">
                <label class="question-label" for="${question.id}">${index}. ${question.text} ${requiredMarker}</label>
                <textarea id="${question.id}" name="${question.id}" class="form-textarea" rows="4" placeholder="Your answer..." ${isInitiallyRequired ? 'required' : ''} data-base-required="${isBaseRequired ? '1' : '0'}" data-exception-reporting="${hasExceptionReporting ? '1' : '0'}"></textarea>
            </div>
        `;
    }
}

const EXCEPTION_REPORTING_FILLER_VALUES = new Set([
    'n/a',
    'na',
    'none',
    'no comment',
    'no comments',
    'ok',
    'test',
    '-',
    '--',
    '...'
]);

const EXCEPTION_REPORTING_COMMON_WORDS = new Set([
    'the', 'and', 'is', 'are', 'because', 'this', 'that', 'with', 'for', 'from', 'was', 'were',
    'sa', 'ang', 'at', 'siya', 'pero', 'dahil', 'para', 'mga', 'nang', 'ng', 'ako', 'kami'
]);

function normalizeExceptionReportingTextValue(value) {
    return String(value || '').trim().replace(/\s+/g, ' ');
}

function extractExceptionReportingWordTokens(value) {
    const normalized = normalizeExceptionReportingTextValue(value).toLowerCase();
    if (!normalized) return [];

    return normalized
        .split(/\s+/)
        .map(token => token
            .replace(/^[^a-z]+|[^a-z]+$/gi, '')
            .replace(/[^a-z']/gi, '')
        )
        .filter(Boolean);
}

function isLikelyGibberishExceptionToken(token) {
    const compact = String(token || '').toLowerCase().replace(/'/g, '');
    if (!compact) return false;
    if (compact.length > 24) return true;
    if (/(.)\1{3,}/.test(compact)) return true;
    if (compact.length >= 5 && !/[aeiou]/.test(compact)) return true;

    if (compact.length >= 8) {
        const uniqueRatio = (new Set(compact.split(''))).size / compact.length;
        if (uniqueRatio < 0.35) return true;
    }

    return false;
}

function validateExceptionReportingTextValue(value, isRequired) {
    const normalized = normalizeExceptionReportingTextValue(value);
    const normalizedLower = normalized.toLowerCase();

    if (!normalized) {
        return isRequired ? 'Please enter a meaningful response with at least 8 words.' : '';
    }

    if (EXCEPTION_REPORTING_FILLER_VALUES.has(normalizedLower)) {
        return 'Please avoid filler responses like "n/a" or "none".';
    }

    const tokens = extractExceptionReportingWordTokens(normalized);
    if (tokens.length < 8) {
        return 'Please write at least 8 words in one meaningful sentence.';
    }

    const hasCommonWord = tokens.some(token => EXCEPTION_REPORTING_COMMON_WORDS.has(token));
    if (!hasCommonWord) {
        return 'Please write a clearer sentence using normal words.';
    }

    const gibberishCount = tokens.filter(isLikelyGibberishExceptionToken).length;
    if (tokens.length > 0 && (gibberishCount / tokens.length) > 0.5) {
        return 'Please avoid random letters or unreadable words.';
    }

    return '';
}

function computeCurrentRatingAverage() {
    const selectedRatings = document.querySelectorAll('#dynamic-questions-container input[type="radio"]:checked');
    let total = 0;
    let count = 0;

    selectedRatings.forEach(input => {
        const value = parseFloat(input.value);
        if (!Number.isFinite(value)) return;
        total += value;
        count += 1;
    });

    if (!count) return null;
    return total / count;
}

function applyExceptionReportingRequirements(options) {
    const cfg = options || {};
    const shouldReport = cfg.report === true;
    const scopeRoot = cfg.scopeRoot && typeof cfg.scopeRoot.querySelectorAll === 'function'
        ? cfg.scopeRoot
        : document;
    const averageRating = computeCurrentRatingAverage();
    const isExceptionTriggered = Number.isFinite(averageRating) && averageRating < 2.5;
    const exceptionTextareas = scopeRoot.querySelectorAll('#dynamic-questions-container textarea[data-exception-reporting="1"], textarea[data-exception-reporting="1"]');
    let firstInvalidField = null;

    exceptionTextareas.forEach(textarea => {
        const isBaseRequired = textarea.getAttribute('data-base-required') === '1';
        const shouldRequire = isBaseRequired || isExceptionTriggered;

        if (shouldRequire) {
            textarea.setAttribute('required', 'required');
        } else {
            textarea.removeAttribute('required');
        }

        const validationMessage = validateExceptionReportingTextValue(textarea.value, shouldRequire);
        textarea.setCustomValidity(validationMessage);
        if (validationMessage && !firstInvalidField) {
            firstInvalidField = textarea;
        }

        const group = textarea.closest('.question-group');
        const marker = group ? group.querySelector('.question-required-star') : null;
        if (marker) {
            marker.style.display = shouldRequire ? '' : 'none';
        }
    });

    if (shouldReport && firstInvalidField) {
        firstInvalidField.reportValidity();
    }

    return !firstInvalidField;
}

function setupExceptionReportingTextValidation() {
    const exceptionTextareas = document.querySelectorAll('#dynamic-questions-container textarea[data-exception-reporting="1"]');
    exceptionTextareas.forEach(textarea => {
        const validate = () => {
            applyExceptionReportingRequirements();
        };
        textarea.addEventListener('input', validate);
        textarea.addEventListener('change', validate);
    });
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
    if (!applyExceptionReportingRequirements({ report: true, scopeRoot: currentStep })) {
        return false;
    }

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

    const exceptionFields = Array.from(currentStep.querySelectorAll('textarea[data-exception-reporting="1"]'));
    for (const field of exceptionFields) {
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

    if (!SharedData.changeOwnPassword) {
        showErrorMessage('Password update service is unavailable.');
        return;
    }

    try {
        SharedData.changeOwnPassword(currentPassword, newPassword);
        showSuccessMessage('Password updated successfully.');
    } catch (error) {
        console.error('[StudentPanel] Failed to update password.', error);
        showErrorMessage(error && error.message ? error.message : 'Failed to update password.');
        return;
    }

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

    const targetValidation = validateSelectedEvaluationTarget();
    if (!targetValidation.valid) {
        clearUnavailableEvaluationContext();
        updateEvaluationTargetIndicator();
        switchView('dashboard');
        updateNavigation('dashboard');
        renderAssignedEvaluationList();
        refreshEvaluationStatuses();
        updateSummaryCards();
        updateEvaluationAvailabilityUi(targetValidation.state);
        showErrorMessage(targetValidation.message || 'This evaluation is no longer assigned to you. Please select an active pending professor evaluation from the Dashboard.');
        return;
    }

    if (isSubmittedEvaluation(studentId, semesterId, targetParts.professorName, studentIdentity, selectedOfferingId, targetParts.subjectCode)) {
        showErrorMessage('This evaluation was already submitted for the selected professor this semester.');
        switchView('dashboard');
        updateNavigation('dashboard');
        refreshEvaluationStatuses();
        updateSummaryCards();
        updateEvaluationAvailabilityUi();
        return;
    }

    removeAutosaveTimer();
    enableAllSectionInputs();
    if (!applyExceptionReportingRequirements({ report: true })) {
        return;
    }

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
                    updateEvaluationAvailabilityUi();
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
        submittedAt: SharedData.getNowIsoString(),
        status: 'submitted'
    };
    data.behaviorMeta = buildEvaluationBehaviorMeta(data, allQuestions);

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
                date: SharedData.getNowIsoString()
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
    resetEvaluationBehaviorCapture();
}

function clearUnavailableEvaluationContext() {
    removeAutosaveTimer();
    clearSelectedEvaluationTarget();
    evaluationDraftState.lastSavedAt = '';
    evaluationDraftState.lastDraftKey = '';
    updateDraftStatusIndicator({ state: 'idle' });
}

/**
 * Setup rating inputs for better UX
 */
function setupRatingInputs() {
    const ratingInputs = document.querySelectorAll('.rating-scale input[type="radio"]');

    ratingInputs.forEach(input => {
        input.addEventListener('change', function () {
            applyExceptionReportingRequirements();
        });
    });
}

function renderStudentFormMessage(message, tone) {
    const form = document.getElementById('evaluationForm');
    if (!form) return;

    const existingMessage = form.querySelector('.form-message');
    if (existingMessage) {
        existingMessage.remove();
    }

    if (!message) {
        return;
    }

    const messageDiv = document.createElement('div');
    const state = String(tone || 'error').toLowerCase();
    const variant = state === 'success' ? 'ui-message--success' : 'ui-message--error';
    messageDiv.className = `form-message ui-message ${variant}`;
    messageDiv.textContent = String(message);
    form.insertBefore(messageDiv, form.firstChild);

    setTimeout(() => {
        if (messageDiv.parentNode) {
            messageDiv.remove();
        }
    }, 5000);
}

/**
 * Show success message
 * @param {string} message - Success message
 */
function showSuccessMessage(message) {
    renderStudentFormMessage(message, 'success');
}

/**
 * Show error message
 * @param {string} message - Error message
 */
function showErrorMessage(message) {
    renderStudentFormMessage(message, 'error');
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
    const now = SharedData.getNowDate();
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

