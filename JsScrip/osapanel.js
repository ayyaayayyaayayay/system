document.addEventListener("DOMContentLoaded", function () {
    if (!checkAuthentication()) {
        redirectToLogin();
        return;
    }

    osaProfile = getProfileData();
    loadUserInfo();
    renderProfileDetails();
    setupNavigation();
    setupLogout();
    setupMobileDrawer();
    initializeStatusMonitoring();
    setupDataSubscriptions();
    setupProfilePhotoUpload();
    setupProfileForms();
    setupProfileActionToggle();
    setupPasswordVisibility();
    setupDashboardHeroActions();
});

let allStudents = [];
let filteredStudents = [];
let osaProfile = null;
let currentSearchKeyword = "";
let latestAnalyticsSnapshot = null;
let selectedAnalyticsDepartment = "";
let selectedAnalyticsCampus = "all";
let manualClearModalContext = null;
let osaMobileDrawerBound = false;

function checkAuthentication() {
    return !!SharedData.requireSession("osa");
}

function redirectToLogin() {
    window.location.href = "mainpage.html";
}

function loadUserInfo() {
    const session = SharedData.getSession();
    if (!session) return;

    const displayName =
        (osaProfile && osaProfile.fullName) ||
        (session.username ? `${capitalizeFirstLetter(session.username)} OSA` : "OSA User");

    ["profileName", "profileNameDuplicate", "profileNameStatus", "profileNameProof"].forEach(function (id) {
        const element = document.getElementById(id);
        if (element) {
            element.textContent = displayName;
        }
    });
}

function setupLogout() {
    const logoutBtn = document.getElementById("logoutBtn");
    if (!logoutBtn) return;

    logoutBtn.addEventListener("click", function (e) {
        e.preventDefault();
        SharedData.clearSession();
        window.location.href = "mainpage.html";
    });
}

function initializeStatusMonitoring() {
    setupManualClearModal();
    setupSearch();
    setupStatusActions();
    setupAnalyticsInteractions();
    refreshStatusAndAnalytics();
}

function setupDataSubscriptions() {
    if (!SharedData.onDataChange) return;

    SharedData.onDataChange(function (key) {
        if (
            key === SharedData.KEYS.EVALUATIONS ||
            key === SharedData.KEYS.SUBJECT_MANAGEMENT ||
            key === SharedData.KEYS.CURRENT_SEMESTER ||
            key === SharedData.KEYS.USERS ||
            key === SharedData.KEYS.OSA_STUDENT_CLEARANCES ||
            key === SharedData.KEYS.STUDENT_EVAL_PROOF_REQUESTS
        ) {
            refreshStatusAndAnalytics();
        }
    });
}

function setupNavigation() {
    const navLinks = document.querySelectorAll(".sidebar-nav .nav-link[data-view]");
    const contentViews = document.querySelectorAll(".content-view");

    if (!navLinks.length || !contentViews.length) return;

    navLinks.forEach((link) => {
        link.addEventListener("click", function (e) {
            e.preventDefault();
            const targetId = link.dataset.view;
            if (!targetId) return;

            contentViews.forEach((view) => {
                view.classList.toggle("active", view.id === targetId);
            });

            navLinks.forEach((nav) => nav.classList.remove("active"));
            link.classList.add("active");
            closeMobileDrawer();
        });
    });
}

function setupMobileDrawer() {
    if (osaMobileDrawerBound) return;

    const toggleButtons = document.querySelectorAll(".mobile-nav-toggle");
    const backdrop = document.getElementById("sidebarBackdrop");
    if (!toggleButtons.length || !backdrop) return;

    toggleButtons.forEach((button) => {
        button.addEventListener("click", function () {
            const isOpen = document.body.classList.contains("osa-sidebar-open");
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

    osaMobileDrawerBound = true;
}

function openMobileDrawer() {
    document.body.classList.add("osa-sidebar-open");
    document.querySelectorAll(".mobile-nav-toggle").forEach((button) => {
        button.setAttribute("aria-expanded", "true");
    });
}

function closeMobileDrawer() {
    document.body.classList.remove("osa-sidebar-open");
    document.querySelectorAll(".mobile-nav-toggle").forEach((button) => {
        button.setAttribute("aria-expanded", "false");
    });
}

function setupDashboardHeroActions() {
    const statusBtn = document.getElementById("heroOpenStatusBtn");
    const proofBtn = document.getElementById("heroOpenProofReviewBtn");
    const navLinks = document.querySelectorAll(".sidebar-nav .nav-link[data-view]");

    function activateView(viewId) {
        document.querySelectorAll(".content-view").forEach((view) => {
            view.classList.toggle("active", view.id === viewId);
        });

        navLinks.forEach((nav) => {
            nav.classList.toggle("active", nav.dataset.view === viewId);
        });

        closeMobileDrawer();
    }

    if (statusBtn) {
        statusBtn.addEventListener("click", function () {
            activateView("studentStatusView");
        });
    }

    if (proofBtn) {
        proofBtn.addEventListener("click", function () {
            activateView("proofReviewView");
        });
    }
}

function normalizeTextToken(value) {
    return String(value || "").trim().toLowerCase();
}

function normalizeUserId(value) {
    const raw = String(value || "").trim();
    if (!raw) return "";
    const prefixed = raw.match(/^u(\d+)$/i);
    if (prefixed) return `u${prefixed[1]}`;
    const numeric = raw.match(/^\d+$/);
    if (numeric) return `u${String(parseInt(raw, 10))}`;
    return raw.toLowerCase();
}

function escapeHtml(value) {
    return String(value || "")
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

function getActiveSemesterId() {
    return String((SharedData.getCurrentSemester && SharedData.getCurrentSemester()) || "").trim();
}

function isStudentEvaluationRecord(record) {
    const role = normalizeTextToken(record && (record.evaluatorRole || record.evaluationType));
    return role === "" || role === "student";
}

function isSubmittedStatus(record) {
    const status = normalizeTextToken(record && record.status);
    return status === "" || status === "submitted";
}

function isEvaluationInSemester(record, semesterId) {
    const semester = String(semesterId || "").trim();
    if (!semester) return true;
    const evalSemester = String(record && record.semesterId || "").trim();
    return !evalSemester || evalSemester === semester;
}

function buildStudentDirectory() {
    const users = SharedData.getUsers ? SharedData.getUsers() : [];
    const directoryByUserId = new Map();
    const userIdByStudentNumber = new Map();

    users.forEach(function (user) {
        if (!user || normalizeTextToken(user.role) !== "student") return;
        if (normalizeTextToken(user.status) === "inactive") return;

        const userId = normalizeUserId(user.id);
        if (!userId) return;

        const studentNumber = String(user.studentNumber || "").trim();
        directoryByUserId.set(userId, {
            studentUserId: userId,
            studentNumber: studentNumber,
            fullName: String(user.name || "").trim() || "Unknown Student",
            department: String(user.department || user.institute || "UNASSIGNED").trim().toUpperCase() || "UNASSIGNED",
            program: String(user.programCode || user.programName || "UNASSIGNED").trim().toUpperCase() || "UNASSIGNED",
            campus: String(user.campus || user.campusSlug || "UNASSIGNED").trim().toUpperCase() || "UNASSIGNED",
            yearSection: String(user.yearSection || "").trim() || "N/A",
        });

        if (studentNumber) {
            userIdByStudentNumber.set(normalizeTextToken(studentNumber), userId);
        }
    });

    return { directoryByUserId, userIdByStudentNumber };
}

function getEvaluationPeriodState() {
    const dates = SharedData.getEvalPeriodDates
        ? SharedData.getEvalPeriodDates("student-professor")
        : { start: "", end: "" };
    const endRaw = String(dates && dates.end || "").trim();
    if (!endRaw) {
        return {
            isClosed: false,
            hasEndDate: false,
            note: "Proof review is unavailable because the Student-to-Professor period end date is not configured.",
        };
    }

    const todayYmd = SharedData.getCurrentPhilippineDateYmd();
    const isClosed = todayYmd !== '' && todayYmd > endRaw;

    return {
        isClosed: isClosed,
        hasEndDate: true,
        note: isClosed
            ? `Evaluation period ended on ${endRaw}. OSA can now review submitted student proof requests.`
            : `Proof review becomes available after the evaluation period ends on ${endRaw}.`,
    };
}

function buildStatusRows() {
    const semesterId = getActiveSemesterId();
    const periodState = getEvaluationPeriodState();
    const directory = buildStudentDirectory();
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
        if (!enrollment || normalizeTextToken(enrollment.status) !== "enrolled") return;

        const offeringId = String(enrollment.courseOfferingId || "").trim();
        if (!offeringId || !activeOfferingsById.has(offeringId)) return;

        let studentUserId = normalizeUserId(enrollment.studentUserId || enrollment.studentId);
        const studentNumber = String(enrollment.studentNumber || "").trim();
        if (!studentUserId && studentNumber) {
            studentUserId = userIdByStudentNumber.get(normalizeTextToken(studentNumber)) || "";
        }
        if (!studentUserId) return;

        if (!expectedByStudent.has(studentUserId)) {
            expectedByStudent.set(studentUserId, new Set());
        }
        expectedByStudent.get(studentUserId).add(offeringId);

        const baseMeta = directoryByUserId.get(studentUserId);
        studentMetaById.set(studentUserId, {
            studentUserId,
            studentNumber: studentNumber || (baseMeta && baseMeta.studentNumber) || "",
            fullName: String(enrollment.studentName || "").trim() || (baseMeta && baseMeta.fullName) || "Unknown Student",
            department: (baseMeta && baseMeta.department) || "UNASSIGNED",
            program: (baseMeta && baseMeta.program) || "UNASSIGNED",
            campus: (baseMeta && baseMeta.campus) || "UNASSIGNED",
            yearSection: (baseMeta && baseMeta.yearSection) || "N/A",
        });
    });

    const completedByStudent = new Map();
    evaluations.forEach(function (evaluation) {
        if (!isStudentEvaluationRecord(evaluation)) return;
        if (!isSubmittedStatus(evaluation)) return;
        if (!isEvaluationInSemester(evaluation, semesterId)) return;

        const offeringId = String(evaluation.courseOfferingId || "").trim();
        if (!offeringId) return;

        let studentUserId = normalizeUserId(
            evaluation.studentUserId ||
            evaluation.studentId ||
            evaluation.evaluatorId ||
            evaluation.userId
        );

        const evalStudentNumber = String(evaluation.studentNumber || "").trim();
        if (!studentUserId && evalStudentNumber) {
            studentUserId = userIdByStudentNumber.get(normalizeTextToken(evalStudentNumber)) || "";
        }
        if (!studentUserId) return;
        if (!expectedByStudent.has(studentUserId)) return;
        if (!expectedByStudent.get(studentUserId).has(offeringId)) return;

        if (!completedByStudent.has(studentUserId)) {
            completedByStudent.set(studentUserId, new Set());
        }
        completedByStudent.get(studentUserId).add(offeringId);
    });

    const clearanceRows = SharedData.getOsaStudentClearances ? SharedData.getOsaStudentClearances() : [];
    const clearanceByUserAndSemester = new Map();
    const clearanceByNumberAndSemester = new Map();
    clearanceRows.forEach(function (row) {
        if (!row || normalizeTextToken(row.status || "cleared") !== "cleared") return;
        const sem = String(row.semesterId || "").trim();
        if (!sem || (semesterId && sem !== semesterId)) return;

        const userId = normalizeUserId(row.studentUserId);
        const studentNumber = normalizeTextToken(row.studentNumber);
        if (userId) clearanceByUserAndSemester.set(`${userId}|${sem}`, row);
        if (studentNumber) clearanceByNumberAndSemester.set(`${studentNumber}|${sem}`, row);
    });

    const proofRows = SharedData.getStudentEvaluationProofRequests
        ? SharedData.getStudentEvaluationProofRequests()
        : [];
    const proofByUserAndSemester = new Map();
    const proofByNumberAndSemester = new Map();

    function upsertLatestProof(map, key, row) {
        if (!key) return;
        const existing = map.get(key);
        if (!existing) {
            map.set(key, row);
            return;
        }
        const existingTs = Date.parse(String(existing.submittedAt || existing.reviewedAt || "")) || 0;
        const candidateTs = Date.parse(String(row.submittedAt || row.reviewedAt || "")) || 0;
        if (candidateTs >= existingTs) {
            map.set(key, row);
        }
    }

    proofRows.forEach(function (row) {
        if (!row) return;
        const sem = String(row.semesterId || "").trim();
        if (!sem || (semesterId && sem !== semesterId)) return;

        const userId = normalizeUserId(row.studentUserId);
        const studentNumber = normalizeTextToken(row.studentNumber);
        if (userId) upsertLatestProof(proofByUserAndSemester, `${userId}|${sem}`, row);
        if (studentNumber) upsertLatestProof(proofByNumberAndSemester, `${studentNumber}|${sem}`, row);
    });

    const rows = [];
    expectedByStudent.forEach(function (expectedSet, studentUserId) {
        const meta = studentMetaById.get(studentUserId) || directoryByUserId.get(studentUserId) || {
            studentUserId,
            studentNumber: "",
            fullName: "Unknown Student",
            department: "UNASSIGNED",
            program: "UNASSIGNED",
            campus: "UNASSIGNED",
            yearSection: "N/A",
        };
        const expectedCount = expectedSet.size;
        const completedCount = (completedByStudent.get(studentUserId) || new Set()).size;
        const evaluated = expectedCount > 0 && completedCount >= expectedCount;

        let clearance = null;
        if (!evaluated) {
            clearance = clearanceByUserAndSemester.get(`${studentUserId}|${semesterId}`)
                || clearanceByNumberAndSemester.get(`${normalizeTextToken(meta.studentNumber)}|${semesterId}`)
                || null;
        }

        const cleared = Boolean(clearance);
        const proof = proofByUserAndSemester.get(`${studentUserId}|${semesterId}`)
            || proofByNumberAndSemester.get(`${normalizeTextToken(meta.studentNumber)}|${semesterId}`)
            || null;
        const proofStatus = normalizeTextToken(proof && proof.status || "");

        rows.push({
            studentUserId,
            studentNumber: meta.studentNumber || "",
            fullName: meta.fullName || "Unknown Student",
            department: meta.department || "UNASSIGNED",
            program: meta.program || "UNASSIGNED",
            campus: meta.campus || "UNASSIGNED",
            yearSection: meta.yearSection || "N/A",
            expectedCount,
            completedCount,
            evaluated,
            cleared,
            clearanceReason: cleared ? String(clearance.reason || "").trim() : "",
            clearanceNotedAt: cleared ? String(clearance.notedAt || "").trim() : "",
            canReviewProof: !evaluated && !cleared && periodState.isClosed && proofStatus === "pending",
            proofId: proof ? String(proof.id || "").trim() : "",
            proofStatus: proofStatus,
            proofReason: proof ? String(proof.reason || "").trim() : "",
            proofDriveLink: proof ? String(proof.proofDriveLink || "").trim() : "",
            proofSubmittedAt: proof ? String(proof.submittedAt || "").trim() : "",
            proofReviewedAt: proof ? String(proof.reviewedAt || "").trim() : "",
            proofReviewNote: proof ? String(proof.reviewNote || "").trim() : "",
        });
    });

    rows.sort(function (a, b) {
        return String(a.fullName || "").localeCompare(String(b.fullName || ""));
    });

    return {
        rows,
        periodState,
        summary: buildSummaryFromRows(rows),
        departmentBreakdown: buildDepartmentBreakdown(rows),
        programBreakdown: buildProgramBreakdown(rows),
    };
}

function buildSummaryFromRows(rows) {
    const assigned = rows.length;
    const evaluated = rows.filter(function (row) { return row.evaluated; }).length;
    const notEvaluated = Math.max(assigned - evaluated, 0);
    const completionRate = assigned > 0 ? Math.round((evaluated / assigned) * 100) : 0;
    return { assigned, evaluated, notEvaluated, completionRate };
}

function buildDepartmentBreakdown(rows) {
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

function buildProgramBreakdown(rows) {
    const map = new Map();
    rows.forEach(function (row) {
        const program = row.program || "UNASSIGNED";
        const department = row.department || "UNASSIGNED";
        const key = `${department}|${program}`;
        if (!map.has(key)) {
            map.set(key, { program, department, assigned: 0, evaluated: 0, notEvaluated: 0, completionRate: 0 });
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

function getAnalyticsRowsForSelectedCampus(rows) {
    const source = Array.isArray(rows) ? rows : [];
    const selectedCampus = String(selectedAnalyticsCampus || "all").trim();
    if (!selectedCampus || selectedCampus === "all") return source;

    return source.filter(function (row) {
        return String(row && row.campus || "UNASSIGNED").trim() === selectedCampus;
    });
}

function buildAnalyticsSnapshotFromRows(rows, sourceSnapshot) {
    return {
        rows,
        periodState: sourceSnapshot && sourceSnapshot.periodState,
        summary: buildSummaryFromRows(rows),
        departmentBreakdown: buildDepartmentBreakdown(rows),
        programBreakdown: buildProgramBreakdown(rows),
    };
}

function renderAnalyticsCampusFilter(rows) {
    const select = document.getElementById("analyticsCampusSelect");
    if (!select) return;

    const campuses = Array.from(new Set((Array.isArray(rows) ? rows : []).map(function (row) {
        return String(row && row.campus || "UNASSIGNED").trim() || "UNASSIGNED";
    }))).sort(function (a, b) {
        return a.localeCompare(b);
    });

    const currentValue = campuses.includes(selectedAnalyticsCampus) ? selectedAnalyticsCampus : "all";
    if (selectedAnalyticsCampus !== currentValue) {
        selectedAnalyticsCampus = currentValue;
        selectedAnalyticsDepartment = "";
    }

    const options = ['<option value="all">All Campuses</option>'].concat(campuses.map(function (campus) {
        const selected = campus === selectedAnalyticsCampus ? " selected" : "";
        return `<option value="${escapeHtml(campus)}"${selected}>${escapeHtml(campus)}</option>`;
    }));

    select.innerHTML = options.join("");
    select.value = selectedAnalyticsCampus;
}

function refreshStatusAndAnalytics() {
    const snapshot = buildStatusRows();
    allStudents = snapshot.rows;
    filteredStudents = applySearchFilter(allStudents, currentSearchKeyword);

    renderStatusPeriodNote(snapshot.periodState);
    renderStatusTable(filteredStudents, snapshot.periodState);
    renderProofReviewTable(snapshot.periodState);
    renderDashboardAnalytics(snapshot);
}

function renderStatusPeriodNote(periodState) {
    const noteEl = document.getElementById("statusPeriodNote");
    if (!noteEl) return;
    noteEl.textContent = periodState && periodState.note ? periodState.note : "";
}

function renderDashboardAnalytics(snapshot) {
    latestAnalyticsSnapshot = snapshot;
    renderAnalyticsCampusFilter(snapshot.rows);

    const analyticsSnapshot = buildAnalyticsSnapshotFromRows(
        getAnalyticsRowsForSelectedCampus(snapshot.rows),
        snapshot
    );
    const summary = analyticsSnapshot.summary || { assigned: 0, evaluated: 0, notEvaluated: 0, completionRate: 0 };
    setText("assignedCount", summary.assigned);
    setText("evaluatedCountAnalytics", summary.evaluated);
    setText("notEvaluatedCountAnalytics", summary.notEvaluated);
    setText("completionRateCount", `${summary.completionRate}%`);

    const deptBody = document.getElementById("departmentAnalyticsBody");
    const progBody = document.getElementById("departmentProgramsBody");
    const progTitle = document.getElementById("departmentProgramsTitle");
    const progEmpty = document.getElementById("departmentProgramsEmpty");
    const emptyEl = document.getElementById("analyticsEmptyState");
    if (!deptBody || !progBody || !progTitle || !progEmpty || !emptyEl) return;

    if (!analyticsSnapshot.rows.length) {
        deptBody.innerHTML = "";
        progBody.innerHTML = "";
        selectedAnalyticsDepartment = "";
        progTitle.textContent = "Programs by Department";
        progEmpty.textContent = "Select a department above to view its programs.";
        progEmpty.style.display = "block";
        emptyEl.style.display = "block";
        emptyEl.textContent = selectedAnalyticsCampus === "all"
            ? "No assigned students found for analytics."
            : "No assigned students found for the selected campus.";
        return;
    }

    emptyEl.style.display = "none";
    const hasSelectedDepartment = analyticsSnapshot.departmentBreakdown.some(function (item) {
        return item.department === selectedAnalyticsDepartment;
    });
    if (!hasSelectedDepartment) {
        selectedAnalyticsDepartment = "";
    }

    deptBody.innerHTML = analyticsSnapshot.departmentBreakdown.map(function (item) {
        const isActive = item.department === selectedAnalyticsDepartment;
        return `
            <tr class="analytics-dept-row${isActive ? " active" : ""}" data-department="${escapeHtml(item.department)}" tabindex="0" role="button" aria-label="Show programs for ${escapeHtml(item.department)}">
                <td>${escapeHtml(item.department)}</td>
                <td>${item.assigned}</td>
                <td>${item.evaluated}</td>
                <td>${item.notEvaluated}</td>
                <td>${item.completionRate}%</td>
            </tr>
        `;
    }).join("");

    if (!selectedAnalyticsDepartment) {
        progBody.innerHTML = "";
        progTitle.textContent = "Programs by Department";
        progEmpty.textContent = "Select a department above to view its programs.";
        progEmpty.style.display = "block";
        return;
    }

    const filteredPrograms = analyticsSnapshot.programBreakdown.filter(function (item) {
        return item.department === selectedAnalyticsDepartment;
    });
    progTitle.textContent = `Programs under ${selectedAnalyticsDepartment}`;

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

function formatNotedAt(value) {
    const raw = String(value || "").trim();
    if (!raw) return "";
    const formatted = SharedData.formatDateTimeInPhilippines(raw);
    return formatted || raw;
}

function renderStatusTable(students, periodState) {
    const tbody = document.getElementById("statusTableBody");
    const actionHeader = document.getElementById("statusActionHeader");
    const emptyState = document.getElementById("emptyState");
    if (!tbody || !emptyState) return;

    const showActionColumn = Boolean(periodState && periodState.isClosed);
    const activeSemesterId = getActiveSemesterId();
    if (actionHeader) {
        actionHeader.style.display = showActionColumn ? "" : "none";
    }

    if (!students.length) {
        tbody.innerHTML = "";
        emptyState.style.display = "block";
        return;
    }

    emptyState.style.display = "none";
    tbody.innerHTML = students.map(function (student) {
        const proofStatus = normalizeTextToken(student.proofStatus);
        let statusClass = "not-done";
        let statusText = "Not Done";
        let icon = "fa-circle-xmark";
        if (student.evaluated) {
            statusClass = "done";
            statusText = "Done";
            icon = "fa-circle-check";
        } else if (student.cleared) {
            statusClass = "cleared";
            statusText = "Cleared";
            icon = "fa-file-circle-check";
        } else if (proofStatus === "pending") {
            statusClass = "pending-review";
            statusText = "Pending Review";
            icon = "fa-hourglass-half";
        } else if (proofStatus === "rejected") {
            statusClass = "rejected";
            statusText = "Rejected";
            icon = "fa-triangle-exclamation";
        }
        const progressText = `${student.completedCount}/${student.expectedCount}`;

        const clearedReasonBlock = student.cleared && student.clearanceReason
            ? `<div class="status-reason">Cleared Reason: ${escapeHtml(student.clearanceReason)}${student.clearanceNotedAt ? ` (${escapeHtml(formatNotedAt(student.clearanceNotedAt))})` : ""}</div>`
            : "";
        const proofReasonBlock = !student.evaluated && proofStatus
            ? `<div class="status-reason">Proof Reason: ${escapeHtml(student.proofReason || "N/A")}${student.proofSubmittedAt ? ` (${escapeHtml(formatNotedAt(student.proofSubmittedAt))})` : ""}</div>`
            : "";
        const proofLinkBlock = !student.evaluated && proofStatus && student.proofDriveLink
            ? `<div class="status-reason">Drive Link: <a class="status-proof-link" href="${escapeHtml(student.proofDriveLink)}" target="_blank" rel="noopener noreferrer">Open proof</a></div>`
            : "";
        const reviewNoteBlock = !student.evaluated && proofStatus === "rejected" && student.proofReviewNote
            ? `<div class="status-reason">OSA Review Note: ${escapeHtml(student.proofReviewNote)}${student.proofReviewedAt ? ` (${escapeHtml(formatNotedAt(student.proofReviewedAt))})` : ""}</div>`
            : "";

        let actionCell = "";
        if (showActionColumn) {
            let actionHtml = "";
            if (student.evaluated) {
                actionHtml = `<span class="status-progress">Completed</span>`;
            } else if (student.cleared) {
                actionHtml = `<span class="status-progress">Cleared (Locked)</span>`;
            } else if (student.canReviewProof) {
                actionHtml = `
                    <div class="status-review-actions">
                        <button type="button" class="status-action-btn approve" data-review-action="approve" data-proof-id="${escapeHtml(student.proofId)}" data-student-user-id="${escapeHtml(student.studentUserId)}" data-student-number="${escapeHtml(student.studentNumber)}" data-semester-id="${escapeHtml(activeSemesterId)}">Approve</button>
                        <button type="button" class="status-action-btn reject" data-review-action="reject" data-proof-id="${escapeHtml(student.proofId)}" data-student-user-id="${escapeHtml(student.studentUserId)}" data-student-number="${escapeHtml(student.studentNumber)}" data-semester-id="${escapeHtml(activeSemesterId)}">Reject</button>
                        <button type="button" class="status-action-btn manual" data-review-action="manual-clear" data-student-user-id="${escapeHtml(student.studentUserId)}" data-student-number="${escapeHtml(student.studentNumber)}" data-student-name="${escapeHtml(student.fullName)}" data-semester-id="${escapeHtml(activeSemesterId)}">Mark Cleared</button>
                    </div>
                `;
            } else if (proofStatus === "rejected") {
                actionHtml = `
                    <div class="status-review-actions">
                        <button type="button" class="status-action-btn manual" data-review-action="manual-clear" data-student-user-id="${escapeHtml(student.studentUserId)}" data-student-number="${escapeHtml(student.studentNumber)}" data-student-name="${escapeHtml(student.fullName)}" data-semester-id="${escapeHtml(activeSemesterId)}">Mark Cleared</button>
                    </div>
                    <div class="status-reason">Awaiting student resubmission</div>
                `;
            } else if (proofStatus === "pending") {
                actionHtml = `
                    <div class="status-review-actions">
                        <button type="button" class="status-action-btn manual" data-review-action="manual-clear" data-student-user-id="${escapeHtml(student.studentUserId)}" data-student-number="${escapeHtml(student.studentNumber)}" data-student-name="${escapeHtml(student.fullName)}" data-semester-id="${escapeHtml(activeSemesterId)}">Mark Cleared</button>
                    </div>
                    <div class="status-reason">Pending OSA decision</div>
                `;
            } else if (periodState && periodState.isClosed) {
                actionHtml = `
                    <div class="status-review-actions">
                        <button type="button" class="status-action-btn manual" data-review-action="manual-clear" data-student-user-id="${escapeHtml(student.studentUserId)}" data-student-number="${escapeHtml(student.studentNumber)}" data-student-name="${escapeHtml(student.fullName)}" data-semester-id="${escapeHtml(activeSemesterId)}">Mark Cleared</button>
                    </div>
                    <div class="status-reason">Awaiting student proof submission</div>
                `;
            } else {
                const note = periodState && periodState.hasEndDate
                    ? "Available after evaluation period ends"
                    : "Unavailable: no period end date";
                actionHtml = `<span class="status-progress">${escapeHtml(note)}</span>`;
            }
            actionCell = `<td>${actionHtml}</td>`;
        }

        return `
            <tr>
                <td>${escapeHtml(student.studentNumber)}</td>
                <td>${escapeHtml(student.fullName)}</td>
                <td>${escapeHtml(student.department)}</td>
                <td>${escapeHtml(student.program)}</td>
                <td>${escapeHtml(student.yearSection)}</td>
                <td><span class="status-progress">${escapeHtml(progressText)}</span></td>
                <td>
                    <div class="status-cell">
                        <span class="status-pill ${statusClass}">
                            <i class="fas ${icon}"></i>
                            ${statusText}
                        </span>
                        ${clearedReasonBlock}
                        ${proofReasonBlock}
                        ${proofLinkBlock}
                        ${reviewNoteBlock}
                    </div>
                </td>
                ${actionCell}
            </tr>
        `;
    }).join("");
}

function buildProofReviewRows() {
    const semesterId = getActiveSemesterId();
    const proofRows = SharedData.getStudentEvaluationProofRequests
        ? SharedData.getStudentEvaluationProofRequests()
        : [];
    if (!proofRows.length) return [];

    const directory = buildStudentDirectory();
    const directoryByUserId = directory.directoryByUserId;
    const userIdByStudentNumber = directory.userIdByStudentNumber;
    const latestByStudentSemester = new Map();
    const clearanceRows = SharedData.getOsaStudentClearances ? SharedData.getOsaStudentClearances() : [];
    const clearanceByUserAndSemester = new Map();
    const clearanceByNumberAndSemester = new Map();

    clearanceRows.forEach(function (row) {
        if (!row || normalizeTextToken(row.status || "cleared") !== "cleared") return;
        const sem = String(row.semesterId || "").trim();
        if (!sem || (semesterId && sem !== semesterId)) return;

        const userId = normalizeUserId(row.studentUserId);
        const studentNumber = normalizeTextToken(row.studentNumber);
        if (userId) clearanceByUserAndSemester.set(`${userId}|${sem}`, row);
        if (studentNumber) clearanceByNumberAndSemester.set(`${studentNumber}|${sem}`, row);
    });

    function upsertLatest(map, key, row) {
        if (!key) return;
        const existing = map.get(key);
        if (!existing) {
            map.set(key, row);
            return;
        }
        const existingTs = Date.parse(String(existing.submittedAt || existing.reviewedAt || "")) || 0;
        const candidateTs = Date.parse(String(row.submittedAt || row.reviewedAt || "")) || 0;
        if (candidateTs >= existingTs) {
            map.set(key, row);
        }
    }

    proofRows.forEach(function (row) {
        if (!row) return;
        const rowSemesterId = String(row.semesterId || "").trim();
        if (!rowSemesterId) return;
        if (semesterId && rowSemesterId !== semesterId) return;

        let studentUserId = normalizeUserId(row.studentUserId);
        const studentNumber = String(row.studentNumber || "").trim();
        if (!studentUserId && studentNumber) {
            studentUserId = userIdByStudentNumber.get(normalizeTextToken(studentNumber)) || "";
        }

        const keyIdentity = studentUserId || normalizeTextToken(studentNumber);
        if (!keyIdentity) return;

        upsertLatest(latestByStudentSemester, `${keyIdentity}|${rowSemesterId}`, {
            proofId: String(row.id || "").trim(),
            studentUserId: studentUserId,
            studentNumber: studentNumber,
            semesterId: rowSemesterId,
            status: normalizeTextToken(row.status),
            reason: String(row.reason || "").trim(),
            proofDriveLink: String(row.proofDriveLink || "").trim(),
            submittedAt: String(row.submittedAt || "").trim(),
            reviewNote: String(row.reviewNote || "").trim(),
            reviewedAt: String(row.reviewedAt || "").trim(),
        });
    });

    const list = Array.from(latestByStudentSemester.values()).map(function (row) {
        const resolvedUserId = row.studentUserId
            || userIdByStudentNumber.get(normalizeTextToken(row.studentNumber))
            || "";
        const baseMeta = directoryByUserId.get(resolvedUserId);
        const cleared = Boolean(
            clearanceByUserAndSemester.get(`${resolvedUserId}|${row.semesterId}`)
            || clearanceByNumberAndSemester.get(`${normalizeTextToken(row.studentNumber)}|${row.semesterId}`)
        );
        return {
            proofId: row.proofId,
            studentUserId: resolvedUserId,
            studentNumber: row.studentNumber || (baseMeta && baseMeta.studentNumber) || "",
            fullName: (baseMeta && baseMeta.fullName) || "Unknown Student",
            semesterId: row.semesterId,
            cleared: cleared,
            status: row.status,
            reason: row.reason,
            proofDriveLink: row.proofDriveLink,
            submittedAt: row.submittedAt,
            reviewNote: row.reviewNote,
            reviewedAt: row.reviewedAt,
        };
    });

    list.sort(function (a, b) {
        const aTs = Date.parse(String(a.submittedAt || "")) || 0;
        const bTs = Date.parse(String(b.submittedAt || "")) || 0;
        if (bTs !== aTs) return bTs - aTs;
        return String(a.fullName || "").localeCompare(String(b.fullName || ""));
    });
    return list;
}

function renderProofReviewTable(periodState) {
    const tbody = document.getElementById("proofReviewTableBody");
    const emptyState = document.getElementById("proofReviewEmptyState");
    const noteEl = document.getElementById("proofReviewPeriodNote");
    if (!tbody || !emptyState || !noteEl) return;

    noteEl.textContent = periodState && periodState.note ? periodState.note : "";
    const rows = buildProofReviewRows();
    if (!rows.length) {
        tbody.innerHTML = "";
        emptyState.style.display = "block";
        return;
    }

    emptyState.style.display = "none";
    tbody.innerHTML = rows.map(function (row) {
        let statusClass = "not-done";
        let statusText = "Submitted";
        let icon = "fa-file-lines";
        if (row.cleared) {
            statusClass = "cleared";
            statusText = "Cleared";
            icon = "fa-file-circle-check";
        } else if (row.status === "pending") {
            statusClass = "pending-review";
            statusText = "Pending Review";
            icon = "fa-hourglass-half";
        } else if (row.status === "approved") {
            statusClass = "cleared";
            statusText = "Approved";
            icon = "fa-file-circle-check";
        } else if (row.status === "rejected") {
            statusClass = "rejected";
            statusText = "Rejected";
            icon = "fa-triangle-exclamation";
        }

        let actionHtml = `<span class="status-progress">Reviewed</span>`;
        if (row.cleared || row.status === "approved") {
            actionHtml = `<span class="status-progress">Cleared (Locked)</span>`;
        } else if (row.status === "pending") {
            if (periodState && periodState.isClosed) {
                actionHtml = `
                    <div class="status-review-actions">
                        <button type="button" class="status-action-btn approve" data-review-action="approve" data-proof-id="${escapeHtml(row.proofId)}" data-student-user-id="${escapeHtml(row.studentUserId)}" data-student-number="${escapeHtml(row.studentNumber)}" data-semester-id="${escapeHtml(row.semesterId)}">Approve</button>
                        <button type="button" class="status-action-btn reject" data-review-action="reject" data-proof-id="${escapeHtml(row.proofId)}" data-student-user-id="${escapeHtml(row.studentUserId)}" data-student-number="${escapeHtml(row.studentNumber)}" data-semester-id="${escapeHtml(row.semesterId)}">Reject</button>
                    </div>
                `;
            } else {
                actionHtml = `<span class="status-progress">Available after evaluation period ends</span>`;
            }
        } else if (row.status === "rejected") {
            actionHtml = `<span class="status-progress">Rejected</span>`;
        }

        const linkHtml = row.proofDriveLink
            ? `<a class="status-proof-link" href="${escapeHtml(row.proofDriveLink)}" target="_blank" rel="noopener noreferrer">Open proof</a>`
            : `<span class="status-progress">No link</span>`;

        const reviewNote = row.reviewNote
            ? `${escapeHtml(row.reviewNote)}${row.reviewedAt ? ` (${escapeHtml(formatNotedAt(row.reviewedAt))})` : ""}`
            : `<span class="status-progress">N/A</span>`;

        return `
            <tr>
                <td>${escapeHtml(row.studentNumber)}</td>
                <td>${escapeHtml(row.fullName)}</td>
                <td>${escapeHtml(row.semesterId)}</td>
                <td>
                    <span class="status-pill ${statusClass}">
                        <i class="fas ${icon}"></i>
                        ${statusText}
                    </span>
                </td>
                <td class="proof-reason-cell">
                    ${escapeHtml(row.reason || "N/A")}
                    ${row.submittedAt ? `<div class="status-reason">Submitted: ${escapeHtml(formatNotedAt(row.submittedAt))}</div>` : ""}
                </td>
                <td>${linkHtml}</td>
                <td class="proof-note-cell">${reviewNote}</td>
                <td>${actionHtml}</td>
            </tr>
        `;
    }).join("");
}

function setupAnalyticsInteractions() {
    const deptBody = document.getElementById("departmentAnalyticsBody");
    const campusSelect = document.getElementById("analyticsCampusSelect");
    if (!deptBody && !campusSelect) return;

    function selectDepartmentFromEvent(event) {
        const row = event.target.closest("tr[data-department]");
        if (!row) return;
        const department = String(row.dataset.department || "").trim();
        if (!department || department === selectedAnalyticsDepartment) return;

        selectedAnalyticsDepartment = department;
        if (latestAnalyticsSnapshot) {
            renderDashboardAnalytics(latestAnalyticsSnapshot);
        }
    }

    if (deptBody) {
        deptBody.addEventListener("click", selectDepartmentFromEvent);
        deptBody.addEventListener("keydown", function (event) {
            if (event.key !== "Enter" && event.key !== " ") return;
            event.preventDefault();
            selectDepartmentFromEvent(event);
        });
    }

    if (campusSelect) {
        campusSelect.addEventListener("change", function () {
            selectedAnalyticsCampus = String(campusSelect.value || "all").trim() || "all";
            selectedAnalyticsDepartment = "";
            if (latestAnalyticsSnapshot) {
                renderDashboardAnalytics(latestAnalyticsSnapshot);
            }
        });
    }
}

function setupSearch() {
    const searchInput = document.getElementById("studentSearch");
    if (!searchInput) return;

    searchInput.addEventListener("input", function () {
        currentSearchKeyword = searchInput.value.trim().toLowerCase();
        filteredStudents = applySearchFilter(allStudents, currentSearchKeyword);
        renderStatusTable(filteredStudents, getEvaluationPeriodState());
    });
}

function applySearchFilter(rows, keyword) {
    const token = String(keyword || "").trim().toLowerCase();
    if (!token) return [...rows];
    return rows.filter(function (student) {
        return (
            String(student.fullName || "").toLowerCase().includes(token) ||
            String(student.studentNumber || "").toLowerCase().includes(token)
        );
    });
}

function setupStatusActions() {
    const actionContainers = [
        document.getElementById("statusTableBody"),
        document.getElementById("proofReviewTableBody"),
    ].filter(Boolean);
    if (!actionContainers.length) return;

    actionContainers.forEach(function (container) {
        container.addEventListener("click", handleProofReviewActionClick);
    });
}

function setupManualClearModal() {
    const modal = document.getElementById("manualClearModal");
    const form = document.getElementById("manualClearForm");
    const cancelBtn = document.getElementById("manualClearCancelBtn");
    const closeBtn = document.getElementById("manualClearCloseBtn");
    if (!modal || !form || !cancelBtn || !closeBtn) return;

    cancelBtn.addEventListener("click", closeManualClearModal);
    closeBtn.addEventListener("click", closeManualClearModal);
    modal.addEventListener("click", function (event) {
        if (event.target === modal) {
            closeManualClearModal();
        }
    });
    document.addEventListener("keydown", function (event) {
        if (event.key === "Escape" && modal.classList.contains("active")) {
            closeManualClearModal();
        }
    });
    form.addEventListener("submit", handleManualClearFormSubmit);
}

function setManualClearModalMessage(type, text) {
    const messageEl = document.getElementById("manualClearMessage");
    if (!messageEl) return;

    messageEl.classList.toggle("error", type === "error");
    messageEl.textContent = String(text || "");
}

function setManualClearModalBusy(isBusy) {
    const reasonInput = document.getElementById("manualClearReasonInput");
    const saveBtn = document.getElementById("manualClearSaveBtn");
    const cancelBtn = document.getElementById("manualClearCancelBtn");
    const closeBtn = document.getElementById("manualClearCloseBtn");

    [reasonInput, saveBtn, cancelBtn, closeBtn].forEach(function (element) {
        if (element) {
            element.disabled = Boolean(isBusy);
        }
    });
}

function openManualClearModal(context) {
    const modal = document.getElementById("manualClearModal");
    const leadEl = document.getElementById("manualClearLead");
    const reasonInput = document.getElementById("manualClearReasonInput");
    if (!modal || !leadEl || !reasonInput) return;

    manualClearModalContext = context || null;
    const studentName = String(context && context.studentName || "").trim();
    const studentNumber = String(context && context.studentNumber || "").trim();
    const studentLabel = studentName && studentNumber
        ? `${studentName} (${studentNumber})`
        : (studentName || studentNumber || "this student");
    leadEl.textContent = `Enter reason for manually marking ${studentLabel} as cleared.`;

    reasonInput.value = "";
    setManualClearModalMessage("", "");
    setManualClearModalBusy(false);

    modal.classList.add("active");
    modal.setAttribute("aria-hidden", "false");
    document.body.classList.add("manual-clear-modal-open");

    requestAnimationFrame(function () {
        reasonInput.focus();
    });
}

function closeManualClearModal() {
    const modal = document.getElementById("manualClearModal");
    const reasonInput = document.getElementById("manualClearReasonInput");
    if (!modal) return;

    modal.classList.remove("active");
    modal.setAttribute("aria-hidden", "true");
    document.body.classList.remove("manual-clear-modal-open");
    setManualClearModalMessage("", "");

    if (reasonInput) {
        reasonInput.value = "";
    }
    manualClearModalContext = null;
}

function persistManualClearRequest(context, manualReason) {
    if (!SharedData.upsertOsaStudentClearance) {
        throw new Error("Clearance persistence is unavailable.");
    }

    const response = SharedData.upsertOsaStudentClearance({
        studentUserId: context.studentUserId,
        studentNumber: context.studentNumber,
        semesterId: context.semesterId,
        reason: manualReason,
        status: "cleared",
    });

    if (!response || response.success !== true) {
        throw new Error((response && response.error) || "Failed to save clearance.");
    }

    const saved = response.record || {};
    const savedReason = String(saved.reason || "").trim();
    return {
        alreadyLocked: Boolean(savedReason && savedReason !== manualReason),
    };
}

function handleManualClearFormSubmit(event) {
    event.preventDefault();
    if (!manualClearModalContext) return;

    const reasonInput = document.getElementById("manualClearReasonInput");
    const manualReason = String(reasonInput && reasonInput.value || "").trim();
    if (!manualReason) {
        setManualClearModalMessage("error", "Reason is required.");
        if (reasonInput) {
            reasonInput.focus();
        }
        return;
    }

    try {
        setManualClearModalBusy(true);
        const result = persistManualClearRequest(manualClearModalContext, manualReason);
        closeManualClearModal();
        refreshStatusAndAnalytics();

        if (result.alreadyLocked) {
            alert("Student is already marked as cleared. Existing clearance cannot be edited.");
            return;
        }
        alert("Student marked as cleared. This clearance is now locked.");
    } catch (error) {
        console.error("[OSA] Failed to save manual clearance:", error);
        setManualClearModalMessage("error", error && error.message ? error.message : "Failed to mark student as cleared.");
    } finally {
        setManualClearModalBusy(false);
    }
}

function handleProofReviewActionClick(event) {
    const button = event.target.closest(".status-action-btn");
    if (!button) return;

    const periodState = getEvaluationPeriodState();
    if (!periodState.isClosed) {
        alert("Proof review is only available after the Student-to-Professor evaluation period ends.");
        return;
    }

    const action = String(button.dataset.reviewAction || "").trim().toLowerCase();
    if (action !== "approve" && action !== "reject" && action !== "manual-clear") {
        return;
    }

    const proofId = String(button.dataset.proofId || "").trim();
    const studentUserId = String(button.dataset.studentUserId || "").trim();
    const studentNumber = String(button.dataset.studentNumber || "").trim();
    const studentName = String(button.dataset.studentName || "").trim();
    if (action !== "manual-clear" && !proofId && !studentUserId && !studentNumber) return;
    if (action === "manual-clear" && !studentUserId && !studentNumber) return;

    if (action === "manual-clear") {
        const manualSemesterId = String(button.dataset.semesterId || "").trim() || getActiveSemesterId();
        if (!manualSemesterId) {
            alert("Current semester is not configured.");
            return;
        }

        openManualClearModal({
            studentUserId: studentUserId,
            studentNumber: studentNumber,
            studentName: studentName,
            semesterId: manualSemesterId,
        });
        return;
    }

    let reviewNote = "";
    if (action === "reject") {
        const noteInput = prompt("Enter rejection note for the student proof request:");
        if (noteInput === null) return;
        reviewNote = String(noteInput || "").trim();
        if (!reviewNote) {
            alert("Rejection note is required.");
            return;
        }
    } else {
        const proceed = confirm("Approve this student's submitted proof request?");
        if (!proceed) return;
    }

    const semesterId = String(button.dataset.semesterId || "").trim() || getActiveSemesterId();
    if (!semesterId) {
        alert("Current semester is not configured.");
        return;
    }

    try {
        if (!SharedData.reviewStudentEvaluationProof) {
            throw new Error("Proof review persistence is unavailable.");
        }

        const response = SharedData.reviewStudentEvaluationProof({
            proofId: proofId,
            studentUserId: studentUserId,
            studentNumber: studentNumber,
            semesterId: semesterId,
            decision: action === "approve" ? "approved" : "rejected",
            reviewNote: reviewNote,
        });

        if (!response || response.success !== true) {
            throw new Error((response && response.error) || "Failed to review proof request.");
        }

        refreshStatusAndAnalytics();
        alert(action === "approve"
            ? "Proof approved and student marked as cleared."
            : "Proof rejected successfully.");
    } catch (error) {
        console.error("[OSA] Failed to review proof request:", error);
        alert(error && error.message ? error.message : "Failed to review proof request.");
    }
}

function setText(id, value) {
    const element = document.getElementById(id);
    if (element) {
        element.textContent = value;
    }
}

function capitalizeFirstLetter(text) {
    if (!text) return "";
    return text.charAt(0).toUpperCase() + text.slice(1);
}

function setupProfilePhotoUpload() {
    const input = document.getElementById("profilePhotoInput");
    const preview = document.getElementById("profilePhotoPreview");
    const placeholder = document.getElementById("profilePhotoPlaceholder");

    if (!input || !preview || !placeholder) return;

    const fullName = getProfileFullName();
    placeholder.textContent = buildInitials(fullName) || "OS";

    const storedPhoto = SharedData.getProfilePhoto('osa');
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
                SharedData.setProfilePhoto('osa', reader.result);
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
            const storedPhoto = SharedData.getProfilePhoto('osa');
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
    if (osaProfile && osaProfile.fullName) return osaProfile.fullName;
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

function getUserSession() {
    return SharedData.getSession();
}

function getProfileData() {
    const stored = SharedData.getProfileData('osa');
    if (stored) {
        if (!stored.passwordHash) {
            stored.passwordHash = simpleHash("OSA@12345");
        }
        if (!stored.fullName) {
            stored.fullName = "Office of Student Affairs";
        }
        if (!stored.email) {
            stored.email = "osa@naap.edu.ph";
        }
        SharedData.setProfileData('osa', stored);
        return stored;
    }

    const fallback = createDefaultProfile();
    saveProfileData(fallback);
    return fallback;
}

function saveProfileData(profile) {
    SharedData.setProfileData('osa', profile);
}

function createDefaultProfile() {
    return {
        fullName: "Office of Student Affairs",
        email: "osa@naap.edu.ph",
        passwordHash: simpleHash("OSA@12345")
    };
}

function simpleHash(value) {
    try {
        return btoa(unescape(encodeURIComponent(value)));
    } catch (e) {
        return btoa(value);
    }
}

function renderProfileDetails() {
    const emailDisplay = document.getElementById("profileEmail");
    const nameDisplay = document.getElementById("profileFullName");
    const usernameDisplay = document.getElementById("profileUsername");
    const currentEmailInput = document.getElementById("currentEmail");
    const session = getUserSession();

    if (nameDisplay && osaProfile) {
        nameDisplay.textContent = osaProfile.fullName;
    }

    if (emailDisplay && osaProfile) {
        emailDisplay.textContent = osaProfile.email;
    }

    if (usernameDisplay && session) {
        usernameDisplay.textContent = session.username || "osa";
    }

    if (currentEmailInput && osaProfile) {
        currentEmailInput.value = osaProfile.email;
    }
}

function setupProfileForms() {
    const gmailForm = document.getElementById("gmailForm");
    const gmailMessage = document.getElementById("gmailFormMessage");
    const newEmailInput = document.getElementById("newEmail");
    const confirmEmailInput = document.getElementById("confirmEmail");

    const passwordForm = document.getElementById("passwordForm");
    const passwordMessage = document.getElementById("passwordFormMessage");
    const currentPasswordInput = document.getElementById("currentPassword");
    const newPasswordInput = document.getElementById("newPassword");
    const confirmPasswordInput = document.getElementById("confirmPassword");
    const resetButtons = document.querySelectorAll("[data-reset-target]");

    resetButtons.forEach((btn) => {
        btn.addEventListener("click", function () {
            const formId = btn.getAttribute("data-reset-target");
            const form = document.getElementById(formId);
            if (form) form.reset();
            resetFormMessages();
            renderProfileDetails();
        });
    });

    if (gmailForm) {
        gmailForm.addEventListener("submit", function (e) {
            e.preventDefault();
            if (!osaProfile) osaProfile = getProfileData();

            const currentEmail = String((document.getElementById("currentEmail") || {}).value || "").trim().toLowerCase();
            const newEmail = (newEmailInput ? newEmailInput.value.trim() : "").toLowerCase();
            const confirmEmail = (confirmEmailInput ? confirmEmailInput.value.trim() : "").toLowerCase();

            if (!validateEmail(newEmail)) {
                showFormMessage(gmailMessage, "Please enter a valid Gmail address.", "error");
                return;
            }

            if (newEmail !== confirmEmail) {
                showFormMessage(gmailMessage, "New Gmail entries do not match.", "error");
                return;
            }

            if (!SharedData.changeOwnEmail) {
                showFormMessage(gmailMessage, "Email update service is unavailable.", "error");
                return;
            }

            try {
                const result = SharedData.changeOwnEmail(currentEmail, newEmail);
                const nextEmail = String(result && result.email || newEmail).trim().toLowerCase();
                osaProfile.email = nextEmail;
                saveProfileData(osaProfile);
                renderProfileDetails();
                showFormMessage(gmailMessage, "Gmail updated successfully.", "success");
            } catch (error) {
                console.error("[OSAPanel] Failed to update email.", error);
                showFormMessage(
                    gmailMessage,
                    error && error.message ? error.message : "Failed to update Gmail.",
                    "error"
                );
                return;
            }

            if (newEmailInput) newEmailInput.value = "";
            if (confirmEmailInput) confirmEmailInput.value = "";
        });
    }

    if (passwordForm) {
        passwordForm.addEventListener("submit", function (e) {
            e.preventDefault();

            const currentPassword = currentPasswordInput ? currentPasswordInput.value : "";
            const newPassword = newPasswordInput ? newPasswordInput.value : "";
            const confirmPassword = confirmPasswordInput ? confirmPasswordInput.value : "";

            if (!currentPassword) {
                showFormMessage(passwordMessage, "Enter your current password.", "error");
                return;
            }

            if (!validatePassword(newPassword)) {
                showFormMessage(
                    passwordMessage,
                    "Password must be at least 8 characters and include a number.",
                    "error"
                );
                return;
            }

            if (newPassword !== confirmPassword) {
                showFormMessage(passwordMessage, "New passwords do not match.", "error");
                return;
            }

            if (!SharedData.changeOwnPassword) {
                showFormMessage(passwordMessage, "Password update service is unavailable.", "error");
                return;
            }

            try {
                SharedData.changeOwnPassword(currentPassword, newPassword);
                showFormMessage(passwordMessage, "Password updated successfully.", "success");
            } catch (error) {
                console.error("[OSAPanel] Failed to update password.", error);
                showFormMessage(
                    passwordMessage,
                    error && error.message ? error.message : "Failed to update password.",
                    "error"
                );
                return;
            }

            if (currentPasswordInput) currentPasswordInput.value = "";
            if (newPasswordInput) newPasswordInput.value = "";
            if (confirmPasswordInput) confirmPasswordInput.value = "";
        });
    }
}

function setupProfileActionToggle() {
    const buttons = document.querySelectorAll(".toggle-btn");
    const cards = document.querySelectorAll(".action-card");

    if (!buttons.length || !cards.length) return;

    buttons.forEach((btn) => {
        btn.addEventListener("click", function () {
            const targetId = btn.dataset.target;
            if (!targetId) return;

            buttons.forEach((b) => b.classList.toggle("active", b === btn));
            cards.forEach((card) => {
                card.classList.toggle("active", card.id === targetId);
            });
        });
    });
}

function setupPasswordVisibility() {
    const toggles = document.querySelectorAll(".eye-toggle");
    toggles.forEach((toggle) => {
        toggle.addEventListener("click", function () {
            const targetId = toggle.dataset.target;
            if (!targetId) return;
            const input = document.getElementById(targetId);
            if (!input) return;

            const isPassword = input.type === "password";
            input.type = isPassword ? "text" : "password";
            toggle.innerHTML = `<i class="fas ${isPassword ? "fa-eye-slash" : "fa-eye"}"></i>`;
        });
    });
}

function validateEmail(email) {
    if (!email) return false;
    const pattern = /^[\w.+-]+@gmail\.com$/i;
    return pattern.test(email);
}

function validatePassword(password) {
    if (typeof password !== "string") return false;
    if (password.length < 8) return false;
    const hasNumber = /\d/.test(password);
    return hasNumber;
}

function showFormMessage(element, message, type) {
    if (!element) return;
    element.textContent = message;
    element.classList.remove("success", "error");
    if (type === "success") {
        element.classList.add("success");
    } else if (type === "error") {
        element.classList.add("error");
    }
}

function resetFormMessages() {
    const messages = document.querySelectorAll(".form-message");
    messages.forEach((msg) => {
        msg.textContent = "";
        msg.classList.remove("success", "error");
    });
}
