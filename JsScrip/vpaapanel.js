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
    if (token === "supervisor" || token === "dean" || token === "hr" || token === "vpaa" || token === "admin" || token === "supervisor-to-professor" || token === "supervisor-professor") return "supervisor";
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
        return { category: category, score: Number(score.toFixed(1)) };
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

    const supervisorCount = context.users.filter(function (user) {
        if (normalizeVpaaToken(user && user.status || "active") === "inactive") return false;
        const role = normalizeVpaaToken(user && user.role);
        return role === "dean" || role === "hr" || role === "vpaa";
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

            const totalRequired = requiredStudentRaters + Math.max(activeProfessorCount - 1, 0) + supervisorCount;
            const totalReceived = allEvals.length;
            const responseRate = totalRequired > 0 ? Math.round((totalReceived / totalRequired) * 100) : 0;

            resultRows.push({
                id: (professor.id || ("prof-" + (index + 1))) + "|" + semesterLabel,
                employeeId: String(professor.employeeId || ("FAC-" + (10000 + index))).trim(),
                name: String(professor.name || ("Professor " + (index + 1))).trim(),
                campus: String(professor.campus || "").trim(),
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
    professorGrid: document.getElementById("professorGrid"),
    reportModal: document.getElementById("vpaaReportModal"),
    reportModalClose: document.getElementById("vpaaReportModalClose"),
    reportModalBody: document.getElementById("vpaaReportModalBody"),
    reportModalTitle: document.getElementById("vpaaReportModalTitle")
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
    populateDepartments();
    populateSemesters();
    populateCampuses();
    setupReportModalEvents();
    applyFilters();
    bindEvents();
    setupProfilePhotoUpload();
    setupProfileActions();
    setupChangeEmailForm();
    setupChangePasswordForm();
    setupPasswordToggles();
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

            if (targetId !== "reports-view") {
                closeReportModal();
            }
        });
    });
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
            return { category, score: Number(score.toFixed(1)), responses: bucket.count };
        }),
        ratingDistribution: distribution,
        totalEvaluations: list.length
    };
}

function renderWordFrequencyList(target, list) {
    if (!target) return;
    target.innerHTML = list
        .map(
            (item) =>
                `<li><span class="term">${capitalize(item.label)}</span><span class="count">${item.count}x</span></li>`
        )
        .join("");
}

function renderWordFrequencyListHtml(list) {
    const rows = Array.isArray(list) ? list : [];
    if (!rows.length) {
        return '<li class="empty">No word frequency data available yet.</li>';
    }

    return rows
        .map(
            (item) =>
                "<li><span class=\"term\">" + capitalize(item.label) + "</span><span class=\"count\">" + item.count + "x</span></li>"
        )
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
        barColor: "rgba(102, 126, 234, 0.8)",
        barBorder: "rgba(102, 126, 234, 1)"
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
        if (chartKey.bar) {
            chartKey.bar.destroy();
        }
        chartKey.bar = new Chart(barCtx, {
            type: "bar",
            data: {
                labels: chartData.categoryScores.map((item) => item.category),
                datasets: [{
                    label: "Average Score",
                    data: chartData.categoryScores.map((item) => item.score),
                    backgroundColor: config.barColor,
                    borderColor: config.barBorder,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 0,
                            minRotation: 0,
                            autoSkip: false
                        }
                    },
                    y: {
                        beginAtZero: true,
                        max: 5,
                        ticks: {
                            stepSize: 1
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: true,
                        position: "bottom"
                    }
                }
            }
        });
    }

    if (pieCtx) {
        if (chartKey.pie) {
            chartKey.pie.destroy();
        }
        chartKey.pie = new Chart(pieCtx, {
            type: "pie",
            data: {
                labels: ["5 Stars", "4 Stars", "3 Stars", "2 Stars", "1 Star"],
                datasets: [{
                    data: [
                        chartData.ratingDistribution[5],
                        chartData.ratingDistribution[4],
                        chartData.ratingDistribution[3],
                        chartData.ratingDistribution[2],
                        chartData.ratingDistribution[1]
                    ],
                    backgroundColor: [
                        "#10b981",
                        "#34d399",
                        "#fbbf24",
                        "#f97316",
                        "#ef4444"
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        position: "bottom"
                    }
                }
            }
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

        if (!file.type.startsWith("image/")) {
            alert("Please select a valid image file.");
            input.value = "";
            return;
        }

        const reader = new FileReader();
        reader.onload = function () {
            preview.src = reader.result;
            preview.classList.add("active");
            placeholder.style.display = "none";
            SharedData.setProfilePhoto('vpaa', reader.result);
        };
        reader.readAsDataURL(file);
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
                targetCard.scrollIntoView({ behavior: "smooth", block: "start" });
            }
        });
    });

    closeButtons.forEach((button) => {
        button.addEventListener("click", function () {
            const targetId = this.getAttribute("data-target");
            const targetCard = targetId ? document.getElementById(targetId) : null;
            if (targetCard) {
                const form = targetCard.querySelector("form");
                if (form) form.reset();
                targetCard.style.display = "none";
            }
        });
    });
}

function hideAccountActionCards() {
    document.querySelectorAll(".account-action-card").forEach((card) => {
        card.style.display = "none";
    });
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
    const currentEmail = document.getElementById("currentEmail").value.trim();
    const newEmail = document.getElementById("newEmail").value.trim();
    const confirmEmail = document.getElementById("confirmEmail").value.trim();

    if (!newEmail || !confirmEmail) {
        alert("Please fill out all email fields.");
        return;
    }

    if (newEmail !== confirmEmail) {
        alert("New email and confirmation do not match.");
        return;
    }

    if (currentEmail && newEmail.toLowerCase() === currentEmail.toLowerCase()) {
        alert("New email must be different from the current email.");
        return;
    }

    const payload = {
        username: "",
        currentEmail,
        newEmail
    };

    console.log("Ready for SQL integration: /api/vpaa/change-email", payload);
    alert("Email update request ready for SQL connection.");

    const profileEmail = document.getElementById("profileEmail");
    if (profileEmail) profileEmail.textContent = newEmail;
    const currentEmailInput = document.getElementById("currentEmail");
    if (currentEmailInput) {
        currentEmailInput.value = newEmail;
        currentEmailInput.defaultValue = newEmail;
    }

    const form = document.getElementById("changeEmailForm");
    if (form) form.reset();
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
    const currentPassword = document.getElementById("currentPassword").value.trim();
    const newPassword = document.getElementById("newPassword").value.trim();
    const confirmPassword = document.getElementById("confirmPassword").value.trim();

    if (!currentPassword || !newPassword || !confirmPassword) {
        alert("Please fill out all password fields.");
        return;
    }

    if (newPassword !== confirmPassword) {
        alert("New password and confirmation do not match.");
        return;
    }

    const payload = {
        username: "",
        currentPassword,
        newPassword
    };

    console.log("Ready for SQL integration: /api/vpaa/change-password", payload);
    alert("Password update request ready for SQL connection.");

    const form = document.getElementById("changePasswordForm");
    if (form) form.reset();
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
    card.setAttribute("aria-label", `Open evaluation report for ${prof.name}`);
    card.innerHTML = buildProfessorIdentityBlock(prof);

    const openReport = () => openProfessorReportModal(prof);
    card.addEventListener("click", openReport);
    card.addEventListener("keydown", (event) => {
        if (event.key === "Enter" || event.key === " ") {
            event.preventDefault();
            openReport();
        }
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
                <button class="btn-summary" data-prof-id="${escapeAttr(prof.id)}" aria-label="Summarise all comments for ${escapeAttr(prof.name)}">
                    Summarise
                </button>
            </div>
            <div class="vpaa-summary" data-summary-output aria-live="polite"></div>
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
        const summaryBtn = event.target.closest(".btn-summary[data-prof-id]");
        if (!summaryBtn) return;
        const profId = String(summaryBtn.getAttribute("data-prof-id") || "");
        const scope = summaryBtn.closest(".vpaa-report-details");
        const summaryEl = scope ? scope.querySelector("[data-summary-output]") : null;
        handleCommentSummary(profId, summaryEl);
    });

    elements.reportModalBody.addEventListener("change", (event) => {
        const typeSelect = event.target.closest(".vpaa-popup-eval-type");
        if (!typeSelect) return;
        const profId = String(typeSelect.getAttribute("data-prof-id") || "");
        const prof = allProfessorData.find((item) => String(item.id) === profId);
        if (!prof) return;
        renderPopupAnalyticsForType(prof, typeSelect.value);
    });

    document.addEventListener("keydown", (event) => {
        if (event.key === "Escape" && isReportModalOpen()) {
            closeReportModal();
        }
    });
}

function openProfessorReportModal(prof) {
    if (!prof || !elements.reportModal || !elements.reportModalBody) return;

    elements.reportModalBody.innerHTML = buildProfessorReportDetailsHtml(prof);
    renderPopupAnalyticsForType(prof, "student");
    elements.reportModal.classList.add("active");
    elements.reportModal.setAttribute("aria-hidden", "false");
    document.body.classList.add("vpaa-modal-open");
}

function closeReportModal() {
    if (!elements.reportModal || !elements.reportModalBody) return;

    elements.reportModal.classList.remove("active");
    elements.reportModal.setAttribute("aria-hidden", "true");
    elements.reportModalBody.innerHTML = "";
    document.body.classList.remove("vpaa-modal-open");
}

function isReportModalOpen() {
    return !!(elements.reportModal && elements.reportModal.classList.contains("active"));
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

function handleCommentSummary(profId, summaryEl) {
    const prof = allProfessorData.find((p) => String(p.id) === String(profId));
    if (!prof) return;

    const summaryText = generateCommentSummary(prof);

    if (summaryEl) {
        summaryEl.textContent = summaryText;
        summaryEl.classList.add("visible");
    } else {
        alert(summaryText);
    }
}

function generateCommentSummary(prof) {
    const student = prof.studentComments || [];
    const peer = prof.peerComments || [];
    const supervisor = prof.supervisorComments || [];
    const all = [...student, ...peer, ...supervisor].filter(Boolean);

    if (!all.length) {
        return "No comments available to summarise yet.";
    }

    const snippets = [];
    if (student.length) snippets.push(`Students: ${student[0]}`);
    if (peer.length) snippets.push(`Professors: ${peer[0]}`);
    if (supervisor.length) snippets.push(`Supervisors: ${supervisor[0]}`);

    const counts = `Students (${student.length}), Professors (${peer.length}), Supervisors (${supervisor.length})`;
    return `${counts}. Summary: ${snippets.join(" | ")}`;
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
    const session = SharedData.getSession();
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


