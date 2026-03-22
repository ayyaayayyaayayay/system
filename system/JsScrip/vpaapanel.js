const currentSemesterLabel = SharedData.getCurrentSemester() || "2nd Semester 2025-2026";
const previousSemesterLabel = "1st Semester 2025-2026";
const olderSemesterLabel = "2nd Semester 2024-2025";

/**
 * Build professor evaluation data from SharedData (centralized users).
 * Maps each professor user into the structure expected by the VPAA dashboard.
 */
function buildProfessorDataFromSharedData() {
    const users = SharedData.getUsers();
    const professors = users.filter(function (u) { return u.role === 'professor'; });
    if (!professors.length) return [];

    const criteriaKeys = [
        "Teaching Effectiveness",
        "Clarity of Instruction",
        "Assessment Fairness",
        "Student Engagement",
        "Professionalism"
    ];

    return professors.map(function (prof, index) {
        const baseRating = prof.averageRating || 0;
        const criteria = {};
        criteriaKeys.forEach(function (key) {
            criteria[key] = parseFloat(baseRating.toFixed(1));
        });

        const totalStudents = prof.totalStudents || 0;
        const evaluatedCount = prof.evaluatedCount || 0;
        const responseRate = totalStudents > 0 ? Math.round((evaluatedCount / totalStudents) * 100) : 0;

        return {
            id: prof.id || ("prof-" + (index + 1)),
            employeeId: prof.employeeId || ("FAC-" + (10000 + index)),
            name: prof.name || "Professor " + (index + 1),
            department: prof.department || "General",
            rank: prof.position || "Instructor",
            semester: currentSemesterLabel,
            overall: parseFloat(baseRating.toFixed(1)),
            responseRate: responseRate,
            evaluations: evaluatedCount,
            students: totalStudents,
            subjects: [],
            criteria: criteria,
            distribution: {
                5: Math.floor(evaluatedCount * 0.4),
                4: Math.floor(evaluatedCount * 0.3),
                3: Math.floor(evaluatedCount * 0.15),
                2: Math.floor(evaluatedCount * 0.1),
                1: Math.floor(evaluatedCount * 0.05)
            },
            trend: [baseRating - 0.2, baseRating - 0.1, baseRating, baseRating, baseRating + 0.1].map(function (v) { return parseFloat(v.toFixed(1)); }),
            studentComments: prof.qualitativeResponses || [],
            peerComments: [],
            supervisorComments: []
        };
    });
}

const professorData = buildProfessorDataFromSharedData();

const previousSemesterData = professorData.map(function (prof) {
    return Object.assign({}, prof, {
        id: prof.id + "-prev",
        semester: previousSemesterLabel,
        overall: Math.max(0, prof.overall - 0.15),
        responseRate: Math.max(0, prof.responseRate - 6),
        evaluations: Math.max(0, prof.evaluations - 8),
        students: Math.max(0, prof.students - 10),
        criteria: Object.fromEntries(
            Object.entries(prof.criteria).map(function (entry) { return [entry[0], Math.max(0, entry[1] - 0.15)]; })
        ),
        trend: prof.trend.map(function (v) { return Math.max(0, v - 0.15); }),
        studentComments: prof.studentComments.slice(0, 2),
        peerComments: prof.peerComments.slice(0, 1),
        supervisorComments: (prof.supervisorComments || []).slice(0, 1)
    });
});

const olderSemesterData = professorData.map(function (prof) {
    return Object.assign({}, prof, {
        id: prof.id + "-older",
        semester: olderSemesterLabel,
        overall: Math.max(0, prof.overall - 0.3),
        responseRate: Math.max(0, prof.responseRate - 12),
        evaluations: Math.max(0, prof.evaluations - 14),
        students: Math.max(0, prof.students - 15),
        criteria: Object.fromEntries(
            Object.entries(prof.criteria).map(function (entry) { return [entry[0], Math.max(0, entry[1] - 0.3)]; })
        ),
        trend: prof.trend.map(function (v) { return Math.max(0, v - 0.3); }),
        studentComments: prof.studentComments.slice(0, 1),
        peerComments: prof.peerComments.slice(0, 1),
        supervisorComments: (prof.supervisorComments || []).slice(0, 1)
    });
});

const allProfessorData = [].concat(professorData, previousSemesterData, olderSemesterData);

const exampleHighlights = {
    topPerformer: { name: "—", meta: "No data" },
    mostFeedback: { name: "—", meta: "No data" },
    needsAttention: { name: "—", meta: "No data" }
};

const exampleWordFrequency = {
    positive: [],
    negative: []
};

const wordLexicon = {
    positive: ["great teaching", "great", "helpful", "clear", "engaging", "supportive", "organized", "fair"],
    negative: ["bad teaching", "bad", "late", "boring", "unclear", "unfair", "strict", "confusing", "harsh", "lenient"]
};



const elements = {
    totalStudents: document.getElementById("totalStudents"),
    completionRate: document.getElementById("completionRate"),
    pendingEvaluations: document.getElementById("pendingEvaluations"),
    activeProfessors: document.getElementById("activeProfessors"),
    topPerformerName: document.getElementById("topPerformerName"),
    topPerformerMeta: document.getElementById("topPerformerMeta"),
    mostFeedbackName: document.getElementById("mostFeedbackName"),
    mostFeedbackMeta: document.getElementById("mostFeedbackMeta"),
    needsAttentionName: document.getElementById("needsAttentionName"),
    needsAttentionMeta: document.getElementById("needsAttentionMeta"),
    wordFrequencyPositive: document.getElementById("wordFrequencyPositive"),
    wordFrequencyNegative: document.getElementById("wordFrequencyNegative"),
    searchInput: document.getElementById("searchInput"),
    searchBtn: document.getElementById("searchBtn"),
    semesterFilter: document.getElementById("semesterFilter"),
    departmentFilter: document.getElementById("departmentFilter"),
    sortFilter: document.getElementById("sortFilter"),
    resetFilters: document.getElementById("resetFilters"),
    professorGrid: document.getElementById("professorGrid")
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
    setupNavigation();
    populateDepartments();
    populateSemesters();
    applyFilters();
    renderDashboardCharts();
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
        });
    });
}

function populateDepartments() {
    const departments = [...new Set(allProfessorData.map((prof) => prof.department))].sort();
    departments.forEach((dept) => {
        const option = document.createElement("option");
        option.value = dept;
        option.textContent = dept;
        elements.departmentFilter.appendChild(option);
    });
}

function populateSemesters() {
    const semesterOrder = [currentSemesterLabel, previousSemesterLabel, olderSemesterLabel];
    const semesters = [...new Set(allProfessorData.map((prof) => prof.semester))];
    const orderedSemesters = semesterOrder.filter((label) => semesters.includes(label));
    const remainingSemesters = semesters.filter((label) => !semesterOrder.includes(label)).sort();
    const finalSemesters = [...orderedSemesters, ...remainingSemesters];
    const allOption = document.createElement("option");
    allOption.value = "all";
    allOption.textContent = "All Semesters";
    elements.semesterFilter.appendChild(allOption);

    finalSemesters.forEach((semester) => {
        const option = document.createElement("option");
        option.value = semester;
        option.textContent = semester;
        elements.semesterFilter.appendChild(option);
    });

    elements.semesterFilter.value = currentSemesterLabel;
}

function bindEvents() {
    elements.searchBtn.addEventListener("click", applyFilters);
    elements.searchInput.addEventListener("keydown", (event) => {
        if (event.key === "Enter") {
            event.preventDefault();
            applyFilters();
        }
    });
    elements.semesterFilter.addEventListener("change", applyFilters);
    elements.departmentFilter.addEventListener("change", applyFilters);
    elements.sortFilter.addEventListener("change", applyFilters);
    elements.resetFilters.addEventListener("click", resetFilters);
}

function resetFilters() {
    elements.searchInput.value = "";
    elements.semesterFilter.value = currentSemesterLabel;
    elements.departmentFilter.value = "all";
    elements.sortFilter.value = "rating-high";
    applyFilters();
}

function applyFilters() {
    const term = elements.searchInput.value.trim().toLowerCase();
    const semester = elements.semesterFilter.value;
    const department = elements.departmentFilter.value;
    const sortMode = elements.sortFilter.value;

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
    updateInsights(filtered);
    updateWordFrequency(filtered);
    renderProfessors(filtered);
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

function updateSummary(list) {
    if (list.length === 0) {
        elements.totalStudents.textContent = "0";
        elements.completionRate.textContent = "0%";
        elements.pendingEvaluations.textContent = "0";
        elements.activeProfessors.textContent = "0";
        return;
    }

    const totalStudents = list.reduce((sum, prof) => sum + prof.students, 0);
    const totalEvaluations = list.reduce((sum, prof) => sum + prof.evaluations, 0);
    const completionRate = totalStudents === 0 ? 0 : Math.round((totalEvaluations / totalStudents) * 100);
    const pendingEvaluations = Math.max(0, totalStudents - totalEvaluations);

    elements.totalStudents.textContent = totalStudents.toString();
    elements.completionRate.textContent = `${completionRate}%`;
    elements.pendingEvaluations.textContent = pendingEvaluations.toString();
    elements.activeProfessors.textContent = list.length.toString();
}

function updateInsights(list) {
    let highlights = { ...exampleHighlights };

    if (list.length > 0) {
        const topPerformer = [...list].sort((a, b) => b.overall - a.overall)[0];
        const mostFeedback = [...list].sort((a, b) => {
            const aCount = a.studentComments.length + a.peerComments.length + (a.supervisorComments || []).length;
            const bCount = b.studentComments.length + b.peerComments.length + (b.supervisorComments || []).length;
            return bCount - aCount;
        })[0];
        const needsAttention = [...list].sort((a, b) => {
            if (a.overall === b.overall) {
                return a.responseRate - b.responseRate;
            }
            return a.overall - b.overall;
        })[0];

        const feedbackCount = mostFeedback.studentComments.length
            + mostFeedback.peerComments.length
            + (mostFeedback.supervisorComments || []).length;

        highlights = {
            topPerformer: {
                name: topPerformer.name,
                meta: topPerformer.department + " | " + topPerformer.overall.toFixed(1) + " rating"
            },
            mostFeedback: {
                name: mostFeedback.name,
                meta: feedbackCount + " comments logged"
            },
            needsAttention: {
                name: needsAttention.name,
                meta: needsAttention.overall.toFixed(1) + " rating | " + needsAttention.responseRate + "% response"
            }
        };
    }

    elements.topPerformerName.textContent = highlights.topPerformer.name;
    elements.topPerformerMeta.textContent = highlights.topPerformer.meta;
    elements.mostFeedbackName.textContent = highlights.mostFeedback.name;
    elements.mostFeedbackMeta.textContent = highlights.mostFeedback.meta;
    elements.needsAttentionName.textContent = highlights.needsAttention.name;
    elements.needsAttentionMeta.textContent = highlights.needsAttention.meta;
}

function updateWordFrequency(list) {
    const comments = collectAllComments(list);

    const positive = countLexicon(comments, wordLexicon.positive);
    const negative = countLexicon(comments, wordLexicon.negative);

    renderWordFrequencyList(elements.wordFrequencyPositive, positive.length ? positive : exampleWordFrequency.positive);
    renderWordFrequencyList(elements.wordFrequencyNegative, negative.length ? negative : exampleWordFrequency.negative);
}

function getWordFrequencyForProfessor(prof) {
    const comments = collectAllComments([prof]);
    const positive = countLexicon(comments, wordLexicon.positive);
    const negative = countLexicon(comments, wordLexicon.negative);

    return {
        positive: positive.length ? positive : exampleWordFrequency.positive,
        negative: negative.length ? negative : exampleWordFrequency.negative
    };
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
    return list
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

    const chartData = buildDashboardChartData();

    renderDashboardChartPair({
        key: "student",
        barId: "vpaa-student-professor-bar-chart",
        pieId: "vpaa-student-professor-pie-chart",
        avgId: "vpaa-student-prof-avg-rating",
        totalId: "vpaa-student-prof-total",
        countId: "vpaa-student-prof-count",
        barColor: "rgba(102, 126, 234, 0.8)",
        barBorder: "rgba(102, 126, 234, 1)"
    }, chartData);

    renderDashboardChartPair({
        key: "professor",
        barId: "vpaa-professor-professor-bar-chart",
        pieId: "vpaa-professor-professor-pie-chart",
        avgId: "vpaa-professor-prof-avg-rating",
        totalId: "vpaa-professor-prof-total",
        countId: "vpaa-professor-prof-count",
        barColor: "rgba(59, 130, 246, 0.8)",
        barBorder: "rgba(59, 130, 246, 1)"
    }, chartData);

    renderDashboardChartPair({
        key: "supervisor",
        barId: "vpaa-supervisor-professor-bar-chart",
        pieId: "vpaa-supervisor-professor-pie-chart",
        avgId: "vpaa-supervisor-prof-avg-rating",
        totalId: "vpaa-supervisor-prof-total",
        countId: "vpaa-supervisor-prof-count",
        barColor: "rgba(139, 92, 246, 0.8)",
        barBorder: "rgba(139, 92, 246, 1)"
    }, chartData);
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

    if (avgRatingEl) avgRatingEl.textContent = chartData.averageRating.toFixed(1);
    if (totalEvalEl) totalEvalEl.textContent = chartData.totalEvaluations.toString();
    if (profCountEl) profCountEl.textContent = chartData.evaluatedCount.toString();
}

function buildDashboardChartData() {
    const currentData = allProfessorData.filter((prof) => prof.semester === currentSemesterLabel);
    if (!currentData.length) {
        return {
            categoryScores: [],
            ratingDistribution: { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 },
            averageRating: 0,
            totalEvaluations: 0,
            evaluatedCount: 0
        };
    }

    const categories = Object.keys(currentData[0].criteria || {});
    const categoryScores = categories.map((category) => {
        const total = currentData.reduce((sum, prof) => sum + (prof.criteria[category] || 0), 0);
        const score = total / currentData.length;
        return { category, score: Number(score.toFixed(1)) };
    });

    const ratingDistribution = currentData.reduce((acc, prof) => {
        [5, 4, 3, 2, 1].forEach((star) => {
            acc[star] += prof.distribution[star] || 0;
        });
        return acc;
    }, { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 });

    const totals = Object.entries(ratingDistribution).reduce(
        (sum, [star, count]) => sum + (Number(star) * count),
        0
    );
    const totalEvaluations = Object.values(ratingDistribution).reduce((sum, count) => sum + count, 0);
    const averageRating = totalEvaluations ? totals / totalEvaluations : 0;

    return {
        categoryScores,
        ratingDistribution,
        averageRating,
        totalEvaluations,
        evaluatedCount: currentData.length
    };
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

function renderProfessors(list) {
    elements.professorGrid.innerHTML = "";
    if (list.length === 0) {
        const emptyState = document.createElement("div");
        emptyState.className = "professor-card";
        emptyState.innerHTML = "<p>No professors match the current filters.</p>";
        elements.professorGrid.appendChild(emptyState);
        return;
    }

    list.forEach((prof) => {
        elements.professorGrid.appendChild(createProfessorCard(prof));
    });
}

function createProfessorCard(prof) {
    const card = document.createElement("article");
    card.className = "professor-card";

    const deptClass = toDeptClass(prof.department);
    const initials = getInitials(prof.name);
    const wordFrequency = getWordFrequencyForProfessor(prof);
    const positiveListHtml = renderWordFrequencyListHtml(wordFrequency.positive);
    const negativeListHtml = renderWordFrequencyListHtml(wordFrequency.negative);
    const criteriaRows = Object.entries(prof.criteria)
        .map(([label, value]) => {
            const width = Math.min(100, Math.round((value / 5) * 100));
            return `
                <div class="vpaa-criteria-row">
                    <span>${label}</span>
                    <div class="vpaa-bar">
                        <div class="vpaa-fill" style="width: ${width}%"></div>
                    </div>
                    <span class="vpaa-score">${value.toFixed(1)}</span>
                </div>
            `;
        })
        .join("");

    const totalDist = Object.values(prof.distribution).reduce((sum, count) => sum + count, 0) || 1;
    const distRows = [5, 4, 3, 2, 1]
        .map((score) => {
            const count = prof.distribution[score] || 0;
            const width = Math.round((count / totalDist) * 100);
            return `
                <div class="vpaa-distribution-row">
                    <span>${score}</span>
                    <div class="vpaa-bar">
                        <div class="vpaa-fill" style="width: ${width}%"></div>
                    </div>
                    <span>${count}</span>
                </div>
            `;
        })
        .join("");

    const studentComments = renderComments(prof.studentComments);
    const peerComments = renderComments(prof.peerComments);
    const supervisorComments = renderComments(prof.supervisorComments || []);

    card.innerHTML = `
        <div class="professor-info">
            <div class="professor-avatar">${initials}</div>
            <div class="professor-details">
                <div class="professor-name-row">
                    <h3>${prof.name}</h3>
                    <span class="dept-badge ${deptClass}">${prof.department}</span>
                </div>
                <div class="professor-employee">${prof.employeeId} | ${prof.semester}</div>
                <div class="professor-position">${prof.rank}</div>

            </div>
        </div>
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
                <span>${prof.evaluations} evaluations ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¢ ${prof.students} students</span>
            </div>
        </div>
        <div class="vpaa-section">
            <div class="vpaa-section-title">Category Ratings</div>
            <div class="vpaa-criteria">${criteriaRows}</div>
        </div>
        <div class="vpaa-section scrollable-section">
            <div class="vpaa-section-title">Rating Distribution</div>
            <div class="vpaa-distribution">${distRows}</div>
            <div class="vpaa-section-title" style="margin-top:16px;">Word Frequency Snapshot</div>
            <div class="word-frequency-grid">
                <div>
                    <div class="chip positive"><i class="fas fa-thumbs-up"></i> Positive</div>
                    <ul class="word-frequency-list">${positiveListHtml}</ul>
                </div>
                <div>
                    <div class="chip negative"><i class="fas fa-thumbs-down"></i> Needs attention</div>
                    <ul class="word-frequency-list">${negativeListHtml}</ul>
                </div>
            </div>
        </div>
        <div class="vpaa-section comments-header">
            <div class="vpaa-section-title">Comments</div>
            <button class="btn-summary" data-prof-id="${prof.id}" aria-label="Summarise all comments for ${prof.name}">
                Summarise
            </button>
        </div>
        <div class="vpaa-summary" id="summary-${prof.id}" aria-live="polite"></div>
        <details class="vpaa-comments">
            <summary>
                Student to Professor
                <span class="vpaa-comment-count">${prof.studentComments.length}</span>
            </summary>
            <ul class="vpaa-comment-list">${studentComments}</ul>
        </details>
        <details class="vpaa-comments">
            <summary>
                Professor to Professor
                <span class="vpaa-comment-count">${prof.peerComments.length}</span>
            </summary>
            <ul class="vpaa-comment-list">${peerComments}</ul>
        </details>
        <details class="vpaa-comments">
            <summary>
                Supervisor to Professor
                <span class="vpaa-comment-count">${(prof.supervisorComments || []).length}</span>
            </summary>
            <ul class="vpaa-comment-list">${supervisorComments}</ul>
        </details>
    `;

    const summaryBtn = card.querySelector(".btn-summary");
    if (summaryBtn) {
        summaryBtn.addEventListener("click", () => handleCommentSummary(prof.id));
    }

    return card;
}

function renderComments(list) {
    if (!list.length) {
        return '<li class="empty">No comments submitted.</li>';
    }
    return list.map((comment) => `<li>${comment}</li>`).join("");
}

function handleCommentSummary(profId) {
    const prof = allProfessorData.find((p) => p.id === profId);
    if (!prof) return;

    const summaryText = generateCommentSummary(prof);
    const summaryEl = document.getElementById(`summary-${profId}`);

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

function toDeptClass(department) {
    const cleaned = department.replace(/[^a-z0-9]/gi, "");
    return `dept-${cleaned}`;
}

function getInitials(name) {
    const parts = name.split(" ").filter(Boolean);
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

