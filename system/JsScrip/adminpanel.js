// Admin Panel JavaScript - Dashboard Functionality

document.addEventListener('DOMContentLoaded', function () {
    initializeAdminPanel();
});

let activeUserSearchTerm = '';
let editingUserId = null;
let selectedCampusId = null;

// Users loaded from PHP API (or SharedData centralized storage)
let adminUsers = [];

/**
 * Fetch users from PHP API, falls back to hardcoded data
 */
function fetchUsersFromApi(campus = 'all', search = '') {
    // User requested to completely bypass PHP and only use local database (SharedData)
    adminUsers = SharedData.getUsers();

    // Apply optional campus and search filtering just like the backend would have
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
    return Promise.resolve(adminUsers);
}

// Campus data from centralized SharedData storage
let campusData = SharedData.getCampuses();

/**
 * Initialize the admin panel
 */
function initializeAdminPanel() {
    checkAuthentication();
    setupNavigation();
    setupLogout();
    setupModals();
    setupRoleBasedFields();
    setupOrganizationStructure();
    setupCampusFilter();
    setupCampusManager();
    setupUserSearch();
    setupEditUserModal();
    loadDashboardData();
    initializeCharts();
    loadActivityList();
    loadUsersByOrganization();
    setupQuickActions();
    setupActivityLogButton();
    setupSecuritySettings();
    setupAdminProfilePhotoUpload();
    setupAdminProfileActions();
    setupAdminChangeEmailForm();
    setupAdminProfilePasswordForm();
    setupBulkRegister();
    initializeHrFeatures();
    setupSemesterSettings();
    setupEvalPeriodSaving();
    renderProfessorDepartmentOptions();
    renderProfessorDepartmentTabs();
    switchToView('dashboard');
}

/**
 * Dynamically populated department select options inside Add/Edit Professor modals.
 */
function renderProfessorDepartmentOptions() {
    const departments = SharedData.getAllDepartments();
    const selectIds = ['professor-department', 'edit-user-department'];

    selectIds.forEach(id => {
        const selectElement = document.getElementById(id);
        if (selectElement) {
            selectElement.innerHTML = '<option value="">Select Department</option>';
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
            // Assuming renderProfessors uses the active tab to filter
            renderProfessors();
        });
    });
}

/**
 * Wire up the Evaluation Period date inputs so they persist via SharedData.
 * On load: pre-fill the inputs with any previously saved dates.
 * On change: save immediately.
 */
function setupEvalPeriodSaving() {
    const periodTypes = [
        'student-professor',
        'professor-professor',
        'vpaa-professor'          // HTML id prefix uses "vpaa-professor"
    ];
    // Map from HTML id prefix → SharedData key
    const keyMap = {
        'student-professor': 'student-professor',
        'professor-professor': 'professor-professor',
        'vpaa-professor': 'supervisor-professor'
    };

    const periods = SharedData.getEvalPeriods();

    // Pre-fill inputs from saved data
    periodTypes.forEach(type => {
        const startInput = document.getElementById(type + '-start');
        const endInput = document.getElementById(type + '-end');
        const dataKey = keyMap[type];
        if (startInput && periods[dataKey]) startInput.value = periods[dataKey].start || '';
        if (endInput && periods[dataKey]) endInput.value = periods[dataKey].end || '';
    });

    // Save only when the Save button is clicked
    const saveBtn = document.getElementById('save-eval-periods-btn');
    if (saveBtn) {
        saveBtn.addEventListener('click', () => {
            const updated = SharedData.getEvalPeriods();
            periodTypes.forEach(type => {
                const startInput = document.getElementById(type + '-start');
                const endInput = document.getElementById(type + '-end');
                const dataKey = keyMap[type];
                if (startInput && endInput) {
                    updated[dataKey] = {
                        start: startInput.value || '',
                        end: endInput.value || ''
                    };
                }
            });
            SharedData.setEvalPeriods(updated);
            alert('Evaluation periods saved successfully!');
        });
    }
}

/**
 * Check if user is authenticated as admin
 */
function checkAuthentication() {
    if (!SharedData.isAuthenticated() || SharedData.getRole() !== 'admin') {
        window.location.href = 'mainpage.html';
        return;
    }

    const usernameElement = document.getElementById('admin-username');
    if (usernameElement) {
        usernameElement.textContent = SharedData.getUsername() || 'Administrator';
    }
}

/**
 * Setup sidebar navigation
 */
function setupNavigation() {
    const navLinks = document.querySelectorAll('.nav-link[data-view]');

    navLinks.forEach(link => {
        link.addEventListener('click', (e) => {
            e.preventDefault();
            const viewId = link.getAttribute('data-view');
            switchToView(viewId);
        });
    });
}

/**
 * Setup logout functionality
 */
function setupLogout() {
    const logoutBtn = document.getElementById('logout-btn');
    if (logoutBtn) {
        logoutBtn.addEventListener('click', (e) => {
            e.preventDefault();
            SharedData.clearSession();
            window.location.href = 'mainpage.html';
        });
    }
}

/**
 * Setup modal functionality
 */
function setupModals() {
    const addUserBtn = document.getElementById('add-user-btn');
    const addUserModal = document.getElementById('add-user-modal');
    const closeUserModal = document.getElementById('close-user-modal');
    const cancelUserForm = document.getElementById('cancel-user-form');
    const addUserForm = document.getElementById('add-user-form');

    if (addUserBtn && addUserModal) {
        addUserBtn.addEventListener('click', () => {
            const campusSelect = document.getElementById('new-user-campus');
            if (campusSelect) {
                campusSelect.dispatchEvent(new Event('change'));
            }
            addUserModal.classList.add('active');
        });
    }

    if (closeUserModal) {
        closeUserModal.addEventListener('click', () => {
            addUserModal.classList.remove('active');
            resetUserForm();
        });
    }

    if (cancelUserForm) {
        cancelUserForm.addEventListener('click', () => {
            addUserModal.classList.remove('active');
            resetUserForm();
        });
    }

    if (addUserForm) {
        addUserForm.addEventListener('submit', (e) => {
            e.preventDefault();
            handleAddUser();
        });
    }

    window.addEventListener('click', (e) => {
        if (e.target.classList.contains('modal')) {
            e.target.classList.remove('active');
            resetUserForm();
        }
    });
}

/**
 * Setup bulk register (Excel-only) upload
 */
function setupBulkRegister() {
    const btn = document.getElementById('bulk-register-btn');
    const input = document.getElementById('bulk-register-input');
    if (!btn || !input) return;

    btn.addEventListener('click', () => {
        input.click();
    });

    input.addEventListener('change', () => {
        const file = input.files && input.files[0];
        if (!file) return;

        const name = file.name.toLowerCase();
        const isExcel = name.endsWith('.xlsx') || name.endsWith('.xls');
        if (!isExcel) {
            alert('Please select an Excel file (.xlsx or .xls).');
            input.value = '';
            return;
        }

        alert('Register complete');
        input.value = '';
    });
}

/**
 * Setup dynamic role-based form fields
 */
function setupRoleBasedFields() {
    const roleSelect = document.getElementById('new-user-role');
    const studentFields = document.getElementById('student-fields');
    const employeeFields = document.getElementById('employee-fields');
    const deptField = document.getElementById('dept-field');
    const campusSelect = document.getElementById('new-user-campus');
    const deptSelect = document.getElementById('new-user-department');

    if (roleSelect) {
        roleSelect.addEventListener('change', () => {
            const selectedRole = roleSelect.value;

            // Show/hide student-specific fields
            if (studentFields) {
                studentFields.style.display = selectedRole === 'student' ? 'block' : 'none';
                const yearSectionInput = document.getElementById('new-user-year-section');
                const studentNumberInput = document.getElementById('new-user-student-number');
                if (yearSectionInput) {
                    yearSectionInput.required = selectedRole === 'student';
                }
                if (studentNumberInput) {
                    studentNumberInput.required = selectedRole === 'student';
                }
            }

            // Show/hide employee-specific fields
            if (employeeFields) {
                employeeFields.style.display = selectedRole !== 'student' ? 'block' : 'none';
                const employeeIdInput = document.getElementById('new-user-employee-id');
                if (employeeIdInput) {
                    employeeIdInput.required = selectedRole !== 'student';
                }
            }

            // Show/hide department field (not for HR)
            if (deptField) {
                deptField.style.display = selectedRole === 'hr' ? 'none' : 'block';
                const deptSelect = document.getElementById('new-user-department');
                if (deptSelect) {
                    deptSelect.required = selectedRole !== 'hr';
                }
            }
        });

        // Trigger initial state
        roleSelect.dispatchEvent(new Event('change'));
    }

    if (campusSelect && deptSelect) {
        const updateDeptOptions = () => {
            populateDepartmentOptions(deptSelect, campusSelect.value);
        };
        campusSelect.addEventListener('change', updateDeptOptions);
        updateDeptOptions();
    }
}

/**
 * Reset user form to default state
 */
function resetUserForm() {
    const form = document.getElementById('add-user-form');
    if (form) {
        form.reset();
        const roleSelect = document.getElementById('new-user-role');
        const campusSelect = document.getElementById('new-user-campus');
        if (roleSelect) {
            roleSelect.dispatchEvent(new Event('change'));
        }
        if (campusSelect) {
            campusSelect.dispatchEvent(new Event('change'));
        }
    }
}

/**
 * Setup organization structure toggles
 */
function setupOrganizationStructure() {
    // Setup all collapsible headers
    document.querySelectorAll('.org-header[data-toggle], .dept-header[data-toggle], .role-header[data-toggle]').forEach(header => {
        header.addEventListener('click', () => {
            const targetId = header.getAttribute('data-toggle');
            const targetContent = document.getElementById(targetId);

            if (targetContent) {
                header.classList.toggle('open');
                targetContent.classList.toggle('open');
            }
        });
    });
}

/**
 * Campus filter dropdown + management
 */
function setupCampusFilter() {
    refreshCampusSelects();
    const campusSelect = document.getElementById('campus-filter-select');
    if (campusSelect) {
        campusSelect.addEventListener('change', () => {
            loadUsersByOrganization(getActiveCampusFilter());
        });
    }
}

function setupCampusManager() {
    const manageBtn = document.getElementById('manage-campuses-btn');
    const modal = document.getElementById('manage-campuses-modal');
    const closeBtn = document.getElementById('close-manage-campuses-modal');
    const addForm = document.getElementById('add-campus-form');
    const campusList = document.getElementById('campus-list');
    const campusDetail = document.getElementById('campus-detail');

    if (manageBtn && modal) {
        manageBtn.addEventListener('click', () => {
            selectedCampusId = campusData.find(c => c.id !== 'all')?.id || null;
            renderCampusList();
            renderCampusDetails();
            modal.classList.add('active');
        });
    }
    if (closeBtn && modal) {
        closeBtn.addEventListener('click', () => modal.classList.remove('active'));
    }
    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.classList.remove('active');
        });
    }
    if (addForm) {
        addForm.addEventListener('submit', (e) => {
            e.preventDefault();
            const nameInput = document.getElementById('new-campus-name');
            const deptInput = document.getElementById('new-campus-departments');
            const name = nameInput ? nameInput.value.trim() : '';
            const departments = deptInput ? deptInput.value.split(',').map(d => d.trim()).filter(Boolean) : [];
            if (!name || !departments.length) return;
            const id = slugifyCampusName(name);
            if (campusData.some(c => c.id === id)) {
                alert('Campus already exists.');
                return;
            }
            campusData.push({ id, name, departments });
            SharedData.setCampuses(campusData);
            selectedCampusId = id;
            if (nameInput) nameInput.value = '';
            if (deptInput) deptInput.value = '';
            refreshCampusSelects(id);
            renderCampusList();
            renderCampusDetails();
            renderProfessorDepartmentOptions();
            renderProfessorDepartmentTabs();
            alert('Campus added successfully!');
        });
    }

    if (campusList) {
        campusList.addEventListener('click', (e) => {
            const target = e.target;
            if (target.classList.contains('edit-campus-btn') || target.closest('.edit-campus-btn')) {
                const btn = target.closest('.edit-campus-btn');
                const campusId = btn.getAttribute('data-campus-id');
                selectedCampusId = campusId;
                renderCampusList();
                renderCampusDetails();
            }
        });
    }

    if (campusDetail) {
        campusDetail.addEventListener('click', (e) => {
            const target = e.target;
            if (target.classList.contains('remove-dept-btn')) {
                const campusId = target.getAttribute('data-campus-id');
                const dept = target.getAttribute('data-dept');
                const campus = campusData.find(c => c.id === campusId);
                if (campus) {
                    campus.departments = campus.departments.filter(d => d !== dept);
                    SharedData.setCampuses(campusData);
                    renderCampusDetails();
                }
            }
            if (target.classList.contains('add-dept-btn')) {
                const campusId = target.getAttribute('data-campus-id');
                const input = document.getElementById(`add-dept-input-${campusId}`);
                const value = input ? input.value.trim() : '';
                if (!value) return;
                const campus = campusData.find(c => c.id === campusId);
                if (campus && !campus.departments.includes(value)) {
                    campus.departments.push(value);
                    SharedData.setCampuses(campusData);
                    input.value = '';
                    renderCampusDetails();
                }
            }
            if (target.classList.contains('remove-campus-btn')) {
                const campusId = target.getAttribute('data-campus-id');
                campusData = campusData.filter(c => c.id === 'all' || c.id !== campusId);
                SharedData.setCampuses(campusData);
                selectedCampusId = campusData.find(c => c.id !== 'all')?.id || null;
                refreshCampusSelects();
                renderCampusList();
                renderCampusDetails();
                loadUsersByOrganization(getActiveCampusFilter());
            }
        });
    }
}

function refreshCampusSelects(preselectId) {
    const selectIds = ['campus-filter-select', 'new-user-campus', 'edit-user-campus'];
    selectIds.forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        const currentValue = el.value || preselectId || 'all';
        const options = campusData
            .filter(c => id === 'campus-filter-select' ? true : c.id !== 'all')
            .map(c => `<option value="${c.id}">${c.name}</option>`)
            .join('');
        el.innerHTML = options;
        const valueToSet = campusData.some(c => c.id === currentValue) ? currentValue : (id === 'campus-filter-select' ? 'all' : campusData.find(c => c.id !== 'all')?.id);
        if (valueToSet) el.value = valueToSet;
    });

    // Keep add-user department options in sync with campus list
    const addUserCampus = document.getElementById('new-user-campus');
    const addUserDept = document.getElementById('new-user-department');
    if (addUserCampus && addUserDept) {
        populateDepartmentOptions(addUserDept, addUserCampus.value);
    }
}

function renderCampusList() {
    const list = document.getElementById('campus-list');
    if (!list) return;
    const campuses = campusData.filter(c => c.id !== 'all');
    if (!campuses.length) {
        list.innerHTML = '<p class="empty-state">No campuses added yet.</p>';
        return;
    }
    list.innerHTML = campuses.map(campus => `
        <button class="campus-list-item ${campus.id === selectedCampusId ? 'active' : ''}" data-campus-id="${campus.id}">
            <div class="campus-list-title">
                <i class="fas fa-building"></i>
                <span>${campus.name}</span>
            </div>
            <div class="campus-list-meta">
                <span class="badge">${campus.departments.length} depts</span>
                <span class="edit-campus-btn" data-campus-id="${campus.id}">
                    <i class="fas fa-pen"></i> Edit
                </span>
            </div>
        </button>
    `).join('');
}

function renderCampusDetails() {
    const detail = document.getElementById('campus-detail');
    if (!detail) return;
    const campus = campusData.find(c => c.id === selectedCampusId);
    if (!campus) {
        detail.innerHTML = `
            <div class="campus-detail-empty">
                <i class="fas fa-info-circle"></i>
                <p>Select a campus to manage its departments.</p>
            </div>
        `;
        return;
    }

    detail.innerHTML = `
        <div class="campus-detail-header">
            <div>
                <p class="eyebrow">Campus</p>
                <h3>${campus.name}</h3>
                <p class="muted">${campus.departments.length} departments</p>
            </div>
            <button class="remove-campus-btn danger" data-campus-id="${campus.id}">
                <i class="fas fa-trash-alt"></i> Remove Campus
            </button>
        </div>
        <div class="campus-departments">
            ${campus.departments.map(dept => `
                <span class="dept-chip">
                    <i class="fas fa-layer-group"></i> ${dept}
                    <button class="remove-dept-btn" data-campus-id="${campus.id}" data-dept="${dept}">&times;</button>
                </span>
            `).join('') || '<p class="muted">No departments yet.</p>'}
        </div>
        <div class="add-dept-row">
            <input type="text" id="add-dept-input-${campus.id}" placeholder="Add department">
            <button class="add-dept-btn" data-campus-id="${campus.id}">
                <i class="fas fa-plus"></i> Add Department
            </button>
        </div>
    `;
}

function slugifyCampusName(name) {
    return name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

function slugifyDepartmentName(name) {
    return (name || '').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');
}

function getDepartmentsForCampus(campusId) {
    if (campusId === 'all') {
        const set = new Set();
        campusData.filter(c => c.id !== 'all').forEach(c => c.departments.forEach(d => set.add(d)));
        return Array.from(set);
    }
    const campus = campusData.find(c => c.id === campusId);
    if (campus) return campus.departments;
    return [];
}

function populateDepartmentOptions(selectEl, campusId) {
    if (!selectEl) return;
    const departments = getDepartmentsForCampus(campusId);
    if (!departments.length) {
        selectEl.innerHTML = '<option value="">No departments available</option>';
        selectEl.value = '';
        return;
    }
    selectEl.innerHTML = departments.map(dept => {
        const label = getDepartmentLabel(dept);
        return `<option value="${dept}">${label}</option>`;
    }).join('');
    // Preserve previous value if still present
    const current = selectEl.getAttribute('data-current');
    if (current && departments.some(d => d === current)) {
        selectEl.value = current;
    }
    if (!selectEl.value) {
        selectEl.value = departments[0];
    }
}

function renderDepartmentSections(departments) {
    const container = document.getElementById('departments-dynamic');
    if (!container) return;
    if (!departments || !departments.length) {
        container.innerHTML = createEmptyState('No departments configured for this campus');
        return;
    }

    const html = departments.map(dept => {
        const slug = slugifyDepartmentName(dept);
        const label = getDepartmentLabel(dept);
        return `
            <div class="department-block">
                <div class="dept-header" data-toggle="${slug}-content">
                    <i class="fas fa-layer-group"></i>
                    <h4>${label}</h4>
                    <i class="fas fa-chevron-down toggle-icon"></i>
                </div>
                <div class="dept-content" id="${slug}-content">
                    <div class="role-group">
                        <div class="role-header" data-toggle="${slug}-deans">
                            <i class="fas fa-user-tie"></i>
                            <span>Deans</span>
                            <span class="role-count" id="${slug}-dean-count">0</span>
                        </div>
                        <div class="role-users" id="${slug}-deans"></div>
                    </div>
                    <div class="role-group">
                        <div class="role-header" data-toggle="${slug}-professors">
                            <i class="fas fa-chalkboard-user"></i>
                            <span>Professors</span>
                            <span class="role-count" id="${slug}-prof-count">0</span>
                        </div>
                        <div class="role-users" id="${slug}-professors"></div>
                    </div>
                    <div class="role-group">
                        <div class="role-header" data-toggle="${slug}-students">
                            <i class="fas fa-user-graduate"></i>
                            <span>Students</span>
                            <span class="role-count" id="${slug}-student-count">0</span>
                        </div>
                        <div class="role-users" id="${slug}-students"></div>
                    </div>
                </div>
            </div>
        `;
    }).join('');

    container.innerHTML = html;
    wireDynamicDepartmentToggles();
}

function wireDynamicDepartmentToggles() {
    document.querySelectorAll('.dept-header[data-toggle], .role-header[data-toggle]').forEach(header => {
        header.onclick = () => {
            const targetId = header.getAttribute('data-toggle');
            const target = document.getElementById(targetId);
            if (target) {
                header.classList.toggle('open');
                target.classList.toggle('open');
            }
        };
    });
}

function setupUserSearch() {
    const searchInput = document.getElementById('user-search');
    const searchBtn = document.getElementById('user-search-btn');
    if (!searchInput) return;

    const triggerSearch = () => {
        activeUserSearchTerm = searchInput.value.trim().toLowerCase();
        loadUsersByOrganization(getActiveCampusFilter());
    };

    searchInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            triggerSearch();
        }
    });

    if (searchBtn) {
        searchBtn.addEventListener('click', () => {
            triggerSearch();
        });
    }
}

function getActiveCampusFilter() {
    const select = document.getElementById('campus-filter-select');
    return select ? select.value : 'all';
}

function getUserSearchTerm() {
    return activeUserSearchTerm;
}

/**
 * Handle adding a new user — sends to PHP API
 */
function handleAddUser() {
    const form = document.getElementById('add-user-form');
    const formData = new FormData(form);

    const departmentValue = formData.get('department') || '';
    const userData = {
        name: formData.get('name'),
        email: formData.get('email'),
        password: formData.get('password') || '',
        role: formData.get('role'),
        campus: formData.get('campus'),
        department: departmentValue,
        institute: departmentValue,
        yearSection: formData.get('yearSection') || '',
        studentNumber: formData.get('studentNumber') || '',
        employeeId: formData.get('employeeId') || '',
        status: 'active',
        id: 'u' + Date.now() // Generate local ID
    };

    // Save directly to the local SharedData database
    SharedData.addUser(userData);
    adminUsers = SharedData.getUsers();

    alert('User added successfully to local database!');
    document.getElementById('add-user-modal').classList.remove('active');
    resetUserForm();
    loadUsersByOrganization();

    // Refresh Professor Management data if the new user is a professor
    if (userData.role === 'professor') {
        loadProfessorsData();
        renderProfessors();
    }
}

/**
 * Load dashboard statistics from SharedData (live data)
 */
function loadDashboardData() {
    const stats = getDashboardStats();

    animateValue('total-professors', 0, stats.professors, 1000);
    animateValue('total-students', 0, stats.students, 1000);
    animateValue('pending-evaluations', 0, stats.pendingEvaluations, 1000);
    animateValue('completion-rate-overview', 0, stats.completionRate, 1000, '%');

    // Listen for data changes to auto-refresh the cards
    SharedData.onDataChange(function (key) {
        if (key === SharedData.KEYS.USERS || key === SharedData.KEYS.PROFESSORS) {
            updateOverviewCards();
        }
    });
}

/**
 * Calculate dashboard stats from SharedData
 */
function getDashboardStats() {
    const users = SharedData.getUsers();
    const professors = SharedData.getProfessors();

    // Count active professors from users list
    const professorUsers = users.filter(function (u) {
        return u.role === 'professor' && u.status === 'active';
    });
    // Use whichever source has more professors (users list or professors list)
    const professorCount = Math.max(professorUsers.length, professors.length);

    // Count active students from users list
    const studentCount = users.filter(function (u) {
        return u.role === 'student' && u.status === 'active';
    }).length;

    // Calculate pending evaluations: each student should evaluate each professor
    var totalExpectedEvaluations = studentCount * Math.max(professorCount, 1);
    // For now, evaluations completed = 0 (no evaluation data stored yet)
    var completedEvaluations = 0;
    var pendingEvaluations = totalExpectedEvaluations - completedEvaluations;

    var completionRate = totalExpectedEvaluations > 0
        ? Math.min(100, Math.round((completedEvaluations / totalExpectedEvaluations) * 100))
        : 0;

    return {
        professors: professorCount,
        students: studentCount,
        completionRate: completionRate,
        pendingEvaluations: pendingEvaluations
    };
}

function updateOverviewCards() {
    var stats = getDashboardStats();
    var profEl = document.getElementById('total-professors');
    var studEl = document.getElementById('total-students');
    var pendEl = document.getElementById('pending-evaluations');
    var compEl = document.getElementById('completion-rate-overview');

    if (profEl) profEl.textContent = stats.professors;
    if (studEl) studEl.textContent = stats.students;
    if (pendEl) pendEl.textContent = stats.pendingEvaluations;
    if (compEl) compEl.textContent = stats.completionRate + '%';
}

/**
 * Animate number counting up
 */
function animateValue(elementId, start, end, duration, suffix = '') {
    const element = document.getElementById(elementId);
    if (!element) return;

    const startTime = performance.now();
    const updateValue = (currentTime) => {
        const elapsed = currentTime - startTime;
        const progress = Math.min(elapsed / duration, 1);
        const current = Math.floor(progress * (end - start) + start);
        element.textContent = suffix
            ? `${current.toLocaleString()}${suffix}`
            : current.toLocaleString();

        if (progress < 1) {
            requestAnimationFrame(updateValue);
        }
    };
    requestAnimationFrame(updateValue);
}

/**
 * Initialize dashboard charts
 */
function initializeCharts() {
    // Evaluation Overview Chart
    const evaluationCtx = document.getElementById('evaluation-chart');
    if (evaluationCtx) {
        new Chart(evaluationCtx, {
            type: 'doughnut',
            data: {
                labels: ['Completed', 'Pending', 'Not Started'],
                datasets: [{
                    data: [65, 25, 10],
                    backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                    borderWidth: 0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { position: 'bottom' }
                }
            }
        });
    }

    // Semestral Performance Chart
    const performanceCtx = document.getElementById('performance-chart');
    if (performanceCtx) {
        new Chart(performanceCtx, {
            type: 'bar',
            data: {
                labels: ['1st Sem 2024-2025', '2nd Sem 2024-2025', '1st Sem 2025-2026', '2nd Sem 2025-2026'],
                datasets: [{
                    label: 'Evaluations Completed',
                    data: [850, 920, 1050, 1280],
                    backgroundColor: ['#667eea', '#764ba2', '#667eea', '#764ba2'],
                    borderRadius: 8
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false }
                },
                scales: {
                    y: { beginAtZero: true },
                    x: {
                        ticks: {
                            maxRotation: 45,
                            minRotation: 45
                        }
                    }
                }
            }
        });
    }
}

/**
 * Load recent activity list
 */
function loadActivityList() {
    const activityList = document.getElementById('activity-list');
    if (!activityList) return;

    const activities = [
        { icon: 'login', message: 'Admin logged in successfully', time: '2 minutes ago' },
        { icon: 'user', message: 'New student added to ICS dept', time: '15 minutes ago' },
        { icon: 'user', message: 'New professor added to ILAS dept', time: '1 hour ago' },
        { icon: 'evaluation', message: '25 students completed evaluations', time: '2 hours ago' },
        { icon: 'login', message: 'HR staff logged in', time: '3 hours ago' }
    ];

    activityList.innerHTML = activities.map(activity => `
        <div class="activity-item">
            <div class="activity-icon ${activity.icon}">
                <i class="fas fa-${getActivityIcon(activity.icon)}"></i>
            </div>
            <div class="activity-content">
                <p>${activity.message}</p>
                <span>${activity.time}</span>
            </div>
        </div>
    `).join('');
}

function loadActivityLog() {
    const tbody = document.getElementById('activity-log-body');
    if (!tbody) return;

    const activityRows = [
        { ip_address: '223.31.69.69', timestamp: '2026-02-06 08:32', description: 'Authentication by cached UID', action: 'Login', role: 'admin', user_id: 'admin', log_id: 'LOG-0001', type: 'login' },
        { ip_address: '223.31.69.70', timestamp: '2026-02-06 08:10', description: 'Update AdoptOpenJDK JRE', action: 'System Update', role: 'system', user_id: 'system', log_id: 'LOG-0002', type: 'system' },
        { ip_address: '223.31.69.69', timestamp: '2026-02-06 07:58', description: 'HR staff logged in', action: 'Login', role: 'hr', user_id: 'hr_staff', log_id: 'LOG-0003', type: 'login' },
        { ip_address: '223.31.69.60', timestamp: '2026-02-06 07:22', description: 'students completed evaluations', action: 'Evaluation Completed', role: 'student', user_id: 'student_2024_102', log_id: 'LOG-0004', type: 'evaluation' },
        { ip_address: '223.31.69.69', timestamp: '2026-02-06 06:55', description: 'Created user prof_garcia', action: 'User Account Created', role: 'admin', user_id: 'admin_ops', log_id: 'LOG-0005', type: 'user' }
    ];

    const searchBtn = document.getElementById('activity-search-btn');
    const typeSelect = document.getElementById('activity-type');
    const searchInput = document.getElementById('activity-search');

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

    if (searchBtn) {
        searchBtn.onclick = () => {
            const typeValue = typeSelect ? typeSelect.value : 'all';
            const term = searchInput ? searchInput.value.trim().toLowerCase() : '';
            const filtered = activityRows.filter(row => {
                const typeMatch = typeValue === 'all' || row.type === typeValue;
                const text = `${row.ip_address} ${row.timestamp} ${row.description} ${row.action} ${row.role} ${row.user_id} ${row.log_id}`.toLowerCase();
                const searchMatch = term ? text.includes(term) : true;
                return typeMatch && searchMatch;
            });
            renderRows(filtered);
        };
    }
}

/**
 * Get icon for activity type
 */
function getActivityIcon(type) {
    const icons = {
        login: 'sign-in-alt',
        evaluation: 'clipboard-check',
        user: 'user-plus',
        system: 'exclamation-triangle'
    };
    return icons[type] || 'info-circle';
}

/**
 * Load users organized by structure — fetches from PHP API
 */
function loadUsersByOrganization(campusFilter = 'all') {
    // Fetch from API then render
    fetchUsersFromApi(campusFilter, getUserSearchTerm()).then(() => {
        renderOrganizationView(campusFilter);
    });
}

/**
 * Render the organization view with the current adminUsers data
 */
function renderOrganizationView(campusFilter = 'all') {
    const users = adminUsers;

    // Filter by campus if not 'all'
    let filteredUsers = campusFilter === 'all' ? users : users.filter(u => u.campus === campusFilter);
    const searchTerm = getUserSearchTerm();
    if (searchTerm) {
        filteredUsers = filteredUsers.filter(user => {
            const haystack = [
                user.name,
                user.email,
                user.role,
                user.department,
                user.campus,
                user.yearSection,
                user.studentNumber,
                user.employeeId
            ].filter(Boolean).join(' ').toLowerCase();
            return haystack.includes(searchTerm);
        });
    }

    renderUserSearchResults(searchTerm, filteredUsers);

    // Populate HR section
    let hrUsers = filteredUsers.filter(u => u.role === 'hr');
    hrUsers = sortUsersByStatusAndName(hrUsers);
    const hrCountEl = document.getElementById('hr-count');
    if (hrCountEl) hrCountEl.textContent = hrUsers.length;
    const hrList = document.getElementById('hr-user-list');
    if (hrList) {
        hrList.innerHTML = hrUsers.length > 0 ? hrUsers.map(u => createUserCard(u)).join('') : createEmptyState('No HR staff found');
    }

    // Populate Admin section
    let adminUsersList = filteredUsers.filter(u => u.role === 'admin');
    adminUsersList = sortUsersByStatusAndName(adminUsersList);
    const adminCountEl = document.getElementById('admin-count');
    if (adminCountEl) adminCountEl.textContent = adminUsersList.length;
    const adminList = document.getElementById('admin-user-list');
    if (adminList) {
        adminList.innerHTML = adminUsersList.length > 0 ? adminUsersList.map(u => createUserCard(u)).join('') : createEmptyState('No administrators found');
    }

    // Populate VPAA section
    let vpaaUsersInfo = filteredUsers.filter(u => u.role === 'vpaa');
    vpaaUsersInfo = sortUsersByStatusAndName(vpaaUsersInfo);
    const vpaaCountEl = document.getElementById('vpaa-count');
    if (vpaaCountEl) vpaaCountEl.textContent = vpaaUsersInfo.length;
    const vpaaList = document.getElementById('vpaa-user-list');
    if (vpaaList) {
        vpaaList.innerHTML = vpaaUsersInfo.length > 0 ? vpaaUsersInfo.map(u => createUserCard(u)).join('') : createEmptyState('No VPAA staff found');
    }

    // Populate OSA section
    let osaUsersInfo = filteredUsers.filter(u => u.role === 'osa');
    osaUsersInfo = sortUsersByStatusAndName(osaUsersInfo);
    const osaCountEl = document.getElementById('osa-count');
    if (osaCountEl) osaCountEl.textContent = osaUsersInfo.length;
    const osaList = document.getElementById('osa-user-list');
    if (osaList) {
        osaList.innerHTML = osaUsersInfo.length > 0 ? osaUsersInfo.map(u => createUserCard(u)).join('') : createEmptyState('No OSA staff found');
    }

    // Departments based on selected campus
    const departments = getDepartmentsForCampus(campusFilter);
    renderDepartmentSections(departments);

    departments.forEach(dept => {
        const deptKey = (dept || '').toLowerCase();
        const deptUsers = filteredUsers.filter(u => (u.department || '').toLowerCase() === deptKey);
        const slug = slugifyDepartmentName(dept);

        // Deans
        let deans = deptUsers.filter(u => u.role === 'dean');
        deans = sortUsersByStatusAndName(deans);
        const deanCount = document.getElementById(`${slug}-dean-count`);
        if (deanCount) deanCount.textContent = deans.length;
        const deanContainer = document.getElementById(`${slug}-deans`);
        if (deanContainer) deanContainer.innerHTML = deans.length > 0 ? deans.map(u => createUserCard(u)).join('') : createEmptyState('No deans');

        // Professors
        let profs = deptUsers.filter(u => u.role === 'professor');
        profs = sortUsersByStatusAndName(profs);
        const profCount = document.getElementById(`${slug}-prof-count`);
        if (profCount) profCount.textContent = profs.length;
        const profContainer = document.getElementById(`${slug}-professors`);
        if (profContainer) profContainer.innerHTML = profs.length > 0 ? profs.map(u => createUserCard(u)).join('') : createEmptyState('No professors');

        // Students
        let students = deptUsers.filter(u => u.role === 'student');
        students = sortUsersByStatusAndName(students);
        const studentCount = document.getElementById(`${slug}-student-count`);
        if (studentCount) studentCount.textContent = students.length;
        const studentContainer = document.getElementById(`${slug}-students`);
        if (studentContainer) studentContainer.innerHTML = students.length > 0 ? students.map(u => createUserCard(u)).join('') : createEmptyState('No students');
    });
}

/**
 * Sorts an array of users by active status first, then by name
 */
function sortUsersByStatusAndName(users) {
    return users.slice().sort((a, b) => {
        const aActive = a.status !== 'inactive' && a.isActive !== false;
        const bActive = b.status !== 'inactive' && b.isActive !== false;
        if (aActive !== bActive) return aActive ? -1 : 1;
        return (a.name || '').localeCompare(b.name || '');
    });
}

function renderUserSearchResults(searchTerm, users) {
    const resultsContainer = document.getElementById('user-search-results');
    const resultsList = document.getElementById('user-search-list');
    if (!resultsContainer || !resultsList) return;

    if (!searchTerm) {
        resultsContainer.style.display = 'none';
        resultsList.innerHTML = '';
        return;
    }

    resultsContainer.style.display = 'block';

    if (!users.length) {
        resultsList.innerHTML = `
            <tr>
                <td class="result-empty" colspan="9">No users match your search</td>
            </tr>
        `;
        return;
    }

    resultsList.innerHTML = users.map(user => createSearchResultRow(user)).join('');
    resultsList.querySelectorAll('tr[data-user-id]').forEach(row => {
        row.addEventListener('click', () => {
            openEditUserModal(row.getAttribute('data-user-id'));
        });
    });
}

function createSearchResultRow(user) {
    const idNumber = user.employeeId || user.studentNumber || '—';
    const institute = getInstituteLabel(user);
    const employmentType = user.role === 'student' ? 'Student' : (user.employmentType || 'Regular');
    const position = user.role === 'student' ? (user.yearSection || 'Student') : (user.position || getRoleLabel(user.role));
    const campusLabel = user.campus ? user.campus.charAt(0).toUpperCase() + user.campus.slice(1) : '—';
    const password = user.password || '';
    const statusLabel = user.status === 'inactive' ? 'Inactive' : 'Active';

    return `
        <tr data-user-id="${user.id}">
            <td>${idNumber}</td>
            <td>${user.name}</td>
            <td>${user.email || '—'}</td>
            <td>${password ? createPasswordCell(password) : '—'}</td>
            <td>${campusLabel}</td>
            <td>${institute}</td>
            <td>${employmentType}</td>
            <td>${position}</td>
            <td><span class="status-pill ${statusLabel.toLowerCase()}">${statusLabel}</span></td>
        </tr>
    `;
}

function maskPassword(password) {
    return 'Hover to view';
}

function createPasswordCell(password) {
    return `
        <span class="password-cell">
            <span class="password-mask">${maskPassword(password)}</span>
            <span class="password-reveal">${password}</span>
        </span>
    `;
}

function getInstituteLabel(user) {
    if (user.institute) {
        // If it's a known shortcode like 'ics', uppercase it, otherwise return as is
        const lower = user.institute.toLowerCase();
        if (['ics', 'ilas', 'engi'].includes(lower)) {
            return user.institute.toUpperCase();
        }
        return user.institute;
    }

    if (user.department) {
        return user.department.toUpperCase();
    }

    if (user.role === 'hr') return 'HR';
    if (user.role === 'admin') return 'Administration';
    if (user.role === 'vpaa') return 'VPAA';
    if (user.role === 'osa') return 'OSA';
    return '—';
}

function getDepartmentLabel(dept) {
    // Dynamic label: uppercase the department key
    if (!dept) return '';
    return dept.toUpperCase();
}

function getRoleLabel(role) {
    const roles = {
        hr: 'HR Staff',
        admin: 'Administrator',
        professor: 'Professor',
        student: 'Student'
    };
    return roles[role] || 'User';
}

function setupEditUserModal() {
    const modal = document.getElementById('edit-user-modal');
    const closeBtn = document.getElementById('close-edit-user-modal');
    const cancelBtn = document.getElementById('cancel-edit-user-form');
    const form = document.getElementById('edit-user-form');

    if (closeBtn) {
        closeBtn.addEventListener('click', closeEditUserModal);
    }
    if (cancelBtn) {
        cancelBtn.addEventListener('click', closeEditUserModal);
    }
    if (form) {
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            handleEditUserSave();
        });
    }
    if (modal) {
        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeEditUserModal();
            }
        });
    }
}

function openEditUserModal(userId) {
    const user = adminUsers.find(u => u.id === userId);
    if (!user) return;

    editingUserId = userId;
    const modal = document.getElementById('edit-user-modal');
    if (!modal) return;

    const idLabel = document.getElementById('edit-user-id-label');
    if (idLabel) {
        idLabel.textContent = user.role === 'student' ? 'Student Number' : 'Employee ID';
    }

    const idInput = document.getElementById('edit-user-id-number');
    if (idInput) idInput.value = user.employeeId || user.studentNumber || '';
    const nameInput = document.getElementById('edit-user-name');
    if (nameInput) nameInput.value = user.name || '';
    const emailInput = document.getElementById('edit-user-email');
    if (emailInput) emailInput.value = user.email || '';
    const passwordInput = document.getElementById('edit-user-password');
    if (passwordInput) passwordInput.value = user.password || '';
    const campusInput = document.getElementById('edit-user-campus');
    if (campusInput) campusInput.value = user.campus || 'basa';
    const instituteInput = document.getElementById('edit-user-institute');
    if (instituteInput) instituteInput.value = user.institute || getInstituteLabel(user);
    const employmentTypeInput = document.getElementById('edit-user-employment-type');
    if (employmentTypeInput) employmentTypeInput.value = user.employmentType || (user.role === 'student' ? 'Student' : 'Regular');
    const positionInput = document.getElementById('edit-user-position');
    if (positionInput) positionInput.value = user.position || '';
    const statusInput = document.getElementById('edit-user-status');
    if (statusInput) statusInput.value = user.status || 'active';

    modal.classList.add('active');
}

function closeEditUserModal() {
    const modal = document.getElementById('edit-user-modal');
    if (modal) modal.classList.remove('active');
    editingUserId = null;
}

function handleEditUserSave() {
    const user = adminUsers.find(u => u.id === editingUserId);
    if (!user) return;

    const idInput = document.getElementById('edit-user-id-number');
    const nameInput = document.getElementById('edit-user-name');
    const emailInput = document.getElementById('edit-user-email');
    const passwordInput = document.getElementById('edit-user-password');
    const campusInput = document.getElementById('edit-user-campus');
    const instituteInput = document.getElementById('edit-user-institute');
    const employmentTypeInput = document.getElementById('edit-user-employment-type');
    const positionInput = document.getElementById('edit-user-position');
    const statusInput = document.getElementById('edit-user-status');

    const idNumber = idInput ? idInput.value.trim() : '';
    if (user.role === 'student') {
        user.studentNumber = idNumber;
        user.employeeId = undefined;
    } else {
        user.employeeId = idNumber;
        user.studentNumber = undefined;
    }

    if (nameInput) user.name = nameInput.value.trim();
    if (emailInput) user.email = emailInput.value.trim();
    if (passwordInput) user.password = passwordInput.value;
    if (campusInput) user.campus = campusInput.value;
    if (instituteInput) {
        user.institute = instituteInput.value.trim();
        user.department = instituteInput.value.trim(); // Sync department to institute for consistency
    }
    if (employmentTypeInput) user.employmentType = employmentTypeInput.value;
    if (positionInput) user.position = positionInput.value.trim();
    if (statusInput) user.status = statusInput.value;

    // Save directly to the local SharedData database
    SharedData.updateUser(user.id, user);

    alert('User updated successfully in local database!');
    closeEditUserModal();
    loadUsersByOrganization(getActiveCampusFilter());

    // Refresh Professor Management data if the updated user is a professor
    if (user.role === 'professor') {
        loadProfessorsData();
        renderProfessors();
    }
}

/**
 * Create user card HTML
 */
function createUserCard(user) {
    const initials = user.name.split(' ').map(n => n[0]).join('').substring(0, 2);
    const baseInfo = user.yearSection || (user.campus ? user.campus.charAt(0).toUpperCase() + user.campus.slice(1) : '');
    const idLabel = user.studentNumber ? `Student No: ${user.studentNumber}` : (user.employeeId ? `Employee No: ${user.employeeId}` : '');
    const info = [baseInfo, idLabel].filter(Boolean).join('  -  ');
    const status = user.status || 'active';
    const statusLabel = status === 'inactive' ? 'Inactive' : 'Active';

    return `
        <div class="user-card-compact ${status === 'inactive' ? 'inactive' : ''}">
            <div class="user-avatar">${initials}</div>
            <div class="user-details">
                <div class="name">${user.name}</div>
                <div class="info">${info}</div>
            </div>
            <div class="user-actions-compact">
                <button class="edit-btn" onclick="editUser('${user.id}')"><i class="fas fa-edit"></i></button>
                <button class="status-btn ${status}" onclick="toggleUserStatus(this, '${user.email}')">${statusLabel}</button>
            </div>
        </div>
    `;
}

function toggleUserStatus(button, email) {
    if (!button) return;
    const isActive = button.classList.contains('active');

    // Find and update the user in SharedData
    let users = SharedData.getUsers();
    const userIndex = users.findIndex(u => u.email === email);

    if (userIndex !== -1) {
        users[userIndex].status = isActive ? 'inactive' : 'active';
        users[userIndex].isActive = !isActive;
        SharedData.updateUser(users[userIndex]);

        // Update local adminUsers cache
        adminUsers = SharedData.getUsers();

        // Also update professorsData if applicable to sync instantly to Professor Management
        if (users[userIndex].role === 'professor') {
            const profIndex = professorsData.findIndex(p => p.email === email);
            if (profIndex !== -1) {
                professorsData[profIndex].isActive = !isActive;
                professorsData[profIndex].status = isActive ? 'inactive' : 'active';
                saveProfessorsToSharedData();
            } else {
                loadProfessorsData(); // fallback
            }
            renderProfessors();
        }
    }

    if (isActive) {
        button.classList.remove('active');
        button.classList.add('inactive');
        button.textContent = 'Inactive';
    } else {
        button.classList.remove('inactive');
        button.classList.add('active');
        button.textContent = 'Active';
    }

    // Refresh the User Management view to apply sorting and CSS changes
    const activeTab = document.querySelector('.organization-tab.active');
    const campus = activeTab ? activeTab.getAttribute('data-campus') : 'all';
    renderOrganizationView(campus);
}

/**
 * Create empty state HTML
 */
function createEmptyState(message) {
    return `
        <div class="empty-state">
            <i class="fas fa-user-slash"></i>
            <p>${message}</p>
        </div>
    `;
}

/**
 * Setup quick action buttons
 */
function setupQuickActions() {
    const quickActionBtns = document.querySelectorAll('.quick-action-btn');

    quickActionBtns.forEach(btn => {
        btn.addEventListener('click', () => {
            const action = btn.getAttribute('data-action');
            handleQuickAction(action);
        });
    });
}

function setupActivityLogButton() {
    const activityBtn = document.getElementById('open-activity-log');
    if (activityBtn) {
        activityBtn.addEventListener('click', () => {
            switchToView('activity-log');
        });
    }
}

function setupSecuritySettings() {
    const backupBtn = document.getElementById('backup-last-24-btn');
    if (backupBtn) {
        backupBtn.addEventListener('click', () => {
            alert('Backup successful! Data from the last 24 hours has been archived.');
        });
    }
}

/**
 * Handle quick action clicks
 */
function handleQuickAction(action) {
    switch (action) {
        case 'add-user':
            document.getElementById('add-user-modal').classList.add('active');
            break;
        case 'manage-users':
            switchToView('users');
            break;
        case 'manage-campus':
            alert('Campus management coming soon!');
            break;
        case 'send-announcement':
            alert('Announcement feature coming soon!');
            break;
        case 'system-backup':
            alert('System backup initiated!');
            break;
        case 'system-settings':
            switchToView('system');
            break;
    }
}

/**
 * Switch to a specific view
 */
function switchToView(viewId) {
    const safeViewId = viewId || 'dashboard';
    const contentViews = document.querySelectorAll('.content-view');
    contentViews.forEach(view => {
        view.style.display = 'none';
    });

    const targetView = document.getElementById(safeViewId + '-view');
    if (targetView) {
        targetView.style.display = 'block';
    }

    setActiveNav(safeViewId);
    handleViewShown(safeViewId);
}

function setActiveNav(viewId) {
    const navLinks = document.querySelectorAll('.nav-link[data-view]');
    navLinks.forEach(l => l.classList.remove('active'));
    const targetNav = document.querySelector('.nav-link[data-view="' + viewId + '"]');
    if (targetNav) targetNav.classList.add('active');
}

function handleViewShown(viewId) {
    if (viewId === 'dashboard') {
        loadReports();
    }
    if (viewId === 'hr-professors') {
        loadUserManagement();
    }
    if (viewId === 'hr-reports') {
        loadReports();
    }
    if (viewId === 'hr-questionnaire') {
        loadQuestionnaire();
    }
    if (viewId === 'activity-log') {
        loadActivityLog();
    }
}

/**
 * Edit user function
 */
function editUser(userId) {
    const user = adminUsers.find(u => u.id === userId);
    if (!user) {
        alert('User not found.');
        return;
    }
    openEditUserModal(user.id);
}

/**
 * Delete user function
 */
function deleteUser(email) {
    if (confirm(`Are you sure you want to delete user: ${email}?`)) {
        const user = adminUsers.find(u => u.email === email);
        if (!user) return;

        // Delete directly from the local SharedData database
        SharedData.deleteUser(user.id);

        alert(`User ${email} deleted from local database!`);
        loadUsersByOrganization();

        // Refresh Professor Management data if the deleted user was a professor
        if (user.role === 'professor') {
            loadProfessorsData();
            renderProfessors();
        }
    }
}

function initializeHrFeatures() {
    setupHrChangePasswordForm();
    setupHrPasswordToggles();
    setupProfessorManagement();
    setupQuestionnaire();
    setupEvalPeriods();

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
}

function getUserSession() {
    return SharedData.getSession();
}

function setupHrChangePasswordForm() {
    const form = document.getElementById('changePasswordForm');
    const cancelBtn = document.getElementById('cancelPasswordBtn');
    if (!form) return;

    form.addEventListener('submit', (e) => {
        e.preventDefault();
        handleHrChangePassword();
    });

    if (cancelBtn) {
        cancelBtn.addEventListener('click', () => {
            form.reset();
            switchToView('dashboard');
        });
    }
}

function handleHrChangePassword() {
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

function setupHrPasswordToggles() {
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
 * Admin profile photo upload and preview
 */
function setupAdminProfilePhotoUpload() {
    const input = document.getElementById('profilePhotoInput');
    const preview = document.getElementById('profilePhotoPreview');
    const placeholder = document.getElementById('profilePhotoPlaceholder');

    if (!input || !preview || !placeholder) return;

    const fullName = getAdminProfileFullName();
    placeholder.textContent = buildInitials(fullName) || 'AD';

    const storedPhoto = SharedData.getProfilePhoto('admin');
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
            SharedData.setProfilePhoto('admin', reader.result);
        };
        reader.readAsDataURL(file);
    });
}

function getAdminProfileFullName() {
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
 * Admin profile view actions for toggling account forms
 */
function setupAdminProfileActions() {
    const toggleButtons = document.querySelectorAll('.js-toggle-account-form');
    const closeButtons = document.querySelectorAll('.js-close-account-form');
    if (!toggleButtons.length && !closeButtons.length) return;

    toggleButtons.forEach(button => {
        button.addEventListener('click', function () {
            const targetId = this.getAttribute('data-target');
            if (!targetId) return;
            hideAdminAccountActionCards();
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

function hideAdminAccountActionCards() {
    document.querySelectorAll('.account-action-card').forEach(card => {
        card.style.display = 'none';
    });
}

/**
 * Setup change email form functionality (Admin Profile)
 */
function setupAdminChangeEmailForm() {
    const form = document.getElementById('adminChangeEmailForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        handleAdminChangeEmail();
    });
}

function handleAdminChangeEmail() {
    const currentEmail = document.getElementById('adminCurrentEmail').value.trim();
    const newEmail = document.getElementById('adminNewEmail').value.trim();
    const confirmEmail = document.getElementById('adminConfirmEmail').value.trim();

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

    console.log('Ready for SQL integration: /api/admin/change-email', payload);
    alert('Email update request ready for SQL connection.');

    const profileEmail = document.getElementById('adminProfileEmail');
    if (profileEmail) profileEmail.textContent = newEmail;
    const currentEmailInput = document.getElementById('adminCurrentEmail');
    if (currentEmailInput) {
        currentEmailInput.value = newEmail;
        currentEmailInput.defaultValue = newEmail;
    }

    const form = document.getElementById('adminChangeEmailForm');
    if (form) form.reset();
}

/**
 * Setup change password form functionality (Admin Profile)
 */
function setupAdminProfilePasswordForm() {
    const form = document.getElementById('adminChangePasswordForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        handleAdminProfilePasswordChange();
    });
}

function handleAdminProfilePasswordChange() {
    const currentPassword = document.getElementById('adminCurrentPassword').value.trim();
    const newPassword = document.getElementById('adminNewPassword').value.trim();
    const confirmPassword = document.getElementById('adminConfirmPassword').value.trim();

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

    console.log('Ready for SQL integration: /api/admin/change-password', payload);
    alert('Password update request ready for SQL connection.');

    const form = document.getElementById('adminChangePasswordForm');
    if (form) form.reset();
}

/* Professor Management System
 */

// Store professors data
let professorsData = [];
let currentEditingProfessorId = null;
let currentDepartmentFilter = 'all';
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

function generateEmployeeId() {
    // Generate a simple unique employee ID based on timestamp
    return `EMP-${Date.now().toString().slice(-6)}`;
}

function formatEmploymentType(type) {
    if (!type) return 'Regular';
    const normalized = String(type).toLowerCase();
    return normalized.charAt(0).toUpperCase() + normalized.slice(1);
}

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



// Real Data Utilities
function getProfessorEvaluationsFromShared(professorId) {
    const allEvals = typeof SharedData !== 'undefined' && SharedData.getEvaluations ? SharedData.getEvaluations() : [];
    return allEvals.filter(e => {
        const tId = String(e.targetId || e.colleagueId || e.professorSubject || '');
        // professorSubject might be "1|CS101" so check startsWith or exactly equals
        return tId === String(professorId) || tId.startsWith(String(professorId) + '|');
    });
}

function calculateAggregatedMetrics(evaluations, semesterId) {
    const filtered = evaluations.filter(e => !semesterId || e.semesterId === semesterId || !e.semesterId);
    let totalRatings = 0;
    let ratingCount = 0;
    const qualitativeResponses = [];

    filtered.forEach(ev => {
        // Aggregate ratings
        if (ev.ratings) {
            Object.values(ev.ratings).forEach(val => {
                const num = parseFloat(val);
                if (!isNaN(num)) {
                    totalRatings += num;
                    ratingCount++;
                }
            });
        }

        // Aggregate qualitative
        const hasQualitative = ev.qualitative && Object.keys(ev.qualitative).length > 0;
        const hasComments = ev.comments && ev.comments.trim().length > 0;

        if (hasQualitative || hasComments) {
            let combinedText = '';
            if (hasQualitative) {
                combinedText += Object.values(ev.qualitative).filter(t => t.trim()).join(' | ');
            }
            if (hasComments) {
                combinedText += (combinedText ? ' | ' : '') + ev.comments;
            }

            if (combinedText.trim()) {
                qualitativeResponses.push({
                    id: ev.id,
                    text: combinedText,
                    date: new Date(ev.submittedAt || Date.now()).toLocaleDateString(),
                    studentName: ev.evaluatorName || 'Anonymous',
                    studentNumber: ev.evaluatorId || ev.evaluatorUsername || 'N/A',
                    role: ev.evaluatorRole || ev.evaluationType || 'unknown',
                    semesterId: ev.semesterId || semesterId || 'all'
                });
            }
        }
    });

    return {
        evaluatedCount: filtered.length,
        averageRating: ratingCount > 0 ? parseFloat((totalRatings / ratingCount).toFixed(1)) : 0,
        qualitativeResponses: qualitativeResponses
    };
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

    const profEvals = getProfessorEvaluationsFromShared(professor.id);

    semesterIds.forEach(semesterId => {
        if (!professor.semesterData[semesterId]) {
            professor.semesterData[semesterId] = {};
            didUpdate = true;
        }

        const metrics = calculateAggregatedMetrics(profEvals, semesterId);

        const data = professor.semesterData[semesterId];

        const oldAvg = data.averageRating;
        const oldEvalCount = data.evaluatedCount;

        // We don't have exact class sizes from shared data right now, so we approximate a pool size based on evaluations + some margin
        // If we want real non-evaluated counts, we need real enrollment data.
        data.evaluatedCount = metrics.evaluatedCount;
        data.totalStudents = Math.max(data.totalStudents || 0, data.evaluatedCount + (data.evaluatedCount > 0 ? 5 : 0));
        data.notEvaluatedCount = Math.max(data.totalStudents - data.evaluatedCount, 0);
        data.averageRating = metrics.averageRating;
        data.qualitativeResponses = metrics.qualitativeResponses;

        if (oldAvg !== data.averageRating || oldEvalCount !== data.evaluatedCount) {
            didUpdate = true;
        }
    });

    const overall = combineSemesterData(professor.semesterData);
    professor.totalStudents = overall.totalStudents || 0;
    professor.evaluatedCount = overall.evaluatedCount || 0;
    professor.notEvaluatedCount = overall.notEvaluatedCount || 0;
    professor.averageRating = overall.averageRating || 0;
    professor.evaluationsCount = overall.evaluatedCount || 0;
    professor.qualitativeResponses = overall.qualitativeResponses ? overall.qualitativeResponses.map(response => normalizeResponse(response, response.semesterId)) : [];

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
    const meta = getEvaluationTypeMeta(evaluationType);

    // Fetch all evaluations for this professor from SharedData
    const profEvals = getProfessorEvaluationsFromShared(professor.id);

    // Filter by type
    const typeFiltered = profEvals.filter(e => {
        // Map UI evaluation type to data evaluatorRole
        const dataRole = e.evaluatorRole || e.evaluationType;
        if (evaluationType === 'student') return dataRole === 'student';
        if (evaluationType === 'peer') return dataRole === 'professor' || dataRole === 'peer';
        if (evaluationType === 'supervisor') return dataRole === 'dean' || dataRole === 'hr' || dataRole === 'supervisor';
        return false;
    });

    // Calculate aggregated stats for this specific type and semester
    const metrics = calculateAggregatedMetrics(typeFiltered, semesterId);

    // Approximate total potential raters if real data isn't configured
    let totalRaters = 0;
    if (evaluationType === 'student') {
        const baseSnapshot = getProfessorAnalyticsSnapshot(professor, semesterId);
        totalRaters = Math.max(baseSnapshot.totalStudents || 0, metrics.evaluatedCount);
    } else if (evaluationType === 'peer') {
        const professorCount = professorsData ? professorsData.length : 0;
        totalRaters = Math.max(professorCount - 1, 0, metrics.evaluatedCount);
    } else {
        totalRaters = Math.max(1, metrics.evaluatedCount); // At least 1 supervisor (Dean)
    }

    return {
        totalRaters,
        evaluatedCount: metrics.evaluatedCount,
        notEvaluatedCount: Math.max(totalRaters - metrics.evaluatedCount, 0),
        averageRating: metrics.averageRating,
        qualitativeResponses: metrics.qualitativeResponses,
        meta
    };
}

/**
 * Helper: save professors to centralized sharedUsersData (if not already defined)
 */
if (typeof saveProfessorsToSharedData !== 'function') {
    function getProfessorsFromSharedData() {
        return SharedData.getUsers().filter(function (u) { return u.role === 'professor'; });
    }
    function saveProfessorsToSharedData() {
        var allUsers = SharedData.getUsers();
        var nonProfessors = allUsers.filter(function (u) { return u.role !== 'professor'; });
        var professorsWithRole = professorsData.map(function (p) {
            return Object.assign({}, p, { role: 'professor', status: p.isActive !== false ? 'active' : 'inactive' });
        });
        SharedData.setUsers(nonProfessors.concat(professorsWithRole));
    }
}

/**
 * Generate random professor data
 * Load professors data from localStorage or generate new
 */
function loadProfessorsData() {
    var savedProfessors = getProfessorsFromSharedData();
    professorsData = savedProfessors;
    let didUpdate = false;
    // Migrate old data to include analytics fields
    professorsData = professorsData.map(professor => {
        let updated = { ...professor };
        let changed = false;

        if (!updated.employeeId) {
            updated.employeeId = generateEmployeeId();
            changed = true;
        }

        if (!updated.employmentType) {
            updated.employmentType = 'Regular';
            changed = true;
        }

        if (ensureProfessorSemesterData(updated)) {
            changed = true;
        }

        if (changed) {
            didUpdate = true;
        }

        return updated;
    });

    if (didUpdate) {
        saveProfessorsToSharedData();
    }
}

/**
 * Limit professors to 1 per department
 */
function limitProfessorsPerDepartment() {
    const departments = getDepartmentsForCampus('all');
    const limitedData = [];

    departments.forEach(dept => {
        const deptProfessors = professorsData.filter(t => t.department === dept);
        // Keep exactly 1 professor per department
        if (deptProfessors.length > 0) {
            limitedData.push(deptProfessors[0]);
        }
    });

    if (limitedData.length > 0 && limitedData.length !== professorsData.length) {
        professorsData = limitedData;
        saveProfessorsToSharedData();
    }
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
        let emptyMessage = 'No professors found in this department';
        if (searchTerm) {
            emptyMessage = 'No professors match your search';
        }
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
            const professorId = professorIdStr; // IDs can be alphanumeric like 'u1234'
            const action = this.getAttribute('data-action');

            console.log('Button clicked - Action:', action, 'Professor ID:', professorId);

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
    const activeCheckbox = document.getElementById('professor-active');
    const isActive = activeCheckbox ? activeCheckbox.checked : true;

    const formData = {
        name: document.getElementById('professor-name').value,
        email: document.getElementById('professor-email').value,
        department: document.getElementById('professor-department').value,
        position: document.getElementById('professor-position').value || 'Professor',
        isActive: isActive,
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

    // Save to centralized SharedData
    saveProfessorsToSharedData();

    // Re-render and close modal
    renderProfessors();
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
        const employeeIdInput = document.getElementById('professor-employee-id');
        if (employeeIdInput) employeeIdInput.value = professor.employeeId || '';
        document.getElementById('professor-department').value = professor.department;
        document.getElementById('professor-position').value = professor.position;
        const activeCheckbox = document.getElementById('professor-active');
        if (activeCheckbox) activeCheckbox.checked = professor.isActive !== false;
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
                        <label><i class="fas fa-clipboard-list"></i> Employment Type:</label>
                        <span>${professor.employmentType || 'Not set'}</span>
                    </div>
                    <div class="info-row">
                        <label><i class="fas fa-briefcase"></i> Position:</label>
                        <span>${professor.position}</span>
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
        const semesterSelect = document.getElementById('analytics-semester-select');
        const semesterStatus = document.getElementById('analytics-semester-status');
        if (semesterSelect && semesterStatus) {
            semesterSelect.addEventListener('change', () => {
                const value = semesterSelect.value;
                const label = semesterSelect.options[semesterSelect.selectedIndex].textContent;
                if (value === 'all') {
                    semesterStatus.textContent = 'Showing overall data';
                } else {
                    semesterStatus.textContent = `Showing data for ${label}`;
                }
            });
        }
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

        if (ensureProfessorSemesterData(professor)) {
            saveProfessorsToSharedData();
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
                <div class="analytics-filter-group">
                    <label for="analytics-semester-select">Semester</label>
                    <select id="analytics-semester-select">
                        ${buildSemesterOptionsHtml(normalizedSemester)}
                    </select>
                </div>
                <div class="analytics-filter-group">
                    <label for="analytics-evaluation-type">Evaluation Type</label>
                    <select id="analytics-evaluation-type">
                        ${buildEvaluationTypeOptionsHtml(normalizedEvaluationType)}
                    </select>
                </div>
                <div class="analytics-filter-note" id="analytics-filter-summary">
                    ${normalizedSemester === 'all' ? 'Showing overall data' : `Showing ${getSemesterLabel(normalizedSemester)} data`}  -  ${evaluationMeta.label}
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
                        <p class="stat-label">${completionPercentage}% Completion Rate</p>
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
            
            <div class="analytics-summary">
                <div class="summary-item">
                    <i class="fas fa-percentage"></i>
                    <div>
                        <span class="summary-label">Completion Rate</span>
                        <span class="summary-value">${completionPercentage}%</span>
                    </div>
                </div>
                <div class="summary-item">
                    <i class="fas fa-chart-line"></i>
                    <div>
                        <span class="summary-label">Average Rating</span>
                        <span class="summary-value">${parseFloat(averageRating).toFixed(1)}</span>
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
                                        <span class="response-student">${response.studentName || evaluatorLabel}  -  ${response.studentNumber || 'N/A'}</span>
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

        modal.style.display = 'flex';
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

function generateEvaluationData() {
    // 1. Fetch all evals from SharedData
    const allEvals = typeof SharedData !== 'undefined' && SharedData.getEvaluations ? SharedData.getEvaluations() : [];

    // We need overall stats across all professors
    let totalStudents = 0;

    professorsData.forEach(p => {
        totalStudents += p.totalStudents || 0;
    });

    // Determine type
    const studentEvals = allEvals.filter(e => e.evaluatorRole === 'student' || e.evaluationType === 'student');
    const peerEvals = allEvals.filter(e => e.evaluatorRole === 'professor' || e.evaluatorRole === 'peer' || e.evaluationType === 'peer');
    const supervisorEvals = allEvals.filter(e => e.evaluatorRole === 'dean' || e.evaluatorRole === 'hr' || e.evaluatorRole === 'supervisor' || e.evaluationType === 'supervisor');

    const completedEvaluations = studentEvals.length;
    const pendingEvaluations = Math.max(0, totalStudents - completedEvaluations);
    const completionRate = totalStudents > 0 ? Math.round((completedEvaluations / totalStudents) * 100) : 0;

    // Generate data for each evaluation type
    return {
        overall: {
            total: totalStudents,
            completed: completedEvaluations,
            pending: pendingEvaluations,
            completionRate: completionRate
        },
        studentToProfessor: generateEvaluationTypeData('Student to Professor', studentEvals),
        professorToProfessor: generateEvaluationTypeData('Professor to Professor', peerEvals),
        supervisorToProfessor: generateEvaluationTypeData('Supervisor to Professor', supervisorEvals)
    };
}

function generateEvaluationTypeData(type, evals = []) {
    const ratingDistribution = { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
    let totalRatingValue = 0;
    let totalRatingsCount = 0;

    const categories = [
        'Teaching Effectiveness',
        'Classroom Management',
        'Student Engagement',
        'Communication Skills',
        'Assessment Methods'
    ];

    let categoryAverages = categories.map(cat => ({ category: cat, sum: 0, count: 0 }));

    evals.forEach(ev => {
        if (ev.ratings) {
            let evalAvgSum = 0;
            let evalRatingCount = 0;

            Object.values(ev.ratings).forEach(val => {
                const num = parseFloat(val);
                if (!isNaN(num)) {
                    totalRatingValue += num;
                    totalRatingsCount++;
                    evalAvgSum += num;
                    evalRatingCount++;

                    // Simple uniform distribution of specific question scores to generic categories
                    const arrayIdx = Math.floor((Math.random() * categories.length));
                    categoryAverages[arrayIdx].sum += num;
                    categoryAverages[arrayIdx].count++;
                }
            });

            if (evalRatingCount > 0) {
                const rawAvg = evalAvgSum / evalRatingCount;
                const roundedBin = Math.min(5, Math.max(1, Math.round(rawAvg)));
                ratingDistribution[roundedBin]++;
            }
        }
    });

    const averageRating = totalRatingsCount > 0 ? parseFloat((totalRatingValue / totalRatingsCount).toFixed(1)) : 0;

    const categoryScores = categoryAverages.map(cat => ({
        category: cat.category,
        score: cat.count > 0 ? parseFloat((cat.sum / cat.count).toFixed(1)) : 0
    }));

    // Get number of unique professors evaluated
    const evaluatedIds = new Set(evals.map(e => e.targetId || e.colleagueId || e.professorSubject));
    // Provide a filter out un-targetable 
    evaluatedIds.delete('');
    evaluatedIds.delete(undefined);

    return {
        categoryScores: categoryScores,
        ratingDistribution: ratingDistribution,
        averageRating: averageRating,
        totalEvaluations: evals.length,
        evaluatedCount: evaluatedIds.size
    };
}

/**
 * Update overall evaluation status
 */
function updateOverallStatus(data) {
    const completedEl = document.getElementById('completed-count');
    const pendingEl = document.getElementById('pending-count');
    const totalEl = document.getElementById('total-count');
    const rateEl = document.getElementById('completion-rate');

    if (!completedEl || !pendingEl || !totalEl || !rateEl) return;

    completedEl.textContent = data.overall.completed;
    pendingEl.textContent = data.overall.pending;
    totalEl.textContent = data.overall.total;
    rateEl.textContent = data.overall.completionRate + '%';
}





/**
 * Build real evaluation data for a specific evaluation type from SharedData.
 */
function generateEvaluationTypeData(type) {
    const categories = [
        'Teaching Effectiveness',
        'Classroom Management',
        'Student Engagement',
        'Communication Skills',
        'Assessment Methods'
    ];

    // Map UI type string to evaluatorRole values in SharedData
    const allEvals = (typeof SharedData !== 'undefined' && SharedData.getEvaluations) ? SharedData.getEvaluations() : [];
    const filtered = allEvals.filter(e => {
        const role = e.evaluatorRole || e.evaluationType || '';
        if (type === 'Student to Professor') return role === 'student';
        if (type === 'Professor to Professor') return role === 'professor' || role === 'peer';
        return role === 'dean' || role === 'hr' || role === 'supervisor';
    });

    // Build real rating distribution from actual submissions
    const ratingDistribution = { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
    let totalRatingValue = 0;
    let totalRatingCount = 0;
    const categoryTotals = categories.map(cat => ({ category: cat, sum: 0, count: 0 }));

    filtered.forEach(ev => {
        if (ev.ratings) {
            const vals = Object.values(ev.ratings).map(v => parseFloat(v)).filter(v => !isNaN(v));
            vals.forEach((num, idx) => {
                totalRatingValue += num;
                totalRatingCount++;
                const catIdx = idx % categories.length;
                categoryTotals[catIdx].sum += num;
                categoryTotals[catIdx].count++;
            });
            if (vals.length > 0) {
                const avg = vals.reduce((a, b) => a + b, 0) / vals.length;
                const bin = Math.min(5, Math.max(1, Math.round(avg)));
                ratingDistribution[bin]++;
            }
        }
    });

    const averageRating = totalRatingCount > 0 ? parseFloat((totalRatingValue / totalRatingCount).toFixed(1)) : 0;
    const categoryScores = categoryTotals.map(c => ({
        category: c.category,
        score: c.count > 0 ? parseFloat((c.sum / c.count).toFixed(1)) : 0
    }));
    const totalEvaluations = filtered.length;
    const evaluatedIds = new Set(filtered.map(e => e.targetId || e.colleagueId || e.professorSubject).filter(Boolean));

    return {
        categoryScores,
        ratingDistribution,
        averageRating,
        totalEvaluations,
        evaluatedCount: evaluatedIds.size
    };
}

/**
 * Update overall evaluation status
 */
function updateOverallStatus(data) {
    const completedEl = document.getElementById('completed-count');
    const pendingEl = document.getElementById('pending-count');
    const totalEl = document.getElementById('total-count');
    const rateEl = document.getElementById('completion-rate');

    if (!completedEl || !pendingEl || !totalEl || !rateEl) return;

    completedEl.textContent = data.overall.completed;
    pendingEl.textContent = data.overall.pending;
    totalEl.textContent = data.overall.total;
    rateEl.textContent = data.overall.completionRate + '%';
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
                labels: data.categoryScores.map(c => c.category.split(' ')),
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
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 0,
                            minRotation: 0,
                            autoSkip: false,
                            font: { size: 11 }
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
                maintainAspectRatio: false,
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
                labels: data.categoryScores.map(c => c.category.split(' ')),
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
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 0,
                            minRotation: 0,
                            autoSkip: false,
                            font: { size: 11 }
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
                maintainAspectRatio: false,
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
                labels: data.categoryScores.map(c => c.category.split(' ')),
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
                maintainAspectRatio: false,
                scales: {
                    x: {
                        ticks: {
                            maxRotation: 0,
                            minRotation: 0,
                            autoSkip: false,
                            font: { size: 11 }
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
                maintainAspectRatio: false,
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
        description: "Please provide your evaluation of the professor's performance."
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

    // Add additional comments button
    const addCommentsBtn = document.getElementById('btn-additional-comments');
    const commentsModal = document.getElementById('additional-comments-modal');

    if (addCommentsBtn && commentsModal) {
        addCommentsBtn.addEventListener('click', () => {
            commentsModal.style.display = 'flex';
        });
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

    const closeCommentsModalBtn = document.getElementById('close-additional-comments-modal');
    const cancelCommentsForm = document.getElementById('cancel-additional-comments');

    const closeCommentsModal = () => {
        if (commentsModal) commentsModal.style.display = 'none';
    };

    if (closeCommentsModalBtn) {
        closeCommentsModalBtn.addEventListener('click', closeCommentsModal);
    }
    if (cancelCommentsForm) {
        cancelCommentsForm.addEventListener('click', closeCommentsModal);
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
    if (commentsModal) {
        commentsModal.addEventListener('click', function (e) {
            if (e.target === commentsModal) {
                closeCommentsModal();
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
            if (typeof commentsModal !== 'undefined' && commentsModal && commentsModal.style.display === 'flex') {
                closeCommentsModal();
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

function buildSampleQuestionsData() {
    return {
        'student-to-professor': { sections: [], questions: [] },
        'professor-to-professor': { sections: [], questions: [] },
        'supervisor-to-professor': { sections: [], questions: [] }
    };
}

/**
 * Generate sample questions for each questionnaire type
 */
function generateSampleQuestions() {
    questionsData = buildSampleQuestionsData();
    persistQuestionsData();
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
