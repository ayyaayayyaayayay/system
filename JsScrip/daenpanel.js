// Dean Panel JavaScript - Dashboard Functionality

let deanProfessorCount = 0;
let deanFacultyPaperState = {
    actorUserId: '',
    papers: [],
    selectedId: '',
};

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function () {
    applySupervisorPanelCopy();
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
let deanFacultyFeedbackState = {
    selectedProfessorUserId: '',
    selectedProfessorId: '',
    selectedProfessorName: '',
    selectedSourceView: 'student',
    loadedComments: [],
    lastTrendRows: []
};
let deanIferDirectoryState = {
    records: [],
    query: '',
    templateName: 'ifer.docx',
    semesterId: '',
    initialized: false
};
let deanSupervisorTargetDirectory = [];
let deanMobileDrawerBound = false;
let deanViewportRefreshBound = false;
let deanViewportRefreshTimer = 0;
const SUPERVISOR_PANEL_CONFIG = (() => {
    const cfg = window.NAAP_SUPERVISOR_PANEL || {};
    const role = String(cfg.role || 'dean').trim().toLowerCase() === 'procoor' ? 'procoor' : 'dean';
    return Object.assign({
        role,
        label: role === 'procoor' ? 'Program Coordinator' : 'Dean',
        dashboardTitle: role === 'procoor' ? 'Program Coordinator Dashboard' : 'Dean Dashboard',
        scopeLabel: role === 'procoor' ? 'program' : 'department',
        scopeDescriptor: role === 'procoor' ? 'program scope' : 'department scope',
        peerListMethod: role === 'procoor' ? 'listCoordinatorProgramPeerAssignmentsCurrent' : 'listDeanProgramPeerAssignmentsCurrent',
        peerDetailsMethod: role === 'procoor' ? 'listCoordinatorProgramPeerAssignmentDetailsCurrent' : 'listDeanProgramPeerAssignmentDetailsCurrent',
        peerGenerateMethod: role === 'procoor' ? 'generateCoordinatorProgramPeerAssignments' : 'generateDeanProgramPeerAssignments'
    }, cfg, { role });
})();
const SUPERVISOR_ROLE = SUPERVISOR_PANEL_CONFIG.role;

const DEAN_EMPTY_SUMMARY = {
    criteriaAverages: [],
    breakdownRows: [],
    subjects: [],
    ratingDistribution: { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 },
    ratingDistributionAverage: 0,
    comments: [],
    commentBuckets: {},
    detailedRows: [],
    totals: { required: 0, received: 0, responseRate: 0, averageScore: 0 }
};

function isDeanDashboardViewActive() {
    const view = document.getElementById('dashboardView');
    return !!(view && view.style.display !== 'none');
}

function isDeanFacultyResponseViewActive() {
    const view = document.getElementById('facultyResponseView');
    return !!(view && view.style.display !== 'none');
}

function refreshDeanViewportLayout() {
    if (isDeanDashboardViewActive()) {
        initializeReports();
    }

    if (isDeanFacultyResponseViewActive()) {
        const trendSection = document.getElementById('deanSemestralTrendSection');
        const isTrendVisible = !!(trendSection && trendSection.style.display !== 'none');
        if (isTrendVisible) {
            renderDeanSemestralTrendChart(deanFacultyFeedbackState.lastTrendRows || []);
        }
    }
}

function scheduleDeanViewportRefresh(delayMs) {
    if (deanViewportRefreshTimer) {
        window.clearTimeout(deanViewportRefreshTimer);
    }

    const wait = Number.isFinite(delayMs) ? delayMs : 180;
    deanViewportRefreshTimer = window.setTimeout(function () {
        deanViewportRefreshTimer = 0;

        const runRefresh = function () {
            refreshDeanViewportLayout();
        };

        if (typeof window.requestAnimationFrame === 'function') {
            window.requestAnimationFrame(function () {
                window.requestAnimationFrame(runRefresh);
            });
            return;
        }

        runRefresh();
    }, wait);
}

function setupDeanViewportRefresh() {
    if (deanViewportRefreshBound) return;

    const handleViewportChange = function () {
        scheduleDeanViewportRefresh(180);
    };

    window.addEventListener('resize', handleViewportChange);
    window.addEventListener('orientationchange', handleViewportChange);
    deanViewportRefreshBound = true;
}

function normalizeUserIdToken(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^u\d+$/i.test(raw)) return 'u' + raw.replace(/^u/i, '');
    if (/^\d+$/.test(raw)) return 'u' + String(parseInt(raw, 10));
    return normalizeRoleToken(raw);
}

function normalizeRoleToken(value) {
    return String(value || '').trim().toLowerCase();
}

function isProgramScopedSupervisorPanel() {
    return SUPERVISOR_ROLE === 'procoor';
}

function shouldHideStudentCommentIdentity() {
    return isProgramScopedSupervisorPanel();
}

function getSupervisorLabel() {
    return String(SUPERVISOR_PANEL_CONFIG.label || (isProgramScopedSupervisorPanel() ? 'Program Coordinator' : 'Dean'));
}

function getSupervisorScopeDescriptor() {
    return String(SUPERVISOR_PANEL_CONFIG.scopeDescriptor || (isProgramScopedSupervisorPanel() ? 'program scope' : 'department scope'));
}

function getSupervisorAnonymousLabel() {
    return `Anonymous ${getSupervisorLabel()}`;
}

function getSupervisorPeerMethodName(kind) {
    if (kind === 'list') return SUPERVISOR_PANEL_CONFIG.peerListMethod;
    if (kind === 'details') return SUPERVISOR_PANEL_CONFIG.peerDetailsMethod;
    if (kind === 'generate') return SUPERVISOR_PANEL_CONFIG.peerGenerateMethod;
    return '';
}

function applySupervisorPanelCopy() {
    document.title = String(SUPERVISOR_PANEL_CONFIG.dashboardTitle || document.title);
    document.body.classList.add('dean-panel');
    document.body.classList.toggle('procoor-panel', isProgramScopedSupervisorPanel());
    if (!isProgramScopedSupervisorPanel()) return;

    const replacements = [
        ['Dean Dashboard', 'Program Coordinator Dashboard'],
        ['Dean Profile', 'Program Coordinator Profile'],
        ['Dean workspace', 'Program coordinator workspace'],
        ['Dean User', 'Program Coordinator User'],
        ['Latest updates for deans', 'Latest updates for program coordinators'],
        ['Review department-wide results, manage peer assignments, and process faculty papers.', 'Review program-specific results, manage peer assignments, and process faculty papers.'],
        ['Section C (Editable by Professor and Dean)', 'Section C (Editable by Professor and Program Coordinator)']
    ];
    const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
    while (walker.nextNode()) {
        const node = walker.currentNode;
        let nextText = String(node.textContent || '');
        replacements.forEach(([from, to]) => {
            nextText = nextText.replaceAll(from, to);
        });
        if (nextText !== node.textContent) {
            node.textContent = nextText;
        }
    }
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

function getScopedDeanProgramCode() {
    const supervisor = resolveCurrentDeanUserAnyStatus(getUserSession() || {});
    return String((supervisor && supervisor.programCode) || '').trim().toUpperCase();
}

function getSupervisorProgramLeadLabel(supervisor) {
    const programCode = String((supervisor && supervisor.programCode) || '').trim().toUpperCase();
    if (!programCode) return 'N/A';

    const programs = (SharedData.getPrograms && SharedData.getPrograms()) || [];
    const matchedProgram = (Array.isArray(programs) ? programs : []).find(program =>
        String((program && (program.programCode || program.program_code)) || '').trim().toUpperCase() === programCode
    );
    const programName = String(
        (matchedProgram && (matchedProgram.programName || matchedProgram.program_name))
        || (supervisor && (supervisor.programName || supervisor.program))
        || ''
    ).trim();

    return programName ? `${programCode} - ${programName}` : programCode;
}

function isProfessorWithinSupervisorScope(user, scopedDepartment, scopedProgramCode) {
    if (normalizeRoleToken(user && user.role) !== 'professor') return false;
    const department = String((user && (user.department || user.institute)) || '').trim().toUpperCase();
    if (!department || !scopedDepartment || department !== scopedDepartment) return false;
    if (isProgramScopedSupervisorPanel()) {
        const programCode = String(user && user.programCode || '').trim().toUpperCase();
        if (!programCode || !scopedProgramCode || programCode !== scopedProgramCode) return false;
    }
    return true;
}

function isActiveUser(user) {
    return normalizeRoleToken(user && user.status || 'active') !== 'inactive';
}

function getScopedProfessorUsers(includeInactive = false) {
    const users = (SharedData.getUsers && SharedData.getUsers()) || [];
    const scopedDepartment = getScopedDeanDepartment();
    const scopedProgramCode = getScopedDeanProgramCode();
    return (Array.isArray(users) ? users : []).filter(user => {
        if (!isProfessorWithinSupervisorScope(user, scopedDepartment, scopedProgramCode)) return false;
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
    if (token === 'supervisor' || token === 'dean' || token === 'procoor' || token === 'supervisor-to-professor') return 'supervisor';
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

function classifyDeanFeedbackCommentBiasByRules(text) {
    const value = String(text || '').trim().replace(/\s+/g, ' ');
    if (!value) return { label: 'Neutral' };

    const lower = value.toLowerCase();
    const words = lower.split(/\s+/).filter(Boolean);
    const wordCount = words.length;

    const biasedKeywords = [
        'hate', 'terror', 'worst', 'stupid', 'dumb', 'useless', 'bobo', 'idiot', 'trash', 'awful', 'pangit',
        'bwisit', 'gago', 'bobo prof', 'i hate', 'not related', 'irrelevant', 'nonsense',
    ];
    for (let i = 0; i < biasedKeywords.length; i += 1) {
        const keyword = biasedKeywords[i];
        if (keyword && lower.includes(keyword)) {
            return { label: 'Biased' };
        }
    }

    const constructiveKeywords = [
        'needs', 'need', 'should', 'improve', 'improvement', 'examples', 'explain', 'explains',
        'clearer', 'clarify', 'better', 'more', 'less', 'pace', 'feedback',
    ];
    let hasConstructiveSignal = false;
    for (let i = 0; i < constructiveKeywords.length; i += 1) {
        const keyword = constructiveKeywords[i];
        if (keyword && lower.includes(keyword)) {
            hasConstructiveSignal = true;
            break;
        }
    }

    if (hasConstructiveSignal && wordCount >= 4) return { label: 'Constructive' };

    const neutralKeywords = ['ok', 'okay', 'fine', 'good', 'nice', 'average', 'pwede'];
    if (neutralKeywords.includes(lower)) return { label: 'Neutral' };
    if (wordCount <= 3) return { label: 'Neutral' };

    return { label: 'Neutral' };
}

function getDeanCommentBiasTagClass(label) {
    if (label === 'Constructive') return 'constructive';
    if (label === 'Biased') return 'biased';
    return 'neutral';
}

function getSupervisorOpenAiPanelAccess() {
    try {
        if (typeof SharedData !== 'undefined' && typeof SharedData.getOpenAiPanelAccess === 'function') {
            return SharedData.getOpenAiPanelAccess({ role: SUPERVISOR_ROLE });
        }
    } catch (error) {
        console.warn('[SupervisorPanel] Failed to load OpenAI panel access.', error);
    }
    return { role: SUPERVISOR_ROLE, enabled: true };
}

function isSupervisorOpenAiPanelEnabled() {
    const access = getSupervisorOpenAiPanelAccess();
    return !access || access.enabled !== false;
}

function setDeanCommentsAiSummary(message, type = 'info') {
    const summaryEl = document.getElementById('facultyCommentsAiSummary');
    if (!summaryEl) return;

    summaryEl.classList.remove('warning', 'error', 'success', 'is-generated');
    if (type === 'warning' || type === 'error' || type === 'success') {
        summaryEl.classList.add(type);
    }

    if (message && typeof message === 'object') {
        const topics = Array.isArray(message.topics) ? message.topics : [];
        const sourceNote = getDeanFeedbackAiSummarySourceNote(message);
        summaryEl.classList.add('is-generated');
        summaryEl.innerHTML = `
            <div class="faculty-comments-ai-kicker">AI Summary</div>
            <div class="faculty-comments-ai-headline">${escapeHTML(message.summaryLine || '')}</div>
            <div class="faculty-comments-ai-tone">${escapeHTML(message.toneLine || '')}</div>
            <div class="faculty-comments-ai-stats" aria-label="Feedback classification counts">
                <span><strong>${Number(message.total || 0)}</strong> Comments</span>
                <span><strong>${Number(message.constructive || 0)}</strong> Actionable</span>
                <span><strong>${Number(message.neutral || 0)}</strong> General</span>
                <span><strong>${Number(message.biased || 0)}</strong> Needs review</span>
            </div>
            ${topics.length ? `
                <div class="faculty-comments-ai-topics">
                    ${topics.slice(0, 3).map(topic => `<span>${escapeHTML(topic.label)} (${Number(topic.count || 0)})</span>`).join('')}
                </div>
            ` : ''}
            ${sourceNote ? `<div class="faculty-comments-ai-source">${escapeHTML(sourceNote)}</div>` : ''}
        `;
        return;
    }

    summaryEl.textContent = String(message || '').trim();
}

function getDeanFeedbackAiSummarySourceNote(message) {
    const source = normalizeDeanToken(message && message.source);
    const warning = String(message && message.warning || '').trim();
    if (source === 'openai') {
        const model = String(message && (message.aiModel || message.model) || '').trim();
        return model ? `Generated with OpenAI (${model}).` : 'Generated with OpenAI.';
    }
    if (source === 'openai+rule' || source === 'gemini+rule') {
        return warning || 'Generated with OpenAI and completed with rule fallback.';
    }
    if (source === 'rule') {
        return warning || 'Rule-based summary used.';
    }
    return warning;
}

function setDeanCommentsListVisibility(isVisible) {
    const list = document.getElementById('facultyCommentsList');
    if (!list) return;
    list.classList.toggle('summary-hidden', !isVisible);
}

function detectDeanFeedbackTopics(comments) {
    const topicRules = [
        {
            label: 'lack of learning materials',
            keywords: ['material', 'materials', 'module', 'modules', 'learning material', 'handout', 'handouts', 'slides', 'references', 'resources'],
        },
        {
            label: 'need clearer explanations',
            keywords: ['clear', 'clearer', 'clarify', 'explains', 'explain', 'explanation', 'understand'],
        },
        {
            label: 'need more examples',
            keywords: ['example', 'examples', 'sample', 'samples'],
        },
        {
            label: 'class pace is too fast',
            keywords: ['pace', 'fast', 'quick', 'rushed'],
        },
        {
            label: 'want more interactive discussions',
            keywords: ['interactive', 'discussion', 'engaging', 'participate', 'interaction'],
        },
    ];

    const counts = topicRules.map(rule => ({ label: rule.label, count: 0 }));
    comments.forEach(comment => {
        const lower = String(comment || '').toLowerCase();
        topicRules.forEach((rule, index) => {
            const matched = rule.keywords.some(keyword => lower.includes(keyword));
            if (matched) counts[index].count += 1;
        });
    });

    return counts
        .filter(item => item.count > 0)
        .sort((a, b) => b.count - a.count);
}

function buildDeanFeedbackAiSummary(items, options = {}) {
    const comments = (Array.isArray(items) ? items : [])
        .map(item => String(item && item.text || '').trim())
        .filter(Boolean);
    if (!comments.length) {
        return {
            total: 0,
            constructive: 0,
            neutral: 0,
            biased: 0,
            topics: [],
            summaryLine: 'No comments available to summarize.',
            toneLine: '',
            source: 'rule',
            warning: '',
        };
    }

    const evaluationLabel = String(options && options.evaluationLabel || 'evaluation').trim() || 'evaluation';
    const commentLabel = evaluationLabel.toLowerCase().replace(/\s+evaluation$/, '').trim() || 'evaluation';

    let constructive = 0;
    let neutral = 0;
    let biased = 0;
    comments.forEach(text => {
        const label = classifyDeanFeedbackCommentBiasByRules(text).label;
        if (label === 'Constructive') constructive += 1;
        else if (label === 'Biased') biased += 1;
        else neutral += 1;
    });

    const topics = detectDeanFeedbackTopics(comments);
    const top = topics[0] || null;
    const second = topics[1] || null;
    const threshold = Math.ceil(comments.length * 0.4);

    let summaryLine = `Summary of ${comments.length} ${commentLabel} comments: feedback is varied.`;
    if (top && top.count >= threshold) {
        summaryLine = `Summary of ${comments.length} ${commentLabel} comments: majority mention ${top.label}.`;
    } else if (top && second) {
        summaryLine = `Summary of ${comments.length} ${commentLabel} comments: common points are ${top.label} and ${second.label}.`;
    } else if (top) {
        summaryLine = `Summary of ${comments.length} ${commentLabel} comments: a common point is ${top.label}.`;
    }

    let toneLine = 'Overall tone is mostly neutral.';
    if (constructive >= neutral && constructive >= biased) {
        toneLine = 'Overall tone is mostly constructive.';
    } else if (biased > constructive && biased >= neutral) {
        toneLine = 'Overall tone includes notable biased comments.';
    }

    return {
        total: comments.length,
        constructive,
        neutral,
        biased,
        topics,
        summaryLine,
        toneLine,
        source: 'rule',
        warning: '',
    };
}

function buildDeanCommentIdentityFields(evaluation) {
    return {
        evaluatorStudentNumber: String(evaluation && evaluation.evaluatorStudentNumber || '').trim(),
        studentNumber: String(evaluation && evaluation.studentNumber || '').trim(),
        studentId: String(evaluation && evaluation.studentId || '').trim(),
        studentUserId: String(evaluation && evaluation.studentUserId || '').trim(),
        evaluatorId: String(evaluation && evaluation.evaluatorId || '').trim(),
        evaluatorUsername: String(evaluation && evaluation.evaluatorUsername || '').trim(),
        evaluatorName: String(evaluation && evaluation.evaluatorName || '').trim()
    };
}

function buildDeanCommentRecord(evaluation, text, sourceLabel) {
    return {
        text: String(text || '').trim(),
        source: String(sourceLabel || '').trim(),
        date: String(evaluation && (evaluation.submittedAt || evaluation.timestamp) || '').trim(),
        ...buildDeanCommentIdentityFields(evaluation)
    };
}

function resolveDeanCommentStudentNumber(comment) {
    const priority = [
        comment && comment.evaluatorStudentNumber,
        comment && comment.studentNumber,
        comment && comment.studentId,
        comment && comment.studentUserId,
        comment && comment.evaluatorId,
        comment && comment.evaluatorUsername
    ];
    for (let index = 0; index < priority.length; index += 1) {
        const value = String(priority[index] || '').trim();
        if (value) return value;
    }
    return 'N/A';
}

function countRatingEntriesFromEvaluations(evaluations) {
    let count = 0;
    (Array.isArray(evaluations) ? evaluations : []).forEach(item => {
        const ratings = item && typeof item.ratings === 'object' && item.ratings ? item.ratings : {};
        Object.keys(ratings).forEach(questionId => {
            const parsed = parseFloat(ratings[questionId]);
            if (!Number.isFinite(parsed)) return;
            count += 1;
        });
    });
    return count;
}

function getDeanSemestralWindowIds(context, selectedSemesterId, maxCount = 4) {
    const list = Array.isArray(context && context.semesterList) ? context.semesterList : [];
    const ids = list
        .map(item => String(item && item.value || '').trim())
        .filter(Boolean);

    if (!ids.length) {
        const fallback = String(selectedSemesterId || context && context.currentSemester || '').trim();
        return fallback ? [fallback] : [];
    }

    const selected = String(selectedSemesterId || '').trim();
    let index = selected ? ids.indexOf(selected) : -1;
    if (index < 0) index = ids.length - 1;

    const windowIds = [];
    for (let cursor = index; cursor >= 0 && windowIds.length < maxCount; cursor -= 1) {
        windowIds.push(ids[cursor]);
    }
    return windowIds;
}

function fetchDeanProfessorEvaluationsByType(context, professorUserId, evaluationType, semesterId) {
    return (context.evaluations || []).filter(evaluation => {
        if (resolveEvaluationTypeToken(evaluation) !== evaluationType) return false;
        if (!isEvaluationInSemester(evaluation, semesterId)) return false;
        const targetProfessorId = resolveDeanTargetProfessorId(evaluation, evaluationType, context);
        return targetProfessorId === professorUserId;
    });
}

function buildDeanProfessorSemestralTrendRows(professorUserId, selectedSemesterId) {
    const context = buildDeanPanelContext();
    const targetProfessorId = normalizeUserIdToken(professorUserId);
    if (!targetProfessorId || !context.professorById[targetProfessorId]) {
        return [];
    }

    const semesterIds = getDeanSemestralWindowIds(context, selectedSemesterId, 4);
    return semesterIds.map(semesterId => {
        const studentEvaluations = fetchDeanProfessorEvaluationsByType(context, targetProfessorId, 'student', semesterId);
        const peerEvaluations = fetchDeanProfessorEvaluationsByType(context, targetProfessorId, 'professor', semesterId);
        const supervisorEvaluations = fetchDeanProfessorEvaluationsByType(context, targetProfessorId, 'supervisor', semesterId);

        const studentAverage = computeAverageRatingFromEvaluations(studentEvaluations);
        const peerAverage = computeAverageRatingFromEvaluations(peerEvaluations);
        const supervisorAverage = computeAverageRatingFromEvaluations(supervisorEvaluations);

        const studentRatings = countRatingEntriesFromEvaluations(studentEvaluations);
        const peerRatings = countRatingEntriesFromEvaluations(peerEvaluations);
        const supervisorRatings = countRatingEntriesFromEvaluations(supervisorEvaluations);

        const totalRatings = studentRatings + peerRatings + supervisorRatings;
        const weightedSum = (studentAverage * studentRatings)
            + (peerAverage * peerRatings)
            + (supervisorAverage * supervisorRatings);
        const overallAverage = totalRatings ? (weightedSum / totalRatings) : 0;

        return {
            semesterId,
            semesterLabel: getSemesterLabelById(semesterId),
            overallAverage,
            studentAverage,
            peerAverage,
            supervisorAverage
        };
    });
}

function renderDeanSemestralTrendChart(rows) {
    const canvas = document.getElementById('deanSemestralTrendChart');
    if (!canvas) return;

    const chartRows = (Array.isArray(rows) ? rows.slice() : []).reverse();
    const labels = chartRows.length ? chartRows.map(item => item.semesterLabel) : ['No Data'];
    const values = chartRows.length ? chartRows.map(item => Number((item.overallAverage || 0).toFixed(2))) : [0];

    window.deanSemestralTrendChartInstance = window.AppChartDesign.renderLineChart(canvas, {
        labels,
        values,
        label: 'Overall Average',
        lineColor: '#4f46e5',
        fillColor: 'rgba(79, 70, 229, 0.2)',
        maxValue: 5,
        stepSize: 1,
        tooltipDecimals: 2
    });
}

function resetDeanProfessorSemestralTrend() {
    const statusEl = document.getElementById('deanSemestralTrendStatus');
    const deltaEl = document.getElementById('deanSemestralTrendDelta');
    const tableBody = document.getElementById('deanSemestralTrendTableBody');
    if (!statusEl || !deltaEl || !tableBody) return;

    statusEl.classList.remove('improved', 'declined', 'stable');
    statusEl.textContent = 'No semestral trend data available.';
    deltaEl.textContent = 'Open a professor comments list to load the last 4-semester performance trend.';
    tableBody.innerHTML = '<tr class="mobile-card-empty-row"><td colspan="5">No data available.</td></tr>';
    deanFacultyFeedbackState.lastTrendRows = [];
    renderDeanSemestralTrendChart([]);
}

function setDeanSemestralTrendVisibility(isVisible) {
    const section = document.getElementById('deanSemestralTrendSection');
    if (!section) return;
    section.style.display = isVisible ? 'block' : 'none';
}

function renderDeanProfessorSemestralTrend(professorUserId, selectedSemesterId) {
    const statusEl = document.getElementById('deanSemestralTrendStatus');
    const deltaEl = document.getElementById('deanSemestralTrendDelta');
    const tableBody = document.getElementById('deanSemestralTrendTableBody');
    if (!statusEl || !deltaEl || !tableBody) return;

    const rows = buildDeanProfessorSemestralTrendRows(professorUserId, selectedSemesterId);
    statusEl.classList.remove('improved', 'declined', 'stable');

    if (!rows.length) {
        resetDeanProfessorSemestralTrend();
        return;
    }

    tableBody.innerHTML = rows.map(item => `
        <tr>
            <td data-label="Semester">${escapeHTML(item.semesterLabel || item.semesterId)}</td>
            <td data-label="Overall Avg"><span class="avg-score">${Number(item.overallAverage || 0).toFixed(2)}</span></td>
            <td data-label="Student">${Number(item.studentAverage || 0).toFixed(2)}</td>
            <td data-label="Peer">${Number(item.peerAverage || 0).toFixed(2)}</td>
            <td data-label="Supervisor">${Number(item.supervisorAverage || 0).toFixed(2)}</td>
        </tr>
    `).join('');

    const current = Number(rows[0] && rows[0].overallAverage || 0);
    const previous = Number(rows[1] && rows[1].overallAverage || 0);

    if (rows.length < 2 || !Number.isFinite(previous) || previous <= 0) {
        statusEl.textContent = `Current semestral average: ${current.toFixed(2)}`;
        statusEl.classList.add('stable');
        deltaEl.textContent = 'No previous semester baseline yet for improve/decline comparison.';
    } else {
        const delta = current - previous;
        const deltaLabel = `${delta >= 0 ? '+' : ''}${delta.toFixed(2)}`;
        if (delta > 0.02) {
            statusEl.textContent = `Improved performance in ${rows[0].semesterLabel}`;
            statusEl.classList.add('improved');
        } else if (delta < -0.02) {
            statusEl.textContent = `Declined performance in ${rows[0].semesterLabel}`;
            statusEl.classList.add('declined');
        } else {
            statusEl.textContent = `Stable performance in ${rows[0].semesterLabel}`;
            statusEl.classList.add('stable');
        }
        deltaEl.textContent = `Current vs previous semester: ${current.toFixed(2)} vs ${previous.toFixed(2)} (${deltaLabel}).`;
    }

    deanFacultyFeedbackState.lastTrendRows = rows;
    renderDeanSemestralTrendChart(rows);
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

function computeDistributionAverage(distribution) {
    let weighted = 0;
    let total = 0;
    Object.keys(distribution || {}).forEach(key => {
        const rating = Number(key);
        const count = Number(distribution[key] || 0);
        if (!Number.isFinite(rating) || !Number.isFinite(count)) return;
        weighted += rating * count;
        total += count;
    });
    return total ? (weighted / total) : 0;
}

function formatDisplaySection(sectionName) {
    const value = String(sectionName || '').trim();
    if (!value) return '';
    if (/^\d+\-\d+$/.test(value)) return value.replace('-', '/');
    return value;
}

function getActiveSupervisorCount() {
    const users = (SharedData.getUsers && SharedData.getUsers()) || [];
    const supervisorRoles = new Set(['dean', 'procoor', 'hr', 'vpaa', 'admin']);
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
    if (token === 'supervisor' || token === 'dean' || token === 'procoor') {
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
    const scopedProgramCode = String((deanUser && deanUser.programCode) || '').trim().toUpperCase();

    const scopedProfessors = (Array.isArray(users) ? users : []).filter(user => {
        if (!isActiveUser(user)) return false;
        return isProfessorWithinSupervisorScope(user, scopedDepartment, scopedProgramCode);
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
        scopedProgramCode,
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
    let ratingDistributionAverage = 0;
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
            if (evaluationType !== 'student') {
                ratingDistribution[rounded] += 1;
            }

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
            comments.push(buildDeanCommentRecord(
                evaluation,
                text,
                getDeanEvaluationTypeMeta(evaluationType).label
            ));
        });
    });

    if (evaluationType === 'student') {
        let evaluationAverageTotal = 0;
        let evaluationAverageCount = 0;
        matchedEvaluations.forEach(evaluation => {
            const evaluationAverage = computeAverageRatingFromEvaluations([evaluation]);
            if (!evaluationAverage) return;
            evaluationAverageTotal += evaluationAverage;
            evaluationAverageCount += 1;
            const bucket = Math.max(1, Math.min(5, Math.round(evaluationAverage)));
            ratingDistribution[bucket] += 1;
        });
        ratingDistributionAverage = evaluationAverageCount ? (evaluationAverageTotal / evaluationAverageCount) : 0;
    } else {
        ratingDistributionAverage = computeDistributionAverage(ratingDistribution);
    }

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
                collectEvaluationComments(evaluation).map(text =>
                    buildDeanCommentRecord(evaluation, text, 'Student Evaluation')
                )
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
                collectEvaluationComments(evaluation).map(text =>
                    buildDeanCommentRecord(evaluation, text, getDeanEvaluationTypeMeta(evaluationType).label)
                )
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
        ratingDistributionAverage,
        averageRating: averageScore,
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
    const session = SharedData.requireSession(SUPERVISOR_ROLE);
    if (!session) {
        return false;
    }

    try {
        return session.isAuthenticated === true
            && session.role === SUPERVISOR_ROLE
            && normalizeDeanToken(session.status || 'active') !== 'inactive';
    } catch (e) {
        return false;
    }
}

function resolveCurrentDeanUserAnyStatus(sessionInput) {
    const session = sessionInput || getUserSession() || {};
    const users = (typeof SharedData !== 'undefined' && SharedData.getUsers) ? SharedData.getUsers() : [];
    const supervisors = (Array.isArray(users) ? users : []).filter(user => normalizeDeanToken(user && user.role) === SUPERVISOR_ROLE);
    if (!supervisors.length) return null;

    const sessionUserId = normalizeDeanUserIdToken(session && session.userId);
    if (sessionUserId) {
        const byId = supervisors.find(user => normalizeDeanUserIdToken(user && user.id) === sessionUserId);
        if (byId) return byId;
    }

    const sessionEmail = normalizeDeanToken(session && session.email);
    if (sessionEmail) {
        const byEmail = supervisors.find(user => normalizeDeanToken(user && user.email) === sessionEmail);
        if (byEmail) return byEmail;
    }

    const sessionEmployeeId = normalizeDeanToken(session && session.employeeId);
    if (sessionEmployeeId) {
        const byEmployeeId = supervisors.find(user => normalizeDeanToken(user && user.employeeId) === sessionEmployeeId);
        if (byEmployeeId) return byEmployeeId;
    }

    const sessionUsername = normalizeDeanToken(session && session.username);
    if (sessionUsername) {
        const byName = supervisors.find(user => normalizeDeanToken(user && user.name) === sessionUsername);
        if (byName) return byName;
        const byEmailName = supervisors.find(user => normalizeDeanToken(user && user.email) === sessionUsername);
        if (byEmailName) return byEmailName;
    }

    const sessionFullName = normalizeDeanToken(session && session.fullName);
    if (sessionFullName) {
        const byFullName = supervisors.find(user => normalizeDeanToken(user && user.name) === sessionFullName);
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
        : `Your login session is not linked to an active ${getSupervisorLabel().toLowerCase()} account.`;

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
    setupMobileDrawer();
    setupHeaderPanels();
    setupDeanHeroActions();
    setupTableActions();
    setupDeanViewportRefresh();
    loadFacultySummary({ evaluationType: 'student' });
    loadProfessorCount();
    setupFacultyResponseView();
    setupDeanIferDirectory();
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
    scheduleDeanViewportRefresh(120);
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
            refreshDeanIferDirectory();
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
            refreshDeanIferDirectory();
        }

        if (key === SharedData.KEYS.FACULTY_PAPERS) {
            refreshDeanIferDirectory();
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
        const programLeadLabel = getSupervisorProgramLeadLabel(deanUser);
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
            if (key === 'mail account') value.textContent = deanEmail || 'N/A';
            if (key === 'program lead') value.textContent = programLeadLabel;
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
                closeMobileDrawer();
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
    closeMobileDrawer();
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

    if (viewName === 'dashboard' || viewName === 'facultyResponse') {
        scheduleDeanViewportRefresh(120);
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

    if (barCtx) {
        const sectionSeries = window.AppChartDesign.buildSectionSeries(criteria, {
            labelKey: 'name',
            valueKey: 'average'
        });
        window.studentBarChartInstance = window.AppChartDesign.renderBarChart(barCtx, {
            labels: sectionSeries.labels,
            values: sectionSeries.values,
            fullLabels: sectionSeries.fullLabels,
            label: 'Average Score',
            colors: ['#4f46e5', '#22c55e'],
            maxValue: 5,
            stepSize: 1,
            tooltipDecimals: 2
        });
    }

    if (pieCtx) {
        window.studentPieChartInstance = window.AppChartDesign.renderRatingDistributionChart(pieCtx, {
            ratingDistribution: distribution,
            averageRating: Number(summary.ratingDistributionAverage || summary.averageRating || (summary.totals && summary.totals.averageScore) || 0)
        });
    }
}

function initializePeerCharts() {
    const barCtx = document.getElementById('peerBarChart');
    const pieCtx = document.getElementById('peerPieChart');
    const summary = getDeanSummaryForType('professor');
    const criteria = Array.isArray(summary.criteriaAverages) ? summary.criteriaAverages : [];
    const distribution = summary.ratingDistribution || { 1: 0, 2: 0, 3: 0, 4: 0, 5: 0 };

    if (barCtx) {
        const sectionSeries = window.AppChartDesign.buildSectionSeries(criteria, {
            labelKey: 'name',
            valueKey: 'average'
        });
        window.peerBarChartInstance = window.AppChartDesign.renderBarChart(barCtx, {
            labels: sectionSeries.labels,
            values: sectionSeries.values,
            fullLabels: sectionSeries.fullLabels,
            label: 'Peer Avg Score',
            colors: ['#2563eb', '#14b8a6'],
            maxValue: 5,
            stepSize: 1,
            tooltipDecimals: 2
        });
    }

    if (pieCtx) {
        window.peerPieChartInstance = window.AppChartDesign.renderRatingDistributionChart(pieCtx, {
            ratingDistribution: distribution,
            averageRating: Number(summary.averageRating || (summary.totals && summary.totals.averageScore) || 0)
        });
    }
}
/**
 * Setup logout functionality
 */
function setupLogout() {
    const logoutLink = document.getElementById('deanLogoutBtn');

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

function setupMobileDrawer() {
    if (deanMobileDrawerBound) return;

    const toggleButtons = document.querySelectorAll('.mobile-nav-toggle');
    const backdrop = document.getElementById('sidebarBackdrop');
    if (!toggleButtons.length || !backdrop) return;

    toggleButtons.forEach(button => {
        button.addEventListener('click', function () {
            const isOpen = document.body.classList.contains('dean-sidebar-open');
            if (isOpen) {
                closeMobileDrawer();
            } else {
                openMobileDrawer();
            }
        });
    });

    backdrop.addEventListener('click', closeMobileDrawer);
    window.addEventListener('resize', function () {
        if (window.innerWidth > 1000) {
            closeMobileDrawer();
        }
    });

    deanMobileDrawerBound = true;
}

function openMobileDrawer() {
    document.body.classList.add('dean-sidebar-open');
    document.querySelectorAll('.mobile-nav-toggle').forEach(button => {
        button.setAttribute('aria-expanded', 'true');
    });
}

function closeMobileDrawer() {
    document.body.classList.remove('dean-sidebar-open');
    document.querySelectorAll('.mobile-nav-toggle').forEach(button => {
        button.setAttribute('aria-expanded', 'false');
    });
}

function setupDeanHeroActions() {
    const evaluationButton = document.getElementById('heroOpenEvaluationBtn');
    const facultyResponseButton = document.getElementById('heroOpenFacultyResponseBtn');
    const facultyPaperButton = document.getElementById('heroOpenFacultyPaperBtn');

    if (evaluationButton) {
        evaluationButton.addEventListener('click', function () {
            switchView('peerEvaluation');
            updateNavigation('peerEvaluation');
        });
    }

    if (facultyResponseButton) {
        facultyResponseButton.addEventListener('click', function () {
            switchView('facultyResponse');
            updateNavigation('facultyResponse');
        });
    }

    if (facultyPaperButton) {
        facultyPaperButton.addEventListener('click', function () {
            switchView('facultyPaperInbox');
            updateNavigation('facultyPaperInbox');
        });
    }
}

function normalizeDeanUserIdToken(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^u\d+$/i.test(raw)) return 'u' + raw.replace(/^u/i, '');
    if (/^\d+$/.test(raw)) return 'u' + String(parseInt(raw, 10));
    return normalizeDeanToken(raw);
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
    const formatted = SharedData.formatDateTimeInPhilippines(raw, 'en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
    return formatted || raw;
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
    if (!paperId) {
        throw new Error('Unable to resolve stored paper context.');
    }

    const params = new URLSearchParams({
        paper_id: paperId
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

function getDeanIferActiveSemesterId() {
    const semesterFilter = document.getElementById('deanIferSemesterSelect');
    return resolveSelectedSemesterId(
        semesterFilter && semesterFilter.value
            ? semesterFilter.value
            : deanIferDirectoryState.semesterId || deanSummaryState.selectedSemesterId
    );
}

function parsePdfFilenameFromDisposition(headerValue) {
    const value = String(headerValue || '').trim();
    if (!value) return '';

    const utfMatch = value.match(/filename\*=UTF-8''([^;]+)/i);
    if (utfMatch && utfMatch[1]) {
        try {
            return decodeURIComponent(utfMatch[1]).replace(/["']/g, '').trim();
        } catch (_error) {
            return String(utfMatch[1]).replace(/["']/g, '').trim();
        }
    }

    const simpleMatch = value.match(/filename="?([^";]+)"?/i);
    return simpleMatch && simpleMatch[1] ? String(simpleMatch[1]).trim() : '';
}

function buildDeanIferCommentKey(source, evaluation, field, questionKey, index) {
    return [
        String(source || '').trim().toLowerCase(),
        String(evaluation && evaluation.id || '').trim() || 'unknown',
        String(field || '').trim() || 'field',
        String(questionKey || '').trim() || '-',
        String(Math.max(0, Number(index) || 0))
    ].join('|');
}

function collectDeanIferEvaluationCommentItems(evaluation, source) {
    const items = [];
    const commentText = String(evaluation && evaluation.comments || '').trim();
    if (commentText) {
        items.push({
            key: buildDeanIferCommentKey(source, evaluation, 'comments', '-', 0),
            text: commentText,
            date: String(evaluation && (evaluation.submittedAt || evaluation.timestamp) || '').trim(),
            source: source === 'supervisor' ? 'Supervisor Evaluation' : 'Student Evaluation'
        });
    }

    const qualitative = evaluation && typeof evaluation.qualitative === 'object' && evaluation.qualitative
        ? evaluation.qualitative
        : {};
    let index = 0;
    Object.keys(qualitative).forEach(questionKey => {
        const text = String(qualitative[questionKey] || '').trim();
        if (!text) return;
        items.push({
            key: buildDeanIferCommentKey(source, evaluation, 'qualitative', questionKey, index),
            text,
            date: String(evaluation && (evaluation.submittedAt || evaluation.timestamp) || '').trim(),
            source: source === 'supervisor' ? 'Supervisor Evaluation' : 'Student Evaluation'
        });
        index += 1;
    });

    return items;
}

function resolveDeanIferRecordProfessorId(record, context) {
    const direct = normalizeUserIdToken(record && record.professorUserId);
    if (direct) return direct;

    const employeeToken = normalizeRoleToken(record && record.employeeId);
    const nameToken = normalizeRoleToken(record && record.professorName);
    const professor = (context.scopedProfessors || []).find(item =>
        normalizeRoleToken(item && (item.employeeId || item.id)) === employeeToken
        || normalizeRoleToken(item && item.name) === nameToken
    );
    return normalizeUserIdToken(professor && professor.id);
}

function isDeanIferEvaluationForRecord(evaluation, source, record, context, targetProfessorId) {
    const resolved = resolveDeanTargetProfessorId(evaluation, source, context);
    if (resolved && targetProfessorId && resolved === targetProfessorId) {
        return true;
    }

    const employeeToken = normalizeRoleToken(record && record.employeeId);
    const nameToken = normalizeRoleToken(record && record.professorName);
    const candidates = [
        evaluation && evaluation.targetProfessorId,
        evaluation && evaluation.targetId,
        evaluation && evaluation.colleagueId,
        evaluation && evaluation.targetUserId
    ];
    for (let index = 0; index < candidates.length; index += 1) {
        const candidateUserId = normalizeUserIdToken(candidates[index]);
        if (candidateUserId && targetProfessorId && candidateUserId === targetProfessorId) return true;
        const candidateToken = normalizeRoleToken(candidates[index]);
        if (candidateToken && employeeToken && candidateToken === employeeToken) return true;
    }

    const nameCandidates = [
        evaluation && evaluation.targetProfessor,
        evaluation && evaluation.professorSubject,
        evaluation && evaluation.targetName
    ];
    for (let index = 0; index < nameCandidates.length; index += 1) {
        const head = normalizeRoleToken(String(nameCandidates[index] || '').split(' - ')[0]);
        if (head && nameToken && head === nameToken) return true;
    }

    return false;
}

function buildDeanIferSelectableComments(record, semesterId) {
    const context = buildDeanPanelContext();
    const targetProfessorId = resolveDeanIferRecordProfessorId(record, context);
    const result = {
        student: [],
        supervisor: []
    };
    const seen = {
        student: new Set(),
        supervisor: new Set()
    };

    (context.evaluations || []).forEach(evaluation => {
        if (!isEvaluationInSemester(evaluation, semesterId)) return;
        const type = resolveEvaluationTypeToken(evaluation);
        if (type !== 'student' && type !== 'supervisor') return;
        if (!isDeanIferEvaluationForRecord(evaluation, type, record, context, targetProfessorId)) return;

        collectDeanIferEvaluationCommentItems(evaluation, type).forEach(item => {
            if (!item.text || seen[type].has(item.key)) return;
            seen[type].add(item.key);
            result[type].push(item);
        });
    });

    Object.keys(result).forEach(source => {
        result[source].sort((left, right) => {
            const leftTime = new Date(left.date || 0).getTime() || 0;
            const rightTime = new Date(right.date || 0).getTime() || 0;
            return rightTime - leftTime;
        });
    });

    return result;
}

function renderDeanIferCommentOptions(container, comments, source) {
    if (!container) return;
    if (!Array.isArray(comments) || !comments.length) {
        container.innerHTML = '<div class="dean-ifer-comment-empty">No comments available for this source.</div>';
        return;
    }

    container.innerHTML = comments.map((comment, index) => `
        <label class="dean-ifer-comment-option">
            <input type="checkbox" class="dean-ifer-comment-check" data-source="${escapeHTML(source)}" value="${escapeHTML(comment.key)}">
            <span>
                <strong>${escapeHTML(String(comment.source || 'Comment'))} ${escapeHTML(String(index + 1))}</strong>
                <span>${escapeHTML(String(comment.text || ''))}</span>
                <em>${escapeHTML(formatDisplayDate(comment.date))}</em>
            </span>
        </label>
    `).join('');
}

function updateDeanIferCommentLimitState(modal) {
    ['student', 'supervisor'].forEach(source => {
        const checks = Array.from(modal.querySelectorAll(`.dean-ifer-comment-check[data-source="${source}"]`));
        const selected = checks.filter(item => item.checked);
        checks.forEach(item => {
            item.disabled = false;
        });
        const counter = modal.querySelector(`[data-ifer-comment-count="${source}"]`);
        if (counter) counter.textContent = `${selected.length} selected`;
    });
}

function openDeanIferCommentPicker(record) {
    return openDeanIferTemplatePreview(record);
}

async function openDeanIferTemplatePreview(record) {
    const professorUserId = String(record && record.professorUserId || '').trim();
    const semesterId = getDeanIferActiveSemesterId();
    if (!professorUserId) {
        alert('Unable to resolve professor account for IFER download.');
        return;
    }
    if (!semesterId) {
        alert('Select a semester first.');
        return;
    }

    let response;
    try {
        response = await fetch('../api/generate_ifer.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                professor_user_id: professorUserId,
                semester_id: semesterId
            })
        });
    } catch (_error) {
        alert('Unable to connect to the IFER generator.');
        return;
    }

    if (!response.ok) {
        let errorMessage = 'Failed to generate IFER Word file.';
        try {
            const data = await response.json();
            if (data && data.error) errorMessage = String(data.error);
        } catch (_error) {
            // Ignore non-JSON body.
        }
        alert(errorMessage);
        return;
    }

    const wordBlob = await response.blob();
    const fileName = parsePdfFilenameFromDisposition(response.headers.get('Content-Disposition'))
        || String(deanIferDirectoryState.templateName || 'ifer.docx').trim()
        || 'ifer.docx';
    const blobUrl = URL.createObjectURL(wordBlob);
    const anchor = document.createElement('a');
    anchor.href = blobUrl;
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    window.setTimeout(() => URL.revokeObjectURL(blobUrl), 30000);
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

    const editable = paper && typeof paper.canCurrentActorEdit === 'boolean'
        ? !!paper.canCurrentActorEdit
        : (normalizeDeanToken(paper.status) === 'sent' || normalizeDeanToken(paper.status) === 'completed');
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
                load_type: String(paper.load_type || paper.loadType || 'main').trim().toLowerCase() === 'excess' ? 'excess' : 'main',
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
        tableBody.innerHTML = `<tr class="mobile-card-empty-row"><td colspan="6">Unable to resolve ${getSupervisorLabel().toLowerCase()} account for this session.</td></tr>`;
        renderDeanFacultyPaperDetail(null);
        return;
    }

    let papers = [];
    try {
        papers = SharedData.listFacultyPapers(SUPERVISOR_ROLE, actorUserId);
    } catch (error) {
        tableBody.innerHTML = '<tr class="mobile-card-empty-row"><td colspan="6">Failed to load faculty papers.</td></tr>';
        renderDeanFacultyPaperDetail(null);
        return;
    }

    deanFacultyPaperState.papers = Array.isArray(papers) ? papers : [];
    if (!deanFacultyPaperState.papers.length) {
        tableBody.innerHTML = '<tr class="mobile-card-empty-row"><td colspan="6">No faculty papers assigned.</td></tr>';
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
                <td data-label="Paper ID">${escapeHTML(String(paper.id || 'N/A'))}</td>
                <td data-label="Professor">${escapeHTML(String(paper.professor_name || 'N/A'))}</td>
                <td data-label="Semester">${escapeHTML(String(paper.semester_label || 'N/A'))}</td>
                <td data-label="Status">${escapeHTML(mapDeanPaperStatus(paper.status))}</td>
                <td data-label="Sent At">${escapeHTML(formatDeanPaperTimestamp(paper.sent_at))}</td>
                <td data-label="Actions"><button type="button" class="btn-submit dean-paper-open-btn" data-paper-id="${escapeHTML(String(paper.id || ''))}">Open</button></td>
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
                alert(`Unable to resolve ${getSupervisorLabel().toLowerCase()} account.`);
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
                    actor_role: SUPERVISOR_ROLE,
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
    const scopedDirectory = professors.map(professor => {
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
    const availableDirectory = scopedDirectory.filter(item => !isSupervisorTargetLocked(item.userId));
    const lockedCount = Math.max(0, scopedDirectory.length - availableDirectory.length);
    deanSupervisorTargetDirectory = availableDirectory;

    datalist.innerHTML = '';
    deanSupervisorTargetDirectory.forEach(item => {
        const option = document.createElement('option');
        option.value = item.label;
        datalist.appendChild(option);
    });

    if (searchMeta) {
        if (deanSupervisorTargetDirectory.length) {
            const label = deanSupervisorTargetDirectory.length === 1 ? 'employee' : 'employees';
            searchMeta.textContent = lockedCount > 0
                ? `Showing ${deanSupervisorTargetDirectory.length} ${label}. ${lockedCount} already evaluated this semester were hidden.`
                : `Showing ${deanSupervisorTargetDirectory.length} ${label} in your ${getSupervisorScopeDescriptor()}.`;
        } else if (scopedDirectory.length > 0) {
            searchMeta.textContent = `All employees in your ${getSupervisorScopeDescriptor()} were already evaluated this semester.`;
        } else {
            searchMeta.textContent = `No active professors in your ${getSupervisorScopeDescriptor()}.`;
        }
    }

    const currentMatch = deanSupervisorTargetDirectory.find(item => item.userId === currentUserId);
    if (currentMatch) {
        hiddenSelectValue.value = currentMatch.userId;
        searchInput.value = currentMatch.label;
    } else {
        hiddenSelectValue.value = '';
        searchInput.value = '';
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
        if (role && role !== 'dean' && role !== 'procoor' && role !== 'supervisor') return false;

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
        evaluatorName: session.fullName || getSupervisorAnonymousLabel(),
        evaluatorRole: SUPERVISOR_ROLE,
        evaluationType: 'supervisor',
        targetId: formData.get('peerProfessor'),
        targetProfessorId: targetProfessorId,
        semesterId: semesterId,
        evaluationKey: evaluationKey,
        ratings: ratingsGroup,
        qualitative: qualitativeGroup,
        comments: formData.get('peerComments') || '',
        submittedAt: SharedData.getNowIsoString()
    };

    try {
        // Save via centralized API
        SharedData.addEvaluation(payload);

        // Add to activity log
        SharedData.addActivityLogEntry({
            type: 'evaluation_submitted',
            title: 'Supervisor Evaluation Submitted',
            user: payload.evaluatorName,
            role: SUPERVISOR_ROLE,
            date: SharedData.getNowIsoString()
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

    // Keep user on evaluation view and reload the target list after successful submission.
    setTimeout(() => {
        form.reset();
        populatePeerProfessorOptions();
        syncSupervisorTargetFromInput();
        const evaluationTypeInput = document.getElementById('evaluationType');
        if (evaluationTypeInput) evaluationTypeInput.value = evaluationType;
        refreshSupervisorTargetLockState();
        switchView('peerEvaluation');
        updateNavigation('peerEvaluation');
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
    const qid = escapeHTML(String(question.id));

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
    if (!form) return;
    clearFormMessage(form);

    const messageDiv = document.createElement('div');
    const tone = type === 'error' ? 'error' : (type === 'success' ? 'success' : 'info');
    messageDiv.className = `form-message ui-message ui-message--${tone}`;
    messageDiv.textContent = message;
    form.insertBefore(messageDiv, form.firstChild);

    setTimeout(() => {
        if (messageDiv.parentNode) {
            messageDiv.remove();
        }
    }, 4000);
}

function clearFormMessage(form) {
    if (!form) return;
    const existing = form.querySelector('.form-message');
    if (existing) existing.remove();
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

        const allowedTypes = ['image/jpeg', 'image/png', 'image/webp'];
        if (!allowedTypes.includes(String(file.type || '').toLowerCase())) {
            alert('Please choose a JPG, JPEG, PNG, or WEBP image.');
            input.value = '';
            return;
        }

        if (Number(file.size || 0) > (2 * 1024 * 1024)) {
            alert('Please choose an image smaller than 2MB.');
            input.value = '';
            return;
        }

        const localPreviewUrl = URL.createObjectURL(file);
        preview.src = localPreviewUrl;
        preview.classList.add('active');
        placeholder.style.display = 'none';

        if (typeof SharedData.uploadProfilePhoto !== 'function') {
            const reader = new FileReader();
            reader.onload = function () {
                preview.src = reader.result;
                preview.classList.add('active');
                placeholder.style.display = 'none';
                SharedData.setProfilePhoto('dean', reader.result);
                URL.revokeObjectURL(localPreviewUrl);
                input.value = '';
            };
            reader.readAsDataURL(file);
            return;
        }

        try {
            const savedPhoto = SharedData.uploadProfilePhoto(file);
            if (savedPhoto) {
                preview.src = savedPhoto;
            }
            preview.classList.add('active');
            placeholder.style.display = 'none';
        } catch (error) {
            alert(error && error.message ? error.message : 'Failed to upload the profile image.');
            const storedPhoto = SharedData.getProfilePhoto('dean');
            if (storedPhoto) {
                preview.src = storedPhoto;
                preview.classList.add('active');
                placeholder.style.display = 'none';
            } else {
                preview.removeAttribute('src');
                preview.classList.remove('active');
                placeholder.style.display = '';
            }
        } finally {
            URL.revokeObjectURL(localPreviewUrl);
            input.value = '';
        }
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
            hideAccountActionCards();
            const targetCard = document.getElementById(targetId);
            if (targetCard) {
                setActiveAccountActionButton(targetId);
                targetCard.style.display = 'block';
                const prefersReducedMotion = window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                targetCard.scrollIntoView({ behavior: prefersReducedMotion ? 'auto' : 'smooth', block: 'start' });
            }
        });
    });

    closeButtons.forEach(button => {
        button.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            const targetCard = targetId ? document.getElementById(targetId) : null;
            if (targetCard) {
                const form = targetCard.querySelector('form');
                if (form) {
                    form.reset();
                    clearFormMessage(form);
                }
                targetCard.style.display = 'none';
                clearActiveAccountActionButtons();
            }
        });
    });
}

function setActiveAccountActionButton(targetId) {
    document.querySelectorAll('.js-toggle-account-form').forEach(button => {
        button.classList.toggle('is-active', button.getAttribute('data-target') === targetId);
    });
}

function clearActiveAccountActionButtons() {
    document.querySelectorAll('.js-toggle-account-form').forEach(button => {
        button.classList.remove('is-active');
    });
}

function hideAccountActionCards() {
    document.querySelectorAll('.account-action-card').forEach(card => {
        const form = card.querySelector('form');
        if (form) clearFormMessage(form);
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
 * Change email handler
 */
function handleChangeEmail() {
    const form = document.getElementById('changeEmailForm');
    if (!form) return;

    const currentEmail = String((document.getElementById('currentEmail') || {}).value || '').trim();
    const newEmail = String((document.getElementById('newEmail') || {}).value || '').trim();
    const confirmEmail = String((document.getElementById('confirmEmail') || {}).value || '').trim();

    if (!newEmail || !confirmEmail) {
        showFormMessage(form, 'Please fill out all mail account fields.', 'error');
        return;
    }
    if (newEmail !== confirmEmail) {
        showFormMessage(form, 'New mail account and confirmation do not match.', 'error');
        return;
    }
    if (currentEmail && newEmail.toLowerCase() === currentEmail.toLowerCase()) {
        showFormMessage(form, 'New mail account must be different from the current mail account.', 'error');
        return;
    }
    if (!SharedData.changeOwnEmail) {
        showFormMessage(form, 'Mail account update service is unavailable.', 'error');
        return;
    }

    try {
        const result = SharedData.changeOwnEmail(currentEmail, newEmail);
        const nextEmail = String(result && result.email || newEmail).trim();
        const currentEmailInput = document.getElementById('currentEmail');
        if (currentEmailInput) {
            currentEmailInput.value = nextEmail;
            currentEmailInput.defaultValue = nextEmail;
        }
        const profileEmail = document.getElementById('profileEmail');
        if (profileEmail) {
            profileEmail.textContent = nextEmail;
        }
        showFormMessage(form, 'Mail account updated successfully.', 'success');
        form.reset();
    } catch (error) {
        console.error('[DeanPanel] Failed to update mail account.', error);
        showFormMessage(form, error && error.message ? error.message : 'Failed to update mail account.', 'error');
    }
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
 * Change password handler
 */
function handleChangePassword() {
    const form = document.getElementById('changePasswordForm');
    if (!form) return;

    const currentPassword = String((document.getElementById('currentPassword') || {}).value || '').trim();
    const newPassword = String((document.getElementById('newPassword') || {}).value || '').trim();
    const confirmPassword = String((document.getElementById('confirmPassword') || {}).value || '').trim();

    if (!currentPassword || !newPassword || !confirmPassword) {
        showFormMessage(form, 'Please fill out all password fields.', 'error');
        return;
    }
    if (newPassword !== confirmPassword) {
        showFormMessage(form, 'New password and confirmation do not match.', 'error');
        return;
    }
    if (!SharedData.changeOwnPassword) {
        showFormMessage(form, 'Password update service is unavailable.', 'error');
        return;
    }

    try {
        SharedData.changeOwnPassword(currentPassword, newPassword);
        showFormMessage(form, 'Password updated successfully.', 'success');
        form.reset();
    } catch (error) {
        console.error('[DeanPanel] Failed to update password.', error);
        showFormMessage(form, error && error.message ? error.message : 'Failed to update password.', 'error');
    }
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
    const formatted = SharedData.formatDateInPhilippines(dateString, 'en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
    return formatted || dateString;
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
        instituteFilter.innerHTML = `<option value="all">${isProgramScopedSupervisorPanel() ? 'Program scope' : 'Department scope'}</option>` + institutes.map(institute =>
            '<option value="' + escapeHTML(institute) + '">' + escapeHTML(institute) + '</option>'
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

function buildDeanPeerRequiredCountMap(semesterId) {
    const requiredByProfessor = {};
    const detailMethod = getSupervisorPeerMethodName('details');
    if (!SharedData || typeof SharedData[detailMethod] !== 'function') {
        return requiredByProfessor;
    }

    const selectedSemester = resolveSelectedSemesterId(semesterId);
    try {
        const response = SharedData[detailMethod]({});
        const currentSemester = String(response && response.currentSemester || '').trim();
        if (!currentSemester || (selectedSemester && currentSemester !== selectedSemester)) {
            return requiredByProfessor;
        }

        const programs = Array.isArray(response && response.programs) ? response.programs : [];
        programs.forEach(program => {
            const professors = Array.isArray(program && program.professors) ? program.professors : [];
            professors.forEach(professor => {
                const professorUserId = normalizeUserIdToken(professor && professor.userId);
                if (!professorUserId) return;
                requiredByProfessor[professorUserId] = Math.max(Number(professor && professor.outgoingCount || 0), 0);
            });
        });
    } catch (error) {
        console.warn('[Dean] Unable to load peer assignment counts for response table.', error);
    }

    return requiredByProfessor;
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
    const deanEvaluatorToken = normalizeRoleToken(query && query.deanId);
    const peerRequiredByProfessor = normalizedType === 'professor'
        ? buildDeanPeerRequiredCountMap(semesterId)
        : {};
    const hasPeerAssignmentMap = Object.keys(peerRequiredByProfessor).length > 0;
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
            if (normalizedType === 'professor') {
                const assignmentRequired = Number(peerRequiredByProfessor[professorUserId] || 0);
                required = hasPeerAssignmentMap
                    ? Math.max(assignmentRequired, 0)
                    : Math.max(scopedProfessors.length - 1, 0);
            } else {
                required = 1;
            }

            filteredByType.forEach(evaluation => {
                const targetProfessorId = resolveDeanTargetProfessorId(evaluation, normalizedType, context);
                if (targetProfessorId !== professorUserId) return;
                if (normalizedType === 'supervisor' && deanEvaluatorToken) {
                    const evaluatorToken = normalizeRoleToken(
                        evaluation && (evaluation.evaluatorId || evaluation.evaluatorUsername || evaluation.evaluatorEmail || evaluation.evaluatorName)
                    );
                    if (!evaluatorToken || evaluatorToken !== deanEvaluatorToken) return;
                }
                professorEvaluations.push(evaluation);
            });
        }

        const avgScore = computeAverageRatingFromEvaluations(professorEvaluations);
        const receivedCount = normalizedType === 'supervisor'
            ? Math.min(professorEvaluations.length, 1)
            : professorEvaluations.length;
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
            received: receivedCount,
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
        if (scopeBadge) scopeBadge.textContent = 'Scope: ' + (selectedInstitute === 'all' ? (isProgramScopedSupervisorPanel() ? 'Program scope' : 'Department scope') : selectedInstitute);
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
    if (scopeBadge) scopeBadge.textContent = 'Scope: ' + (selectedInstitute === 'all' ? (isProgramScopedSupervisorPanel() ? 'Program scope' : 'Department scope') : selectedInstitute);
}

function buildDeanIferDirectoryRecords() {
    const context = buildDeanPanelContext();
    const session = getUserSession() || {};
    const assignedInstitutes = getDeanAssignedInstitutes(session);
    const instituteSet = new Set(
        (Array.isArray(assignedInstitutes) ? assignedInstitutes : [])
            .map(item => String(item || '').trim().toUpperCase())
            .filter(Boolean)
    );
    const professors = Array.isArray(context && context.scopedProfessors) ? context.scopedProfessors : [];

    return professors
        .filter(professor => {
            if (!instituteSet.size) return true;
            const institute = String((professor && (professor.department || professor.institute)) || '').trim().toUpperCase();
            return instituteSet.has(institute);
        })
        .map(professor => ({
            professorUserId: normalizeUserIdToken(professor && professor.id),
            employeeId: String(professor && (professor.employeeId || professor.id) || '').trim() || 'N/A',
            professorName: String(professor && professor.name || '').trim() || 'Unknown',
            institute: String((professor && (professor.department || professor.institute)) || '').trim().toUpperCase() || 'N/A',
            position: String(professor && professor.position || '').trim() || 'N/A',
            status: normalizeRoleToken(professor && professor.status || 'active') === 'inactive' ? 'Inactive' : 'Active',
            fileName: deanIferDirectoryState.templateName,
            recordKey: [
                normalizeUserIdToken(professor && professor.id),
                String(professor && (professor.employeeId || professor.id) || '').trim(),
                String(professor && professor.name || '').trim()
            ].join('::')
        }))
        .sort((left, right) => String(left.professorName || '').localeCompare(String(right.professorName || '')));
}

function filterDeanIferDirectoryRecords(records, query) {
    const keyword = String(query || '').trim().toLowerCase();
    if (!keyword) return records.slice();

    return records.filter(record => {
        const professorName = String(record && record.professorName || '').toLowerCase();
        const employeeId = String(record && record.employeeId || '').toLowerCase();
        const institute = String(record && record.institute || '').toLowerCase();
        return professorName.includes(keyword) || employeeId.includes(keyword) || institute.includes(keyword);
    });
}

function renderDeanIferDirectory() {
    const tableBody = document.getElementById('deanIferTableBody');
    const resultEl = document.getElementById('deanIferSearchResult');
    const templateNameEl = document.getElementById('deanIferTemplateName');
    const searchInput = document.getElementById('deanIferSearchInput');
    const semesterSelect = document.getElementById('deanIferSemesterSelect');

    if (!tableBody || !resultEl) return;

    if (templateNameEl) {
        templateNameEl.textContent = deanIferDirectoryState.templateName;
    }
    if (searchInput && searchInput.value !== deanIferDirectoryState.query) {
        searchInput.value = deanIferDirectoryState.query;
    }
    if (semesterSelect && deanIferDirectoryState.semesterId && semesterSelect.value !== deanIferDirectoryState.semesterId) {
        semesterSelect.value = deanIferDirectoryState.semesterId;
    }

    const records = Array.isArray(deanIferDirectoryState.records) ? deanIferDirectoryState.records : [];
    const query = deanIferDirectoryState.query;
    const semesterLabel = getSemesterLabelById(getDeanIferActiveSemesterId());
    const filtered = filterDeanIferDirectoryRecords(records, query);

    if (!records.length) {
        resultEl.textContent = `No professors found in your ${getSupervisorScopeDescriptor()}.`;
        tableBody.innerHTML = '<tr class="mobile-card-empty-row"><td colspan="6">No professors available for IFER viewing yet.</td></tr>';
        return;
    }

    if (!filtered.length) {
        resultEl.textContent = `No IFER matches found for "${query}".`;
        tableBody.innerHTML = '<tr class="mobile-card-empty-row"><td colspan="6">No professor matches your search.</td></tr>';
        return;
    }

    resultEl.textContent = query
        ? `Showing ${filtered.length} of ${records.length} professors for "${query}". Semester: ${semesterLabel}. File: ${deanIferDirectoryState.templateName}`
        : `Showing ${filtered.length} professors in your ${getSupervisorScopeDescriptor()} for ${semesterLabel}. File: ${deanIferDirectoryState.templateName}`;

    tableBody.innerHTML = filtered.map(record => {
        const statusClass = record.status === 'Inactive' ? 'inactive' : 'active';
        return `
            <tr>
                <td data-label="Employee ID">${escapeHTML(record.employeeId)}</td>
                <td data-label="Professor Name">
                    <div class="dean-ifer-professor-cell">
                        <strong>${escapeHTML(record.professorName)}</strong>
                        <span class="dean-status-pill ${statusClass}">${escapeHTML(record.status)}</span>
                    </div>
                </td>
                <td data-label="Institute">${escapeHTML(record.institute)}</td>
                <td data-label="Position">${escapeHTML(record.position)}</td>
                <td data-label="IFER File"><span class="dean-ifer-file-name">${escapeHTML(record.fileName)}</span></td>
                <td data-label="Action"><button type="button" class="btn-submit dean-ifer-open-btn" data-professor-key="${escapeHTML(record.recordKey)}">Download IFER</button></td>
            </tr>
        `;
    }).join('');

    tableBody.querySelectorAll('.dean-ifer-open-btn').forEach(button => {
        button.addEventListener('click', function () {
            const professorKey = String(button.getAttribute('data-professor-key') || '').trim();
            const record = filtered.find(item => String(item.recordKey || '').trim() === professorKey)
                || null;
            openDeanIferTemplatePreview(record);
        });
    });
}

function refreshDeanIferDirectory() {
    populateDeanIferSemesterFilter();
    deanIferDirectoryState.records = buildDeanIferDirectoryRecords();
    renderDeanIferDirectory();
}

function populateDeanIferSemesterFilter() {
    const semesterSelect = document.getElementById('deanIferSemesterSelect');
    if (!semesterSelect) return;

    const context = buildDeanPanelContext();
    const list = Array.isArray(context && context.semesterList) ? context.semesterList : [];
    const fallbackId = resolveSelectedSemesterId(deanIferDirectoryState.semesterId || deanSummaryState.selectedSemesterId || context.currentSemester);
    const options = list.length
        ? list
        : (fallbackId ? [{ value: fallbackId, label: getSemesterLabelById(fallbackId) }] : []);

    semesterSelect.innerHTML = '';
    options.forEach(item => {
        const value = String(item && item.value || '').trim();
        if (!value) return;
        const option = document.createElement('option');
        option.value = value;
        option.textContent = String(item && item.label || '').trim() || getSemesterLabelById(value);
        semesterSelect.appendChild(option);
    });

    if (!semesterSelect.options.length) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'No semester available';
        semesterSelect.appendChild(option);
    }

    const preferred = String(deanIferDirectoryState.semesterId || fallbackId || '').trim();
    if (preferred && Array.from(semesterSelect.options).some(opt => opt.value === preferred)) {
        semesterSelect.value = preferred;
    } else if (semesterSelect.options.length) {
        semesterSelect.selectedIndex = 0;
    }

    deanIferDirectoryState.semesterId = resolveSelectedSemesterId(semesterSelect.value || fallbackId);
}

function setupDeanIferDirectory() {
    const searchInput = document.getElementById('deanIferSearchInput');
    const searchBtn = document.getElementById('deanIferSearchBtn');
    const resetBtn = document.getElementById('deanIferResetBtn');
    const semesterSelect = document.getElementById('deanIferSemesterSelect');

    if (!searchInput || !searchBtn || !resetBtn || !semesterSelect) return;

    if (!deanIferDirectoryState.initialized) {
        deanIferDirectoryState.initialized = true;

        searchBtn.addEventListener('click', function () {
            deanIferDirectoryState.query = searchInput.value.trim();
            renderDeanIferDirectory();
        });

        resetBtn.addEventListener('click', function () {
            deanIferDirectoryState.query = '';
            searchInput.value = '';
            renderDeanIferDirectory();
        });

        searchInput.addEventListener('keydown', function (event) {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            deanIferDirectoryState.query = searchInput.value.trim();
            renderDeanIferDirectory();
        });

        semesterSelect.addEventListener('change', function () {
            deanIferDirectoryState.semesterId = resolveSelectedSemesterId(semesterSelect.value);
            renderDeanIferDirectory();
        });
    }

    searchInput.value = deanIferDirectoryState.query;
    refreshDeanIferDirectory();
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
    const commentsAiBtn = document.getElementById('facultyCommentsAiSummarize');
    const commentsAiSummary = document.getElementById('facultyCommentsAiSummary');
    const commentsClose = document.getElementById('facultyCommentsClose');
    const trendStatus = document.getElementById('deanSemestralTrendStatus');
    const trendDelta = document.getElementById('deanSemestralTrendDelta');
    const trendTableBody = document.getElementById('deanSemestralTrendTableBody');
    const trendChart = document.getElementById('deanSemestralTrendChart');
    const trendSection = document.getElementById('deanSemestralTrendSection');

    if (
        !searchInput || !searchBtn || !resetBtn || !semesterFilter
        || !resultEl || !table || !commentsPanel || !commentsTitle || !commentsMeta
        || !commentsList || !commentsAiBtn || !commentsAiSummary || !commentsClose
        || !trendStatus || !trendDelta || !trendTableBody || !trendChart || !trendSection
    ) return;

    const session = getUserSession() || {};
    const deanId = session.username || '';
    const assignedInstitutes = getDeanAssignedInstitutes(session);
    let sourceData = [];
    let currentView = 'student';
    let selectedSemesterId = resolveSelectedSemesterId(deanSummaryState.selectedSemesterId);
    const feedbackState = deanFacultyFeedbackState;
    feedbackState.selectedSourceView = currentView;
    feedbackState.loadedComments = Array.isArray(feedbackState.loadedComments) ? feedbackState.loadedComments : [];
    feedbackState.lastTrendRows = Array.isArray(feedbackState.lastTrendRows) ? feedbackState.lastTrendRows : [];

    function getPanelLabel() {
        return isProgramScopedSupervisorPanel() ? 'Program Coordinator' : 'Dean';
    }

    function applyCommentsAiAccessState() {
        const enabled = isSupervisorOpenAiPanelEnabled();
        commentsAiBtn.disabled = !enabled;
        commentsAiBtn.title = enabled ? '' : `OpenAI features are disabled for the ${getPanelLabel()} panel by the administrator.`;
        return enabled;
    }

    function getFeedbackViewLabel(view) {
        if (view === 'peer') return 'Professor Evaluation';
        if (view === 'supervisor') return 'Supervisor Evaluation';
        return 'Student Evaluation';
    }

    function renderTaggedComments(comments) {
        setDeanCommentsListVisibility(true);
        if (!Array.isArray(comments) || !comments.length) {
            commentsList.innerHTML = '<li class="faculty-comments-empty">No comments available.</li>';
            return;
        }
        const isStudentView = String(feedbackState.selectedSourceView || currentView).trim() === 'student';
        const hideStudentIdentity = shouldHideStudentCommentIdentity();

        commentsList.innerHTML = comments.map(comment => {
            const studentNumberMeta = isStudentView && !hideStudentIdentity
                ? '<div class="faculty-comment-meta">Student Number: ' + escapeHTML(resolveDeanCommentStudentNumber(comment)) + '</div>'
                : '';
            return '<li>' +
                '<div class="faculty-comment-text">"' + escapeHTML(String(comment && comment.text || '')) + '"</div>' +
                studentNumberMeta +
                '<div class="faculty-comment-meta">' + escapeHTML(String(comment && comment.source || getFeedbackViewLabel(currentView))) + ' • ' + escapeHTML(formatDisplayDate(comment && comment.date)) + '</div>' +
                '</li>';
        }).join('');
    }

    function resetFeedbackPanelState(hidePanel = false) {
        feedbackState.selectedProfessorUserId = '';
        feedbackState.selectedProfessorId = '';
        feedbackState.selectedProfessorName = '';
        feedbackState.selectedSourceView = currentView;
        feedbackState.loadedComments = [];
        feedbackState.lastTrendRows = [];
        commentsList.innerHTML = '<li class="faculty-comments-empty">No comments loaded yet.</li>';
        if (applyCommentsAiAccessState()) {
            setDeanCommentsAiSummary('Click AI Summarize after opening a professor comments list.', 'info');
        } else {
            setDeanCommentsAiSummary(`OpenAI features are disabled for the ${getPanelLabel()} panel by the administrator.`, 'warning');
        }
        resetDeanProfessorSemestralTrend();
        setDeanSemestralTrendVisibility(false);
        if (hidePanel) {
            commentsPanel.classList.remove('active');
        }
    }

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

        resetFeedbackPanelState(true);

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
        resetFeedbackPanelState(true);
    });

    commentsAiBtn.addEventListener('click', async function () {
        if (!applyCommentsAiAccessState()) {
            setDeanCommentsAiSummary(`OpenAI features are disabled for the ${getPanelLabel()} panel by the administrator.`, 'warning');
            return;
        }

        if (!Array.isArray(feedbackState.loadedComments) || !feedbackState.loadedComments.length) {
            setDeanCommentsAiSummary('No comments loaded for the selected professor. Open a professor comments list first.', 'warning');
            return;
        }

        const originalHtml = commentsAiBtn.innerHTML;
        commentsAiBtn.disabled = true;
        commentsAiBtn.innerHTML = '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i> Summarizing...';
        setDeanCommentsAiSummary('Generating AI summary...', 'info');

        const viewLabel = getFeedbackViewLabel(feedbackState.selectedSourceView || currentView);
        try {
            if (typeof SharedData === 'undefined' || typeof SharedData.summarizeFeedbackComments !== 'function') {
                throw new Error('AI summary API is unavailable.');
            }

            const response = await SharedData.summarizeFeedbackComments({
                evaluationLabel: viewLabel,
                comments: feedbackState.loadedComments.map((item, index) => ({
                    id: String(item && item.id || item && item.key || `comment_${index + 1}`),
                    text: String(item && item.text || '').trim(),
                })).filter(item => item.text)
            }, { role: SUPERVISOR_ROLE });

            if (response && response.disabled) {
                setDeanCommentsAiSummary(response.error || `OpenAI features are disabled for the ${getPanelLabel()} panel by the administrator.`, 'warning');
                return;
            }

            if (!response || response.success === false) {
                throw new Error((response && response.error) || 'Failed to generate AI summary.');
            }

            const summaryData = response.summary && typeof response.summary === 'object'
                ? response.summary
                : buildDeanFeedbackAiSummary(feedbackState.loadedComments, { evaluationLabel: viewLabel });
            const source = normalizeDeanToken(summaryData.source || response.source);
            setDeanCommentsAiSummary(summaryData, source === 'openai' ? 'success' : 'warning');
        } catch (error) {
            const fallbackSummary = buildDeanFeedbackAiSummary(feedbackState.loadedComments, {
                evaluationLabel: viewLabel
            });
            fallbackSummary.warning = (error && error.message ? error.message : 'OpenAI summary failed.') + ' Rule-based summary used.';
            fallbackSummary.source = 'rule';
            setDeanCommentsAiSummary(fallbackSummary, 'warning');
        } finally {
            commentsAiBtn.innerHTML = originalHtml || '<i class="fas fa-wand-magic-sparkles" aria-hidden="true"></i> AI Summarize';
            applyCommentsAiAccessState();
        }
        setDeanCommentsListVisibility(false);
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
                const professorUserIdToken = normalizeUserIdToken(this.getAttribute('data-professor-user-id'));
                const professor = items.find(item =>
                    (professorUserIdToken && normalizeUserIdToken(item && item.professorUserId) === professorUserIdToken)
                    || String(item && item.professorId || '') === String(professorId || '')
                );
                if (!professor) return;

                commentsTitle.textContent = 'Comments for ' + professor.professorName;
                commentsMeta.textContent = professor.professorId + ' | ' + professor.institute + ' | ' + getFeedbackViewLabel(currentView);

                let professorUserId = professorUserIdToken || normalizeUserIdToken(professor && professor.professorUserId);
                if (!professorUserId) {
                    const context = buildDeanPanelContext();
                    const professorToken = normalizeRoleToken(professor && professor.professorId);
                    const matchedProfessor = (context.scopedProfessors || []).find(item =>
                        normalizeRoleToken(item && (item.employeeId || item.id)) === professorToken
                    );
                    professorUserId = normalizeUserIdToken(matchedProfessor && matchedProfessor.id);
                }
                feedbackState.selectedProfessorUserId = professorUserId;
                feedbackState.selectedProfessorId = String(professor.professorId || '').trim();
                feedbackState.selectedProfessorName = String(professor.professorName || '').trim();
                feedbackState.selectedSourceView = currentView;
                setDeanSemestralTrendVisibility(true);

                fetchFacultyCommentsFromSql({
                    professorId: professor.professorId,
                    professorUserId: professorUserId,
                    deanId,
                    semesterId: selectedSemesterId,
                    source: currentView
                }).then(comments => {
                    const normalized = Array.isArray(comments) ? comments : [];
                    feedbackState.loadedComments = normalized;

                    renderTaggedComments(normalized);
                    renderDeanProfessorSemestralTrend(feedbackState.selectedProfessorUserId, selectedSemesterId);
                    if (!normalized.length) {
                        setDeanCommentsAiSummary('No comments available for this professor in the selected filters.', 'warning');
                    } else if (!applyCommentsAiAccessState()) {
                        setDeanCommentsAiSummary(`OpenAI features are disabled for the ${getPanelLabel()} panel by the administrator.`, 'warning');
                    } else {
                        setDeanCommentsAiSummary('Comments loaded. Click AI Summarize to summarize this professor comments.', 'info');
                    }
                    commentsPanel.classList.add('active');
                }).catch(() => {
                    commentsList.innerHTML = '<li class="faculty-comments-empty">Unable to load comments.</li>';
                    feedbackState.loadedComments = [];
                    setDeanCommentsAiSummary('Unable to load comments for summarization.', 'error');
                    renderDeanProfessorSemestralTrend(feedbackState.selectedProfessorUserId, selectedSemesterId);
                    commentsPanel.classList.add('active');
                });
            });
        });
    }

    populateSemesterFilter(selectedSemesterId);
    resetFeedbackPanelState(true);
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
        tbody.innerHTML = '<tr class="mobile-card-empty-row"><td colspan="10">No faculty records found.</td></tr>';
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
            '<td data-label="Employee ID">' + item.professorId + '</td>' +
            '<td data-label="Faculty Name">' + item.professorName + '</td>' +
            '<td data-label="Institute">' + item.institute + '</td>' +
            '<td data-label="Regular/Temporary">' + employmentType + '</td>' +
            '<td data-label="Position">' + position + '</td>' +
            '<td data-label="Evaluations Received"><span class="count-pill">' + item.received + '/' + item.required + '</span></td>' +
            '<td data-label="Response Rate">' + responseRate + '%</td>' +
            '<td data-label="Average Score">' + item.avgScore.toFixed(1) + '</td>' +
            '<td data-label="Status"><span class="dean-status-pill ' + statusClass + '">' + statusText + '</span></td>' +
            '<td data-label="Comments"><button type="button" class="btn-submit faculty-comments-btn js-view-comments" data-professor-id="' + item.professorId + '" data-professor-user-id="' + (item.professorUserId || '') + '">View</button></td>' +
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
    const professorUserToken = normalizeUserIdToken(query && query.professorUserId);
    const row = (summary.breakdownRows || []).find(item =>
        normalizeRoleToken(item && item.professorId) === professorToken
        || normalizeRoleToken(item && item.employeeId) === professorToken
    );
    if (row && row.rowKey && summary.commentBuckets && Array.isArray(summary.commentBuckets[row.rowKey])) {
        const bucket = summary.commentBuckets[row.rowKey].map(item => ({
            text: String(item && item.text || '').trim(),
            source: String(item && item.source || '').trim(),
            date: String(item && item.date || '').trim(),
            evaluatorStudentNumber: String(item && item.evaluatorStudentNumber || '').trim(),
            studentNumber: String(item && item.studentNumber || '').trim(),
            studentId: String(item && item.studentId || '').trim(),
            studentUserId: String(item && item.studentUserId || '').trim(),
            evaluatorId: String(item && item.evaluatorId || '').trim(),
            evaluatorUsername: String(item && item.evaluatorUsername || '').trim(),
            evaluatorName: String(item && item.evaluatorName || '').trim()
        })).filter(item => item.text);
        return Promise.resolve(bucket);
    }

    const context = buildDeanPanelContext();
    const semesterId = resolveSelectedSemesterId(query && query.semesterId || deanSummaryState.selectedSemesterId || context.currentSemester);
    let targetProfessorId = professorUserToken;
    if (!targetProfessorId) {
        const professorByEmployee = (context.scopedProfessors || []).find(professor =>
            normalizeRoleToken(professor && professor.employeeId) === professorToken
            || normalizeRoleToken(professor && professor.id) === professorToken
        );
        targetProfessorId = professorByEmployee ? normalizeUserIdToken(professorByEmployee.id) : '';
    }
    if (!targetProfessorId) return Promise.resolve([]);

    const comments = [];
    (context.evaluations || []).forEach(evaluation => {
        if (resolveEvaluationTypeToken(evaluation) !== sourceType) return;
        if (!isEvaluationInSemester(evaluation, semesterId)) return;
        if (resolveDeanTargetProfessorId(evaluation, sourceType, context) !== targetProfessorId) return;
        collectEvaluationComments(evaluation).forEach(text => {
            comments.push(buildDeanCommentRecord(
                evaluation,
                text,
                getDeanEvaluationTypeMeta(sourceType).label
            ));
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
        resultEl.textContent = 'Showing ' + label + ' under your ' + getSupervisorScopeDescriptor() + ' for ' + semesterLabel + '. Total: ' + total;
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
 * Setup dean program-based peer assignment management.
 */
function setupPeerManagementView() {
    const programSelect = document.getElementById('peerMgmtProgramSelect');
    const professorCountInput = document.getElementById('peerMgmtProfessorCount');
    const createRoomBtn = document.getElementById('peerMgmtCreateRoomBtn');
    const clearSelectionBtn = document.getElementById('peerMgmtClearSelectionBtn');
    const messageEl = document.getElementById('peerMgmtMessage');
    const programsTable = document.getElementById('peerMgmtProgramsTable');
    const selectedProgramDisplay = document.getElementById('peerMgmtSelectedProgramDisplay');
    const viewAssignmentsBtn = document.getElementById('peerMgmtViewAssignmentsBtn');
    const assignmentDetailsTable = document.getElementById('peerMgmtAssignmentDetailsTable');

    if (
        !programSelect || !professorCountInput || !createRoomBtn ||
        !clearSelectionBtn || !messageEl || !programsTable ||
        !selectedProgramDisplay || !viewAssignmentsBtn || !assignmentDetailsTable
    ) {
        return;
    }

    const scopedDepartment = getScopedDeanDepartment();
    let scopedPrograms = [];
    let programSummaryCache = [];
    let selectedProgramCode = '';

    function setMessage(text, type) {
        messageEl.textContent = text;
        messageEl.classList.remove('success', 'error', 'info');
        if (text) {
            messageEl.classList.add(type || 'info');
        }
    }

    function formatDateTime(value) {
        const raw = String(value || '').trim();
        if (!raw) return 'N/A';
        const formatted = SharedData.formatDateTimeInPhilippines(raw);
        return formatted || raw;
    }

    function normalizeProgramCode(value) {
        return String(value || '').trim().toUpperCase();
    }

    function getProgramLabel(program) {
        const code = normalizeProgramCode(program && program.programCode || program && program.program_code || '');
        const name = String(program && (program.programName || program.program_name) || '').trim();
        if (!code && !name) return 'No program selected';
        return name ? `${code} - ${name}` : code;
    }

    function setSelectedProgram(programCode) {
        const normalizedCode = normalizeProgramCode(programCode);
        selectedProgramCode = normalizedCode;
        if (!normalizedCode) {
            programSelect.value = '';
            selectedProgramDisplay.value = 'No program selected';
            viewAssignmentsBtn.disabled = true;
            return '';
        }

        const summaryEntry = programSummaryCache.find(program => normalizeProgramCode(program && program.programCode) === normalizedCode);
        const scopedEntry = scopedPrograms.find(program => normalizeProgramCode(program && program.programCode) === normalizedCode);
        if (summaryEntry || scopedEntry) {
            programSelect.value = normalizedCode;
        }
        selectedProgramDisplay.value = getProgramLabel(summaryEntry || scopedEntry || { programCode: normalizedCode });
        viewAssignmentsBtn.disabled = false;
        return normalizedCode;
    }

    function resolveProgramStatusClass(programSummary) {
        const status = String(programSummary && programSummary.status || '').trim().toLowerCase();
        if (status === 'generated') return 'good';
        if (status === 'submitted-locked') return 'active';
        if (status === 'not-generated') return 'inactive';
        return 'needs-attention';
    }

    function renderAssignmentDetails(payload) {
        const tbody = assignmentDetailsTable.querySelector('tbody');
        if (!tbody) return;

        const rows = Array.isArray(payload && payload.professors) ? payload.professors : [];
        const emptyMessage = String(payload && payload.emptyMessage || '').trim();
        if (!rows.length) {
            tbody.innerHTML = `<tr><td colspan="5">${escapeHTML(emptyMessage || 'No peer assignment details available for this program.')}</td></tr>`;
            return;
        }

        tbody.innerHTML = rows.map(professor => {
            const willEvaluate = Array.isArray(professor && professor.willEvaluate) ? professor.willEvaluate : [];
            const willBeEvaluatedBy = Array.isArray(professor && professor.willBeEvaluatedBy) ? professor.willBeEvaluatedBy : [];
            const outgoingLabel = willEvaluate.length
                ? willEvaluate.map(item => escapeHTML(String(item && item.name || 'Professor'))).join('<br>')
                : '<span class="table-empty-text">None</span>';
            const incomingLabel = willBeEvaluatedBy.length
                ? willBeEvaluatedBy.map(item => escapeHTML(String(item && item.name || 'Professor'))).join('<br>')
                : '<span class="table-empty-text">None</span>';
            return (
                '<tr>' +
                '<td>' + escapeHTML(professor && professor.name || 'N/A') + '</td>' +
                '<td>' + outgoingLabel + '</td>' +
                '<td>' + incomingLabel + '</td>' +
                '<td>' + String(Number(professor && professor.pendingCount || 0)) + '</td>' +
                '<td>' + String(Number(professor && professor.submittedCount || 0)) + '</td>' +
                '</tr>'
            );
        }).join('');
    }

    function renderPrograms() {
        const allPrograms = (SharedData.getPrograms && SharedData.getPrograms()) || [];
        const scopedProgramCode = getScopedDeanProgramCode();
        scopedPrograms = (Array.isArray(allPrograms) ? allPrograms : [])
            .filter(program => {
                const dept = String(program && program.departmentCode || '').trim().toUpperCase();
                if (!scopedDepartment || dept !== scopedDepartment) return false;
                if (isProgramScopedSupervisorPanel()) {
                    const programCode = String(program && program.programCode || '').trim().toUpperCase();
                    return !!scopedProgramCode && programCode === scopedProgramCode;
                }
                return true;
            })
            .sort((a, b) => String(a && a.programCode || '').localeCompare(String(b && b.programCode || '')));

        if (!scopedPrograms.length) {
            programSelect.innerHTML = '<option value="">No programs available in your scope</option>';
            createRoomBtn.disabled = true;
            viewAssignmentsBtn.disabled = true;
            return;
        }

        createRoomBtn.disabled = false;
        programSelect.innerHTML = '<option value="">Select program</option>' +
            scopedPrograms.map(program =>
                `<option value="${escapeHTML(String(program.programCode || ''))}">${escapeHTML(String(program.programCode || ''))} - ${escapeHTML(String(program.programName || ''))}</option>`
            ).join('');
        if (isProgramScopedSupervisorPanel() && scopedPrograms.length === 1) {
            programSelect.value = String(scopedPrograms[0].programCode || '');
        }
    }

    function renderProgramsTable(payload) {
        const tbody = programsTable.querySelector('tbody');
        if (!tbody) return;

        const rows = Array.isArray(payload && payload.programs) ? payload.programs : [];
        programSummaryCache = rows.slice();
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="6">No peer assignment data available for the current semester.</td></tr>';
            setSelectedProgram('');
            return;
        }

        tbody.innerHTML = rows.map(room =>
            '<tr>' +
            '<td>' + escapeHTML((room.programCode || 'N/A') + (room.programName ? (' - ' + room.programName) : '')) + '</td>' +
            '<td>' + String(room.professorCount || 0) + '</td>' +
            '<td>' + String(room.requestedPeerCount || 0) + '</td>' +
            '<td>' + String(room.actualPeerCount || 0) + '</td>' +
            '<td><span class="dean-status-pill ' + resolveProgramStatusClass(room) + '">' + escapeHTML(String(room.statusMessage || '')) + '</span></td>' +
            '<td>' +
            '<div class="peer-mgmt-row-actions">' +
            '<button type="button" class="btn-cancel peer-mgmt-program-view-btn" data-program-code="' + escapeHTML(String(room.programCode || '')) + '">View</button>' +
            '</div>' +
            '</td>' +
            '</tr>'
        ).join('');
    }

    function loadProgramSummaries(options = {}) {
        const silent = !!(options && options.silent);
        const preferredProgramCode = normalizeProgramCode(options && options.preferredProgramCode);
        try {
            const response = SharedData[getSupervisorPeerMethodName('list')]({});
            renderProgramsTable(response || {});
            const selectedCode = preferredProgramCode || normalizeProgramCode(programSelect.value || selectedProgramCode);
            if (selectedCode) {
                setSelectedProgram(selectedCode);
                loadAssignmentDetails(selectedCode, { silent: true });
            } else {
                renderAssignmentDetails({
                    emptyMessage: 'Select a program to view assignment details.'
                });
            }
            if (!silent) {
                const semesterLabel = String(response && response.currentSemester || '').trim();
                if (semesterLabel) {
                    setMessage('Loaded current semester peer assignments: ' + semesterLabel + '.', 'info');
                } else {
                    setMessage('No current semester is configured yet.', 'error');
                }
            }
        } catch (error) {
            renderProgramsTable({ programs: [] });
            renderAssignmentDetails({
                emptyMessage: 'Unable to load peer assignment details.'
            });
            if (!silent) {
                setMessage(String(error && error.message || 'Unable to load peer assignments.'), 'error');
            }
        }
    }

    function loadAssignmentDetails(programCode, options = {}) {
        const silent = !!(options && options.silent);
        const normalizedCode = normalizeProgramCode(programCode || selectedProgramCode || programSelect.value);
        if (!normalizedCode) {
            setSelectedProgram('');
            renderAssignmentDetails({
                emptyMessage: 'Select a program to view assignment details.'
            });
            return;
        }

        try {
            const response = SharedData[getSupervisorPeerMethodName('details')]({
                programCode: normalizedCode
            });
            setSelectedProgram(normalizedCode);
            renderAssignmentDetails(response || {});
            if (!silent) {
                const summary = response && response.summary ? response.summary : null;
                const type = summary && summary.status === 'generated' ? 'success' : 'info';
                setMessage(String(summary && summary.statusMessage || 'Loaded peer assignment details.'), type);
            }
        } catch (error) {
            renderAssignmentDetails({
                emptyMessage: 'Unable to load peer assignment details for the selected program.'
            });
            if (!silent) {
                setMessage(String(error && error.message || 'Unable to load peer assignment details.'), 'error');
            }
        }
    }

    function clearInputs() {
        if (scopedPrograms.length > 0) {
            programSelect.value = '';
        }
        professorCountInput.value = '5';
    }

    createRoomBtn.addEventListener('click', function () {
        const programCode = normalizeProgramCode(programSelect.value);
        const peerCount = parseInt(String(professorCountInput.value || '').trim(), 10);
        if (!programCode) {
            setMessage('Program selection is required.', 'error');
            return;
        }
        if (!Number.isFinite(peerCount) || peerCount < 1) {
            setMessage('Peer count must be at least 1.', 'error');
            return;
        }

        createRoomBtn.disabled = true;
        try {
            const response = SharedData[getSupervisorPeerMethodName('generate')]({
                programCode,
                peerCount
            });
            const summary = response && response.summary ? response.summary : null;
            const type = summary && summary.status === 'generated' ? 'success' : 'info';
            setMessage(String(summary && summary.statusMessage || 'Peer assignments generated.'), type);
            setSelectedProgram(programCode);
            loadProgramSummaries({ silent: true, preferredProgramCode: programCode });
        } catch (error) {
            setMessage(String(error && error.message || 'Failed to generate peer assignments.'), 'error');
        } finally {
            createRoomBtn.disabled = false;
        }
    });

    clearSelectionBtn.addEventListener('click', function () {
        clearInputs();
        setSelectedProgram('');
        renderAssignmentDetails({
            emptyMessage: 'Select a program to view assignment details.'
        });
        setMessage('', 'info');
    });

    viewAssignmentsBtn.addEventListener('click', function () {
        const programCode = normalizeProgramCode(selectedProgramCode || programSelect.value);
        if (!programCode) {
            setMessage('Select a program first.', 'error');
            return;
        }
        loadAssignmentDetails(programCode);
    });

    programSelect.addEventListener('change', function () {
        const programCode = normalizeProgramCode(this.value);
        if (!programCode) {
            setSelectedProgram('');
            renderAssignmentDetails({
                emptyMessage: 'Select a program to view assignment details.'
            });
            return;
        }
        setSelectedProgram(programCode);
        loadAssignmentDetails(programCode, { silent: true });
    });

    programsTable.addEventListener('click', function (event) {
        const target = event.target;
        if (!(target instanceof HTMLElement)) return;
        const viewBtn = target.closest('.peer-mgmt-program-view-btn');
        if (!viewBtn) return;

        const programCode = normalizeProgramCode(viewBtn.getAttribute('data-program-code'));
        if (!programCode) return;
        setSelectedProgram(programCode);
        loadAssignmentDetails(programCode);
    });

    renderPrograms();
    renderAssignmentDetails({
        emptyMessage: 'Select a program to view assignment details.'
    });
    loadProgramSummaries();
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
        handleViewDetails,
        updateSummaryCards
    };
}
