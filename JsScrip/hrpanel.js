// Admin Panel JavaScript - Dashboard Functionality

// Wait for DOM to be fully loaded
document.addEventListener('DOMContentLoaded', function () {
    // Check authentication
    if (!checkAuthentication()) {
        redirectToLogin();
        return;
    }

    // Initialize the dashboard
    initializeDashboard();
});

/**
 * Check if user is authenticated and is an admin
 * @returns {boolean} - True if user is authenticated as admin
 */
function checkAuthentication() {
    const session = SharedData.getSession();
    if (!session) {
        return false;
    }

    try {
        // Check if user is authenticated and is an admin
        return session.isAuthenticated === true && (session.role === 'admin' || session.role === 'hr');
    } catch (e) {
        return false;
    }
}

/**
 * Redirect to login page if not authenticated
 */
function redirectToLogin() {
    window.location.href = 'mainpage.html';
}

/**
 * Initialize the admin dashboard
 */
function initializeDashboard() {
    // Ensure only dashboard view is visible on load
    hideAllViews();
    const dashboardView = document.getElementById('dashboard-view');
    if (dashboardView) {
        dashboardView.style.display = 'block';
    }

    loadUserInfo();
    setupNavigation();
    setupLogout();
    setupNotifications();
    setupProfilePhotoUpload();
    setupProfileActions();
    setupSemesterSettings();
    setupEvalPeriods();
    setupProfessorManagement();
    setupProfessorRanking();
    renderProfessorDepartmentOptions();
    renderProfessorDepartmentTabs();
    setupQuestionnaire();

    // Cross-tab sync: auto-refresh questionnaire when another panel saves changes
    // (only fires for OTHER tabs, not this one — avoids redundant re-renders)
    window.addEventListener('storage', (e) => {
        if (e.key === SharedData.KEYS.QUESTIONNAIRES || e.key === SharedData.KEYS.CURRENT_SEMESTER || e.key === SharedData.KEYS.SEMESTER_LIST) {
            loadQuestionsData();
            setupSemesterPicker();
            updateFormHeader(currentQuestionnaireType);
            renderQuestions();
            applyQuestionnaireEditMode(isQuestionnaireEditable());
        }
        if (e.key === SharedData.KEYS.EVAL_PERIODS) {
            // Reload eval period dates from SharedData
            const periods = SharedData.getEvalPeriods();
            ['student-professor', 'professor-professor', 'supervisor-professor'].forEach(type => {
                const startEl = document.getElementById(type + '-start');
                const endEl = document.getElementById(type + '-end');
                if (startEl && periods[type]) startEl.value = periods[type].start || '';
                if (endEl && periods[type]) endEl.value = periods[type].end || '';
            });
        }
    });
    updateOverviewCards();
    loadReports();
    setupChangePasswordForm();
    setupPasswordToggles();
}

/**
 * Dynamically populated department select options inside Add/Edit Professor modals.
 */
function renderProfessorDepartmentOptions() {
    const departments = SharedData.getAllDepartments();
    const selectIds = ['professor-department', 'ranking-dept-filter'];

    selectIds.forEach(id => {
        const selectElement = document.getElementById(id);
        if (selectElement) {
            // Check if it's the ranking filter which needs an "All Departments" default
            const isFilter = id === 'ranking-dept-filter';
            selectElement.innerHTML = isFilter
                ? '<option value="all">All Departments</option>'
                : '<option value="">Select Department</option>';

            departments.forEach(dept => {
                const opt = document.createElement('option');
                opt.value = dept;
                opt.textContent = dept;
                selectElement.appendChild(opt);
            });
        }
    });
}

/**
 * Dynamically populated filtering tabs for Professor Management.
 */
function renderProfessorDepartmentTabs() {
    const tabsContainer = document.querySelector('.department-tabs');
    if (!tabsContainer) return;

    // Always preserve "all" tab
    tabsContainer.innerHTML = `
        <button class="dept-tab active" data-department="all">
            <i class="fas fa-users"></i>
            All Departments
        </button>
    `;

    const departments = SharedData.getAllDepartments();

    const icons = {
        'ICS': 'fa-laptop-code',
        'ILAS': 'fa-language',
        'ENGI': 'fa-tools',
        'DEFAULT': 'fa-building'
    };

    departments.forEach(dept => {
        const iconClass = icons[dept] || icons['DEFAULT'];
        const btn = document.createElement('button');
        btn.className = 'dept-tab';
        btn.setAttribute('data-department', dept);
        btn.innerHTML = `<i class="fas ${iconClass}"></i> ${dept}`;
        tabsContainer.appendChild(btn);
    });

    // Re-bind listeners for newly generated tabs
    const newTabs = tabsContainer.querySelectorAll('.dept-tab');
    newTabs.forEach(tab => {
        tab.addEventListener('click', (e) => {
            newTabs.forEach(t => t.classList.remove('active'));
            e.currentTarget.classList.add('active');

            currentDepartmentFilter = e.currentTarget.getAttribute('data-department');
            // Calling renderProfessors to refresh the list
            renderProfessors();
        });
    });
}

/**
 * Load and display user information
 */
function loadUserInfo() {
    const session = SharedData.getSession();
    if (session) {
        try {
            const username = session.username;

            // Update user profile name
            const userProfileSpan = document.querySelector('.user-profile span');
            if (userProfileSpan) {
                // Format username: capitalize first letter
                const formattedName = username.charAt(0).toUpperCase() + username.slice(1) + ' User';
                userProfileSpan.textContent = formattedName;
            }
        } catch (e) {
            console.error('Error loading user info:', e);
        }
    }
}

/**
 * Setup navigation links
 */
function setupNavigation() {
    const navLinks = document.querySelectorAll('.nav-link:not(.logout)');

    navLinks.forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault();

            // Remove active class from all links
            navLinks.forEach(l => l.classList.remove('active'));

            // Add active class to clicked link
            this.classList.add('active');

            // Handle navigation (for future implementation)
            const linkText = this.querySelector('span').textContent.trim();
            handleNavigation(linkText);
        });
    });
}

/**
 * Hide all content views
 */
function hideAllViews() {
    const allViews = document.querySelectorAll('.content-view');
    allViews.forEach(view => {
        if (view) {
            view.style.display = 'none';
        }
    });
}

/**
 * Handle navigation to different sections
 * @param {string} section - Section name
 */
function handleNavigation(section) {
    // Hide all views first to ensure only one is visible
    hideAllViews();

    const dashboardView = document.getElementById('dashboard-view');
    const userManagementView = document.getElementById('user-management-view');
    const settingsView = document.getElementById('settings-view');
    const reportsView = document.getElementById('reports-view');
    const pageTitle = document.getElementById('mainPageTitle');

    if (pageTitle) {
        pageTitle.textContent = section;
    }

    switch (section) {
        case 'Dashboard':
            if (dashboardView) {
                dashboardView.style.display = 'block';
                loadReports();
            }
            break;
        case 'User Management':
            if (userManagementView) {
                userManagementView.style.display = 'block';
                loadUserManagement();
            }
            break;
        case 'Activity Log':
            const activityLogView = document.getElementById('activity-log-view');
            if (activityLogView) {
                activityLogView.style.display = 'block';
                loadHrActivityLog();
            }
            break;
        case 'Evaluation Settings':
        case 'System Settings':
            if (settingsView) {
                settingsView.style.display = 'block';
            }
            break;
        case 'Reports & Analytics':
        case 'Reports':
            if (reportsView) {
                reportsView.style.display = 'block';
                loadReports();
            }
            break;
        case 'Profile':
            const profileView = document.getElementById('profile-view');
            if (profileView) {
                profileView.style.display = 'block';
            }
            break;
        case 'Questionnaire':
            const questionnaireView = document.getElementById('questionnaire-view');
            if (questionnaireView) {
                questionnaireView.style.display = 'block';
                loadQuestionnaire();
            }
            break;
        case 'Change Password':
            const changePasswordView = document.getElementById('change-password-view');
            if (changePasswordView) {
                changePasswordView.style.display = 'block';
            }
            break;
        default:
            // If no match, show dashboard as default
            if (dashboardView) {
                dashboardView.style.display = 'block';
            }
            if (pageTitle) {
                pageTitle.textContent = 'HR Dashboard';
            }
            break;
    }
}

/**
 * Load HR activity log (mock data with filters)
 */
function loadHrActivityLog() {
    const tbody = document.getElementById('hr-activity-log-body');
    if (!tbody) return;

    const activityRows = [
        { ip_address: '223.31.69.69', timestamp: '2026-02-06 08:32', description: 'Authentication by cached UID', action: 'Login', role: 'admin', user_id: 'admin', log_id: 'LOG-0001', type: 'login' },
        { ip_address: '223.31.69.70', timestamp: '2026-02-06 08:10', description: 'Update AdoptOpenJDK JRE', action: 'System Update', role: 'system', user_id: 'system', log_id: 'LOG-0002', type: 'system' },
        { ip_address: '223.31.69.69', timestamp: '2026-02-06 07:58', description: 'HR staff logged in', action: 'Login', role: 'hr', user_id: 'hr_staff', log_id: 'LOG-0003', type: 'login' },
        { ip_address: '223.31.69.60', timestamp: '2026-02-06 07:22', description: 'Students completed evaluations', action: 'Evaluation Completed', role: 'student', user_id: 'student_2024_102', log_id: 'LOG-0004', type: 'evaluation' },
        { ip_address: '223.31.69.69', timestamp: '2026-02-06 06:55', description: 'Created user prof_garcia', action: 'User Account Created', role: 'admin', user_id: 'admin_ops', log_id: 'LOG-0005', type: 'user' }
    ];

    const searchBtn = document.getElementById('hr-activity-search-btn');
    const typeSelect = document.getElementById('hr-activity-type');
    const searchInput = document.getElementById('hr-activity-search');

    const renderRows = (rows) => {
        tbody.innerHTML = rows.map(row => `
            <tr>
                <td>${row.ip_address}</td>
                <td>${row.timestamp}</td>
                <td>${row.description}</td>
                <td>${row.action}</td>
                <td>${row.role}</td>
                <td>${row.user_id}</td>
                <td>${row.log_id}</td>
            </tr>
        `).join('');
    };

    renderRows(activityRows);

    if (searchBtn && typeSelect && searchInput) {
        const handleSearch = () => {
            const typeValue = typeSelect.value;
            const term = searchInput.value.trim().toLowerCase();
            const filtered = activityRows.filter(row => {
                const typeMatch = typeValue === 'all' || row.type === typeValue;
                const text = `${row.ip_address} ${row.timestamp} ${row.description} ${row.action} ${row.role} ${row.user_id} ${row.log_id}`.toLowerCase();
                const searchMatch = term ? text.includes(term) : true;
                return typeMatch && searchMatch;
            });
            renderRows(filtered);
        };

        searchBtn.onclick = handleSearch;
        typeSelect.onchange = handleSearch;
        searchInput.oninput = handleSearch;
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
 * Setup notification dropdown functionality
 */
function setupNotifications() {
    const icon = document.getElementById('notification-icon');
    const dropdown = document.getElementById('notification-dropdown');
    const list = document.getElementById('notification-list');
    const badge = document.getElementById('notification-badge');
    const wrapper = icon ? icon.parentElement : null;

    if (!icon || !dropdown || !list) {
        return;
    }

    const notifications = [
        { title: 'Evaluation Started', meta: 'Student to Professor - Just now' },
        { title: 'Evaluation Window Open', meta: 'Peer Evaluation - Today' },
        { title: 'New Review Period', meta: 'Supervisor Evaluation - Today' }
    ];

    if (badge) {
        badge.textContent = notifications.length;
    }

    list.innerHTML = notifications.map(item => `
        <div class="notification-item">
            <div class="notification-item-title">${item.title}</div>
            <div class="notification-item-meta">${item.meta}</div>
        </div>
    `).join('');

    icon.addEventListener('click', function (e) {
        e.stopPropagation();
        dropdown.classList.toggle('show');
        dropdown.setAttribute('aria-hidden', dropdown.classList.contains('show') ? 'false' : 'true');
    });

    document.addEventListener('click', function (e) {
        if (!dropdown.classList.contains('show')) return;
        if (wrapper && wrapper.contains(e.target)) return;
        dropdown.classList.remove('show');
        dropdown.setAttribute('aria-hidden', 'true');
    });
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
    placeholder.textContent = buildInitials(fullName) || 'HR';

    const storedPhoto = SharedData.getProfilePhoto('hr');
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
            SharedData.setProfilePhoto('hr', reader.result);
        };
        reader.readAsDataURL(file);
    });
}

function getProfileFullName() {
    const items = document.querySelectorAll('#profile-view .profile-item');
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
 * Placeholder change email handler (SQL-ready)
 */
function handleChangeEmail() {
    const currentEmail = document.getElementById('currentEmail').value.trim();
    const newEmail = document.getElementById('newEmail').value.trim();
    const confirmEmail = document.getElementById('confirmEmail').value.trim();

    if (!newEmail || !confirmEmail) {
        alert('Please fill out all email fields.');
        return;
    }

    if (newEmail !== confirmEmail) {
        alert('New email and confirmation do not match.');
        return;
    }

    if (currentEmail && newEmail.toLowerCase() === currentEmail.toLowerCase()) {
        alert('New email must be different from the current email.');
        return;
    }

    const payload = {
        username: getUserSession() ? getUserSession().username : '',
        currentEmail,
        newEmail
    };

    console.log('Ready for SQL integration: /api/hr/change-email', payload);
    alert('Email update request ready for SQL connection.');

    const profileEmail = document.getElementById('profileEmail');
    if (profileEmail) profileEmail.textContent = newEmail;
    const currentEmailInput = document.getElementById('currentEmail');
    if (currentEmailInput) {
        currentEmailInput.value = newEmail;
        currentEmailInput.defaultValue = newEmail;
    }

    const form = document.getElementById('changeEmailForm');
    if (form) form.reset();
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
        alert('Please fill out all password fields.');
        return;
    }

    if (newPassword !== confirmPassword) {
        alert('New password and confirmation do not match.');
        return;
    }

    const payload = {
        username: getUserSession() ? getUserSession().username : '',
        currentPassword,
        newPassword
    };

    console.log('Ready for SQL integration: /api/hr/change-password', payload);
    alert('Password update request ready for SQL connection.');

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
 * Update navigation active state
 * @param {string} viewName - Name of the active view
 */
function updateNavigation(viewName) {
    const navLinks = document.querySelectorAll('.nav-link:not(.logout)');
    navLinks.forEach(link => {
        link.classList.remove('active');
        const text = link.querySelector('span') ? link.querySelector('span').textContent.trim() : '';
        if (text === viewName) {
            link.classList.add('active');
        }
    });
}
/**
 * Handle add user action
 */
function handleAddUser() {
    // Placeholder for future add user functionality
    console.log('Opening add user form...');

    // For now, show an alert
    alert('Add User feature will be implemented soon!\n\nThis will open a modal or form to add a new user.');

    // Future: Open modal or redirect to add user page
    // openAddUserModal();
}

/**
 * Handle edit user action
 * @param {string} userName - User's name
 */
function handleEditUser(userName) {
    // Placeholder for future edit user functionality
    console.log(`Editing user: ${userName}`);

    // For now, show an alert
    alert(`Edit User: ${userName}\n\nThis feature will be implemented soon!`);

    // Future: Open edit modal or redirect to edit page
    // openEditUserModal(userName);
}

/**
 * Handle delete user action
 * @param {string} userName - User's name
 * @param {HTMLElement} userItem - User item element
 */
function handleDeleteUser(userName, userItem) {
    // Confirm deletion
    if (confirm(`Are you sure you want to delete user "${userName}"?\n\nThis action cannot be undone.`)) {
        // Placeholder for actual deletion logic
        console.log(`Deleting user: ${userName}`);

        // For now, just remove from DOM (in real app, this would be an API call)
        userItem.style.animation = 'fadeOut 0.3s ease';
        setTimeout(() => {
            userItem.remove();
            updateOverviewCards(); // Update stats after deletion
        }, 300);

        // Future: API call to delete user
        // deleteUserAPI(userName).then(() => {
        //     userItem.remove();
        //     updateOverviewCards();
        // });
    }
}

/**
 * Setup system settings functionality
 */
function setupSystemSettings() {
    const settingButtons = document.querySelectorAll('.btn-setting');

    settingButtons.forEach(button => {
        button.addEventListener('click', function () {
            const settingItem = this.closest('.setting-item');
            if (!settingItem) {
                return;
            }
            const settingTitle = settingItem.querySelector('h3').textContent;
            handleSettingAction(settingTitle);
        });
    });
}

/**
 * Handle system setting action
 * @param {string} settingTitle - Setting title
 */
function handleSettingAction(settingTitle) {
    // Placeholder for future settings functionality
    console.log(`Opening setting: ${settingTitle}`);

    // For now, show an alert
    alert(`${settingTitle}\n\nThis feature will be implemented soon!`);

    // Future: Open settings modal or redirect to settings page
    // openSettingsModal(settingTitle);
}

/**
 * Update overview cards with dynamic data
 */
function updateOverviewCards() {
    const profs = professorsData || [];
    const totalStudents = profs.reduce((sum, professor) => sum + (Number(professor.totalStudents) || 0), 0);

    const completedEvaluations = profs.reduce((sum, professor) => sum + (Number(professor.evaluatedCount || professor.evaluationsCount) || 0), 0);

    const pendingEvaluations = Math.max(totalStudents - completedEvaluations, 0);
    const completionRate = totalStudents > 0
        ? `${((completedEvaluations / totalStudents) * 100).toFixed(1)}%`
        : '0%';

    const activeProfessors = profs.filter(professor => professor.isActive !== false).length;

    const studentsCard = document.querySelector('.overview-card.users .card-number');
    const completionCard = document.querySelector('.overview-card.evaluations .card-number');
    const completedCard = document.querySelector('.overview-card.completed .card-number');
    const pendingCard = document.querySelector('.overview-card.professors .card-number');
    const activeProfessorsCard = document.querySelector('.overview-card.status .card-number');

    if (studentsCard) studentsCard.textContent = totalStudents;
    if (completionCard) completionCard.textContent = completionRate;
    if (completedCard) completedCard.textContent = completedEvaluations;
    if (pendingCard) pendingCard.textContent = pendingEvaluations;
    if (activeProfessorsCard) activeProfessorsCard.textContent = activeProfessors;
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

/**
 * Refresh user list (for future use)
 */
function refreshUserList() {
    // Placeholder for future API call to refresh users
    console.log('Refreshing user list...');

    // Future: Fetch from API and update DOM
    // fetchUsers().then(users => {
    //     renderUsers(users);
    //     updateOverviewCards();
    // });
}

/**
 * Professor Management System
 * Professors are stored in sharedUsersData with role='professor'
 */

// Working cache — loaded from SharedData.getUsers() filtered by role='professor'
let professorsData = [];

/**
 * Load professors from centralized sharedUsersData
 */
function getProfessorsFromSharedData() {
    return SharedData.getUsers().filter(function (u) {
        return u.role === 'professor';
    });
}

/**
 * Save professors back to centralized sharedUsersData
 * Merges professor records with non-professor users
 */
function saveProfessorsToSharedData() {
    const allUsers = SharedData.getUsers();
    // Remove all existing professor users
    const nonProfessors = allUsers.filter(function (u) {
        return u.role !== 'professor';
    });
    // Add current professorsData with role marker
    const professorsWithRole = professorsData.map(function (p) {
        return Object.assign({}, p, { role: 'professor', status: p.isActive !== false ? 'active' : 'inactive' });
    });
    SharedData.setUsers(nonProfessors.concat(professorsWithRole));
}
let currentEditingProfessorId = null;
let currentDepartmentFilter = 'all';
let rankingDepartmentFilter = 'all';
let rankingEmploymentFilter = 'all';
let currentAnalyticsSemester = 'all';
let currentAnalyticsEvaluationType = 'student';

const SEMESTER_OPTIONS = [
    { id: 'all', label: 'All Semesters' },
    { id: 'sem1', label: '1st Semester' },
    { id: 'sem2', label: '2nd Semester' },
    { id: 'summer', label: 'Summer Term' }
];

const EVALUATION_TYPE_OPTIONS = [
    {
        id: 'student',
        label: 'Student Evaluation',
        unitLabel: 'Students',
        totalLabel: 'Total Students',
        statusTitle: 'Student Evaluation Status',
        icon: 'fas fa-user-graduate',
        feedbackIcon: 'fas fa-user-graduate'
    },
    {
        id: 'peer',
        label: 'Peer Evaluation',
        unitLabel: 'Peers',
        totalLabel: 'Total Peers',
        statusTitle: 'Peer Evaluation Status',
        icon: 'fas fa-users',
        feedbackIcon: 'fas fa-users'
    },
    {
        id: 'supervisor',
        label: 'Supervisor Evaluation',
        unitLabel: 'Supervisors',
        totalLabel: 'Total Supervisors',
        statusTitle: 'Supervisor Evaluation Status',
        icon: 'fas fa-user-tie',
        feedbackIcon: 'fas fa-user-tie'
    }
];

function getSemesterLabel(id) {
    const option = SEMESTER_OPTIONS.find(item => item.id === id);
    return option ? option.label : 'Semester';
}

function getSemesterOptions() {
    return SEMESTER_OPTIONS;
}

function getEvaluationTypeOptions() {
    return EVALUATION_TYPE_OPTIONS;
}

function getEvaluationTypeMeta(id) {
    return EVALUATION_TYPE_OPTIONS.find(item => item.id === id) || EVALUATION_TYPE_OPTIONS[0];
}

function clampNumber(value, min, max) {
    return Math.min(Math.max(value, min), max);
}


function normalizeResponse(response, semesterId) {
    const normalized = { ...response };
    if (!normalized.id) normalized.id = Date.now() + '_' + Math.floor(Math.random() * 10000);
    if (!normalized.text) normalized.text = '';
    if (!normalized.date) normalized.date = new Date().toLocaleDateString();
    if (!normalized.studentName) normalized.studentName = 'Anonymous';
    if (!normalized.studentNumber) normalized.studentNumber = 'N/A';
    if (!normalized.semesterId) normalized.semesterId = semesterId || 'all';
    return normalized;
}


function combineSemesterData(semesterData) {
    const semesters = Object.values(semesterData || {});
    let totalStudents = 0;
    let evaluatedCount = 0;
    let weightedRating = 0;
    let ratingWeight = 0;
    let qualitativeResponses = [];

    semesters.forEach(data => {
        const total = Number(data.totalStudents) || 0;
        const evaluated = Number(data.evaluatedCount) || 0;
        const avgRating = parseFloat(data.averageRating) || 0;
        const weight = evaluated || total;

        totalStudents += total;
        evaluatedCount += evaluated;
        weightedRating += avgRating * weight;
        ratingWeight += weight;

        if (Array.isArray(data.qualitativeResponses)) {
            qualitativeResponses = qualitativeResponses.concat(data.qualitativeResponses);
        }
    });

    const notEvaluatedCount = Math.max(totalStudents - evaluatedCount, 0);
    const averageRating = ratingWeight > 0 ? parseFloat((weightedRating / ratingWeight).toFixed(1)) : 0;

    return {
        totalStudents: totalStudents,
        evaluatedCount: evaluatedCount,
        notEvaluatedCount: notEvaluatedCount,
        averageRating: averageRating,
        qualitativeResponses: qualitativeResponses
    };
}

function ensureProfessorSemesterData(professor) {
    const semesterIds = getSemesterOptions().filter(option => option.id !== 'all').map(option => option.id);
    let didUpdate = false;

    if (professor.isActive === undefined) {
        professor.isActive = true;
        didUpdate = true;
    }

    if (!professor.semesterData) {
        professor.semesterData = {};
        didUpdate = true;
    }

    semesterIds.forEach(semesterId => {
        if (!professor.semesterData[semesterId]) {
            professor.semesterData[semesterId] = {
                totalStudents: 0,
                evaluatedCount: 0,
                notEvaluatedCount: 0,
                averageRating: 0,
                qualitativeResponses: []
            };
            didUpdate = true;
        }

        const data = professor.semesterData[semesterId];
        data.totalStudents = Number(data.totalStudents) || 0;
        data.evaluatedCount = Number(data.evaluatedCount) || 0;
        data.notEvaluatedCount = Number(data.notEvaluatedCount) || Math.max(data.totalStudents - data.evaluatedCount, 0);
        data.averageRating = parseFloat(data.averageRating) || 0;
        if (Array.isArray(data.qualitativeResponses) && data.qualitativeResponses.length > 0) {
            data.qualitativeResponses = data.qualitativeResponses.map(response => {
                if (!response.studentName || !response.studentNumber || !response.semesterId) {
                    didUpdate = true;
                }
                return normalizeResponse(response, semesterId);
            });
        } else {
            data.qualitativeResponses = [];
        }
    });

    const overall = combineSemesterData(professor.semesterData);
    professor.totalStudents = overall.totalStudents;
    professor.evaluatedCount = overall.evaluatedCount;
    professor.notEvaluatedCount = overall.notEvaluatedCount;
    professor.averageRating = overall.averageRating;
    professor.evaluationsCount = overall.evaluatedCount;
    professor.qualitativeResponses = overall.qualitativeResponses.map(response => normalizeResponse(response, response.semesterId));

    return didUpdate;
}

function getProfessorAnalyticsSnapshot(professor, semesterId) {
    if (!professor) return null;
    if (!semesterId || semesterId === 'all' || !professor.semesterData || !professor.semesterData[semesterId]) {
        return {
            totalStudents: professor.totalStudents || 0,
            evaluatedCount: professor.evaluatedCount || professor.evaluationsCount || 0,
            notEvaluatedCount: professor.notEvaluatedCount || 0,
            averageRating: parseFloat(professor.averageRating) || 0,
            qualitativeResponses: Array.isArray(professor.qualitativeResponses) ? professor.qualitativeResponses : []
        };
    }

    const data = professor.semesterData[semesterId];
    const totalStudents = Number(data.totalStudents) || 0;
    const evaluatedCount = Number(data.evaluatedCount) || 0;
    const notEvaluatedCount = Number(data.notEvaluatedCount) || Math.max(totalStudents - evaluatedCount, 0);
    const averageRating = parseFloat(data.averageRating) || 0;

    return {
        totalStudents: totalStudents,
        evaluatedCount: evaluatedCount,
        notEvaluatedCount: notEvaluatedCount,
        averageRating: averageRating,
        qualitativeResponses: Array.isArray(data.qualitativeResponses) ? data.qualitativeResponses : []
    };
}


/**
 * Load professors data from localStorage or generate new
 */
function loadProfessorsData() {
    // Load professors from centralized sharedUsersData
    const savedProfessors = getProfessorsFromSharedData();
    if (savedProfessors.length > 0) {
        professorsData = savedProfessors;
        let didUpdate = false;
        // Migrate old data to include analytics fields
        professorsData = professorsData.map(professor => {
            if (!professor.employeeId) {
                professor.employeeId = generateEmployeeId();
                didUpdate = true;
            }
            if (!professor.employmentType) {
                professor.employmentType = 'Regular';
                didUpdate = true;
            }
            if (ensureProfessorSemesterData(professor)) {
                didUpdate = true;
            }
            return professor;
        });

        // Limit professors to 2-3 per department
        limitProfessorsPerDepartment();
        if (didUpdate) {
            saveProfessorsToSharedData();
        }
    } else {
        // No professors in SharedData — show empty state, don't auto-generate fake data
        professorsData = [];
    }
}

/**
 * Limit professors to 1 per department
 */
function limitProfessorsPerDepartment() {
    const campuses = SharedData.getCampuses();
    const deptSet = new Set();
    campuses.filter(function (c) { return c.id !== 'all'; }).forEach(function (c) { c.departments.forEach(function (d) { deptSet.add(d); }); });
    const departments = Array.from(deptSet);
    const limitedData = [];
    let needsRegeneration = false;

    departments.forEach(dept => {
        const deptProfessors = professorsData.filter(t => t.department === dept);
        // Keep exactly 1 professor per department
        if (deptProfessors.length === 0) {
            // If no professors, mark for regeneration
            needsRegeneration = true;
        } else if (deptProfessors.length > 1) {
            // If more than 1, keep only first one
            limitedData.push(deptProfessors[0]);
        } else {
            // If exactly 1, keep it
            limitedData.push(...deptProfessors);
        }
    });

    if (needsRegeneration) {
        // Some departments have no professor — leave empty, don't auto-generate fake data
    } else if (limitedData.length > 0) {
        // Update data if we limited it
        professorsData = limitedData;
        saveProfessorsToSharedData();
    }
}

/**
 * Setup professor ranking section (dashboard)
 */
function setupProfessorRanking() {
    populateRankingFilters();

    const deptSelect = document.getElementById('ranking-dept-filter');
    const employmentSelect = document.getElementById('ranking-employment-filter');

    if (deptSelect) {
        deptSelect.addEventListener('change', (e) => {
            rankingDepartmentFilter = e.target.value || 'all';
            renderProfessorRanking();
        });
    }

    if (employmentSelect) {
        employmentSelect.addEventListener('change', (e) => {
            rankingEmploymentFilter = e.target.value || 'all';
            renderProfessorRanking();
        });
    }

    renderProfessorRanking();
}

/**
 * Populate ranking filter dropdowns
 */
function populateRankingFilters() {
    const deptSelect = document.getElementById('ranking-dept-filter');
    const employmentSelect = document.getElementById('ranking-employment-filter');

    if (deptSelect) {
        const campuses = SharedData.getCampuses();
        const campusDepts = new Set();
        campuses.filter(function (c) { return c.id !== 'all'; }).forEach(function (c) { c.departments.forEach(function (d) { campusDepts.add(d); }); });
        const departments = Array.from(new Set([
            ...campusDepts,
            ...professorsData.map(p => p.department).filter(Boolean)
        ]));

        deptSelect.innerHTML = [
            '<option value=\"all\">All Departments</option>',
            ...departments.map(dept => `<option value=\"${dept}\">${dept}</option>`)
        ].join('');

        // Keep previously selected value if possible
        if (departments.includes(rankingDepartmentFilter)) {
            deptSelect.value = rankingDepartmentFilter;
        } else {
            rankingDepartmentFilter = 'all';
            deptSelect.value = 'all';
        }
    }

    if (employmentSelect) {
        employmentSelect.value = rankingEmploymentFilter || 'all';
    }
}

/**
 * Render professor ranking list with filters
 */
function renderProfessorRanking() {
    const rankingList = document.getElementById('professor-ranking-list');
    if (!rankingList) return;

    populateRankingFilters();

    if (!professorsData || professorsData.length === 0) {
        rankingList.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-user-slash"></i>
                <p>No professor data available yet.</p>
            </div>
        `;
        return;
    }

    let filtered = professorsData.filter(p => p.isActive !== false);

    if (rankingDepartmentFilter !== 'all') {
        filtered = filtered.filter(p => p.department === rankingDepartmentFilter);
    }

    if (rankingEmploymentFilter !== 'all') {
        const employmentFilter = String(rankingEmploymentFilter).toLowerCase();
        filtered = filtered.filter(p => formatEmploymentType(p.employmentType).toLowerCase() === employmentFilter);
    }

    const ranked = filtered
        .map(prof => {
            const averageRating = parseFloat(prof.averageRating) || 0;
            const ratingPercent = Math.min(Math.max((averageRating / 5) * 100, 0), 100);
            return { ...prof, ratingPercent };
        })
        .sort((a, b) => b.ratingPercent - a.ratingPercent || (b.evaluatedCount || 0) - (a.evaluatedCount || 0));

    const topProfessors = ranked.slice(0, 5);

    if (topProfessors.length === 0) {
        rankingList.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-filter"></i>
                <p>No professors match the selected filters.</p>
            </div>
        `;
        return;
    }

    rankingList.innerHTML = topProfessors.map((prof, index) => {
        const employmentType = formatEmploymentType(prof.employmentType);
        const employmentClass = employmentType.toLowerCase() === 'temporary' ? 'temporary' : 'regular';
        return `
            <div class="user-item">
                <div class="user-info">
                    <div class="user-name">
                        ${index + 1}. ${prof.name}
                        <span class="role-badge rating-badge">${prof.ratingPercent.toFixed(1)}%</span>
                    </div>
                    <div class="user-meta">
                        <span class="user-position">${prof.position || 'Professor'}</span>
                        <button type="button" class="employment-pill ${employmentClass}">${employmentType}</button>
                    </div>
                    <div class="user-email">${prof.department} Department</div>
                </div>
                <div class="user-actions">
                    ${getRankingIcon(index + 1)}
                </div>
            </div>
        `;
    }).join('');
}

function getRankingIcon(rank) {
    if (rank === 1) {
        return '<i class="fas fa-trophy" style="color: #fbbf24; font-size: 20px;"></i>';
    }
    if (rank === 2) {
        return '<i class="fas fa-medal" style="color: #9ca3af; font-size: 20px;"></i>';
    }
    if (rank === 3) {
        return '<i class="fas fa-medal" style="color: #b45309; font-size: 20px;"></i>';
    }
    return `<span class="ranking-number">#${rank}</span>`;
}

/**
 * Setup professor management functionality
 */
function setupProfessorManagement() {
    // Load professor data from SharedData
    loadProfessorsData();

    // Department tabs
    const deptTabs = document.querySelectorAll('.dept-tab');
    deptTabs.forEach(tab => {
        tab.addEventListener('click', function () {
            deptTabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            currentDepartmentFilter = this.getAttribute('data-department');
            renderProfessors();
        });
    });

    // Add professor button
    const addProfessorBtn = document.getElementById('add-professor-btn');
    if (addProfessorBtn) {
        addProfessorBtn.addEventListener('click', openAddProfessorModal);
    }

    const searchInput = document.getElementById('professor-search');
    if (searchInput) {
        searchInput.addEventListener('input', renderProfessors);
    }

    // Modal close buttons
    const closeModal = document.getElementById('close-modal');
    const closeDetailsModal = document.getElementById('close-details-modal');
    const closeAnalyticsModal = document.getElementById('close-analytics-modal');
    const cancelForm = document.getElementById('cancel-form');

    if (closeModal) {
        closeModal.addEventListener('click', closeProfessorModal);
    }
    if (closeDetailsModal) {
        closeDetailsModal.addEventListener('click', closeProfessorDetailsModal);
    }
    if (closeAnalyticsModal) {
        closeAnalyticsModal.addEventListener('click', closeProfessorAnalyticsModal);
    }
    if (cancelForm) {
        cancelForm.addEventListener('click', closeProfessorModal);
    }

    // Form submission
    const professorForm = document.getElementById('professor-form');
    if (professorForm) {
        professorForm.addEventListener('submit', handleProfessorFormSubmit);
    }

    // Close modal on outside click
    const modal = document.getElementById('professor-modal');
    const detailsModal = document.getElementById('professor-details-modal');
    const analyticsModal = document.getElementById('professor-analytics-modal');

    if (modal) {
        modal.addEventListener('click', function (e) {
            if (e.target === modal) {
                closeProfessorModal();
            }
        });
    }

    if (detailsModal) {
        detailsModal.addEventListener('click', function (e) {
            if (e.target === detailsModal) {
                closeProfessorDetailsModal();
            }
        });
    }

    if (analyticsModal) {
        analyticsModal.addEventListener('click', function (e) {
            if (e.target === analyticsModal) {
                closeProfessorAnalyticsModal();
            }
        });
    }
}

/**
 * Load user management view
 */
function loadUserManagement() {
    renderProfessors();
}

/**
 * Render professors list
 */
function renderProfessors() {
    const professorsList = document.getElementById('professors-list');
    if (!professorsList) return;

    const searchInput = document.getElementById('professor-search');
    const searchTerm = searchInput ? searchInput.value.trim().toLowerCase() : '';

    // Filter professors by department
    let filteredProfessors = professorsData;
    if (currentDepartmentFilter !== 'all') {
        filteredProfessors = professorsData.filter(t => t.department === currentDepartmentFilter);
    }

    if (searchTerm) {
        filteredProfessors = filteredProfessors.filter(professor => {
            const nameMatch = (professor.name || '').toLowerCase().includes(searchTerm);
            const employeeMatch = (professor.employeeId || '').toLowerCase().includes(searchTerm);
            return nameMatch || employeeMatch;
        });
    }

    filteredProfessors = filteredProfessors
        .slice()
        .sort((a, b) => {
            const aActive = a.isActive !== false;
            const bActive = b.isActive !== false;
            if (aActive !== bActive) return aActive ? -1 : 1;
            return (a.name || '').localeCompare(b.name || '');
        });

    if (filteredProfessors.length === 0) {
        const emptyMessage = searchTerm
            ? 'No professors match your search'
            : 'No professors found in this department';
        professorsList.innerHTML = `
            <div class="empty-state">
                <i class="fas fa-user-slash"></i>
                <p>${emptyMessage}</p>
            </div>
        `;
        return;
    }

    professorsList.innerHTML = filteredProfessors.map(professor => `
        <div class="professor-card ${professor.isActive === false ? 'inactive' : ''}" data-id="${professor.id}">
            <div class="professor-info">
                <div class="professor-avatar">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="professor-details">
                    <div class="professor-name-row">
                        <h3>${professor.name}</h3>
                        <span class="dept-badge dept-${professor.department}">${professor.department}</span>
                    </div>
                    <p class="professor-email">${professor.email}</p>
                    <p class="professor-employee">Employee ID: ${professor.employeeId || 'N/A'}</p>
                    <p class="professor-position">${professor.position}</p>
                    <p class="professor-employment">${formatEmploymentType(professor.employmentType)}</p>
                    <div class="professor-stats">
                        <div class="stat-item">
                            <i class="fas fa-user-check"></i>
                            <span>${professor.evaluatedCount || professor.evaluationsCount || 0} Students Evaluated</span>
                        </div>
                        <div class="stat-item">
                            <i class="fas fa-toggle-${professor.isActive ? 'on' : 'off'}"></i>
                            <span>${professor.isActive ? 'Active' : 'Inactive'}</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="professor-actions">
                <button class="action-btn view" data-action="view" data-professor-id="${professor.id}" title="View Details">
                    <i class="fas fa-eye"></i>
                </button>
                <button class="action-btn analytics" data-action="analytics" data-professor-id="${professor.id}" title="Analytics">
                    <i class="fas fa-chart-line"></i>
                </button>
                <button class="action-btn edit" data-action="edit" data-professor-id="${professor.id}" title="Edit">
                    <i class="fas fa-edit"></i>
                </button>
            </div>
        </div>
    `).join('');

    // Add event listeners to action buttons
    const actionButtons = professorsList.querySelectorAll('.action-btn');
    actionButtons.forEach(button => {
        button.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const professorIdStr = this.getAttribute('data-professor-id');
            const professorId = professorIdStr; // IDs can be alphanumeric
            const action = this.getAttribute('data-action');

            console.log('Button clicked - Action:', action, 'professor ID:', professorId);

            if (!professorId) {
                console.error('Invalid professor ID:', professorIdStr);
                alert('Invalid professor ID. Please try again.');
                return;
            }

            switch (action) {
                case 'view':
                    viewProfessorDetails(professorId);
                    break;
                case 'analytics':
                    console.log('Calling viewProfessorAnalytics with ID:', professorId);
                    viewProfessorAnalytics(professorId);
                    break;
                case 'edit':
                    editProfessor(professorId);
                    break;
                default:
                    console.error('Unknown action:', action);
            }
        });
    });
}

/**
 * Open add professor modal
 */
function openAddProfessorModal() {
    const modal = document.getElementById('professor-modal');
    const modalTitle = document.getElementById('modal-title');
    const form = document.getElementById('professor-form');

    if (modal && modalTitle && form) {
        modalTitle.textContent = 'Add Professor';
        form.reset();
        const activeCheckbox = document.getElementById('professor-active');
        const employmentTypeSelect = document.getElementById('professor-employment-type');
        if (activeCheckbox) activeCheckbox.checked = true;
        if (employmentTypeSelect) employmentTypeSelect.value = 'Regular';
        currentEditingProfessorId = null;
        modal.style.display = 'flex';
    }
}

/**
 * Close professor modal
 */
function closeProfessorModal() {
    const modal = document.getElementById('professor-modal');
    if (modal) {
        modal.style.display = 'none';
        currentEditingProfessorId = null;
    }
}

/**
 * Handle professor form submission
 */
function handleProfessorFormSubmit(e) {
    e.preventDefault();

    const employeeIdInput = document.getElementById('professor-employee-id');
    const employeeIdValue = employeeIdInput ? employeeIdInput.value.trim() : '';

    const formData = {
        name: document.getElementById('professor-name').value,
        email: document.getElementById('professor-email').value,
        department: document.getElementById('professor-department').value,
        position: document.getElementById('professor-position').value || 'Professor',
        employmentType: document.getElementById('professor-employment-type').value,
        isActive: document.getElementById('professor-active').checked,
        employeeId: employeeIdValue
    };

    if (currentEditingProfessorId) {
        // Update existing professor
        const professorIndex = professorsData.findIndex(t => t.id === currentEditingProfessorId);
        if (professorIndex !== -1) {
            if (!formData.employeeId) {
                formData.employeeId = professorsData[professorIndex].employeeId || generateEmployeeId();
            }
            professorsData[professorIndex] = {
                ...professorsData[professorIndex],
                ...formData
            };
        }
    } else {
        // Add new professor with default analytics
        if (!formData.employeeId) {
            formData.employeeId = generateEmployeeId();
        }
        const newProfessor = {
            id: Date.now() + Math.random(),
            ...formData,
            evaluationsCount: 0,
            totalStudents: 0,
            evaluatedCount: 0,
            notEvaluatedCount: 0,
            averageRating: 0,
            qualitativeResponses: []
        };
        professorsData.push(newProfessor);
    }

    // Save to localStorage
    saveProfessorsToSharedData();

    // Re-render and close modal
    renderProfessors();
    renderProfessorRanking();
    closeProfessorModal();
    updateOverviewCards();
}

/**
 * Edit professor
 */
function editProfessor(professorId) {
    const professor = professorsData.find(t => String(t.id) === String(professorId));
    if (!professor) return;

    const modal = document.getElementById('professor-modal');
    const modalTitle = document.getElementById('modal-title');

    if (modal && modalTitle) {
        modalTitle.textContent = 'Edit Professor';
        document.getElementById('professor-name').value = professor.name;
        document.getElementById('professor-email').value = professor.email;
        document.getElementById('professor-employee-id').value = professor.employeeId || '';
        document.getElementById('professor-department').value = professor.department;
        document.getElementById('professor-position').value = professor.position;
        document.getElementById('professor-employment-type').value = professor.employmentType || 'Regular';
        document.getElementById('professor-active').checked = professor.isActive !== false;
        currentEditingProfessorId = professorId;
        modal.style.display = 'flex';
    }
}

/**
 * Delete professor
 */
function deleteProfessor(professorId) {
    const professor = professorsData.find(t => String(t.id) === String(professorId));
    if (!professor) return;

    if (confirm(`Are you sure you want to delete ${professor.name}?\n\nThis action cannot be undone.`)) {
        professorsData = professorsData.filter(t => t.id !== professorId);
        saveProfessorsToSharedData();
        renderProfessors();
        renderProfessorRanking();
        updateOverviewCards();
    }
}

/**
 * View professor details
 */
function viewProfessorDetails(professorId) {
    const professor = professorsData.find(t => String(t.id) === String(professorId));
    if (!professor) return;

    const modal = document.getElementById('professor-details-modal');
    const content = document.getElementById('professor-details-content');

    if (modal && content) {
        content.innerHTML = `
            <div class="professor-details-view">
                <div class="detail-header">
                    <div class="detail-avatar">
                        <i class="fas fa-user-tie"></i>
                    </div>
                    <div class="detail-name">
                        <h2>${professor.name}</h2>
                        <span class="dept-badge dept-${professor.department}">${professor.department}</span>
                    </div>
                </div>
                <div class="detail-info">
                    <div class="info-row">
                        <label><i class="fas fa-envelope"></i> Email:</label>
                        <span>${professor.email}</span>
                    </div>
                    <div class="info-row">
                        <label><i class="fas fa-id-badge"></i> Employee ID:</label>
                        <span>${professor.employeeId || 'N/A'}</span>
                    </div>
                    <div class="info-row">
                        <label><i class="fas fa-briefcase"></i> Position:</label>
                        <span>${professor.position}</span>
                    </div>
                    <div class="info-row">
                        <label><i class="fas fa-user-tag"></i> Employment Type:</label>
                        <span>${professor.employmentType || 'Regular'}</span>
                    </div>
                    <div class="info-row">
                        <label><i class="fas fa-building"></i> Department:</label>
                        <span>${professor.department}</span>
                    </div>
                    <div class="info-row">
                        <label><i class="fas fa-toggle-${professor.isActive ? 'on' : 'off'}"></i> Status:</label>
                        <span>${professor.isActive ? 'Active' : 'Inactive'}</span>
                    </div>
                    <div class="info-row highlight">
                        <label><i class="fas fa-user-check"></i> Students Evaluated:</label>
                        <span class="evaluation-count">${professor.evaluatedCount || professor.evaluationsCount || 0}</span>
                    </div>
                </div>
                <div class="detail-actions">
                    <button class="btn-edit-detail" onclick="closeProfessorDetailsModal(); editProfessor(${professor.id});">
                        <i class="fas fa-edit"></i> Edit Profile
                    </button>
                </div>
            </div>
        `;
        modal.style.display = 'flex';
    }
}

/**
 * Close professor details modal
 */
function closeProfessorDetailsModal() {
    const modal = document.getElementById('professor-details-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}

function buildSemesterOptionsHtml(selectedSemester) {
    return getSemesterOptions().map(option => {
        const selected = option.id === selectedSemester ? 'selected' : '';
        return `<option value="${option.id}" ${selected}>${option.label}</option>`;
    }).join('');
}

function buildEvaluationTypeOptionsHtml(selectedType) {
    return getEvaluationTypeOptions().map(option => {
        const selected = option.id === selectedType ? 'selected' : '';
        return `<option value="${option.id}" ${selected}>${option.label}</option>`;
    }).join('');
}

function getEvaluationSnapshotForType(professor, semesterId, evaluationType) {
    const baseSnapshot = getProfessorAnalyticsSnapshot(professor, semesterId);
    const meta = getEvaluationTypeMeta(evaluationType);

    if (evaluationType === 'student') {
        return {
            totalRaters: baseSnapshot.totalStudents || 0,
            evaluatedCount: baseSnapshot.evaluatedCount || 0,
            notEvaluatedCount: baseSnapshot.notEvaluatedCount || Math.max((baseSnapshot.totalStudents || 0) - (baseSnapshot.evaluatedCount || 0), 0),
            averageRating: parseFloat(baseSnapshot.averageRating) || 0,
            qualitativeResponses: baseSnapshot.qualitativeResponses || [],
            meta
        };
    }

    const professorCount = professorsData ? professorsData.length : 0;
    const totalRaters = evaluationType === 'peer'
        ? Math.max(professorCount - 1, 0)
        : professorCount > 0 ? 1 : 0;

    const completionRatio = baseSnapshot.totalStudents > 0
        ? baseSnapshot.evaluatedCount / baseSnapshot.totalStudents
        : 0;

    const ratio = evaluationType === 'peer'
        ? clampNumber(completionRatio * 0.9 + 0.05, 0.3, 0.95)
        : clampNumber(completionRatio * 0.75 + 0.05, 0.2, 0.9);

    const evaluatedCount = totalRaters > 0 ? Math.round(totalRaters * ratio) : 0;
    const notEvaluatedCount = Math.max(totalRaters - evaluatedCount, 0);

    const ratingOffset = evaluationType === 'peer' ? 0.1 : 0.2;
    const averageRating = clampNumber((parseFloat(baseSnapshot.averageRating) || 0) + ratingOffset, 1, 5);

    return {
        totalRaters,
        evaluatedCount,
        notEvaluatedCount,
        averageRating,
        qualitativeResponses: [],
        meta
    };
}

/**
 * View professor analytics
 */
function viewProfessorAnalytics(professorId) {
    try {
        console.log('Opening analytics for professor ID:', professorId);

        console.log('Available professors:', professorsData.map(t => ({ id: t.id, name: t.name })));

        const professor = professorsData.find(t => String(t.id) === String(professorId));

        if (!professor) {
            console.error('Professor not found! ID:', professorId, 'Available IDs:', professorsData.map(t => t.id));
            alert('Professor not found. Please try again.');
            return;
        }

        console.log('Found professor:', professor);

        const modal = document.getElementById('professor-analytics-modal');
        const content = document.getElementById('professor-analytics-content');

        if (!modal) {
            console.error('Analytics modal not found!');
            alert('Analytics modal not found. Please refresh the page.');
            return;
        }

        if (!content) {
            console.error('Analytics content not found!');
            alert('Analytics content not found. Please refresh the page.');
            return;
        }

        const selectedSemester = currentAnalyticsSemester || 'all';
        const normalizedSemester = professor.semesterData && professor.semesterData[selectedSemester]
            ? selectedSemester
            : 'all';

        currentAnalyticsSemester = normalizedSemester;

        const selectedEvaluationType = currentAnalyticsEvaluationType || 'student';
        const normalizedEvaluationType = getEvaluationTypeMeta(selectedEvaluationType).id;
        currentAnalyticsEvaluationType = normalizedEvaluationType;
        const evaluationMeta = getEvaluationTypeMeta(normalizedEvaluationType);

        const snapshot = getEvaluationSnapshotForType(professor, normalizedSemester, normalizedEvaluationType);

        const totalRaters = snapshot.totalRaters || 0;
        const evaluatedCount = snapshot.evaluatedCount || 0;
        const notEvaluatedCount = snapshot.notEvaluatedCount || Math.max(totalRaters - evaluatedCount, 0);
        const averageRating = parseFloat(snapshot.averageRating) || 0;
        const completionPercentage = totalRaters > 0 ? Math.round((evaluatedCount / totalRaters) * 100) : 0;
        const evaluatorLabel = evaluationMeta.unitLabel.endsWith('s')
            ? evaluationMeta.unitLabel.slice(0, -1)
            : evaluationMeta.unitLabel;

        // Determine status
        let status = 'Excellent';
        let statusColor = '#10b981';
        if (completionPercentage < 50) {
            status = 'Needs Attention';
            statusColor = '#ef4444';
        } else if (completionPercentage < 75) {
            status = 'Good';
            statusColor = '#f59e0b';
        }

        content.innerHTML = `
        <div class="analytics-view">
            <div class="analytics-header">
                <div class="analytics-avatar">
                    <i class="fas fa-user-tie"></i>
                </div>
                <div class="analytics-name">
                    <h2>${professor.name}</h2>
                    <span class="dept-badge dept-${professor.department}">${professor.department}</span>
                </div>
            </div>
            
            <div class="analytics-filters">
                <div class="analytics-filter">
                    <label for="analytics-semester-select">Semester</label>
                    <select id="analytics-semester-select">
                        ${buildSemesterOptionsHtml(normalizedSemester)}
                    </select>
                </div>
                <div class="analytics-filter">
                    <label for="analytics-evaluation-type">Evaluation Type</label>
                    <select id="analytics-evaluation-type">
                        ${buildEvaluationTypeOptionsHtml(normalizedEvaluationType)}
                    </select>
                </div>
                <div class="analytics-filter-summary">
                    <span>${normalizedSemester === 'all' ? 'Showing overall data' : `Showing ${getSemesterLabel(normalizedSemester)} data`} • ${evaluationMeta.label}</span>
                </div>
            </div>

            <div class="analytics-stats-grid">
                <div class="stat-card rating">
                    <div class="stat-icon">
                        <i class="fas fa-star"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Average Rating</h3>
                        <p class="stat-value">${parseFloat(averageRating).toFixed(1)}<span class="stat-unit">/5.0</span></p>
                        <div class="rating-stars">
                            ${generateStarRating(averageRating)}
                        </div>
                    </div>
                </div>
                
                <div class="stat-card status">
                    <div class="stat-icon">
                        <i class="fas fa-info-circle"></i>
                    </div>
                    <div class="stat-content">
                        <h3>Current Status</h3>
                        <p class="stat-value status-badge" style="color: ${statusColor}">${status}</p>
                    </div>
                </div>
            </div>
            
            <div class="analytics-section">
                <h3 class="section-title">
                    <i class="${evaluationMeta.icon}"></i>
                    ${evaluationMeta.statusTitle}
                </h3>
                <div class="evaluation-stats">
                    <div class="evaluation-item evaluated">
                        <div class="evaluation-icon">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <div class="evaluation-info">
                            <h4>Evaluated</h4>
                            <p class="evaluation-count">${evaluatedCount} ${evaluationMeta.unitLabel}</p>
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
                            <p class="evaluation-count">${notEvaluatedCount} ${evaluationMeta.unitLabel}</p>
                            <div class="progress-bar">
                                <div class="progress-fill not-evaluated-fill" style="width: ${totalRaters > 0 ? (notEvaluatedCount / totalRaters) * 100 : 0}%"></div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="evaluation-item total">
                        <div class="evaluation-icon">
                            <i class="${evaluationMeta.icon}"></i>
                        </div>
                        <div class="evaluation-info">
                            <h4>${evaluationMeta.totalLabel}</h4>
                            <p class="evaluation-count">${totalRaters} ${evaluationMeta.unitLabel}</p>
                        </div>
                    </div>
                </div>
            </div>
            
         
            
            <div class="qualitative-responses-section">
                <div class="section-header-with-button">
                    <h3 class="section-title">
                        <i class="fas fa-comments"></i>
                        ${evaluationMeta.label} Feedback
                    </h3>
                    <button class="btn-ai-summarize" id="ai-summarize-btn" onclick="handleAISummarization(${professor.id})">
                        <i class="fas fa-robot"></i>
                        AI Summarization
                    </button>
                </div>
                <div class="qualitative-responses-list">
                    ${snapshot.qualitativeResponses && snapshot.qualitativeResponses.length > 0
                ? snapshot.qualitativeResponses.map(response => `
                            <div class="response-card compact">
                                <div class="response-header">
                                    <div class="response-icon">
                                        <i class="${evaluationMeta.feedbackIcon}"></i>
                                    </div>
                                    <div class="response-meta">
                                        <span class="response-label">${evaluationMeta.label} Feedback</span>
                                        <span class="response-student">${response.studentName || evaluatorLabel} • ${response.studentNumber || 'N/A'}</span>
                                    </div>
                                    <span class="response-date">${response.date}</span>
                                </div>
                                <p class="response-text">"${response.text}"</p>
                            </div>
                        `).join('')
                : `<div class="no-responses"><p>No ${evaluationMeta.label.toLowerCase()} feedback available for ${getSemesterLabel(normalizedSemester).toLowerCase()}.</p></div>`
            }
                </div>
            </div>
        </div>
    `;
        modal.style.display = 'flex';
        const semesterSelect = content.querySelector('#analytics-semester-select');
        if (semesterSelect) {
            semesterSelect.addEventListener('change', function () {
                currentAnalyticsSemester = this.value;
                viewProfessorAnalytics(professor.id);
            });
        }
        const evaluationTypeSelect = content.querySelector('#analytics-evaluation-type');
        if (evaluationTypeSelect) {
            evaluationTypeSelect.addEventListener('change', function () {
                currentAnalyticsEvaluationType = this.value;
                viewProfessorAnalytics(professor.id);
            });
        }
        console.log('Analytics modal displayed');
    } catch (error) {
        console.error('Error in viewProfessorAnalytics:', error);
        alert('An error occurred while loading analytics. Please check the console for details.');
    }
}

/**
 * Generate star rating display
 */
function generateStarRating(rating) {
    const numRating = parseFloat(rating) || 0;
    const fullStars = Math.floor(numRating);
    const hasHalfStar = (numRating % 1) >= 0.5;
    const emptyStars = 5 - fullStars - (hasHalfStar ? 1 : 0);

    let stars = '';
    for (let i = 0; i < fullStars; i++) {
        stars += '<i class="fas fa-star"></i>';
    }
    if (hasHalfStar) {
        stars += '<i class="fas fa-star-half-alt"></i>';
    }
    for (let i = 0; i < emptyStars; i++) {
        stars += '<i class="far fa-star"></i>';
    }
    return stars;
}

/**
 * Close professor analytics modal
 */
function closeProfessorAnalyticsModal() {
    const modal = document.getElementById('professor-analytics-modal');
    if (modal) {
        modal.style.display = 'none';
    }
}

/**
 * Handle AI Summarization button click
 */
function handleAISummarization(professorId) {
    const id = typeof professorId === 'string' ? parseFloat(professorId) : professorId;
    const professor = professorsData.find(t => Math.abs(t.id - id) < 0.0001 || t.id === id);

    if (!professor) {
        alert('Professor not found.');
        return;
    }

    // For now, just show a placeholder message
    // This will be replaced with actual API call later
    const btn = document.getElementById('ai-summarize-btn');
    if (btn) {
        const originalText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating Summary...';

        // Simulate API call (replace with actual API call later)
        setTimeout(() => {
            alert('AI Summarization feature will be implemented soon!\n\nThis will analyze all student feedback and generate a comprehensive summary.');
            btn.disabled = false;
            btn.innerHTML = originalText;
        }, 1500);
    }
}

function generateEmployeeId() {
    return `EMP-${Math.floor(1000 + Math.random() * 9000)}`;
}

function formatEmploymentType(type) {
    if (!type) return 'Regular';
    const normalized = String(type).toLowerCase();
    return normalized.charAt(0).toUpperCase() + normalized.slice(1);
}

/**
 * Load and display reports
 */
function loadReports() {
    // Generate evaluation data
    const evaluationData = generateEvaluationData();

    // Update overall status
    updateOverallStatus(evaluationData);

    // Render charts for each evaluation type
    renderStudentProfessorCharts(evaluationData.studentToProfessor);
    renderProfessorProfessorCharts(evaluationData.professorToProfessor);
    renderSupervisorProfessorCharts(evaluationData.supervisorToProfessor);
}

/**
 * Generate evaluation data
 */
function generateEvaluationData() {
    // Calculate overall statistics from professors data
    let totalStudents = 0;
    let completedEvaluations = 0;

    professorsData.forEach(professor => {
        totalStudents += professor.totalStudents || 0;
        completedEvaluations += professor.evaluatedCount || 0;
    });

    const pendingEvaluations = totalStudents - completedEvaluations;
    const completionRate = totalStudents > 0 ? Math.round((completedEvaluations / totalStudents) * 100) : 0;

    // Generate data for each evaluation type
    return {
        overall: {
            total: totalStudents,
            completed: completedEvaluations,
            pending: pendingEvaluations,
            completionRate: completionRate
        },
        studentToProfessor: generateEvaluationTypeData('Student to Professor'),
        professorToProfessor: generateEvaluationTypeData('Professor to Professor'),
        supervisorToProfessor: generateEvaluationTypeData('Supervisor to Professor')
    };
}

/**
 * Generate data for a specific evaluation type
 */
function generateEvaluationTypeData(type) {
    const categories = [
        'Teaching Effectiveness',
        'Classroom Management',
        'Student Engagement',
        'Communication Skills',
        'Assessment Methods'
    ];

    // Generate average scores for each category (3.5-5.0 range)
    const categoryScores = categories.map(cat => ({
        category: cat,
        score: parseFloat((Math.random() * 1.5 + 3.5).toFixed(1))
    }));

    // Generate rating distribution (1-5 stars)
    const totalRatings = Math.floor(Math.random() * 200) + 100; // 100-300 ratings
    const ratingDistribution = {
        5: Math.floor(totalRatings * (0.4 + Math.random() * 0.2)), // 40-60%
        4: Math.floor(totalRatings * (0.2 + Math.random() * 0.15)), // 20-35%
        3: Math.floor(totalRatings * (0.1 + Math.random() * 0.1)), // 10-20%
        2: Math.floor(totalRatings * (0.02 + Math.random() * 0.03)), // 2-5%
        1: Math.floor(totalRatings * (0.005 + Math.random() * 0.015)) // 0.5-2%
    };

    // Calculate average rating
    const totalWeighted = ratingDistribution[5] * 5 + ratingDistribution[4] * 4 +
        ratingDistribution[3] * 3 + ratingDistribution[2] * 2 + ratingDistribution[1] * 1;
    const totalCount = ratingDistribution[5] + ratingDistribution[4] + ratingDistribution[3] +
        ratingDistribution[2] + ratingDistribution[1];
    const averageRating = totalCount > 0 ? parseFloat((totalWeighted / totalCount).toFixed(1)) : 0;

    // Get number of professors/professors evaluated
    const evaluatedCount = type === 'Student to Professor' ? professorsData.length :
        type === 'Professor to Professor' ? Math.floor(professorsData.length * 0.8) :
            Math.floor(professorsData.length * 0.6);

    return {
        categoryScores: categoryScores,
        ratingDistribution: ratingDistribution,
        averageRating: averageRating,
        totalEvaluations: totalCount,
        evaluatedCount: evaluatedCount
    };
}

/**
 * Update overall evaluation status
 */
function updateOverallStatus(data) {
    const completedEl = document.getElementById('completed-count');
    const pendingEl = document.getElementById('pending-count');
    const totalEl = document.getElementById('total-count');
    const completionEl = document.getElementById('completion-rate');

    if (completedEl) completedEl.textContent = data.overall.completed;
    if (pendingEl) pendingEl.textContent = data.overall.pending;
    if (totalEl) totalEl.textContent = data.overall.total;
    if (completionEl) completionEl.textContent = data.overall.completionRate + '%';
}

/**
 * Render Student to Professor charts
 */
function renderStudentProfessorCharts(data) {
    // Destroy existing charts if they exist
    const barCtx = document.getElementById('student-professor-bar-chart');
    const pieCtx = document.getElementById('student-professor-pie-chart');

    if (barCtx) {
        const existingBarChart = Chart.getChart(barCtx);
        if (existingBarChart) existingBarChart.destroy();

        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: data.categoryScores.map(c => c.category),
                datasets: [{
                    label: 'Average Score',
                    data: data.categoryScores.map(c => c.score),
                    backgroundColor: 'rgba(102, 126, 234, 0.8)',
                    borderColor: 'rgba(102, 126, 234, 1)',
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
                        position: 'bottom'
                    }
                }
            }
        });
    }

    if (pieCtx) {
        const existingPieChart = Chart.getChart(pieCtx);
        if (existingPieChart) existingPieChart.destroy();

        new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: ['5 Stars', '4 Stars', '3 Stars', '2 Stars', '1 Star'],
                datasets: [{
                    data: [
                        data.ratingDistribution[5],
                        data.ratingDistribution[4],
                        data.ratingDistribution[3],
                        data.ratingDistribution[2],
                        data.ratingDistribution[1]
                    ],
                    backgroundColor: [
                        '#10b981',
                        '#34d399',
                        '#fbbf24',
                        '#f97316',
                        '#ef4444'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    }
                }
            }
        });
    }

    // Update stats
    document.getElementById('student-prof-avg-rating').textContent = data.averageRating;
    document.getElementById('student-prof-total').textContent = data.totalEvaluations;
    document.getElementById('student-prof-count').textContent = data.evaluatedCount;
}

/**
 * Render Professor to Professor charts
 */
function renderProfessorProfessorCharts(data) {
    const barCtx = document.getElementById('professor-professor-bar-chart');
    const pieCtx = document.getElementById('professor-professor-pie-chart');

    if (barCtx) {
        const existingBarChart = Chart.getChart(barCtx);
        if (existingBarChart) existingBarChart.destroy();

        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: data.categoryScores.map(c => c.category),
                datasets: [{
                    label: 'Average Score',
                    data: data.categoryScores.map(c => c.score),
                    backgroundColor: 'rgba(59, 130, 246, 0.8)',
                    borderColor: 'rgba(59, 130, 246, 1)',
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
                        position: 'bottom'
                    }
                }
            }
        });
    }

    if (pieCtx) {
        const existingPieChart = Chart.getChart(pieCtx);
        if (existingPieChart) existingPieChart.destroy();

        new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: ['5 Stars', '4 Stars', '3 Stars', '2 Stars', '1 Star'],
                datasets: [{
                    data: [
                        data.ratingDistribution[5],
                        data.ratingDistribution[4],
                        data.ratingDistribution[3],
                        data.ratingDistribution[2],
                        data.ratingDistribution[1]
                    ],
                    backgroundColor: [
                        '#10b981',
                        '#34d399',
                        '#fbbf24',
                        '#f97316',
                        '#ef4444'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    }
                }
            }
        });
    }

    // Update stats
    document.getElementById('professor-professor-avg-rating').textContent = data.averageRating;
    document.getElementById('professor-professor-total').textContent = data.totalEvaluations;
    document.getElementById('professor-professor-count').textContent = data.evaluatedCount;
}

/**
 * Render Supervisor to Professor charts
 */
function renderSupervisorProfessorCharts(data) {
    const barCtx = document.getElementById('supervisor-professor-bar-chart');
    const pieCtx = document.getElementById('supervisor-professor-pie-chart');

    if (barCtx) {
        const existingBarChart = Chart.getChart(barCtx);
        if (existingBarChart) existingBarChart.destroy();

        new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: data.categoryScores.map(c => c.category),
                datasets: [{
                    label: 'Average Score',
                    data: data.categoryScores.map(c => c.score),
                    backgroundColor: 'rgba(139, 92, 246, 0.8)',
                    borderColor: 'rgba(139, 92, 246, 1)',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                scales: {
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
                        position: 'bottom'
                    }
                }
            }
        });
    }

    if (pieCtx) {
        const existingPieChart = Chart.getChart(pieCtx);
        if (existingPieChart) existingPieChart.destroy();

        new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: ['5 Stars', '4 Stars', '3 Stars', '2 Stars', '1 Star'],
                datasets: [{
                    data: [
                        data.ratingDistribution[5],
                        data.ratingDistribution[4],
                        data.ratingDistribution[3],
                        data.ratingDistribution[2],
                        data.ratingDistribution[1]
                    ],
                    backgroundColor: [
                        '#10b981',
                        '#34d399',
                        '#fbbf24',
                        '#f97316',
                        '#ef4444'
                    ]
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: true,
                plugins: {
                    legend: {
                        display: true,
                        position: 'bottom'
                    }
                }
            }
        });
    }

    // Update stats
    document.getElementById('supervisor-prof-avg-rating').textContent = data.averageRating;
    document.getElementById('supervisor-prof-total').textContent = data.totalEvaluations;
    document.getElementById('supervisor-prof-count').textContent = data.evaluatedCount;
}

/**
 * Questionnaire Management System
 */

const QUESTIONNAIRES_STORAGE_KEY = 'questionnairesBySemester';
let questionnairesBySemester = {};

// Store questions data by type - new structure with sections
let questionsData = {
    'student-to-professor': {
        sections: [],
        questions: []
    },
    'professor-to-professor': {
        sections: [],
        questions: []
    },
    'supervisor-to-professor': {
        sections: [],
        questions: []
    }
};
let currentEditingQuestionId = null;
let currentEditingSectionId = null;
let currentQuestionnaireType = 'student-to-professor';
let activeSemester = null;
const DEFAULT_QUESTIONNAIRE_HEADERS = {
    'student-to-professor': {
        title: 'Student Evaluation Form',
        description: 'Please provide your honest feedback about your professors.'
    },
    'professor-to-professor': {
        title: 'Professor to Professor Evaluation Form',
        description: 'Please provide your professional assessment of your colleague.'
    },
    'supervisor-to-professor': {
        title: 'Supervisor Evaluation Form',
        description: 'Please provide your evaluation of the professor\'s performance.'
    }
};

/**
 * Setup questionnaire functionality
 */
function setupQuestionnaire() {
    loadQuestionsData();
    setupSemesterPicker();

    // Questionnaire type selector
    const questionnaireTypeSelect = document.getElementById('questionnaire-type-select');
    if (questionnaireTypeSelect) {
        questionnaireTypeSelect.addEventListener('change', handleQuestionnaireTypeChange);
        // Set default type
        questionnaireTypeSelect.value = currentQuestionnaireType;
        updateFormHeader(currentQuestionnaireType);
    }
    setupFormHeaderEditing();

    // Add section button
    const addSectionBtn = document.getElementById('add-section-btn');
    if (addSectionBtn) {
        addSectionBtn.addEventListener('click', openAddSectionModal);
    }

    // Add question button
    const addQuestionBtn = document.getElementById('add-question-btn');
    if (addQuestionBtn) {
        addQuestionBtn.addEventListener('click', openAddQuestionModal);
    }

    // Question type change handler (for question form)
    const questionTypeSelect = document.getElementById('question-type');
    if (questionTypeSelect) {
        questionTypeSelect.addEventListener('change', handleQuestionTypeChange);
    }

    // Modal close buttons
    const closeQuestionModalBtn = document.getElementById('close-question-modal');
    const cancelQuestionForm = document.getElementById('cancel-question-form');
    const closeSectionModalBtn = document.getElementById('close-section-modal');
    const cancelSectionForm = document.getElementById('cancel-section-form');

    if (closeQuestionModalBtn) {
        closeQuestionModalBtn.addEventListener('click', closeQuestionModal);
    }
    if (cancelQuestionForm) {
        cancelQuestionForm.addEventListener('click', closeQuestionModal);
    }
    if (closeSectionModalBtn) {
        closeSectionModalBtn.addEventListener('click', closeSectionModal);
    }
    if (cancelSectionForm) {
        cancelSectionForm.addEventListener('click', closeSectionModal);
    }

    // Form submission
    const questionForm = document.getElementById('question-form');
    if (questionForm) {
        questionForm.addEventListener('submit', handleQuestionFormSubmit);
    }

    const sectionForm = document.getElementById('section-form');
    if (sectionForm) {
        sectionForm.addEventListener('submit', handleSectionFormSubmit);
    }

    // Save questionnaire button
    const saveQuestionnaireBtn = document.getElementById('save-questionnaire-btn');
    if (saveQuestionnaireBtn) {
        saveQuestionnaireBtn.addEventListener('click', saveQuestionnaire);
    }

    // Close modal on outside click
    const questionModal = document.getElementById('question-modal');
    const sectionModal = document.getElementById('section-modal');
    if (questionModal) {
        questionModal.addEventListener('click', function (e) {
            if (e.target === questionModal) {
                closeQuestionModal();
            }
        });
    }
    if (sectionModal) {
        sectionModal.addEventListener('click', function (e) {
            if (e.target === sectionModal) {
                closeSectionModal();
            }
        });
    }

    // Also close modals on Escape key
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            if (questionModal && questionModal.style.display === 'flex') {
                closeQuestionModal();
            }
            if (sectionModal && sectionModal.style.display === 'flex') {
                closeSectionModal();
            }
        }
    });
}

/**
 * Load questions data from localStorage
 */
function loadQuestionsData() {
    const savedSemesters = SharedData.getQuestionnaires();
    if (savedSemesters && Object.keys(savedSemesters).length > 0) {
        questionnairesBySemester = savedSemesters;
    } else {
        questionnairesBySemester = {};
    }

    // Clean up junk entries created by previous bugs
    delete questionnairesBySemester[''];
    delete questionnairesBySemester['Current Semester'];

    Object.keys(questionnairesBySemester).forEach(semester => {
        questionnairesBySemester[semester] = normalizeQuestionsData(questionnairesBySemester[semester]);
    });

    // Determine the active semester — prefer SharedData, then fall back to
    // the first available semester from stored questionnaires
    activeSemester = getCurrentSemesterValue();
    if (!activeSemester) {
        const available = Object.keys(questionnairesBySemester);
        if (available.length > 0) activeSemester = available[0];
    }

    if (activeSemester) {
        questionsData = questionnairesBySemester[activeSemester] || buildEmptyQuestionsData();
        questionnairesBySemester[activeSemester] = questionsData;
        persistQuestionsData();
    } else {
        questionsData = buildEmptyQuestionsData();
    }
}

function getCurrentSemesterValue() {
    // Prioritize SharedData (always available) over the DOM dropdown
    // (which may be empty during early init since options are now dynamic)
    const stored = SharedData.getCurrentSemester();
    if (stored && stored.trim()) return stored.trim();
    const currentSemesterInput = document.getElementById('current-semester');
    const domValue = currentSemesterInput && currentSemesterInput.value.trim();
    return domValue || '';
}

function getAvailableSemesters() {
    const semesters = new Set();
    const current = getCurrentSemesterValue();
    if (current) semesters.add(current);

    // Include all semesters from SharedData semester list
    const semesterList = SharedData.getSemesterList();
    semesterList.forEach(s => semesters.add(s.value));

    Object.keys(questionnairesBySemester).forEach(semester => semesters.add(semester));
    return Array.from(semesters);
}

function populateSemesterSelect(selectEl) {
    if (!selectEl) return;
    const semesters = getAvailableSemesters();
    // Build a label lookup from SharedData semester list
    const labelMap = {};
    SharedData.getSemesterList().forEach(s => { labelMap[s.value] = s.label; });
    selectEl.innerHTML = semesters.map(semester => {
        const label = labelMap[semester] || semester;
        return `<option value="${semester}">${label}</option>`;
    }).join('');
}

function setupSemesterPicker() {
    const semesterSelect = document.getElementById('semester-select');
    if (!semesterSelect) return;

    populateSemesterSelect(semesterSelect);
    if (activeSemester) {
        semesterSelect.value = activeSemester;
    }

    setActiveSemester(semesterSelect.value || getCurrentSemesterValue());

    const applySelection = () => {
        const selectedSemester = semesterSelect.value;
        setActiveSemester(selectedSemester);
    };

    semesterSelect.addEventListener('change', applySelection);
}

function setupEvalPeriods() {
    const PERIOD_TYPES = [
        'student-professor',
        'professor-professor',
        'supervisor-professor'
    ];

    // Load saved eval periods into the date inputs
    function loadEvalPeriods() {
        const periods = SharedData.getEvalPeriods();
        PERIOD_TYPES.forEach(type => {
            const startEl = document.getElementById(type + '-start');
            const endEl = document.getElementById(type + '-end');
            if (startEl && periods[type]) startEl.value = periods[type].start || '';
            if (endEl && periods[type]) endEl.value = periods[type].end || '';
        });
    }

    loadEvalPeriods();

    // Wire up Save Evaluation Periods button
    const saveBtn = document.getElementById('save-eval-periods-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', () => {
            const periods = {};
            PERIOD_TYPES.forEach(type => {
                const startEl = document.getElementById(type + '-start');
                const endEl = document.getElementById(type + '-end');
                periods[type] = {
                    start: startEl ? startEl.value : '',
                    end: endEl ? endEl.value : ''
                };
            });
            SharedData.setEvalPeriods(periods);
            alert('Evaluation periods saved successfully!');
        });
    }
}

function setupSemesterSettings() {
    const semesterSelect = document.getElementById('current-semester');
    if (!semesterSelect) return;

    // ── Populate dropdown from SharedData semester list ──
    const populateDropdown = () => {
        const list = SharedData.getSemesterList();
        const saved = SharedData.getCurrentSemester();
        semesterSelect.innerHTML = '';

        if (list.length === 0) {
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = '— No semesters added yet —';
            placeholder.disabled = true;
            placeholder.selected = true;
            semesterSelect.appendChild(placeholder);
            return;
        }

        list.forEach(sem => {
            const opt = document.createElement('option');
            opt.value = sem.value;
            opt.textContent = sem.label;
            if (sem.value === saved) opt.selected = true;
            semesterSelect.appendChild(opt);
        });

        // If no saved match, select first option
        if (saved && !semesterSelect.value) {
            semesterSelect.selectedIndex = 0;
        }
    };

    populateDropdown();

    // ── Add Term button ──
    const addTermBtn = document.getElementById('btn-add-term');
    const newTermInput = document.getElementById('new-term-input');
    if (addTermBtn && newTermInput) {
        addTermBtn.addEventListener('click', () => {
            const label = newTermInput.value.trim();
            if (!label) {
                alert('Please enter a semester name (e.g. "1st Semester 2027-2028").');
                return;
            }
            // Create a slug-style value from the label
            const value = label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
            // Check for duplicates
            const existing = SharedData.getSemesterList().find(s => s.value === value);
            if (existing) {
                alert('This semester already exists.');
                semesterSelect.value = value;
                newTermInput.value = '';
                return;
            }
            // Add to SharedData and refresh dropdown
            SharedData.addSemester(value, label);
            populateDropdown();
            semesterSelect.value = value;
            newTermInput.value = '';
        });
    }

    // ── Save Current Semester button ──
    const saveBtn = document.getElementById('save-current-semester-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', () => {
            const value = semesterSelect.value;
            if (!value) {
                alert('Please add a semester first.');
                return;
            }
            SharedData.setCurrentSemester(value);
            refreshSemesterPicker();
            const label = semesterSelect.options[semesterSelect.selectedIndex].textContent;
            alert('Current semester saved: ' + label + '\nThe system will now operate under this semester.');
        });
    }
}

function refreshSemesterPicker() {
    const semesterSelect = document.getElementById('semester-select');
    if (!semesterSelect) return;
    const previousValue = semesterSelect.value;
    populateSemesterSelect(semesterSelect);
    semesterSelect.value = previousValue;
    if (!semesterSelect.value) {
        semesterSelect.value = getCurrentSemesterValue();
    }
    applyQuestionnaireEditMode(isQuestionnaireEditable());
}

function setActiveSemester(semester) {
    if (!semester) return;

    activeSemester = semester;
    const existingData = questionnairesBySemester[semester];
    questionsData = existingData || buildEmptyQuestionsData();
    questionnairesBySemester[semester] = questionsData;

    applyQuestionnaireEditMode(isQuestionnaireEditable());
    updateFormHeader(currentQuestionnaireType);
    renderQuestions();
}

function isQuestionnaireEditable() {
    return activeSemester === getCurrentSemesterValue();
}

function ensureQuestionnaireEditable() {
    if (isQuestionnaireEditable()) return true;
    alert('This semester is read-only. Select the latest semester to edit the questionnaire.');
    return false;
}

function applyQuestionnaireEditMode(editable) {
    const container = document.querySelector('.questionnaire-container');
    if (container) {
        container.classList.toggle('read-only', !editable);
    }

    const addSectionBtn = document.getElementById('add-section-btn');
    const addQuestionBtn = document.getElementById('add-question-btn');
    const saveQuestionnaireBtn = document.getElementById('save-questionnaire-btn');
    if (addSectionBtn) addSectionBtn.disabled = !editable;
    if (addQuestionBtn) addQuestionBtn.disabled = !editable;
    if (saveQuestionnaireBtn) saveQuestionnaireBtn.disabled = !editable;

    const titleEl = document.getElementById('form-title-preview');
    const descEl = document.getElementById('form-description-preview');
    if (titleEl) titleEl.setAttribute('contenteditable', editable ? 'true' : 'false');
    if (descEl) descEl.setAttribute('contenteditable', editable ? 'true' : 'false');
}

function persistQuestionsData() {
    if (!activeSemester) return false;
    questionnairesBySemester[activeSemester] = questionsData;
    const savedQuestionnaires = SharedData.setQuestionnaires(questionnairesBySemester);
    if (!savedQuestionnaires) return false;

    questionnairesBySemester = savedQuestionnaires;
    questionsData = questionnairesBySemester[activeSemester] || buildEmptyQuestionsData();
    return true;
}

function buildEmptyQuestionsData() {
    return {
        'student-to-professor': { sections: [], questions: [] },
        'professor-to-professor': { sections: [], questions: [] },
        'supervisor-to-professor': { sections: [], questions: [] }
    };
}

function isQuestionsDataEmpty(data) {
    if (!data) return true;
    return ['student-to-professor', 'professor-to-professor', 'supervisor-to-professor'].every(type => {
        const entry = data[type] || {};
        const sections = entry.sections || [];
        const questions = entry.questions || [];
        const header = entry.header || {};
        return sections.length === 0 && questions.length === 0 && !header.title && !header.description;
    });
}

function normalizeQuestionsData(parsed) {
    if (!parsed) return buildSampleQuestionsData();

    if (Array.isArray(parsed)) {
        const baseTime = Date.now();
        const defaultSection = {
            id: baseTime,
            letter: 'A',
            title: 'General Questions',
            description: 'General evaluation questions'
        };
        return {
            'student-to-professor': {
                sections: [defaultSection],
                questions: parsed.map((q, idx) => ({ ...q, sectionId: defaultSection.id, order: idx + 1 }))
            },
            'professor-to-professor': { sections: [], questions: [] },
            'supervisor-to-professor': { sections: [], questions: [] }
        };
    }

    if (parsed['student-to-professor'] && Array.isArray(parsed['student-to-professor'])) {
        const baseTime = Date.now();
        const defaultSection = {
            id: baseTime,
            letter: 'A',
            title: 'General Questions',
            description: 'General evaluation questions'
        };
        return {
            'student-to-professor': {
                sections: [defaultSection],
                questions: parsed['student-to-professor'].map((q, idx) => ({ ...q, sectionId: defaultSection.id, order: idx + 1 }))
            },
            'professor-to-professor': {
                sections: [],
                questions: parsed['professor-to-professor'] ? parsed['professor-to-professor'].map((q, idx) => ({ ...q, sectionId: null, order: idx + 1 })) : []
            },
            'supervisor-to-professor': {
                sections: [],
                questions: parsed['supervisor-to-professor'] ? parsed['supervisor-to-professor'].map((q, idx) => ({ ...q, sectionId: null, order: idx + 1 })) : []
            }
        };
    }

    const normalized = { ...buildEmptyQuestionsData(), ...parsed };
    Object.keys(normalized).forEach(type => {
        if (!normalized[type].sections) normalized[type].sections = [];
        if (!normalized[type].questions) normalized[type].questions = [];
    });
    return normalized;
}

/**
 * Generate sample questions for each questionnaire type
 */
function generateSampleQuestions() {
    return buildSampleQuestionsData();
}

function buildSampleQuestionsData() {
    return {
        'student-to-professor': { sections: [], questions: [] },
        'professor-to-professor': { sections: [], questions: [] },
        'supervisor-to-professor': { sections: [], questions: [] }
    };
}

/**
 * Handle questionnaire type change
 */
function handleQuestionnaireTypeChange() {
    const select = document.getElementById('questionnaire-type-select');
    if (select) {
        currentQuestionnaireType = select.value;
        updateFormHeader(currentQuestionnaireType);
        renderQuestions();
    }
}

/**
 * Update form header based on questionnaire type
 */
function updateFormHeader(type) {
    const titleEl = document.getElementById('form-title-preview');
    const descEl = document.getElementById('form-description-preview');

    const header = getQuestionnaireHeader(type);

    if (titleEl) titleEl.textContent = header.title;
    if (descEl) descEl.textContent = header.description;
}

function getQuestionnaireHeader(type) {
    const defaults = DEFAULT_QUESTIONNAIRE_HEADERS[type] || DEFAULT_QUESTIONNAIRE_HEADERS['student-to-professor'];
    const currentData = questionsData[type] || { sections: [], questions: [] };
    const header = currentData.header || {};
    return {
        title: header.title ?? defaults.title,
        description: header.description ?? defaults.description
    };
}

function saveQuestionnaireHeader(type, updates) {
    const currentData = questionsData[type] || { sections: [], questions: [] };
    const existingHeader = getQuestionnaireHeader(type);
    currentData.header = { ...existingHeader, ...updates };
    questionsData[type] = currentData;
    persistQuestionsData();
}

function setupFormHeaderEditing() {
    const titleEl = document.getElementById('form-title-preview');
    const descEl = document.getElementById('form-description-preview');
    if (!titleEl && !descEl) return;

    if (titleEl) {
        titleEl.addEventListener('input', function () {
            if (!isQuestionnaireEditable()) return;
            saveQuestionnaireHeader(currentQuestionnaireType, { title: titleEl.textContent.trim() });
        });
    }

    if (descEl) {
        descEl.addEventListener('input', function () {
            if (!isQuestionnaireEditable()) return;
            saveQuestionnaireHeader(currentQuestionnaireType, { description: descEl.textContent.trim() });
        });
    }
}

/**
 * Load and display questionnaire
 */
function loadQuestionnaire() {
    // Set default type and update header
    const questionnaireTypeSelect = document.getElementById('questionnaire-type-select');
    if (questionnaireTypeSelect) {
        currentQuestionnaireType = questionnaireTypeSelect.value;
        updateFormHeader(currentQuestionnaireType);
    }
    renderQuestions();
}

/**
 * Render questions list with sections
 */
function renderQuestions() {
    const questionsList = document.getElementById('questions-list');
    if (!questionsList) return;

    // Get data for current questionnaire type
    const currentData = questionsData[currentQuestionnaireType] || { sections: [], questions: [] };
    const sections = currentData.sections || [];
    const questions = currentData.questions || [];

    if (sections.length === 0 && questions.length === 0) {
        questionsList.innerHTML = `
            <div class="empty-questions">
                <i class="fas fa-clipboard-question"></i>
                <p>No sections or questions yet. Click "Add Section" to create your first section, or "Add Question" to create a question.</p>
            </div>
        `;
        return;
    }

    // Sort sections by letter
    const sortedSections = [...sections].sort((a, b) => (a.letter || '').localeCompare(b.letter || ''));

    let html = '';
    let globalQuestionIndex = 0;

    sortedSections.forEach(section => {
        // Get questions for this section
        const sectionQuestions = questions
            .filter(q => q.sectionId === section.id)
            .sort((a, b) => (a.order || 0) - (b.order || 0));

        html += `
            <div class="question-section" data-section-id="${section.id}">
                <div class="section-header">
                    <div class="section-title-group">
                        <div class="section-letter-badge" aria-label="Section ${section.letter}">
                            <span class="section-letter-label">Section</span>
                            <span class="section-letter-value">${section.letter}</span>
                        </div>
                        <div class="section-title-content">
                            <p class="section-title-label">Section Title</p>
                            <h2 class="section-title">${section.title}</h2>
                            <p class="section-description">${section.description}</p>
                        </div>
                    </div>
                    <div class="section-actions">
                        <button class="action-btn edit" onclick="editSection(${section.id})" title="Edit Section">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button class="action-btn delete" onclick="deleteSection(${section.id})" title="Delete Section">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <div class="section-questions">
                    ${sectionQuestions.length > 0
                ? sectionQuestions.map((question, idx) => {
                    globalQuestionIndex++;
                    return `
                                <div class="question-item" data-id="${question.id}">
                                    <div class="question-number">${globalQuestionIndex}</div>
                                    <div class="question-content">
                                        <div class="question-header">
                                            <h3 class="question-text">${question.text}</h3>
                                            ${question.required ? '<span class="required-badge">Required</span>' : ''}
                                        </div>
                                        <div class="question-preview">
                                            ${question.type === 'rating'
                            ? renderRatingPreview(question)
                            : renderQualitativePreview(question)
                        }
                                        </div>
                                        <div class="question-actions">
                                            <button class="action-btn edit" onclick="editQuestion(${question.id})" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <button class="action-btn delete" onclick="deleteQuestion(${question.id})" title="Delete">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                            ${idx > 0 ? `<button class="action-btn move-up" onclick="moveQuestion(${question.id}, 'up')" title="Move Up">
                                                <i class="fas fa-arrow-up"></i>
                                            </button>` : ''}
                                            ${idx < sectionQuestions.length - 1 ? `<button class="action-btn move-down" onclick="moveQuestion(${question.id}, 'down')" title="Move Down">
                                                <i class="fas fa-arrow-down"></i>
                                            </button>` : ''}
                                        </div>
                                    </div>
                                </div>
                            `;
                }).join('')
                : '<div class="empty-section-message"><p><i class="fas fa-info-circle"></i> No questions in this section yet. Click "Add Question" to add questions to this section.</p></div>'
            }
                </div>
            </div>
        `;
    });

    // Questions without sections (if any)
    const questionsWithoutSection = questions.filter(q => !q.sectionId);
    if (questionsWithoutSection.length > 0) {
        questionsWithoutSection.sort((a, b) => (a.order || 0) - (b.order || 0));
        questionsWithoutSection.forEach((question, idx) => {
            globalQuestionIndex++;
            html += `
                <div class="question-item" data-id="${question.id}">
                    <div class="question-number">${globalQuestionIndex}</div>
                    <div class="question-content">
                        <div class="question-header">
                            <h3 class="question-text">${question.text}</h3>
                            ${question.required ? '<span class="required-badge">Required</span>' : ''}
                        </div>
                        <div class="question-preview">
                            ${question.type === 'rating'
                    ? renderRatingPreview(question)
                    : renderQualitativePreview(question)
                }
                        </div>
                        <div class="question-actions">
                            <button class="action-btn edit" onclick="editQuestion(${question.id})" title="Edit">
                                <i class="fas fa-edit"></i>
                            </button>
                            <button class="action-btn delete" onclick="deleteQuestion(${question.id})" title="Delete">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                </div>
            `;
        });
    }

    questionsList.innerHTML = html || `
        <div class="empty-questions">
            <i class="fas fa-clipboard-question"></i>
            <p>No questions in sections yet. Click "Add Question" to create your first question.</p>
        </div>
    `;
}

/**
 * Render rating question preview
 */
function renderRatingPreview(question) {
    const scale = question.ratingScale || '1-5';
    const maxRating = parseInt(scale.split('-')[1]) || 5;
    const numbers = [];
    for (let i = maxRating; i >= 1; i--) {
        numbers.push(i);
    }
    return `
        <div class="rating-preview">
            <div class="number-rating-preview">
                ${numbers.map(n => `<span class="rating-num-box">${n}</span>`).join('')}
            </div>
            <span class="rating-label">${maxRating} = Excellent, 1 = Poor</span>
        </div>
    `;
}

/**
 * Render qualitative question preview
 */
function renderQualitativePreview(question) {
    const maxLength = question.maxLength || 500;
    return `
        <div class="qualitative-preview">
            <textarea disabled placeholder="Enter your response here..." rows="4" maxlength="${maxLength}"></textarea>
            <span class="char-count">0 / ${maxLength} characters</span>
        </div>
    `;
}

/**
 * Open add question modal
 */
function openAddQuestionModal() {
    if (!ensureQuestionnaireEditable()) return;
    const modal = document.getElementById('question-modal');
    const modalTitle = document.getElementById('question-modal-title');
    const form = document.getElementById('question-form');

    if (modal && modalTitle && form) {
        modalTitle.textContent = 'Add Question';
        form.reset();
        currentEditingQuestionId = null;
        document.getElementById('rating-options-group').style.display = 'none';
        document.getElementById('qualitative-options-group').style.display = 'none';

        // Populate sections dropdown
        populateSectionsDropdown();

        modal.style.display = 'flex';
    }
}

/**
 * Populate sections dropdown
 */
function populateSectionsDropdown() {
    const sectionSelect = document.getElementById('question-section');
    if (!sectionSelect) return;

    const currentData = questionsData[currentQuestionnaireType] || { sections: [], questions: [] };
    const sections = currentData.sections || [];

    sectionSelect.innerHTML = '<option value="">Select a section</option>';

    sections.forEach(section => {
        const option = document.createElement('option');
        option.value = section.id;
        option.textContent = `${section.letter}. ${section.title}`;
        sectionSelect.appendChild(option);
    });

    if (sections.length === 0) {
        const option = document.createElement('option');
        option.value = '';
        option.textContent = 'No sections available. Please create a section first.';
        option.disabled = true;
        option.selected = true;
        sectionSelect.innerHTML = '';
        sectionSelect.appendChild(option);
        sectionSelect.disabled = true;
    } else {
        sectionSelect.disabled = false;
    }
}

/**
 * Close question modal
 */
function closeQuestionModal() {
    const modal = document.getElementById('question-modal');
    if (modal) {
        modal.style.display = 'none';
        currentEditingQuestionId = null;
    }
}

/**
 * Handle question type change
 */
function handleQuestionTypeChange() {
    const questionType = document.getElementById('question-type').value;
    const ratingGroup = document.getElementById('rating-options-group');
    const qualitativeGroup = document.getElementById('qualitative-options-group');

    if (questionType === 'rating') {
        ratingGroup.style.display = 'block';
        qualitativeGroup.style.display = 'none';
    } else if (questionType === 'qualitative') {
        ratingGroup.style.display = 'none';
        qualitativeGroup.style.display = 'block';
    } else {
        ratingGroup.style.display = 'none';
        qualitativeGroup.style.display = 'none';
    }
}

/**
 * Handle question form submission
 */
function handleQuestionFormSubmit(e) {
    e.preventDefault();
    if (!ensureQuestionnaireEditable()) return;

    const sectionId = document.getElementById('question-section').value;
    if (!sectionId) {
        alert('Please select a section for this question.');
        return;
    }

    const formData = {
        text: document.getElementById('question-text').value,
        type: document.getElementById('question-type').value,
        required: document.getElementById('question-required').checked,
        sectionId: parseFloat(sectionId)
    };

    if (formData.type === 'rating') {
        const ratingMax = parseInt(document.getElementById('rating-max').value) || 5;
        formData.ratingScale = '1-' + ratingMax;
    } else if (formData.type === 'qualitative') {
        formData.maxLength = parseInt(document.getElementById('max-length').value) || 500;
    }

    // Get current questionnaire data
    const currentData = questionsData[currentQuestionnaireType] || { sections: [], questions: [] };
    const currentQuestions = currentData.questions || [];

    if (currentEditingQuestionId) {
        // Update existing question
        const questionIndex = currentQuestions.findIndex(q => q.id === currentEditingQuestionId);
        if (questionIndex !== -1) {
            currentQuestions[questionIndex] = {
                ...currentQuestions[questionIndex],
                ...formData
            };
        }
    } else {
        // Add new question
        // Get max order for questions in this section
        const sectionQuestions = currentQuestions.filter(q => q.sectionId === formData.sectionId);
        const maxOrder = sectionQuestions.length > 0
            ? Math.max(...sectionQuestions.map(q => q.order || 0))
            : 0;

        const newQuestion = {
            id: Date.now() + Math.random(),
            ...formData,
            order: maxOrder + 1
        };
        currentQuestions.push(newQuestion);
    }

    // Update questionsData with modified questions
    currentData.questions = currentQuestions;
    questionsData[currentQuestionnaireType] = currentData;

    // Save to localStorage
    persistQuestionsData();

    // Re-render and close modal
    renderQuestions();
    closeQuestionModal();
}

/**
 * Edit question
 */
function editQuestion(questionId) {
    if (!ensureQuestionnaireEditable()) return;
    const currentData = questionsData[currentQuestionnaireType] || { sections: [], questions: [] };
    const currentQuestions = currentData.questions || [];
    const question = currentQuestions.find(q => q.id === questionId);
    if (!question) return;

    const modal = document.getElementById('question-modal');
    const modalTitle = document.getElementById('question-modal-title');

    if (modal && modalTitle) {
        modalTitle.textContent = 'Edit Question';
        document.getElementById('question-text').value = question.text;
        document.getElementById('question-type').value = question.type;
        document.getElementById('question-required').checked = question.required || false;

        // Populate sections dropdown and select current section
        populateSectionsDropdown();
        if (question.sectionId) {
            document.getElementById('question-section').value = question.sectionId;
        }

        // Trigger type change to show appropriate options
        handleQuestionTypeChange();

        if (question.type === 'rating') {
            const scale = question.ratingScale || '1-5';
            const maxVal = parseInt(scale.split('-')[1]) || 5;
            document.getElementById('rating-max').value = maxVal;
        } else if (question.type === 'qualitative') {
            document.getElementById('max-length').value = question.maxLength || 500;
        }

        currentEditingQuestionId = questionId;
        modal.style.display = 'flex';
    }
}

/**
 * Delete question
 */
function deleteQuestion(questionId) {
    if (!ensureQuestionnaireEditable()) return;
    const currentData = questionsData[currentQuestionnaireType] || { sections: [], questions: [] };
    const currentQuestions = currentData.questions || [];
    const question = currentQuestions.find(q => q.id === questionId);
    if (!question) return;

    if (confirm(`Are you sure you want to delete this question?\n\n"${question.text}"\n\nThis action cannot be undone.`)) {
        const updatedQuestions = currentQuestions.filter(q => q.id !== questionId);
        // Reorder remaining questions in the same section
        if (question.sectionId) {
            const sectionQuestions = updatedQuestions.filter(q => q.sectionId === question.sectionId);
            sectionQuestions.forEach((q, index) => {
                q.order = index + 1;
            });
        }
        currentData.questions = updatedQuestions;
        questionsData[currentQuestionnaireType] = currentData;
        persistQuestionsData();
        renderQuestions();
    }
}

/**
 * Move question up or down within its section
 */
function moveQuestion(questionId, direction) {
    if (!ensureQuestionnaireEditable()) return;
    const currentData = questionsData[currentQuestionnaireType] || { sections: [], questions: [] };
    const currentQuestions = currentData.questions || [];
    const question = currentQuestions.find(q => q.id === questionId);
    if (!question || !question.sectionId) return;

    // Get questions in the same section
    const sectionQuestions = currentQuestions
        .filter(q => q.sectionId === question.sectionId)
        .sort((a, b) => (a.order || 0) - (b.order || 0));

    const questionIndex = sectionQuestions.findIndex(q => q.id === questionId);
    if (questionIndex === -1) return;

    if (direction === 'up' && questionIndex > 0) {
        const temp = sectionQuestions[questionIndex].order;
        sectionQuestions[questionIndex].order = sectionQuestions[questionIndex - 1].order;
        sectionQuestions[questionIndex - 1].order = temp;
    } else if (direction === 'down' && questionIndex < sectionQuestions.length - 1) {
        const temp = sectionQuestions[questionIndex].order;
        sectionQuestions[questionIndex].order = sectionQuestions[questionIndex + 1].order;
        sectionQuestions[questionIndex + 1].order = temp;
    }

    // Update questionsData
    currentData.questions = currentQuestions;
    questionsData[currentQuestionnaireType] = currentData;
    persistQuestionsData();
    renderQuestions();
}

/**
 * Save questionnaire
 */
function saveQuestionnaire() {
    if (!ensureQuestionnaireEditable()) return;
    const currentData = questionsData[currentQuestionnaireType] || { sections: [], questions: [] };
    if (currentData.sections.length === 0 && currentData.questions.length === 0) {
        alert('Please add at least one section or question before saving.');
        return;
    }

    const saved = persistQuestionsData();
    if (saved) {
        alert('Questionnaire saved successfully!');
        return;
    }

    alert('Questionnaire could not be saved to the database. Check Apache/MySQL, then try again.');
}

/**
 * Section Management Functions
 */

/**
 * Open add section modal
 */
function openAddSectionModal() {
    if (!ensureQuestionnaireEditable()) return;
    const modal = document.getElementById('section-modal');
    const modalTitle = document.getElementById('section-modal-title');
    const form = document.getElementById('section-form');

    if (modal && modalTitle && form) {
        modalTitle.textContent = 'Add Section';
        form.reset();
        currentEditingSectionId = null;
        modal.style.display = 'flex';
        // Focus on first input after a brief delay
        setTimeout(() => {
            const letterInput = document.getElementById('section-letter');
            if (letterInput) letterInput.focus();
        }, 100);
    }
}

/**
 * Close section modal
 */
function closeSectionModal() {
    const modal = document.getElementById('section-modal');
    if (modal) {
        modal.style.display = 'none';
        currentEditingSectionId = null;
    }
}

/**
 * Handle section form submission
 */
function handleSectionFormSubmit(e) {
    e.preventDefault();
    if (!ensureQuestionnaireEditable()) return;

    const letterInput = document.getElementById('section-letter');
    const formData = {
        letter: letterInput.value.toUpperCase().trim(),
        title: document.getElementById('section-title').value.trim(),
        description: document.getElementById('section-description').value.trim()
    };

    // Validate letter
    if (!/^[A-Z]$/.test(formData.letter)) {
        alert('Please enter a valid single letter (A-Z).');
        letterInput.focus();
        return;
    }

    // Get current questionnaire data
    const currentData = questionsData[currentQuestionnaireType] || { sections: [], questions: [] };
    const currentSections = currentData.sections || [];

    // Check if letter already exists
    if (currentEditingSectionId) {
        const existingSection = currentSections.find(s => s.id === currentEditingSectionId);
        if (existingSection && existingSection.letter !== formData.letter) {
            const letterExists = currentSections.some(s => s.id !== currentEditingSectionId && s.letter === formData.letter);
            if (letterExists) {
                alert(`Section with letter "${formData.letter}" already exists.`);
                return;
            }
        }
    } else {
        const letterExists = currentSections.some(s => s.letter === formData.letter);
        if (letterExists) {
            alert(`Section with letter "${formData.letter}" already exists.`);
            return;
        }
    }

    if (currentEditingSectionId) {
        // Update existing section
        const sectionIndex = currentSections.findIndex(s => s.id === currentEditingSectionId);
        if (sectionIndex !== -1) {
            currentSections[sectionIndex] = {
                ...currentSections[sectionIndex],
                ...formData
            };
        }
    } else {
        // Add new section
        const newSection = {
            id: Date.now() + Math.random(),
            ...formData
        };
        currentSections.push(newSection);
    }

    // Update questionsData
    currentData.sections = currentSections;
    questionsData[currentQuestionnaireType] = currentData;

    // Save to localStorage
    persistQuestionsData();

    // Re-render and close modal
    renderQuestions();
    closeSectionModal();
}

/**
 * Edit section
 */
function editSection(sectionId) {
    if (!ensureQuestionnaireEditable()) return;
    const currentData = questionsData[currentQuestionnaireType] || { sections: [], questions: [] };
    const currentSections = currentData.sections || [];
    const section = currentSections.find(s => s.id === sectionId);
    if (!section) return;

    const modal = document.getElementById('section-modal');
    const modalTitle = document.getElementById('section-modal-title');

    if (modal && modalTitle) {
        modalTitle.textContent = 'Edit Section';
        document.getElementById('section-letter').value = section.letter;
        document.getElementById('section-title').value = section.title;
        document.getElementById('section-description').value = section.description;
        currentEditingSectionId = sectionId;
        modal.style.display = 'flex';
        // Focus on first input after a brief delay
        setTimeout(() => {
            const letterInput = document.getElementById('section-letter');
            if (letterInput) letterInput.focus();
        }, 100);
    }
}

/**
 * Delete section
 */
function deleteSection(sectionId) {
    if (!ensureQuestionnaireEditable()) return;
    const currentData = questionsData[currentQuestionnaireType] || { sections: [], questions: [] };
    const currentSections = currentData.sections || [];
    const currentQuestions = currentData.questions || [];
    const section = currentSections.find(s => s.id === sectionId);
    if (!section) return;

    // Check if section has questions
    const sectionQuestions = currentQuestions.filter(q => q.sectionId === sectionId);
    if (sectionQuestions.length > 0) {
        if (!confirm(`This section has ${sectionQuestions.length} question(s). Deleting it will also delete all questions in this section.\n\nAre you sure you want to delete section "${section.letter}. ${section.title}"?\n\nThis action cannot be undone.`)) {
            return;
        }
    } else {
        if (!confirm(`Are you sure you want to delete section "${section.letter}. ${section.title}"?\n\nThis action cannot be undone.`)) {
            return;
        }
    }

    // Remove section and its questions
    const updatedSections = currentSections.filter(s => s.id !== sectionId);
    const updatedQuestions = currentQuestions.filter(q => q.sectionId !== sectionId);

    currentData.sections = updatedSections;
    currentData.questions = updatedQuestions;
    questionsData[currentQuestionnaireType] = currentData;

    persistQuestionsData();
    renderQuestions();
}

// Make functions globally available
window.editQuestion = editQuestion;
window.deleteQuestion = deleteQuestion;
window.moveQuestion = moveQuestion;
window.editSection = editSection;
window.deleteSection = deleteSection;

// Make functions globally available for onclick handlers
window.viewProfessorDetails = viewProfessorDetails;
window.editProfessor = editProfessor;
window.deleteProfessor = deleteProfessor;
window.viewProfessorAnalytics = viewProfessorAnalytics;
window.handleAISummarization = handleAISummarization;

// Export functions for future use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        checkAuthentication,
        handleLogout,
        clearUserSession,
        getUserSession,
        handleAddUser,
        handleEditUser,
        handleDeleteUser,
        handleSettingAction,
        updateOverviewCards,
        loadUserManagement
    };
}
