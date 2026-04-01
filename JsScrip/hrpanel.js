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

// Users loaded from PHP API (or SharedData fallback)
let adminUsers = [];
let hrNotificationHandlersBound = false;
let hrAnnouncementComposerReady = false;
let hrUsersRefreshPromise = null;
let hrUsersLastRefreshAt = 0;
let hrEvaluationOverviewChartInstance = null;
let hrSemestralPerformanceChartInstance = null;
const HR_USERS_REFRESH_INTERVAL_MS = 30000;

/**
 * Fetch users from PHP API, with SharedData fallback
 */
function fetchUsersFromApi(campus = 'all', search = '') {
    const params = new URLSearchParams();
    if (campus) params.set('campus', campus);
    if (search) params.set('search', search);

    return fetch(`../api/users.php?${params.toString()}`, { cache: 'no-store' })
        .then(response => {
            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }
            return response.json();
        })
        .then(payload => {
            adminUsers = Array.isArray(payload && payload.users) ? payload.users : [];
            return adminUsers;
        })
        .catch(error => {
            console.warn('[HRPanel] Falling back to SharedData users:', error);
            adminUsers = SharedData.getUsers();

            let filtered = [...adminUsers];
            if (campus && campus !== 'all') {
                filtered = filtered.filter(u => u.campus === campus);
            }
            if (search) {
                const query = search.toLowerCase();
                filtered = filtered.filter(u =>
                    (u.name && u.name.toLowerCase().includes(query)) ||
                    (u.email && u.email.toLowerCase().includes(query)) ||
                    (u.department && u.department.toLowerCase().includes(query))
                );
            }

            adminUsers = filtered;
            return adminUsers;
        });
}

function refreshHrUsersInBackground(force = false) {
    const now = Date.now();
    if (!force && now - hrUsersLastRefreshAt < HR_USERS_REFRESH_INTERVAL_MS) {
        return Promise.resolve(adminUsers);
    }
    if (hrUsersRefreshPromise) {
        return hrUsersRefreshPromise;
    }

    hrUsersRefreshPromise = fetchUsersFromApi('all', '')
        .then(() => {
            hrUsersLastRefreshAt = Date.now();
            loadProfessorsData();
            renderProfessors();
            return adminUsers;
        })
        .catch(() => adminUsers)
        .finally(() => {
            hrUsersRefreshPromise = null;
        });

    return hrUsersRefreshPromise;
}

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
    renderHrSystemNotifications();
    setupHrAnnouncementComposer();
    setupProfilePhotoUpload();
    setupProfileActions();
    setupSemesterSettings();
    setupEvalPeriods();
    setupProfessorManagement();
    setupProfessorRanking();
    renderProfessorDepartmentOptions();
    renderProfessorDepartmentTabs();
    setupQuestionnaire();
    setupHrSharedDataBindings();

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
            renderHrSystemNotifications();
        }
        if (e.key === SharedData.KEYS.ANNOUNCEMENTS) {
            renderHrSystemNotifications();
        }
    });
    updateOverviewCards();
    renderHrDashboardTopCharts();
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
    const navLinks = document.querySelectorAll('.sidebar-nav .nav-link[data-view]');

    navLinks.forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault();

            // Remove active class from all links
            navLinks.forEach(l => l.classList.remove('active'));

            // Add active class to clicked link
            this.classList.add('active');

            const viewId = this.getAttribute('data-view') || 'dashboard';
            handleNavigation(viewId);
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

function isContentViewVisible(viewId) {
    const view = document.getElementById(viewId);
    return !!(view && view.style.display !== 'none');
}

/**
 * Handle navigation to different sections
 * @param {string} section - Section name
 */
function handleNavigation(section) {
    const raw = String(section || '').trim();
    const lower = raw.toLowerCase();
    const viewId = (
        lower === 'dashboard' ? 'dashboard' :
            (lower === 'users' || lower === 'user management') ? 'users' :
                (lower === 'activity-log' || lower === 'activity log') ? 'activity-log' :
                    (lower === 'settings' || lower === 'evaluation settings' || lower === 'system settings') ? 'settings' :
                        (lower === 'reports' || lower === 'reports & analytics') ? 'reports' :
                            (lower === 'questionnaire') ? 'questionnaire' :
                                (lower === 'profile') ? 'profile' :
                                    (lower === 'change-password' || lower === 'change password') ? 'change-password' :
                                        'dashboard'
    );
    const pageTitleMap = {
        'dashboard': 'Dashboard',
        'users': 'User Management',
        'activity-log': 'Activity Log',
        'settings': 'Evaluation Settings',
        'reports': 'Reports',
        'questionnaire': 'Questionnaire',
        'profile': 'Profile',
        'change-password': 'Change Password',
    };

    // Hide all views first to ensure only one is visible
    hideAllViews();

    const dashboardView = document.getElementById('dashboard-view');
    const userManagementView = document.getElementById('user-management-view');
    const settingsView = document.getElementById('settings-view');
    const reportsView = document.getElementById('reports-view');
    const pageTitle = document.getElementById('mainPageTitle');

    if (pageTitle) {
        pageTitle.textContent = pageTitleMap[viewId] || 'Dashboard';
    }

    switch (viewId) {
        case 'dashboard':
            if (dashboardView) {
                dashboardView.style.display = 'block';
                updateOverviewCards();
                renderProfessorRanking();
                renderHrDashboardTopCharts();
                loadReports();
            }
            break;
        case 'users':
            if (userManagementView) {
                userManagementView.style.display = 'block';
                loadUserManagement();
            }
            break;
        case 'activity-log':
            const activityLogView = document.getElementById('activity-log-view');
            if (activityLogView) {
                activityLogView.style.display = 'block';
                loadHrActivityLog();
            }
            break;
        case 'settings':
            if (settingsView) {
                settingsView.style.display = 'block';
            }
            break;
        case 'reports':
            if (reportsView) {
                reportsView.style.display = 'block';
                loadReports();
            }
            break;
        case 'profile':
            const profileView = document.getElementById('profile-view');
            if (profileView) {
                profileView.style.display = 'block';
            }
            break;
        case 'questionnaire':
            const questionnaireView = document.getElementById('questionnaire-view');
            if (questionnaireView) {
                questionnaireView.style.display = 'block';
                loadQuestionnaire();
            }
            break;
        case 'change-password':
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
 * Load HR activity log from database-backed SharedData
 */
function loadHrActivityLog() {
    const tbody = document.getElementById('hr-activity-log-body');
    if (!tbody) return;

    const fromInput = document.getElementById('hr-activity-from');
    const toInput = document.getElementById('hr-activity-to');
    const searchBtn = document.getElementById('hr-activity-search-btn');
    const typeSelect = document.getElementById('hr-activity-type');
    const searchInput = document.getElementById('hr-activity-search');

    const escapeHtml = value => String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    const normalizeType = value => String(value || '').trim().toLowerCase();
    const parseTimestamp = value => {
        const raw = String(value || '').trim();
        if (!raw) return null;
        const parsed = new Date(raw);
        if (!Number.isNaN(parsed.getTime())) return parsed;
        const altParsed = new Date(raw.replace(' ', 'T'));
        return Number.isNaN(altParsed.getTime()) ? null : altParsed;
    };
    const formatTimestamp = value => {
        const parsed = parseTimestamp(value);
        return parsed ? parsed.toLocaleString() : String(value || '-');
    };
    const renderPrompt = message => {
        tbody.innerHTML = `
            <tr>
                <td colspan="7" style="text-align:center; padding:18px;">${escapeHtml(message)}</td>
            </tr>
        `;
    };

    const renderRows = (rows) => {
        if (!rows.length) {
            renderPrompt('No activity records found.');
            return;
        }
        tbody.innerHTML = rows.map(row => `
            <tr>
                <td>${escapeHtml(row.ip_address || '-')}</td>
                <td>${escapeHtml(formatTimestamp(row.timestamp))}</td>
                <td>${escapeHtml(row.description || '-')}</td>
                <td>${escapeHtml(row.action || '-')}</td>
                <td>${escapeHtml(row.role || '-')}</td>
                <td>${escapeHtml(row.user_id || '-')}</td>
                <td>${escapeHtml(row.log_id || row.id || '-')}</td>
            </tr>
        `).join('');
    };

    const runSearch = () => {
        const filters = {
            type: normalizeType(typeSelect ? typeSelect.value : 'all'),
            term: String(searchInput ? searchInput.value : '').trim(),
            from: String(fromInput ? fromInput.value : '').trim(),
            to: String(toInput ? toInput.value : '').trim(),
            limit: 200,
        };

        try {
            const rows = SharedData.searchActivityLog ? SharedData.searchActivityLog(filters) : [];
            renderRows(Array.isArray(rows) ? rows : []);
        } catch (error) {
            console.error('[HRPanel] Failed to search activity log.', error);
            renderPrompt('Failed to load activity records.');
        }
    };

    if (searchBtn) {
        searchBtn.onclick = runSearch;
    }

    renderPrompt('Click Search to load activity records.');
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

    const announcements = (SharedData.getAnnouncements && SharedData.getAnnouncements()) || [];
    const formatMeta = (item) => {
        const message = String(item && item.message || '').trim();
        const timestamp = String(item && item.timestamp || '').trim();
        const parsed = timestamp ? new Date(timestamp) : null;
        const dateLabel = parsed && !Number.isNaN(parsed.getTime()) ? parsed.toLocaleString() : timestamp;
        if (message && dateLabel) return `${message} • ${dateLabel}`;
        if (message) return message;
        if (dateLabel) return dateLabel;
        return 'No details';
    };

    if (badge) {
        const unreadCount = SharedData.getUnreadAnnouncementCount
            ? Number(SharedData.getUnreadAnnouncementCount()) || 0
            : announcements.length;
        badge.textContent = unreadCount;
    }

    if (announcements.length === 0) {
        list.innerHTML = `
            <div class="notification-item">
                <div class="notification-item-title">No notifications</div>
                <div class="notification-item-meta">Announcements will appear here.</div>
            </div>
        `;
    } else {
        list.innerHTML = announcements.map(item => `
            <div class="notification-item">
                <div class="notification-item-title">${item.title || 'Announcement'}</div>
                <div class="notification-item-meta">${formatMeta(item)}</div>
            </div>
        `).join('');
    }

    if (!hrNotificationHandlersBound) {
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

        hrNotificationHandlersBound = true;
    }
}

function normalizeHrAnnouncementComposerToken(value) {
    return String(value == null ? '' : value).trim().toLowerCase();
}

function populateHrAnnouncementComposerCampusOptions() {
    const campusSelect = document.getElementById('hr-announcement-target-campus');
    if (!campusSelect) return;

    const campuses = (SharedData.getCampuses ? SharedData.getCampuses() : []) || [];
    const previous = normalizeHrAnnouncementComposerToken(campusSelect.value);
    const realCampuses = (Array.isArray(campuses) ? campuses : []).filter(campus => {
        const id = normalizeHrAnnouncementComposerToken(campus && campus.id);
        return id && id !== 'all';
    });

    campusSelect.innerHTML = '<option value="">All Campuses</option>';
    realCampuses.forEach(campus => {
        const id = String(campus && campus.id || '').trim();
        if (!id) return;
        const name = String(campus && (campus.name || campus.id) || id).trim() || id;
        const option = document.createElement('option');
        option.value = id;
        option.textContent = name;
        campusSelect.appendChild(option);
    });

    if (previous && realCampuses.some(campus => normalizeHrAnnouncementComposerToken(campus && campus.id) === previous)) {
        campusSelect.value = previous;
    } else {
        campusSelect.value = '';
    }
}

function populateHrAnnouncementComposerProgramOptions() {
    const campusSelect = document.getElementById('hr-announcement-target-campus');
    const programSelect = document.getElementById('hr-announcement-target-program');
    if (!programSelect) return;

    const selectedCampus = normalizeHrAnnouncementComposerToken(campusSelect ? campusSelect.value : '');
    const previousRaw = String(programSelect.value || '').trim();
    const previous = normalizeHrAnnouncementComposerToken(previousRaw);
    const programs = (SharedData.getPrograms ? SharedData.getPrograms() : []) || [];

    const filteredPrograms = (Array.isArray(programs) ? programs : [])
        .filter(program => {
            if (!program) return false;
            if (!selectedCampus) return true;
            return normalizeHrAnnouncementComposerToken(program.campusSlug) === selectedCampus;
        })
        .sort((a, b) => String(a && a.programCode || '').localeCompare(String(b && b.programCode || '')));

    programSelect.innerHTML = '<option value="">All Programs</option>';
    filteredPrograms.forEach(program => {
        const code = String(program && program.programCode || '').trim();
        if (!code) return;
        const name = String(program && program.programName || '').trim();
        const option = document.createElement('option');
        option.value = code;
        option.textContent = code + (name ? ' - ' + name : '');
        programSelect.appendChild(option);
    });

    const matchedPrevious = previous
        ? filteredPrograms.find(program => normalizeHrAnnouncementComposerToken(program && program.programCode) === previous)
        : null;
    if (matchedPrevious) {
        programSelect.value = String(matchedPrevious.programCode || '').trim();
    } else {
        programSelect.value = '';
    }
}

function syncHrAnnouncementStudentCompletionVisibility() {
    const roleSelect = document.getElementById('hr-announcement-target-role');
    const completionWrap = document.getElementById('hr-announcement-student-completion-wrap');
    const completionSelect = document.getElementById('hr-announcement-student-completion');
    const isStudentTarget = normalizeHrAnnouncementComposerToken(roleSelect ? roleSelect.value : '') === 'student';
    if (completionWrap) completionWrap.style.display = isStudentTarget ? 'block' : 'none';
    if (completionSelect && !isStudentTarget) {
        completionSelect.value = 'all';
    }
}

function resetHrAnnouncementComposerForm() {
    const form = document.getElementById('hr-announcement-compose-form');
    const feedback = document.getElementById('hr-announcement-compose-feedback');
    if (form) form.reset();
    if (feedback) feedback.textContent = '';
    populateHrAnnouncementComposerCampusOptions();
    populateHrAnnouncementComposerProgramOptions();
    syncHrAnnouncementStudentCompletionVisibility();
}

function closeHrAnnouncementComposerModal() {
    const modal = document.getElementById('hr-announcement-compose-modal');
    if (!modal) return;
    modal.style.display = 'none';
    resetHrAnnouncementComposerForm();
}

function openHrAnnouncementComposerModal() {
    const modal = document.getElementById('hr-announcement-compose-modal');
    if (!modal) return;
    populateHrAnnouncementComposerCampusOptions();
    populateHrAnnouncementComposerProgramOptions();
    syncHrAnnouncementStudentCompletionVisibility();
    modal.style.display = 'flex';

    const titleInput = document.getElementById('hr-announcement-compose-title');
    if (titleInput) titleInput.focus();
}

function handleHrAnnouncementComposeSubmit(event) {
    if (event) event.preventDefault();

    const titleInput = document.getElementById('hr-announcement-compose-title');
    const messageInput = document.getElementById('hr-announcement-compose-message');
    const roleSelect = document.getElementById('hr-announcement-target-role');
    const campusSelect = document.getElementById('hr-announcement-target-campus');
    const programSelect = document.getElementById('hr-announcement-target-program');
    const completionSelect = document.getElementById('hr-announcement-student-completion');
    const feedback = document.getElementById('hr-announcement-compose-feedback');

    const title = String(titleInput ? titleInput.value : '').trim();
    const message = String(messageInput ? messageInput.value : '').trim();
    const role = normalizeHrAnnouncementComposerToken(roleSelect ? roleSelect.value : '');
    const campus = normalizeHrAnnouncementComposerToken(campusSelect ? campusSelect.value : '');
    const programCode = normalizeHrAnnouncementComposerToken(programSelect ? programSelect.value : '');
    const studentCompletion = role === 'student'
        ? normalizeHrAnnouncementComposerToken(completionSelect ? completionSelect.value : 'all')
        : 'all';

    if (!title || !message || !role) {
        if (feedback) {
            feedback.textContent = 'Please fill in title, message, and target role.';
        }
        return;
    }

    const session = getUserSession() || SharedData.getSession() || {};
    const createdAt = new Date().toISOString();
    const audience = {
        role: role,
        campus: campus,
        programCode: programCode,
        studentCompletion: studentCompletion === 'completed' || studentCompletion === 'not_completed'
            ? studentCompletion
            : 'all',
    };

    try {
        SharedData.addAnnouncement({
            title: title,
            message: message,
            audience: audience,
            createdAt: createdAt,
            timestamp: createdAt,
            createdByRole: normalizeHrAnnouncementComposerToken(session.role || 'hr') || 'hr',
            createdByUserId: String(session.userId || '').trim(),
            read: false,
        });

        if (SharedData.addActivityLogEntry) {
            const targetDetails = [
                role,
                campus ? `campus:${campus}` : '',
                programCode ? `program:${programCode}` : '',
                role === 'student' ? `completion:${audience.studentCompletion}` : ''
            ].filter(Boolean).join(', ');

            SharedData.addActivityLogEntry({
                action: 'Announcement Published',
                description: `Published announcement "${title}" for ${targetDetails}.`,
                type: 'announcement',
                userId: String(session.userId || '').trim(),
                username: String(session.username || '').trim(),
                role: String(session.role || 'hr').trim(),
            });
        }

        closeHrAnnouncementComposerModal();
        setupNotifications();
        renderHrSystemNotifications();
        alert('Announcement published successfully.');
    } catch (error) {
        console.error('[HRPanel] Failed to publish announcement.', error);
        if (feedback) {
            feedback.textContent = 'Failed to publish announcement. Please try again.';
        } else {
            alert('Failed to publish announcement.');
        }
    }
}

function setupHrAnnouncementComposer() {
    if (hrAnnouncementComposerReady) return;

    const openBtn = document.getElementById('hr-open-announcement-compose-btn');
    const modal = document.getElementById('hr-announcement-compose-modal');
    if (!openBtn || !modal) return;

    const closeBtn = document.getElementById('hr-close-announcement-compose-modal');
    const cancelBtn = document.getElementById('hr-cancel-announcement-compose-btn');
    const form = document.getElementById('hr-announcement-compose-form');
    const roleSelect = document.getElementById('hr-announcement-target-role');
    const campusSelect = document.getElementById('hr-announcement-target-campus');

    openBtn.addEventListener('click', openHrAnnouncementComposerModal);
    if (closeBtn) closeBtn.addEventListener('click', closeHrAnnouncementComposerModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeHrAnnouncementComposerModal);
    if (form) form.addEventListener('submit', handleHrAnnouncementComposeSubmit);
    if (roleSelect) roleSelect.addEventListener('change', syncHrAnnouncementStudentCompletionVisibility);
    if (campusSelect) campusSelect.addEventListener('change', populateHrAnnouncementComposerProgramOptions);

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeHrAnnouncementComposerModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.style.display === 'flex') {
            closeHrAnnouncementComposerModal();
        }
    });

    hrAnnouncementComposerReady = true;
    resetHrAnnouncementComposerForm();
}

function renderHrSystemNotifications() {
    const container = document.getElementById('hr-system-notifications');
    if (!container) return;

    const alerts = [];
    const periodLabels = {
        'student-professor': 'Student to Professor',
        'professor-professor': 'Professor to Professor',
        'supervisor-professor': 'Supervisor to Professor',
    };
    const dayMs = 24 * 60 * 60 * 1000;
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const escapeHtml = value => String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
    const parseDate = value => {
        const raw = String(value || '').trim();
        if (!raw) return null;
        const parsed = new Date(raw + 'T00:00:00');
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    };
    const formatDate = value => {
        const parsed = parseDate(value);
        return parsed
            ? parsed.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
            : 'Date not set';
    };

    const periods = (SharedData.getEvalPeriods && SharedData.getEvalPeriods()) || {};
    Object.keys(periodLabels).forEach(typeKey => {
        const period = periods[typeKey] || {};
        const startDate = parseDate(period.start);
        const endDate = parseDate(period.end);
        if (!startDate || !endDate) return;

        const daysToStart = Math.ceil((startDate.getTime() - today.getTime()) / dayMs);
        const daysToEnd = Math.ceil((endDate.getTime() - today.getTime()) / dayMs);
        const label = periodLabels[typeKey];

        if (daysToStart > 0) {
            alerts.push({
                message: `${label} evaluation opens in ${daysToStart} day${daysToStart === 1 ? '' : 's'}`,
                date: formatDate(period.start),
                sortKey: daysToStart,
            });
            return;
        }

        if (daysToEnd >= 0) {
            alerts.push({
                message: `${label} evaluation ends in ${daysToEnd} day${daysToEnd === 1 ? '' : 's'}`,
                date: formatDate(period.end),
                sortKey: daysToEnd,
            });
            return;
        }

        const daysSinceEnd = Math.abs(daysToEnd);
        if (daysSinceEnd <= 7) {
            alerts.push({
                message: `${label} evaluation ended ${daysSinceEnd} day${daysSinceEnd === 1 ? '' : 's'} ago`,
                date: formatDate(period.end),
                sortKey: 1000 + daysSinceEnd,
            });
        }
    });

    const announcements = (SharedData.getAnnouncementsForCurrentUser && SharedData.getAnnouncementsForCurrentUser()) ||
        (SharedData.getAnnouncements && SharedData.getAnnouncements()) || [];
    const latestAnnouncements = announcements
        .slice()
        .sort((a, b) => new Date(b.timestamp || 0) - new Date(a.timestamp || 0))
        .slice(0, 2);

    latestAnnouncements.forEach(item => {
        const title = String(item && item.title ? item.title : 'Announcement').trim();
        const timestamp = String(item && item.timestamp ? item.timestamp : '').trim();
        const parsed = timestamp ? new Date(timestamp) : null;
        const dateLabel = parsed && !Number.isNaN(parsed.getTime())
            ? parsed.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })
            : 'Recent update';
        alerts.push({
            message: title,
            date: dateLabel,
            sortKey: 2000,
        });
    });

    const sortedAlerts = alerts.sort((a, b) => a.sortKey - b.sortKey).slice(0, 3);

    if (!sortedAlerts.length) {
        container.innerHTML = `
            <div class="notification-alert">
                <div class="alert-icon">
                    <i class="fas fa-check"></i>
                </div>
                <div class="alert-content">
                    <div class="alert-message">No active system notifications.</div>
                    <div class="alert-date">${today.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' })}</div>
                </div>
            </div>
        `;
        return;
    }

    container.innerHTML = sortedAlerts.map(alert => `
        <div class="notification-alert">
            <div class="alert-icon">
                <i class="fas fa-exclamation-triangle"></i>
            </div>
            <div class="alert-content">
                <div class="alert-message">${escapeHtml(alert.message)}</div>
                <div class="alert-date">${escapeHtml(alert.date)}</div>
            </div>
        </div>
    `).join('');
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
    const context = buildHrEvaluationContext();
    const semesterId = context.currentSemester || 'all';
    const registration = buildHrStudentRegistrationStats(context, semesterId);
    const population = buildHrStudentPopulationCompletionStats(context, semesterId);
    const totalStudents = population.totalStudents;
    const completedEvaluations = registration.completed;
    const pendingEvaluations = registration.pending;
    const completedStudents = population.completedStudents;
    const completionRate = totalStudents > 0
        ? `${((completedStudents / totalStudents) * 100).toFixed(1)}%`
        : '0%';
    const activeProfessors = context.professorUsers.filter(professor => normalizeHrToken(professor.status) !== 'inactive').length;

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
        return normalizeHrToken(u && u.role) !== 'professor';
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
let currentAnalyticsProfessorId = null;
let hrSharedDataBindingsRegistered = false;

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
    const option = getSemesterOptions().find(item => item.id === id);
    return option ? option.label : 'Semester';
}

function getSemesterOptions() {
    const options = [{ id: 'all', label: 'All Semesters' }];
    const seen = new Set(['all']);
    const semesterList = SharedData.getSemesterList ? SharedData.getSemesterList() : [];

    semesterList.forEach(item => {
        const value = String(item && item.value || '').trim();
        if (!value || seen.has(value)) return;
        seen.add(value);
        options.push({
            id: value,
            label: String(item && item.label || value),
        });
    });

    const current = String(SharedData.getCurrentSemester ? SharedData.getCurrentSemester() : '').trim();
    if (current && !seen.has(current)) {
        options.push({ id: current, label: current });
    }

    return options;
}

function getEvaluationTypeOptions() {
    return EVALUATION_TYPE_OPTIONS;
}

function getEvaluationTypeMeta(id) {
    return EVALUATION_TYPE_OPTIONS.find(item => item.id === id) || EVALUATION_TYPE_OPTIONS[0];
}

function normalizeHrToken(value) {
    return String(value || '').trim().toLowerCase();
}

function normalizeHrUserIdToken(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    if (/^u\d+$/i.test(raw)) {
        return 'u' + raw.replace(/^u/i, '');
    }
    if (/^\d+$/.test(raw)) {
        return 'u' + String(parseInt(raw, 10));
    }
    return normalizeHrToken(raw);
}

function getHrEvaluationTypeKey(evaluation) {
    const role = normalizeHrToken(evaluation && (evaluation.evaluatorRole || evaluation.evaluationType));
    if (role === 'student' || role === 'student-to-professor') return 'student';
    if (role === 'professor' || role === 'peer' || role === 'professor-to-professor') return 'peer';
    if (role === 'dean' || role === 'hr' || role === 'supervisor' || role === 'supervisor-to-professor') return 'supervisor';
    return '';
}

function getHrQuestionnaireTypeCode(typeKey) {
    if (typeKey === 'student') return 'student-to-professor';
    if (typeKey === 'peer') return 'professor-to-professor';
    return 'supervisor-to-professor';
}

function isHrEvaluationInSemester(evaluation, semesterId) {
    const normalizedSemester = String(semesterId || '').trim();
    if (!normalizedSemester || normalizedSemester === 'all') return true;
    const evaluationSemester = String(evaluation && evaluation.semesterId || '').trim();
    if (!evaluationSemester) return true;
    return evaluationSemester === normalizedSemester;
}

function buildHrEvaluationContext() {
    const users = SharedData.getUsers ? SharedData.getUsers() : [];
    const evaluations = SharedData.getEvaluations ? SharedData.getEvaluations() : [];
    const studentEvaluationDrafts = SharedData.getStudentEvaluationDrafts ? SharedData.getStudentEvaluationDrafts() : [];
    const subjectManagement = SharedData.getSubjectManagement ? SharedData.getSubjectManagement() : { offerings: [], enrollments: [] };
    const questionnaires = SharedData.getQuestionnaires ? SharedData.getQuestionnaires() : {};
    const semesterList = SharedData.getSemesterList ? SharedData.getSemesterList() : [];
    const currentSemester = String(SharedData.getCurrentSemester ? SharedData.getCurrentSemester() : '').trim();

    const professorUsers = users.filter(user => normalizeHrToken(user && user.role) === 'professor');
    const supervisorUsers = users.filter(user => {
        const role = normalizeHrToken(user && user.role);
        return role === 'dean' || role === 'hr' || role === 'supervisor';
    });

    const professorIdSet = new Set();
    const professorNameMap = {};
    const professorEmployeeIdMap = {};

    professorUsers.forEach(user => {
        const normalizedId = normalizeHrUserIdToken(user && user.id);
        if (!normalizedId) return;
        professorIdSet.add(normalizedId);

        const nameToken = normalizeHrToken(user && user.name);
        if (nameToken && !professorNameMap[nameToken]) {
            professorNameMap[nameToken] = normalizedId;
        }

        const employeeToken = normalizeHrToken(user && user.employeeId);
        if (employeeToken && !professorEmployeeIdMap[employeeToken]) {
            professorEmployeeIdMap[employeeToken] = normalizedId;
        }
    });

    const offerings = Array.isArray(subjectManagement.offerings) ? subjectManagement.offerings : [];
    const enrollments = Array.isArray(subjectManagement.enrollments) ? subjectManagement.enrollments : [];
    const offeringsById = {};
    offerings.forEach(offering => {
        const offeringId = String(offering && offering.id || '').trim();
        if (offeringId) offeringsById[offeringId] = offering;
    });

    return {
        users,
        evaluations: Array.isArray(evaluations) ? evaluations : [],
        studentEvaluationDrafts: Array.isArray(studentEvaluationDrafts) ? studentEvaluationDrafts : [],
        questionnaires: questionnaires || {},
        semesterList: Array.isArray(semesterList) ? semesterList : [],
        currentSemester,
        offerings,
        enrollments,
        offeringsById,
        professorUsers,
        supervisorUsers,
        professorIdSet,
        professorNameMap,
        professorEmployeeIdMap,
    };
}

function resolveHrProfessorIdToken(rawValue, context) {
    const normalizedId = normalizeHrUserIdToken(rawValue);
    if (normalizedId && context.professorIdSet.has(normalizedId)) {
        return normalizedId;
    }

    const token = normalizeHrToken(rawValue);
    if (!token) return '';
    if (context.professorEmployeeIdMap[token]) return context.professorEmployeeIdMap[token];
    if (context.professorNameMap[token]) return context.professorNameMap[token];

    if (token.includes(' - ')) {
        const head = normalizeHrToken(token.split(' - ')[0]);
        if (head && context.professorNameMap[head]) return context.professorNameMap[head];
    }

    return '';
}

function resolveHrEvaluationTargetProfessorId(evaluation, typeKey, context) {
    if (typeKey === 'student') {
        const offeringId = String(evaluation && evaluation.courseOfferingId || '').trim();
        const offering = context.offeringsById[offeringId];
        if (offering && offering.professorUserId) {
            const professorByOffering = resolveHrProfessorIdToken(offering.professorUserId, context);
            if (professorByOffering) return professorByOffering;
        }
    }

    const candidates = [
        evaluation && evaluation.targetProfessorId,
        evaluation && evaluation.targetId,
        evaluation && evaluation.colleagueId,
        evaluation && evaluation.professorId,
        evaluation && evaluation.professorUserId,
        evaluation && evaluation.targetProfessor,
        evaluation && evaluation.professorSubject,
    ];

    for (let index = 0; index < candidates.length; index += 1) {
        const candidate = candidates[index];
        const resolved = resolveHrProfessorIdToken(candidate, context);
        if (resolved) return resolved;
    }

    return '';
}

function buildHrQuestionSectionLookup(typeKey, context, semesterId) {
    const questionnaireType = getHrQuestionnaireTypeCode(typeKey);
    const questionnaires = context.questionnaires || {};
    const preferredSemester = String(semesterId || '').trim();
    const fallbackSemester = String(context.currentSemester || '').trim();

    let bucket = null;
    const candidateSemesters = [];
    if (preferredSemester) candidateSemesters.push(preferredSemester);
    if (fallbackSemester && fallbackSemester !== preferredSemester) candidateSemesters.push(fallbackSemester);
    Object.keys(questionnaires).forEach(semester => {
        if (!candidateSemesters.includes(semester)) candidateSemesters.push(semester);
    });

    for (let index = 0; index < candidateSemesters.length; index += 1) {
        const semester = candidateSemesters[index];
        if (questionnaires[semester] && questionnaires[semester][questionnaireType]) {
            bucket = questionnaires[semester][questionnaireType];
            break;
        }
    }

    const sections = Array.isArray(bucket && bucket.sections) ? bucket.sections.slice() : [];
    const questions = Array.isArray(bucket && bucket.questions) ? bucket.questions.slice() : [];
    sections.sort((a, b) => (Number(a && a.order) || 0) - (Number(b && b.order) || 0));
    questions.sort((a, b) => (Number(a && a.order) || 0) - (Number(b && b.order) || 0));

    const categoryOrder = [];
    const sectionTitleById = {};
    sections.forEach(section => {
        const title = String(section && (section.title || section.letter) || '').trim() || 'Untitled Section';
        if (!categoryOrder.includes(title)) categoryOrder.push(title);
        const sectionIdToken = normalizeHrToken(section && section.id);
        if (sectionIdToken) sectionTitleById[sectionIdToken] = title;
    });

    const questionToCategory = {};
    questions.forEach(question => {
        const questionToken = normalizeHrToken(question && question.id);
        if (!questionToken) return;
        const sectionToken = normalizeHrToken(question && question.sectionId);
        const category = sectionTitleById[sectionToken] || 'Unassigned';
        questionToCategory[questionToken] = category;
        if (!categoryOrder.includes(category)) categoryOrder.push(category);
    });

    return {
        categoryOrder,
        questionToCategory,
        fallbackCategory: 'Unassigned',
    };
}

function collectHrQualitativeResponses(evaluation) {
    const responses = [];
    const baseDateRaw = evaluation && (evaluation.submittedAt || evaluation.timestamp || '');
    const parsedDate = baseDateRaw ? new Date(baseDateRaw) : null;
    const dateLabel = parsedDate && !Number.isNaN(parsedDate.getTime())
        ? parsedDate.toLocaleDateString()
        : new Date().toLocaleDateString();
    const evaluatorName = String(
        evaluation && (evaluation.evaluatorName || evaluation.studentName || evaluation.evaluatorUsername) || 'Anonymous'
    ).trim() || 'Anonymous';
    const evaluatorIdentity = String(
        evaluation && (evaluation.studentNumber || evaluation.studentUserId || evaluation.studentId || evaluation.evaluatorId || evaluation.evaluatorUsername) || 'N/A'
    ).trim() || 'N/A';
    const semesterId = String(evaluation && evaluation.semesterId || '').trim();
    const prefix = String(
        evaluation && (evaluation.evaluationKey || evaluation.id || evaluation.submittedAt || Date.now())
    ).trim();

    const qualitative = evaluation && typeof evaluation.qualitative === 'object' && evaluation.qualitative
        ? evaluation.qualitative
        : {};
    Object.values(qualitative).forEach((value, index) => {
        const text = String(value || '').trim();
        if (!text) return;
        responses.push({
            id: `${prefix}-qual-${index}`,
            text,
            date: dateLabel,
            studentName: evaluatorName,
            studentNumber: evaluatorIdentity,
            semesterId,
        });
    });

    const comment = String(evaluation && evaluation.comments || '').trim();
    if (comment) {
        responses.push({
            id: `${prefix}-comment`,
            text: comment,
            date: dateLabel,
            studentName: evaluatorName,
            studentNumber: evaluatorIdentity,
            semesterId,
        });
    }

    return responses;
}

function aggregateHrEvaluationData(options) {
    const settings = options || {};
    const context = settings.context || buildHrEvaluationContext();
    const typeKey = settings.typeKey || 'student';
    const semesterId = settings.semesterId || 'all';
    const targetProfessorId = settings.targetProfessorId ? normalizeHrUserIdToken(settings.targetProfessorId) : '';
    const includeCategoryScores = !!settings.includeCategoryScores;

    const sectionLookup = includeCategoryScores
        ? buildHrQuestionSectionLookup(typeKey, context, semesterId)
        : { categoryOrder: [], questionToCategory: {}, fallbackCategory: 'Unassigned' };

    const categoryStats = {};
    sectionLookup.categoryOrder.forEach(category => {
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

    (context.evaluations || []).forEach(evaluation => {
        const evaluationType = getHrEvaluationTypeKey(evaluation);
        if (evaluationType !== typeKey) return;
        if (!isHrEvaluationInSemester(evaluation, semesterId)) return;

        const targetId = resolveHrEvaluationTargetProfessorId(evaluation, typeKey, context);
        if (targetProfessorId && targetId !== targetProfessorId) return;
        if (targetId) uniqueTargetProfessorIds.add(targetId);
        const fallbackTargetToken = normalizeHrToken(
            evaluation && (evaluation.targetProfessorId || evaluation.targetId || evaluation.colleagueId || evaluation.targetProfessor || evaluation.professorSubject)
        );
        if (fallbackTargetToken) uniqueTargetTokens.add(fallbackTargetToken);

        const raterToken = normalizeHrToken(
            evaluation && (evaluation.studentUserId || evaluation.studentId || evaluation.evaluatorId || evaluation.evaluatorUsername || evaluation.evaluatorName)
        );
        if (raterToken) uniqueRaterTokens.add(raterToken);

        totalEvaluations += 1;

        const ratings = (evaluation && typeof evaluation.ratings === 'object' && evaluation.ratings) ? evaluation.ratings : {};
        const evaluationValues = [];
        Object.keys(ratings).forEach(questionId => {
            const parsed = parseFloat(ratings[questionId]);
            if (!Number.isFinite(parsed)) return;

            const numericRating = clampNumber(parsed, 1, 5);
            evaluationValues.push(numericRating);
            totalRatingValue += numericRating;
            totalRatingCount += 1;

            if (includeCategoryScores) {
                const questionToken = normalizeHrToken(questionId);
                const category = sectionLookup.questionToCategory[questionToken] || sectionLookup.fallbackCategory;
                if (!categoryStats[category]) {
                    categoryStats[category] = { sum: 0, count: 0 };
                }
                categoryStats[category].sum += numericRating;
                categoryStats[category].count += 1;
            }
        });

        if (evaluationValues.length > 0) {
            const average = evaluationValues.reduce((sum, value) => sum + value, 0) / evaluationValues.length;
            const ratingBucket = clampNumber(Math.round(average), 1, 5);
            ratingDistribution[ratingBucket] = (ratingDistribution[ratingBucket] || 0) + 1;
        }

        qualitativeResponses = qualitativeResponses.concat(collectHrQualitativeResponses(evaluation));
    });

    const orderedCategories = sectionLookup.categoryOrder.concat(
        Object.keys(categoryStats).filter(category => !sectionLookup.categoryOrder.includes(category))
    );
    let categoryScores = orderedCategories.map(category => {
        const stat = categoryStats[category] || { sum: 0, count: 0 };
        return {
            category,
            score: stat.count > 0 ? parseFloat((stat.sum / stat.count).toFixed(1)) : 0,
        };
    });
    if (includeCategoryScores && categoryScores.length === 0) {
        categoryScores = [{ category: sectionLookup.fallbackCategory, score: 0 }];
    }

    return {
        averageRating: totalRatingCount > 0 ? parseFloat((totalRatingValue / totalRatingCount).toFixed(1)) : 0,
        totalEvaluations,
        ratingDistribution,
        categoryScores,
        uniqueTargetCount: Math.max(uniqueTargetProfessorIds.size, uniqueTargetTokens.size),
        uniqueRaterCount: uniqueRaterTokens.size,
        qualitativeResponses,
    };
}

function buildHrStudentRegistrationStats(context, semesterId) {
    const normalizedSemester = String(semesterId || '').trim();
    const activeOfferingIds = new Set(
        (context.offerings || [])
            .filter(offering => {
                if (!offering || !offering.isActive) return false;
                if (!normalizedSemester || normalizedSemester === 'all') return true;
                const offeringSemester = String(offering.semesterSlug || '').trim();
                return !offeringSemester || offeringSemester === normalizedSemester;
            })
            .map(offering => String(offering.id))
    );

    const expectedPairs = new Set();
    (context.enrollments || []).forEach(enrollment => {
        if (!enrollment) return;
        if (normalizeHrToken(enrollment.status) !== 'enrolled') return;
        const offeringId = String(enrollment.courseOfferingId || '').trim();
        const studentToken = normalizeHrToken(enrollment.studentUserId || enrollment.studentId || enrollment.studentNumber || enrollment.studentName);
        if (!offeringId || !studentToken || !activeOfferingIds.has(offeringId)) return;
        expectedPairs.add(`${studentToken}|${normalizeHrToken(offeringId)}`);
    });

    const completedPairs = new Set();
    (context.evaluations || []).forEach(evaluation => {
        if (getHrEvaluationTypeKey(evaluation) !== 'student') return;
        if (!isHrEvaluationInSemester(evaluation, normalizedSemester || 'all')) return;

        const offeringId = String(evaluation.courseOfferingId || '').trim();
        if (!offeringId || !activeOfferingIds.has(offeringId)) return;

        const studentToken = normalizeHrToken(
            evaluation.studentUserId || evaluation.studentId || evaluation.evaluatorId || evaluation.evaluatorUsername
        );
        if (!studentToken) return;

        const pairKey = `${studentToken}|${normalizeHrToken(offeringId)}`;
        if (expectedPairs.has(pairKey)) completedPairs.add(pairKey);
    });

    const total = expectedPairs.size;
    const completed = completedPairs.size;
    const pending = Math.max(total - completed, 0);

    return { total, completed, pending };
}

function buildHrActiveStudentCountForSemester(context, semesterId) {
    const normalizedSemester = String(semesterId || '').trim();
    const activeStudentIds = new Set(
        (context.users || [])
            .filter(user =>
                normalizeHrToken(user && user.role) === 'student' &&
                normalizeHrToken(user && user.status) !== 'inactive'
            )
            .map(user => normalizeHrUserIdToken(user && user.id))
            .filter(Boolean)
    );

    const validOfferingIds = new Set(
        (context.offerings || [])
            .filter(offering => {
                if (!offering || !offering.isActive) return false;
                if (!normalizedSemester || normalizedSemester === 'all') return true;
                const offeringSemester = String(offering.semesterSlug || '').trim();
                return !offeringSemester || offeringSemester === normalizedSemester;
            })
            .map(offering => String(offering.id))
    );

    const studentsInSemester = new Set();
    (context.enrollments || []).forEach(enrollment => {
        if (!enrollment) return;
        if (normalizeHrToken(enrollment.status) !== 'enrolled') return;
        const offeringId = String(enrollment.courseOfferingId || '').trim();
        if (!offeringId || !validOfferingIds.has(offeringId)) return;

        const studentUserId = normalizeHrUserIdToken(
            enrollment.studentUserId || enrollment.studentId || enrollment.studentNumber
        );
        if (!studentUserId || !activeStudentIds.has(studentUserId)) return;
        studentsInSemester.add(studentUserId);
    });

    return studentsInSemester.size;
}

function buildHrStudentPopulationCompletionStats(context, semesterId) {
    const normalizedSemester = String(semesterId || '').trim();
    const validOfferingIds = new Set(
        (context.offerings || [])
            .filter(offering => {
                if (!offering || !offering.isActive) return false;
                if (!normalizedSemester || normalizedSemester === 'all') return true;
                const offeringSemester = String(offering.semesterSlug || '').trim();
                return !offeringSemester || offeringSemester === normalizedSemester;
            })
            .map(offering => String(offering.id))
    );

    const enrolledStudents = new Set();
    (context.enrollments || []).forEach(enrollment => {
        if (!enrollment) return;
        if (normalizeHrToken(enrollment.status) !== 'enrolled') return;
        const offeringId = String(enrollment.courseOfferingId || '').trim();
        if (!offeringId || !validOfferingIds.has(offeringId)) return;

        const studentToken = normalizeHrToken(
            enrollment.studentUserId || enrollment.studentId || enrollment.studentNumber || enrollment.studentName
        );
        if (!studentToken) return;
        enrolledStudents.add(studentToken);
    });

    const completedStudents = new Set();
    (context.evaluations || []).forEach(evaluation => {
        if (getHrEvaluationTypeKey(evaluation) !== 'student') return;
        if (!isHrEvaluationInSemester(evaluation, normalizedSemester || 'all')) return;

        const offeringId = String(evaluation.courseOfferingId || '').trim();
        if (!offeringId || !validOfferingIds.has(offeringId)) return;

        const studentToken = normalizeHrToken(
            evaluation.studentUserId || evaluation.studentId || evaluation.evaluatorId || evaluation.evaluatorUsername
        );
        if (!studentToken || !enrolledStudents.has(studentToken)) return;
        completedStudents.add(studentToken);
    });

    const totalStudents = enrolledStudents.size;
    const completed = completedStudents.size;
    const notCompleted = Math.max(totalStudents - completed, 0);

    return {
        totalStudents,
        completedStudents: completed,
        notCompletedStudents: notCompleted,
    };
}

function buildHrExpectedStudentEvaluationPairs(context, semesterId) {
    const normalizedSemester = String(semesterId || '').trim();
    const activeOfferingIds = new Set(
        (context.offerings || [])
            .filter(offering => {
                if (!offering || !offering.isActive) return false;
                if (!normalizedSemester || normalizedSemester === 'all') return true;
                const offeringSemester = String(offering.semesterSlug || '').trim();
                return !offeringSemester || offeringSemester === normalizedSemester;
            })
            .map(offering => String(offering.id))
    );

    const expectedPairs = new Set();
    (context.enrollments || []).forEach(enrollment => {
        if (!enrollment) return;
        if (normalizeHrToken(enrollment.status) !== 'enrolled') return;

        const offeringId = String(enrollment.courseOfferingId || '').trim();
        if (!offeringId || !activeOfferingIds.has(offeringId)) return;

        const studentToken = normalizeHrToken(
            enrollment.studentUserId || enrollment.studentId || enrollment.studentNumber || enrollment.studentName
        );
        if (!studentToken) return;

        expectedPairs.add(`${studentToken}|${normalizeHrToken(offeringId)}`);
    });

    return {
        expectedPairs,
        activeOfferingIds,
    };
}

function buildHrStudentsCompletedAssignedForSemester(context, semesterId) {
    const expected = buildHrExpectedStudentEvaluationPairs(context, semesterId);
    const expectedCountByStudent = new Map();
    const completedCountByStudent = new Map();
    const completedPairs = new Set();

    expected.expectedPairs.forEach(pairKey => {
        const separatorIndex = pairKey.indexOf('|');
        const studentToken = separatorIndex >= 0 ? pairKey.slice(0, separatorIndex) : '';
        if (!studentToken) return;
        expectedCountByStudent.set(studentToken, (expectedCountByStudent.get(studentToken) || 0) + 1);
    });

    (context.evaluations || []).forEach(evaluation => {
        if (getHrEvaluationTypeKey(evaluation) !== 'student') return;
        if (!isHrEvaluationInSemester(evaluation, semesterId || 'all')) return;

        const offeringId = String(evaluation.courseOfferingId || '').trim();
        if (!offeringId || !expected.activeOfferingIds.has(offeringId)) return;

        const studentToken = normalizeHrToken(
            evaluation.studentUserId || evaluation.studentId || evaluation.evaluatorId || evaluation.evaluatorUsername
        );
        if (!studentToken) return;

        const pairKey = `${studentToken}|${normalizeHrToken(offeringId)}`;
        if (!expected.expectedPairs.has(pairKey) || completedPairs.has(pairKey)) return;
        completedPairs.add(pairKey);
        completedCountByStudent.set(studentToken, (completedCountByStudent.get(studentToken) || 0) + 1);
    });

    let completedAssignedStudents = 0;
    expectedCountByStudent.forEach((expectedCount, studentToken) => {
        const completedCount = completedCountByStudent.get(studentToken) || 0;
        if (expectedCount > 0 && completedCount >= expectedCount) {
            completedAssignedStudents += 1;
        }
    });

    return completedAssignedStudents;
}

function buildHrDashboardEvaluationOverview(context) {
    const semesterId = context.currentSemester || 'all';
    const expected = buildHrExpectedStudentEvaluationPairs(context, semesterId);

    const completedPairs = new Set();
    (context.evaluations || []).forEach(evaluation => {
        if (getHrEvaluationTypeKey(evaluation) !== 'student') return;
        if (!isHrEvaluationInSemester(evaluation, semesterId)) return;

        const offeringId = String(evaluation.courseOfferingId || '').trim();
        if (!offeringId || !expected.activeOfferingIds.has(offeringId)) return;

        const studentToken = normalizeHrToken(
            evaluation.studentUserId || evaluation.studentId || evaluation.evaluatorId || evaluation.evaluatorUsername
        );
        if (!studentToken) return;

        const pairKey = `${studentToken}|${normalizeHrToken(offeringId)}`;
        if (expected.expectedPairs.has(pairKey)) {
            completedPairs.add(pairKey);
        }
    });

    const pendingPairs = new Set();
    (context.studentEvaluationDrafts || []).forEach(draft => {
        if (!draft) return;
        if (!isHrEvaluationInSemester({ semesterId: draft.semesterId || '' }, semesterId)) return;

        const offeringId = String(draft.courseOfferingId || '').trim();
        if (!offeringId || !expected.activeOfferingIds.has(offeringId)) return;

        const studentToken = normalizeHrToken(draft.studentUserId || draft.studentId);
        if (!studentToken) return;

        const pairKey = `${studentToken}|${normalizeHrToken(offeringId)}`;
        if (!expected.expectedPairs.has(pairKey) || completedPairs.has(pairKey)) return;
        pendingPairs.add(pairKey);
    });

    const totalExpected = expected.expectedPairs.size;
    const completed = completedPairs.size;
    const pending = pendingPairs.size;
    const notStarted = Math.max(totalExpected - completed - pending, 0);

    return {
        labels: ['Completed', 'Pending', 'Not Started'],
        values: [completed, pending, notStarted],
        totalExpected,
        semesterId,
    };
}

function getHrLatestSemestersForTrend(context, limit = 4) {
    const desired = Number(limit) > 0 ? Number(limit) : 4;
    const orderedSemesters = [];
    const seen = new Set();
    (context.semesterList || []).forEach(item => {
        const value = String(item && item.value || '').trim();
        if (!value || seen.has(value)) return;
        seen.add(value);
        orderedSemesters.push({
            id: value,
            label: String(item && item.label || value),
        });
    });

    const currentSemester = String(context.currentSemester || '').trim();
    if (currentSemester && !seen.has(currentSemester)) {
        orderedSemesters.unshift({
            id: currentSemester,
            label: currentSemester,
        });
    }

    if (orderedSemesters.length > 0) {
        return orderedSemesters.slice(0, desired).reverse();
    }

    const latestBySemester = new Map();
    (context.evaluations || []).forEach(evaluation => {
        const semesterId = String(evaluation && evaluation.semesterId || '').trim();
        if (!semesterId) return;

        const rawTs = evaluation && (evaluation.submittedAt || evaluation.timestamp || '');
        const ts = Date.parse(rawTs);
        const score = Number.isFinite(ts) ? ts : 0;
        const previous = latestBySemester.get(semesterId);
        if (previous === undefined || score > previous) {
            latestBySemester.set(semesterId, score);
        }
    });

    return Array.from(latestBySemester.entries())
        .sort((a, b) => {
            if (b[1] !== a[1]) return b[1] - a[1];
            return String(b[0]).localeCompare(String(a[0]));
        })
        .slice(0, desired)
        .reverse()
        .map(entry => ({ id: entry[0], label: entry[0] }));
}

function buildHrSemestralPerformanceData(context) {
    const semesters = getHrLatestSemestersForTrend(context, 4);
    if (!semesters.length) {
        return {
            labels: ['No Semester Data'],
            values: [0],
        };
    }

    const values = semesters.map(semester => {
        const semesterId = String(semester.id || '').trim();
        return buildHrStudentsCompletedAssignedForSemester(context, semesterId);
    });

    return {
        labels: semesters.map(semester => String(semester.label || semester.id || '').trim() || String(semester.id || '')),
        values,
    };
}

function renderHrEvaluationOverviewChart(data) {
    if (typeof Chart === 'undefined') return;
    const chartCanvas = document.getElementById('hr-evaluation-overview-chart');
    if (!chartCanvas) return;

    if (hrEvaluationOverviewChartInstance) {
        hrEvaluationOverviewChartInstance.destroy();
        hrEvaluationOverviewChartInstance = null;
    }

    hrEvaluationOverviewChartInstance = new Chart(chartCanvas, {
        type: 'doughnut',
        data: {
            labels: data.labels,
            datasets: [{
                data: data.values,
                backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                borderWidth: 0
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

function renderHrSemestralPerformanceChart(data) {
    if (typeof Chart === 'undefined') return;
    const chartCanvas = document.getElementById('hr-semestral-performance-chart');
    if (!chartCanvas) return;

    if (hrSemestralPerformanceChartInstance) {
        hrSemestralPerformanceChartInstance.destroy();
        hrSemestralPerformanceChartInstance = null;
    }

    hrSemestralPerformanceChartInstance = new Chart(chartCanvas, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Students Completed Assigned Evaluations',
                data: data.values,
                backgroundColor: ['#667eea', '#7c8df0', '#5f78dd', '#4d66cf'],
                borderRadius: 8,
                borderWidth: 0
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        precision: 0
                    }
                },
                x: {
                    ticks: {
                        maxRotation: 0,
                        minRotation: 0,
                        callback: function (value) {
                            const label = String(this.getLabelForValue(value) || '');
                            const semesterYearMatch = label.match(/^(.*)\s(\d{4}-\d{4})$/);
                            if (semesterYearMatch) {
                                return [semesterYearMatch[1], semesterYearMatch[2]];
                            }
                            return label;
                        }
                    }
                }
            }
        }
    });
}

function renderHrDashboardTopCharts() {
    const context = buildHrEvaluationContext();
    const overview = buildHrDashboardEvaluationOverview(context);
    const semestral = buildHrSemestralPerformanceData(context);
    renderHrEvaluationOverviewChart(overview);
    renderHrSemestralPerformanceChart(semestral);
}

function buildHrStudentsEvaluatedCountMap(context, semesterId) {
    const countsByProfessor = {};
    const expectedPairsByProfessor = new Map();
    const completedPairsByProfessor = new Map();
    const fallbackPairsByProfessor = new Map();

    const ensureSet = (map, key) => {
        if (!map.has(key)) map.set(key, new Set());
        return map.get(key);
    };

    (context.enrollments || []).forEach(enrollment => {
        if (!enrollment) return;
        if (normalizeHrToken(enrollment.status) !== 'enrolled') return;

        const offeringId = String(enrollment.courseOfferingId || '').trim();
        const offering = context.offeringsById[offeringId];
        if (!offering || !offering.isActive) return;
        if (!isHrEvaluationInSemester({ semesterId: offering.semesterSlug || '' }, semesterId)) return;

        const professorId = resolveHrProfessorIdToken(offering.professorUserId, context);
        if (!professorId) return;

        const studentToken = normalizeHrToken(enrollment.studentUserId || enrollment.studentId || enrollment.studentNumber || enrollment.studentName);
        const offeringToken = normalizeHrToken(offeringId);
        if (!studentToken || !offeringToken) return;

        ensureSet(expectedPairsByProfessor, professorId).add(`${studentToken}|${offeringToken}`);
    });

    (context.evaluations || []).forEach(evaluation => {
        if (getHrEvaluationTypeKey(evaluation) !== 'student') return;
        if (!isHrEvaluationInSemester(evaluation, semesterId)) return;

        const professorId = resolveHrEvaluationTargetProfessorId(evaluation, 'student', context);
        if (!professorId) return;

        const studentToken = normalizeHrToken(
            evaluation.studentUserId || evaluation.studentId || evaluation.evaluatorId || evaluation.evaluatorUsername
        );
        if (!studentToken) return;

        const offeringToken = normalizeHrToken(evaluation.courseOfferingId);
        const expectedPairs = expectedPairsByProfessor.get(professorId);

        if (expectedPairs && expectedPairs.size > 0 && offeringToken) {
            const pairKey = `${studentToken}|${offeringToken}`;
            if (expectedPairs.has(pairKey)) {
                ensureSet(completedPairsByProfessor, professorId).add(pairKey);
            }
            return;
        }

        ensureSet(fallbackPairsByProfessor, professorId).add(`${studentToken}|${offeringToken || 'direct'}`);
    });

    context.professorUsers.forEach(user => {
        const professorId = normalizeHrUserIdToken(user && user.id);
        if (!professorId) return;

        const expectedCount = (expectedPairsByProfessor.get(professorId) || new Set()).size;
        const completedCount = (completedPairsByProfessor.get(professorId) || new Set()).size;
        const fallbackCount = (fallbackPairsByProfessor.get(professorId) || new Set()).size;

        countsByProfessor[professorId] = expectedCount > 0 ? completedCount : fallbackCount;
    });

    return countsByProfessor;
}

function getHrReportDataByType(typeKey, context, semesterId) {
    const aggregate = aggregateHrEvaluationData({
        context,
        typeKey,
        semesterId,
        includeCategoryScores: true,
    });
    return {
        categoryScores: aggregate.categoryScores,
        ratingDistribution: aggregate.ratingDistribution,
        averageRating: aggregate.averageRating,
        totalEvaluations: aggregate.totalEvaluations,
        evaluatedCount: aggregate.uniqueTargetCount,
    };
}

function getHrProfessorStudentTotals(context, professorId, semesterId) {
    const expectedPairs = new Set();

    (context.enrollments || []).forEach(enrollment => {
        if (!enrollment) return;
        if (normalizeHrToken(enrollment.status) !== 'enrolled') return;

        const offering = context.offeringsById[String(enrollment.courseOfferingId || '').trim()];
        if (!offering || !offering.isActive) return;
        if (!isHrEvaluationInSemester({ semesterId: offering.semesterSlug || '' }, semesterId)) return;

        const offeringProfessorId = resolveHrProfessorIdToken(offering.professorUserId, context);
        if (offeringProfessorId !== professorId) return;

        const studentToken = normalizeHrToken(enrollment.studentUserId || enrollment.studentId || enrollment.studentNumber || enrollment.studentName);
        const offeringToken = normalizeHrToken(enrollment.courseOfferingId);
        if (!studentToken || !offeringToken) return;
        expectedPairs.add(`${studentToken}|${offeringToken}`);
    });

    const completedPairs = new Set();
    (context.evaluations || []).forEach(evaluation => {
        if (getHrEvaluationTypeKey(evaluation) !== 'student') return;
        if (!isHrEvaluationInSemester(evaluation, semesterId)) return;

        const targetId = resolveHrEvaluationTargetProfessorId(evaluation, 'student', context);
        if (targetId !== professorId) return;

        const studentToken = normalizeHrToken(
            evaluation.studentUserId || evaluation.studentId || evaluation.evaluatorId || evaluation.evaluatorUsername
        );
        const offeringToken = normalizeHrToken(evaluation.courseOfferingId);
        if (!studentToken) return;

        if (expectedPairs.size > 0 && offeringToken) {
            const pairKey = `${studentToken}|${offeringToken}`;
            if (expectedPairs.has(pairKey)) {
                completedPairs.add(pairKey);
            }
            return;
        }

        completedPairs.add(`${studentToken}|${offeringToken || 'direct'}`);
    });

    return {
        totalRaters: expectedPairs.size,
        evaluatedPairs: completedPairs.size,
    };
}

function getHrProfessorEvaluationSnapshot(professorId, semesterId, evaluationType, contextInput) {
    const context = contextInput || buildHrEvaluationContext();
    const normalizedProfessorId = normalizeHrUserIdToken(professorId);
    const normalizedSemester = String(semesterId || 'all').trim() || 'all';
    const normalizedType = getEvaluationTypeMeta(evaluationType).id;
    const aggregate = aggregateHrEvaluationData({
        context,
        typeKey: normalizedType,
        semesterId: normalizedSemester,
        targetProfessorId: normalizedProfessorId,
        includeCategoryScores: false,
    });
    const meta = getEvaluationTypeMeta(normalizedType);

    if (normalizedType === 'student') {
        const studentTotals = getHrProfessorStudentTotals(context, normalizedProfessorId, normalizedSemester);
        const fallbackRaters = aggregate.uniqueRaterCount;
        const totalRaters = studentTotals.totalRaters > 0 ? studentTotals.totalRaters : fallbackRaters;
        const evaluatedCount = studentTotals.totalRaters > 0 ? studentTotals.evaluatedPairs : aggregate.uniqueRaterCount;
        return {
            totalRaters,
            evaluatedCount,
            notEvaluatedCount: Math.max(totalRaters - evaluatedCount, 0),
            averageRating: aggregate.averageRating,
            qualitativeResponses: aggregate.qualitativeResponses,
            meta,
        };
    }

    const activeProfessors = context.professorUsers.filter(user => normalizeHrToken(user.status) !== 'inactive');
    const activeSupervisors = context.supervisorUsers.filter(user => normalizeHrToken(user.status) !== 'inactive');
    const professorPool = Math.max(activeProfessors.length - 1, 0);
    const supervisorPool = activeSupervisors.length;
    let totalRaters = normalizedType === 'peer' ? professorPool : supervisorPool;
    if (totalRaters < aggregate.uniqueRaterCount) {
        totalRaters = aggregate.uniqueRaterCount;
    }

    const evaluatedCount = aggregate.uniqueRaterCount;
    return {
        totalRaters,
        evaluatedCount,
        notEvaluatedCount: Math.max(totalRaters - evaluatedCount, 0),
        averageRating: aggregate.averageRating,
        qualitativeResponses: aggregate.qualitativeResponses,
        meta,
    };
}

function setupHrSharedDataBindings() {
    if (hrSharedDataBindingsRegistered || !SharedData.onDataChange || !SharedData.KEYS) return;
    hrSharedDataBindingsRegistered = true;

    SharedData.onDataChange(function (key) {
        const keys = SharedData.KEYS;

        if (key === keys.QUESTIONNAIRES || key === keys.CURRENT_SEMESTER || key === keys.SEMESTER_LIST) {
            loadQuestionsData();
            setupSemesterPicker();
            updateFormHeader(currentQuestionnaireType);
            renderQuestions();
            applyQuestionnaireEditMode(isQuestionnaireEditable());
        }

        if (key === keys.EVAL_PERIODS) {
            const periods = SharedData.getEvalPeriods();
            ['student-professor', 'professor-professor', 'supervisor-professor'].forEach(type => {
                const startEl = document.getElementById(type + '-start');
                const endEl = document.getElementById(type + '-end');
                if (startEl && periods[type]) startEl.value = periods[type].start || '';
                if (endEl && periods[type]) endEl.value = periods[type].end || '';
            });
            renderHrSystemNotifications();
        }

        if (key === keys.USERS) {
            loadProfessorsData();
            renderProfessorDepartmentOptions();
            renderProfessorDepartmentTabs();
            if (isContentViewVisible('user-management-view')) {
                renderProfessors();
            }
            if (isContentViewVisible('dashboard-view')) {
                updateOverviewCards();
                renderProfessorRanking();
                renderHrDashboardTopCharts();
                loadReports();
            }
            if (isContentViewVisible('reports-view')) {
                loadReports();
            }
        }

        if (
            key === keys.EVALUATIONS ||
            key === keys.SUBJECT_MANAGEMENT ||
            key === keys.STUDENT_EVAL_DRAFTS ||
            key === keys.CURRENT_SEMESTER ||
            key === keys.SEMESTER_LIST ||
            key === keys.QUESTIONNAIRES
        ) {
            if (isContentViewVisible('dashboard-view')) {
                updateOverviewCards();
                renderProfessorRanking();
                renderHrDashboardTopCharts();
                loadReports();
            }
            if (isContentViewVisible('reports-view')) {
                loadReports();
            }
            if (isContentViewVisible('user-management-view')) {
                renderProfessors();
            }
            if (currentAnalyticsProfessorId) {
                const modal = document.getElementById('professor-analytics-modal');
                if (modal && modal.style.display === 'flex') {
                    viewProfessorAnalytics(currentAnalyticsProfessorId);
                }
            }
        }

        if (key === keys.ACTIVITY_LOG) {
            loadHrActivityLog();
        }

        if (key === keys.ANNOUNCEMENTS) {
            setupNotifications();
            renderHrSystemNotifications();
        }
    });
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
    // Always load from the canonical shared user snapshot.
    // Using filtered adminUsers can drop professors when later persisted.
    const sourceUsers = SharedData.getUsers();

    professorsData = sourceUsers.filter(function (u) {
        return String(u.role || '').toLowerCase() === 'professor';
    });

    professorsData = professorsData.map(professor => {
        const updated = { ...professor };
        if (!updated.employeeId) {
            updated.employeeId = deriveEmployeeIdFallback(updated.id);
        }
        if (!updated.employmentType) {
            updated.employmentType = 'Regular';
        }
        if (!updated.department && updated.institute) {
            updated.department = updated.institute;
        }
        if (updated.department) {
            const normalizedDepartment = String(updated.department).toUpperCase();
            updated.department = normalizedDepartment;
        }
        if (typeof updated.isActive !== 'boolean') {
            const normalizedStatus = String(updated.status || '').toLowerCase();
            updated.isActive = normalizedStatus === 'inactive' ? false : true;
        }
        if (!updated.status) {
            updated.status = updated.isActive ? 'active' : 'inactive';
        }
        ensureProfessorSemesterData(updated);

        return updated;
    });
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

    const context = buildHrEvaluationContext();
    const ranked = filtered
        .map(prof => {
            const snapshot = getHrProfessorEvaluationSnapshot(prof.id, 'all', 'student', context);
            const averageRating = parseFloat(snapshot.averageRating) || 0;
            const ratingPercent = Math.min(Math.max((averageRating / 5) * 100, 0), 100);
            return {
                ...prof,
                averageRating,
                evaluatedCount: snapshot.evaluatedCount || 0,
                ratingPercent,
            };
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
    // Render immediately from current SharedData cache.
    loadProfessorsData();
    renderProfessors();

    // Refresh from API in the background and re-render when new data arrives.
    setTimeout(() => refreshHrUsersInBackground(false), 0);
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

    professorsList.innerHTML = `
        <div class="professor-table-wrap">
            <table class="professor-table">
                <thead>
                    <tr>
                        <th>Professor Details</th>
                        <th>Email</th>
                        <th>Employee ID</th>
                        <th>Department</th>
                        <th>Position</th>
                        <th>Employment</th>
                        <th>Students Evaluated</th>
                        <th>Status</th>
                        <th class="actions-col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    ${filteredProfessors.map(professor => {
                        const studentsEvaluated = Number(professor.evaluatedCount || professor.evaluationsCount) || 0;
                        return `
                        <tr class="${professor.isActive === false ? 'inactive' : ''}" data-id="${professor.id}">
                            <td>
                                <div class="professor-table-name">
                                    <i class="fas fa-user-tie"></i>
                                    <span>${professor.name || 'N/A'}</span>
                                </div>
                            </td>
                            <td>${professor.email || 'N/A'}</td>
                            <td>${professor.employeeId || 'N/A'}</td>
                            <td><span class="dept-badge dept-${professor.department}">${professor.department || 'N/A'}</span></td>
                            <td>${professor.position || 'Professor'}</td>
                            <td>${formatEmploymentType(professor.employmentType)}</td>
                            <td>${studentsEvaluated}</td>
                            <td>
                                <span class="status-pill ${professor.isActive ? 'active' : 'inactive'}">
                                    ${professor.isActive ? 'Active' : 'Inactive'}
                                </span>
                            </td>
                            <td>
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
                            </td>
                        </tr>
                    `;
                    }).join('')}
                </tbody>
            </table>
        </div>
    `;

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

function normalizeHrProgramCode(value) {
    return String(value || '').trim().toUpperCase();
}

function resolveHrProfessorProgramLabel(professor) {
    if (!professor) return 'Not assigned';

    const programCode = normalizeHrProgramCode(
        professor.programCode || professor.program || ''
    );
    if (!programCode) {
        return 'Not assigned';
    }

    const directProgramName = String(professor.programName || '').trim();
    if (directProgramName) {
        return `${programCode} - ${directProgramName}`;
    }

    const campusToken = normalizeHrToken(professor.campus || '');
    const departmentToken = normalizeHrToken(professor.department || professor.institute || '');
    const programs = SharedData.getPrograms ? SharedData.getPrograms() : [];
    const programList = Array.isArray(programs) ? programs : [];

    let matched = null;
    if (campusToken && departmentToken) {
        matched = programList.find(program =>
            normalizeHrToken(program && program.campusSlug) === campusToken &&
            normalizeHrToken(program && program.departmentCode) === departmentToken &&
            normalizeHrProgramCode(program && program.programCode) === programCode
        ) || null;
    }

    if (!matched) {
        matched = programList.find(program =>
            normalizeHrProgramCode(program && program.programCode) === programCode
        ) || null;
    }

    const matchedName = String(matched && matched.programName || '').trim();
    return matchedName ? `${programCode} - ${matchedName}` : programCode;
}

/**
 * View professor details
 */
function viewProfessorDetails(professorId) {
    const professor = professorsData.find(t => String(t.id) === String(professorId));
    if (!professor) return;
    const programLabel = resolveHrProfessorProgramLabel(professor);
    const snapshot = getHrProfessorEvaluationSnapshot(professor.id, 'all', 'student');
    const studentsEvaluated = snapshot.evaluatedCount || 0;

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
                        <label><i class="fas fa-graduation-cap"></i> Program:</label>
                        <span>${programLabel}</span>
                    </div>
                    <div class="info-row">
                        <label><i class="fas fa-toggle-${professor.isActive ? 'on' : 'off'}"></i> Status:</label>
                        <span>${professor.isActive ? 'Active' : 'Inactive'}</span>
                    </div>
                    <div class="info-row highlight">
                        <label><i class="fas fa-user-check"></i> Students Evaluated:</label>
                        <span class="evaluation-count">${studentsEvaluated}</span>
                    </div>
                </div>
                <div class="detail-actions">
                    <button class="btn-edit-detail" onclick="closeProfessorDetailsModal(); editProfessor(${JSON.stringify(String(professor.id))});">
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
    if (!professor) {
        const meta = getEvaluationTypeMeta(evaluationType);
        return {
            totalRaters: 0,
            evaluatedCount: 0,
            notEvaluatedCount: 0,
            averageRating: 0,
            qualitativeResponses: [],
            meta
        };
    }

    return getHrProfessorEvaluationSnapshot(
        professor.id,
        semesterId || 'all',
        evaluationType || 'student',
        buildHrEvaluationContext()
    );
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

        currentAnalyticsProfessorId = String(professor.id);
        const selectedSemester = currentAnalyticsSemester || 'all';
        const semesterOptions = getSemesterOptions();
        const normalizedSemester = semesterOptions.some(option => option.id === selectedSemester)
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
    currentAnalyticsProfessorId = null;
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

function deriveEmployeeIdFallback(userId) {
    const digits = String(userId || '').replace(/\D/g, '');
    const base = digits ? digits.slice(-4) : String(Date.now()).slice(-4);
    return `EMP-${base.padStart(4, '0')}`;
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
    const context = buildHrEvaluationContext();
    const semesterId = context.currentSemester || 'all';
    const registration = buildHrStudentRegistrationStats(context, semesterId);
    const population = buildHrStudentPopulationCompletionStats(context, semesterId);
    const completionRate = population.totalStudents > 0
        ? Math.round((population.completedStudents / population.totalStudents) * 100)
        : 0;

    return {
        overall: {
            total: registration.total,
            completed: registration.completed,
            pending: registration.pending,
            completionRate
        },
        studentToProfessor: generateEvaluationTypeData('student', context, semesterId),
        professorToProfessor: generateEvaluationTypeData('peer', context, semesterId),
        supervisorToProfessor: generateEvaluationTypeData('supervisor', context, semesterId)
    };
}

/**
 * Generate data for a specific evaluation type
 */
function generateEvaluationTypeData(type, contextInput, semesterIdInput) {
    const typeToken = normalizeHrToken(type);
    let typeKey = 'student';
    if (typeToken === 'peer' || typeToken.includes('professor')) {
        typeKey = 'peer';
    }
    if (typeToken === 'supervisor' || typeToken.includes('supervisor')) {
        typeKey = 'supervisor';
    }

    const context = contextInput || buildHrEvaluationContext();
    const semesterId = semesterIdInput || context.currentSemester || 'all';
    return getHrReportDataByType(typeKey, context, semesterId);
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
    const yearStartSelect = document.getElementById('new-term-year-start');
    const yearEndSelect = document.getElementById('new-term-year-end');
    const semesterTypeSelect = document.getElementById('new-term-semester-type');
    const termPreviewInput = document.getElementById('new-term-preview');
    if (!semesterSelect || !yearStartSelect || !yearEndSelect || !semesterTypeSelect || !termPreviewInput) return;

    const semesterPattern = /^(1st|2nd|3rd)\s+Semester\s+(\d{4})-(\d{4})$/i;
    const slugifyTerm = (label) => label.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
    const parseSemesterLabel = (label) => {
        const match = String(label || '').trim().match(semesterPattern);
        if (!match) return null;
        return {
            semesterType: match[1] + ' Semester',
            startYear: String(match[2]),
            endYear: String(match[3])
        };
    };

    const getYearChoices = () => {
        const years = new Set();
        const nowYear = new Date().getFullYear();
        for (let year = nowYear - 3; year <= nowYear + 8; year += 1) {
            years.add(year);
        }
        (SharedData.getSemesterList() || []).forEach(item => {
            const parsed = parseSemesterLabel(item && item.label);
            if (parsed) {
                years.add(Number(parsed.startYear));
                years.add(Number(parsed.endYear));
            }
        });
        return Array.from(years).sort((a, b) => a - b);
    };

    const fillYearDropdowns = (preferredStart, preferredEnd) => {
        const years = getYearChoices();
        yearStartSelect.innerHTML = '<option value="">Start Year</option>';
        yearEndSelect.innerHTML = '<option value="">End Year</option>';

        years.forEach(year => {
            const startOption = document.createElement('option');
            startOption.value = String(year);
            startOption.textContent = String(year);
            yearStartSelect.appendChild(startOption);

            const endOption = document.createElement('option');
            endOption.value = String(year);
            endOption.textContent = String(year);
            yearEndSelect.appendChild(endOption);
        });

        const nowYear = new Date().getFullYear();
        const fallbackStart = String(nowYear);
        const fallbackEnd = String(nowYear + 1);
        const startValue = preferredStart || fallbackStart;
        const endValue = preferredEnd || fallbackEnd;

        if ([...yearStartSelect.options].some(option => option.value === startValue)) {
            yearStartSelect.value = startValue;
        }
        if ([...yearEndSelect.options].some(option => option.value === endValue)) {
            yearEndSelect.value = endValue;
        }
    };

    const buildPreviewLabel = () => {
        const startYear = yearStartSelect.value;
        const endYear = yearEndSelect.value;
        const semesterType = semesterTypeSelect.value;
        if (!startYear || !endYear || !semesterType) return '';
        return `${semesterType} ${startYear}-${endYear}`;
    };

    const refreshPreview = () => {
        termPreviewInput.value = buildPreviewLabel();
    };

    const syncBuilderFromLabel = (label) => {
        const parsed = parseSemesterLabel(label);
        fillYearDropdowns(parsed && parsed.startYear, parsed && parsed.endYear);
        if (parsed && parsed.semesterType) {
            semesterTypeSelect.value = parsed.semesterType;
        }
        refreshPreview();
    };

    const populateDropdown = () => {
        const list = SharedData.getSemesterList();
        const saved = SharedData.getCurrentSemester();
        semesterSelect.innerHTML = '';

        if (list.length === 0) {
            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'No semesters added yet';
            placeholder.disabled = true;
            placeholder.selected = true;
            semesterSelect.appendChild(placeholder);
            syncBuilderFromLabel('');
            return;
        }

        list.forEach(sem => {
            const option = document.createElement('option');
            option.value = sem.value;
            option.textContent = sem.label;
            if (sem.value === saved) option.selected = true;
            semesterSelect.appendChild(option);
        });

        if (saved && !semesterSelect.value) {
            semesterSelect.selectedIndex = 0;
        }

        const selectedOption = semesterSelect.options[semesterSelect.selectedIndex];
        syncBuilderFromLabel(selectedOption ? selectedOption.textContent : '');
    };

    fillYearDropdowns();
    populateDropdown();
    [yearStartSelect, yearEndSelect, semesterTypeSelect].forEach(element => {
        element.addEventListener('change', refreshPreview);
    });
    semesterSelect.addEventListener('change', () => {
        const selectedOption = semesterSelect.options[semesterSelect.selectedIndex];
        syncBuilderFromLabel(selectedOption ? selectedOption.textContent : '');
    });

    const addTermBtn = document.getElementById('btn-add-term');
    if (addTermBtn) {
        addTermBtn.addEventListener('click', () => {
            const startYear = Number(yearStartSelect.value);
            const endYear = Number(yearEndSelect.value);
            const semesterType = semesterTypeSelect.value;

            if (!startYear || !endYear || !semesterType) {
                alert('Please select start year, end year, and semester.');
                return;
            }
            if (endYear !== startYear + 1) {
                alert('Academic year must be consecutive (example: 2026-2027).');
                return;
            }

            const label = `${semesterType} ${startYear}-${endYear}`;
            const value = slugifyTerm(label);
            const existing = SharedData.getSemesterList().find(s =>
                s.value === value || String(s.label || '').toLowerCase() === label.toLowerCase()
            );
            if (existing) {
                alert('This semester already exists.');
                semesterSelect.value = existing.value;
                syncBuilderFromLabel(existing.label);
                return;
            }

            SharedData.addSemester(value, label);
            populateDropdown();
            semesterSelect.value = value;
            syncBuilderFromLabel(label);
        });
    }

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
                        <div class="section-title-content">
                            <h2 class="section-title"><span class="section-letter-inline">${section.letter}.</span> ${section.title}</h2>
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

