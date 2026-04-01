// Dean Panel JavaScript - Dashboard Functionality

let deanProfessorCount = 0;
let deanFacultyPaperState = {
    actorUserId: '',
    papers: [],
    selectedId: '',
};

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function () {
    // Check authentication
    if (!checkAuthentication()) {
        redirectToLogin();
        return;
    }

    if (!enforceActiveDeanAccount({ inline: false })) {
        return;
    }

    // Initialize the dashboard
    initializeDashboard();
});

let supervisorSectionFlow = {
    steps: [],
    activeIndex: 0
};

let deanSummaryState = {
    byType: {
        student: null,
        professor: null,
        supervisor: null
    },
    selectedSemesterId: '',
    selectedSemesterLabel: '',
    selectedEvaluationType: 'student'
};
let deanSupervisorTargetDirectory = [];

const DEAN_EMPTY_SUMMARY = {
    criteriaAverages: [],
    breakdownRows: [],
    subjects: [],
    ratingDistribution: { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 },
    comments: [],
    commentBuckets: {},
    detailedRows: [],
    totals: { required: 0, received: 0, responseRate: 0, averageScore: 0 }
};

function normalizeUserIdToken(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^u\d+$/i.test(raw)) return 'u' + raw.replace(/^u/i, '');
    if (/^\d+$/.test(raw)) return 'u' + String(parseInt(raw, 10));
    return '';
}

function normalizeRoleToken(value) {
    return String(value || '').trim().toLowerCase();
}

function getLatestSemesterOption() {
    const list = (SharedData.getSemesterList && SharedData.getSemesterList()) || [];
    if (!Array.isArray(list) || !list.length) return null;
    return list[list.length - 1] || null;
}

function resolveSelectedSemesterId(preferred) {
    const token = String(preferred || '').trim();
    if (token) return token;

    const current = String((SharedData.getCurrentSemester && SharedData.getCurrentSemester()) || '').trim();
    if (current) return current;

    const latest = getLatestSemesterOption();
    return latest ? String(latest.value || '').trim() : '';
}

function getSemesterLabelById(semesterId) {
    const id = String(semesterId || '').trim();
    if (!id) return 'Selected semester';
    const list = (SharedData.getSemesterList && SharedData.getSemesterList()) || [];
    const match = Array.isArray(list) ? list.find(item => String(item && item.value || '') === id) : null;
    if (match && match.label) return String(match.label);
    return id;
}

function getScopedDeanDepartment() {
    const dean = resolveCurrentDeanUserAnyStatus(getUserSession() || {});
    return String((dean && (dean.department || dean.institute)) || '').trim().toUpperCase();
}

function isActiveUser(user) {
    return normalizeRoleToken(user && user.status || 'active') !== 'inactive';
}

function getScopedProfessorUsers(includeInactive = false) {
    const users = (SharedData.getUsers && SharedData.getUsers()) || [];
    const scopedDepartment = getScopedDeanDepartment();
    return (Array.isArray(users) ? users : []).filter(user => {
        if (normalizeRoleToken(user && user.role) !== 'professor') return false;
        const department = String((user && (user.department || user.institute)) || '').trim().toUpperCase();
        if (!department || !scopedDepartment) return false;
        if (department !== scopedDepartment) return false;
        if (!includeInactive && !isActiveUser(user)) return false;
        return true;
    });
}

function buildDeanUserLookup(users) {
    const byUserId = {};
    const byEmployeeId = {};
    const byName = {};

    (Array.isArray(users) ? users : []).forEach(user => {
        const userId = normalizeUserIdToken(user && user.id);
        const employeeId = String(user && user.employeeId || '').trim().toLowerCase();
        const name = String(user && user.name || '').trim().toLowerCase();
        if (userId) byUserId[userId] = user;
        if (employeeId) byEmployeeId[employeeId] = user;
        if (name) byName[name] = user;
    });

    return { byUserId, byEmployeeId, byName };
}

function resolveEvaluationTypeToken(evaluation) {
    const token = normalizeRoleToken((evaluation && evaluation.evaluatorRole) || (evaluation && evaluation.evaluationType));
    if (token === 'student') return 'student';
    if (token === 'peer' || token === 'professor' || token === 'professor-to-professor') return 'professor';
    if (token === 'supervisor' || token === 'dean' || token === 'supervisor-to-professor') return 'supervisor';
    return '';
}

function isEvaluationInSemester(evaluation, semesterId) {
    const target = String(semesterId || '').trim().toLowerCase();
    if (!target) return true;
    const value = String(evaluation && evaluation.semesterId || '').trim().toLowerCase();
    if (!value) return true;
    return value === target;
}

function resolveTargetProfessorFromEvaluation(evaluation, lookup) {
    const candidates = [
        evaluation && evaluation.targetProfessorId,
        evaluation && evaluation.targetId,
        evaluation && evaluation.colleagueId,
        evaluation && evaluation.targetUserId
    ];

    for (let i = 0; i < candidates.length; i += 1) {
        const candidate = candidates[i];
        const userId = normalizeUserIdToken(candidate);
        if (userId && lookup.byUserId[userId]) return lookup.byUserId[userId];

        const employeeId = String(candidate || '').trim().toLowerCase();
        if (employeeId && lookup.byEmployeeId[employeeId]) return lookup.byEmployeeId[employeeId];
    }

    return null;
}

function collectEvaluationComments(evaluation) {
    const comments = [];
    const note = String(evaluation && evaluation.comments || '').trim();
    if (note) comments.push(note);

    const qualitative = evaluation && typeof evaluation.qualitative === 'object' && evaluation.qualitative
        ? evaluation.qualitative
        : {};
    Object.keys(qualitative).forEach(key => {
        const value = String(qualitative[key] || '').trim();
        if (value) comments.push(value);
    });
    return comments;
}

function computeAverageRatingFromEvaluations(evaluations) {
    let sum = 0;
    let count = 0;
    (Array.isArray(evaluations) ? evaluations : []).forEach(item => {
        const ratings = item && typeof item.ratings === 'object' && item.ratings ? item.ratings : {};
        Object.keys(ratings).forEach(questionId => {
            const parsed = parseFloat(ratings[questionId]);
            if (!Number.isFinite(parsed)) return;
            const value = Math.max(1, Math.min(5, parsed));
            sum += value;
            count += 1;
        });
    });
    return count ? (sum / count) : 0;
}

function formatDisplaySection(sectionName) {
    const value = String(sectionName || '').trim();
    if (!value) return '';
    if (/^\d+\-\d+$/.test(value)) return value.replace('-', '/');
    return value;
}

function getActiveSupervisorCount() {
    const users = (SharedData.getUsers && SharedData.getUsers()) || [];
    const supervisorRoles = new Set(['dean', 'hr', 'vpaa', 'admin']);
    return (Array.isArray(users) ? users : []).filter(user =>
        supervisorRoles.has(normalizeRoleToken(user && user.role)) &&
        isActiveUser(user)
    ).length;
}

function buildDeanQuestionMeta(evaluationType, semesterId) {
    const questionnaires = (SharedData.getQuestionnaires && SharedData.getQuestionnaires()) || {};
    const targetSemesterId = resolveSelectedSemesterId(semesterId);
    const fallbackSemester = getLatestSemesterOption();
    const bucket = questionnaires[targetSemesterId]
        || (fallbackSemester && questionnaires[fallbackSemester.value])
        || {};

    const typeMap = {
        student: 'student-to-professor',
        professor: 'professor-to-professor',
        supervisor: 'supervisor-to-professor'
    };
    const typeKey = typeMap[evaluationType] || 'student-to-professor';
    const sectionBucket = bucket[typeKey] || { sections: [], questions: [] };
    const sections = Array.isArray(sectionBucket.sections) ? sectionBucket.sections : [];
    const questions = Array.isArray(sectionBucket.questions) ? sectionBucket.questions : [];

    const categoryByQuestionId = {};
    const categoryOrder = [];
    const sectionTitles = {};

    sections.forEach(section => {
        const sectionId = String(section && section.id || '').trim();
        const title = String(section && (section.title || section.letter) || '').trim();
        if (!sectionId || !title) return;
        sectionTitles[sectionId] = title;
        if (!categoryOrder.includes(title)) {
            categoryOrder.push(title);
        }
    });

    questions.forEach(question => {
        const questionId = String(question && question.id || '').trim();
        if (!questionId) return;
        const sectionId = String(question && question.sectionId || '').trim();
        const category = sectionTitles[sectionId] || 'General Questions';
        categoryByQuestionId[questionId] = category;
        categoryByQuestionId[questionId.toLowerCase()] = category;
        if (!categoryOrder.includes(category)) {
            categoryOrder.push(category);
        }
    });

    return { categoryByQuestionId, categoryOrder };
}

function getDeanEvaluationTypeMeta(type) {
    const token = normalizeRoleToken(type);
    if (token === 'peer' || token === 'professor') {
        return { id: 'professor', label: 'Professor Evaluation' };
    }
    if (token === 'supervisor' || token === 'dean') {
        return { id: 'supervisor', label: 'Supervisor Evaluation' };
    }
    return { id: 'student', label: 'Student Evaluation' };
}

function isSemesterTokenMatch(value, semesterId) {
    const selected = String(semesterId || '').trim().toLowerCase();
    if (!selected) return true;
    const token = String(value || '').trim().toLowerCase();
    if (!token) return true;
    return token === selected;
}

function createCategoryStatBucket() {
    return {
        sum: 0,
        count: 0,
        responses: 0,
        excellent: 0,
        good: 0,
        fair: 0,
        poor: 0,
        veryPoor: 0
    };
}

function buildDeanPanelContext() {
    const session = getUserSession() || {};
    const deanUser = resolveCurrentDeanUserAnyStatus(session);
    const users = (SharedData.getUsers && SharedData.getUsers()) || [];
    const evaluations = (SharedData.getEvaluations && SharedData.getEvaluations()) || [];
    const semesterList = (SharedData.getSemesterList && SharedData.getSemesterList()) || [];
    const currentSemester = String((SharedData.getCurrentSemester && SharedData.getCurrentSemester()) || '').trim();
    const subjectManagement = SharedData.getSubjectManagement
        ? SharedData.getSubjectManagement()
        : { offerings: [], enrollments: [] };
    const scopedDepartment = String((deanUser && (deanUser.department || deanUser.institute)) || '').trim().toUpperCase();

    const scopedProfessors = (Array.isArray(users) ? users : []).filter(user => {
        if (normalizeRoleToken(user && user.role) !== 'professor') return false;
        if (!isActiveUser(user)) return false;
        const department = String((user && (user.department || user.institute)) || '').trim().toUpperCase();
        return !!department && !!scopedDepartment && department === scopedDepartment;
    });

    const professorLookup = buildDeanUserLookup(scopedProfessors);
    const professorById = {};
    const professorByName = {};
    const professorByEmployeeId = {};
    scopedProfessors.forEach(professor => {
        const idToken = normalizeUserIdToken(professor && professor.id);
        if (idToken) professorById[idToken] = professor;

        const nameToken = normalizeRoleToken(professor && professor.name);
        if (nameToken && !professorByName[nameToken]) professorByName[nameToken] = professor;

        const employeeToken = normalizeRoleToken(professor && professor.employeeId);
        if (employeeToken && !professorByEmployeeId[employeeToken]) professorByEmployeeId[employeeToken] = professor;
    });

    const offerings = Array.isArray(subjectManagement && subjectManagement.offerings)
        ? subjectManagement.offerings
        : [];
    const scopedOfferings = offerings.filter(offering => {
        if (!offering || !offering.isActive) return false;
        const professorId = normalizeUserIdToken(offering.professorUserId);
        return !!professorId && !!professorById[professorId];
    });
    const offeringsById = {};
    scopedOfferings.forEach(offering => {
        const offeringId = String(offering && offering.id || '').trim();
        if (offeringId) offeringsById[offeringId] = offering;
    });

    const enrollments = Array.isArray(subjectManagement && subjectManagement.enrollments)
        ? subjectManagement.enrollments
        : [];

    return {
        session,
        deanUser,
        users,
        evaluations: Array.isArray(evaluations) ? evaluations : [],
        semesterList: Array.isArray(semesterList) ? semesterList : [],
        currentSemester,
        scopedDepartment,
        scopedProfessors,
        professorLookup,
        professorById,
        professorByName,
        professorByEmployeeId,
        offerings: scopedOfferings,
        offeringsById,
        enrollments
    };
}

function resolveDeanTargetProfessorId(evaluation, evaluationType, context) {
    if (evaluationType === 'student') {
        const offeringId = String(evaluation && evaluation.courseOfferingId || '').trim();
        if (offeringId && context.offeringsById[offeringId]) {
            const professorId = normalizeUserIdToken(context.offeringsById[offeringId].professorUserId);
            if (professorId && context.professorById[professorId]) {
                return professorId;
            }
        }
    }

    const resolved = resolveTargetProfessorFromEvaluation(evaluation, context.professorLookup);
    if (resolved) {
        const token = normalizeUserIdToken(resolved.id);
        if (token && context.professorById[token]) return token;
    }

    const fallbackTokens = [
        evaluation && evaluation.targetProfessor,
        evaluation && evaluation.professorSubject,
        evaluation && evaluation.targetName
    ];

    for (let index = 0; index < fallbackTokens.length; index += 1) {
        const raw = String(fallbackTokens[index] || '').trim();
        if (!raw) continue;
        const head = normalizeRoleToken(raw.split(' - ')[0]);
        if (head && context.professorByName[head]) {
            return normalizeUserIdToken(context.professorByName[head].id);
        }
    }

    return '';
}

function getDeanSummaryForType(type) {
    const key = getDeanEvaluationTypeMeta(type).id;
    return deanSummaryState.byType[key] || DEAN_EMPTY_SUMMARY;
}

function buildDeanEvaluationAggregates(context, evaluationType, semesterId) {
    const questionMeta = buildDeanQuestionMeta(evaluationType, semesterId);
    const categoryStats = {};
    (questionMeta.categoryOrder || []).forEach(category => {
        categoryStats[category] = createCategoryStatBucket();
    });

    const ratingDistribution = { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };
    const matchedEvaluations = [];
    const comments = [];

    (context.evaluations || []).forEach(evaluation => {
        const typeToken = resolveEvaluationTypeToken(evaluation);
        if (typeToken !== evaluationType) return;
        if (!isEvaluationInSemester(evaluation, semesterId)) return;

        const targetProfessorId = resolveDeanTargetProfessorId(evaluation, evaluationType, context);
        if (!targetProfessorId || !context.professorById[targetProfessorId]) return;

        matchedEvaluations.push(evaluation);

        const ratings = evaluation && typeof evaluation.ratings === 'object' && evaluation.ratings
            ? evaluation.ratings
            : {};
        Object.keys(ratings).forEach(questionId => {
            const parsed = parseFloat(ratings[questionId]);
            if (!Number.isFinite(parsed)) return;
            const value = Math.max(1, Math.min(5, parsed));
            const rounded = Math.max(1, Math.min(5, Math.round(value)));
            ratingDistribution[rounded] += 1;

            const questionToken = String(questionId || '').trim();
            const category = questionMeta.categoryByQuestionId[questionToken]
                || questionMeta.categoryByQuestionId[questionToken.toLowerCase()]
                || 'General Questions';
            if (!categoryStats[category]) {
                categoryStats[category] = createCategoryStatBucket();
            }
            categoryStats[category].sum += value;
            categoryStats[category].count += 1;
            categoryStats[category].responses += 1;
            if (rounded === 5) categoryStats[category].excellent += 1;
            if (rounded === 4) categoryStats[category].good += 1;
            if (rounded === 3) categoryStats[category].fair += 1;
            if (rounded === 2) categoryStats[category].poor += 1;
            if (rounded === 1) categoryStats[category].veryPoor += 1;
        });

        collectEvaluationComments(evaluation).forEach(text => {
            comments.push({
                text,
                source: getDeanEvaluationTypeMeta(evaluationType).label,
                date: String(evaluation && (evaluation.submittedAt || evaluation.timestamp) || '').trim()
            });
        });
    });

    const commentBuckets = {};
    const breakdownRows = [];
    let requiredTotal = 0;
    let receivedTotal = 0;

    if (evaluationType === 'student') {
        const scopedOfferings = (context.offerings || []).filter(offering =>
            isSemesterTokenMatch(offering && offering.semesterSlug, semesterId)
        );

        scopedOfferings.forEach(offering => {
            const offeringId = String(offering && offering.id || '').trim();
            if (!offeringId) return;

            const required = (context.enrollments || []).filter(enrollment => {
                if (String(enrollment && enrollment.courseOfferingId || '').trim() !== offeringId) return false;
                const status = normalizeRoleToken(enrollment && enrollment.status || 'enrolled');
                return status !== 'dropped' && status !== 'inactive';
            }).length;

            const offeringEvaluations = matchedEvaluations.filter(evaluation =>
                String(evaluation && evaluation.courseOfferingId || '').trim() === offeringId
            );
            const received = offeringEvaluations.length;
            const avgRating = computeAverageRatingFromEvaluations(offeringEvaluations);
            const subject = offering.subjectCode
                ? `${offering.subjectCode} - ${offering.subjectName}`
                : String(offering.subjectName || '').trim();
            const rowKey = `student|offering|${offeringId}`;

            commentBuckets[rowKey] = offeringEvaluations.flatMap(evaluation =>
                collectEvaluationComments(evaluation).map(text => ({
                    text,
                    source: 'Student Evaluation',
                    date: String(evaluation && (evaluation.submittedAt || evaluation.timestamp) || '').trim()
                }))
            );

            breakdownRows.push({
                rowKey,
                subject: subject || 'Unknown Subject',
                section: formatDisplaySection(offering.sectionName),
                required,
                received,
                avgRating
            });
        });

        breakdownRows.sort((a, b) => {
            const left = `${a.subject || ''}|${a.section || ''}`;
            const right = `${b.subject || ''}|${b.section || ''}`;
            return left.localeCompare(right);
        });

        requiredTotal = breakdownRows.reduce((sum, item) => sum + Number(item.required || 0), 0);
        receivedTotal = breakdownRows.reduce((sum, item) => sum + Number(item.received || 0), 0);
    } else {
        const groupMap = {};
        matchedEvaluations.forEach(evaluation => {
            const targetProfessorId = resolveDeanTargetProfessorId(evaluation, evaluationType, context);
            if (!targetProfessorId) return;
            if (!groupMap[targetProfessorId]) groupMap[targetProfessorId] = [];
            groupMap[targetProfessorId].push(evaluation);
        });

        const requiredPerProfessor = evaluationType === 'professor'
            ? Math.max((context.scopedProfessors || []).length - 1, 0)
            : getActiveSupervisorCount();

        (context.scopedProfessors || []).forEach(professor => {
            const userId = normalizeUserIdToken(professor && professor.id);
            if (!userId) return;

            const evaluationsForProfessor = groupMap[userId] || [];
            const rowKey = `${evaluationType}|professor|${userId}`;
            commentBuckets[rowKey] = evaluationsForProfessor.flatMap(evaluation =>
                collectEvaluationComments(evaluation).map(text => ({
                    text,
                    source: getDeanEvaluationTypeMeta(evaluationType).label,
                    date: String(evaluation && (evaluation.submittedAt || evaluation.timestamp) || '').trim()
                }))
            );

            breakdownRows.push({
                rowKey,
                employeeId: String(professor.employeeId || professor.id || '').trim() || 'N/A',
                professorId: String(professor.employeeId || professor.id || '').trim() || 'N/A',
                professorName: String(professor.name || '').trim() || 'Unknown',
                institute: String((professor.department || professor.institute) || '').trim().toUpperCase(),
                employmentType: String(professor.employmentType || '').trim() || 'N/A',
                position: String(professor.position || '').trim() || 'N/A',
                status: normalizeRoleToken(professor.status || 'active') === 'inactive' ? 'Inactive' : 'Active',
                required: requiredPerProfessor,
                received: evaluationsForProfessor.length,
                avgRating: computeAverageRatingFromEvaluations(evaluationsForProfessor),
                avgScore: computeAverageRatingFromEvaluations(evaluationsForProfessor),
                lastUpdated: evaluationsForProfessor.reduce((latest, item) => {
                    const value = String(item && (item.submittedAt || item.timestamp) || '').trim();
                    if (!value) return latest;
                    if (!latest) return value;
                    return new Date(value).getTime() > new Date(latest).getTime() ? value : latest;
                }, '')
            });
        });

        breakdownRows.sort((a, b) => String(a.professorName || '').localeCompare(String(b.professorName || '')));
        requiredTotal = breakdownRows.reduce((sum, item) => sum + Number(item.required || 0), 0);
        receivedTotal = breakdownRows.reduce((sum, item) => sum + Number(item.received || 0), 0);
    }

    const categories = (questionMeta.categoryOrder || []).concat(
        Object.keys(categoryStats).filter(category => !(questionMeta.categoryOrder || []).includes(category))
    );

    const criteriaAverages = categories.map(category => {
        const stat = categoryStats[category] || createCategoryStatBucket();
        return {
            name: category,
            average: stat.count ? (stat.sum / stat.count) : 0
        };
    }).filter(item => item.name);

    const detailedRows = categories.map(category => {
        const stat = categoryStats[category] || createCategoryStatBucket();
        return {
            category,
            avgScore: stat.count ? (stat.sum / stat.count) : 0,
            responses: stat.responses || 0,
            excellent: stat.excellent || 0,
            good: stat.good || 0,
            fair: stat.fair || 0,
            poor: stat.poor || 0,
            veryPoor: stat.veryPoor || 0
        };
    }).filter(item => item.category);

    const averageScore = computeAverageRatingFromEvaluations(matchedEvaluations);
    const responseRate = requiredTotal ? Math.round((receivedTotal / requiredTotal) * 100) : 0;

    return {
        criteriaAverages,
        breakdownRows,
        subjects: breakdownRows,
        ratingDistribution,
        comments,
        commentBuckets,
        detailedRows,
        totals: {
            required: requiredTotal,
            received: receivedTotal,
            responseRate,
            averageScore
        }
    };
}

/**
 * Check if user is authenticated and is a dean
 * @returns {boolean} - True if user is authenticated as dean
 */
function checkAuthentication() {
    const session = SharedData.getSession();
    if (!session) {
        return false;
    }

    try {
        // Check if user is authenticated and is a dean
        return session.isAuthenticated === true
            && (session.role === 'dean' || session.role === 'daen')
            && normalizeDeanToken(session.status || 'active') !== 'inactive';
    } catch (e) {
        return false;
    }
}

function resolveCurrentDeanUserAnyStatus(sessionInput) {
    const session = sessionInput || getUserSession() || {};
    const users = (typeof SharedData !== 'undefined' && SharedData.getUsers) ? SharedData.getUsers() : [];
    const deans = (Array.isArray(users) ? users : []).filter(user => normalizeDeanToken(user && user.role) === 'dean');
    if (!deans.length) return null;

    const sessionUserId = normalizeDeanUserIdToken(session && session.userId);
    if (sessionUserId) {
        const byId = deans.find(user => normalizeDeanUserIdToken(user && user.id) === sessionUserId);
        if (byId) return byId;
    }

    const sessionEmail = normalizeDeanToken(session && session.email);
    if (sessionEmail) {
        const byEmail = deans.find(user => normalizeDeanToken(user && user.email) === sessionEmail);
        if (byEmail) return byEmail;
    }

    const sessionEmployeeId = normalizeDeanToken(session && session.employeeId);
    if (sessionEmployeeId) {
        const byEmployeeId = deans.find(user => normalizeDeanToken(user && user.employeeId) === sessionEmployeeId);
        if (byEmployeeId) return byEmployeeId;
    }

    const sessionUsername = normalizeDeanToken(session && session.username);
    if (sessionUsername) {
        const byName = deans.find(user => normalizeDeanToken(user && user.name) === sessionUsername);
        if (byName) return byName;
        const byEmailName = deans.find(user => normalizeDeanToken(user && user.email) === sessionUsername);
        if (byEmailName) return byEmailName;
    }

    const sessionFullName = normalizeDeanToken(session && session.fullName);
    if (sessionFullName) {
        const byFullName = deans.find(user => normalizeDeanToken(user && user.name) === sessionFullName);
        if (byFullName) return byFullName;
    }

    return null;
}

function enforceActiveDeanAccount(options = {}) {
    const cfg = options || {};
    const matchedDean = resolveCurrentDeanUserAnyStatus(getUserSession() || {});
    const isInactive = normalizeDeanToken(matchedDean && matchedDean.status) === 'inactive';
    const hasActiveDean = !!(matchedDean && !isInactive);

    if (hasActiveDean) {
        return true;
    }

    const form = cfg.form || document.getElementById('peerEvaluationForm');
    const message = isInactive
        ? 'Your account is inactive. You cannot access evaluations. Please contact your administrator.'
        : 'Your login session is not linked to an active dean account.';

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
 * Initialize the dean dashboard
 */
function initializeDashboard() {
    loadUserInfo();
    renderDeanAnnouncementPanels();
    setupNavigation();
    setupLogout();
    setupHeaderPanels();
    setupTableActions();
    loadFacultySummary({ evaluationType: 'student' });
    loadProfessorCount();
    setupFacultyResponseView();
    setupPeerManagementView();
    setupDeanFacultyPaperInbox();
    updateSummaryCards();
    setupPeerEvaluationForm();
    populatePeerProfessorOptions();
    setupProfileActions();
    setupProfilePhotoUpload();
    setupChangeEmailForm();
    setupChangePasswordForm();
    setupPasswordToggles();
    initializeReports();
    setupDeanDataSync();
}

function setupDeanDataSync() {
    if (!SharedData || typeof SharedData.onDataChange !== 'function' || !SharedData.KEYS) return;

    SharedData.onDataChange(function (key) {
        if (key === SharedData.KEYS.USERS) {
            enforceActiveDeanAccount({ inline: false });
            loadUserInfo();
            renderDeanAnnouncementPanels();
            populatePeerProfessorOptions();
            loadProfessorCount();
            if (deanSummaryState.selectedEvaluationType) {
                loadFacultySummary({
                    semesterId: deanSummaryState.selectedSemesterId,
                    evaluationType: deanSummaryState.selectedEvaluationType
                });
            }
            return;
        }

        const refreshKeys = new Set([
            SharedData.KEYS.EVALUATIONS,
            SharedData.KEYS.SUBJECT_MANAGEMENT,
            SharedData.KEYS.CURRENT_SEMESTER,
            SharedData.KEYS.SEMESTER_LIST,
            SharedData.KEYS.QUESTIONNAIRES
        ]);

        if (key === SharedData.KEYS.ANNOUNCEMENTS) {
            renderDeanAnnouncementPanels();
            return;
        }

        if (refreshKeys.has(key)) {
            populatePeerProfessorOptions();
            loadProfessorCount();
            loadFacultySummary({
                semesterId: deanSummaryState.selectedSemesterId,
                evaluationType: deanSummaryState.selectedEvaluationType
            });
        }
    });
}

/**
 * Load and display user information
 */
function loadUserInfo() {
    const session = SharedData.getSession() || {};
    const deanUser = resolveCurrentDeanUserAnyStatus(session);
    if (!deanUser) return;

    try {
        const deanName = String(deanUser.name || session.fullName || session.username || 'Dean').trim() || 'Dean';
        const deanEmployeeId = String(deanUser.employeeId || session.employeeId || 'N/A').trim() || 'N/A';
        const deanDepartment = String(deanUser.department || deanUser.institute || 'N/A').trim().toUpperCase() || 'N/A';
        const deanEmail = String(deanUser.email || session.email || '').trim();
        const deanRank = String(deanUser.position || deanUser.employmentType || 'N/A').trim() || 'N/A';
        const deanStatus = normalizeRoleToken(deanUser.status || 'active') === 'inactive' ? 'Inactive' : 'Active';
        const currentSemesterId = resolveSelectedSemesterId(deanSummaryState.selectedSemesterId);
        const semesterLabel = getSemesterLabelById(currentSemesterId);

        document.querySelectorAll('.user-profile span').forEach(span => {
            span.textContent = deanName;
        });

        document.querySelectorAll('.profile-item').forEach(item => {
            const label = item.querySelector('.profile-label');
            const value = item.querySelector('.profile-value');
            if (!label || !value) return;
            const key = String(label.textContent || '').trim().toLowerCase();

            if (key === 'faculty id') value.textContent = deanEmployeeId;
            if (key === 'department') value.textContent = deanDepartment;
            if (key === 'full name') value.textContent = deanName;
            if (key === 'gmail') value.textContent = deanEmail || 'N/A';
            if (key === 'rank') value.textContent = deanRank;
            if (key === 'status') value.textContent = deanStatus;
            if (key === 'ay/sem') value.textContent = semesterLabel || 'N/A';
        });

        const profileFacultyId = document.getElementById('profileFacultyId');
        if (profileFacultyId) profileFacultyId.textContent = deanEmployeeId;

        const profileEmail = document.getElementById('profileEmail');
        if (profileEmail) profileEmail.textContent = deanEmail || 'N/A';

        const currentEmail = document.getElementById('currentEmail');
        if (currentEmail) currentEmail.value = deanEmail || '';
    } catch (e) {
        console.error('Error loading user info:', e);
    }
}

function renderDeanAnnouncementPanels() {
    const listItems = SharedData.getAnnouncementsForCurrentUser
        ? SharedData.getAnnouncementsForCurrentUser({ limit: 5 })
        : (SharedData.getAnnouncements ? SharedData.getAnnouncements() : []);
    const announcements = (Array.isArray(listItems) ? listItems : []).slice(0, 5).map(item => ({
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
        case 'facultyPaperInbox':
            switchView('facultyPaperInbox');
            break;
        case 'profile':
            switchView('profile');
            break;
        case 'facultyResponse':
            switchView('facultyResponse');
            break;
        case 'peerManagement':
            switchView('peerManagement');
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
    const facultyPaperInboxView = document.getElementById('facultyPaperInboxView');
    const profileView = document.getElementById('profileView');
    const facultyResponseView = document.getElementById('facultyResponseView');
    const peerManagementView = document.getElementById('peerManagementView');

    if (viewName === 'dashboard') {
        if (dashboardView) dashboardView.style.display = 'block';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (facultyPaperInboxView) facultyPaperInboxView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (facultyResponseView) facultyResponseView.style.display = 'none';
        if (peerManagementView) peerManagementView.style.display = 'none';
        closeAllPanels();
    } else if (viewName === 'peerEvaluation') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'block';
        if (facultyPaperInboxView) facultyPaperInboxView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (facultyResponseView) facultyResponseView.style.display = 'none';
        if (peerManagementView) peerManagementView.style.display = 'none';
        window.scrollTo(0, 0);
        closeAllPanels();
        loadDynamicSupervisorQuestionnaire();
    } else if (viewName === 'profile') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (facultyPaperInboxView) facultyPaperInboxView.style.display = 'none';
        if (profileView) profileView.style.display = 'block';
        if (facultyResponseView) facultyResponseView.style.display = 'none';
        if (peerManagementView) peerManagementView.style.display = 'none';
        window.scrollTo(0, 0);
        closeAllPanels();
    } else if (viewName === 'facultyPaperInbox') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (facultyPaperInboxView) facultyPaperInboxView.style.display = 'block';
        if (profileView) profileView.style.display = 'none';
        if (facultyResponseView) facultyResponseView.style.display = 'none';
        if (peerManagementView) peerManagementView.style.display = 'none';
        window.scrollTo(0, 0);
        closeAllPanels();
        renderDeanFacultyPaperInbox();
    } else if (viewName === 'facultyResponse') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (facultyPaperInboxView) facultyPaperInboxView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (facultyResponseView) facultyResponseView.style.display = 'block';
        if (peerManagementView) peerManagementView.style.display = 'none';
        window.scrollTo(0, 0);
        closeAllPanels();
    } else if (viewName === 'peerManagement') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (facultyPaperInboxView) facultyPaperInboxView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (facultyResponseView) facultyResponseView.style.display = 'none';
        if (peerManagementView) peerManagementView.style.display = 'block';
        window.scrollTo(0, 0);
        closeAllPanels();
    }
}

/**
 * Initialize reports view with charts
 */
function initializeReports() {
    initializeStudentCharts();
    initializePeerCharts();
}

function initializeStudentCharts() {
    const barCtx = document.getElementById('studentBarChart');
    const pieCtx = document.getElementById('studentPieChart');
    const summary = getDeanSummaryForType('student');
    const criteria = Array.isArray(summary.criteriaAverages) ? summary.criteriaAverages : [];
    const distribution = summary.ratingDistribution || { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };
    const labels = criteria.length ? criteria.map(item => item.name) : ['No data'];
    const values = criteria.length ? criteria.map(item => Number(item.average || 0)) : [0];

    if (barCtx) {
        if (window.studentBarChartInstance) window.studentBarChartInstance.destroy();
        window.studentBarChartInstance = new Chart(barCtx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Average Score',
                    data: values,
                    backgroundColor: '#667eea',
                    borderColor: '#764ba2',
                    borderWidth: 1
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

    if (pieCtx) {
        if (window.studentPieChartInstance) window.studentPieChartInstance.destroy();
        window.studentPieChartInstance = new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: ['5 Stars', '4 Stars', '3 Stars', '2 Stars', '1 Star'],
                datasets: [{
                    data: [
                        Number(distribution[5] || 0),
                        Number(distribution[4] || 0),
                        Number(distribution[3] || 0),
                        Number(distribution[2] || 0),
                        Number(distribution[1] || 0)
                    ],
                    backgroundColor: ['#10b981', '#34d399', '#fbbf24', '#f59e0b', '#ef4444'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
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

function initializePeerCharts() {
    const barCtx = document.getElementById('peerBarChart');
    const pieCtx = document.getElementById('peerPieChart');
    const summary = getDeanSummaryForType('professor');
    const criteria = Array.isArray(summary.criteriaAverages) ? summary.criteriaAverages : [];
    const distribution = summary.ratingDistribution || { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };
    const labels = criteria.length ? criteria.map(item => item.name) : ['No data'];
    const values = criteria.length ? criteria.map(item => Number(item.average || 0)) : [0];

    if (barCtx) {
        if (window.peerBarChartInstance) window.peerBarChartInstance.destroy();
        window.peerBarChartInstance = new Chart(barCtx, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: 'Peer Avg Score',
                    data: values,
                    backgroundColor: '#34d399',
                    borderColor: '#059669',
                    borderWidth: 1
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

    if (pieCtx) {
        if (window.peerPieChartInstance) window.peerPieChartInstance.destroy();
        window.peerPieChartInstance = new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: ['5 Stars', '4 Stars', '3 Stars', '2 Stars', '1 Star'],
                datasets: [{
                    data: [
                        Number(distribution[5] || 0),
                        Number(distribution[4] || 0),
                        Number(distribution[3] || 0),
                        Number(distribution[2] || 0),
                        Number(distribution[1] || 0)
                    ],
                    backgroundColor: ['#10b981', '#34d399', '#fbbf24', '#f59e0b', '#ef4444'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
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

function normalizeDeanUserIdToken(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^u\d+$/i.test(raw)) return 'u' + raw.replace(/^u/i, '');
    if (/^\d+$/.test(raw)) return 'u' + String(parseInt(raw, 10));
    return '';
}

function normalizeDeanToken(value) {
    return String(value || '').trim().toLowerCase();
}

function resolveCurrentDeanActorUserId() {
    const deanUser = resolveCurrentDeanUserAnyStatus(getUserSession() || {});
    if (!deanUser) return '';
    if (normalizeDeanToken(deanUser.status) === 'inactive') return '';
    return normalizeDeanUserIdToken(deanUser.id);
}

function formatDeanPaperTimestamp(value) {
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

function mapDeanPaperStatus(status) {
    const token = normalizeDeanToken(status);
    if (token === 'draft') return 'Draft';
    if (token === 'archived') return 'Archived';
    if (token === 'sent') return 'Sent';
    if (token === 'completed') return 'Completed';
    return 'Unknown';
}

async function openDeanFacultyPaperPdf(payload, downloadFilename) {
    let modal = document.getElementById('deanPdfPreviewModal');
    let frame = document.getElementById('deanPdfPreviewFrame');
    let blobUrlHolder = openDeanFacultyPaperPdf._blobUrl || '';
    let filenameHolder = openDeanFacultyPaperPdf._filename || 'faculty_acknowledgement.pdf';

    function closeModal() {
        if (frame) frame.src = 'about:blank';
        if (modal) modal.classList.remove('active');
        if (blobUrlHolder) {
            URL.revokeObjectURL(blobUrlHolder);
            blobUrlHolder = '';
            openDeanFacultyPaperPdf._blobUrl = '';
        }
    }

    function ensureModal() {
        if (modal) return;
        modal = document.createElement('div');
        modal.id = 'deanPdfPreviewModal';
        modal.className = 'pdf-preview-modal';
        modal.innerHTML = `
            <div class="pdf-preview-dialog" role="dialog" aria-modal="true" aria-label="Faculty Paper PDF Preview">
                <div class="pdf-preview-toolbar">
                    <h3>Faculty Paper Preview</h3>
                    <div class="pdf-preview-actions">
                        <button type="button" class="btn-submit pdf-preview-download-btn" id="deanPdfPreviewDownloadBtn">Download</button>
                        <button type="button" class="btn-cancel pdf-preview-close-btn" id="deanPdfPreviewCloseBtn">Close</button>
                    </div>
                </div>
                <iframe id="deanPdfPreviewFrame" class="pdf-preview-frame" title="Faculty Paper PDF Preview"></iframe>
            </div>
        `;
        document.body.appendChild(modal);
        frame = document.getElementById('deanPdfPreviewFrame');

        const closeBtn = document.getElementById('deanPdfPreviewCloseBtn');
        const downloadBtn = document.getElementById('deanPdfPreviewDownloadBtn');

        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        if (downloadBtn) {
            downloadBtn.addEventListener('click', function () {
                if (!blobUrlHolder) return;
                const anchor = document.createElement('a');
                anchor.href = blobUrlHolder;
                anchor.download = filenameHolder || 'faculty_acknowledgement.pdf';
                document.body.appendChild(anchor);
                anchor.click();
                document.body.removeChild(anchor);
            });
        }

        modal.addEventListener('click', function (event) {
            if (event.target === modal) closeModal();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && modal && modal.classList.contains('active')) {
                closeModal();
            }
        });
    }

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
        alert('Unable to connect to the PDF generator endpoint. Please try again.');
        return;
    }

    if (!response.ok) {
        let errorMessage = 'Failed to generate faculty acknowledgement paper.';
        try {
            const errorData = await response.json();
            if (errorData && errorData.error) errorMessage = errorData.error;
        } catch (_error) {
            // Ignore non-JSON response body.
        }
        alert(errorMessage);
        return;
    }

    const pdfBlob = await response.blob();
    ensureModal();
    if (!frame || !modal) {
        alert('Unable to open PDF preview modal.');
        return;
    }

    if (blobUrlHolder) {
        URL.revokeObjectURL(blobUrlHolder);
        blobUrlHolder = '';
    }
    filenameHolder = downloadFilename || 'faculty_acknowledgement.pdf';
    blobUrlHolder = URL.createObjectURL(pdfBlob);
    openDeanFacultyPaperPdf._blobUrl = blobUrlHolder;
    openDeanFacultyPaperPdf._filename = filenameHolder;
    frame.src = `${blobUrlHolder}#toolbar=1&navpanes=0&scrollbar=1`;
    modal.classList.add('active');
}

async function openDeanStoredPaperPdf(paper, actorUserId, versionNo) {
    const paperId = String(paper && paper.id || '').trim();
    const actorId = normalizeDeanUserIdToken(actorUserId);
    if (!paperId || !actorId) {
        throw new Error('Unable to resolve stored paper context.');
    }

    const params = new URLSearchParams({
        paper_id: paperId,
        actor_role: 'dean',
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
            // Ignore non-JSON body.
        }
        throw new Error(message);
    }

    const pdfBlob = await response.blob();
    const filename = String(paper.latest_file_name || `${paperId}.pdf`).trim() || `${paperId}.pdf`;

    const blobUrl = URL.createObjectURL(pdfBlob);
    let modal = document.getElementById('deanPdfPreviewModal');
    let frame = document.getElementById('deanPdfPreviewFrame');
    if (!modal || !frame) {
        modal = document.createElement('div');
        modal.id = 'deanPdfPreviewModal';
        modal.className = 'pdf-preview-modal';
        modal.innerHTML = `
            <div class="pdf-preview-dialog" role="dialog" aria-modal="true" aria-label="Faculty Paper PDF Preview">
                <div class="pdf-preview-toolbar">
                    <h3>Faculty Paper Preview</h3>
                    <div class="pdf-preview-actions">
                        <button type="button" class="btn-submit pdf-preview-download-btn" id="deanPdfPreviewDownloadBtn">Download</button>
                        <button type="button" class="btn-cancel pdf-preview-close-btn" id="deanPdfPreviewCloseBtn">Close</button>
                    </div>
                </div>
                <iframe id="deanPdfPreviewFrame" class="pdf-preview-frame" title="Faculty Paper PDF Preview"></iframe>
            </div>
        `;
        document.body.appendChild(modal);
        frame = document.getElementById('deanPdfPreviewFrame');

        const closeBtn = document.getElementById('deanPdfPreviewCloseBtn');
        const downloadBtn = document.getElementById('deanPdfPreviewDownloadBtn');
        if (closeBtn) {
            closeBtn.addEventListener('click', function () {
                if (frame) frame.src = 'about:blank';
                if (modal) modal.classList.remove('active');
                if (openDeanFacultyPaperPdf._blobUrl) {
                    URL.revokeObjectURL(openDeanFacultyPaperPdf._blobUrl);
                    openDeanFacultyPaperPdf._blobUrl = '';
                }
            });
        }
        if (downloadBtn) {
            downloadBtn.addEventListener('click', function () {
                if (!openDeanFacultyPaperPdf._blobUrl) return;
                const anchor = document.createElement('a');
                anchor.href = openDeanFacultyPaperPdf._blobUrl;
                anchor.download = openDeanFacultyPaperPdf._filename || 'faculty_acknowledgement.pdf';
                document.body.appendChild(anchor);
                anchor.click();
                document.body.removeChild(anchor);
            });
        }
        modal.addEventListener('click', function (event) {
            if (event.target !== modal) return;
            if (frame) frame.src = 'about:blank';
            modal.classList.remove('active');
        });
        document.addEventListener('keydown', function (event) {
            if (event.key !== 'Escape' || !modal || !modal.classList.contains('active')) return;
            if (frame) frame.src = 'about:blank';
            modal.classList.remove('active');
        });
    }

    if (openDeanFacultyPaperPdf._blobUrl) {
        URL.revokeObjectURL(openDeanFacultyPaperPdf._blobUrl);
    }
    openDeanFacultyPaperPdf._blobUrl = blobUrl;
    openDeanFacultyPaperPdf._filename = filename;
    frame.src = `${blobUrl}#toolbar=1&navpanes=0&scrollbar=1`;
    modal.classList.add('active');
}

function renderDeanFacultyPaperDetail(paper) {
    const card = document.getElementById('deanFacultyPaperDetailCard');
    const meta = document.getElementById('deanFacultyPaperDetailMeta');
    const areasInput = document.getElementById('deanSectionCAreas');
    const activitiesInput = document.getElementById('deanSectionCActivities');
    const actionPlanInput = document.getElementById('deanSectionCActionPlan');
    const saveBtn = document.getElementById('deanFacultyPaperSaveBtn');
    const previewBtn = document.getElementById('deanFacultyPaperPreviewBtn');

    if (!card) return;

    if (!paper) {
        card.style.display = 'none';
        return;
    }

    card.style.display = 'block';
    if (meta) meta.textContent = `Sent: ${formatDeanPaperTimestamp(paper.sent_at)} | Updated: ${formatDeanPaperTimestamp(paper.updated_at)}`;

    const setText = (id, value) => {
        const el = document.getElementById(id);
        if (el) el.textContent = String(value || 'N/A');
    };

    setText('deanFpDetailId', paper.id || 'N/A');
    setText('deanFpDetailStatus', mapDeanPaperStatus(paper.status));
    setText('deanFpDetailFacultyName', paper.professor_name || 'N/A');
    setText('deanFpDetailDepartment', paper.department || 'N/A');
    setText('deanFpDetailRank', paper.rank || 'N/A');
    setText('deanFpDetailSemester', paper.semester_label || 'N/A');
    setText('deanFpDetailSetRating', paper.set_rating || 'N/A');
    setText('deanFpDetailSafRating', paper.saf_rating || 'N/A');

    if (areasInput) areasInput.value = String(paper.section_c_areas || '');
    if (activitiesInput) activitiesInput.value = String(paper.section_c_activities || '');
    if (actionPlanInput) actionPlanInput.value = String(paper.section_c_action_plan || '');

    const editable = normalizeDeanToken(paper.status) === 'sent' || normalizeDeanToken(paper.status) === 'completed';
    if (areasInput) areasInput.disabled = !editable;
    if (activitiesInput) activitiesInput.disabled = !editable;
    if (actionPlanInput) actionPlanInput.disabled = !editable;
    if (saveBtn) saveBtn.disabled = !editable;

    if (previewBtn) {
        previewBtn.onclick = async () => {
            const statusToken = normalizeDeanToken(paper.status);
            const actorId = deanFacultyPaperState.actorUserId || resolveCurrentDeanActorUserId();
            const shouldUseStored = (statusToken === 'sent' || statusToken === 'completed')
                && String(paper.latest_file_path || '').trim() !== ''
                && !!actorId;

            if (shouldUseStored) {
                try {
                    await openDeanStoredPaperPdf(paper, actorId);
                    return;
                } catch (error) {
                    console.warn('[DeanPanel] Falling back to live PDF generation.', error);
                }
            }

            await openDeanFacultyPaperPdf({
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
}

function renderDeanFacultyPaperInbox() {
    const tableBody = document.getElementById('deanFacultyPaperTableBody');
    if (!tableBody) return;

    const actorUserId = resolveCurrentDeanActorUserId();
    deanFacultyPaperState.actorUserId = actorUserId;
    if (!actorUserId) {
        tableBody.innerHTML = '<tr><td colspan="6">Unable to resolve dean account for this session.</td></tr>';
        renderDeanFacultyPaperDetail(null);
        return;
    }

    let papers = [];
    try {
        papers = SharedData.listFacultyPapers('dean', actorUserId);
    } catch (error) {
        tableBody.innerHTML = '<tr><td colspan="6">Failed to load faculty papers.</td></tr>';
        renderDeanFacultyPaperDetail(null);
        return;
    }

    deanFacultyPaperState.papers = Array.isArray(papers) ? papers : [];
    if (!deanFacultyPaperState.papers.length) {
        tableBody.innerHTML = '<tr><td colspan="6">No faculty papers assigned.</td></tr>';
        deanFacultyPaperState.selectedId = '';
        renderDeanFacultyPaperDetail(null);
        return;
    }

    if (!deanFacultyPaperState.papers.some(item => item.id === deanFacultyPaperState.selectedId)) {
        deanFacultyPaperState.selectedId = deanFacultyPaperState.papers[0].id || '';
    }

    tableBody.innerHTML = deanFacultyPaperState.papers.map(paper => {
        const selected = deanFacultyPaperState.selectedId === paper.id;
        return `
            <tr class="${selected ? 'faculty-paper-row-active' : ''}">
                <td>${escapeHTML(String(paper.id || 'N/A'))}</td>
                <td>${escapeHTML(String(paper.professor_name || 'N/A'))}</td>
                <td>${escapeHTML(String(paper.semester_label || 'N/A'))}</td>
                <td>${escapeHTML(mapDeanPaperStatus(paper.status))}</td>
                <td>${escapeHTML(formatDeanPaperTimestamp(paper.sent_at))}</td>
                <td><button type="button" class="btn-submit dean-paper-open-btn" data-paper-id="${escapeHTML(String(paper.id || ''))}">Open</button></td>
            </tr>
        `;
    }).join('');

    tableBody.querySelectorAll('.dean-paper-open-btn').forEach(button => {
        button.addEventListener('click', () => {
            deanFacultyPaperState.selectedId = button.getAttribute('data-paper-id') || '';
            const selected = deanFacultyPaperState.papers.find(item => String(item.id || '') === deanFacultyPaperState.selectedId) || null;
            renderDeanFacultyPaperDetail(selected);
            renderDeanFacultyPaperInbox();
        });
    });

    const selectedPaper = deanFacultyPaperState.papers.find(item => String(item.id || '') === deanFacultyPaperState.selectedId) || null;
    renderDeanFacultyPaperDetail(selectedPaper);
}

function setupDeanFacultyPaperInbox() {
    const form = document.getElementById('deanFacultyPaperSectionCForm');
    if (form) {
        form.addEventListener('submit', function (event) {
            event.preventDefault();
            const actorUserId = deanFacultyPaperState.actorUserId || resolveCurrentDeanActorUserId();
            if (!actorUserId) {
                alert('Unable to resolve dean account.');
                return;
            }

            const paperId = deanFacultyPaperState.selectedId;
            if (!paperId) {
                alert('Select a faculty paper first.');
                return;
            }

            const areas = document.getElementById('deanSectionCAreas');
            const activities = document.getElementById('deanSectionCActivities');
            const actionPlan = document.getElementById('deanSectionCActionPlan');

            try {
                const response = SharedData.saveFacultyPaperSectionC({
                    actor_role: 'dean',
                    actor_user_id: actorUserId,
                    paper_id: paperId,
                    section_c: {
                        areas: areas ? areas.value : '',
                        activities: activities ? activities.value : '',
                        action_plan: actionPlan ? actionPlan.value : '',
                    }
                });
                if (!response || response.success === false) {
                    throw new Error((response && response.error) || 'Failed to save Section C.');
                }
                renderDeanFacultyPaperInbox();
                alert('Section C saved successfully.');
            } catch (error) {
                alert(error && error.message ? error.message : 'Failed to save Section C.');
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
function updateSummaryCards() {
    const stats = getFacultySummaryTotals();

    // Update card numbers
    const evaluationsCard = document.querySelector('.summary-card.evaluations .card-number');
    const scoreCard = document.querySelector('.summary-card.score .score-text');
    const professorsCard = document.querySelector('.summary-card.professors .card-number');
    const responseCard = document.querySelector('.summary-card.response .card-number');

    if (evaluationsCard) evaluationsCard.textContent = `${stats.received}/${stats.required}`;
    if (scoreCard) scoreCard.textContent = `${stats.averageScore.toFixed(1)}/5.0`;
    if (professorsCard) professorsCard.textContent = String(deanProfessorCount);
    if (responseCard) responseCard.textContent = `${stats.responseRate}%`;
}

/**
 * Setup peer evaluation form functionality
 */
function setupPeerEvaluationForm() {
    const form = document.getElementById('peerEvaluationForm');
    const cancelBtn = document.getElementById('cancelPeerBtn');
    const peerSearchInput = document.getElementById('peerProfessorSearch');
    if (!form) return;

    // Force supervisor mode
    const evaluationTypeInput = document.getElementById('evaluationType');
    const targetLabel = document.getElementById('peerTargetLabel');
    const endpoint = document.getElementById('peerEvaluationEndpoint');

    if (evaluationTypeInput) evaluationTypeInput.value = 'supervisor';
    if (form) form.dataset.evalType = 'supervisor';
    if (targetLabel) targetLabel.textContent = 'Select Employee';
    if (endpoint) endpoint.textContent = 'SQL Ready: connect to /api/dean/supervisor-evaluations/submit (POST)';

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        handlePeerEvaluation();
    });

    if (peerSearchInput) {
        peerSearchInput.addEventListener('input', function () {
            syncSupervisorTargetFromInput();
        });
        peerSearchInput.addEventListener('change', function () {
            syncSupervisorTargetFromInput();
        });
        peerSearchInput.addEventListener('blur', function () {
            syncSupervisorTargetFromInput();
        });
    }

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            form.reset();
            syncSupervisorTargetFromInput();
            switchView('dashboard');
            updateNavigation('dashboard');
        });
    }

    refreshSupervisorTargetLockState();
}

function populatePeerProfessorOptions() {
    const hiddenSelectValue = document.getElementById('peerProfessor');
    const searchInput = document.getElementById('peerProfessorSearch');
    const datalist = document.getElementById('peerProfessorOptions');
    const searchMeta = document.getElementById('peerProfessorSearchMeta');
    if (!hiddenSelectValue || !searchInput || !datalist) return;

    const currentUserId = String(hiddenSelectValue.value || '').trim();
    const professors = getScopedProfessorUsers(false);
    deanSupervisorTargetDirectory = professors.map(professor => {
        const userId = normalizeUserIdToken(professor && professor.id) || String(professor && professor.employeeId || '').trim();
        if (!userId) return null;
        const name = String(professor && professor.name || 'Unknown').trim() || 'Unknown';
        const employeeId = String(professor && professor.employeeId || '').trim();
        const department = String((professor && (professor.department || professor.institute)) || '').trim().toUpperCase();
        const programCode = String(professor && professor.programCode || '').trim().toUpperCase();
        const programLabel = programCode || 'UNASSIGNED';
        const label = `${name} (${[employeeId || 'N/A', programLabel, department || 'N/A'].join(' | ')})`;
        return {
            userId,
            label
        };
    }).filter(Boolean);

    datalist.innerHTML = '';
    deanSupervisorTargetDirectory.forEach(item => {
        const option = document.createElement('option');
        option.value = item.label;
        datalist.appendChild(option);
    });

    if (searchMeta) {
        const label = deanSupervisorTargetDirectory.length === 1 ? 'employee' : 'employees';
        searchMeta.textContent = deanSupervisorTargetDirectory.length
            ? `Showing ${deanSupervisorTargetDirectory.length} ${label} in your department scope.`
            : 'No active professors in your department scope.';
    }

    const currentMatch = deanSupervisorTargetDirectory.find(item => item.userId === currentUserId);
    if (currentMatch) {
        hiddenSelectValue.value = currentMatch.userId;
        searchInput.value = currentMatch.label;
    } else {
        hiddenSelectValue.value = '';
    }
    syncSupervisorTargetFromInput();
}

function syncSupervisorTargetFromInput() {
    const searchInput = document.getElementById('peerProfessorSearch');
    const hiddenTarget = document.getElementById('peerProfessor');
    if (!searchInput || !hiddenTarget) return;

    const raw = String(searchInput.value || '').trim();
    if (!raw) {
        hiddenTarget.value = '';
        searchInput.setCustomValidity('');
        refreshSupervisorTargetLockState();
        return;
    }

    const match = deanSupervisorTargetDirectory.find(item =>
        String(item.label || '').trim().toLowerCase() === raw.toLowerCase()
    );
    if (match) {
        hiddenTarget.value = match.userId;
        searchInput.value = match.label;
        searchInput.setCustomValidity('');
    } else {
        hiddenTarget.value = '';
        searchInput.setCustomValidity('Please choose an employee from the dropdown suggestions.');
    }
    refreshSupervisorTargetLockState();
}

function setupEvaluationToggle(form) {
    // Peer toggle removed; supervisor mode enforced in setupPeerEvaluationForm
}

function normalizeSupervisorLockValue(value) {
    return String(value || '').trim().toLowerCase();
}

function getSupervisorSemesterId() {
    const semester = (SharedData.getCurrentSemester && SharedData.getCurrentSemester()) || '';
    return String(semester || '').trim() || 'current';
}

function buildSupervisorEvaluationKey(evaluatorId, semesterId, targetId) {
    return [
        normalizeSupervisorLockValue(evaluatorId),
        normalizeSupervisorLockValue(semesterId),
        normalizeSupervisorLockValue(targetId)
    ].join('|');
}

function isSupervisorTargetLocked(targetId) {
    const session = SharedData.getSession() || {};
    const evaluatorId = session.username || '';
    const semesterId = getSupervisorSemesterId();
    const key = buildSupervisorEvaluationKey(evaluatorId, semesterId, targetId);
    const evaluations = (SharedData.getEvaluations && SharedData.getEvaluations()) || [];

    return evaluations.some(ev => {
        const role = String(ev.evaluatorRole || ev.evaluationType || '').toLowerCase();
        if (role && role !== 'dean' && role !== 'supervisor') return false;

        const evEvaluator = normalizeSupervisorLockValue(ev.evaluatorId || ev.evaluatorUsername);
        if (!evEvaluator || evEvaluator !== normalizeSupervisorLockValue(evaluatorId)) return false;

        const evSemester = normalizeSupervisorLockValue(ev.semesterId);
        if (evSemester && evSemester !== normalizeSupervisorLockValue(semesterId)) return false;

        const existingKey = normalizeSupervisorLockValue(ev.evaluationKey);
        if (existingKey && existingKey === normalizeSupervisorLockValue(key)) return true;

        const evTarget = normalizeSupervisorLockValue(ev.targetProfessorId || ev.targetId || ev.colleagueId);
        return !!evTarget && evTarget === normalizeSupervisorLockValue(targetId);
    });
}

function refreshSupervisorTargetLockState() {
    const form = document.getElementById('peerEvaluationForm');
    const select = document.getElementById('peerProfessor');
    const submitBtn = form ? form.querySelector('.btn-submit') : null;
    if (!form || !select || !submitBtn) return;

    const targetId = String(select.value || '').trim();
    if (!targetId) {
        submitBtn.disabled = false;
        return;
    }

    const locked = isSupervisorTargetLocked(targetId);
    submitBtn.disabled = locked;

    if (locked) {
        showFormMessage(form, 'You already submitted a supervisor evaluation for this target this semester.', 'error');
    }
}

/**
 * Placeholder peer evaluation handler (SQL-ready)
 */
function handlePeerEvaluation() {
    const form = document.getElementById('peerEvaluationForm');
    if (!form) return;

    syncSupervisorTargetFromInput();

    if (!enforceActiveDeanAccount({ inline: true, form })) {
        return;
    }

    // ── Evaluation period gate ──
    if (!SharedData.isEvalPeriodOpen('supervisor-professor')) {
        const dates = SharedData.getEvalPeriodDates('supervisor-professor');
        let msg = 'The Supervisor to Professor evaluation period is not currently open.';
        if (dates.start && dates.end) {
            msg += '\nEvaluation period: ' + dates.start + ' to ' + dates.end + '.';
        } else {
            msg += '\nNo evaluation period has been set by the administrator yet.';
        }
        showFormMessage(form, msg, 'error');
        return;
    }

    const selectedTargetId = String((document.getElementById('peerProfessor') || {}).value || '').trim();
    if (selectedTargetId && isSupervisorTargetLocked(selectedTargetId)) {
        showFormMessage(form, 'You already submitted a supervisor evaluation for this target this semester.', 'error');
        refreshSupervisorTargetLockState();
        return;
    }

    enableAllSupervisorStepInputs();

    if (!form.checkValidity()) {
        const firstInvalid = form.querySelector(':invalid');
        if (firstInvalid) {
            const targetStep = firstInvalid.closest('.eval-step');
            if (targetStep) {
                const targetIndex = parseInt(targetStep.getAttribute('data-step-index'), 10);
                if (!Number.isNaN(targetIndex)) {
                    goToSupervisorStep(targetIndex);
                }
            }
        }
        form.reportValidity();
        return;
    }

    const formData = new FormData(form);
    const evaluationType = 'supervisor';

    // Fetch data definitions to separate ratings from qualitative
    const questionnaires = SharedData.getQuestionnaires() || {};
    const semester = SharedData.getCurrentSemester() || '';
    let dataToUse = null;
    if (semester && questionnaires[semester]) {
        dataToUse = questionnaires[semester];
    } else {
        const semesters = Object.keys(questionnaires).sort().reverse();
        if (semesters.length > 0) dataToUse = questionnaires[semesters[0]];
    }
    const supervisorData = (dataToUse && dataToUse['supervisor-to-professor']) || { sections: [], questions: [], header: {} };
    const allQuestions = supervisorData.questions || [];

    const ratingsGroup = {};
    const qualitativeGroup = {};

    for (let [key, value] of formData.entries()) {
        if (key === 'evaluationType' || key === 'peerProfessor' || key === 'peerProfessorSearch' || key === 'peerComments') continue;

        // Find the question definition
        let questionDef = allQuestions.find(q => String(q.id) === key);

        if (questionDef && questionDef.type === 'qualitative') {
            qualitativeGroup[key] = value;
        } else {
            ratingsGroup[key] = value;
        }
    }

    const session = SharedData.getSession() || {};
    const semesterId = getSupervisorSemesterId();
    const targetProfessorId = formData.get('peerProfessor') || '';
    const evaluationKey = buildSupervisorEvaluationKey(session.username || '', semesterId, targetProfessorId);
    const payload = {
        evaluatorId: session.username || '',
        evaluatorName: session.fullName || 'Anonymous Dean',
        evaluatorRole: 'dean',
        evaluationType: 'supervisor',
        targetId: formData.get('peerProfessor'),
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
            title: 'Supervisor Evaluation Submitted',
            user: payload.evaluatorName,
            role: 'dean',
            date: new Date().toISOString()
        });
    } catch (error) {
        const message = String(error && error.message || '');
        if (message.toLowerCase().includes('inactive')) {
            enforceActiveDeanAccount({ inline: true, form });
            return;
        }
        showFormMessage(form, message || 'Failed to submit supervisor evaluation. Please try again.', 'error');
        return;
    }

    console.log('Supervisor evaluation submitted to local database:', payload);
    showFormMessage(
        form,
        'Supervisor evaluation submitted successfully to local database.',
        'success'
    );

    // Auto redirect after briefly showing the success state
    setTimeout(() => {
        form.reset();
        syncSupervisorTargetFromInput();
        const evaluationTypeInput = document.getElementById('evaluationType');
        if (evaluationTypeInput) evaluationTypeInput.value = evaluationType;
        refreshSupervisorTargetLockState();
        switchView('dashboard');

        // Find and update the active nav link for dashboard
        const navLinks = document.querySelectorAll('.nav-link:not(.logout)');
        navLinks.forEach(l => l.classList.remove('active'));
        const dashboardLink = document.querySelector('.nav-link[data-view="dashboard"]');
        if (dashboardLink) dashboardLink.classList.add('active');
    }, 1500);
}

/**
 * Load Dynamic Supervisor Questionnaire from Shared Data
 */
function loadDynamicSupervisorQuestionnaire() {
    const container = document.getElementById('dynamic-supervisor-questions-container');
    if (!container) return;

    const questionnaires = SharedData.getQuestionnaires() || {};
    const semester = SharedData.getCurrentSemester() || '';

    console.log('[Dean] loadDynamicSupervisorQuestionnaire — semester:', JSON.stringify(semester), '| available keys:', Object.keys(questionnaires));

    let dataToUse = null;
    if (semester && questionnaires[semester]) {
        dataToUse = questionnaires[semester];
    } else {
        // Fallback to latest available semester
        const semesters = Object.keys(questionnaires).sort().reverse();
        if (semesters.length > 0) {
            dataToUse = questionnaires[semesters[0]];
            console.log('[Dean] Semester key not found, falling back to:', semesters[0]);
        }
    }

    // Fallback if structure is missing
    const supervisorData = (dataToUse && dataToUse['supervisor-to-professor']) || { sections: [], questions: [], header: {} };

    // Update Form Header
    const dynamicTitle = document.getElementById('dynamic-form-title');
    if (dynamicTitle) {
        dynamicTitle.textContent = supervisorData.header && supervisorData.header.title ? supervisorData.header.title : 'Supervisor Evaluation Form';
    }

    const dynamicDesc = document.getElementById('dynamic-form-description');
    if (dynamicDesc) {
        dynamicDesc.textContent = supervisorData.header && supervisorData.header.description ? supervisorData.header.description : 'Evaluate an employee based on workplace conduct, collaboration, and professionalism.';
    }

    if (!supervisorData || !supervisorData.sections || supervisorData.sections.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="text-align: center; padding: 3rem 1rem; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;">
                <i class="fas fa-clipboard-list" style="font-size: 3rem; color: #94a3b8; margin-bottom: 1rem;"></i>
                <h3 style="color: #475569; margin-bottom: 0.5rem;">No Evaluation Form Configured</h3>
                <p style="color: #64748b; font-size: 0.95rem;">Please configure the Supervisor-to-Professor evaluation form in the Admin Panel.</p>
            </div>
        `;
        return;
    }

    let html = '';
    let stepIndex = 0;

    html += `
        <div class="eval-form-progress" id="supervisor-form-progress">
            <div class="eval-form-progress-header">
                <span class="eval-form-progress-label">Progress</span>
                <span class="eval-form-progress-meta" id="supervisor-progress-meta">Section 1 of 1</span>
            </div>
            <div class="eval-form-progress-track">
                <div class="eval-form-progress-fill" id="supervisor-progress-fill" style="width: 0%;"></div>
            </div>
        </div>
    `;

    supervisorData.sections.forEach(section => {
        const sectionHasContent = supervisorData.questions && supervisorData.questions.some(q => q.sectionId === section.id);

        if (!sectionHasContent) return;

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

        const sectionQuestions = supervisorData.questions.filter(q => q.sectionId === section.id);

        sectionQuestions.forEach((question, index) => {
            html += renderSupervisorQuestionHTML(question, index);
        });

        html += `
                </div>
            </div>
        `;
        stepIndex++;
    });
    html += `
        <div class="eval-form-nav" id="supervisor-form-nav">
            <button type="button" class="btn-eval-nav btn-eval-prev" id="supervisor-prev-btn" disabled>
                <i class="fas fa-arrow-left"></i>
                Back
            </button>
            <button type="button" class="btn-eval-nav btn-eval-next" id="supervisor-next-btn">
                Next
                <i class="fas fa-arrow-right"></i>
            </button>
        </div>
    `;
    container.innerHTML = html;
    setupSupervisorSectionFlow();
    refreshSupervisorTargetLockState();
}

function setupSupervisorSectionFlow() {
    const steps = Array.from(document.querySelectorAll('#dynamic-supervisor-questions-container .eval-step'));
    const prevBtn = document.getElementById('supervisor-prev-btn');
    const nextBtn = document.getElementById('supervisor-next-btn');

    supervisorSectionFlow.steps = steps;
    supervisorSectionFlow.activeIndex = 0;

    if (!steps.length) {
        const submitBtn = document.querySelector('#peerEvaluationForm .btn-submit');
        const progress = document.getElementById('supervisor-form-progress');
        const nav = document.getElementById('supervisor-form-nav');
        if (progress) progress.style.display = 'none';
        if (nav) nav.style.display = 'none';
        if (submitBtn) submitBtn.style.display = 'inline-flex';
        return;
    }

    if (prevBtn) {
        prevBtn.onclick = () => goToSupervisorStep(supervisorSectionFlow.activeIndex - 1);
    }
    if (nextBtn) {
        nextBtn.onclick = () => {
            if (!validateSupervisorCurrentStep()) return;
            goToSupervisorStep(supervisorSectionFlow.activeIndex + 1);
        };
    }

    goToSupervisorStep(0);
}

function goToSupervisorStep(index) {
    const steps = supervisorSectionFlow.steps || [];
    if (!steps.length) return;

    const maxIndex = steps.length - 1;
    supervisorSectionFlow.activeIndex = Math.max(0, Math.min(index, maxIndex));

    steps.forEach((step, idx) => {
        const isActive = idx === supervisorSectionFlow.activeIndex;
        step.classList.toggle('is-active', isActive);
        toggleSupervisorStepInputs(step, isActive);
    });

    const prevBtn = document.getElementById('supervisor-prev-btn');
    const nextBtn = document.getElementById('supervisor-next-btn');
    const progressFill = document.getElementById('supervisor-progress-fill');
    const progressMeta = document.getElementById('supervisor-progress-meta');
    const submitBtn = document.querySelector('#peerEvaluationForm .btn-submit');

    const isFirst = supervisorSectionFlow.activeIndex === 0;
    const isLast = supervisorSectionFlow.activeIndex === maxIndex;
    const progressPercent = ((supervisorSectionFlow.activeIndex + 1) / steps.length) * 100;

    if (progressFill) progressFill.style.width = `${progressPercent}%`;
    if (progressMeta) progressMeta.textContent = `Section ${supervisorSectionFlow.activeIndex + 1} of ${steps.length}`;
    if (prevBtn) prevBtn.disabled = isFirst;
    if (nextBtn) nextBtn.style.display = isLast ? 'none' : 'inline-flex';
    if (submitBtn) submitBtn.style.display = isLast ? 'inline-flex' : 'none';
}

function toggleSupervisorStepInputs(stepElement, enabled) {
    if (!stepElement) return;
    const fields = stepElement.querySelectorAll('input, textarea, select');
    fields.forEach(field => {
        field.disabled = !enabled;
    });
}

function validateSupervisorCurrentStep() {
    const current = supervisorSectionFlow.steps[supervisorSectionFlow.activeIndex];
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

function enableAllSupervisorStepInputs() {
    const fields = document.querySelectorAll('#dynamic-supervisor-questions-container .eval-step input, #dynamic-supervisor-questions-container .eval-step textarea, #dynamic-supervisor-questions-container .eval-step select');
    fields.forEach(field => {
        field.disabled = false;
    });
}

function renderSupervisorQuestionHTML(question, index) {
    const isRequired = question.required ? 'required' : '';
    const qid = String(question.id);

    if (question.type === 'qualitative') {
        return `
            <div class="question-group" style="margin-bottom: 24px;">
                <label class="question-label" for="q-${qid}">${escapeHTML(question.text)} ${question.required ? '<span style="color:#ef4444">*</span>' : ''}</label>
                <div class="form-group" style="margin-top: 8px;">
                    <textarea id="q-${qid}" name="${qid}" class="form-textarea" rows="4" placeholder="Type your response here..." ${isRequired}></textarea>
                </div>
            </div>
        `;
    }

    // Default to rating scale
    return `
        <div class="question-group">
            <label class="question-label">${escapeHTML(question.text)} ${question.required ? '<span style="color:#ef4444">*</span>' : ''}</label>
            <div class="rating-scale">
                <input type="radio" name="${qid}" id="q-${qid}-1" value="1" ${isRequired}>
                <label for="q-${qid}-1" class="rating-option">1</label>
                <input type="radio" name="${qid}" id="q-${qid}-2" value="2" ${isRequired}>
                <label for="q-${qid}-2" class="rating-option">2</label>
                <input type="radio" name="${qid}" id="q-${qid}-3" value="3" ${isRequired}>
                <label for="q-${qid}-3" class="rating-option">3</label>
                <input type="radio" name="${qid}" id="q-${qid}-4" value="4" ${isRequired}>
                <label for="q-${qid}-4" class="rating-option">4</label>
                <input type="radio" name="${qid}" id="q-${qid}-5" value="5" ${isRequired}>
                <label for="q-${qid}-5" class="rating-option">5</label>
            </div>
            <p class="rating-legend">5 = Excellent, 1 = Poor</p>
        </div>
    `;
}

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
 * Load faculty summary data (SQL-ready placeholder)
 */
function loadFacultySummary(selection = {}) {
    const context = buildDeanPanelContext();
    const requestedType = getDeanEvaluationTypeMeta(
        selection.evaluationType
        || deanSummaryState.selectedEvaluationType
        || 'student'
    ).id;
    const requestedSemesterId = resolveSelectedSemesterId(
        selection.semesterId
        || selection.semester
        || deanSummaryState.selectedSemesterId
        || context.currentSemester
    );

    const semesterLabel = getSemesterLabelById(requestedSemesterId);
    deanSummaryState.selectedSemesterId = requestedSemesterId;
    deanSummaryState.selectedSemesterLabel = semesterLabel;
    deanSummaryState.selectedEvaluationType = requestedType;

    try {
        const studentSummary = fetchFacultySummaryFromSql({ semesterId: requestedSemesterId, evaluationType: 'student' });
        const professorSummary = fetchFacultySummaryFromSql({ semesterId: requestedSemesterId, evaluationType: 'professor' });
        const supervisorSummary = fetchFacultySummaryFromSql({ semesterId: requestedSemesterId, evaluationType: 'supervisor' });

        deanSummaryState.byType.student = studentSummary;
        deanSummaryState.byType.professor = professorSummary;
        deanSummaryState.byType.supervisor = supervisorSummary;

        const activeSummary = getDeanSummaryForType(requestedType);
        renderCriteriaSummary(activeSummary.criteriaAverages);
        renderDetailedSummaryTable(activeSummary.detailedRows, requestedType);
        renderEvaluationCount(activeSummary.breakdownRows || activeSummary.subjects || [], activeSummary.totals);
        updateSummaryCards();
        initializeReports();
    } catch (error) {
        deanSummaryState.byType.student = { ...DEAN_EMPTY_SUMMARY };
        deanSummaryState.byType.professor = { ...DEAN_EMPTY_SUMMARY };
        deanSummaryState.byType.supervisor = { ...DEAN_EMPTY_SUMMARY };
        const emptySummary = getDeanSummaryForType(requestedType);
        renderCriteriaSummary([]);
        renderDetailedSummaryTable([], requestedType);
        renderEvaluationCount([], emptySummary.totals);
        updateSummaryCards();
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
    placeholder.textContent = buildInitials(fullName) || 'DP';

    const storedPhoto = SharedData.getProfilePhoto('dean');
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
            SharedData.setProfilePhoto('dean', reader.result);
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

function loadProfessorCount() {
    const session = getUserSession() || {};
    const deanId = session.username || '';
    const semesterId = resolveSelectedSemesterId(deanSummaryState.selectedSemesterId);
    const assignedInstitutes = getDeanAssignedInstitutes(session);

    return fetchDeanProfessorResultsFromSql({
        deanId,
        assignedInstitutes,
        semesterId,
        evaluationType: 'student'
    }).then(results => {
        deanProfessorCount = Array.isArray(results) ? results.length : 0;
        updateSummaryCards();
    }).catch(() => {
        deanProfessorCount = 0;
        updateSummaryCards();
    });
}

/**
 * SQL-ready fetch for faculty summary data
 */
function fetchFacultySummaryFromSql(query) {
    const context = buildDeanPanelContext();
    const evaluationType = getDeanEvaluationTypeMeta(query && query.evaluationType || 'student').id;
    const semesterId = resolveSelectedSemesterId(
        query && (query.semesterId || query.semester) || deanSummaryState.selectedSemesterId || context.currentSemester
    );
    return buildDeanEvaluationAggregates(context, evaluationType, semesterId);
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
 * Render evaluations received count
 */
function renderEvaluationCount(subjects, totals) {
    const countEl = document.getElementById('evaluationCount');
    if (!countEl) return;
    const summaryTotals = totals || computeTotals(subjects);
    countEl.textContent = `${summaryTotals.received}/${summaryTotals.required}`;
}

/**
 * Compute totals for summary cards
 */
function computeTotals(subjects) {
    return (Array.isArray(subjects) ? subjects : []).reduce((acc, item) => {
        acc.required += Number(item && item.required || 0);
        acc.received += Number(item && item.received || 0);
        return acc;
    }, { required: 0, received: 0 });
}

/**
 * Get totals for summary cards
 */
function getFacultySummaryTotals() {
    const activeType = getDeanEvaluationTypeMeta(deanSummaryState.selectedEvaluationType || 'student').id;
    const summary = getDeanSummaryForType(activeType);
    return summary && summary.totals ? summary.totals : { required: 0, received: 0, responseRate: 0, averageScore: 0 };
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
                targetCard.style.display = 'block';
            }
        });
    });
}

function hideAccountActionCards() {
    document.querySelectorAll('.account-action-card').forEach(card => {
        card.style.display = 'block';
    });
}

/**
 * Setup change email form functionality
 */
function setupChangeEmailForm() {
    const form = document.getElementById('changeEmailForm');
    if (!form) return;
    const newEmail = document.getElementById('newEmail');
    const confirmEmail = document.getElementById('confirmEmail');

    if (newEmail) newEmail.disabled = true;
    if (confirmEmail) confirmEmail.disabled = true;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        handleChangeEmail();
    });
}

/**
 * Placeholder change email handler (SQL-ready)
 */
function handleChangeEmail() {
    const form = document.getElementById('changeEmailForm');
    if (!form) return;
    showFormMessage(form, 'Email update is not available yet in this panel.', 'error');
}

/**
 * Setup change password form functionality
 */
function setupChangePasswordForm() {
    const form = document.getElementById('changePasswordForm');
    if (!form) return;
    const currentPassword = document.getElementById('currentPassword');
    const newPassword = document.getElementById('newPassword');
    const confirmPassword = document.getElementById('confirmPassword');

    if (currentPassword) currentPassword.disabled = true;
    if (newPassword) newPassword.disabled = true;
    if (confirmPassword) confirmPassword.disabled = true;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        handleChangePassword();
    });
}

/**
 * Placeholder change password handler (SQL-ready)
 */
function handleChangePassword() {
    const form = document.getElementById('changePasswordForm');
    if (!form) return;
    showFormMessage(form, 'Password update is not available yet in this panel.', 'error');
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
 * Setup dean-only professor evaluation visibility by institute
 */
function setupDeanEvaluationResults() {
    const instituteFilter = document.getElementById('deanInstituteFilter');
    const table = document.getElementById('deanProfessorResultsTable');
    if (!instituteFilter || !table) return;
    const session = getUserSession() || {};
    const deanId = session.username || '';
    const semesterId = resolveSelectedSemesterId(deanSummaryState.selectedSemesterId);
    const assignedInstitutes = getDeanAssignedInstitutes(session);
    fetchDeanProfessorResultsFromSql({
        deanId,
        assignedInstitutes,
        semesterId,
        evaluationType: 'student'
    }).then(results => {
        const institutes = Array.from(new Set(results.map(item => item.institute))).sort();
        instituteFilter.innerHTML = '<option value="all">Department scope</option>' + institutes.map(institute =>
            '<option value="' + institute + '">' + institute + '</option>'
        ).join('');
        renderDeanProfessorResults(results, instituteFilter.value);
        instituteFilter.addEventListener('change', function () {
            renderDeanProfessorResults(results, this.value);
        });
    }).catch(() => {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8">No professor evaluation results available.</td></tr>';
    });
}

/**
 * Resolve assigned institute scope for the current dean
 */
function getDeanAssignedInstitutes(sessionOrUsername) {
    const deanUser = resolveCurrentDeanUserAnyStatus(
        typeof sessionOrUsername === 'object' && sessionOrUsername
            ? sessionOrUsername
            : getUserSession() || {}
    );
    const department = String((deanUser && (deanUser.department || deanUser.institute)) || '').trim().toUpperCase();
    return department ? [department] : [];
}

function buildDeanProfessorResultRows(query, typeOverride) {
    const context = buildDeanPanelContext();
    const evaluationType = getDeanEvaluationTypeMeta(typeOverride || (query && query.evaluationType) || 'student').id;
    const semesterId = resolveSelectedSemesterId(
        query && (query.semesterId || query.semester) || deanSummaryState.selectedSemesterId || context.currentSemester
    );
    const assignedInstitutes = Array.isArray(query && query.assignedInstitutes) && query.assignedInstitutes.length
        ? query.assignedInstitutes.map(item => String(item || '').trim().toUpperCase()).filter(Boolean)
        : getDeanAssignedInstitutes(getUserSession() || {});
    const instituteSet = new Set(assignedInstitutes);
    const scopedProfessors = (context.scopedProfessors || []).filter(professor => {
        if (!instituteSet.size) return true;
        const institute = String((professor && (professor.department || professor.institute)) || '').trim().toUpperCase();
        return instituteSet.has(institute);
    });
    const evaluations = Array.isArray(context.evaluations) ? context.evaluations : [];

    const normalizedType = evaluationType;
    const filteredByType = evaluations.filter(evaluation =>
        resolveEvaluationTypeToken(evaluation) === normalizedType &&
        isEvaluationInSemester(evaluation, semesterId)
    );

    return scopedProfessors.map(professor => {
        const professorUserId = normalizeUserIdToken(professor && professor.id);
        const institute = String((professor && (professor.department || professor.institute)) || '').trim().toUpperCase();
        const professorEvaluations = [];
        let required = 0;

        if (normalizedType === 'student') {
            const offeringIds = new Set((context.offerings || []).filter(offering =>
                normalizeUserIdToken(offering && offering.professorUserId) === professorUserId &&
                isSemesterTokenMatch(offering && offering.semesterSlug, semesterId)
            ).map(offering => String(offering && offering.id || '').trim()).filter(Boolean));

            required = (context.enrollments || []).filter(enrollment => {
                const offeringId = String(enrollment && enrollment.courseOfferingId || '').trim();
                if (!offeringIds.has(offeringId)) return false;
                const status = normalizeRoleToken(enrollment && enrollment.status || 'enrolled');
                return status !== 'dropped' && status !== 'inactive';
            }).length;

            filteredByType.forEach(evaluation => {
                const offeringId = String(evaluation && evaluation.courseOfferingId || '').trim();
                if (!offeringIds.has(offeringId)) return;
                const targetProfessorId = resolveDeanTargetProfessorId(evaluation, 'student', context);
                if (targetProfessorId && targetProfessorId !== professorUserId) return;
                professorEvaluations.push(evaluation);
            });
        } else {
            required = normalizedType === 'professor'
                ? Math.max(scopedProfessors.length - 1, 0)
                : getActiveSupervisorCount();

            filteredByType.forEach(evaluation => {
                const targetProfessorId = resolveDeanTargetProfessorId(evaluation, normalizedType, context);
                if (targetProfessorId === professorUserId) {
                    professorEvaluations.push(evaluation);
                }
            });
        }

        const avgScore = computeAverageRatingFromEvaluations(professorEvaluations);
        const lastUpdated = professorEvaluations.reduce((latest, evaluation) => {
            const current = String(evaluation && (evaluation.submittedAt || evaluation.timestamp) || '').trim();
            if (!current) return latest;
            if (!latest) return current;
            return new Date(current).getTime() > new Date(latest).getTime() ? current : latest;
        }, '');
        const statusText = normalizeRoleToken(professor && professor.status || 'active') === 'inactive'
            ? 'Inactive'
            : 'Active';

        return {
            professorUserId,
            professorId: String(professor && (professor.employeeId || professor.id) || '').trim() || 'N/A',
            professorName: String(professor && professor.name || '').trim() || 'Unknown',
            institute,
            employmentType: String(professor && professor.employmentType || '').trim() || 'N/A',
            position: String(professor && professor.position || '').trim() || 'N/A',
            required,
            received: professorEvaluations.length,
            avgScore,
            lastUpdated,
            status: statusText
        };
    });
}

/**
 * SQL-ready fetch for dean-level professor results
 */
function fetchDeanProfessorResultsFromSql(query) {
    return Promise.resolve(buildDeanProfessorResultRows(query, 'student'));
}
/**
 * Render dean view table and metrics
 */
function renderDeanProfessorResults(results, selectedInstitute) {
    const table = document.getElementById('deanProfessorResultsTable');
    const professorCountEl = document.getElementById('deanProfessorCount');
    const averageScoreEl = document.getElementById('deanAverageScore');
    const responseRateEl = document.getElementById('deanResponseRate');
    const scopeBadge = document.getElementById('deanScopeBadge');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    const filtered = selectedInstitute && selectedInstitute !== 'all'
        ? results.filter(item => item.institute === selectedInstitute)
        : results.slice();
    if (!filtered.length) {
        tbody.innerHTML = '<tr><td colspan="8">No professor evaluation results in this scope.</td></tr>';
        if (professorCountEl) professorCountEl.textContent = '0';
        if (averageScoreEl) averageScoreEl.textContent = '0.0/5.0';
        if (responseRateEl) responseRateEl.textContent = '0%';
        if (scopeBadge) scopeBadge.textContent = 'Scope: ' + (selectedInstitute === 'all' ? 'Department scope' : selectedInstitute);
        return;
    }
    tbody.innerHTML = filtered.map(item => {
        const rowResponseRate = item.required ? Math.round((item.received / item.required) * 100) : 0;
        const normalizedStatus = String(item.status || '').toLowerCase();
        const statusText = (normalizedStatus === 'inactive' || normalizedStatus === 'needs attention')
            ? 'Inactive'
            : 'Active';
        const statusClass = (statusText === 'Inactive')
            ? 'inactive'
            : 'active';
        return '<tr>' +
            '<td>' + item.professorId + '</td>' +
            '<td>' + item.professorName + '</td>' +
            '<td>' + item.institute + '</td>' +
            '<td><span class=\"count-pill\">' + item.received + '/' + item.required + '</span></td>' +
            '<td>' + rowResponseRate + '%</td>' +
            '<td>' + item.avgScore.toFixed(1) + '</td>' +
            '<td>' + formatDisplayDate(item.lastUpdated) + '</td>' +
            '<td><span class=\"dean-status-pill ' + statusClass + '\">' + statusText + '</span></td>' +
            '</tr>';
    }).join('');
    const totalRequired = filtered.reduce((sum, item) => sum + item.required, 0);
    const totalReceived = filtered.reduce((sum, item) => sum + item.received, 0);
    const averageScore = filtered.reduce((sum, item) => sum + item.avgScore, 0) / filtered.length;
    const responseRate = totalRequired ? Math.round((totalReceived / totalRequired) * 100) : 0;
    if (professorCountEl) professorCountEl.textContent = String(filtered.length);
    if (averageScoreEl) averageScoreEl.textContent = averageScore.toFixed(1) + '/5.0';
    if (responseRateEl) responseRateEl.textContent = responseRate + '%';
    if (scopeBadge) scopeBadge.textContent = 'Scope: ' + (selectedInstitute === 'all' ? 'Department scope' : selectedInstitute);
}

/**
 * Setup faculty response rate view search and table rendering
 */
function setupFacultyResponseView() {
    const searchInput = document.getElementById('facultySearchInput');
    const searchBtn = document.getElementById('facultySearchBtn');
    const resetBtn = document.getElementById('facultyResetBtn');
    const semesterFilter = document.getElementById('facultyResponseSemesterFilter');
    const studentResultsBtn = document.getElementById('studentResultsBtn');
    const peerResultsBtn = document.getElementById('peerResultsBtn');
    const supervisorResultsBtn = document.getElementById('supervisorResultsBtn');
    const resultEl = document.getElementById('facultySearchResult');
    const table = document.getElementById('facultyResponseCountsTable');
    const commentsPanel = document.getElementById('facultyCommentsPanel');
    const commentsTitle = document.getElementById('facultyCommentsTitle');
    const commentsMeta = document.getElementById('facultyCommentsMeta');
    const commentsList = document.getElementById('facultyCommentsList');
    const commentsClose = document.getElementById('facultyCommentsClose');

    if (
        !searchInput || !searchBtn || !resetBtn || !semesterFilter
        || !resultEl || !table || !commentsPanel || !commentsTitle || !commentsMeta || !commentsList || !commentsClose
    ) return;

    const session = getUserSession() || {};
    const deanId = session.username || '';
    const assignedInstitutes = getDeanAssignedInstitutes(session);
    let sourceData = [];
    let currentView = 'student';
    let selectedSemesterId = resolveSelectedSemesterId(deanSummaryState.selectedSemesterId);

    function populateSemesterFilter(preferredSemesterId) {
        const context = buildDeanPanelContext();
        const list = Array.isArray(context && context.semesterList) ? context.semesterList : [];
        const fallbackId = resolveSelectedSemesterId(
            preferredSemesterId
            || (context && context.currentSemester)
            || deanSummaryState.selectedSemesterId
        );
        const options = list.length
            ? list
            : (fallbackId ? [{ value: fallbackId, label: getSemesterLabelById(fallbackId) }] : []);

        semesterFilter.innerHTML = '';
        options.forEach(item => {
            const value = String(item && item.value || '').trim();
            if (!value) return;
            const option = document.createElement('option');
            option.value = value;
            option.textContent = String(item && item.label || '').trim() || getSemesterLabelById(value);
            semesterFilter.appendChild(option);
        });

        if (!semesterFilter.options.length) {
            const option = document.createElement('option');
            option.value = '';
            option.textContent = 'No semester available';
            semesterFilter.appendChild(option);
        }

        const preferred = String(preferredSemesterId || fallbackId || '').trim();
        if (preferred && Array.from(semesterFilter.options).some(opt => opt.value === preferred)) {
            semesterFilter.value = preferred;
        } else if (semesterFilter.options.length) {
            semesterFilter.selectedIndex = 0;
        }

        selectedSemesterId = resolveSelectedSemesterId(semesterFilter.value || fallbackId);
        deanSummaryState.selectedSemesterId = selectedSemesterId;
        deanSummaryState.selectedSemesterLabel = getSemesterLabelById(selectedSemesterId);
    }

    function fetchResults(view) {
        const fetcher = view === 'peer'
            ? fetchDeanPeerEvaluationResultsFromSql
            : view === 'supervisor'
                ? fetchDeanSupervisorEvaluationResultsFromSql
                : fetchDeanProfessorResultsFromSql;

        return fetcher({
            deanId,
            assignedInstitutes,
            semesterId: selectedSemesterId
        });
    }

    function applyFilter(items) {
        const keyword = searchInput.value.trim().toLowerCase();
        if (!keyword) {
            return { filtered: items, keyword: '' };
        }

        const filtered = items.filter(item => {
            const facultyName = (item.professorName || '').toLowerCase();
            const employeeId = (item.professorId || '').toLowerCase();
            return facultyName.includes(keyword) || employeeId.includes(keyword);
        });

        return { filtered, keyword };
    }

    function setToggleState(button, isActive) {
        if (!button) return;
        if (isActive) {
            button.classList.add('btn-submit', 'active');
            button.classList.remove('btn-cancel');
            button.setAttribute('aria-pressed', 'true');
        } else {
            button.classList.add('btn-cancel');
            button.classList.remove('btn-submit', 'active');
            button.setAttribute('aria-pressed', 'false');
        }
    }

    function setResultsView(view) {
        currentView = view === 'peer' ? 'peer' : view === 'supervisor' ? 'supervisor' : 'student';
        setToggleState(studentResultsBtn, currentView === 'student');
        setToggleState(peerResultsBtn, currentView === 'peer');
        setToggleState(supervisorResultsBtn, currentView === 'supervisor');

        if (commentsPanel) commentsPanel.classList.remove('active');
        if (commentsList) commentsList.innerHTML = '<li class="faculty-comments-empty">No comments loaded yet.</li>';

        fetchResults(currentView).then(results => {
            sourceData = Array.isArray(results) ? results : [];
            const { filtered, keyword } = applyFilter(sourceData);
            renderFacultyResponseTable(filtered);
            attachFacultyCommentButtons(filtered);
            updateFacultySearchResult(filtered.length, sourceData.length, keyword, currentView, selectedSemesterId);
        }).catch(() => {
            renderFacultyResponseTable([]);
            attachFacultyCommentButtons([]);
            updateFacultySearchResult(0, 0, '', currentView, selectedSemesterId);
        });
    }

    function runSearch() {
        const { filtered, keyword } = applyFilter(sourceData);
        renderFacultyResponseTable(filtered);
        attachFacultyCommentButtons(filtered);
        updateFacultySearchResult(filtered.length, sourceData.length, keyword, currentView, selectedSemesterId);
    }

    searchBtn.addEventListener('click', runSearch);
    resetBtn.addEventListener('click', function () {
        searchInput.value = '';
        renderFacultyResponseTable(sourceData);
        attachFacultyCommentButtons(sourceData);
        updateFacultySearchResult(sourceData.length, sourceData.length, '', currentView, selectedSemesterId);
    });

    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            runSearch();
        }
    });

    commentsClose.addEventListener('click', function () {
        commentsPanel.classList.remove('active');
    });

    semesterFilter.addEventListener('change', function () {
        selectedSemesterId = resolveSelectedSemesterId(semesterFilter.value || selectedSemesterId);
        deanSummaryState.selectedSemesterId = selectedSemesterId;
        deanSummaryState.selectedSemesterLabel = getSemesterLabelById(selectedSemesterId);
        loadFacultySummary({
            semesterId: selectedSemesterId,
            evaluationType: deanSummaryState.selectedEvaluationType || 'student'
        });
        setResultsView(currentView);
    });

    if (studentResultsBtn) {
        studentResultsBtn.addEventListener('click', function () {
            setResultsView('student');
        });
    }

    if (peerResultsBtn) {
        peerResultsBtn.addEventListener('click', function () {
            setResultsView('peer');
        });
    }

    if (supervisorResultsBtn) {
        supervisorResultsBtn.addEventListener('click', function () {
            setResultsView('supervisor');
        });
    }

    function attachFacultyCommentButtons(items) {
        const buttons = table.querySelectorAll('.js-view-comments');
        buttons.forEach(button => {
            button.addEventListener('click', function () {
                const professorId = this.getAttribute('data-professor-id');
                const professor = items.find(item => item.professorId === professorId);
                if (!professor) return;

                commentsTitle.textContent = 'Comments for ' + professor.professorName;
                commentsMeta.textContent = professor.professorId + ' | ' + professor.institute;

                fetchFacultyCommentsFromSql({
                    professorId: professor.professorId,
                    deanId,
                    semesterId: selectedSemesterId,
                    source: currentView
                }).then(comments => {
                    if (!comments.length) {
                        commentsList.innerHTML = '<li class="faculty-comments-empty">No comments available.</li>';
                    } else {
                        commentsList.innerHTML = comments.map(comment =>
                            '<li>' +
                            '<div class="faculty-comment-text">"' + comment.text + '"</div>' +
                            '<div class="faculty-comment-meta">' + comment.source + ' • ' + formatDisplayDate(comment.date) + '</div>' +
                            '</li>'
                        ).join('');
                    }
                    commentsPanel.classList.add('active');
                }).catch(() => {
                    commentsList.innerHTML = '<li class="faculty-comments-empty">Unable to load comments.</li>';
                    commentsPanel.classList.add('active');
                });
            });
        });
    }

    populateSemesterFilter(selectedSemesterId);
    setResultsView(currentView);
}

/**
 * Render faculty response count table rows
 */
function renderFacultyResponseTable(items) {
    const table = document.getElementById('facultyResponseCountsTable');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    if (!items.length) {
        tbody.innerHTML = '<tr><td colspan="10">No faculty records found.</td></tr>';
        return;
    }

    tbody.innerHTML = items.map(item => {
        const responseRate = item.required ? Math.round((item.received / item.required) * 100) : 0;
        const normalizedStatus = String(item.status || '').toLowerCase();
        const statusText = (normalizedStatus === 'inactive' || normalizedStatus === 'needs attention')
            ? 'Inactive'
            : 'Active';
        const statusClass = (statusText === 'Inactive')
            ? 'inactive'
            : 'active';
        const employmentType = item.employmentType || '-';
        const position = item.position || '-';
        return '<tr>' +
            '<td>' + item.professorId + '</td>' +
            '<td>' + item.professorName + '</td>' +
            '<td>' + item.institute + '</td>' +
            '<td>' + employmentType + '</td>' +
            '<td>' + position + '</td>' +
            '<td><span class="count-pill">' + item.received + '/' + item.required + '</span></td>' +
            '<td>' + responseRate + '%</td>' +
            '<td>' + item.avgScore.toFixed(1) + '</td>' +
            '<td><span class="dean-status-pill ' + statusClass + '">' + statusText + '</span></td>' +
            '<td><button type="button" class="btn-submit faculty-comments-btn js-view-comments" data-professor-id="' + item.professorId + '">View</button></td>' +
            '</tr>';
    }).join('');
}

/**
 * SQL-ready placeholder for faculty comments
 */
function fetchFacultyCommentsFromSql(query) {
    const sourceType = query && query.source === 'peer'
        ? 'professor'
        : query && query.source === 'supervisor'
            ? 'supervisor'
            : 'student';
    const summary = getDeanSummaryForType(sourceType);
    const professorToken = normalizeRoleToken(query && query.professorId);
    const row = (summary.breakdownRows || []).find(item =>
        normalizeRoleToken(item && item.professorId) === professorToken
        || normalizeRoleToken(item && item.employeeId) === professorToken
    );
    if (row && row.rowKey && summary.commentBuckets && Array.isArray(summary.commentBuckets[row.rowKey])) {
        return Promise.resolve(summary.commentBuckets[row.rowKey]);
    }

    const context = buildDeanPanelContext();
    const semesterId = resolveSelectedSemesterId(query && query.semesterId || deanSummaryState.selectedSemesterId || context.currentSemester);
    const professorByEmployee = (context.scopedProfessors || []).find(professor =>
        normalizeRoleToken(professor && professor.employeeId) === professorToken
        || normalizeRoleToken(professor && professor.id) === professorToken
    );
    const targetProfessorId = professorByEmployee ? normalizeUserIdToken(professorByEmployee.id) : '';
    if (!targetProfessorId) return Promise.resolve([]);

    const comments = [];
    (context.evaluations || []).forEach(evaluation => {
        if (resolveEvaluationTypeToken(evaluation) !== sourceType) return;
        if (!isEvaluationInSemester(evaluation, semesterId)) return;
        if (resolveDeanTargetProfessorId(evaluation, sourceType, context) !== targetProfessorId) return;
        collectEvaluationComments(evaluation).forEach(text => {
            comments.push({
                text,
                source: getDeanEvaluationTypeMeta(sourceType).label,
                date: String(evaluation && (evaluation.submittedAt || evaluation.timestamp) || '').trim()
            });
        });
    });
    return Promise.resolve(comments);
}

/**
 * Show current search scope and result count
 */
function updateFacultySearchResult(count, total, keyword, view, semesterId) {
    const resultEl = document.getElementById('facultySearchResult');
    if (!resultEl) return;

    const label = view === 'peer'
        ? 'Peer Evaluation Results'
        : view === 'supervisor'
            ? 'Supervisor Evaluation Results'
            : 'Student Evaluation Results';
    const semesterLabel = getSemesterLabelById(semesterId || deanSummaryState.selectedSemesterId);

    if (!keyword) {
        resultEl.textContent = 'Showing ' + label + ' under your department scope for ' + semesterLabel + '. Total: ' + total;
        return;
    }

    resultEl.textContent = 'Found ' + count + ' of ' + total + ' faculty record(s) for "' + keyword + '" in ' + label + ' for ' + semesterLabel + '.';
}

/**
 * SQL-ready placeholder for peer evaluation results
 */
function fetchDeanPeerEvaluationResultsFromSql(query) {
    return Promise.resolve(buildDeanProfessorResultRows(query, 'professor'));
}

/**
 * SQL-ready placeholder for supervisor evaluation results (single record)
 */
function fetchDeanSupervisorEvaluationResultsFromSql(query) {
    return Promise.resolve(buildDeanProfessorResultRows(query, 'supervisor'));
}

/**
 * Setup faculty peer-to-peer room management
 * Rule: auto-generation is dean-scoped and current-semester only.
 */
function setupPeerManagementView() {
    const programSelect = document.getElementById('peerMgmtProgramSelect');
    const professorCountInput = document.getElementById('peerMgmtProfessorCount');
    const roomNameInput = document.getElementById('peerMgmtRoomName');
    const createRoomBtn = document.getElementById('peerMgmtCreateRoomBtn');
    const clearSelectionBtn = document.getElementById('peerMgmtClearSelectionBtn');
    const messageEl = document.getElementById('peerMgmtMessage');
    const roomsTable = document.getElementById('peerMgmtRoomsTable');
    const roomSelect = document.getElementById('peerMgmtRoomSelect');
    const roomDisplay = document.getElementById('peerMgmtRoomDisplay');
    const manualProfessorSelect = document.getElementById('peerMgmtManualProfessorSelect');
    const addProfessorBtn = document.getElementById('peerMgmtAddProfessorBtn');
    const viewMembersBtn = document.getElementById('peerMgmtViewMembersBtn');
    const dismantleRoomBtn = document.getElementById('peerMgmtDismantleRoomBtn');
    const membersTable = document.getElementById('peerMgmtRoomMembersTable');

    if (
        !programSelect || !professorCountInput || !roomNameInput ||
        !createRoomBtn || !clearSelectionBtn || !messageEl || !roomsTable ||
        !roomSelect || !roomDisplay || !manualProfessorSelect || !addProfessorBtn ||
        !viewMembersBtn || !dismantleRoomBtn || !membersTable
    ) {
        return;
    }

    const scopedDepartment = getScopedDeanDepartment();
    let scopedPrograms = [];
    let roomRowsCache = [];

    function setMessage(text, type) {
        messageEl.textContent = text;
        messageEl.classList.remove('success', 'error', 'info');
        messageEl.classList.add(type || 'info');
    }

    function formatDateTime(value) {
        const raw = String(value || '').trim();
        if (!raw) return 'N/A';
        const dt = new Date(raw);
        if (Number.isNaN(dt.getTime())) return raw;
        return dt.toLocaleString();
    }

    function normalizeRoomId(value) {
        const parsed = parseInt(String(value || '').trim(), 10);
        return Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
    }

    function getSelectedRoomId() {
        return normalizeRoomId(roomSelect.value || '');
    }

    function setSelectedRoom(roomId) {
        const normalizedId = normalizeRoomId(roomId);
        if (normalizedId <= 0) {
            roomSelect.value = '';
            roomDisplay.value = Array.isArray(roomRowsCache) && roomRowsCache.length
                ? 'Select a room from Existing Rooms actions'
                : 'No rooms available';
            return 0;
        }

        roomSelect.value = String(normalizedId);
        const roomEntry = roomRowsCache.find(room => normalizeRoomId(room && room.id) === normalizedId);
        roomDisplay.value = roomEntry && roomEntry.roomName
            ? String(roomEntry.roomName)
            : ('Room #' + String(normalizedId));
        return normalizedId;
    }

    function renderMembersTable(payload) {
        const tbody = membersTable.querySelector('tbody');
        if (!tbody) return;

        const rows = Array.isArray(payload && payload.members) ? payload.members : [];
        const roomId = normalizeRoomId(payload && payload.room && payload.room.id);
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="4">No members found for this room.</td></tr>';
            return;
        }

        tbody.innerHTML = rows.map(member => {
            const memberName = String(member && member.name || 'Professor');
            const memberNameEncoded = encodeURIComponent(memberName);
            return (
            '<tr>' +
            '<td>' + escapeHTML(member.name || 'N/A') + '</td>' +
            '<td>' + escapeHTML(member.email || 'N/A') + '</td>' +
            '<td>' + escapeHTML(member.employeeId || 'N/A') + '</td>' +
            '<td>' +
            '<div class="peer-mgmt-member-actions">' +
            '<button type="button" class="btn-cancel peer-mgmt-member-remove-btn" data-room-id="' + String(roomId || 0) + '" data-user-id="' + escapeHTML(String(member.userId || '')) + '" data-member-name="' + memberNameEncoded + '">Remove</button>' +
            '</div>' +
            '</td>' +
            '</tr>'
            );
        }).join('');
    }

    function renderRoomSelectOptions(preferredRoomId) {
        const selectedCandidate = normalizeRoomId(preferredRoomId);
        const hasRooms = Array.isArray(roomRowsCache) && roomRowsCache.length > 0;

        if (!hasRooms) {
            setSelectedRoom(0);
            manualProfessorSelect.innerHTML = '<option value="">No eligible professors</option>';
            addProfessorBtn.disabled = true;
            viewMembersBtn.disabled = true;
            dismantleRoomBtn.disabled = true;
            renderMembersTable({ members: [] });
            return;
        }

        const hasPreferred = selectedCandidate > 0
            && roomRowsCache.some(room => normalizeRoomId(room && room.id) === selectedCandidate);
        const currentSelected = getSelectedRoomId();
        const hasCurrent = currentSelected > 0
            && roomRowsCache.some(room => normalizeRoomId(room && room.id) === currentSelected);
        const fallbackRoomId = normalizeRoomId(roomRowsCache[0] && roomRowsCache[0].id);
        const finalRoomId = hasPreferred
            ? selectedCandidate
            : (hasCurrent ? currentSelected : fallbackRoomId);

        setSelectedRoom(finalRoomId);

        addProfessorBtn.disabled = false;
        viewMembersBtn.disabled = false;
        dismantleRoomBtn.disabled = false;
    }

    function renderEligibleProfessors(payload) {
        const rows = Array.isArray(payload && payload.professors) ? payload.professors : [];
        if (!rows.length) {
            manualProfessorSelect.innerHTML = '<option value="">No eligible professors available</option>';
            return;
        }

        manualProfessorSelect.innerHTML = '<option value="">Select professor to add</option>' +
            rows.map(item =>
                `<option value="${escapeHTML(String(item.userId || ''))}">${escapeHTML(String(item.name || 'Professor'))} (${escapeHTML(String(item.employeeId || 'N/A'))})</option>`
            ).join('');
    }

    function renderPrograms() {
        const allPrograms = (SharedData.getPrograms && SharedData.getPrograms()) || [];
        scopedPrograms = (Array.isArray(allPrograms) ? allPrograms : [])
            .filter(program => {
                const dept = String(program && program.departmentCode || '').trim().toUpperCase();
                return !!scopedDepartment && dept === scopedDepartment;
            })
            .sort((a, b) => String(a && a.programCode || '').localeCompare(String(b && b.programCode || '')));

        if (!scopedPrograms.length) {
            programSelect.innerHTML = '<option value="">No programs available in your scope</option>';
            return;
        }

        programSelect.innerHTML = '<option value="">Select program</option>' +
            scopedPrograms.map(program =>
                `<option value="${escapeHTML(String(program.programCode || ''))}">${escapeHTML(String(program.programCode || ''))} - ${escapeHTML(String(program.programName || ''))}</option>`
            ).join('');
    }

    function renderRoomsTable(payload) {
        const tbody = roomsTable.querySelector('tbody');
        if (!tbody) return;

        const rows = Array.isArray(payload && payload.rooms) ? payload.rooms : [];
        roomRowsCache = rows.slice();
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="6">No rooms generated for the current semester.</td></tr>';
            renderRoomSelectOptions(0);
            return;
        }

        tbody.innerHTML = rows.map(room =>
            '<tr>' +
            '<td>' + escapeHTML(room.roomName || 'N/A') + '</td>' +
            '<td>' + escapeHTML((room.programCode || 'N/A') + (room.programName ? (' - ' + room.programName) : '')) + '</td>' +
            '<td>' + String(room.memberCount || 0) + '</td>' +
            '<td>' + String(room.submittedAssignments || 0) + '/' + String(room.totalAssignments || 0) + ' submitted</td>' +
            '<td>' + escapeHTML(formatDateTime(room.createdAt)) + '</td>' +
            '<td>' +
            '<div class="peer-mgmt-row-actions">' +
            '<button type="button" class="btn-cancel peer-mgmt-room-view-btn" data-room-id="' + String(Number(room.id) || 0) + '">View</button>' +
            '<button type="button" class="btn-cancel peer-mgmt-room-dismantle-btn" data-room-id="' + String(Number(room.id) || 0) + '">Dismantle</button>' +
            '</div>' +
            '</td>' +
            '</tr>'
        ).join('');

        renderRoomSelectOptions(getSelectedRoomId());
    }

    function loadEligibleProfessors(roomId, options = {}) {
        const selectedRoomId = normalizeRoomId(roomId);
        if (selectedRoomId <= 0) {
            manualProfessorSelect.innerHTML = '<option value="">Select a room first</option>';
            return;
        }
        try {
            const response = SharedData.listDeanPeerRoomEligibleProfessorsCurrent({ roomId: selectedRoomId }, selectedRoomId);
            renderEligibleProfessors(response || {});
            if (!options || !options.silent) {
                setMessage('Eligible professors loaded for selected room.', 'info');
            }
        } catch (error) {
            manualProfessorSelect.innerHTML = '<option value="">Unable to load eligible professors</option>';
            if (!options || !options.silent) {
                setMessage(String(error && error.message || 'Unable to load eligible professors.'), 'error');
            }
        }
    }

    function loadRoomMembers(roomId, options = {}) {
        const selectedRoomId = normalizeRoomId(roomId);
        if (selectedRoomId <= 0) {
            renderMembersTable({ members: [] });
            return;
        }
        try {
            const response = SharedData.listDeanPeerRoomMembersCurrent({ roomId: selectedRoomId }, selectedRoomId);
            renderMembersTable(response || {});
            if (!options || !options.silent) {
                const roomName = String(response && response.room && response.room.roomName || '').trim();
                if (roomName) {
                    setMessage('Loaded members for room: ' + roomName + '.', 'info');
                } else {
                    setMessage('Loaded room members.', 'info');
                }
            }
        } catch (error) {
            renderMembersTable({ members: [] });
            if (!options || !options.silent) {
                setMessage(String(error && error.message || 'Unable to load room members.'), 'error');
            }
        }
    }

    function dismantleRoom(roomId) {
        const selectedRoomId = normalizeRoomId(roomId);
        if (selectedRoomId <= 0) {
            setMessage('Select a room first.', 'error');
            return;
        }

        const roomEntry = roomRowsCache.find(room => normalizeRoomId(room && room.id) === selectedRoomId);
        const roomLabel = roomEntry && roomEntry.roomName ? roomEntry.roomName : ('Room #' + selectedRoomId);
        const approved = window.confirm('Dismantle "' + roomLabel + '"? This removes room members and assignments for this room.');
        if (!approved) {
            return;
        }

        dismantleRoomBtn.disabled = true;
        try {
            const response = SharedData.dismantleDeanPeerRoom({ roomId: selectedRoomId });
            const info = response && response.dismantledRoom ? response.dismantledRoom : {};
            setMessage(
                'Room dismantled: ' + String(info.roomName || roomLabel) + ' (' +
                String(info.memberCount || 0) + ' members, ' +
                String(info.assignmentCount || 0) + ' assignments).',
                'success'
            );
            renderMembersTable({ members: [] });
            loadRooms({ silent: true });
        } catch (error) {
            setMessage(String(error && error.message || 'Failed to dismantle room.'), 'error');
        } finally {
            dismantleRoomBtn.disabled = false;
        }
    }

    function removeRoomMember(roomId, professorUserId, memberName) {
        const selectedRoomId = normalizeRoomId(roomId);
        const professorToken = String(professorUserId || '').trim();
        if (selectedRoomId <= 0 || !professorToken) {
            setMessage('Unable to remove room member due to invalid selection.', 'error');
            return;
        }

        const name = String(memberName || 'this professor').trim();
        const approved = window.confirm('Remove "' + name + '" from this room? Their room assignments in this room will also be removed.');
        if (!approved) {
            return;
        }

        try {
            const response = SharedData.removeDeanPeerRoomMember({
                roomId: selectedRoomId,
                professorUserId: professorToken
            });
            const removedMember = response && response.removedMember ? response.removedMember : null;
            setMessage(
                'Removed ' + String(removedMember && removedMember.name || name)
                + '. Deleted assignments: ' + String(response && response.deletedAssignmentCount || 0) + '.',
                'success'
            );
            loadRooms({ silent: true, preferredRoomId: selectedRoomId });
            loadRoomMembers(selectedRoomId, { silent: true });
        } catch (error) {
            setMessage(String(error && error.message || 'Failed to remove professor from room.'), 'error');
        }
    }

    function loadRooms(options = {}) {
        const silent = !!(options && options.silent);
        const preferredRoomId = normalizeRoomId(options && options.preferredRoomId);
        try {
            const response = SharedData.listDeanPeerRoomsCurrent({});
            renderRoomsTable(response || {});
            const selectedRoomId = preferredRoomId > 0 ? preferredRoomId : getSelectedRoomId();
            if (selectedRoomId > 0) {
                loadEligibleProfessors(selectedRoomId, { silent: true });
            } else {
                manualProfessorSelect.innerHTML = '<option value="">Select a room first</option>';
            }
            if (!silent) {
                const semesterLabel = String(response && response.currentSemester || '').trim();
                if (semesterLabel) {
                    setMessage('Loaded current semester rooms: ' + semesterLabel + '.', 'info');
                } else {
                    setMessage('No current semester is configured yet.', 'error');
                }
            }
        } catch (error) {
            renderRoomsTable({ rooms: [] });
            if (!silent) {
                setMessage(String(error && error.message || 'Unable to load peer rooms.'), 'error');
            }
        }
    }

    function clearInputs() {
        roomNameInput.value = '';
        if (scopedPrograms.length > 0) {
            programSelect.value = '';
        }
        professorCountInput.value = '5';
    }

    createRoomBtn.addEventListener('click', function () {
        const programCode = String(programSelect.value || '').trim().toUpperCase();
        const professorCount = parseInt(String(professorCountInput.value || '').trim(), 10);
        const roomName = String(roomNameInput.value || '').trim();

        if (!programCode) {
            setMessage('Program selection is required.', 'error');
            return;
        }
        if (!Number.isFinite(professorCount) || professorCount < 2) {
            setMessage('Professor count must be at least 2.', 'error');
            return;
        }

        createRoomBtn.disabled = true;
        try {
            const response = SharedData.autoGeneratePeerRoom({
                programCode,
                professorCount,
                roomName
            });
            const summary = response && response.summary ? response.summary : null;
            const generatedRooms = Array.isArray(response && response.rooms) ? response.rooms : [];
            const generatedRoom = response && response.room
                ? response.room
                : (generatedRooms.length ? generatedRooms[0] : null);

            if (summary) {
                setMessage(
                    'Generated ' + String(summary.roomCount || 0) + ' room(s) for '
                    + String(summary.totalEligibleUsed || 0) + ' professors ('
                    + String(summary.totalAssignments || 0) + ' assignments).',
                    'success'
                );
            } else if (generatedRoom) {
                setMessage(
                    'Room generated: ' + (generatedRoom.roomName || 'N/A')
                    + ' (' + String(generatedRoom.memberCount || 0) + ' professors, '
                    + String(generatedRoom.assignmentCount || 0) + ' assignments).',
                    'success'
                );
            } else {
                setMessage('Room generated successfully.', 'success');
            }
            clearInputs();
            loadRooms({ silent: true, preferredRoomId: Number(generatedRoom && generatedRoom.id) || 0 });
        } catch (error) {
            setMessage(String(error && error.message || 'Failed to generate room.'), 'error');
        } finally {
            createRoomBtn.disabled = false;
        }
    });

    clearSelectionBtn.addEventListener('click', function () {
        clearInputs();
        setMessage('', 'info');
    });

    addProfessorBtn.addEventListener('click', function () {
        const selectedRoomId = getSelectedRoomId();
        const professorUserId = String(manualProfessorSelect.value || '').trim();
        if (selectedRoomId <= 0) {
            setMessage('Select a room first.', 'error');
            return;
        }
        if (!professorUserId) {
            setMessage('Select a professor to add.', 'error');
            return;
        }

        addProfessorBtn.disabled = true;
        try {
            const response = SharedData.addDeanPeerRoomMembers({
                roomId: selectedRoomId,
                professorUserIds: [professorUserId]
            });
            const addedMembers = Array.isArray(response && response.addedMembers) ? response.addedMembers : [];
            setMessage(
                'Added ' + String(addedMembers.length) + ' professor(s). New assignments: ' + String(response && response.assignmentAddedCount || 0) + '.',
                'success'
            );
            loadRooms({ silent: true, preferredRoomId: selectedRoomId });
            loadRoomMembers(selectedRoomId, { silent: true });
        } catch (error) {
            setMessage(String(error && error.message || 'Failed to add professor to room.'), 'error');
        } finally {
            addProfessorBtn.disabled = false;
        }
    });

    viewMembersBtn.addEventListener('click', function () {
        const selectedRoomId = getSelectedRoomId();
        if (selectedRoomId <= 0) {
            setMessage('Select a room first.', 'error');
            return;
        }
        loadRoomMembers(selectedRoomId);
    });

    dismantleRoomBtn.addEventListener('click', function () {
        const selectedRoomId = getSelectedRoomId();
        dismantleRoom(selectedRoomId);
    });

    roomsTable.addEventListener('click', function (event) {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        const viewBtn = target.closest('.peer-mgmt-room-view-btn');
        if (viewBtn) {
            const roomId = normalizeRoomId(viewBtn.getAttribute('data-room-id'));
            if (roomId > 0) {
                setSelectedRoom(roomId);
                loadEligibleProfessors(roomId, { silent: true });
                loadRoomMembers(roomId);
            }
            return;
        }

        const dismantleBtn = target.closest('.peer-mgmt-room-dismantle-btn');
        if (dismantleBtn) {
            const roomId = normalizeRoomId(dismantleBtn.getAttribute('data-room-id'));
            dismantleRoom(roomId);
        }
    });

    membersTable.addEventListener('click', function (event) {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        const removeBtn = target.closest('.peer-mgmt-member-remove-btn');
        if (!removeBtn) return;

        const roomId = normalizeRoomId(removeBtn.getAttribute('data-room-id'));
        const professorUserId = String(removeBtn.getAttribute('data-user-id') || '').trim();
        const memberName = decodeURIComponent(String(removeBtn.getAttribute('data-member-name') || '').trim());

        removeBtn.disabled = true;
        try {
            removeRoomMember(roomId, professorUserId, memberName);
        } finally {
            removeBtn.disabled = false;
        }
    });

    renderPrograms();
    loadRooms();
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
        handleViewDetails,
        updateSummaryCards
    };
}
