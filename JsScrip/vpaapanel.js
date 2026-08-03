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
    keyHighlightsGrid: document.getElementById("vpaaKeyHighlightsGrid"),
    highlightsEmpty: document.getElementById("vpaaHighlightsEmpty"),
    highlightTopRating: document.getElementById("vpaaHighlightTopRating"),
    highlightMostComments: document.getElementById("vpaaHighlightMostComments"),
    highlightNeedsAttention: document.getElementById("vpaaHighlightNeedsAttention"),
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
    setupLogout();
    setupMobileDrawer();
    setupDashboardHeroActions();
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
        const analyticsBtn = event.target.closest(".btn-ai-analytics[data-prof-id]");
        if (!analyticsBtn) return;
        const profId = String(analyticsBtn.getAttribute("data-prof-id") || "");
        const scope = analyticsBtn.closest(".vpaa-report-details");
        const outputEl = scope ? scope.querySelector("[data-ai-insight-output]") : null;
        runAiAnalyticsForProfessor(profId, outputEl, analyticsBtn);
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
    if (token === "gemini") return "Gemini";
    if (token === "gemini+rule") return "Gemini + Rule fallback";
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
            const notice = source === "gemini"
                ? ""
                : "Gemini is unavailable or partial; showing rule-based fallback insights.";
            renderAiInsightResult(outputEl, insight, source, notice);
        } catch (error) {
            console.error("[VPAA] AI analytics failed, using local fallback.", error);
            renderAiInsightResult(
                outputEl,
                fallbackInsight,
                "rule",
                "Gemini is unavailable right now. Showing rule-based fallback analytics."
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


