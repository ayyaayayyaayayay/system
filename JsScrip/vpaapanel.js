const criteriaKeys = [
    "Teaching Effectiveness",
    "Clarity of Instruction",
    "Assessment Fairness",
    "Student Engagement",
    "Professionalism"
];

let currentSemesterLabel = SharedData.getCurrentSemester() || "";
let allProfessorData = [];
let availableSemesterLabels = [];
let hasSubmittedSearch = false;
let vpaaChartDataByType = {
    student: createEmptyChartData(),
    professor: createEmptyChartData(),
    supervisor: createEmptyChartData()
};
let vpaaMobileDrawerBound = false;
let selectedVpaaAnalyticsDepartment = "";
let selectedVpaaAnalyticsCampus = "all";
let latestVpaaAnalyticsSnapshot = null;
let currentVpaaAnalyticsSemester = "all";
let currentVpaaAnalyticsEvaluationType = "student";
let currentVpaaAnalyticsProfessorId = null;

const VPAA_EVALUATION_TYPE_OPTIONS = [
    {
        id: "student",
        label: "Student Evaluation",
        unitLabel: "Students",
        totalLabel: "Total Students",
        statusTitle: "Student Evaluation Status",
        icon: "fas fa-user-graduate",
        feedbackIcon: "fas fa-user-graduate"
    },
    {
        id: "professor",
        label: "Peer Evaluation",
        unitLabel: "Peers",
        totalLabel: "Total Peers",
        statusTitle: "Peer Evaluation Status",
        icon: "fas fa-users",
        feedbackIcon: "fas fa-users"
    },
    {
        id: "supervisor",
        label: "Supervisor Evaluation",
        unitLabel: "Supervisors",
        totalLabel: "Total Supervisors",
        statusTitle: "Supervisor Evaluation Status",
        icon: "fas fa-user-tie",
        feedbackIcon: "fas fa-user-tie"
    }
];

function createEmptyChartData(categoriesInput) {
    const categories = Array.isArray(categoriesInput) && categoriesInput.length
        ? categoriesInput
        : criteriaKeys;
    return {
        categoryScores: categories.map(function (category) { return { category: category, score: 0 }; }),
        ratingDistribution: { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 },
        averageRating: 0,
        totalEvaluations: 0,
        evaluatedCount: 0
    };
}

function getQuestionnaireTypeForEvalType(typeKey) {
    if (typeKey === "professor") return "professor-to-professor";
    if (typeKey === "supervisor") return "supervisor-to-professor";
    return "student-to-professor";
}

function buildVpaaQuestionMeta(typeKey, semesterLabel) {
    const questionnaires = (SharedData.getQuestionnaires && SharedData.getQuestionnaires()) || {};
    const desiredSemester = String(semesterLabel || currentSemesterLabel || "").trim();
    const semesterKeys = Object.keys(questionnaires || {});

    let bucket = {};
    if (desiredSemester && questionnaires[desiredSemester]) {
        bucket = questionnaires[desiredSemester] || {};
    } else if (semesterKeys.length) {
        const latestKey = semesterKeys.slice().sort().reverse()[0];
        bucket = questionnaires[latestKey] || {};
    }

    const questionnaireType = getQuestionnaireTypeForEvalType(typeKey);
    const sectionBucket = bucket[questionnaireType] || { sections: [], questions: [] };
    const sections = Array.isArray(sectionBucket.sections) ? sectionBucket.sections : [];
    const questions = Array.isArray(sectionBucket.questions) ? sectionBucket.questions : [];

    const categoryByQuestionId = {};
    const categoryOrder = [];
    const sectionTitleById = {};

    sections.forEach(function (section) {
        const sectionId = String(section && section.id || "").trim();
        const title = String(section && (section.title || section.letter) || "").trim();
        if (!sectionId || !title) return;
        sectionTitleById[sectionId] = title;
        if (!categoryOrder.includes(title)) {
            categoryOrder.push(title);
        }
    });

    questions.forEach(function (question) {
        const questionId = String(question && question.id || "").trim();
        if (!questionId) return;
        const sectionId = String(question && question.sectionId || "").trim();
        const category = sectionTitleById[sectionId] || "General Questions";
        categoryByQuestionId[questionId] = category;
        categoryByQuestionId[questionId.toLowerCase()] = category;
        if (!categoryOrder.includes(category)) {
            categoryOrder.push(category);
        }
    });

    return {
        categoryByQuestionId: categoryByQuestionId,
        categoryOrder: categoryOrder
    };
}

function normalizeVpaaToken(value) {
    return String(value || "").trim().toLowerCase();
}

function normalizeVpaaUserId(value) {
    const raw = String(value || "").trim();
    if (!raw) return "";
    const matchPrefixed = raw.match(/^u(\d+)$/i);
    if (matchPrefixed) return "u" + String(parseInt(matchPrefixed[1], 10));
    if (/^\d+$/.test(raw)) return "u" + String(parseInt(raw, 10));
    return raw;
}

function resolveVpaaEvaluationType(evaluation) {
    const token = normalizeVpaaToken(evaluation && (evaluation.evaluatorRole || evaluation.evaluationType));
    if (token === "student" || token === "student-to-professor" || token === "student-professor") return "student";
    if (token === "peer" || token === "professor" || token === "professor-to-professor" || token === "professor-professor") return "professor";
    if (token === "supervisor" || token === "dean" || token === "procoor" || token === "hr" || token === "vpaa" || token === "admin" || token === "supervisor-to-professor" || token === "supervisor-professor") return "supervisor";
    return "";
}

function normalizeSemesterLabel(value) {
    return String(value || "").trim().toLowerCase();
}

function isVpaaEvaluationInSemester(evaluation, semesterLabel) {
    const selected = normalizeSemesterLabel(semesterLabel);
    if (!selected || selected === "all") return true;
    const evalSemester = normalizeSemesterLabel(evaluation && evaluation.semesterId);
    if (!evalSemester) return selected === normalizeSemesterLabel(currentSemesterLabel);
    return evalSemester === selected;
}

function getEvaluationNumericRatings(evaluation) {
    const ratings = evaluation && typeof evaluation.ratings === "object" && evaluation.ratings ? evaluation.ratings : {};
    const values = [];
    Object.keys(ratings).forEach(function (key) {
        const parsed = parseFloat(ratings[key]);
        if (Number.isFinite(parsed)) {
            values.push(Math.max(1, Math.min(5, parsed)));
        }
    });
    return values;
}

function collectEvaluationComments(evaluation) {
    const output = [];
    const addText = function (value) {
        const text = String(value || "").trim();
        if (text) output.push(text);
    };

    addText(evaluation && evaluation.comments);
    addText(evaluation && evaluation.comment);
    addText(evaluation && evaluation.feedback);

    const qualitativeResponses = evaluation && evaluation.qualitativeResponses;
    if (Array.isArray(qualitativeResponses)) {
        qualitativeResponses.forEach(function (item) {
            if (typeof item === "string") {
                addText(item);
                return;
            }
            if (item && typeof item === "object") {
                addText(item.text || item.answer || item.comment || item.response);
            }
        });
    }

    const qualitative = evaluation && evaluation.qualitative;
    if (qualitative && typeof qualitative === "object") {
        Object.keys(qualitative).forEach(function (key) {
            addText(qualitative[key]);
        });
    }

    return output;
}

function buildVpaaDatabaseContext() {
    const users = (SharedData.getUsers && SharedData.getUsers()) || [];
    const evaluations = (SharedData.getEvaluations && SharedData.getEvaluations()) || [];
    const semesterList = (SharedData.getSemesterList && SharedData.getSemesterList()) || [];
    const subjectManagement = SharedData.getSubjectManagement
        ? SharedData.getSubjectManagement()
        : { offerings: [], enrollments: [] };

    const professors = users.filter(function (user) {
        return normalizeVpaaToken(user && user.role) === "professor";
    });

    const professorById = {};
    const professorByEmployeeId = {};
    const professorByName = {};

    professors.forEach(function (professor) {
        const idToken = normalizeVpaaUserId(professor && professor.id);
        if (idToken) professorById[idToken] = professor;

        const employeeToken = normalizeVpaaToken(professor && professor.employeeId);
        if (employeeToken && !professorByEmployeeId[employeeToken]) {
            professorByEmployeeId[employeeToken] = professor;
        }

        const nameToken = normalizeVpaaToken(professor && professor.name);
        if (nameToken && !professorByName[nameToken]) {
            professorByName[nameToken] = professor;
        }
    });

    const offerings = Array.isArray(subjectManagement && subjectManagement.offerings)
        ? subjectManagement.offerings
        : [];
    const enrollments = Array.isArray(subjectManagement && subjectManagement.enrollments)
        ? subjectManagement.enrollments
        : [];

    const offeringsById = {};
    offerings.forEach(function (offering) {
        const offeringId = String(offering && offering.id || "").trim();
        if (offeringId) offeringsById[offeringId] = offering;
    });

    return {
        users: Array.isArray(users) ? users : [],
        evaluations: Array.isArray(evaluations) ? evaluations : [],
        semesterList: Array.isArray(semesterList) ? semesterList : [],
        currentSemester: String((SharedData.getCurrentSemester && SharedData.getCurrentSemester()) || "").trim(),
        professors: professors,
        professorById: professorById,
        professorByEmployeeId: professorByEmployeeId,
        professorByName: professorByName,
        offerings: offerings,
        offeringsById: offeringsById,
        enrollments: enrollments
    };
}

function resolveTargetProfessorIdFromEvaluation(evaluation, evaluationType, context) {
    if (evaluationType === "student") {
        const offeringId = String(evaluation && evaluation.courseOfferingId || "").trim();
        const offering = offeringId ? context.offeringsById[offeringId] : null;
        if (offering) {
            const professorIdFromOffering = normalizeVpaaUserId(offering.professorUserId);
            if (professorIdFromOffering && context.professorById[professorIdFromOffering]) {
                return professorIdFromOffering;
            }
        }
    }

    const candidateIds = [
        evaluation && evaluation.targetProfessorId,
        evaluation && evaluation.targetId,
        evaluation && evaluation.colleagueId,
        evaluation && evaluation.professorId,
        evaluation && evaluation.evaluateeUserId
    ];

    for (let index = 0; index < candidateIds.length; index += 1) {
        const token = normalizeVpaaUserId(candidateIds[index]);
        if (token && context.professorById[token]) return token;
    }

    const employeeToken = normalizeVpaaToken(evaluation && evaluation.targetProfessorEmployeeId);
    if (employeeToken && context.professorByEmployeeId[employeeToken]) {
        return normalizeVpaaUserId(context.professorByEmployeeId[employeeToken].id);
    }

    const textCandidates = [
        evaluation && evaluation.targetProfessor,
        evaluation && evaluation.targetName,
        evaluation && evaluation.professorSubject
    ];

    for (let idx = 0; idx < textCandidates.length; idx += 1) {
        const rawText = String(textCandidates[idx] || "").trim();
        if (!rawText) continue;
        const byName = normalizeVpaaToken(rawText.split(" - ")[0]);
        if (byName && context.professorByName[byName]) {
            return normalizeVpaaUserId(context.professorByName[byName].id);
        }
    }

    return "";
}

function normalizeVpaaProfessorUserId(value) {
    const raw = String(value || "").trim();
    if (!raw) return "";
    const base = raw.includes("|") ? raw.split("|")[0] : raw;
    return normalizeVpaaUserId(base);
}

function getVpaaApplicableSupervisorUsersForProfessor(context, professorId) {
    const professorToken = normalizeVpaaProfessorUserId(professorId);
    const professor = (context.professors || []).find(function (user) {
        return normalizeVpaaProfessorUserId(user && user.id) === professorToken;
    });
    if (!professor) return [];

    const campus = normalizeVpaaToken(professor.campus || professor.campusSlug);
    const department = normalizeVpaaToken(professor.department || professor.institute);
    const program = normalizeVpaaToken(professor.programCode || professor.program);
    const activeSupervisors = (context.users || []).filter(function (user) {
        if (normalizeVpaaToken(user && user.status || "active") === "inactive") return false;
        const role = normalizeVpaaToken(user && user.role);
        return role === "procoor" || role === "dean" || role === "supervisor";
    });

    const isSameCampusDepartment = function (user) {
        const userCampus = normalizeVpaaToken(user && (user.campus || user.campusSlug));
        const userDepartment = normalizeVpaaToken(user && (user.department || user.institute));
        const campusMatches = !campus || !userCampus || userCampus === campus;
        return campusMatches && department && userDepartment === department;
    };

    const programCoordinators = activeSupervisors.filter(function (user) {
        if (normalizeVpaaToken(user && user.role) !== "procoor") return false;
        if (!isSameCampusDepartment(user)) return false;
        const userProgram = normalizeVpaaToken(user && (user.programCode || user.program));
        return program && userProgram === program;
    });
    if (programCoordinators.length > 0) return programCoordinators;

    const departmentDeans = activeSupervisors.filter(function (user) {
        return normalizeVpaaToken(user && user.role) === "dean" && isSameCampusDepartment(user);
    });
    if (departmentDeans.length > 0) return departmentDeans;

    return activeSupervisors.filter(function (user) {
        return normalizeVpaaToken(user && user.role) === "supervisor" && isSameCampusDepartment(user);
    });
}

function buildVpaaChartDataForType(typeKey, semesterLabel, context) {
    const questionMeta = buildVpaaQuestionMeta(typeKey, semesterLabel);
    const baseCategories = questionMeta.categoryOrder.length ? questionMeta.categoryOrder.slice() : criteriaKeys.slice();
    const result = createEmptyChartData(baseCategories);
    const categoryTotals = {};
    baseCategories.forEach(function (category) {
        categoryTotals[category] = { sum: 0, count: 0 };
    });
    const categoryOrder = baseCategories.slice();
    const targetedProfessors = new Set();

    (context.evaluations || []).forEach(function (evaluation) {
        const evalType = resolveVpaaEvaluationType(evaluation);
        if (evalType !== typeKey) return;
        if (!isVpaaEvaluationInSemester(evaluation, semesterLabel)) return;

        const targetProfessorId = resolveTargetProfessorIdFromEvaluation(evaluation, typeKey, context);
        if (!targetProfessorId) return;

        const ratings = getEvaluationNumericRatings(evaluation);
        if (!ratings.length) return;

        targetedProfessors.add(targetProfessorId);
        result.totalEvaluations += 1;

        const ratingMap = evaluation && typeof evaluation.ratings === "object" && evaluation.ratings ? evaluation.ratings : {};
        const ratingKeys = Object.keys(ratingMap);

        ratings.forEach(function (value, index) {
            const questionId = String(ratingKeys[index] || "").trim();
            const mappedCategory = questionMeta.categoryByQuestionId[questionId]
                || questionMeta.categoryByQuestionId[questionId.toLowerCase()]
                || "";
            const fallbackCategory = baseCategories[Math.min(index, baseCategories.length - 1)] || "General Questions";
            const category = mappedCategory || fallbackCategory;

            if (!categoryTotals[category]) {
                categoryTotals[category] = { sum: 0, count: 0 };
                categoryOrder.push(category);
            }

            categoryTotals[category].sum += value;
            categoryTotals[category].count += 1;
        });

        const average = ratings.reduce(function (sum, value) { return sum + value; }, 0) / ratings.length;
        const rounded = Math.max(1, Math.min(5, Math.round(average)));
        result.ratingDistribution[rounded] += 1;
    });

    result.categoryScores = categoryOrder.map(function (category) {
        const bucket = categoryTotals[category] || { sum: 0, count: 0 };
        const score = bucket.count ? (bucket.sum / bucket.count) : 0;
        return { category: category, score: Number(score.toFixed(2)) };
    });

    const weightedTotal = Object.keys(result.ratingDistribution).reduce(function (sum, key) {
        return sum + (Number(key) * Number(result.ratingDistribution[key] || 0));
    }, 0);
    const distTotal = Object.values(result.ratingDistribution).reduce(function (sum, count) {
        return sum + Number(count || 0);
    }, 0);

    result.averageRating = distTotal ? (weightedTotal / distTotal) : 0;
    result.evaluatedCount = targetedProfessors.size;

    return result;
}

function buildProfessorDataFromSharedData() {
    const context = buildVpaaDatabaseContext();
    const currentSemester = context.currentSemester || currentSemesterLabel || "Current Semester";
    currentSemesterLabel = currentSemester;

    const semesterSet = new Set();
    if (currentSemester) semesterSet.add(currentSemester);

    (context.semesterList || []).forEach(function (item) {
        const value = String(item && (item.value || item.id || item.slug || item.label) || "").trim();
        if (value) semesterSet.add(value);
    });

    (context.evaluations || []).forEach(function (evaluation) {
        const semester = String(evaluation && evaluation.semesterId || "").trim();
        if (semester) semesterSet.add(semester);
    });

    const semesters = Array.from(semesterSet);
    if (currentSemester) {
        semesters.sort(function (a, b) {
            if (a === currentSemester) return -1;
            if (b === currentSemester) return 1;
            return b.localeCompare(a);
        });
    }

    const activeProfessorCount = context.professors.filter(function (prof) {
        return normalizeVpaaToken(prof && prof.status || "active") !== "inactive";
    }).length;

    const resultRows = [];

    context.professors.forEach(function (professor, index) {
        const professorId = normalizeVpaaUserId(professor && professor.id);
        if (!professorId) return;

        const professorOfferings = (context.offerings || []).filter(function (offering) {
            return normalizeVpaaUserId(offering && offering.professorUserId) === professorId;
        });

        semesters.forEach(function (semesterLabel) {
            const semesterToken = normalizeSemesterLabel(semesterLabel);
            const semesterOfferings = professorOfferings.filter(function (offering) {
                const offeringSemester = normalizeSemesterLabel(offering && offering.semesterSlug);
                if (!offeringSemester) return semesterToken === normalizeSemesterLabel(currentSemester);
                return offeringSemester === semesterToken;
            });

            const offeringIdSet = new Set(semesterOfferings.map(function (offering) {
                return String(offering && offering.id || "").trim();
            }).filter(Boolean));

            const requiredStudentRaters = (context.enrollments || []).filter(function (enrollment) {
                const offeringId = String(enrollment && enrollment.courseOfferingId || "").trim();
                if (!offeringIdSet.has(offeringId)) return false;
                const status = normalizeVpaaToken(enrollment && enrollment.status || "enrolled");
                return status !== "inactive" && status !== "dropped";
            }).length;

            const studentEvals = [];
            const peerEvals = [];
            const supervisorEvals = [];

            (context.evaluations || []).forEach(function (evaluation) {
                const evalType = resolveVpaaEvaluationType(evaluation);
                if (!evalType) return;
                if (!isVpaaEvaluationInSemester(evaluation, semesterLabel)) return;

                const targetProfessorId = resolveTargetProfessorIdFromEvaluation(evaluation, evalType, context);
                if (targetProfessorId !== professorId) return;

                if (evalType === "student") studentEvals.push(evaluation);
                if (evalType === "professor") peerEvals.push(evaluation);
                if (evalType === "supervisor") supervisorEvals.push(evaluation);
            });

            const allEvals = studentEvals.concat(peerEvals, supervisorEvals);
            const allRatingValues = allEvals.flatMap(getEvaluationNumericRatings);
            const overall = allRatingValues.length
                ? allRatingValues.reduce(function (sum, value) { return sum + value; }, 0) / allRatingValues.length
                : 0;

            const analyticsByType = {
                student: buildProfessorAnalyticsForType("student", studentEvals, semesterLabel),
                supervisor: buildProfessorAnalyticsForType("supervisor", supervisorEvals, semesterLabel),
                professor: buildProfessorAnalyticsForType("professor", peerEvals, semesterLabel)
            };

            const supervisorCount = getVpaaApplicableSupervisorUsersForProfessor(context, professorId).length || 1;
            const totalRequired = requiredStudentRaters + Math.max(activeProfessorCount - 1, 0) + supervisorCount;
            const totalReceived = allEvals.length;
            const responseRate = totalRequired > 0 ? Math.round((totalReceived / totalRequired) * 100) : 0;

            resultRows.push({
                id: (professor.id || ("prof-" + (index + 1))) + "|" + semesterLabel,
                userId: professorId,
                employeeId: String(professor.employeeId || ("FAC-" + (10000 + index))).trim(),
                name: String(professor.name || ("Professor " + (index + 1))).trim(),
                campus: String(professor.campus || "").trim(),
                program: String(professor.programCode || professor.program || "").trim(),
                programCode: String(professor.programCode || professor.program || "").trim(),
                email: String(professor.email || "").trim(),
                isActive: normalizeVpaaToken(professor.status || "active") !== "inactive",
                department: String(professor.department || professor.institute || "General").trim(),
                rank: String(professor.position || "Instructor").trim(),
                photoData: String(professor.photoData || "").trim(),
                semester: semesterLabel,
                overall: parseFloat(overall.toFixed(1)),
                responseRate: responseRate,
                evaluations: totalReceived,
                students: requiredStudentRaters,
                subjects: semesterOfferings.map(function (offering) {
                    const code = String(offering && offering.subjectCode || "").trim();
                    const name = String(offering && offering.subjectName || "").trim();
                    if (code && name) return code + " - " + name;
                    return code || name;
                }).filter(Boolean),
                analyticsByType: analyticsByType,
                trend: [Math.max(0, overall - 0.3), Math.max(0, overall - 0.2), Math.max(0, overall - 0.1), overall, Math.min(5, overall + 0.1)].map(function (value) {
                    return parseFloat(value.toFixed(1));
                }),
                studentComments: studentEvals.flatMap(collectEvaluationComments),
                peerComments: peerEvals.flatMap(collectEvaluationComments),
                supervisorComments: supervisorEvals.flatMap(collectEvaluationComments)
            });
        });
    });

    const selectedSemester = currentSemester || (semesters[0] || "");

    return {
        currentSemester: selectedSemester,
        semesters: semesters,
        professorData: resultRows,
        chartDataByType: {
            student: buildVpaaChartDataForType("student", selectedSemester, context),
            professor: buildVpaaChartDataForType("professor", selectedSemester, context),
            supervisor: buildVpaaChartDataForType("supervisor", selectedSemester, context)
        }
    };
}

function loadDashboardDataFromDb() {
    const payload = buildProfessorDataFromSharedData();
    allProfessorData = Array.isArray(payload.professorData) ? payload.professorData : [];
    currentSemesterLabel = payload.currentSemester || currentSemesterLabel || "";
    availableSemesterLabels = Array.isArray(payload.semesters) ? payload.semesters : [];
    vpaaChartDataByType = payload.chartDataByType || {
        student: createEmptyChartData(),
        professor: createEmptyChartData(),
        supervisor: createEmptyChartData()
    };
}
const exampleWordFrequency = [];

const WORD_FREQUENCY_STOP_WORDS = new Set([
    "the", "and", "for", "that", "this", "with", "from", "have", "has", "had",
    "are", "was", "were", "will", "would", "should", "could", "can", "may",
    "you", "your", "yours", "they", "them", "their", "theirs", "our", "ours",
    "his", "her", "hers", "its", "it's", "who", "whom", "what", "when", "where",
    "why", "how", "too", "very", "much", "more", "most", "some", "many", "few",
    "all", "any", "not", "but", "because", "about", "into", "over", "under",
    "also", "just", "than", "then", "there", "here", "after", "before", "during",
    "while", "each", "every", "both", "either", "neither", "within", "without",
    "professor", "teacher", "class", "classes", "subject", "students", "student",
    "sir", "maam", "mam", "miss", "mrs", "mr"
]);



const elements = {
    totalStudents: document.getElementById("totalStudents"),
    completionRate: document.getElementById("completionRate"),
    pendingEvaluations: document.getElementById("pendingEvaluations"),
    activeProfessors: document.getElementById("activeProfessors"),
    wordFrequencyPositive: document.getElementById("wordFrequencyPositive"),
    wordFrequencyNegative: document.getElementById("wordFrequencyNegative"),
    searchInput: document.getElementById("searchInput"),
    searchBtn: document.getElementById("searchBtn"),
    semesterFilter: document.getElementById("semesterFilter"),
    campusFilter: document.getElementById("campusFilter"),
    departmentFilter: document.getElementById("departmentFilter"),
    sortFilter: document.getElementById("sortFilter"),
    resetFilters: document.getElementById("resetFilters"),
    overallSasrBtn: document.getElementById("vpaa-overall-sasr-btn"),
    keyHighlightsGrid: document.getElementById("vpaaKeyHighlightsGrid"),
    highlightsEmpty: document.getElementById("vpaaHighlightsEmpty"),
    highlightTopRating: document.getElementById("vpaaHighlightTopRating"),
    highlightMostComments: document.getElementById("vpaaHighlightMostComments"),
    highlightNeedsAttention: document.getElementById("vpaaHighlightNeedsAttention"),
    analyticsCampusSelect: document.getElementById("vpaaAnalyticsCampusSelect"),
    departmentAnalyticsBody: document.getElementById("vpaaDepartmentAnalyticsBody"),
    departmentProgramsBody: document.getElementById("vpaaDepartmentProgramsBody"),
    departmentProgramsTitle: document.getElementById("vpaaDepartmentProgramsTitle"),
    departmentProgramsEmpty: document.getElementById("vpaaDepartmentProgramsEmpty"),
    analyticsEmptyState: document.getElementById("vpaaAnalyticsEmptyState"),
    professorGrid: document.getElementById("professorGrid"),
    reportModal: document.getElementById("professor-analytics-modal"),
    reportModalClose: document.getElementById("close-analytics-modal"),
    reportModalBody: document.getElementById("professor-analytics-content"),
    reportModalTitle: document.getElementById("vpaaProfessorAnalyticsTitle")
};

const dashboardCharts = {
    student: { bar: null, pie: null },
    professor: { bar: null, pie: null },
    supervisor: { bar: null, pie: null }
};

function init() {
    if (!checkAuthentication()) {
        window.location.href = 'mainpage.html';
        return;
    }
    loadDashboardDataFromDb();
    setupNavigation();
    setupLogout();
    setupMobileDrawer();
    setupDashboardHeroActions();
    populateDepartments();
    populateSemesters();
    populateCampuses();
    setupReportModalEvents();
    applyFilters();
    setupVpaaAnalyticsInteractions();
    refreshVpaaDescriptiveAnalytics();
    setupDataSubscriptions();
    bindEvents();
    setupProfilePhotoUpload();
    setupProfileActions();
    setupChangeEmailForm();
    setupChangePasswordForm();
    setupPasswordToggles();
    showVpaaLoginAnnouncements();
}

function showVpaaLoginAnnouncements() {
    if (!SharedData.showUnreadAnnouncementLoginPopup) return;
    SharedData.showUnreadAnnouncementLoginPopup();
}

function setupNavigation() {
    const navLinks = document.querySelectorAll(".sidebar-nav .nav-link[data-view]");
    const contentViews = document.querySelectorAll(".content-view");

    if (!navLinks.length || !contentViews.length) return;

    navLinks.forEach((link) => {
        link.addEventListener("click", (event) => {
            event.preventDefault();
            const targetId = link.dataset.view;
            if (!targetId) return;

            contentViews.forEach((view) => {
                view.classList.toggle("active", view.id === targetId);
            });

            navLinks.forEach((nav) => nav.classList.remove("active"));
            link.classList.add("active");
            closeMobileDrawer();

            if (targetId !== "reports-view") {
                closeReportModal();
            }
        });
    });
}

function setupLogout() {
    const logoutBtn = document.getElementById("vpaaLogoutBtn");
    if (!logoutBtn) return;

    logoutBtn.addEventListener("click", function (event) {
        event.preventDefault();
        SharedData.clearSession();
        window.location.href = "mainpage.html";
    });
}

function setupDataSubscriptions() {
    if (!SharedData.onDataChange || !SharedData.KEYS) return;

    SharedData.onDataChange(function (key) {
        if (
            key === SharedData.KEYS.EVALUATIONS ||
            key === SharedData.KEYS.SUBJECT_MANAGEMENT ||
            key === SharedData.KEYS.CURRENT_SEMESTER ||
            key === SharedData.KEYS.USERS ||
            key === SharedData.KEYS.SEMESTER_LIST ||
            key === SharedData.KEYS.CAMPUSES ||
            key === SharedData.KEYS.QUESTIONNAIRES ||
            key === SharedData.KEYS.FACULTY_PAPERS
        ) {
            const previousSemester = elements.semesterFilter ? elements.semesterFilter.value : "";
            const previousCampus = elements.campusFilter ? elements.campusFilter.value : "";
            const previousDepartment = elements.departmentFilter ? elements.departmentFilter.value : "";
            const previousSort = elements.sortFilter ? elements.sortFilter.value : "";

            loadDashboardDataFromDb();
            populateDepartments();
            populateSemesters();
            populateCampuses();

            restoreSelectValue(elements.semesterFilter, previousSemester);
            restoreSelectValue(elements.campusFilter, previousCampus);
            restoreSelectValue(elements.departmentFilter, previousDepartment);
            restoreSelectValue(elements.sortFilter, previousSort);

            applyFilters();
            refreshVpaaDescriptiveAnalytics();
            if (currentVpaaAnalyticsProfessorId && isReportModalOpen()) {
                viewVpaaProfessorAnalytics(currentVpaaAnalyticsProfessorId);
            }
        }
    });
}

function restoreSelectValue(select, value) {
    if (!select || !value) return;
    const hasOption = Array.from(select.options || []).some(function (option) {
        return option.value === value;
    });
    if (hasOption) {
        select.value = value;
    }
}

function setupMobileDrawer() {
    if (vpaaMobileDrawerBound) return;

    const toggleButtons = document.querySelectorAll(".mobile-nav-toggle");
    const backdrop = document.getElementById("sidebarBackdrop");
    if (!toggleButtons.length || !backdrop) return;

    toggleButtons.forEach((button) => {
        button.addEventListener("click", function () {
            const isOpen = document.body.classList.contains("vpaa-sidebar-open");
            if (isOpen) {
                closeMobileDrawer();
            } else {
                openMobileDrawer();
            }
        });
    });

    backdrop.addEventListener("click", closeMobileDrawer);
    window.addEventListener("resize", function () {
        if (window.innerWidth > 1000) {
            closeMobileDrawer();
        }
    });

    vpaaMobileDrawerBound = true;
}

function openMobileDrawer() {
    document.body.classList.add("vpaa-sidebar-open");
    document.querySelectorAll(".mobile-nav-toggle").forEach((button) => {
        button.setAttribute("aria-expanded", "true");
    });
}

function closeMobileDrawer() {
    document.body.classList.remove("vpaa-sidebar-open");
    document.querySelectorAll(".mobile-nav-toggle").forEach((button) => {
        button.setAttribute("aria-expanded", "false");
    });
}

function setupDashboardHeroActions() {
    const reportsBtn = document.getElementById("heroOpenReportsBtn");
    const highlightsBtn = document.getElementById("heroScrollHighlightsBtn");
    const navLinks = document.querySelectorAll(".sidebar-nav .nav-link[data-view]");
    const contentViews = document.querySelectorAll(".content-view");

    function activateView(viewId) {
        contentViews.forEach((view) => {
            view.classList.toggle("active", view.id === viewId);
        });

        navLinks.forEach((nav) => {
            nav.classList.toggle("active", nav.dataset.view === viewId);
        });

        if (viewId !== "reports-view") {
            closeReportModal();
        }
        closeMobileDrawer();
    }

    if (reportsBtn) {
        reportsBtn.addEventListener("click", function () {
            activateView("reports-view");
        });
    }

    if (highlightsBtn) {
        highlightsBtn.addEventListener("click", function () {
            activateView("reports-view");
            const highlights = document.querySelector(".vpaa-key-highlights-section");
            if (highlights) {
                const prefersReducedMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
                highlights.scrollIntoView({ behavior: prefersReducedMotion ? "auto" : "smooth", block: "start" });
            }
        });
    }
}

function populateDepartments() {
    elements.departmentFilter.innerHTML = '<option value="all">All departments</option>';
    const departments = [...new Set(allProfessorData.map((prof) => prof.department))].sort();
    departments.forEach((dept) => {
        const option = document.createElement("option");
        option.value = dept;
        option.textContent = dept;
        elements.departmentFilter.appendChild(option);
    });
}

function populateSemesters() {
    elements.semesterFilter.innerHTML = "";
    const semesters = availableSemesterLabels.length
        ? availableSemesterLabels
        : [...new Set(allProfessorData.map((prof) => prof.semester))];
    const allOption = document.createElement("option");
    allOption.value = "all";
    allOption.textContent = "All Semesters";
    elements.semesterFilter.appendChild(allOption);

    semesters.forEach((semester) => {
        const option = document.createElement("option");
        option.value = semester;
        option.textContent = semester;
        elements.semesterFilter.appendChild(option);
    });

    elements.semesterFilter.value = semesters.includes(currentSemesterLabel)
        ? currentSemesterLabel
        : "all";
}

function formatCampusLabel(campus) {
    const name = String(campus && (campus.name || campus.id) || "").trim();
    if (name) return name;
    const fallbackId = String(campus && campus.id || "").trim();
    return fallbackId ? fallbackId.toUpperCase() : "";
}

function populateCampuses() {
    if (!elements.campusFilter) return;

    elements.campusFilter.innerHTML = "";
    const allOption = document.createElement("option");
    allOption.value = "all";
    allOption.textContent = "All Campuses";
    elements.campusFilter.appendChild(allOption);

    const campusOptions = new Map();
    const campuses = (SharedData.getCampuses && SharedData.getCampuses()) || [];
    campuses.forEach((campus) => {
        const campusId = String(campus && campus.id || "").trim();
        if (!campusId || normalizeVpaaToken(campusId) === "all") return;
        campusOptions.set(campusId, formatCampusLabel(campus));
    });

    allProfessorData.forEach((prof) => {
        const campusId = String(prof && prof.campus || "").trim();
        if (!campusId || normalizeVpaaToken(campusId) === "all" || campusOptions.has(campusId)) return;
        campusOptions.set(campusId, campusId.toUpperCase());
    });

    Array.from(campusOptions.entries())
        .sort((a, b) => a[1].localeCompare(b[1]))
        .forEach(([campusId, campusLabel]) => {
            const option = document.createElement("option");
            option.value = campusId;
            option.textContent = campusLabel;
            elements.campusFilter.appendChild(option);
        });

    elements.campusFilter.value = "all";
}

function bindEvents() {
    elements.searchBtn.addEventListener("click", () => {
        hasSubmittedSearch = true;
        applyFilters();
    });
    elements.searchInput.addEventListener("input", () => {
        hasSubmittedSearch = false;
        closeReportModal();
        renderProfessors([], "Enter a professor name or employee ID, then click Search to view reports.");
    });
    elements.searchInput.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
            event.preventDefault();
            hasSubmittedSearch = true;
            applyFilters();
        }
    });
    elements.semesterFilter.addEventListener("change", applyFilters);
    if (elements.campusFilter) {
        elements.campusFilter.addEventListener("change", applyFilters);
    }
    elements.departmentFilter.addEventListener("change", applyFilters);
    elements.sortFilter.addEventListener("change", applyFilters);
    elements.resetFilters.addEventListener("click", resetFilters);
    if (elements.overallSasrBtn) {
        elements.overallSasrBtn.addEventListener("click", openVpaaOverallSasrModal);
    }
}

function resetFilters() {
    hasSubmittedSearch = false;
    elements.searchInput.value = "";
    elements.semesterFilter.value = currentSemesterLabel || "all";
    if (elements.campusFilter) {
        elements.campusFilter.value = "all";
    }
    elements.departmentFilter.value = "all";
    elements.sortFilter.value = "rating-high";
    applyFilters();
}

function refreshDashboardChartsForSemester(semesterLabel) {
    const label = semesterLabel || currentSemesterLabel || "";
    const context = buildVpaaDatabaseContext();
    vpaaChartDataByType = {
        student: buildVpaaChartDataForType("student", label, context),
        professor: buildVpaaChartDataForType("professor", label, context),
        supervisor: buildVpaaChartDataForType("supervisor", label, context)
    };
}

function applyFilters() {
    closeReportModal();

    const rawTerm = elements.searchInput.value.trim();
    const term = rawTerm.toLowerCase();
    const semester = elements.semesterFilter.value;
    const campus = elements.campusFilter ? elements.campusFilter.value : "all";
    const department = elements.departmentFilter.value;
    const sortMode = elements.sortFilter.value;

    if (semester !== "all" && semester !== currentSemesterLabel) {
        currentSemesterLabel = semester;
    }
    refreshDashboardChartsForSemester(semester === "all" ? currentSemesterLabel : semester);
    renderDashboardCharts();

    const scopeData = allProfessorData.filter(
        (prof) => semester === "all" || prof.semester === semester
    );

    let filtered = scopeData.filter((prof) => {
        const matchesTerm = !term ||
            prof.name.toLowerCase().includes(term) ||
            prof.employeeId.toLowerCase().includes(term) ||
            (prof.subjects || []).some((subj) => subj.toLowerCase().includes(term));
        const matchesDept = department === "all" || prof.department === department;
        return matchesTerm && matchesDept;
    });

    filtered = sortProfessors(filtered, sortMode);

    updateSummary(filtered);
    updateWordFrequency(filtered);

    let highlightScope = scopeData.filter((prof) => {
        const matchesDept = department === "all" || prof.department === department;
        const matchesCampus = campus === "all" || normalizeVpaaToken(prof.campus) === normalizeVpaaToken(campus);
        return matchesDept && matchesCampus;
    });

    if (hasSubmittedSearch && rawTerm) {
        highlightScope = highlightScope.filter((prof) =>
            prof.name.toLowerCase().includes(term) ||
            prof.employeeId.toLowerCase().includes(term)
        );
    }

    renderKeyHighlights(buildKeyHighlights(highlightScope));

    if (!hasSubmittedSearch || !rawTerm) {
        renderProfessors([], "Enter a professor name or employee ID, then click Search to view reports.");
        return;
    }

    let reportFiltered = scopeData.filter((prof) => {
        const matchesTerm = prof.name.toLowerCase().includes(term) ||
            prof.employeeId.toLowerCase().includes(term);
        const matchesDept = department === "all" || prof.department === department;
        const matchesCampus = campus === "all" || normalizeVpaaToken(prof.campus) === normalizeVpaaToken(campus);
        return matchesTerm && matchesDept && matchesCampus;
    });

    reportFiltered = sortProfessors(reportFiltered, sortMode);
    renderProfessors(reportFiltered);
}

function sortProfessors(list, mode) {
    const sorted = [...list];
    if (mode === "rating-high") {
        sorted.sort((a, b) => b.overall - a.overall);
    } else if (mode === "rating-low") {
        sorted.sort((a, b) => a.overall - b.overall);
    } else if (mode === "response") {
        sorted.sort((a, b) => b.responseRate - a.responseRate);
    } else if (mode === "name") {
        sorted.sort((a, b) => a.name.localeCompare(b.name));
    }
    return sorted;
}

function countProfessorComments(prof) {
    if (!prof || typeof prof !== "object") return 0;
    const student = Array.isArray(prof.studentComments) ? prof.studentComments.length : 0;
    const peer = Array.isArray(prof.peerComments) ? prof.peerComments.length : 0;
    const supervisor = Array.isArray(prof.supervisorComments) ? prof.supervisorComments.length : 0;
    return student + peer + supervisor;
}

function safeNumber(value, fallback) {
    const parsed = Number(value);
    return Number.isFinite(parsed) ? parsed : fallback;
}

function buildKeyHighlights(sourceList) {
    const list = (Array.isArray(sourceList) ? sourceList : []).filter((prof) => prof && typeof prof === "object");
    if (!list.length) {
        return {
            topRating: null,
            mostComments: null,
            needsAttention: null
        };
    }

    const topRating = list.slice().sort((a, b) => {
        const aOverall = safeNumber(a.overall, 0);
        const bOverall = safeNumber(b.overall, 0);
        if (bOverall !== aOverall) return bOverall - aOverall;
        const aResponse = safeNumber(a.responseRate, 0);
        const bResponse = safeNumber(b.responseRate, 0);
        return bResponse - aResponse;
    })[0] || null;

    const mostComments = list.slice().sort((a, b) => {
        const aComments = countProfessorComments(a);
        const bComments = countProfessorComments(b);
        if (bComments !== aComments) return bComments - aComments;
        const aOverall = safeNumber(a.overall, 0);
        const bOverall = safeNumber(b.overall, 0);
        return bOverall - aOverall;
    })[0] || null;

    const needsAttention = list.slice().sort((a, b) => {
        const aOverall = safeNumber(a.overall, 0);
        const bOverall = safeNumber(b.overall, 0);
        if (aOverall !== bOverall) return aOverall - bOverall;
        const aResponse = safeNumber(a.responseRate, 0);
        const bResponse = safeNumber(b.responseRate, 0);
        return aResponse - bResponse;
    })[0] || null;

    return {
        topRating: topRating,
        mostComments: mostComments,
        needsAttention: needsAttention
    };
}

function renderHighlightCard(cardElement, cardData) {
    if (!cardElement) return;

    const nameEl = cardElement.querySelector(".vpaa-highlight-name");
    const metricEl = cardElement.querySelector(".vpaa-highlight-metric");
    if (!nameEl || !metricEl) return;

    if (!cardData || !cardData.name) {
        nameEl.textContent = "No data yet";
        metricEl.textContent = "Waiting for evaluation data.";
        return;
    }

    nameEl.textContent = cardData.name;
    metricEl.textContent = cardData.metric;
}

function renderKeyHighlights(cards) {
    const hasData = !!(cards && (cards.topRating || cards.mostComments || cards.needsAttention));
    if (elements.keyHighlightsGrid) {
        elements.keyHighlightsGrid.hidden = !hasData;
    }
    if (elements.highlightsEmpty) {
        elements.highlightsEmpty.hidden = hasData;
    }

    const topRatingProf = cards && cards.topRating;
    const mostCommentsProf = cards && cards.mostComments;
    const needsAttentionProf = cards && cards.needsAttention;

    renderHighlightCard(elements.highlightTopRating, topRatingProf ? {
        name: String(topRatingProf.name || "Unknown Professor"),
        metric: `${safeNumber(topRatingProf.overall, 0).toFixed(1)} rating`
    } : null);

    renderHighlightCard(elements.highlightMostComments, mostCommentsProf ? {
        name: String(mostCommentsProf.name || "Unknown Professor"),
        metric: `${countProfessorComments(mostCommentsProf)} comments logged`
    } : null);

    renderHighlightCard(elements.highlightNeedsAttention, needsAttentionProf ? {
        name: String(needsAttentionProf.name || "Unknown Professor"),
        metric: `${safeNumber(needsAttentionProf.overall, 0).toFixed(1)} rating | ${safeNumber(needsAttentionProf.responseRate, 0)}% response`
    } : null);
}

function isVpaaStudentEvaluationRecord(record) {
    const role = normalizeVpaaToken(record && (record.evaluatorRole || record.evaluationType));
    return role === "" || role === "student" || role === "student-to-professor" || role === "student-professor";
}

function isVpaaSubmittedEvaluation(record) {
    const status = normalizeVpaaToken(record && record.status);
    return status === "" || status === "submitted";
}

function buildVpaaStudentDirectory() {
    const users = (SharedData.getUsers && SharedData.getUsers()) || [];
    const directoryByUserId = new Map();
    const userIdByStudentNumber = new Map();

    users.forEach(function (user) {
        if (!user || normalizeVpaaToken(user.role) !== "student") return;
        if (normalizeVpaaToken(user.status || "active") === "inactive") return;

        const userId = normalizeVpaaUserId(user.id);
        if (!userId) return;

        const studentNumber = String(user.studentNumber || "").trim();
        directoryByUserId.set(userId, {
            studentUserId: userId,
            studentNumber: studentNumber,
            fullName: String(user.name || "").trim() || "Unknown Student",
            department: String(user.department || user.institute || "UNASSIGNED").trim().toUpperCase() || "UNASSIGNED",
            program: String(user.programCode || user.programName || "UNASSIGNED").trim().toUpperCase() || "UNASSIGNED",
            campus: String(user.campus || user.campusSlug || "UNASSIGNED").trim().toUpperCase() || "UNASSIGNED",
            yearSection: String(user.yearSection || "").trim() || "N/A"
        });

        if (studentNumber) {
            userIdByStudentNumber.set(normalizeVpaaToken(studentNumber), userId);
        }
    });

    return { directoryByUserId: directoryByUserId, userIdByStudentNumber: userIdByStudentNumber };
}

function buildVpaaStudentAnalyticsRows() {
    const semesterId = String((SharedData.getCurrentSemester && SharedData.getCurrentSemester()) || currentSemesterLabel || "").trim();
    const directory = buildVpaaStudentDirectory();
    const directoryByUserId = directory.directoryByUserId;
    const userIdByStudentNumber = directory.userIdByStudentNumber;
    const subjectManagement = SharedData.getSubjectManagement
        ? SharedData.getSubjectManagement()
        : { offerings: [], enrollments: [] };
    const offerings = Array.isArray(subjectManagement.offerings) ? subjectManagement.offerings : [];
    const enrollments = Array.isArray(subjectManagement.enrollments) ? subjectManagement.enrollments : [];
    const evaluations = SharedData.getEvaluations ? SharedData.getEvaluations() : [];

    const activeOfferingsById = new Map(
        offerings
            .filter(function (offering) {
                if (!offering || !offering.isActive) return false;
                const offeringSemester = String(offering.semesterSlug || "").trim();
                if (!semesterId) return true;
                return !offeringSemester || offeringSemester === semesterId;
            })
            .map(function (offering) {
                return [String(offering.id || "").trim(), offering];
            })
    );

    const expectedByStudent = new Map();
    const studentMetaById = new Map();

    enrollments.forEach(function (enrollment) {
        if (!enrollment || normalizeVpaaToken(enrollment.status) !== "enrolled") return;

        const offeringId = String(enrollment.courseOfferingId || "").trim();
        if (!offeringId || !activeOfferingsById.has(offeringId)) return;

        let studentUserId = normalizeVpaaUserId(enrollment.studentUserId || enrollment.studentId);
        const studentNumber = String(enrollment.studentNumber || "").trim();
        if (!studentUserId && studentNumber) {
            studentUserId = userIdByStudentNumber.get(normalizeVpaaToken(studentNumber)) || "";
        }
        if (!studentUserId) return;

        if (!expectedByStudent.has(studentUserId)) {
            expectedByStudent.set(studentUserId, new Set());
        }
        expectedByStudent.get(studentUserId).add(offeringId);

        const baseMeta = directoryByUserId.get(studentUserId);
        studentMetaById.set(studentUserId, {
            studentUserId: studentUserId,
            studentNumber: studentNumber || (baseMeta && baseMeta.studentNumber) || "",
            fullName: String(enrollment.studentName || "").trim() || (baseMeta && baseMeta.fullName) || "Unknown Student",
            department: (baseMeta && baseMeta.department) || "UNASSIGNED",
            program: (baseMeta && baseMeta.program) || "UNASSIGNED",
            campus: (baseMeta && baseMeta.campus) || "UNASSIGNED",
            yearSection: (baseMeta && baseMeta.yearSection) || "N/A"
        });
    });

    const completedByStudent = new Map();
    evaluations.forEach(function (evaluation) {
        if (!isVpaaStudentEvaluationRecord(evaluation)) return;
        if (!isVpaaSubmittedEvaluation(evaluation)) return;
        if (!isVpaaEvaluationInSemester(evaluation, semesterId)) return;

        const offeringId = String(evaluation.courseOfferingId || "").trim();
        if (!offeringId) return;

        let studentUserId = normalizeVpaaUserId(
            evaluation.studentUserId ||
            evaluation.studentId ||
            evaluation.evaluatorId ||
            evaluation.userId
        );
        const evalStudentNumber = String(evaluation.studentNumber || "").trim();
        if (!studentUserId && evalStudentNumber) {
            studentUserId = userIdByStudentNumber.get(normalizeVpaaToken(evalStudentNumber)) || "";
        }
        if (!studentUserId) return;
        if (!expectedByStudent.has(studentUserId)) return;
        if (!expectedByStudent.get(studentUserId).has(offeringId)) return;

        if (!completedByStudent.has(studentUserId)) {
            completedByStudent.set(studentUserId, new Set());
        }
        completedByStudent.get(studentUserId).add(offeringId);
    });

    const rows = [];
    expectedByStudent.forEach(function (expectedSet, studentUserId) {
        const meta = studentMetaById.get(studentUserId) || directoryByUserId.get(studentUserId) || {
            studentUserId: studentUserId,
            studentNumber: "",
            fullName: "Unknown Student",
            department: "UNASSIGNED",
            program: "UNASSIGNED",
            campus: "UNASSIGNED",
            yearSection: "N/A"
        };
        const expectedCount = expectedSet.size;
        const completedCount = (completedByStudent.get(studentUserId) || new Set()).size;

        rows.push({
            studentUserId: studentUserId,
            studentNumber: meta.studentNumber || "",
            fullName: meta.fullName || "Unknown Student",
            department: meta.department || "UNASSIGNED",
            program: meta.program || "UNASSIGNED",
            campus: meta.campus || "UNASSIGNED",
            yearSection: meta.yearSection || "N/A",
            expectedCount: expectedCount,
            completedCount: completedCount,
            evaluated: expectedCount > 0 && completedCount >= expectedCount
        });
    });

    rows.sort(function (a, b) {
        return String(a.fullName || "").localeCompare(String(b.fullName || ""));
    });

    return rows;
}

function buildVpaaAnalyticsSummary(rows) {
    const assigned = rows.length;
    const evaluated = rows.filter(function (row) { return row.evaluated; }).length;
    const notEvaluated = Math.max(assigned - evaluated, 0);
    const completionRate = assigned > 0 ? Math.round((evaluated / assigned) * 100) : 0;
    return { assigned: assigned, evaluated: evaluated, notEvaluated: notEvaluated, completionRate: completionRate };
}

function buildVpaaDepartmentBreakdown(rows) {
    const map = new Map();
    rows.forEach(function (row) {
        const key = row.department || "UNASSIGNED";
        if (!map.has(key)) {
            map.set(key, { department: key, assigned: 0, evaluated: 0, notEvaluated: 0, completionRate: 0 });
        }
        const item = map.get(key);
        item.assigned += 1;
        if (row.evaluated) item.evaluated += 1;
    });

    const list = Array.from(map.values()).map(function (item) {
        item.notEvaluated = Math.max(item.assigned - item.evaluated, 0);
        item.completionRate = item.assigned > 0 ? Math.round((item.evaluated / item.assigned) * 100) : 0;
        return item;
    });

    list.sort(function (a, b) {
        return b.completionRate - a.completionRate || a.department.localeCompare(b.department);
    });
    return list;
}

function buildVpaaProgramBreakdown(rows) {
    const map = new Map();
    rows.forEach(function (row) {
        const program = row.program || "UNASSIGNED";
        const department = row.department || "UNASSIGNED";
        const key = `${department}|${program}`;
        if (!map.has(key)) {
            map.set(key, { program: program, department: department, assigned: 0, evaluated: 0, notEvaluated: 0, completionRate: 0 });
        }
        const item = map.get(key);
        item.assigned += 1;
        if (row.evaluated) item.evaluated += 1;
    });

    const list = Array.from(map.values()).map(function (item) {
        item.notEvaluated = Math.max(item.assigned - item.evaluated, 0);
        item.completionRate = item.assigned > 0 ? Math.round((item.evaluated / item.assigned) * 100) : 0;
        return item;
    });

    list.sort(function (a, b) {
        return b.completionRate - a.completionRate
            || a.department.localeCompare(b.department)
            || a.program.localeCompare(b.program);
    });
    return list;
}

function getVpaaAnalyticsRowsForSelectedCampus(rows) {
    const source = Array.isArray(rows) ? rows : [];
    const selectedCampus = String(selectedVpaaAnalyticsCampus || "all").trim();
    if (!selectedCampus || selectedCampus === "all") return source;

    return source.filter(function (row) {
        return String(row && row.campus || "UNASSIGNED").trim() === selectedCampus;
    });
}

function buildVpaaAnalyticsSnapshotFromRows(rows) {
    return {
        rows: rows,
        summary: buildVpaaAnalyticsSummary(rows),
        departmentBreakdown: buildVpaaDepartmentBreakdown(rows),
        programBreakdown: buildVpaaProgramBreakdown(rows)
    };
}

function renderVpaaAnalyticsCampusFilter(rows) {
    const select = elements.analyticsCampusSelect;
    if (!select) return;

    const campuses = Array.from(new Set((Array.isArray(rows) ? rows : []).map(function (row) {
        return String(row && row.campus || "UNASSIGNED").trim() || "UNASSIGNED";
    }))).sort(function (a, b) {
        return a.localeCompare(b);
    });

    const currentValue = campuses.includes(selectedVpaaAnalyticsCampus) ? selectedVpaaAnalyticsCampus : "all";
    if (selectedVpaaAnalyticsCampus !== currentValue) {
        selectedVpaaAnalyticsCampus = currentValue;
        selectedVpaaAnalyticsDepartment = "";
    }

    const options = ['<option value="all">All Campuses</option>'].concat(campuses.map(function (campus) {
        const selected = campus === selectedVpaaAnalyticsCampus ? " selected" : "";
        return `<option value="${escapeHtml(campus)}"${selected}>${escapeHtml(campus)}</option>`;
    }));

    select.innerHTML = options.join("");
    select.value = selectedVpaaAnalyticsCampus;
}

function refreshVpaaDescriptiveAnalytics() {
    const rows = buildVpaaStudentAnalyticsRows();
    latestVpaaAnalyticsSnapshot = buildVpaaAnalyticsSnapshotFromRows(rows);
    renderVpaaAnalyticsCampusFilter(rows);
    renderVpaaDescriptiveAnalytics(latestVpaaAnalyticsSnapshot);
}

function renderVpaaDescriptiveAnalytics(sourceSnapshot) {
    const analyticsSnapshot = buildVpaaAnalyticsSnapshotFromRows(
        getVpaaAnalyticsRowsForSelectedCampus(sourceSnapshot && sourceSnapshot.rows)
    );

    const deptBody = elements.departmentAnalyticsBody;
    const progBody = elements.departmentProgramsBody;
    const progTitle = elements.departmentProgramsTitle;
    const progEmpty = elements.departmentProgramsEmpty;
    const emptyEl = elements.analyticsEmptyState;
    if (!deptBody || !progBody || !progTitle || !progEmpty || !emptyEl) return;

    if (!analyticsSnapshot.rows.length) {
        deptBody.innerHTML = "";
        progBody.innerHTML = "";
        selectedVpaaAnalyticsDepartment = "";
        progTitle.textContent = "Programs by Department";
        progEmpty.textContent = "Select a department above to view its programs.";
        progEmpty.style.display = "block";
        emptyEl.style.display = "block";
        emptyEl.textContent = selectedVpaaAnalyticsCampus === "all"
            ? "No assigned students found for analytics."
            : "No assigned students found for the selected campus.";
        return;
    }

    emptyEl.style.display = "none";
    const hasSelectedDepartment = analyticsSnapshot.departmentBreakdown.some(function (item) {
        return item.department === selectedVpaaAnalyticsDepartment;
    });
    if (!hasSelectedDepartment) {
        selectedVpaaAnalyticsDepartment = "";
    }

    deptBody.innerHTML = analyticsSnapshot.departmentBreakdown.map(function (item) {
        const isActive = item.department === selectedVpaaAnalyticsDepartment;
        return `
            <tr class="vpaa-analytics-dept-row${isActive ? " active" : ""}" data-department="${escapeAttr(item.department)}" tabindex="0" role="button" aria-label="Show programs for ${escapeAttr(item.department)}">
                <td>${escapeHtml(item.department)}</td>
                <td>${item.assigned}</td>
                <td>${item.evaluated}</td>
                <td>${item.notEvaluated}</td>
                <td>${item.completionRate}%</td>
            </tr>
        `;
    }).join("");

    if (!selectedVpaaAnalyticsDepartment) {
        progBody.innerHTML = "";
        progTitle.textContent = "Programs by Department";
        progEmpty.textContent = "Select a department above to view its programs.";
        progEmpty.style.display = "block";
        return;
    }

    const filteredPrograms = analyticsSnapshot.programBreakdown.filter(function (item) {
        return item.department === selectedVpaaAnalyticsDepartment;
    });
    progTitle.textContent = `Programs under ${selectedVpaaAnalyticsDepartment}`;

    if (!filteredPrograms.length) {
        progBody.innerHTML = "";
        progEmpty.textContent = "No program data available for this department.";
        progEmpty.style.display = "block";
        return;
    }

    progEmpty.style.display = "none";
    progBody.innerHTML = filteredPrograms.map(function (item) {
        return `
            <tr>
                <td>${escapeHtml(item.program)}</td>
                <td>${item.assigned}</td>
                <td>${item.evaluated}</td>
                <td>${item.notEvaluated}</td>
                <td>${item.completionRate}%</td>
            </tr>
        `;
    }).join("");
}

function setupVpaaAnalyticsInteractions() {
    const deptBody = elements.departmentAnalyticsBody;
    const campusSelect = elements.analyticsCampusSelect;

    if (deptBody) {
        deptBody.addEventListener("click", function (event) {
            const row = event.target.closest("tr[data-department]");
            if (!row) return;
            const department = String(row.dataset.department || "").trim();
            if (!department || department === selectedVpaaAnalyticsDepartment) return;
            selectedVpaaAnalyticsDepartment = department;
            renderVpaaDescriptiveAnalytics(latestVpaaAnalyticsSnapshot);
        });

        deptBody.addEventListener("keydown", function (event) {
            if (event.key !== "Enter" && event.key !== " ") return;
            const row = event.target.closest("tr[data-department]");
            if (!row) return;
            event.preventDefault();
            const department = String(row.dataset.department || "").trim();
            if (!department || department === selectedVpaaAnalyticsDepartment) return;
            selectedVpaaAnalyticsDepartment = department;
            renderVpaaDescriptiveAnalytics(latestVpaaAnalyticsSnapshot);
        });
    }

    if (campusSelect) {
        campusSelect.addEventListener("change", function () {
            selectedVpaaAnalyticsCampus = String(campusSelect.value || "all").trim() || "all";
            selectedVpaaAnalyticsDepartment = "";
            renderVpaaDescriptiveAnalytics(latestVpaaAnalyticsSnapshot);
        });
    }
}

function getVpaaActiveStudentCount() {
    const users = (SharedData.getUsers && SharedData.getUsers()) || [];
    return users.filter((user) => {
        const role = normalizeVpaaToken(user && user.role);
        if (role !== "student") return false;
        const status = normalizeVpaaToken(user && (user.status || "active"));
        return status !== "inactive";
    }).length;
}

function updateSummary(list) {
    const activeStudentCount = getVpaaActiveStudentCount();

    if (list.length === 0) {
        elements.totalStudents.textContent = activeStudentCount.toString();
        elements.completionRate.textContent = "0%";
        elements.pendingEvaluations.textContent = "0";
        elements.activeProfessors.textContent = "0";
        return;
    }

    const expectedStudentEvaluations = list.reduce((sum, prof) => sum + prof.students, 0);
    const totalEvaluations = list.reduce((sum, prof) => sum + prof.evaluations, 0);
    const completionRate = expectedStudentEvaluations === 0 ? 0 : Math.round((totalEvaluations / expectedStudentEvaluations) * 100);
    const pendingEvaluations = Math.max(0, expectedStudentEvaluations - totalEvaluations);

    elements.totalStudents.textContent = activeStudentCount.toString();
    elements.completionRate.textContent = `${completionRate}%`;
    elements.pendingEvaluations.textContent = pendingEvaluations.toString();
    elements.activeProfessors.textContent = list.length.toString();
}

function updateWordFrequency(list) {
    const comments = collectAllComments(list);
    const words = computeTopWordFrequency(comments, 10);
    renderWordFrequencyList(elements.wordFrequencyPositive, words.length ? words : exampleWordFrequency);
    renderWordFrequencyList(elements.wordFrequencyNegative, []);
}

function getWordFrequencyForProfessor(prof) {
    const comments = collectAllComments([prof]);
    const words = computeTopWordFrequency(comments, 10);
    return words.length ? words : exampleWordFrequency;
}

function collectAllComments(list) {
    return list.flatMap((prof) => [
        ...(prof.studentComments || []),
        ...(prof.peerComments || []),
        ...(prof.supervisorComments || [])
    ]);
}

function countLexicon(comments, lexicon) {
    const counts = new Map();
    const patterns = lexicon.map((word) => ({
        key: word,
        regex: new RegExp(`\\b${escapeRegex(word)}\\b`, "gi")
    }));

    comments.forEach((comment) => {
        patterns.forEach(({ key, regex }) => {
            const matches = comment.match(regex);
            if (matches && matches.length) {
                counts.set(key, (counts.get(key) || 0) + matches.length);
            }
        });
    });

    return Array.from(counts.entries())
        .map(([label, count]) => ({ label, count }))
        .sort((a, b) => b.count - a.count || a.label.localeCompare(b.label))
        .slice(0, 3);
}

function computeTopWordFrequency(comments, limit) {
    const counts = new Map();
    const safeLimit = Math.max(1, Number(limit) || 10);

    comments.forEach((comment) => {
        normalizeCommentTokens(comment).forEach((token) => {
            counts.set(token, (counts.get(token) || 0) + 1);
        });
    });

    return Array.from(counts.entries())
        .map(([label, count]) => ({ label, count }))
        .sort((a, b) => b.count - a.count || a.label.localeCompare(b.label))
        .slice(0, safeLimit);
}

function normalizeCommentTokens(value) {
    const text = String(value || "")
        .toLowerCase()
        .replace(/[^a-z0-9\s]/g, " ")
        .replace(/\s+/g, " ")
        .trim();
    if (!text) return [];

    return text.split(" ").filter((token) => {
        if (token.length < 3) return false;
        if (WORD_FREQUENCY_STOP_WORDS.has(token)) return false;
        if (/^\d+$/.test(token)) return false;
        return true;
    });
}

function getPopupEvaluationTypeMeta(typeKey) {
    const token = String(typeKey || "").trim().toLowerCase();
    if (token === "professor") {
        return { id: "professor", label: "Professor to Professor" };
    }
    if (token === "supervisor") {
        return { id: "supervisor", label: "Supervisor to Professor" };
    }
    return { id: "student", label: "Student to Professor" };
}

function buildProfessorAnalyticsForType(typeKey, evaluations, semesterLabel) {
    const meta = buildVpaaQuestionMeta(typeKey, semesterLabel);
    const categories = Array.isArray(meta.categoryOrder) ? meta.categoryOrder.slice() : [];
    const categoryTotals = {};
    categories.forEach((category) => {
        categoryTotals[category] = { sum: 0, count: 0 };
    });

    const distribution = { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
    const list = Array.isArray(evaluations) ? evaluations : [];

    list.forEach((evaluation) => {
        const ratings = getEvaluationNumericRatings(evaluation);
        if (!ratings.length) return;

        const ratingMap = evaluation && typeof evaluation.ratings === "object" && evaluation.ratings ? evaluation.ratings : {};
        const ratingKeys = Object.keys(ratingMap);

        ratings.forEach((value, index) => {
            const questionId = String(ratingKeys[index] || "").trim();
            const mappedCategory = meta.categoryByQuestionId[questionId]
                || meta.categoryByQuestionId[questionId.toLowerCase()]
                || "";
            const fallbackCategory = categories[Math.min(index, categories.length - 1)] || "General Questions";
            const category = mappedCategory || fallbackCategory;

            if (!categoryTotals[category]) {
                categoryTotals[category] = { sum: 0, count: 0 };
                categories.push(category);
            }

            categoryTotals[category].sum += value;
            categoryTotals[category].count += 1;
        });

        const average = ratings.reduce((sum, value) => sum + value, 0) / ratings.length;
        const rounded = Math.max(1, Math.min(5, Math.round(average)));
        distribution[rounded] += 1;
    });

    return {
        type: getPopupEvaluationTypeMeta(typeKey).id,
        categoryScores: categories.map((category) => {
            const bucket = categoryTotals[category] || { sum: 0, count: 0 };
            const score = bucket.count ? (bucket.sum / bucket.count) : 0;
            return { category, score: Number(score.toFixed(2)), responses: bucket.count };
        }),
        ratingDistribution: distribution,
        totalEvaluations: list.length
    };
}

function normalizeVpaaAnalyticsEvaluationType(value) {
    const token = normalizeVpaaToken(value);
    if (token === "peer" || token === "professor" || token === "professor-to-professor") return "professor";
    if (token === "supervisor" || token === "supervisor-to-professor") return "supervisor";
    return "student";
}

function getVpaaEvaluationTypeOptions() {
    return VPAA_EVALUATION_TYPE_OPTIONS;
}

function getVpaaEvaluationTypeMeta(id) {
    const normalized = normalizeVpaaAnalyticsEvaluationType(id);
    return VPAA_EVALUATION_TYPE_OPTIONS.find(function (item) {
        return item.id === normalized;
    }) || VPAA_EVALUATION_TYPE_OPTIONS[0];
}

function getVpaaSemesterOptions() {
    const options = [{ id: "all", label: "All Semesters" }];
    const seen = new Set(["all"]);
    const semesterList = SharedData.getSemesterList ? SharedData.getSemesterList() : [];

    (Array.isArray(semesterList) ? semesterList : []).forEach(function (item) {
        const value = String(item && (item.value || item.id || item.slug || item.label) || "").trim();
        if (!value || seen.has(value)) return;
        seen.add(value);
        options.push({
            id: value,
            label: String(item && item.label || value).trim() || value
        });
    });

    const current = String(SharedData.getCurrentSemester ? SharedData.getCurrentSemester() : currentSemesterLabel || "").trim();
    if (current && !seen.has(current)) {
        seen.add(current);
        options.push({ id: current, label: current });
    }

    (Array.isArray(availableSemesterLabels) ? availableSemesterLabels : []).forEach(function (semester) {
        const value = String(semester || "").trim();
        if (!value || seen.has(value)) return;
        seen.add(value);
        options.push({ id: value, label: value });
    });

    return options;
}

function getVpaaSemesterLabel(id) {
    const value = String(id || "").trim();
    const option = getVpaaSemesterOptions().find(function (item) {
        return item.id === value;
    });
    return option ? option.label : (value || "Semester");
}

function buildVpaaSemesterOptionsHtml(selectedSemester) {
    return getVpaaSemesterOptions().map(function (option) {
        const selected = option.id === selectedSemester ? " selected" : "";
        return `<option value="${escapeAttr(option.id)}"${selected}>${escapeHtml(option.label)}</option>`;
    }).join("");
}

function buildVpaaEvaluationTypeOptionsHtml(selectedType) {
    const normalized = normalizeVpaaAnalyticsEvaluationType(selectedType);
    return getVpaaEvaluationTypeOptions().map(function (option) {
        const selected = option.id === normalized ? " selected" : "";
        return `<option value="${escapeAttr(option.id)}"${selected}>${escapeHtml(option.label)}</option>`;
    }).join("");
}

function formatVpaaAnalyticsDate(value) {
    const raw = String(value || "").trim();
    if (!raw) return "-";
    if (SharedData.formatDateTimeInPhilippines) {
        return SharedData.formatDateTimeInPhilippines(raw) || raw;
    }
    if (SharedData.formatDateInPhilippines) {
        const parsed = new Date(raw);
        if (!Number.isNaN(parsed.getTime())) {
            return SharedData.formatDateInPhilippines(parsed) || raw;
        }
    }
    return raw;
}

function collectVpaaQualitativeResponses(evaluation) {
    const responses = [];
    const sourceDate = evaluation && (evaluation.submittedAt || evaluation.timestamp || "");
    const dateLabel = formatVpaaAnalyticsDate(sourceDate);
    const evaluatorName = String(
        evaluation && (evaluation.evaluatorName || evaluation.studentName || evaluation.evaluatorUsername) || "Anonymous"
    ).trim() || "Anonymous";
    const evaluatorIdentity = String(
        evaluation && (
            evaluation.studentNumber ||
            evaluation.evaluatorStudentNumber ||
            evaluation.studentUserId ||
            evaluation.studentId ||
            evaluation.evaluatorUserId ||
            evaluation.evaluatorId ||
            evaluation.evaluatorUsername
        ) || "N/A"
    ).trim() || "N/A";
    const semesterId = String(evaluation && evaluation.semesterId || "").trim();
    const prefix = String(
        evaluation && (evaluation.evaluationKey || evaluation.id || evaluation.submittedAt || Date.now())
    ).trim();
    const seen = new Set();

    const pushResponse = function (keySuffix, value) {
        const text = String(value || "").trim();
        if (!text || seen.has(text)) return;
        seen.add(text);
        responses.push({
            id: `${prefix}-${keySuffix}`,
            text: text,
            date: dateLabel,
            studentName: evaluatorName,
            studentNumber: evaluatorIdentity,
            semesterId: semesterId
        });
    };

    const qualitative = evaluation && typeof evaluation.qualitative === "object" && evaluation.qualitative
        ? evaluation.qualitative
        : {};
    Object.keys(qualitative).forEach(function (questionKey, index) {
        pushResponse(`qual-${questionKey || index}`, qualitative[questionKey]);
    });

    const qualitativeResponses = Array.isArray(evaluation && evaluation.qualitativeResponses)
        ? evaluation.qualitativeResponses
        : [];
    qualitativeResponses.forEach(function (item, index) {
        if (typeof item === "string") {
            pushResponse(`response-${index}`, item);
            return;
        }
        if (item && typeof item === "object") {
            pushResponse(`response-${index}`, item.text || item.answer || item.comment || item.response);
        }
    });

    pushResponse("comment", evaluation && evaluation.comments);
    pushResponse("feedback", evaluation && evaluation.feedback);
    pushResponse("legacy-comment", evaluation && evaluation.comment);

    return responses;
}

function aggregateVpaaEvaluationData(options) {
    const settings = options || {};
    const context = settings.context || buildVpaaDatabaseContext();
    const typeKey = normalizeVpaaAnalyticsEvaluationType(settings.typeKey);
    const semesterId = String(settings.semesterId || "all").trim() || "all";
    const targetProfessorId = settings.targetProfessorId ? normalizeVpaaProfessorUserId(settings.targetProfessorId) : "";
    const includeCategoryScores = !!settings.includeCategoryScores;
    const questionMeta = includeCategoryScores
        ? buildVpaaQuestionMeta(typeKey, semesterId)
        : { categoryByQuestionId: {}, categoryOrder: [] };
    const categoryOrder = Array.isArray(questionMeta.categoryOrder) ? questionMeta.categoryOrder.slice() : [];
    const categoryStats = {};
    categoryOrder.forEach(function (category) {
        categoryStats[category] = { sum: 0, count: 0 };
    });

    const ratingDistribution = { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
    let totalRatingValue = 0;
    let totalRatingCount = 0;
    let totalEvaluations = 0;
    const uniqueTargetProfessorIds = new Set();
    const uniqueTargetTokens = new Set();
    const uniqueRaterTokens = new Set();
    let qualitativeResponses = [];

    (context.evaluations || []).forEach(function (evaluation) {
        const evaluationType = resolveVpaaEvaluationType(evaluation);
        if (evaluationType !== typeKey) return;
        if (!isVpaaEvaluationInSemester(evaluation, semesterId)) return;

        const targetId = resolveTargetProfessorIdFromEvaluation(evaluation, typeKey, context);
        if (targetProfessorId && targetId !== targetProfessorId) return;
        if (targetId) uniqueTargetProfessorIds.add(targetId);

        const fallbackTargetToken = normalizeVpaaToken(
            evaluation && (
                evaluation.targetProfessorId ||
                evaluation.targetId ||
                evaluation.colleagueId ||
                evaluation.targetProfessor ||
                evaluation.professorSubject
            )
        );
        if (fallbackTargetToken) uniqueTargetTokens.add(fallbackTargetToken);

        const raterToken = normalizeVpaaToken(
            evaluation && (
                evaluation.studentUserId ||
                evaluation.studentId ||
                evaluation.evaluatorUserId ||
                evaluation.evaluatorId ||
                evaluation.evaluatorUsername ||
                evaluation.evaluatorName
            )
        );
        if (raterToken) uniqueRaterTokens.add(raterToken);

        totalEvaluations += 1;

        const ratings = evaluation && typeof evaluation.ratings === "object" && evaluation.ratings ? evaluation.ratings : {};
        const evaluationValues = [];
        Object.keys(ratings).forEach(function (questionId) {
            const parsed = parseFloat(ratings[questionId]);
            if (!Number.isFinite(parsed)) return;

            const numericRating = Math.min(5, Math.max(1, parsed));
            evaluationValues.push(numericRating);
            totalRatingValue += numericRating;
            totalRatingCount += 1;

            if (includeCategoryScores) {
                const questionToken = String(questionId || "").trim();
                const category = questionMeta.categoryByQuestionId[questionToken]
                    || questionMeta.categoryByQuestionId[questionToken.toLowerCase()]
                    || "General Questions";
                if (!categoryStats[category]) {
                    categoryStats[category] = { sum: 0, count: 0 };
                    categoryOrder.push(category);
                }
                categoryStats[category].sum += numericRating;
                categoryStats[category].count += 1;
            }
        });

        if (evaluationValues.length > 0) {
            const average = evaluationValues.reduce(function (sum, value) { return sum + value; }, 0) / evaluationValues.length;
            const bucket = Math.min(5, Math.max(1, Math.round(average)));
            ratingDistribution[bucket] = (ratingDistribution[bucket] || 0) + 1;
        }

        qualitativeResponses = qualitativeResponses.concat(collectVpaaQualitativeResponses(evaluation));
    });

    const orderedCategories = categoryOrder.concat(
        Object.keys(categoryStats).filter(function (category) {
            return !categoryOrder.includes(category);
        })
    );
    let categoryScores = orderedCategories.map(function (category) {
        const stat = categoryStats[category] || { sum: 0, count: 0 };
        return {
            category: category,
            score: stat.count > 0 ? parseFloat((stat.sum / stat.count).toFixed(2)) : 0
        };
    });
    if (includeCategoryScores && categoryScores.length === 0) {
        categoryScores = [{ category: "General Questions", score: 0 }];
    }

    qualitativeResponses.sort(function (left, right) {
        const leftTime = Date.parse(String(left && left.date || "")) || 0;
        const rightTime = Date.parse(String(right && right.date || "")) || 0;
        return rightTime - leftTime;
    });

    return {
        averageRating: totalRatingCount > 0 ? parseFloat((totalRatingValue / totalRatingCount).toFixed(2)) : 0,
        totalEvaluations: totalEvaluations,
        ratingDistribution: ratingDistribution,
        categoryScores: categoryScores,
        uniqueTargetCount: Math.max(uniqueTargetProfessorIds.size, uniqueTargetTokens.size),
        uniqueRaterCount: uniqueRaterTokens.size,
        qualitativeResponses: qualitativeResponses
    };
}

function getVpaaProfessorStudentTotals(context, professorId, semesterId) {
    const professorToken = normalizeVpaaProfessorUserId(professorId);
    const expectedPairs = new Set();

    (context.enrollments || []).forEach(function (enrollment) {
        if (!enrollment) return;
        if (normalizeVpaaToken(enrollment.status || "enrolled") !== "enrolled") return;

        const offeringId = String(enrollment.courseOfferingId || "").trim();
        const offering = context.offeringsById[offeringId];
        if (!offering || offering.isActive === false) return;
        if (!isVpaaEvaluationInSemester({ semesterId: offering.semesterSlug || "" }, semesterId)) return;

        const offeringProfessorId = normalizeVpaaProfessorUserId(offering.professorUserId);
        if (offeringProfessorId !== professorToken) return;

        const studentToken = normalizeVpaaToken(enrollment.studentUserId || enrollment.studentId || enrollment.studentNumber || enrollment.studentName);
        const offeringToken = normalizeVpaaToken(offeringId);
        if (!studentToken || !offeringToken) return;
        expectedPairs.add(`${studentToken}|${offeringToken}`);
    });

    const completedPairs = new Set();
    (context.evaluations || []).forEach(function (evaluation) {
        if (resolveVpaaEvaluationType(evaluation) !== "student") return;
        if (!isVpaaEvaluationInSemester(evaluation, semesterId)) return;

        const targetId = resolveTargetProfessorIdFromEvaluation(evaluation, "student", context);
        if (targetId !== professorToken) return;

        const studentToken = normalizeVpaaToken(
            evaluation.studentUserId ||
            evaluation.studentId ||
            evaluation.evaluatorUserId ||
            evaluation.evaluatorId ||
            evaluation.evaluatorUsername
        );
        if (!studentToken) return;

        const offeringToken = normalizeVpaaToken(evaluation.courseOfferingId);
        if (expectedPairs.size > 0) {
            if (!offeringToken) return;
            const pairKey = `${studentToken}|${offeringToken}`;
            if (expectedPairs.has(pairKey)) {
                completedPairs.add(pairKey);
            }
            return;
        }

        completedPairs.add(`${studentToken}|${offeringToken || "direct"}`);
    });

    return {
        totalRaters: expectedPairs.size,
        evaluatedPairs: completedPairs.size
    };
}

function getVpaaProfessorEvaluationSnapshot(professor, semesterId, evaluationType, contextInput) {
    const meta = getVpaaEvaluationTypeMeta(evaluationType);
    if (!professor) {
        return {
            totalRaters: 0,
            evaluatedCount: 0,
            notEvaluatedCount: 0,
            averageRating: 0,
            qualitativeResponses: [],
            meta: meta
        };
    }

    const context = contextInput || buildVpaaDatabaseContext();
    const professorId = normalizeVpaaProfessorUserId(professor.userId || professor.id);
    const normalizedSemester = String(semesterId || "all").trim() || "all";
    const normalizedType = getVpaaEvaluationTypeMeta(evaluationType).id;
    const aggregate = aggregateVpaaEvaluationData({
        context: context,
        typeKey: normalizedType,
        semesterId: normalizedSemester,
        targetProfessorId: professorId,
        includeCategoryScores: true
    });

    if (normalizedType === "student") {
        const studentTotals = getVpaaProfessorStudentTotals(context, professorId, normalizedSemester);
        const fallbackRaters = aggregate.uniqueRaterCount;
        const totalRaters = studentTotals.totalRaters > 0 ? studentTotals.totalRaters : fallbackRaters;
        const evaluatedCount = studentTotals.totalRaters > 0 ? studentTotals.evaluatedPairs : aggregate.uniqueRaterCount;
        return {
            totalRaters: totalRaters,
            evaluatedCount: evaluatedCount,
            notEvaluatedCount: Math.max(totalRaters - evaluatedCount, 0),
            averageRating: aggregate.averageRating,
            qualitativeResponses: aggregate.qualitativeResponses,
            categoryScores: aggregate.categoryScores,
            ratingDistribution: aggregate.ratingDistribution,
            totalEvaluations: aggregate.totalEvaluations,
            meta: meta
        };
    }

    const activeProfessors = (context.professors || []).filter(function (user) {
        return normalizeVpaaToken(user && user.status || "active") !== "inactive";
    });
    const professorPool = Math.max(activeProfessors.length - 1, 0);
    const supervisorPool = getVpaaApplicableSupervisorUsersForProfessor(context, professorId).length || 1;
    let totalRaters = normalizedType === "professor" ? professorPool : supervisorPool;
    if (totalRaters < aggregate.uniqueRaterCount) {
        totalRaters = aggregate.uniqueRaterCount;
    }

    const evaluatedCount = aggregate.uniqueRaterCount;
    return {
        totalRaters: totalRaters,
        evaluatedCount: evaluatedCount,
        notEvaluatedCount: Math.max(totalRaters - evaluatedCount, 0),
        averageRating: aggregate.averageRating,
        qualitativeResponses: aggregate.qualitativeResponses,
        categoryScores: aggregate.categoryScores,
        ratingDistribution: aggregate.ratingDistribution,
        totalEvaluations: aggregate.totalEvaluations,
        meta: meta
    };
}

function resolveVpaaHistoricalTrendSourceAverage(aggregate) {
    const total = Number(aggregate && aggregate.totalEvaluations);
    const average = Number(aggregate && aggregate.averageRating);
    if (!Number.isFinite(total) || total <= 0) return null;
    if (!Number.isFinite(average) || average <= 0) return null;
    return average;
}

function getVpaaLatestSemestersForTrend(context, limit) {
    const desired = Number(limit) > 0 ? Number(limit) : 4;
    const orderedSemesters = [];
    const seen = new Set();

    (context.semesterList || []).forEach(function (item) {
        const value = String(item && (item.value || item.id || item.slug || item.label) || "").trim();
        if (!value || seen.has(value)) return;
        seen.add(value);
        orderedSemesters.push({
            id: value,
            label: String(item && item.label || value).trim() || value
        });
    });

    const currentSemester = String(context.currentSemester || currentSemesterLabel || "").trim();
    if (currentSemester && !seen.has(currentSemester)) {
        orderedSemesters.unshift({
            id: currentSemester,
            label: currentSemester
        });
    }

    if (orderedSemesters.length > 0) {
        return orderedSemesters.slice(0, desired).reverse();
    }

    const latestBySemester = new Map();
    (context.evaluations || []).forEach(function (evaluation) {
        const semesterId = String(evaluation && evaluation.semesterId || "").trim();
        if (!semesterId) return;

        const rawTs = evaluation && (evaluation.submittedAt || evaluation.timestamp || "");
        const ts = Date.parse(rawTs);
        const score = Number.isFinite(ts) ? ts : 0;
        const previous = latestBySemester.get(semesterId);
        if (previous === undefined || score > previous) {
            latestBySemester.set(semesterId, score);
        }
    });

    return Array.from(latestBySemester.entries())
        .sort(function (a, b) {
            if (b[1] !== a[1]) return b[1] - a[1];
            return String(b[0]).localeCompare(String(a[0]));
        })
        .slice(0, desired)
        .reverse()
        .map(function (entry) {
            return { id: entry[0], label: entry[0] };
        });
}

function buildVpaaProfessorHistoricalTrend(professorId, contextInput) {
    const context = contextInput || buildVpaaDatabaseContext();
    const normalizedProfessorId = normalizeVpaaProfessorUserId(professorId);
    const semesters = getVpaaLatestSemestersForTrend(context, 4);
    const weights = { student: 0.50, professor: 0.25, supervisor: 0.25 };

    const points = semesters.map(function (semester) {
        const semesterId = String(semester && semester.id || "").trim();
        const semesterLabel = String(semester && (semester.label || semester.id) || semesterId).trim() || semesterId;
        const sourceScores = {
            student: resolveVpaaHistoricalTrendSourceAverage(aggregateVpaaEvaluationData({
                context: context,
                typeKey: "student",
                semesterId: semesterId,
                targetProfessorId: normalizedProfessorId,
                includeCategoryScores: false
            })),
            professor: resolveVpaaHistoricalTrendSourceAverage(aggregateVpaaEvaluationData({
                context: context,
                typeKey: "professor",
                semesterId: semesterId,
                targetProfessorId: normalizedProfessorId,
                includeCategoryScores: false
            })),
            supervisor: resolveVpaaHistoricalTrendSourceAverage(aggregateVpaaEvaluationData({
                context: context,
                typeKey: "supervisor",
                semesterId: semesterId,
                targetProfessorId: normalizedProfessorId,
                includeCategoryScores: false
            }))
        };

        let weightedSum = 0;
        let availableWeight = 0;
        Object.keys(weights).forEach(function (key) {
            const value = Number(sourceScores[key]);
            if (!Number.isFinite(value)) return;
            const weight = Number(weights[key]);
            weightedSum += value * weight;
            availableWeight += weight;
        });

        return {
            semesterId: semesterId,
            semesterLabel: semesterLabel,
            score: availableWeight > 0 ? parseFloat((weightedSum / availableWeight).toFixed(2)) : null,
            delta: null
        };
    });

    let previousScore = null;
    points.forEach(function (point) {
        const score = Number(point && point.score);
        if (!Number.isFinite(score)) {
            point.delta = null;
            return;
        }
        point.delta = Number.isFinite(previousScore)
            ? parseFloat((score - previousScore).toFixed(2))
            : null;
        previousScore = score;
    });

    return {
        points: points,
        windowSize: 4,
        summary: computeVpaaHistoricalTrendSummary(points)
    };
}

function formatVpaaHistoricalTrendSignedPercent(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return "N/A";
    return `${numeric >= 0 ? "+" : ""}${numeric.toFixed(1)}%`;
}

function formatVpaaHistoricalTrendDelta(value) {
    const numeric = Number(value);
    if (!Number.isFinite(numeric)) return "-";
    return `${numeric >= 0 ? "+" : ""}${numeric.toFixed(2)}`;
}

function computeVpaaHistoricalTrendSummary(pointsInput) {
    const points = Array.isArray(pointsInput) ? pointsInput : [];
    const validPoints = points.filter(function (point) {
        return Number.isFinite(Number(point && point.score));
    }).map(function (point) {
        return Number(point.score);
    });

    if (validPoints.length < 2) {
        return {
            hasSufficientData: false,
            direction: "insufficient",
            percentChange: null,
            variationPercent: null,
            isConsistent: false,
            declinePatternDetected: false,
            semesterCount: validPoints.length,
            statement: "Insufficient historical data to determine performance trend."
        };
    }

    const earliest = validPoints[0];
    const latest = validPoints[validPoints.length - 1];
    const safeBaseline = earliest > 0 ? earliest : 0.01;
    const percentChange = ((latest - earliest) / safeBaseline) * 100;

    let direction = "stable";
    if (percentChange >= 10) {
        direction = "improved";
    } else if (percentChange <= -10) {
        direction = "declined";
    }

    const mean = validPoints.reduce(function (sum, value) { return sum + value; }, 0) / validPoints.length;
    const variance = validPoints.reduce(function (sum, value) {
        return sum + Math.pow(value - mean, 2);
    }, 0) / validPoints.length;
    const variationPercent = mean > 0 ? (Math.sqrt(variance) / mean) * 100 : 0;
    const isConsistent = variationPercent <= 5;

    let maxConsecutiveDrops = 0;
    let consecutiveDrops = 0;
    for (let index = 1; index < validPoints.length; index += 1) {
        if (validPoints[index] < validPoints[index - 1]) {
            consecutiveDrops += 1;
            maxConsecutiveDrops = Math.max(maxConsecutiveDrops, consecutiveDrops);
        } else {
            consecutiveDrops = 0;
        }
    }

    const declinePatternDetected = maxConsecutiveDrops >= 2;
    const semesterCount = validPoints.length;
    let statement = `Faculty remained stable (${formatVpaaHistoricalTrendSignedPercent(percentChange)}) over ${semesterCount} semester${semesterCount === 1 ? "" : "s"}.`;
    if (direction === "improved") {
        statement = `Faculty improved by ${Math.abs(percentChange).toFixed(1)}% over ${semesterCount} semester${semesterCount === 1 ? "" : "s"}.`;
    } else if (direction === "declined") {
        statement = `Faculty declined by ${Math.abs(percentChange).toFixed(1)}% over ${semesterCount} semester${semesterCount === 1 ? "" : "s"}.`;
    }

    return {
        hasSufficientData: true,
        direction: direction,
        percentChange: percentChange,
        variationPercent: variationPercent,
        isConsistent: isConsistent,
        declinePatternDetected: declinePatternDetected,
        semesterCount: semesterCount,
        statement: statement
    };
}

function getVpaaHistoricalTrendDirectionLabel(direction) {
    if (direction === "improved") return "Improving";
    if (direction === "declined") return "Declining";
    if (direction === "stable") return "Stable";
    return "Insufficient Data";
}

function getVpaaHistoricalTrendDirectionClass(direction) {
    if (direction === "improved") return "positive";
    if (direction === "declined") return "negative";
    if (direction === "stable") return "neutral";
    return "neutral";
}

function getVpaaHistoricalTrendConsistencyLabel(summary) {
    if (!summary || !summary.hasSufficientData) return "Unknown";
    return summary.isConsistent ? "Consistent" : "Variable";
}

function getVpaaHistoricalTrendConsistencyClass(summary) {
    if (!summary || !summary.hasSufficientData) return "neutral";
    return summary.isConsistent ? "positive" : "warning";
}

function getVpaaHistoricalTrendDeclinePatternLabel(summary) {
    if (!summary || !summary.hasSufficientData) return "Unknown";
    return summary.declinePatternDetected ? "Detected" : "Not detected";
}

function getVpaaHistoricalTrendDeclinePatternClass(summary) {
    if (!summary || !summary.hasSufficientData) return "neutral";
    return summary.declinePatternDetected ? "negative" : "positive";
}

function generateVpaaStarRating(rating) {
    const numRating = parseFloat(rating) || 0;
    const fullStars = Math.floor(numRating);
    const hasHalfStar = (numRating % 1) >= 0.5;
    const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);

    let stars = "";
    for (let index = 0; index < fullStars; index += 1) {
        stars += '<i class="fas fa-star"></i>';
    }
    if (hasHalfStar) {
        stars += '<i class="fas fa-star-half-alt"></i>';
    }
    for (let index = 0; index < emptyStars; index += 1) {
        stars += '<i class="far fa-star"></i>';
    }
    return stars;
}

function buildVpaaProfessorAvatarHtml(professor, avatarClassName) {
    const className = String(avatarClassName || "").trim();
    const photoSource = sanitizePhotoSource(
        professor && (professor.profileImageUrl || professor.photoData || professor.profileImage)
    );
    if (photoSource) {
        return `<div class="${escapeAttr(className)}"><img src="${escapeAttr(photoSource)}" alt="${escapeAttr(professor && professor.name ? professor.name : "Professor")} photo"></div>`;
    }

    const initials = escapeHtml(getInitials(professor && professor.name ? professor.name : "") || "PR");
    return `<div class="${escapeAttr(className)}"><span class="avatar-fallback-text">${initials}</span></div>`;
}

function getVpaaProfessorById(professorId, semesterId) {
    const rawId = String(professorId || "").trim();
    if (!rawId) return null;

    const baseId = normalizeVpaaProfessorUserId(rawId);
    const selectedSemester = String(semesterId || "").trim();
    const rows = allProfessorData.filter(function (item) {
        if (!item) return false;
        if (String(item.id || "").trim() === rawId) return true;
        return baseId && normalizeVpaaProfessorUserId(item.userId || item.id) === baseId;
    });

    let selected = null;
    if (selectedSemester && selectedSemester !== "all") {
        selected = rows.find(function (item) {
            return String(item && item.semester || "").trim() === selectedSemester;
        }) || null;
    }

    if (!selected) {
        const current = String((SharedData.getCurrentSemester && SharedData.getCurrentSemester()) || currentSemesterLabel || "").trim();
        selected = rows.find(function (item) {
            return current && String(item && item.semester || "").trim() === current;
        }) || null;
    }

    if (!selected) {
        selected = rows.find(function (item) {
            return String(item && item.id || "").trim() === rawId;
        }) || rows[0] || null;
    }

    if (selected) {
        return Object.assign({}, selected, {
            userId: normalizeVpaaProfessorUserId(selected.userId || selected.id)
        });
    }

    const users = SharedData.getUsers ? SharedData.getUsers() : [];
    const sourceUser = (Array.isArray(users) ? users : []).find(function (user) {
        return normalizeVpaaToken(user && user.role) === "professor"
            && normalizeVpaaProfessorUserId(user && user.id) === baseId;
    });
    if (!sourceUser) return null;

    const displaySemester = selectedSemester && selectedSemester !== "all"
        ? selectedSemester
        : (String((SharedData.getCurrentSemester && SharedData.getCurrentSemester()) || currentSemesterLabel || "").trim());
    return {
        id: `${baseId}|${displaySemester || "all"}`,
        userId: baseId,
        employeeId: String(sourceUser.employeeId || "").trim(),
        name: String(sourceUser.name || "Professor").trim(),
        campus: String(sourceUser.campus || "").trim(),
        department: String(sourceUser.department || sourceUser.institute || "General").trim(),
        program: String(sourceUser.programCode || sourceUser.program || "").trim(),
        programCode: String(sourceUser.programCode || sourceUser.program || "").trim(),
        email: String(sourceUser.email || "").trim(),
        rank: String(sourceUser.position || "Professor").trim(),
        photoData: String(sourceUser.photoData || sourceUser.profileImageUrl || sourceUser.profileImage || "").trim(),
        semester: displaySemester || "",
        overall: 0,
        responseRate: 0,
        evaluations: 0,
        students: 0,
        subjects: [],
        analyticsByType: {},
        studentComments: [],
        peerComments: [],
        supervisorComments: [],
        isActive: normalizeVpaaToken(sourceUser.status || "active") !== "inactive"
    };
}

function renderWordFrequencyList(target, list) {
    if (!target) return;
    target.innerHTML = list
        .map((item) => {
            const label = escapeHtml(capitalize(item && item.label));
            const count = Math.max(0, Number.parseInt(item && item.count, 10) || 0);
            return `<li><span class="term">${label}</span><span class="count">${count}x</span></li>`;
        })
        .join("");
}

function renderWordFrequencyListHtml(list) {
    const rows = Array.isArray(list) ? list : [];
    if (!rows.length) {
        return '<li class="empty">No word frequency data available yet.</li>';
    }

    return rows
        .map((item) => {
            const label = escapeHtml(capitalize(item && item.label));
            const count = Math.max(0, Number.parseInt(item && item.count, 10) || 0);
            return "<li><span class=\"term\">" + label + "</span><span class=\"count\">" + count + "x</span></li>";
        })
        .join("");
}
function escapeRegex(text) {
    return text.replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function capitalize(text) {
    if (!text) return "";
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function renderDashboardCharts() {
    if (typeof Chart === "undefined") {
        return;
    }

    renderDashboardChartPair({
        key: "student",
        barId: "vpaa-student-professor-bar-chart",
        pieId: "vpaa-student-professor-pie-chart",
        avgId: "vpaa-student-prof-avg-rating",
        totalId: "vpaa-student-prof-total",
        countId: "vpaa-student-prof-count",
        barColor: "#4f46e5",
        barBorder: "#22c55e"
    }, vpaaChartDataByType.student || createEmptyChartData());

    renderDashboardChartPair({
        key: "professor",
        barId: "vpaa-professor-professor-bar-chart",
        pieId: "vpaa-professor-professor-pie-chart",
        avgId: "vpaa-professor-prof-avg-rating",
        totalId: "vpaa-professor-prof-total",
        countId: "vpaa-professor-prof-count",
        barColor: "rgba(59, 130, 246, 0.8)",
        barBorder: "rgba(59, 130, 246, 1)"
    }, vpaaChartDataByType.professor || createEmptyChartData());

    renderDashboardChartPair({
        key: "supervisor",
        barId: "vpaa-supervisor-professor-bar-chart",
        pieId: "vpaa-supervisor-professor-pie-chart",
        avgId: "vpaa-supervisor-prof-avg-rating",
        totalId: "vpaa-supervisor-prof-total",
        countId: "vpaa-supervisor-prof-count",
        barColor: "rgba(139, 92, 246, 0.8)",
        barBorder: "rgba(139, 92, 246, 1)"
    }, vpaaChartDataByType.supervisor || createEmptyChartData());
}

function renderDashboardChartPair(config, chartData) {
    const barCtx = document.getElementById(config.barId);
    const pieCtx = document.getElementById(config.pieId);
    const chartKey = dashboardCharts[config.key];

    if (barCtx) {
        const sectionSeries = window.AppChartDesign.buildSectionSeries(chartData.categoryScores, {
            labelKey: 'category',
            valueKey: 'score'
        });
        chartKey.bar = window.AppChartDesign.renderBarChart(barCtx, {
            labels: sectionSeries.labels,
            values: sectionSeries.values,
            fullLabels: sectionSeries.fullLabels,
            label: 'Average Score',
            colors: [config.barColor || '#4f46e5', config.barBorder || '#22c55e'],
            maxValue: 5,
            stepSize: 1,
            tooltipDecimals: 2
        });
    }

    if (pieCtx) {
        chartKey.pie = window.AppChartDesign.renderRatingDistributionChart(pieCtx, {
            ratingDistribution: chartData.ratingDistribution,
            averageRating: chartData.averageRating
        });
    }

    const avgRatingEl = document.getElementById(config.avgId);
    const totalEvalEl = document.getElementById(config.totalId);
    const profCountEl = document.getElementById(config.countId);

    if (avgRatingEl) avgRatingEl.textContent = Number(chartData.averageRating || 0).toFixed(1);
    if (totalEvalEl) totalEvalEl.textContent = String(chartData.totalEvaluations || 0);
    if (profCountEl) profCountEl.textContent = String(chartData.evaluatedCount || 0);
}

function setupProfilePhotoUpload() {
    const input = document.getElementById("profilePhotoInput");
    const preview = document.getElementById("profilePhotoPreview");
    const placeholder = document.getElementById("profilePhotoPlaceholder");

    if (!input || !preview || !placeholder) return;

    const fullName = getProfileFullName();
    placeholder.textContent = buildInitials(fullName) || "VP";

    const storedPhoto = SharedData.getProfilePhoto('vpaa');
    if (storedPhoto) {
        preview.src = storedPhoto;
        preview.classList.add("active");
        placeholder.style.display = "none";
    }

    input.addEventListener("change", function () {
        const file = input.files && input.files[0];
        if (!file) return;

        const allowedTypes = ["image/jpeg", "image/png", "image/webp"];
        if (!allowedTypes.includes(String(file.type || "").toLowerCase())) {
            alert("Please choose a JPG, JPEG, PNG, or WEBP image.");
            input.value = "";
            return;
        }

        if (Number(file.size || 0) > (2 * 1024 * 1024)) {
            alert("Please choose an image smaller than 2MB.");
            input.value = "";
            return;
        }

        const localPreviewUrl = URL.createObjectURL(file);
        preview.src = localPreviewUrl;
        preview.classList.add("active");
        placeholder.style.display = "none";

        if (typeof SharedData.uploadProfilePhoto !== "function") {
            const reader = new FileReader();
            reader.onload = function () {
                preview.src = reader.result;
                preview.classList.add("active");
                placeholder.style.display = "none";
                SharedData.setProfilePhoto('vpaa', reader.result);
                URL.revokeObjectURL(localPreviewUrl);
                input.value = "";
            };
            reader.readAsDataURL(file);
            return;
        }

        try {
            const savedPhoto = SharedData.uploadProfilePhoto(file);
            if (savedPhoto) {
                preview.src = savedPhoto;
            }
            preview.classList.add("active");
            placeholder.style.display = "none";
        } catch (error) {
            alert(error && error.message ? error.message : "Failed to upload the profile image.");
            const storedPhoto = SharedData.getProfilePhoto('vpaa');
            if (storedPhoto) {
                preview.src = storedPhoto;
                preview.classList.add("active");
                placeholder.style.display = "none";
            } else {
                preview.removeAttribute("src");
                preview.classList.remove("active");
                placeholder.style.display = "";
            }
        } finally {
            URL.revokeObjectURL(localPreviewUrl);
            input.value = "";
        }
    });
}

function getProfileFullName() {
    const items = document.querySelectorAll("#profile-view .profile-item");
    for (const item of items) {
        const label = item.querySelector(".profile-label");
        if (label && label.textContent.trim() === "Full Name") {
            const value = item.querySelector(".profile-value");
            return value ? value.textContent.trim() : "";
        }
    }
    return "";
}

function buildInitials(name) {
    if (!name) return "";
    const parts = name.split(" ").filter(Boolean);
    if (!parts.length) return "";
    const first = parts[0][0] || "";
    const last = parts.length > 1 ? parts[parts.length - 1][0] : "";
    return (first + last).toUpperCase();
}

function setupProfileActions() {
    const toggleButtons = document.querySelectorAll(".js-toggle-account-form");
    const closeButtons = document.querySelectorAll(".js-close-account-form");
    if (!toggleButtons.length && !closeButtons.length) return;

    toggleButtons.forEach((button) => {
        button.addEventListener("click", function () {
            const targetId = this.getAttribute("data-target");
            if (!targetId) return;
            hideAccountActionCards();
            const targetCard = document.getElementById(targetId);
            if (targetCard) {
                targetCard.style.display = "block";
                const prefersReducedMotion = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
                targetCard.scrollIntoView({ behavior: prefersReducedMotion ? "auto" : "smooth", block: "start" });
            }
        });
    });

    closeButtons.forEach((button) => {
        button.addEventListener("click", function () {
            const targetId = this.getAttribute("data-target");
            const targetCard = targetId ? document.getElementById(targetId) : null;
            if (targetCard) {
                const form = targetCard.querySelector("form");
                if (form) {
                    form.reset();
                    clearFormMessage(form);
                }
                targetCard.style.display = "none";
            }
        });
    });
}

function hideAccountActionCards() {
    document.querySelectorAll(".account-action-card").forEach((card) => {
        const form = card.querySelector("form");
        if (form) clearFormMessage(form);
        card.style.display = "none";
    });
}

function showFormMessage(form, message, type) {
    if (!form) return;
    clearFormMessage(form);

    const messageDiv = document.createElement("div");
    const tone = type === "error" ? "error" : (type === "success" ? "success" : "info");
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
    const existing = form.querySelector(".form-message");
    if (existing) existing.remove();
}

function setupChangeEmailForm() {
    const form = document.getElementById("changeEmailForm");
    if (!form) return;

    form.addEventListener("submit", function (e) {
        e.preventDefault();
        handleChangeEmail();
    });
}

function handleChangeEmail() {
    const form = document.getElementById("changeEmailForm");
    const currentEmail = document.getElementById("currentEmail").value.trim();
    const newEmail = document.getElementById("newEmail").value.trim();
    const confirmEmail = document.getElementById("confirmEmail").value.trim();

    if (!newEmail || !confirmEmail) {
        showFormMessage(form, "Please fill out all email fields.", "error");
        return;
    }

    if (newEmail !== confirmEmail) {
        showFormMessage(form, "New email and confirmation do not match.", "error");
        return;
    }

    if (currentEmail && newEmail.toLowerCase() === currentEmail.toLowerCase()) {
        showFormMessage(form, "New email must be different from the current email.", "error");
        return;
    }

    if (!SharedData.changeOwnEmail) {
        showFormMessage(form, "Email update service is unavailable.", "error");
        return;
    }

    try {
        const result = SharedData.changeOwnEmail(currentEmail, newEmail);
        const nextEmail = String(result && result.email || newEmail).trim();

        const profileEmail = document.getElementById("profileEmail");
        if (profileEmail) profileEmail.textContent = nextEmail;
        const currentEmailInput = document.getElementById("currentEmail");
        if (currentEmailInput) {
            currentEmailInput.value = nextEmail;
            currentEmailInput.defaultValue = nextEmail;
        }
    } catch (error) {
        console.error("[VPAA] Failed to update email.", error);
        showFormMessage(form, error && error.message ? error.message : "Failed to update email.", "error");
        return;
    }

    if (form) {
        form.reset();
        showFormMessage(form, "Email updated successfully.", "success");
    }
}

function setupChangePasswordForm() {
    const form = document.getElementById("changePasswordForm");
    if (!form) return;

    form.addEventListener("submit", function (e) {
        e.preventDefault();
        handleChangePassword();
    });
}

function handleChangePassword() {
    const form = document.getElementById("changePasswordForm");
    const currentPassword = document.getElementById("currentPassword").value.trim();
    const newPassword = document.getElementById("newPassword").value.trim();
    const confirmPassword = document.getElementById("confirmPassword").value.trim();

    if (!currentPassword || !newPassword || !confirmPassword) {
        showFormMessage(form, "Please fill out all password fields.", "error");
        return;
    }

    if (newPassword !== confirmPassword) {
        showFormMessage(form, "New password and confirmation do not match.", "error");
        return;
    }

    if (!SharedData.changeOwnPassword) {
        showFormMessage(form, "Password update service is unavailable.", "error");
        return;
    }

    try {
        SharedData.changeOwnPassword(currentPassword, newPassword);
    } catch (error) {
        console.error("[VPAA] Failed to update password.", error);
        showFormMessage(form, error && error.message ? error.message : "Failed to update password.", "error");
        return;
    }

    if (form) {
        form.reset();
        showFormMessage(form, "Password updated successfully.", "success");
    }
}

function setupPasswordToggles() {
    const toggleButtons = document.querySelectorAll(".toggle-password");
    if (!toggleButtons.length) return;

    toggleButtons.forEach((button) => {
        button.addEventListener("click", function () {
            const targetId = this.getAttribute("data-target");
            const input = document.getElementById(targetId);
            const icon = this.querySelector("i");
            if (!input || !icon) return;

            const isHidden = input.type === "password";
            input.type = isHidden ? "text" : "password";
            icon.classList.toggle("fa-eye", !isHidden);
            icon.classList.toggle("fa-eye-slash", isHidden);
            this.setAttribute("aria-label", isHidden ? "Hide password" : "Show password");
        });
    });
}

function renderProfessors(list, emptyMessage) {
    elements.professorGrid.innerHTML = "";
    if (list.length === 0) {
        const emptyState = document.createElement("div");
        emptyState.className = "professor-card professor-card-empty";
        const message = document.createElement("p");
        message.textContent = emptyMessage || "No professors match the current filters.";
        emptyState.appendChild(message);
        elements.professorGrid.appendChild(emptyState);
        return;
    }

    list.forEach((prof) => {
        elements.professorGrid.appendChild(createProfessorCard(prof));
    });
}

function createProfessorCard(prof) {
    const card = document.createElement("article");
    card.className = "professor-card professor-card-compact";
    card.setAttribute("role", "button");
    card.setAttribute("tabindex", "0");
    card.setAttribute("aria-label", `Open professor analytics for ${prof.name}`);
    card.innerHTML = `
        ${buildProfessorIdentityBlock(prof)}
        <div class="vpaa-professor-card-actions">
            <button type="button" class="action-btn analytics" data-vpaa-action="analytics" data-professor-id="${escapeAttr(prof.userId || normalizeVpaaProfessorUserId(prof.id))}" title="Analytics" aria-label="Open analytics for ${escapeAttr(prof.name)}">
                <i class="fas fa-chart-line"></i>
            </button>
            <button type="button" class="action-btn file" data-vpaa-action="faculty-files" data-professor-id="${escapeAttr(prof.userId || normalizeVpaaProfessorUserId(prof.id))}" title="Faculty Files" aria-label="Open faculty files for ${escapeAttr(prof.name)}">
                <i class="fas fa-folder-open"></i>
            </button>
        </div>
    `;

    const openReport = () => openProfessorReportModal(prof);
    card.addEventListener("click", openReport);
    card.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            openReport();
        }
    });

    card.querySelectorAll("[data-vpaa-action]").forEach(function (button) {
        button.addEventListener("click", function (event) {
            event.preventDefault();
            event.stopPropagation();
            const action = String(button.getAttribute("data-vpaa-action") || "").trim();
            const professorId = String(button.getAttribute("data-professor-id") || "").trim();
            if (action === "faculty-files") {
                openVpaaProfessorFileOptions(professorId);
                return;
            }
            viewVpaaProfessorAnalytics(professorId);
        });
    });

    return card;
}

function buildProfessorIdentityBlock(prof) {
    const deptClass = toDeptClass(prof.department);
    const initials = getInitials(prof.name) || "PR";
    const photoData = sanitizePhotoSource(prof.photoData);
    const avatarHtml = photoData
        ? `<img class="professor-avatar-image" src="${escapeAttr(photoData)}" alt="${escapeAttr(prof.name)} photo">`
        : `<span class="professor-avatar-fallback">${escapeHtml(initials)}</span>`;

    return `
        <div class="professor-info">
            <div class="professor-avatar professor-avatar-photo">${avatarHtml}</div>
            <div class="professor-details">
                <div class="professor-name-row">
                    <h3>${escapeHtml(prof.name)}</h3>
                    <span class="dept-badge ${escapeAttr(deptClass)}">${escapeHtml(prof.department)}</span>
                </div>
                <div class="professor-employee">${escapeHtml(prof.employeeId)} | ${escapeHtml(prof.semester)}</div>
                <div class="professor-position">${escapeHtml(prof.rank)}</div>
            </div>
        </div>
    `;
}

function buildProfessorReportDetailsHtml(prof) {
    const wordFrequency = getWordFrequencyForProfessor(prof);
    const wordFrequencyHtml = renderWordFrequencyListHtml(wordFrequency);
    const combinedComments = buildCombinedCommentEntries(prof);
    const combinedCommentsHtml = renderCombinedCommentsHtml(combinedComments);

    return `
        <div class="vpaa-report-details" data-prof-id="${escapeAttr(prof.id)}" data-popup-type="student">
            ${buildProfessorIdentityBlock(prof)}
            <div class="professor-stats">
                <div class="stat-item">
                    <i class="fas fa-star"></i>
                    <span><strong>${prof.overall.toFixed(1)}</strong> overall rating</span>
                </div>
                <div class="stat-item">
                    <i class="fas fa-chart-line"></i>
                    <span>${prof.responseRate}% response rate</span>
                </div>
                <div class="stat-item">
                    <i class="fas fa-file-alt"></i>
                    <span>${prof.evaluations} evaluations - ${prof.students} students</span>
                </div>
            </div>
            <div class="vpaa-popup-analytics-filter">
                <label for="vpaaPopupEvalType">Evaluation Type</label>
                <select id="vpaaPopupEvalType" class="vpaa-popup-eval-type" data-prof-id="${escapeAttr(prof.id)}">
                    <option value="student">Student to Professor</option>
                    <option value="professor">Professor to Professor</option>
                    <option value="supervisor">Supervisor to Professor</option>
                </select>
            </div>
            <div class="vpaa-section">
                <div class="vpaa-section-title" data-popup-category-title>Category Ratings</div>
                <div class="vpaa-criteria" data-popup-category-rows></div>
            </div>
            <div class="vpaa-section">
                <div class="vpaa-section-title" data-popup-distribution-title>Rating Distribution</div>
                <div class="vpaa-distribution" data-popup-distribution-rows></div>
                <div class="vpaa-section-title vpaa-word-frequency-title">Word Frequency Snapshot</div>
                <div class="vpaa-word-frequency-single">
                    <ul class="word-frequency-list">${wordFrequencyHtml}</ul>
                </div>
            </div>
            <div class="vpaa-section comments-header">
                <div class="vpaa-section-title">Comments (${combinedComments.length})</div>
                <button class="btn-summary btn-ai-analytics" data-prof-id="${escapeAttr(prof.id)}" aria-label="Run AI analytics for ${escapeAttr(prof.name)}">
                    AI Analytics
                </button>
            </div>
            <div class="vpaa-ai-insights" data-ai-insight-output aria-live="polite"></div>
            <div class="vpaa-comments-card">
                <ul class="vpaa-comment-list vpaa-comment-list-combined">${combinedCommentsHtml}</ul>
            </div>
        </div>
    `;
}

function normalizePopupReportType(value) {
    const token = String(value || "").trim().toLowerCase();
    if (token === "professor") return "professor";
    if (token === "supervisor") return "supervisor";
    return "student";
}

function resolveProfessorPopupAnalytics(prof, typeKey) {
    const normalized = normalizePopupReportType(typeKey);
    const byType = prof && typeof prof.analyticsByType === "object" && prof.analyticsByType
        ? prof.analyticsByType
        : {};
    if (byType[normalized]) return byType[normalized];
    return buildProfessorAnalyticsForType(normalized, [], prof && prof.semester);
}

function renderPopupCategoryRows(categoryScores) {
    const rows = Array.isArray(categoryScores) ? categoryScores : [];
    if (!rows.length) {
        return '<div class="vpaa-empty-metric">No category ratings available.</div>';
    }

    return rows.map((item) => {
        const score = Number(item && item.score || 0);
        const width = Math.min(100, Math.round((score / 5) * 100));
        return `
            <div class="vpaa-criteria-row">
                <span>${escapeHtml(item.category || "General Questions")}</span>
                <div class="vpaa-bar">
                    <div class="vpaa-fill" style="width: ${width}%"></div>
                </div>
                <span class="vpaa-score">${score.toFixed(1)}</span>
            </div>
        `;
    }).join("");
}

function renderPopupDistributionRows(distribution) {
    const dist = distribution && typeof distribution === "object" ? distribution : {};
    const total = Object.values(dist).reduce((sum, count) => sum + Number(count || 0), 0) || 1;
    return [5, 4, 3, 2, 1].map((score) => {
        const count = Number(dist[score] || 0);
        const width = Math.round((count / total) * 100);
        return `
            <div class="vpaa-distribution-row">
                <span>${score}</span>
                <div class="vpaa-bar">
                    <div class="vpaa-fill" style="width: ${width}%"></div>
                </div>
                <span>${count}</span>
            </div>
        `;
    }).join("");
}

function renderPopupAnalyticsForType(prof, typeKey) {
    if (!prof || !elements.reportModalBody) return;
    const scope = elements.reportModalBody.querySelector(".vpaa-report-details");
    if (!scope) return;

    const normalized = normalizePopupReportType(typeKey);
    const analytics = resolveProfessorPopupAnalytics(prof, normalized);
    const meta = getPopupEvaluationTypeMeta(normalized);

    const categoryRowsEl = scope.querySelector("[data-popup-category-rows]");
    const distributionRowsEl = scope.querySelector("[data-popup-distribution-rows]");
    const categoryTitleEl = scope.querySelector("[data-popup-category-title]");
    const distributionTitleEl = scope.querySelector("[data-popup-distribution-title]");
    const selectEl = scope.querySelector(".vpaa-popup-eval-type");

    if (selectEl) {
        selectEl.value = normalized;
    }
    if (categoryTitleEl) {
        categoryTitleEl.textContent = `Category Ratings (${meta.label})`;
    }
    if (distributionTitleEl) {
        distributionTitleEl.textContent = `Rating Distribution (${meta.label})`;
    }
    if (categoryRowsEl) {
        categoryRowsEl.innerHTML = renderPopupCategoryRows(analytics.categoryScores);
    }
    if (distributionRowsEl) {
        distributionRowsEl.innerHTML = renderPopupDistributionRows(analytics.ratingDistribution);
    }

    if (elements.reportModalTitle) {
        elements.reportModalTitle.textContent = `${prof.name} - ${meta.label} Report`;
    }
    scope.setAttribute("data-popup-type", normalized);
}

function viewVpaaProfessorAnalytics(professorId) {
    const selectedSemester = currentVpaaAnalyticsSemester || "all";
    const semesterOptions = getVpaaSemesterOptions();
    const normalizedSemester = semesterOptions.some(function (option) {
        return option.id === selectedSemester;
    }) ? selectedSemester : "all";
    currentVpaaAnalyticsSemester = normalizedSemester;

    const normalizedEvaluationType = getVpaaEvaluationTypeMeta(currentVpaaAnalyticsEvaluationType).id;
    currentVpaaAnalyticsEvaluationType = normalizedEvaluationType;

    const professor = getVpaaProfessorById(professorId, normalizedSemester);
    if (!professor) {
        alert("Professor not found. Please try again.");
        return;
    }

    const baseProfessorId = normalizeVpaaProfessorUserId(professor.userId || professor.id);
    currentVpaaAnalyticsProfessorId = baseProfessorId;

    const evaluationMeta = getVpaaEvaluationTypeMeta(normalizedEvaluationType);
    const analyticsContext = buildVpaaDatabaseContext();
    const snapshot = getVpaaProfessorEvaluationSnapshot(professor, normalizedSemester, normalizedEvaluationType, analyticsContext);
    const trend = buildVpaaProfessorHistoricalTrend(baseProfessorId, analyticsContext);
    const trendSummary = trend && trend.summary ? trend.summary : {
        hasSufficientData: false,
        direction: "insufficient",
        percentChange: null,
        variationPercent: null,
        isConsistent: false,
        declinePatternDetected: false,
        statement: "Insufficient historical data to determine performance trend."
    };
    const trendRows = Array.isArray(trend && trend.points) ? trend.points : [];

    const totalRaters = Number(snapshot.totalRaters || 0);
    const evaluatedCount = Number(snapshot.evaluatedCount || 0);
    const notEvaluatedCount = Number(snapshot.notEvaluatedCount || Math.max(totalRaters - evaluatedCount, 0));
    const averageRating = parseFloat(snapshot.averageRating) || 0;
    const completionPercentage = totalRaters > 0 ? Math.round((evaluatedCount / totalRaters) * 100) : 0;
    const evaluatorLabel = evaluationMeta.unitLabel.endsWith("s")
        ? evaluationMeta.unitLabel.slice(0, -1)
        : evaluationMeta.unitLabel;
    const displaySemesterLabel = normalizedSemester === "all"
        ? "all semesters"
        : getVpaaSemesterLabel(normalizedSemester);

    let status = "Excellent";
    let statusColor = "#10b981";
    if (completionPercentage < 50) {
        status = "Needs Attention";
        statusColor = "#ef4444";
    } else if (completionPercentage < 75) {
        status = "Good";
        statusColor = "#f59e0b";
    }

    const feedbackRows = Array.isArray(snapshot.qualitativeResponses) ? snapshot.qualitativeResponses : [];
    const deptClass = toDeptClass(professor.department);

    elements.reportModalBody.innerHTML = `
        <div class="analytics-view">
            <div class="analytics-header vpaa-analytics-header">
                ${buildVpaaProfessorAvatarHtml(professor, "analytics-avatar")}
                <div class="analytics-name">
                    <h2>${escapeHtml(professor.name || "Professor")}</h2>
                    <span class="dept-badge ${escapeAttr(deptClass)}">${escapeHtml(professor.department || "N/A")}</span>
                </div>
                <div class="vpaa-analytics-header-actions">
                    <button type="button" class="btn-faculty-files" data-vpaa-action="faculty-files" data-professor-id="${escapeAttr(baseProfessorId)}">
                        <i class="fas fa-folder-open"></i>
                        Faculty Files
                    </button>
                </div>
            </div>

            <div class="analytics-filters">
                <div class="analytics-filter">
                    <label for="analytics-semester-select">Semester</label>
                    <select id="analytics-semester-select">
                        ${buildVpaaSemesterOptionsHtml(normalizedSemester)}
                    </select>
                </div>
                <div class="analytics-filter">
                    <label for="analytics-evaluation-type">Evaluation Type</label>
                    <select id="analytics-evaluation-type">
                        ${buildVpaaEvaluationTypeOptionsHtml(normalizedEvaluationType)}
                    </select>
                </div>
                <div class="analytics-filter-summary">
                    <span>${normalizedSemester === "all" ? "Showing overall data" : `Showing ${escapeHtml(displaySemesterLabel)} data`} &bull; ${escapeHtml(evaluationMeta.label)}</span>
                </div>
            </div>

            <div class="analytics-stats-grid">
                <div class="stat-card rating">
                    <div class="stat-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Average Rating</h3>
                        <p class="stat-value">${averageRating.toFixed(1)}<span class="stat-unit">/5.0</span></p>
                        <div class="rating-stars">
                            ${generateVpaaStarRating(averageRating)}
                        </div>
                    </div>
                </div>

                <div class="stat-card status">
                    <div class="stat-icon">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Current Status</h3>
                        <p class="stat-value status-badge" style="color: ${escapeAttr(statusColor)}">${escapeHtml(status)}</p>
                    </div>
                </div>
            </div>

            <div class="analytics-section">
                <h3 class="section-title">
                    <i class="${escapeAttr(evaluationMeta.icon)}"></i>
                    ${escapeHtml(evaluationMeta.statusTitle)}
                </h3>
                <div class="evaluation-stats">
                    <div class="evaluation-item evaluated">
                        <div class="evaluation-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="evaluation-info">
                            <h4>Evaluated</h4>
                            <p class="evaluation-count">${evaluatedCount} ${escapeHtml(evaluationMeta.unitLabel)}</p>
                            <div class="progress-bar">
                                <div class="progress-fill evaluated-fill" style="width: ${totalRaters > 0 ? (evaluatedCount / totalRaters) * 100 : 0}%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="evaluation-item not-evaluated">
                        <div class="evaluation-icon">
                            <i class="fas fa-clock"></i>
                        </div>
                        <div class="evaluation-info">
                            <h4>Not Yet Evaluated</h4>
                            <p class="evaluation-count">${notEvaluatedCount} ${escapeHtml(evaluationMeta.unitLabel)}</p>
                            <div class="progress-bar">
                                <div class="progress-fill not-evaluated-fill" style="width: ${totalRaters > 0 ? (notEvaluatedCount / totalRaters) * 100 : 0}%"></div>
                            </div>
                        </div>
                    </div>

                    <div class="evaluation-item total">
                        <div class="evaluation-icon">
                            <i class="${escapeAttr(evaluationMeta.icon)}"></i>
                        </div>
                        <div class="evaluation-info">
                            <h4>${escapeHtml(evaluationMeta.totalLabel)}</h4>
                            <p class="evaluation-count">${totalRaters} ${escapeHtml(evaluationMeta.unitLabel)}</p>
                        </div>
                    </div>
                </div>
            </div>

            <div class="analytics-section historical-trend-section">
                <h3 class="section-title">
                    <i class="fas fa-chart-line"></i>
                    Historical Trend Analytics
                </h3>
                <div class="historical-trend-banner">
                    <p class="historical-trend-statement">${escapeHtml(trendSummary.statement || "Insufficient historical data to determine performance trend.")}</p>
                    <p class="historical-trend-note">Combined source metric uses Student 50%, Peer 25%, Supervisor 25%, across latest 4 semesters.</p>
                    <div class="historical-trend-chips">
                        <span class="historical-trend-chip ${escapeAttr(getVpaaHistoricalTrendDirectionClass(trendSummary.direction))}">
                            Direction: ${escapeHtml(getVpaaHistoricalTrendDirectionLabel(trendSummary.direction))}
                        </span>
                        <span class="historical-trend-chip neutral">
                            Change: ${escapeHtml(formatVpaaHistoricalTrendSignedPercent(trendSummary.percentChange))}
                        </span>
                        <span class="historical-trend-chip ${escapeAttr(getVpaaHistoricalTrendConsistencyClass(trendSummary))}">
                            Consistency: ${escapeHtml(getVpaaHistoricalTrendConsistencyLabel(trendSummary))}
                        </span>
                        <span class="historical-trend-chip ${escapeAttr(getVpaaHistoricalTrendDeclinePatternClass(trendSummary))}">
                            Decline Pattern: ${escapeHtml(getVpaaHistoricalTrendDeclinePatternLabel(trendSummary))}
                        </span>
                    </div>
                </div>
                <div class="historical-trend-table-wrap">
                    <table class="historical-trend-table">
                        <thead>
                            <tr>
                                <th>Semester</th>
                                <th>Combined Score (/5)</th>
                                <th>Delta vs Prior</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${trendRows.length > 0 ? trendRows.map(function (point) {
                                return `
                                    <tr>
                                        <td>${escapeHtml(String(point.semesterLabel || point.semesterId || "Semester"))}</td>
                                        <td>${Number.isFinite(Number(point.score)) ? Number(point.score).toFixed(2) : "N/A"}</td>
                                        <td>${escapeHtml(formatVpaaHistoricalTrendDelta(point.delta))}</td>
                                    </tr>
                                `;
                            }).join("") : `
                                <tr>
                                    <td colspan="3" style="text-align:center; padding:14px;">No semester trend data available.</td>
                                </tr>
                            `}
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="qualitative-responses-section">
                <div class="section-header-with-button">
                    <h3 class="section-title">
                        <i class="fas fa-comments"></i>
                        ${escapeHtml(evaluationMeta.label)} Feedback
                    </h3>
                    <button type="button" class="btn-ai-analytics" id="vpaa-ai-analytics-btn" data-prof-id="${escapeAttr(professor.id)}">
                        <i class="fas fa-robot"></i>
                        AI Analytics
                    </button>
                </div>
                <div class="vpaa-ai-insights" data-ai-insight-output aria-live="polite"></div>
                <div class="qualitative-responses-list">
                    ${feedbackRows.length > 0 ? feedbackRows.map(function (response) {
                        return `
                            <div class="response-card compact">
                                <div class="response-header">
                                    <div class="response-icon">
                                        <i class="${escapeAttr(evaluationMeta.feedbackIcon)}"></i>
                                    </div>
                                    <div class="response-meta">
                                        <span class="response-label">${escapeHtml(evaluationMeta.label)} Feedback</span>
                                        <span class="response-student">${escapeHtml(response.studentName || evaluatorLabel)} &bull; ${escapeHtml(response.studentNumber || "N/A")}</span>
                                    </div>
                                    <span class="response-date">${escapeHtml(response.date || "-")}</span>
                                </div>
                                <p class="response-text">"${escapeHtml(response.text || "")}"</p>
                            </div>
                        `;
                    }).join("") : `
                        <div class="no-responses">
                            <p>No ${escapeHtml(evaluationMeta.label.toLowerCase())} feedback available for ${escapeHtml(displaySemesterLabel)}.</p>
                        </div>
                    `}
                </div>
            </div>
        </div>
    `;

    if (elements.reportModalTitle) {
        elements.reportModalTitle.textContent = "Professor Analytics";
    }
    elements.reportModal.classList.add("active");
    elements.reportModal.style.display = "flex";
    elements.reportModal.setAttribute("aria-hidden", "false");
    document.body.classList.add("vpaa-modal-open");
}

function setupReportModalEvents() {
    if (!elements.reportModal || !elements.reportModalBody) return;

    if (elements.reportModalClose) {
        elements.reportModalClose.addEventListener("click", closeReportModal);
    }

    elements.reportModal.addEventListener("click", (event) => {
        if (event.target === elements.reportModal) {
            closeReportModal();
        }
    });

    elements.reportModalBody.addEventListener("click", (event) => {
        const fileBtn = event.target.closest("[data-vpaa-action='faculty-files']");
        if (fileBtn) {
            const professorId = String(fileBtn.getAttribute("data-professor-id") || "").trim();
            openVpaaProfessorFileOptions(professorId);
            return;
        }

        const analyticsBtn = event.target.closest(".btn-ai-analytics[data-prof-id]");
        if (!analyticsBtn) return;
        const profId = String(analyticsBtn.getAttribute("data-prof-id") || "");
        const scope = analyticsBtn.closest(".analytics-view");
        const outputEl = scope ? scope.querySelector("[data-ai-insight-output]") : null;
        runAiAnalyticsForProfessor(profId, outputEl, analyticsBtn);
    });

    elements.reportModalBody.addEventListener("change", (event) => {
        const semesterSelect = event.target.closest("#analytics-semester-select");
        if (semesterSelect) {
            currentVpaaAnalyticsSemester = String(semesterSelect.value || "all").trim() || "all";
            if (currentVpaaAnalyticsProfessorId) {
                viewVpaaProfessorAnalytics(currentVpaaAnalyticsProfessorId);
            }
            return;
        }

        const typeSelect = event.target.closest("#analytics-evaluation-type");
        if (!typeSelect) return;
        currentVpaaAnalyticsEvaluationType = normalizeVpaaAnalyticsEvaluationType(typeSelect.value);
        if (currentVpaaAnalyticsProfessorId) {
            viewVpaaProfessorAnalytics(currentVpaaAnalyticsProfessorId);
        }
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && isReportModalOpen()) {
            closeReportModal();
        }
    });
}

function openProfessorReportModal(prof) {
    const professorId = normalizeVpaaProfessorUserId(prof && (prof.userId || prof.id));
    if (!professorId) return;
    viewVpaaProfessorAnalytics(professorId);
}

function closeReportModal() {
    if (!elements.reportModal || !elements.reportModalBody) return;

    elements.reportModal.classList.remove("active");
    elements.reportModal.style.display = "none";
    elements.reportModal.setAttribute("aria-hidden", "true");
    elements.reportModalBody.innerHTML = "";
    currentVpaaAnalyticsProfessorId = null;
    document.body.classList.remove("vpaa-modal-open");
}

function isReportModalOpen() {
    return !!(elements.reportModal && (elements.reportModal.classList.contains("active") || elements.reportModal.style.display === "flex"));
}

function parseVpaaFileNameFromDisposition(headerValue) {
    const value = String(headerValue || "").trim();
    if (!value) return "";

    const utfMatch = value.match(/filename\*=UTF-8''([^;]+)/i);
    if (utfMatch && utfMatch[1]) {
        try {
            return decodeURIComponent(String(utfMatch[1]).trim());
        } catch (_error) {
            return String(utfMatch[1]).replace(/["']/g, "").trim();
        }
    }

    const simpleMatch = value.match(/filename="?([^";]+)"?/i);
    return simpleMatch && simpleMatch[1] ? String(simpleMatch[1]).trim() : "";
}

function getVpaaReportSemesterChoices() {
    return getVpaaSemesterOptions().filter(function (option) {
        return option && option.id && option.id !== "all";
    });
}

function normalizeVpaaReportLoadType(value) {
    return String(value || "").trim().toLowerCase() === "excess" ? "excess" : "main";
}

function getVpaaReportLoadTypeLabel(value) {
    return normalizeVpaaReportLoadType(value) === "excess" ? "Excess Load" : "Main Load";
}

function getVpaaFacultyPaperSnapshot() {
    if (typeof SharedData.getFacultyPapers === "function") {
        return SharedData.getFacultyPapers();
    }
    return [];
}

function hasVpaaStoredFacultyPaperFile(paper) {
    if (!paper || typeof paper !== "object") return false;
    if (String(paper.latest_file_path || paper.latestFilePath || "").trim()) return true;
    const versions = Array.isArray(paper.pdf_versions) ? paper.pdf_versions : (Array.isArray(paper.pdfVersions) ? paper.pdfVersions : []);
    return versions.some(function (version) {
        return String(version && (version.file_path || version.filePath) || "").trim();
    });
}

function getVpaaStoredFacultyPaperVersionNo(paper) {
    const versions = Array.isArray(paper && paper.pdf_versions)
        ? paper.pdf_versions
        : (Array.isArray(paper && paper.pdfVersions) ? paper.pdfVersions : []);
    let latest = 0;
    versions.forEach(function (version) {
        const versionNo = Number(version && (version.version_no || version.versionNo));
        if (Number.isFinite(versionNo) && versionNo > latest) {
            latest = versionNo;
        }
    });
    return latest > 0 ? latest : null;
}

function buildVpaaAcknowledgementSemesterChoices(professor, loadType) {
    const professorId = normalizeVpaaProfessorUserId(professor && (professor.userId || professor.id));
    if (!professorId) return [];
    const selectedLoadType = loadType ? normalizeVpaaReportLoadType(loadType) : "";
    const papers = getVpaaFacultyPaperSnapshot();
    const bySemester = new Map();

    (Array.isArray(papers) ? papers : []).forEach(function (paper) {
        if (!paper || typeof paper !== "object") return;
        if (normalizeVpaaProfessorUserId(paper.professor_user_id || paper.professorUserId) !== professorId) return;
        if (!hasVpaaStoredFacultyPaperFile(paper)) return;

        const paperLoadType = normalizeVpaaReportLoadType(paper.load_type || paper.loadType);
        if (selectedLoadType && paperLoadType !== selectedLoadType) return;

        const semesterId = String(paper.semester_id || paper.semesterId || "").trim();
        if (!semesterId) return;

        bySemester.set(`${semesterId}|${paperLoadType}`, {
            id: semesterId,
            label: String(paper.semester_label || paper.semesterLabel || getVpaaSemesterLabel(semesterId) || semesterId).trim(),
            loadType: paperLoadType,
            paper: paper
        });
    });

    const order = getVpaaReportSemesterChoices().map(function (option) {
        return option.id;
    });
    return Array.from(bySemester.values()).sort(function (left, right) {
        const leftIndex = order.indexOf(left.id);
        const rightIndex = order.indexOf(right.id);
        if (leftIndex === -1 && rightIndex === -1) return String(left.label).localeCompare(String(right.label));
        if (leftIndex === -1) return 1;
        if (rightIndex === -1) return -1;
        return leftIndex - rightIndex;
    });
}

function ensureVpaaReportSemesterModal() {
    let modal = document.getElementById("vpaaReportSemesterModal");
    if (modal) return modal;

    modal = document.createElement("div");
    modal.id = "vpaaReportSemesterModal";
    modal.className = "modal hr-report-modal vpaa-report-modal-picker";
    modal.innerHTML = `
        <div class="modal-content hr-report-modal-content" role="dialog" aria-modal="true" aria-label="Select report semester">
            <div class="modal-header">
                <h2 id="vpaaReportSemesterTitle">Select Semester</h2>
                <button type="button" class="modal-close" id="vpaaReportSemesterCloseBtn" aria-label="Close semester selector">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="hr-report-modal-body">
                <p class="hr-report-modal-note" id="vpaaReportSemesterNote">Choose the semester for this report.</p>
                <div class="form-group" id="vpaaReportLoadTypeGroup" style="display:none">
                    <label for="vpaaReportLoadTypeSelect">Load Type</label>
                    <select id="vpaaReportLoadTypeSelect"></select>
                </div>
                <div class="form-group">
                    <label for="vpaaReportSemesterSelect">Semester</label>
                    <select id="vpaaReportSemesterSelect"></select>
                </div>
            </div>
            <div class="modal-actions hr-report-modal-actions">
                <button type="button" class="btn-cancel" id="vpaaReportSemesterCancelBtn">Cancel</button>
                <button type="button" class="btn-submit" id="vpaaReportSemesterConfirmBtn">Continue</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    const close = function () {
        modal.style.display = "none";
        modal._onConfirm = null;
    };

    modal.addEventListener("click", function (event) {
        if (event.target === modal) close();
    });

    const closeBtn = document.getElementById("vpaaReportSemesterCloseBtn");
    const cancelBtn = document.getElementById("vpaaReportSemesterCancelBtn");
    if (closeBtn) closeBtn.addEventListener("click", close);
    if (cancelBtn) cancelBtn.addEventListener("click", close);

    const confirmBtn = document.getElementById("vpaaReportSemesterConfirmBtn");
    if (confirmBtn) {
        confirmBtn.addEventListener("click", function () {
            const select = document.getElementById("vpaaReportSemesterSelect");
            const loadSelect = document.getElementById("vpaaReportLoadTypeSelect");
            const semesterId = String(select && select.value || "").trim();
            const loadType = normalizeVpaaReportLoadType(loadSelect && loadSelect.value);
            if (!semesterId) {
                alert("Select a semester first.");
                return;
            }
            const handler = modal._onConfirm;
            close();
            if (typeof handler === "function") {
                handler(semesterId, loadType);
            }
        });
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && modal.style.display === "flex") {
            close();
        }
    });

    return modal;
}

function openVpaaReportSemesterPicker(config) {
    const modal = ensureVpaaReportSemesterModal();
    const title = document.getElementById("vpaaReportSemesterTitle");
    const note = document.getElementById("vpaaReportSemesterNote");
    const select = document.getElementById("vpaaReportSemesterSelect");
    const loadGroup = document.getElementById("vpaaReportLoadTypeGroup");
    const loadSelect = document.getElementById("vpaaReportLoadTypeSelect");
    const confirmBtn = document.getElementById("vpaaReportSemesterConfirmBtn");
    const baseOptions = Array.isArray(config && config.options) ? config.options : [];
    const scopedOptionsProvider = typeof (config && config.loadScopedOptionsProvider) === "function"
        ? config.loadScopedOptionsProvider
        : null;
    const preferredSemesterId = String(config && config.selectedSemesterId || "").trim();
    const showLoadType = !!(config && config.showLoadType);
    const preferredLoadType = normalizeVpaaReportLoadType(config && config.selectedLoadType);

    if (loadSelect) {
        loadSelect.innerHTML = `
            <option value="main">Main Load</option>
            <option value="excess">Excess Load</option>
        `;
        loadSelect.value = preferredLoadType;
    }

    const resolveOptions = function () {
        const currentLoadType = normalizeVpaaReportLoadType(loadSelect && loadSelect.value || preferredLoadType);
        const scoped = scopedOptionsProvider ? scopedOptionsProvider(currentLoadType) : baseOptions;
        return Array.isArray(scoped) ? scoped : [];
    };

    if (!select || !resolveOptions().length) {
        alert("No semester is available for this report.");
        return;
    }

    if (title) title.textContent = String(config && config.title || "Select Semester");
    if (note) note.textContent = String(config && config.note || "Choose the semester for this report.");
    if (confirmBtn) confirmBtn.textContent = String(config && config.confirmLabel || "Continue");
    if (loadGroup) loadGroup.style.display = showLoadType ? "" : "none";

    const renderSemesterOptions = function () {
        const currentOptions = resolveOptions();
        if (!currentOptions.length) {
            select.innerHTML = '<option value="">No stored paper for this load</option>';
            return;
        }
        select.innerHTML = currentOptions.map(function (option) {
            return `<option value="${escapeAttr(option.id)}">${escapeHtml(option.label || option.id)}</option>`;
        }).join("");

        const selectedOption = currentOptions.some(function (option) {
            return option.id === preferredSemesterId;
        }) ? preferredSemesterId : String(currentOptions[0].id || "").trim();
        select.value = selectedOption;
    };

    renderSemesterOptions();
    if (loadSelect) {
        loadSelect.onchange = renderSemesterOptions;
    }

    modal._onConfirm = typeof config.onConfirm === "function" ? config.onConfirm : null;
    modal.style.display = "flex";
}

function getVpaaOverallSasrCurrentSemesterId() {
    const current = String(SharedData.getCurrentSemester ? SharedData.getCurrentSemester() : "").trim();
    const options = getVpaaReportSemesterChoices();
    if (current && options.some(function (option) { return String(option.id) === current; })) {
        return current;
    }
    return options.length ? String(options[0].id || "").trim() : "";
}

function getVpaaOverallSasrCampusOptions() {
    const campuses = SharedData.getCampuses ? SharedData.getCampuses() : [];
    const realCampuses = Array.isArray(campuses)
        ? campuses.filter(function (campus) {
            const id = normalizeVpaaToken(campus && campus.id);
            return id && id !== "all";
        })
        : [];
    return [
        { id: "all", label: "All Campuses" }
    ].concat(realCampuses.map(function (campus) {
        return {
            id: String(campus && campus.id || "").trim(),
            label: String(campus && (campus.name || campus.id) || "").trim()
        };
    }));
}

function getVpaaOverallSasrDepartments(campusId) {
    const campusToken = normalizeVpaaToken(campusId || "all");
    const departments = new Set();
    const campuses = SharedData.getCampuses ? SharedData.getCampuses() : [];

    if (campusToken && campusToken !== "all" && Array.isArray(campuses)) {
        campuses.forEach(function (campus) {
            if (normalizeVpaaToken(campus && campus.id) !== campusToken) return;
            const items = Array.isArray(campus && campus.departments) ? campus.departments : [];
            items.forEach(function (dept) {
                const value = String(dept || "").trim().toUpperCase();
                if (value) departments.add(value);
            });
        });
    }

    if (departments.size === 0 && SharedData.getAllDepartments) {
        SharedData.getAllDepartments().forEach(function (dept) {
            const value = String(dept || "").trim().toUpperCase();
            if (value) departments.add(value);
        });
    }

    const users = SharedData.getUsers ? SharedData.getUsers() : [];
    (Array.isArray(users) ? users : []).forEach(function (user) {
        if (!user || normalizeVpaaToken(user.role) !== "professor") return;
        if (campusToken !== "all" && normalizeVpaaToken(user.campus || user.campusSlug) !== campusToken) return;
        const value = String(user.department || user.institute || "").trim().toUpperCase();
        if (value) departments.add(value);
    });

    return Array.from(departments).sort();
}

function getVpaaOverallSasrPrograms(campusId) {
    const campusToken = normalizeVpaaToken(campusId || "all");
    const programs = SharedData.getPrograms ? SharedData.getPrograms() : [];
    return (Array.isArray(programs) ? programs : [])
        .filter(function (program) {
            const programCampus = normalizeVpaaToken(program && program.campusSlug);
            return campusToken === "all" || programCampus === campusToken;
        })
        .map(function (program) {
            const code = String(program && program.programCode || "").trim().toUpperCase();
            const name = String(program && program.programName || "").trim();
            const campus = String(program && program.campusSlug || "").trim().toUpperCase();
            const dept = String(program && program.departmentCode || "").trim().toUpperCase();
            const labelParts = [];
            if (campus) labelParts.push(campus);
            if (dept) labelParts.push(dept);
            labelParts.push(name ? `${code} - ${name}` : code);
            return {
                id: String(program && program.id || "").trim(),
                label: labelParts.filter(Boolean).join(" / ")
            };
        })
        .filter(function (program) { return program.id && program.label; })
        .sort(function (left, right) { return left.label.localeCompare(right.label); });
}

function ensureVpaaOverallSasrModal() {
    let modal = document.getElementById("vpaaOverallSasrModal");
    if (modal) return modal;

    modal = document.createElement("div");
    modal.id = "vpaaOverallSasrModal";
    modal.className = "modal hr-report-modal overall-sasr-modal";
    modal.innerHTML = `
        <div class="modal-content hr-report-modal-content overall-sasr-content" role="dialog" aria-modal="true" aria-label="Generate Overall SASR">
            <div class="modal-header">
                <h2>Generate Overall SASR</h2>
                <button type="button" class="modal-close" id="vpaaOverallSasrCloseBtn" aria-label="Close Overall SASR selector">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="hr-report-modal-body">
                <p class="overall-sasr-modal-note">Export professor-level SET and SEF ratings for a selected department or program.</p>
                <div class="overall-sasr-grid">
                    <div class="form-group">
                        <label for="vpaaOverallSasrCampusSelect">Campus</label>
                        <select id="vpaaOverallSasrCampusSelect"></select>
                    </div>
                    <div class="form-group">
                        <label for="vpaaOverallSasrSemesterSelect">Semester</label>
                        <select id="vpaaOverallSasrSemesterSelect"></select>
                    </div>
                    <div class="form-group">
                        <label for="vpaaOverallSasrLoadTypeSelect">Load Type</label>
                        <select id="vpaaOverallSasrLoadTypeSelect">
                            <option value="main">Main Load</option>
                            <option value="excess">Excess Load</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="vpaaOverallSasrScopeTypeSelect">Scope Type</label>
                        <select id="vpaaOverallSasrScopeTypeSelect">
                            <option value="department">Department</option>
                            <option value="program">Program</option>
                        </select>
                    </div>
                    <div class="form-group overall-sasr-field-wide" id="vpaaOverallSasrDepartmentGroup">
                        <label for="vpaaOverallSasrDepartmentSelect">Department</label>
                        <select id="vpaaOverallSasrDepartmentSelect"></select>
                    </div>
                    <div class="form-group overall-sasr-field-wide" id="vpaaOverallSasrProgramGroup" style="display:none">
                        <label for="vpaaOverallSasrProgramSelect">Program</label>
                        <select id="vpaaOverallSasrProgramSelect"></select>
                    </div>
                </div>
                <p class="overall-sasr-empty-note" id="vpaaOverallSasrEmptyNote" hidden></p>
            </div>
            <div class="modal-actions hr-report-modal-actions">
                <button type="button" class="btn-cancel" id="vpaaOverallSasrCancelBtn">Cancel</button>
                <button type="button" class="btn-submit" id="vpaaOverallSasrGenerateBtn">Generate</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    const close = function () {
        modal.style.display = "none";
    };
    const refresh = function () {
        refreshVpaaOverallSasrScopeOptions(modal);
    };

    modal.addEventListener("click", function (event) {
        if (event.target === modal) close();
    });

    const closeBtn = document.getElementById("vpaaOverallSasrCloseBtn");
    const cancelBtn = document.getElementById("vpaaOverallSasrCancelBtn");
    const campusSelect = document.getElementById("vpaaOverallSasrCampusSelect");
    const scopeSelect = document.getElementById("vpaaOverallSasrScopeTypeSelect");
    const generateBtn = document.getElementById("vpaaOverallSasrGenerateBtn");
    if (closeBtn) closeBtn.addEventListener("click", close);
    if (cancelBtn) cancelBtn.addEventListener("click", close);
    if (campusSelect) campusSelect.addEventListener("change", refresh);
    if (scopeSelect) scopeSelect.addEventListener("change", refresh);
    if (generateBtn) {
        generateBtn.addEventListener("click", async function () {
            const payload = buildVpaaOverallSasrPayload(modal);
            if (!payload) return;

            generateBtn.disabled = true;
            const previousLabel = generateBtn.textContent;
            generateBtn.textContent = "Generating...";
            const didDownload = await downloadVpaaOverallSasrReport(payload);
            generateBtn.disabled = false;
            generateBtn.textContent = previousLabel || "Generate";
            if (didDownload) close();
        });
    }

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && modal.style.display === "flex") {
            close();
        }
    });

    return modal;
}

function populateVpaaOverallSasrBaseOptions(modal) {
    const campusSelect = modal.querySelector("#vpaaOverallSasrCampusSelect");
    const semesterSelect = modal.querySelector("#vpaaOverallSasrSemesterSelect");
    const loadSelect = modal.querySelector("#vpaaOverallSasrLoadTypeSelect");
    const scopeSelect = modal.querySelector("#vpaaOverallSasrScopeTypeSelect");
    const campusOptions = getVpaaOverallSasrCampusOptions();
    const semesterOptions = getVpaaReportSemesterChoices();
    const preferredCampus = normalizeVpaaToken(elements.campusFilter && elements.campusFilter.value || "all") || "all";
    const preferredSemester = getVpaaOverallSasrCurrentSemesterId();

    if (campusSelect) {
        campusSelect.innerHTML = campusOptions.map(function (option) {
            return `<option value="${escapeAttr(option.id)}">${escapeHtml(option.label || option.id)}</option>`;
        }).join("");
        const matchedCampus = campusOptions.find(function (option) {
            return normalizeVpaaToken(option.id) === preferredCampus;
        });
        campusSelect.value = matchedCampus ? matchedCampus.id : "all";
    }
    if (semesterSelect) {
        semesterSelect.innerHTML = semesterOptions.map(function (option) {
            return `<option value="${escapeAttr(option.id)}">${escapeHtml(option.label || option.id)}</option>`;
        }).join("");
        semesterSelect.value = preferredSemester;
    }
    if (loadSelect) loadSelect.value = "main";
    if (scopeSelect) scopeSelect.value = "department";
}

function refreshVpaaOverallSasrScopeOptions(modal) {
    const campusSelect = modal.querySelector("#vpaaOverallSasrCampusSelect");
    const scopeSelect = modal.querySelector("#vpaaOverallSasrScopeTypeSelect");
    const departmentGroup = modal.querySelector("#vpaaOverallSasrDepartmentGroup");
    const programGroup = modal.querySelector("#vpaaOverallSasrProgramGroup");
    const departmentSelect = modal.querySelector("#vpaaOverallSasrDepartmentSelect");
    const programSelect = modal.querySelector("#vpaaOverallSasrProgramSelect");
    const emptyNote = modal.querySelector("#vpaaOverallSasrEmptyNote");
    const campusId = String(campusSelect && campusSelect.value || "all").trim() || "all";
    const scopeType = String(scopeSelect && scopeSelect.value || "department").trim();

    if (departmentGroup) departmentGroup.style.display = scopeType === "department" ? "" : "none";
    if (programGroup) programGroup.style.display = scopeType === "program" ? "" : "none";
    if (emptyNote) {
        emptyNote.hidden = true;
        emptyNote.textContent = "";
    }

    if (scopeType === "program") {
        const programs = getVpaaOverallSasrPrograms(campusId);
        if (programSelect) {
            programSelect.innerHTML = '<option value="">Select Program</option>' + programs.map(function (program) {
                return `<option value="${escapeAttr(program.id)}">${escapeHtml(program.label)}</option>`;
            }).join("");
        }
        if (!programs.length && emptyNote) {
            emptyNote.hidden = false;
            emptyNote.textContent = "No programs found for the selected campus.";
        }
        return;
    }

    const departments = getVpaaOverallSasrDepartments(campusId);
    if (departmentSelect) {
        departmentSelect.innerHTML = '<option value="">Select Department</option>' + departments.map(function (dept) {
            return `<option value="${escapeAttr(dept)}">${escapeHtml(dept)}</option>`;
        }).join("");
        const preferred = elements.departmentFilter && elements.departmentFilter.value && elements.departmentFilter.value !== "all"
            ? String(elements.departmentFilter.value).toUpperCase()
            : "";
        if (preferred && departments.includes(preferred)) {
            departmentSelect.value = preferred;
        }
    }
    if (!departments.length && emptyNote) {
        emptyNote.hidden = false;
        emptyNote.textContent = "No departments found for the selected campus.";
    }
}

function buildVpaaOverallSasrPayload(modal) {
    const campusSlug = String((modal.querySelector("#vpaaOverallSasrCampusSelect") || {}).value || "all").trim() || "all";
    const semesterId = String((modal.querySelector("#vpaaOverallSasrSemesterSelect") || {}).value || "").trim();
    const loadType = normalizeVpaaReportLoadType((modal.querySelector("#vpaaOverallSasrLoadTypeSelect") || {}).value);
    const scopeType = String((modal.querySelector("#vpaaOverallSasrScopeTypeSelect") || {}).value || "department").trim();
    const departmentCode = String((modal.querySelector("#vpaaOverallSasrDepartmentSelect") || {}).value || "").trim();
    const programId = String((modal.querySelector("#vpaaOverallSasrProgramSelect") || {}).value || "").trim();

    if (!semesterId) {
        alert("Select a semester first.");
        return null;
    }
    if (scopeType === "program" && !programId) {
        alert("Select a program first.");
        return null;
    }
    if (scopeType !== "program" && !departmentCode) {
        alert("Select a department first.");
        return null;
    }

    return {
        campus_slug: campusSlug,
        semester_id: semesterId,
        load_type: loadType,
        scope_type: scopeType === "program" ? "program" : "department",
        department_code: departmentCode,
        program_id: programId
    };
}

async function downloadVpaaOverallSasrReport(payload) {
    let response;
    try {
        response = await fetch("../api/generate_overall_sasr.php", {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify(payload)
        });
    } catch (_error) {
        alert("Unable to connect to the Overall SASR generator.");
        return false;
    }

    if (!response.ok) {
        let errorMessage = "Failed to generate Overall SASR Excel file.";
        try {
            const data = await response.json();
            if (data && data.error) errorMessage = String(data.error);
        } catch (_error) {
            // Ignore non-JSON error bodies.
        }
        alert(errorMessage);
        return false;
    }

    const excelBlob = await response.blob();
    const fileName = parseVpaaFileNameFromDisposition(response.headers.get("Content-Disposition")) || "overall_sasr.xlsx";
    const blobUrl = URL.createObjectURL(excelBlob);
    const anchor = document.createElement("a");
    anchor.href = blobUrl;
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    window.setTimeout(function () { URL.revokeObjectURL(blobUrl); }, 30000);
    return true;
}

function openVpaaOverallSasrModal() {
    const modal = ensureVpaaOverallSasrModal();
    const semesterOptions = getVpaaReportSemesterChoices();
    if (!semesterOptions.length) {
        alert("No semester is available for Overall SASR generation.");
        return;
    }

    populateVpaaOverallSasrBaseOptions(modal);
    refreshVpaaOverallSasrScopeOptions(modal);
    modal.style.display = "flex";
}

function ensureVpaaReportTypeModal() {
    let modal = document.getElementById("vpaaReportTypeModal");
    if (modal) return modal;

    modal = document.createElement("div");
    modal.id = "vpaaReportTypeModal";
    modal.className = "modal hr-report-type-modal vpaa-report-type-modal";
    modal.innerHTML = `
        <div class="modal-content hr-report-type-modal-content" role="dialog" aria-modal="true" aria-label="Select faculty file">
            <div class="modal-header">
                <h2 id="vpaaReportTypeTitle">Select File Type</h2>
                <button type="button" class="modal-close" id="vpaaReportTypeCloseBtn" aria-label="Close file selector">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            <div class="hr-report-type-body">
                <p class="hr-report-modal-note" id="vpaaReportTypeNote">Choose which faculty file to open.</p>
                <div class="hr-report-type-grid">
                    <button type="button" class="hr-report-type-card" data-report-type="ifer">
                        <i class="fas fa-file-word"></i>
                        <strong>IFER</strong>
                        <span>Generate the Individual Faculty Evaluation Report for a selected semester.</span>
                    </button>
                    <button type="button" class="hr-report-type-card" data-report-type="sasr">
                        <i class="fas fa-file-excel"></i>
                        <strong>SASR</strong>
                        <span>Generate the SET and SEF rating summary as an Excel file.</span>
                    </button>
                    <button type="button" class="hr-report-type-card" data-report-type="acknowledgement">
                        <i class="fas fa-file-pdf"></i>
                        <strong>Acknowledgement</strong>
                        <span>Open the stored Faculty Evaluation and Development Acknowledgement PDF.</span>
                    </button>
                </div>
            </div>
            <div class="modal-actions hr-report-modal-actions">
                <button type="button" class="btn-cancel" id="vpaaReportTypeCancelBtn">Cancel</button>
            </div>
        </div>
    `;
    document.body.appendChild(modal);

    const close = function () {
        modal.style.display = "none";
        modal._professorId = "";
    };

    modal.addEventListener("click", function (event) {
        if (event.target === modal) close();
    });

    const closeBtn = document.getElementById("vpaaReportTypeCloseBtn");
    const cancelBtn = document.getElementById("vpaaReportTypeCancelBtn");
    if (closeBtn) closeBtn.addEventListener("click", close);
    if (cancelBtn) cancelBtn.addEventListener("click", close);

    modal.querySelectorAll("[data-report-type]").forEach(function (button) {
        button.addEventListener("click", function () {
            const reportType = String(button.getAttribute("data-report-type") || "").trim();
            const professorId = String(modal._professorId || "").trim();
            close();

            if (!professorId || !reportType) {
                alert("Unable to open the selected file option.");
                return;
            }

            if (reportType === "ifer") {
                openVpaaProfessorIferFlow(professorId);
                return;
            }
            if (reportType === "sasr") {
                openVpaaProfessorSasrFlow(professorId);
                return;
            }
            if (reportType === "acknowledgement") {
                openVpaaProfessorAcknowledgementFlow(professorId);
            }
        });
    });

    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && modal.style.display === "flex") {
            close();
        }
    });

    return modal;
}

function openVpaaProfessorFileOptions(professorId) {
    const professor = getVpaaProfessorById(professorId, currentVpaaAnalyticsSemester || currentSemesterLabel);
    if (!professor) {
        alert("Professor not found. Please try again.");
        return;
    }

    const baseProfessorId = normalizeVpaaProfessorUserId(professor.userId || professor.id);
    const modal = ensureVpaaReportTypeModal();
    const title = document.getElementById("vpaaReportTypeTitle");
    const note = document.getElementById("vpaaReportTypeNote");
    const acknowledgementButton = modal.querySelector('[data-report-type="acknowledgement"]');
    const acknowledgementAvailable = buildVpaaAcknowledgementSemesterChoices(professor).length > 0;

    if (title) {
        title.textContent = `Faculty Files for ${String(professor.name || "Professor")}`;
    }
    if (note) {
        note.textContent = acknowledgementAvailable
            ? "Choose which faculty file to open."
            : "Choose which faculty file to open. Stored acknowledgement PDF is currently unavailable.";
    }
    if (acknowledgementButton) {
        acknowledgementButton.classList.toggle("is-unavailable", !acknowledgementAvailable);
    }

    modal._professorId = baseProfessorId;
    modal.style.display = "flex";
}

function closeVpaaPdfPreviewModal() {
    const modal = document.getElementById("vpaaPdfPreviewModal");
    const frame = document.getElementById("vpaaPdfPreviewFrame");
    if (frame) frame.src = "about:blank";
    if (modal) modal.classList.remove("active");

    if (openVpaaPdfBlobPreview._blobUrl) {
        URL.revokeObjectURL(openVpaaPdfBlobPreview._blobUrl);
        openVpaaPdfBlobPreview._blobUrl = "";
    }
    openVpaaPdfBlobPreview._filename = "";
}

function ensureVpaaPdfPreviewModal() {
    let modal = document.getElementById("vpaaPdfPreviewModal");
    if (modal) return modal;

    modal = document.createElement("div");
    modal.id = "vpaaPdfPreviewModal";
    modal.className = "pdf-preview-modal";
    modal.innerHTML = `
        <div class="pdf-preview-dialog" role="dialog" aria-modal="true" aria-label="PDF preview">
            <div class="pdf-preview-toolbar">
                <div>
                    <h3 id="vpaaPdfPreviewTitle">PDF Preview</h3>
                    <div class="pdf-preview-filename" id="vpaaPdfPreviewFilename">report.pdf</div>
                </div>
                <div class="pdf-preview-actions">
                    <button type="button" class="btn-submit pdf-preview-download-btn" id="vpaaPdfPreviewDownloadBtn">Download</button>
                    <button type="button" class="btn-cancel pdf-preview-close-btn" id="vpaaPdfPreviewCloseBtn">Close</button>
                </div>
            </div>
            <iframe id="vpaaPdfPreviewFrame" class="pdf-preview-frame" title="PDF Preview"></iframe>
        </div>
    `;
    document.body.appendChild(modal);

    const closeBtn = document.getElementById("vpaaPdfPreviewCloseBtn");
    const downloadBtn = document.getElementById("vpaaPdfPreviewDownloadBtn");
    if (closeBtn) {
        closeBtn.addEventListener("click", closeVpaaPdfPreviewModal);
    }
    if (downloadBtn) {
        downloadBtn.addEventListener("click", function () {
            if (!openVpaaPdfBlobPreview._blobUrl) return;
            const anchor = document.createElement("a");
            anchor.href = openVpaaPdfBlobPreview._blobUrl;
            anchor.download = openVpaaPdfBlobPreview._filename || "report.pdf";
            document.body.appendChild(anchor);
            anchor.click();
            document.body.removeChild(anchor);
        });
    }
    modal.addEventListener("click", function (event) {
        if (event.target === modal) {
            closeVpaaPdfPreviewModal();
        }
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && modal.classList.contains("active")) {
            closeVpaaPdfPreviewModal();
        }
    });

    return modal;
}

function openVpaaPdfBlobPreview(config) {
    const blob = config && config.blob;
    if (!(blob instanceof Blob)) {
        alert("Unable to preview the requested PDF file.");
        return;
    }

    const modal = ensureVpaaPdfPreviewModal();
    const frame = document.getElementById("vpaaPdfPreviewFrame");
    const title = document.getElementById("vpaaPdfPreviewTitle");
    const filename = document.getElementById("vpaaPdfPreviewFilename");
    const fileName = String(config && config.fileName || "report.pdf").trim() || "report.pdf";
    const dialogTitle = String(config && config.title || "PDF Preview").trim() || "PDF Preview";
    const blobUrl = URL.createObjectURL(blob);

    if (openVpaaPdfBlobPreview._blobUrl) {
        URL.revokeObjectURL(openVpaaPdfBlobPreview._blobUrl);
    }
    openVpaaPdfBlobPreview._blobUrl = blobUrl;
    openVpaaPdfBlobPreview._filename = fileName;

    if (title) title.textContent = dialogTitle;
    if (filename) filename.textContent = fileName;
    if (frame) frame.src = `${blobUrl}#toolbar=1&navpanes=0&scrollbar=1`;
    if (modal) modal.classList.add("active");
}

async function openVpaaIferTemplateDownload(professor, semesterId, loadType) {
    const professorUserId = normalizeVpaaProfessorUserId(professor && (professor.userId || professor.id));
    const selectedLoadType = normalizeVpaaReportLoadType(loadType);
    if (!professorUserId) {
        alert("Unable to resolve professor account for IFER download.");
        return;
    }
    if (!semesterId) {
        alert("Select a semester first.");
        return;
    }

    let response;
    try {
        response = await fetch("../api/generate_ifer.php", {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                professor_user_id: professorUserId,
                semester_id: semesterId,
                load_type: selectedLoadType
            })
        });
    } catch (_error) {
        alert("Unable to connect to the IFER generator.");
        return;
    }

    if (!response.ok) {
        let errorMessage = "Failed to generate IFER Word file.";
        try {
            const data = await response.json();
            if (data && data.error) errorMessage = String(data.error);
        } catch (_error) {
            // Ignore non-JSON error bodies.
        }
        alert(errorMessage);
        return;
    }

    const wordBlob = await response.blob();
    const fileName = parseVpaaFileNameFromDisposition(response.headers.get("Content-Disposition"))
        || `${String(professor && professor.name || "Professor").trim() || "Professor"} IFER.docx`;
    const blobUrl = URL.createObjectURL(wordBlob);
    const anchor = document.createElement("a");
    anchor.href = blobUrl;
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    window.setTimeout(function () {
        URL.revokeObjectURL(blobUrl);
    }, 30000);
}

async function openVpaaSasrTemplateDownload(professor, semesterId, loadType) {
    const professorUserId = normalizeVpaaProfessorUserId(professor && (professor.userId || professor.id));
    const selectedLoadType = normalizeVpaaReportLoadType(loadType);
    if (!professorUserId) {
        alert("Unable to resolve professor account for SASR download.");
        return;
    }
    if (!semesterId) {
        alert("Select a semester first.");
        return;
    }

    let response;
    try {
        response = await fetch("../api/generate_sasr.php", {
            method: "POST",
            credentials: "same-origin",
            headers: {
                "Content-Type": "application/json"
            },
            body: JSON.stringify({
                professor_user_id: professorUserId,
                semester_id: semesterId,
                load_type: selectedLoadType
            })
        });
    } catch (_error) {
        alert("Unable to connect to the SASR generator.");
        return;
    }

    if (!response.ok) {
        let errorMessage = "Failed to generate SASR Excel file.";
        try {
            const data = await response.json();
            if (data && data.error) errorMessage = String(data.error);
        } catch (_error) {
            // Ignore non-JSON error bodies.
        }
        alert(errorMessage);
        return;
    }

    const excelBlob = await response.blob();
    const fileName = parseVpaaFileNameFromDisposition(response.headers.get("Content-Disposition"))
        || `${String(professor && professor.name || "Professor").trim() || "Professor"} SASR.xlsx`;
    const blobUrl = URL.createObjectURL(excelBlob);
    const anchor = document.createElement("a");
    anchor.href = blobUrl;
    anchor.download = fileName;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    window.setTimeout(function () {
        URL.revokeObjectURL(blobUrl);
    }, 30000);
}

async function openVpaaStoredFacultyPaperPreview(professor, paper) {
    if (!paper || !paper.id) {
        alert("Stored acknowledgement PDF is unavailable for this semester.");
        return;
    }

    const params = new URLSearchParams();
    params.set("paper_id", String(paper.id));
    const versionNo = getVpaaStoredFacultyPaperVersionNo(paper);
    if (versionNo) {
        params.set("version_no", String(versionNo));
    }

    const requestUrl = `../api/faculty_paper_file.php?${params.toString()}`;
    let response;
    try {
        response = await fetch(requestUrl, {
            method: "GET",
            credentials: "same-origin"
        });
    } catch (_error) {
        alert("Unable to open the stored acknowledgement PDF.");
        return;
    }

    if (!response.ok) {
        let errorMessage = "Stored acknowledgement PDF is unavailable.";
        try {
            const data = await response.json();
            if (data && data.error) errorMessage = String(data.error);
        } catch (_error) {
            // Ignore non-JSON error bodies.
        }
        alert(errorMessage);
        return;
    }

    const pdfBlob = await response.blob();
    const fileName = parseVpaaFileNameFromDisposition(response.headers.get("Content-Disposition"))
        || String(paper.latest_file_name || paper.latestFileName || "faculty_acknowledgement.pdf").trim()
        || "faculty_acknowledgement.pdf";
    openVpaaPdfBlobPreview({
        blob: pdfBlob,
        fileName: fileName,
        title: `${String(professor && professor.name || "Professor")} Acknowledgement`
    });
}

function openVpaaProfessorIferFlow(professorId) {
    const professor = getVpaaProfessorById(professorId, currentVpaaAnalyticsSemester || currentSemesterLabel);
    if (!professor) {
        alert("Professor not found. Please try again.");
        return;
    }

    const semesterOptions = getVpaaReportSemesterChoices();
    if (!semesterOptions.length) {
        alert("No semester is available for IFER generation.");
        return;
    }

    openVpaaReportSemesterPicker({
        title: `Select IFER Semester for ${String(professor.name || "Professor")}`,
        note: "Choose the load type and semester to generate the Individual Faculty Evaluation Report.",
        options: semesterOptions,
        selectedSemesterId: String(SharedData.getCurrentSemester ? SharedData.getCurrentSemester() : "").trim(),
        selectedLoadType: "main",
        showLoadType: true,
        confirmLabel: "Download",
        onConfirm: function (semesterId, loadType) {
            openVpaaIferTemplateDownload(professor, semesterId, loadType);
        }
    });
}

function openVpaaProfessorSasrFlow(professorId) {
    const professor = getVpaaProfessorById(professorId, currentVpaaAnalyticsSemester || currentSemesterLabel);
    if (!professor) {
        alert("Professor not found. Please try again.");
        return;
    }

    const semesterOptions = getVpaaReportSemesterChoices();
    if (!semesterOptions.length) {
        alert("No semester is available for SASR generation.");
        return;
    }

    openVpaaReportSemesterPicker({
        title: `Select SASR Semester for ${String(professor.name || "Professor")}`,
        note: "Choose the load type and semester to generate the SET and SEF rating summary Excel file.",
        options: semesterOptions,
        selectedSemesterId: String(SharedData.getCurrentSemester ? SharedData.getCurrentSemester() : "").trim(),
        selectedLoadType: "main",
        showLoadType: true,
        confirmLabel: "Download",
        onConfirm: function (semesterId, loadType) {
            openVpaaSasrTemplateDownload(professor, semesterId, loadType);
        }
    });
}

function openVpaaProfessorAcknowledgementFlow(professorId) {
    const professor = getVpaaProfessorById(professorId, currentVpaaAnalyticsSemester || currentSemesterLabel);
    if (!professor) {
        alert("Professor not found. Please try again.");
        return;
    }

    const mainSemesterOptions = buildVpaaAcknowledgementSemesterChoices(professor, "main");
    const excessSemesterOptions = buildVpaaAcknowledgementSemesterChoices(professor, "excess");
    const defaultLoadType = mainSemesterOptions.length ? "main" : "excess";
    const semesterOptions = defaultLoadType === "main" ? mainSemesterOptions : excessSemesterOptions;
    if (!semesterOptions.length) {
        alert("No stored acknowledgement PDF is available for this professor.");
        return;
    }

    openVpaaReportSemesterPicker({
        title: `Select Acknowledgement Semester for ${String(professor.name || "Professor")}`,
        note: "Choose the load type and semester. Only matching stored acknowledgement PDFs are available.",
        options: semesterOptions,
        selectedSemesterId: semesterOptions[semesterOptions.length - 1].id,
        selectedLoadType: defaultLoadType,
        showLoadType: true,
        loadScopedOptionsProvider: function (loadType) {
            return buildVpaaAcknowledgementSemesterChoices(professor, loadType);
        },
        confirmLabel: "Preview PDF",
        onConfirm: function (semesterId, loadType) {
            const selectedLoadType = normalizeVpaaReportLoadType(loadType);
            const selectedOptions = buildVpaaAcknowledgementSemesterChoices(professor, selectedLoadType);
            const selected = selectedOptions.find(function (option) {
                return option.id === semesterId;
            });
            if (!selected || !selected.paper) {
                alert(`Stored ${getVpaaReportLoadTypeLabel(selectedLoadType).toLowerCase()} acknowledgement PDF is unavailable for this semester.`);
                return;
            }
            openVpaaStoredFacultyPaperPreview(professor, selected.paper);
        }
    });
}

function buildCombinedCommentEntries(prof) {
    const rows = [];
    const pushRows = (source, comments) => {
        (Array.isArray(comments) ? comments : []).forEach((comment) => {
            const text = String(comment || "").trim();
            if (!text) return;
            rows.push({ source, text });
        });
    };

    pushRows("Student to Professor", prof.studentComments);
    pushRows("Professor to Professor", prof.peerComments);
    pushRows("Supervisor to Professor", prof.supervisorComments || []);

    return rows;
}

function renderCombinedCommentsHtml(entries) {
    const rows = Array.isArray(entries) ? entries : [];
    if (!rows.length) {
        return '<li class="empty">No comments submitted.</li>';
    }

    return rows.map((item) => `
        <li class="vpaa-comment-item">
            <span class="vpaa-comment-source">${escapeHtml(item.source)}</span>
            <span class="vpaa-comment-text">${escapeHtml(item.text)}</span>
        </li>
    `).join("");
}

function renderComments(list) {
    if (!list.length) {
        return '<li class="empty">No comments submitted.</li>';
    }
    return list.map((comment) => `<li>${escapeHtml(comment)}</li>`).join("");
}

function sanitizeAiAnalyticsText(value, maxLength = 260) {
    const text = String(value || "").replace(/\s+/g, " ").trim();
    if (!text) return "";
    const safeLimit = Number(maxLength) > 0 ? Number(maxLength) : 260;
    return text.length > safeLimit ? text.slice(0, safeLimit) : text;
}

function normalizeAiAnalyticsTone(value) {
    const token = String(value || "").trim().toLowerCase();
    if (token === "positive") return "positive";
    if (token === "negative") return "negative";
    return "neutral";
}

function normalizeAiAnalyticsJudgmentLabel(value) {
    const token = String(value || "").trim().toLowerCase();
    if (token === "excellent") return "Excellent";
    if (token === "good") return "Good";
    if (token === "critical concern" || token === "critical" || token === "critical_concern") return "Critical Concern";
    return "Needs Improvement";
}

function normalizeAiAnalyticsSourceLabel(value) {
    const token = String(value || "").trim().toLowerCase();
    if (!token) return "General";
    if (token.includes("student")) return "Student to Professor";
    if (token.includes("peer") || token.includes("professor")) return "Professor to Professor";
    if (token.includes("supervisor") || token.includes("dean") || token.includes("procoor") || token.includes("vpaa") || token.includes("hr")) return "Supervisor to Professor";
    return "General";
}

function getAiAnalyticsActorIdentity() {
    const session = SharedData.getSession ? SharedData.getSession() : null;
    return {
        userId: session && session.userId ? session.userId : "",
        email: session && session.email ? session.email : "",
        username: session && session.username ? session.username : "",
        employeeId: session && session.employeeId ? session.employeeId : "",
        role: session && session.role ? session.role : "",
        fullName: session && session.fullName ? session.fullName : (session && session.username ? session.username : ""),
    };
}

function computeAiAverageFromDistribution(distribution) {
    const dist = distribution && typeof distribution === "object" ? distribution : {};
    let weighted = 0;
    let total = 0;
    [1, 2, 3, 4, 5].forEach((score) => {
        const count = Number(dist[score] || 0);
        if (!Number.isFinite(count) || count <= 0) return;
        weighted += score * count;
        total += count;
    });
    if (total <= 0) return null;
    return Number((weighted / total).toFixed(2));
}

function buildProfessorAiAnalyticsMetrics(prof) {
    const byType = prof && typeof prof.analyticsByType === "object" ? prof.analyticsByType : {};
    const averagesBySource = {
        student: computeAiAverageFromDistribution(byType.student && byType.student.ratingDistribution),
        professor: computeAiAverageFromDistribution(byType.professor && byType.professor.ratingDistribution),
        supervisor: computeAiAverageFromDistribution(byType.supervisor && byType.supervisor.ratingDistribution),
    };

    const available = Object.values(averagesBySource).filter((value) => Number.isFinite(Number(value))).map(Number);
    const combinedAverage = available.length
        ? Number((available.reduce((sum, value) => sum + value, 0) / available.length).toFixed(2))
        : (Number.isFinite(Number(prof && prof.overall)) ? Number(prof.overall) : null);

    return {
        overallRating: Number.isFinite(Number(prof && prof.overall)) ? Number(prof.overall) : null,
        combinedAverage: Number.isFinite(Number(combinedAverage)) ? Number(combinedAverage) : null,
        responseRate: Number.isFinite(Number(prof && prof.responseRate)) ? Number(prof.responseRate) : null,
        totalEvaluations: Number.isFinite(Number(prof && prof.evaluations)) ? Number(prof.evaluations) : 0,
        averagesBySource: averagesBySource,
        countsBySource: {
            student: Array.isArray(prof && prof.studentComments) ? prof.studentComments.length : 0,
            professor: Array.isArray(prof && prof.peerComments) ? prof.peerComments.length : 0,
            supervisor: Array.isArray(prof && prof.supervisorComments) ? prof.supervisorComments.length : 0,
        },
    };
}

function buildProfessorAiAnalyticsPayload(prof) {
    const combined = buildCombinedCommentEntries(prof)
        .map((item, index) => ({
            id: `${String(prof && prof.id || "prof")}_${index + 1}`,
            source: normalizeAiAnalyticsSourceLabel(item && item.source),
            text: sanitizeAiAnalyticsText(item && item.text, 700),
        }))
        .filter((item) => item.text)
        .slice(0, 240);

    return {
        professor: {
            id: sanitizeAiAnalyticsText(prof && prof.id, 80),
            name: sanitizeAiAnalyticsText(prof && prof.name, 160),
            semester: sanitizeAiAnalyticsText(prof && prof.semester, 120),
        },
        comments: combined,
        metrics: buildProfessorAiAnalyticsMetrics(prof),
    };
}

function buildLocalAiKeywordRows(comments) {
    const texts = (Array.isArray(comments) ? comments : []).map((item) => String(item && item.text || "").trim()).filter(Boolean);
    const base = computeTopWordFrequency(texts, 12);
    const positive = new Set(["excellent", "great", "good", "clear", "helpful", "organized", "engaging", "respectful", "supportive", "effective", "fair"]);
    const negative = new Set(["hate", "terror", "worst", "bad", "poor", "unclear", "confusing", "boring", "late", "rude", "unfair", "strict", "difficult", "awful"]);
    return base.map((item) => {
        const term = sanitizeAiAnalyticsText(item && item.label, 40).toLowerCase();
        let tone = "neutral";
        if (positive.has(term)) tone = "positive";
        if (negative.has(term)) tone = "negative";
        return {
            term: sanitizeAiAnalyticsText(item && item.label, 40),
            count: Math.max(1, Number(item && item.count || 1)),
            tone: tone,
        };
    });
}

function buildLocalAiClusters(comments) {
    const rows = Array.isArray(comments) ? comments : [];
    const themes = {
        "Teaching Clarity": ["explain", "explains", "clear", "clarity", "understand", "confusing", "discussion", "lecture"],
        "Engagement & Delivery": ["engaging", "interactive", "boring", "enthusiasm", "pace", "energy", "participation"],
        "Assessment & Fairness": ["exam", "quiz", "grade", "grading", "fair", "rubric", "assignment", "assessment"],
        "Professionalism & Conduct": ["respectful", "rude", "late", "punctual", "attitude", "professional", "behavior", "approachable"],
        "Learning Support": ["examples", "consultation", "feedback", "materials", "resources", "guidance", "support", "helpful"],
    };

    const buckets = {};
    rows.forEach((row) => {
        const text = String(row && row.text || "").toLowerCase();
        const source = normalizeAiAnalyticsSourceLabel(row && row.source);
        let bestTheme = "General Feedback";
        let bestHits = 0;

        Object.keys(themes).forEach((theme) => {
            const hits = themes[theme].reduce((sum, keyword) => {
                return sum + (text.includes(keyword) ? 1 : 0);
            }, 0);
            if (hits > bestHits) {
                bestHits = hits;
                bestTheme = theme;
            }
        });

        if (!buckets[bestTheme]) {
            buckets[bestTheme] = {
                theme: bestTheme,
                count: 0,
                sources: new Set(),
                sampleComments: [],
            };
        }
        buckets[bestTheme].count += 1;
        buckets[bestTheme].sources.add(source);
        if (buckets[bestTheme].sampleComments.length < 2) {
            buckets[bestTheme].sampleComments.push(sanitizeAiAnalyticsText(row && row.text, 220));
        }
    });

    return Object.values(buckets)
        .sort((a, b) => b.count - a.count || String(a.theme).localeCompare(String(b.theme)))
        .slice(0, 5)
        .map((item) => ({
            theme: sanitizeAiAnalyticsText(item.theme, 90),
            count: Math.max(1, Number(item.count || 1)),
            sources: Array.from(item.sources),
            sampleComments: item.sampleComments.filter(Boolean),
        }));
}

function buildLocalAiJudgment(payload, keywords) {
    const metrics = payload && payload.metrics ? payload.metrics : {};
    const comments = Array.isArray(payload && payload.comments) ? payload.comments : [];
    const combinedAverage = Number.isFinite(Number(metrics.combinedAverage)) ? Number(metrics.combinedAverage) : null;
    const responseRate = Number.isFinite(Number(metrics.responseRate)) ? Number(metrics.responseRate) : null;
    const totalComments = comments.length;

    let positiveWeight = 0;
    let negativeWeight = 0;
    let neutralWeight = 0;
    (Array.isArray(keywords) ? keywords : []).forEach((row) => {
        const count = Math.max(1, Number(row && row.count || 1));
        const tone = normalizeAiAnalyticsTone(row && row.tone);
        if (tone === "positive") positiveWeight += count;
        else if (tone === "negative") negativeWeight += count;
        else neutralWeight += count;
    });

    const toneTotal = Math.max(1, positiveWeight + negativeWeight + neutralWeight);
    const toneBalance = ((positiveWeight * 1.0) - (negativeWeight * 1.2)) / toneTotal;

    let score = 50;
    if (Number.isFinite(combinedAverage)) {
        score += (combinedAverage - 3) * 18;
    }
    score += Math.max(-20, Math.min(20, toneBalance * 24));
    if (Number.isFinite(responseRate)) {
        score += ((responseRate - 50) / 50) * 10;
    }
    if (totalComments <= 3) score -= 8;
    else if (totalComments >= 20) score += 4;
    score = Math.round(Math.max(0, Math.min(100, score)));

    let label = "Needs Improvement";
    if (score >= 85) label = "Excellent";
    else if (score >= 70) label = "Good";
    else if (score < 50) label = "Critical Concern";

    let confidence = 45 + Math.min(35, totalComments * 2);
    if (Number.isFinite(responseRate)) confidence += Math.min(10, responseRate / 10);
    if (Number.isFinite(combinedAverage)) confidence += 10;
    if (totalComments < 3) confidence -= 10;
    confidence = Math.round(Math.max(25, Math.min(98, confidence)));

    let rationale = "Mixed sentiment and performance indicators suggest improvements are needed.";
    if (label === "Excellent") rationale = "Consistent positive feedback and strong rating indicators across available sources.";
    if (label === "Good") rationale = "Feedback is generally positive with limited critical concerns.";
    if (label === "Critical Concern") rationale = "Negative patterns and lower performance indicators suggest urgent review.";
    if (!Number.isFinite(combinedAverage)) rationale += " Overall rating context is limited.";

    return {
        label,
        rationale,
        confidence,
        score,
    };
}

function buildLocalAiReasoning(payload, keywords, clusters, judgment) {
    const sourceCounts = payload && payload.metrics && payload.metrics.countsBySource ? payload.metrics.countsBySource : {};
    const lines = [];
    lines.push(
        `Analyzed ${Array.isArray(payload && payload.comments) ? payload.comments.length : 0} comments from Student (${Number(sourceCounts.student || 0)}), Professor (${Number(sourceCounts.professor || 0)}), and Supervisor (${Number(sourceCounts.supervisor || 0)}) sources.`
    );

    if (payload && payload.metrics && Number.isFinite(Number(payload.metrics.combinedAverage))) {
        lines.push(`Combined rating context is ${Number(payload.metrics.combinedAverage).toFixed(2)} / 5.00 based on available evaluation data.`);
    } else {
        lines.push("Combined rating context is limited, so conclusions rely more on textual feedback patterns.");
    }

    const positiveTerms = (Array.isArray(keywords) ? keywords : []).filter((row) => normalizeAiAnalyticsTone(row && row.tone) === "positive").slice(0, 2).map((row) => row.term);
    const negativeTerms = (Array.isArray(keywords) ? keywords : []).filter((row) => normalizeAiAnalyticsTone(row && row.tone) === "negative").slice(0, 2).map((row) => row.term);
    if (positiveTerms.length || negativeTerms.length) {
        lines.push(`Detected positive markers (${positiveTerms.length ? positiveTerms.join(", ") : "none"}) and negative markers (${negativeTerms.length ? negativeTerms.join(", ") : "none"}).`);
    }

    if (Array.isArray(clusters) && clusters.length) {
        const dominant = clusters[0];
        lines.push(`Most comments cluster around "${sanitizeAiAnalyticsText(dominant && dominant.theme, 90)}" (${Number(dominant && dominant.count || 0)} comments).`);
    }

    lines.push(`Final judgment: ${normalizeAiAnalyticsJudgmentLabel(judgment && judgment.label)} (confidence ${Math.round(Number(judgment && judgment.confidence || 0))}%).`);
    return lines.slice(0, 5);
}

function buildLocalAiExplainabilityInsight(payload) {
    const comments = Array.isArray(payload && payload.comments) ? payload.comments : [];
    const keywords = buildLocalAiKeywordRows(comments);
    const clusters = buildLocalAiClusters(comments);
    const judgment = buildLocalAiJudgment(payload, keywords);
    const reasoning = buildLocalAiReasoning(payload, keywords, clusters, judgment);
    return {
        keywords,
        clusters,
        reasoning,
        judgment: {
            label: normalizeAiAnalyticsJudgmentLabel(judgment.label),
            rationale: sanitizeAiAnalyticsText(judgment.rationale, 320),
            confidence: Math.max(0, Math.min(100, Number(judgment.confidence || 0))),
        },
        stats: {
            totalComments: comments.length,
            sourceCounts: payload && payload.metrics && payload.metrics.countsBySource ? payload.metrics.countsBySource : { student: 0, professor: 0, supervisor: 0 },
            combinedAverage: payload && payload.metrics ? payload.metrics.combinedAverage : null,
            responseRate: payload && payload.metrics ? payload.metrics.responseRate : null,
            totalEvaluations: payload && payload.metrics ? payload.metrics.totalEvaluations : 0,
        },
    };
}

function normalizeAiInsightData(rawInsight, fallbackInsight) {
    const fallback = fallbackInsight && typeof fallbackInsight === "object" ? fallbackInsight : buildLocalAiExplainabilityInsight({ comments: [], metrics: {} });
    const insight = rawInsight && typeof rawInsight === "object" ? rawInsight : {};

    const keywords = Array.isArray(insight.keywords) && insight.keywords.length
        ? insight.keywords.map((row) => ({
            term: sanitizeAiAnalyticsText(row && row.term, 40),
            count: Math.max(1, Number(row && row.count || 1)),
            tone: normalizeAiAnalyticsTone(row && row.tone),
        })).filter((row) => row.term)
        : fallback.keywords;

    const clusters = Array.isArray(insight.clusters) && insight.clusters.length
        ? insight.clusters.map((row) => ({
            theme: sanitizeAiAnalyticsText(row && row.theme, 90) || "General Feedback",
            count: Math.max(1, Number(row && row.count || 1)),
            sources: Array.isArray(row && row.sources) ? row.sources.map((source) => normalizeAiAnalyticsSourceLabel(source)).slice(0, 4) : [],
            sampleComments: Array.isArray(row && row.sampleComments) ? row.sampleComments.map((item) => sanitizeAiAnalyticsText(item, 220)).filter(Boolean).slice(0, 2) : [],
        }))
        : fallback.clusters;

    const reasoning = Array.isArray(insight.reasoning) && insight.reasoning.length
        ? insight.reasoning.map((line) => sanitizeAiAnalyticsText(line, 260)).filter(Boolean).slice(0, 8)
        : fallback.reasoning;

    const rawJudgment = insight.judgment && typeof insight.judgment === "object" ? insight.judgment : {};
    const fallbackJudgment = fallback.judgment || {};
    const label = normalizeAiAnalyticsJudgmentLabel(rawJudgment.label || fallbackJudgment.label);
    const rationale = sanitizeAiAnalyticsText(rawJudgment.rationale, 320) || sanitizeAiAnalyticsText(fallbackJudgment.rationale, 320);
    let confidence = Number(rawJudgment.confidence);
    if (!Number.isFinite(confidence) || confidence <= 0) confidence = Number(fallbackJudgment.confidence || 0);
    if (confidence > 0 && confidence <= 1) confidence *= 100;
    confidence = Math.round(Math.max(0, Math.min(100, confidence)));

    const sourceCounts = insight.stats && insight.stats.sourceCounts && typeof insight.stats.sourceCounts === "object"
        ? insight.stats.sourceCounts
        : (fallback.stats && fallback.stats.sourceCounts ? fallback.stats.sourceCounts : { student: 0, professor: 0, supervisor: 0 });
    const stats = {
        totalComments: Number(insight.stats && insight.stats.totalComments),
        sourceCounts: {
            student: Number(sourceCounts.student || 0),
            professor: Number(sourceCounts.professor || 0),
            supervisor: Number(sourceCounts.supervisor || 0),
        },
        combinedAverage: Number.isFinite(Number(insight.stats && insight.stats.combinedAverage))
            ? Number(insight.stats.combinedAverage)
            : (fallback.stats ? fallback.stats.combinedAverage : null),
        responseRate: Number.isFinite(Number(insight.stats && insight.stats.responseRate))
            ? Number(insight.stats.responseRate)
            : (fallback.stats ? fallback.stats.responseRate : null),
        totalEvaluations: Number.isFinite(Number(insight.stats && insight.stats.totalEvaluations))
            ? Number(insight.stats.totalEvaluations)
            : (fallback.stats ? fallback.stats.totalEvaluations : 0),
    };
    if (!Number.isFinite(stats.totalComments)) {
        stats.totalComments = fallback.stats && Number.isFinite(Number(fallback.stats.totalComments))
            ? Number(fallback.stats.totalComments)
            : 0;
    }

    return {
        keywords,
        clusters,
        reasoning,
        judgment: {
            label,
            rationale: rationale || "No detailed rationale available.",
            confidence,
        },
        stats,
    };
}

function formatAiInsightSource(source) {
    const token = String(source || "rule").trim().toLowerCase();
    if (token === "openai" || token === "gemini") return "OpenAI";
    if (token === "openai+rule" || token === "gemini+rule") return "OpenAI + Rule fallback";
    return "Rule fallback";
}

function getAiJudgmentClass(label) {
    const normalized = normalizeAiAnalyticsJudgmentLabel(label);
    if (normalized === "Excellent") return "excellent";
    if (normalized === "Good") return "good";
    if (normalized === "Critical Concern") return "critical";
    return "needs-improvement";
}

function renderAiInsightState(outputEl, stateType, message) {
    if (!outputEl) return;
    const type = String(stateType || "info").toLowerCase();
    const safeMessage = escapeHtml(message || "No data available.");
    const stateContent = type === "loading"
        ? `${window.AppHourglassMarkup ? window.AppHourglassMarkup("small") : ""}<span>${safeMessage}</span>`
        : safeMessage;
    outputEl.classList.add("visible");
    outputEl.innerHTML = `
        <div class="vpaa-ai-note">AI Analytics uses all comment sources (student, peer, supervisor).</div>
        <div class="vpaa-ai-state ${escapeAttr(type)}">${stateContent}</div>
    `;
}

function renderAiInsightResult(outputEl, insightData, source, noticeText) {
    if (!outputEl) return;
    const insight = insightData && typeof insightData === "object" ? insightData : {};
    const keywords = Array.isArray(insight.keywords) ? insight.keywords : [];
    const clusters = Array.isArray(insight.clusters) ? insight.clusters : [];
    const reasoning = Array.isArray(insight.reasoning) ? insight.reasoning : [];
    const judgment = insight.judgment && typeof insight.judgment === "object" ? insight.judgment : {};
    const stats = insight.stats && typeof insight.stats === "object" ? insight.stats : {};
    const sourceLabel = formatAiInsightSource(source);
    const judgmentLabel = normalizeAiAnalyticsJudgmentLabel(judgment.label);
    const judgmentClass = getAiJudgmentClass(judgmentLabel);
    const confidence = Math.round(Math.max(0, Math.min(100, Number(judgment.confidence || 0))));

    const keywordHtml = keywords.length
        ? keywords.map((row) => `
            <span class="vpaa-ai-keyword-chip tone-${escapeAttr(normalizeAiAnalyticsTone(row.tone))}">
                <span class="vpaa-ai-keyword-term">${escapeHtml(row.term || "keyword")}</span>
                <span class="vpaa-ai-keyword-count">${Math.max(1, Number(row.count || 1))}x</span>
            </span>
        `).join("")
        : '<div class="vpaa-ai-empty">No keywords detected.</div>';

    const clusterHtml = clusters.length
        ? clusters.map((cluster) => {
            const sources = Array.isArray(cluster.sources) && cluster.sources.length
                ? cluster.sources.map((sourceName) => `<span class="vpaa-ai-source-chip">${escapeHtml(normalizeAiAnalyticsSourceLabel(sourceName))}</span>`).join("")
                : '<span class="vpaa-ai-empty-inline">No source tags</span>';
            const samples = Array.isArray(cluster.sampleComments) && cluster.sampleComments.length
                ? `<ul class="vpaa-ai-sample-list">${cluster.sampleComments.map((sample) => `<li>${escapeHtml(sample)}</li>`).join("")}</ul>`
                : '<div class="vpaa-ai-empty-inline">No sample comments.</div>';
            return `
                <div class="vpaa-ai-cluster-card">
                    <div class="vpaa-ai-cluster-header">
                        <strong>${escapeHtml(cluster.theme || "General Feedback")}</strong>
                        <span>${Math.max(1, Number(cluster.count || 1))} comments</span>
                    </div>
                    <div class="vpaa-ai-cluster-sources">${sources}</div>
                    ${samples}
                </div>
            `;
        }).join("")
        : '<div class="vpaa-ai-empty">No comment clusters detected.</div>';

    const reasoningHtml = reasoning.length
        ? `<ul class="vpaa-ai-reasoning-list">${reasoning.map((line) => `<li>${escapeHtml(line)}</li>`).join("")}</ul>`
        : '<div class="vpaa-ai-empty">No reasoning details available.</div>';

    const noticeHtml = noticeText
        ? `<div class="vpaa-ai-alert">${escapeHtml(noticeText)}</div>`
        : "";

    outputEl.classList.add("visible");
    outputEl.innerHTML = `
        <div class="vpaa-ai-note">AI Analytics uses all comment sources (student, peer, supervisor).</div>
        ${noticeHtml}
        <div class="vpaa-ai-meta">
            <span class="vpaa-ai-meta-pill">Source: ${escapeHtml(sourceLabel)}</span>
            <span class="vpaa-ai-meta-pill">Comments analyzed: ${Math.max(0, Number(stats.totalComments || 0))}</span>
        </div>
        <div class="vpaa-ai-section">
            <div class="vpaa-ai-section-title">Detected Keywords</div>
            <div class="vpaa-ai-keywords">${keywordHtml}</div>
        </div>
        <div class="vpaa-ai-section">
            <div class="vpaa-ai-section-title">Comment Clusters</div>
            <div class="vpaa-ai-clusters">${clusterHtml}</div>
        </div>
        <div class="vpaa-ai-section">
            <div class="vpaa-ai-section-title">AI Reasoning</div>
            ${reasoningHtml}
        </div>
        <div class="vpaa-ai-judgment-card ${escapeAttr(judgmentClass)}">
            <div class="vpaa-ai-judgment-head">
                <span class="vpaa-ai-judgment-label">${escapeHtml(judgmentLabel)}</span>
                <span class="vpaa-ai-judgment-confidence">${confidence}% confidence</span>
            </div>
            <p class="vpaa-ai-judgment-rationale">${escapeHtml(judgment.rationale || "No rationale available.")}</p>
        </div>
    `;
}

function runAiAnalyticsForProfessor(profId, outputEl, btnEl) {
    const prof = allProfessorData.find((p) => String(p.id) === String(profId));
    if (!prof) {
        renderAiInsightState(outputEl, "error", "Unable to load professor data for AI analytics.");
        return;
    }

    const payload = buildProfessorAiAnalyticsPayload(prof);
    if (!Array.isArray(payload.comments) || payload.comments.length === 0) {
        renderAiInsightState(outputEl, "empty", "No comments available for AI analytics.");
        return;
    }

    const fallbackInsight = buildLocalAiExplainabilityInsight(payload);
    renderAiInsightState(outputEl, "loading", "Analyzing comments with AI...");

    const originalText = btnEl ? btnEl.textContent : "";
    if (btnEl) {
        btnEl.disabled = true;
        btnEl.textContent = "Analyzing...";
    }

    const executeAnalysis = () => {
        try {
            let response = null;
            if (typeof SharedData.analyzeEvaluationExplainability === "function") {
                response = SharedData.analyzeEvaluationExplainability(payload, getAiAnalyticsActorIdentity());
            } else {
                throw new Error("SharedData.analyzeEvaluationExplainability is unavailable.");
            }

            const insight = normalizeAiInsightData(response && response.insight, fallbackInsight);
            const source = response && response.source ? response.source : "rule";
            const notice = source === "openai" || source === "gemini"
                ? ""
                : "Showing rule-based analytics.";
            renderAiInsightResult(outputEl, insight, source, notice);
        } catch (error) {
            console.error("[VPAA] AI analytics failed, using local fallback.", error);
            renderAiInsightResult(
                outputEl,
                fallbackInsight,
                "rule",
                "Showing rule-based analytics."
            );
        } finally {
            if (btnEl) {
                btnEl.disabled = false;
                btnEl.textContent = originalText || "AI Analytics";
            }
        }
    };

    const loadingOverlay = window.AppLoadingOverlay;
    const canUseOverlay = loadingOverlay
        && typeof loadingOverlay.show === "function"
        && typeof loadingOverlay.hide === "function";

    if (!canUseOverlay) {
        executeAnalysis();
        return;
    }

    loadingOverlay.show("Analyzing comments with AI...");
    setTimeout(() => {
        try {
            executeAnalysis();
        } finally {
            loadingOverlay.hide();
        }
    }, 0);
}

function sanitizePhotoSource(value) {
    const photo = String(value || "").trim();
    if (!photo) return "";
    if (/^data:image\/[a-z0-9.+-]+;base64,[a-z0-9+/=\s]+$/i.test(photo)) {
        return photo;
    }
    if (/^https?:\/\//i.test(photo)) {
        return photo;
    }
    if (/^(\/|\.{1,2}\/|uploads\/)/i.test(photo)) {
        return photo;
    }
    return "";
}

function escapeHtml(value) {
    return String(value || "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function escapeAttr(value) {
    return escapeHtml(value).replace(/`/g, "&#96;");
}

function toDeptClass(department) {
    const cleaned = String(department || "General").replace(/[^a-z0-9]/gi, "");
    return `dept-${cleaned}`;
}

function getInitials(name) {
    const parts = String(name || "").split(" ").filter(Boolean);
    return parts.slice(0, 2).map((part) => part[0]).join("").toUpperCase();
}


/**
 * Check if user is authenticated and is a VPAA
 * @returns {boolean} - True if user is authenticated as VPAA
 */
function checkAuthentication() {
    const session = SharedData.requireSession('vpaa');
    if (!session) {
        return false;
    }

    try {
        return session.isAuthenticated === true && session.role === 'vpaa';
    } catch (e) {
        return false;
    }
}

init();
