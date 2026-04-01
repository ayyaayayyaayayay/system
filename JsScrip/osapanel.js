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
    initializeStatusMonitoring();
    setupDataSubscriptions();
    setupProfilePhotoUpload();
    setupProfileForms();
    setupProfileActionToggle();
    setupPasswordVisibility();
});

let allStudents = [];
let filteredStudents = [];
let osaProfile = null;
let currentSearchKeyword = "";
let latestAnalyticsSnapshot = null;
let selectedAnalyticsDepartment = "";

function checkAuthentication() {
    return SharedData.isAuthenticated() && SharedData.getRole() === "osa";
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

    ["profileName", "profileNameDuplicate", "profileNameStatus"].forEach(function (id) {
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
            key === SharedData.KEYS.OSA_STUDENT_CLEARANCES
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
        });
    });
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
    const now = new Date();

    if (!endRaw) {
        return {
            isClosed: false,
            hasEndDate: false,
            note: "Mark Cleared is unavailable because the Student-to-Professor period end date is not configured.",
        };
    }

    const endDate = new Date(`${endRaw}T23:59:59`);
    const isClosed = !Number.isNaN(endDate.getTime()) && now > endDate;

    return {
        isClosed: isClosed,
        hasEndDate: true,
        note: isClosed
            ? `Evaluation period ended on ${endRaw}. Mark Cleared is enabled for non-completed students with valid reasons.`
            : `Mark Cleared becomes available after the evaluation period ends on ${endRaw}.`,
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

    const rows = [];
    expectedByStudent.forEach(function (expectedSet, studentUserId) {
        const meta = studentMetaById.get(studentUserId) || directoryByUserId.get(studentUserId) || {
            studentUserId,
            studentNumber: "",
            fullName: "Unknown Student",
            department: "UNASSIGNED",
            program: "UNASSIGNED",
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
        rows.push({
            studentUserId,
            studentNumber: meta.studentNumber || "",
            fullName: meta.fullName || "Unknown Student",
            department: meta.department || "UNASSIGNED",
            program: meta.program || "UNASSIGNED",
            yearSection: meta.yearSection || "N/A",
            expectedCount,
            completedCount,
            evaluated,
            cleared,
            clearanceReason: cleared ? String(clearance.reason || "").trim() : "",
            clearanceNotedAt: cleared ? String(clearance.notedAt || "").trim() : "",
            canMarkCleared: !evaluated && periodState.isClosed,
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

function refreshStatusAndAnalytics() {
    const snapshot = buildStatusRows();
    allStudents = snapshot.rows;
    filteredStudents = applySearchFilter(allStudents, currentSearchKeyword);

    renderStatusPeriodNote(snapshot.periodState);
    renderStatusTable(filteredStudents, snapshot.periodState);
    renderDashboardAnalytics(snapshot);
}

function renderStatusPeriodNote(periodState) {
    const noteEl = document.getElementById("statusPeriodNote");
    if (!noteEl) return;
    noteEl.textContent = periodState && periodState.note ? periodState.note : "";
}

function renderDashboardAnalytics(snapshot) {
    latestAnalyticsSnapshot = snapshot;
    const summary = snapshot.summary || { assigned: 0, evaluated: 0, notEvaluated: 0, completionRate: 0 };
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

    if (!snapshot.rows.length) {
        deptBody.innerHTML = "";
        progBody.innerHTML = "";
        selectedAnalyticsDepartment = "";
        progTitle.textContent = "Programs by Department";
        progEmpty.textContent = "Select a department above to view its programs.";
        progEmpty.style.display = "block";
        emptyEl.style.display = "block";
        return;
    }

    emptyEl.style.display = "none";
    const hasSelectedDepartment = snapshot.departmentBreakdown.some(function (item) {
        return item.department === selectedAnalyticsDepartment;
    });
    if (!hasSelectedDepartment) {
        selectedAnalyticsDepartment = "";
    }

    deptBody.innerHTML = snapshot.departmentBreakdown.map(function (item) {
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

    const filteredPrograms = snapshot.programBreakdown.filter(function (item) {
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
    const parsed = new Date(raw);
    if (Number.isNaN(parsed.getTime())) return raw;
    return parsed.toLocaleString();
}

function renderStatusTable(students, periodState) {
    const tbody = document.getElementById("statusTableBody");
    const actionHeader = document.getElementById("statusActionHeader");
    const emptyState = document.getElementById("emptyState");
    if (!tbody || !emptyState) return;

    const showActionColumn = Boolean(periodState && periodState.isClosed);
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
        const statusClass = student.evaluated ? "done" : (student.cleared ? "cleared" : "not-done");
        const statusText = student.evaluated ? "Done" : (student.cleared ? "Cleared" : "Not Done");
        const icon = student.evaluated ? "fa-circle-check" : (student.cleared ? "fa-file-circle-check" : "fa-circle-xmark");
        const progressText = `${student.completedCount}/${student.expectedCount}`;

        const reasonBlock = student.cleared && student.clearanceReason
            ? `<div class="status-reason">Reason: ${escapeHtml(student.clearanceReason)}${student.clearanceNotedAt ? ` (${escapeHtml(formatNotedAt(student.clearanceNotedAt))})` : ""}</div>`
            : "";

        let actionCell = "";
        if (showActionColumn) {
            let actionHtml = "";
            if (student.evaluated) {
                actionHtml = `<span class="status-progress">Completed</span>`;
            } else if (student.canMarkCleared) {
                const actionLabel = student.cleared ? "Update Reason" : "Mark Cleared";
                actionHtml = `<button type="button" class="status-action-btn" data-student-user-id="${escapeHtml(student.studentUserId)}" data-student-number="${escapeHtml(student.studentNumber)}">${actionLabel}</button>`;
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
                        ${reasonBlock}
                    </div>
                </td>
                ${actionCell}
            </tr>
        `;
    }).join("");
}

function setupAnalyticsInteractions() {
    const deptBody = document.getElementById("departmentAnalyticsBody");
    if (!deptBody) return;

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

    deptBody.addEventListener("click", selectDepartmentFromEvent);
    deptBody.addEventListener("keydown", function (event) {
        if (event.key !== "Enter" && event.key !== " ") return;
        event.preventDefault();
        selectDepartmentFromEvent(event);
    });
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
    const tbody = document.getElementById("statusTableBody");
    if (!tbody) return;

    tbody.addEventListener("click", function (event) {
        const button = event.target.closest(".status-action-btn");
        if (!button) return;

        const periodState = getEvaluationPeriodState();
        if (!periodState.isClosed) {
            alert("Mark Cleared is only available after the Student-to-Professor evaluation period ends.");
            return;
        }

        const studentUserId = String(button.dataset.studentUserId || "").trim();
        const studentNumber = String(button.dataset.studentNumber || "").trim();
        if (!studentUserId && !studentNumber) return;

        const reasonInput = prompt("Enter the student's reason for not completing the evaluation:");
        if (reasonInput === null) return;
        const reason = String(reasonInput || "").trim();
        if (!reason) {
            alert("Reason is required.");
            return;
        }

        const semesterId = getActiveSemesterId();
        if (!semesterId) {
            alert("Current semester is not configured.");
            return;
        }

        const session = getUserSession();
        const notedBy = (session && (session.fullName || session.username)) || "OSA";

        try {
            if (!SharedData.upsertOsaStudentClearance) {
                throw new Error("Clearance persistence is unavailable.");
            }

            const response = SharedData.upsertOsaStudentClearance({
                studentUserId: studentUserId,
                studentNumber: studentNumber,
                semesterId: semesterId,
                reason: reason,
                notedAt: new Date().toISOString(),
                notedBy: notedBy,
                status: "cleared",
            });

            if (!response || response.success !== true) {
                throw new Error((response && response.error) || "Failed to save clearance.");
            }

            refreshStatusAndAnalytics();
            alert("Student marked as cleared with reason.");
        } catch (error) {
            console.error("[OSA] Failed to save clearance:", error);
            alert(error && error.message ? error.message : "Failed to mark student as cleared.");
        }
    });
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
            SharedData.setProfilePhoto('osa', reader.result);
        };
        reader.readAsDataURL(file);
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

            osaProfile.email = newEmail;
            saveProfileData(osaProfile);
            renderProfileDetails();
            showFormMessage(gmailMessage, "Gmail updated locally.", "success");

            if (newEmailInput) newEmailInput.value = "";
            if (confirmEmailInput) confirmEmailInput.value = "";
            resetFormMessages();
        });
    }

    if (passwordForm) {
        passwordForm.addEventListener("submit", function (e) {
            e.preventDefault();
            if (!osaProfile) osaProfile = getProfileData();

            const currentPassword = currentPasswordInput ? currentPasswordInput.value : "";
            const newPassword = newPasswordInput ? newPasswordInput.value : "";
            const confirmPassword = confirmPasswordInput ? confirmPasswordInput.value : "";

            if (!currentPassword) {
                showFormMessage(passwordMessage, "Enter your current password.", "error");
                return;
            }

            if (simpleHash(currentPassword) !== osaProfile.passwordHash) {
                showFormMessage(passwordMessage, "Current password is incorrect.", "error");
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

            osaProfile.passwordHash = simpleHash(newPassword);
            saveProfileData(osaProfile);
            showFormMessage(passwordMessage, "Password updated locally.", "success");

            if (currentPasswordInput) currentPasswordInput.value = "";
            if (newPasswordInput) newPasswordInput.value = "";
            if (confirmPasswordInput) confirmPasswordInput.value = "";
            resetFormMessages();
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
