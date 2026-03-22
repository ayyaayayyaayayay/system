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
    setupProfilePhotoUpload();
    setupProfileForms();
    setupProfileActionToggle();
    setupPasswordVisibility();
});

/**
 * Build student evaluation data from centralized SharedData
 */
function getStudentEvaluationData() {
    const users = SharedData.getUsers();
    const students = users.filter(function(u) {
        return u.role === 'student';
    });
    return students.map(function(s) {
        return {
            studentNumber: s.studentNumber || '',
            fullName: s.name || '',
            program: (s.department || '').toUpperCase(),
            yearSection: s.yearSection || '',
            done: false
        };
    });
}

let allStudents = [];
let filteredStudents = [];
let osaProfile = null;

function checkAuthentication() {
    return SharedData.isAuthenticated() && SharedData.getRole() === "osa";
}

function redirectToLogin() {
    window.location.href = "mainpage.html";
}

function loadUserInfo() {
    const session = SharedData.getSession();
    if (!session) return;

    const profileName = document.getElementById("profileName");
    const profileNameDuplicate = document.getElementById("profileNameDuplicate");
    const displayName =
        (osaProfile && osaProfile.fullName) ||
        (session.username ? `${capitalizeFirstLetter(session.username)} OSA` : "OSA User");
    if (profileName) {
        profileName.textContent = displayName;
    }
    if (profileNameDuplicate) {
        profileNameDuplicate.textContent = displayName;
    }
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

async function initializeStatusMonitoring() {
    setupSearch();
    setupStatusActions();
    await loadStudentEvaluationStatus();
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

function setupStatusActions() {
    const tbody = document.getElementById("statusTableBody");
    if (!tbody) return;

    tbody.addEventListener("click", function (event) {
        const button = event.target.closest(".status-action-btn");
        if (!button) return;

        const studentNumber = button.dataset.studentNumber;
        if (!studentNumber) return;

        const student = allStudents.find((item) => item.studentNumber === studentNumber);
        if (!student) return;

        student.done = true;
        student.cleared = true;

        renderStatusTable(filteredStudents);
        updateSummaryCards(filteredStudents);
    });
}

async function loadStudentEvaluationStatus() {
    const session = getUserSession();
    const query = {
        requestedBy: session ? session.username : "",
        ay: "2025-2026",
        sem: "2"
    };

    try {
        const records = await fetchStudentsFromSql(query);
        allStudents = normalizeStudentRecords(records);
    } catch (error) {
        allStudents = getStudentEvaluationData();
        console.error("Failed to load SQL student records, using SharedData.", error);
    }

    filteredStudents = [...allStudents];
    renderStatusTable(filteredStudents);
    updateSummaryCards(filteredStudents);
}

function setupSearch() {
    const searchInput = document.getElementById("studentSearch");
    if (!searchInput) return;

    searchInput.addEventListener("input", function () {
        const keyword = searchInput.value.trim().toLowerCase();
        filteredStudents = allStudents.filter((student) => {
            return (
                student.fullName.toLowerCase().includes(keyword) ||
                student.studentNumber.toLowerCase().includes(keyword)
            );
        });

        renderStatusTable(filteredStudents);
        updateSummaryCards(filteredStudents);
    });
}

function fetchStudentsFromSql(query) {
    // SQL integration placeholder:
    // return fetch("/api/osa/student-evaluation-status", {
    //     method: "POST",
    //     headers: { "Content-Type": "application/json" },
    //     body: JSON.stringify(query)
    // }).then((response) => response.json());
    //
    // Expected SQL/API response fields:
    // Student Number, Full Name, Program, Year/Section, Status
    // Example row:
    // {
    //   studentNumber: "2022-04519",
    //   fullName: "John Michael Santos",
    //   program: "BSIT",
    //   yearSection: "3A",
    //   status: "Done"
    // }

    return Promise.resolve(getStudentEvaluationData());
}

function normalizeStudentRecords(records) {
    if (!Array.isArray(records)) return [];

    return records.map((record) => {
        const normalizedStatus = String(record.status || "").trim().toLowerCase();
        const clearedFromStatus = normalizedStatus === "cleared";
        const doneFromStatus =
            normalizedStatus === "done" ||
            normalizedStatus === "completed" ||
            normalizedStatus === "true" ||
            normalizedStatus === "1" ||
            clearedFromStatus;

        return {
            studentNumber: String(record.studentNumber || "").trim(),
            fullName: String(record.fullName || "").trim(),
            program: String(record.program || "").trim(),
            yearSection: String(record.yearSection || "").trim(),
            done: typeof record.done === "boolean" ? record.done : doneFromStatus,
            cleared: clearedFromStatus
        };
    });
}

function renderStatusTable(students) {
    const tbody = document.getElementById("statusTableBody");
    const emptyState = document.getElementById("emptyState");
    if (!tbody || !emptyState) return;

    if (!students.length) {
        tbody.innerHTML = "";
        emptyState.style.display = "block";
        return;
    }

    emptyState.style.display = "none";
    tbody.innerHTML = students
        .map((student) => {
            const isCleared = student.cleared === true;
            const statusClass = student.done ? "done" : "not-done";
            const statusText = student.done ? (isCleared ? "Cleared" : "Done") : "Not Done";
            const icon = student.done ? "fa-circle-check" : "fa-circle-xmark";
            const actionButton = student.done
                ? ""
                : `<button type="button" class="status-action-btn" data-student-number="${student.studentNumber}">Mark Cleared</button>`;

            return `
                <tr>
                    <td>${student.studentNumber}</td>
                    <td>${student.fullName}</td>
                    <td>${student.program}</td>
                    <td>${student.yearSection}</td>
                    <td>
                        <div class="status-cell">
                            <span class="status-pill ${statusClass}">
                                <i class="fas ${icon}"></i>
                                ${statusText}
                            </span>
                            ${actionButton}
                        </div>
                    </td>
                </tr>
            `;
        })
        .join("");
}

function updateSummaryCards(students) {
    const total = students.length;
    const done = students.filter((student) => student.done).length;
    const notDone = total - done;

    setText("totalCount", total);
    setText("doneCount", done);
    setText("notDoneCount", notDone);
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
