// Admin Panel JavaScript - Dashboard Functionality

document.addEventListener('DOMContentLoaded', function () {
    initializeAdminPanel();
});

let activeUserSearchTerm = '';
let editingUserId = null;
let selectedCampusId = null;
let subjectManagementState = { subjects: [], offerings: [], enrollments: [] };
let editingSubjectId = null;
let selectedOfferingForStudents = null;
let offeringSearchAppliedTerm = '';

// Users loaded from PHP API (or SharedData centralized storage)
let adminUsers = [];
let adminEvaluationOverviewChartInstance = null;
let adminSemestralPerformanceChartInstance = null;
let announcementComposerReady = false;
let credentialDistributorParsedRows = [];
let credentialDistributorFailures = [];

/**
 * Fetch users from PHP API, falls back to hardcoded data
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
            console.warn('[AdminPanel] Falling back to SharedData users:', error);
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
    setupProgramManager();
    setupUserSearch();
    setupEditUserModal();
    loadDashboardData();
    initializeCharts();
    loadActivityList();
    loadUsersByOrganization();
    setupQuickActions();
    setupAnnouncementComposer();
    setupActivityLogButton();
    setupSecuritySettings();
    setupCredentialDistributor();
    setupAdminProfilePhotoUpload();
    setupAdminProfileActions();
    setupAdminChangeEmailForm();
    setupAdminProfilePasswordForm();
    setupBulkRegister();
    setupSubjectManagement();
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
const BULK_HEADER_ALIASES = {
    name: 'name',
    fullname: 'name',
    email: 'email',
    emailaddress: 'email',
    gmail: 'email',
    role: 'role',
    userrole: 'role',
    campus: 'campus',
    campusid: 'campus',
    campusname: 'campus',
    password: 'password',
    pass: 'password',
    initialpassword: 'password',
    department: 'department',
    dept: 'department',
    institute: 'department',
    employeeid: 'employeeId',
    empid: 'employeeId',
    employmenttype: 'employmentType',
    regulartemporary: 'employmentType',
    regularortemporary: 'employmentType',
    position: 'position',
    title: 'position',
    jobtitle: 'position',
    studentnumber: 'studentNumber',
    studentno: 'studentNumber',
    studno: 'studentNumber',
    studentid: 'studentNumber',
    yearsection: 'yearSection',
    yearandsection: 'yearSection',
    yearsec: 'yearSection',
    section: 'yearSection',
    program: 'programCode',
    programcode: 'programCode'
};

const BULK_ALLOWED_ROLES = new Set(['student', 'professor', 'dean', 'osa', 'vpaa', 'hr', 'admin']);
const BULK_UNASSIGNED_DEPARTMENT = 'UNASSIGNED';
const BULK_SIMPLE_EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const CREDENTIAL_DISTRIBUTOR_HEADER_ALIASES = {
    name: 'name',
    fullname: 'name',
    email: 'email',
    emailaddress: 'email',
    gmail: 'email',
    role: 'role',
    campus: 'campus',
    employee: 'employee',
    employeeid: 'employee',
    employeenumber: 'employee',
    studentnumber: 'employee',
    employeeorstudentnumber: 'employee',
    employee_or_student_number: 'employee',
    password: 'password',
    pass: 'password',
    initialpassword: 'password',
};

function normalizeRoleCode(role) {
    return String(role || '').trim().toLowerCase();
}

function normalizeBulkHeaderKey(rawKey) {
    return String(rawKey || '')
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]/g, '');
}

function normalizeBulkLookupKey(value) {
    return String(value || '').trim().toLowerCase().replace(/\s+/g, ' ');
}

function normalizeBulkText(value) {
    if (value === null || value === undefined) return '';
    return String(value).trim();
}

function sectionTokenToNumber(token) {
    const value = normalizeBulkText(token).toUpperCase();
    if (!value) return '';
    if (/^\d+$/.test(value)) return String(parseInt(value, 10));
    if (/^[A-Z]$/.test(value)) return String(value.charCodeAt(0) - 64);
    return '';
}

function normalizeYearSection(value) {
    const raw = normalizeBulkText(value);
    if (!raw) return '';

    const compact = raw.replace(/\s+/g, '');
    let match = compact.match(/^(\d+)-(\d+)$/);
    if (match) {
        return `${parseInt(match[1], 10)}-${parseInt(match[2], 10)}`;
    }

    match = raw.match(/^(\d+)\s*-\s*([A-Za-z0-9])$/);
    if (match) {
        const sectionNo = sectionTokenToNumber(match[2]);
        if (!sectionNo) return '';
        return `${parseInt(match[1], 10)}-${sectionNo}`;
    }

    const yearMatch = raw.match(/(\d+)\s*(?:st|nd|rd|th)?\s*year/i);
    const sectionMatch = raw.match(/section\s*([A-Za-z0-9]+)/i);
    if (yearMatch && sectionMatch) {
        const yearNo = parseInt(yearMatch[1], 10);
        const sectionNo = sectionTokenToNumber(sectionMatch[1]);
        if (!sectionNo) return '';
        return `${yearNo}-${sectionNo}`;
    }

    return '';
}

function isCanonicalYearSection(value) {
    return /^\d+-\d+$/.test(normalizeBulkText(value));
}

function normalizeOfferingSection(value) {
    const raw = normalizeBulkText(value);
    if (!raw) return '';
    const match = raw.match(/^(\d+)\s*[\/-]\s*(\d+)$/);
    if (!match) return '';
    const yearLevel = parseInt(match[1], 10);
    const sectionNo = parseInt(match[2], 10);
    return `${yearLevel}/${sectionNo}`;
}

function mapBulkRowToCanonical(rawRow) {
    const mapped = {
        name: '',
        email: '',
        role: '',
        campus: '',
        password: '',
        department: '',
        employeeId: '',
        employmentType: '',
        position: '',
        studentNumber: '',
        yearSection: '',
        programCode: ''
    };

    Object.entries(rawRow || {}).forEach(([key, value]) => {
        const normalizedHeader = normalizeBulkHeaderKey(key);
        const canonicalKey = BULK_HEADER_ALIASES[normalizedHeader];
        if (!canonicalKey) return;
        mapped[canonicalKey] = normalizeBulkText(value);
    });

    return mapped;
}

function normalizeBulkDepartment(rawDepartment, campusSlug, departmentLookupByCampus) {
    const trimmed = normalizeBulkText(rawDepartment);
    if (!trimmed) return BULK_UNASSIGNED_DEPARTMENT;

    const campusMap = departmentLookupByCampus.get(campusSlug);
    if (!campusMap || campusMap.size === 0) return trimmed.toUpperCase();

    const primaryToken = trimmed.includes('-') ? trimmed.split('-')[0].trim() : trimmed;
    const mappedByPrimary = campusMap.get(normalizeBulkLookupKey(primaryToken));
    if (mappedByPrimary) return mappedByPrimary;

    const mappedByWhole = campusMap.get(normalizeBulkLookupKey(trimmed));
    if (mappedByWhole) return mappedByWhole;

    return trimmed.toUpperCase();
}

function normalizeBulkEmploymentType(rawEmploymentType, role) {
    if (role === 'student') return '';
    const trimmed = normalizeBulkText(rawEmploymentType);
    if (!trimmed) return 'Regular';

    const lower = trimmed.toLowerCase();
    if (lower === 'regular') return 'Regular';
    if (lower === 'temporary') return 'Temporary';
    return trimmed;
}

function normalizeProgramCode(value) {
    return normalizeBulkText(value).toUpperCase();
}

function buildBulkCampusMap(existingUsers = []) {
    const map = new Map();
    const users = Array.isArray(existingUsers) ? existingUsers : [];

    function registerCampus(rawId, rawName) {
        const id = normalizeBulkText(rawId).toLowerCase();
        if (!id || id === 'all') return;
        const name = normalizeBulkText(rawName);
        map.set(normalizeBulkLookupKey(id), id);
        if (name) {
            map.set(normalizeBulkLookupKey(name), id);
            const nameWithoutCampus = name.replace(/\bcampus\b/ig, '').trim();
            if (nameWithoutCampus) {
                map.set(normalizeBulkLookupKey(nameWithoutCampus), id);
            }
        }
    }

    const campuses = SharedData.getCampuses().filter(campus => campus && campus.id && campus.id !== 'all');
    campuses.forEach(campus => {
        registerCampus(campus.id, campus.name);
    });

    users.forEach(user => {
        registerCampus(user.campus, user.campus);
    });

    if (map.size === 0) {
        ['basa', 'villamor', 'medelin', 'mactan', 'fernando'].forEach(slug => registerCampus(slug, slug));
    }

    return map;
}

function buildBulkDepartmentMap(existingUsers = []) {
    const campusDepartmentMap = new Map();
    const users = Array.isArray(existingUsers) ? existingUsers : [];

    function registerDepartment(campusValue, departmentValue) {
        const campusSlug = normalizeBulkText(campusValue).toLowerCase();
        const canonicalDepartment = normalizeBulkText(departmentValue).toUpperCase();
        if (!campusSlug || campusSlug === 'all' || !canonicalDepartment || canonicalDepartment === BULK_UNASSIGNED_DEPARTMENT) {
            return;
        }

        if (!campusDepartmentMap.has(campusSlug)) {
            campusDepartmentMap.set(campusSlug, new Map());
        }

        const departmentMap = campusDepartmentMap.get(campusSlug);
        departmentMap.set(normalizeBulkLookupKey(canonicalDepartment), canonicalDepartment);
        const primaryToken = canonicalDepartment.includes('-')
            ? canonicalDepartment.split('-')[0].trim()
            : canonicalDepartment;
        departmentMap.set(normalizeBulkLookupKey(primaryToken), canonicalDepartment);
    }

    const campuses = SharedData.getCampuses().filter(campus => campus && campus.id && campus.id !== 'all');
    campuses.forEach(campus => {
        const departments = Array.isArray(campus.departments) ? campus.departments : [];
        departments.forEach(department => registerDepartment(campus.id, department));
    });

    users.forEach(user => {
        registerDepartment(user.campus, user.department || user.institute);
    });

    return campusDepartmentMap;
}

function buildBulkProgramMap() {
    const map = new Map();
    const programs = SharedData.getPrograms ? SharedData.getPrograms() : [];
    programs.forEach(program => {
        const campusSlug = String(program && program.campusSlug || '').toLowerCase();
        const departmentCode = String(program && program.departmentCode || '').toLowerCase();
        const programCode = normalizeProgramCode(program && program.programCode);
        if (!campusSlug || !departmentCode || !programCode) {
            return;
        }
        map.set(`${campusSlug}|${departmentCode}|${programCode.toLowerCase()}`, {
            programCode,
            programName: normalizeBulkText(program.programName),
        });
    });
    return map;
}

function resolveBulkProgramRecord(campusSlug, departmentCode, rawProgramCode, programMap) {
    const programCode = normalizeProgramCode(rawProgramCode);
    if (!programCode) return null;
    const key = `${String(campusSlug || '').toLowerCase()}|${String(departmentCode || '').toLowerCase()}|${programCode.toLowerCase()}`;
    const record = programMap.get(key);
    if (!record) return null;
    return {
        programCode: record.programCode,
        programName: record.programName,
    };
}

function generateRandomPassword(length = 6) {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    let output = '';
    for (let index = 0; index < length; index++) {
        const randomIndex = Math.floor(Math.random() * chars.length);
        output += chars.charAt(randomIndex);
    }
    return output;
}

function escapeCsvCell(value) {
    const text = String(value == null ? '' : value);
    if (/[",\n]/.test(text)) {
        return `"${text.replace(/"/g, '""')}"`;
    }
    return text;
}

function downloadBulkCredentialsCsv(credentialRows) {
    if (!Array.isArray(credentialRows) || credentialRows.length === 0) return;

    const header = ['name', 'email', 'role', 'campus', 'employee_or_student_number', 'password'];
    const lines = [header.join(',')];
    credentialRows.forEach(row => {
        lines.push([
            escapeCsvCell(row.name),
            escapeCsvCell(row.email),
            escapeCsvCell(row.role),
            escapeCsvCell(row.campus),
            escapeCsvCell(row.idNumber),
            escapeCsvCell(row.password)
        ].join(','));
    });

    const csvContent = '\uFEFF' + lines.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `bulk_register_credentials_${stamp}.csv`;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
}

function downloadBulkInvalidRowsCsv(rowErrors) {
    if (!Array.isArray(rowErrors) || rowErrors.length === 0) return;

    const header = ['row', 'error'];
    const lines = [header.join(',')];
    rowErrors.forEach(item => {
        lines.push([
            escapeCsvCell(item && item.rowNumber ? item.rowNumber : ''),
            escapeCsvCell(item && item.error ? item.error : '')
        ].join(','));
    });

    const csvContent = '\uFEFF' + lines.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `bulk_register_invalid_rows_${stamp}.csv`;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
}

function resolveProtectedAdminEmail(users = []) {
    const session = SharedData.getSession ? SharedData.getSession() : null;
    if (!session || normalizeRoleCode(session.role) !== 'admin') {
        return '';
    }

    const directCandidates = [
        normalizeBulkText(session.email).toLowerCase(),
        normalizeBulkText(session.username).toLowerCase()
    ].filter(Boolean);

    for (const candidate of directCandidates) {
        if (BULK_SIMPLE_EMAIL_PATTERN.test(candidate)) {
            return candidate;
        }
    }

    const sessionUserId = normalizeBulkText(session.userId);
    if (!sessionUserId) {
        return '';
    }

    const matchedAdmin = (Array.isArray(users) ? users : []).find(user =>
        String(user && user.id || '') === sessionUserId &&
        normalizeRoleCode(user && user.role) === 'admin'
    );
    if (!matchedAdmin) {
        return '';
    }

    const matchedEmail = normalizeBulkText(matchedAdmin.email).toLowerCase();
    return BULK_SIMPLE_EMAIL_PATTERN.test(matchedEmail) ? matchedEmail : '';
}

function readExcelRows(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();

        reader.onerror = () => reject(new Error('Unable to read the selected Excel file.'));

        reader.onload = (event) => {
            try {
                const arrayBuffer = event.target && event.target.result;
                const workbook = XLSX.read(arrayBuffer, { type: 'array' });
                const firstSheetName = workbook.SheetNames && workbook.SheetNames[0];
                if (!firstSheetName) {
                    resolve([]);
                    return;
                }

                const worksheet = workbook.Sheets[firstSheetName];
                const rows = XLSX.utils.sheet_to_json(worksheet, { defval: '' });
                resolve(Array.isArray(rows) ? rows : []);
            } catch (error) {
                reject(error);
            }
        };

        reader.readAsArrayBuffer(file);
    });
}

function mapCredentialDistributorRow(rawRow, rowNumber) {
    const mapped = {
        rowNumber,
        name: '',
        email: '',
        role: '',
        campus: '',
        employee: '',
        password: ''
    };

    Object.entries(rawRow || {}).forEach(([key, value]) => {
        const normalizedHeader = normalizeBulkHeaderKey(key);
        const canonicalKey = CREDENTIAL_DISTRIBUTOR_HEADER_ALIASES[normalizedHeader];
        if (!canonicalKey) return;
        mapped[canonicalKey] = normalizeBulkText(value);
    });

    return mapped;
}

function extractCredentialDistributorRows(rawRows) {
    const rows = [];
    let hasRecognizedColumn = false;

    (Array.isArray(rawRows) ? rawRows : []).forEach((rawRow, index) => {
        if (!rawRow || typeof rawRow !== 'object') return;

        const recognized = Object.keys(rawRow).some(key => {
            return Boolean(CREDENTIAL_DISTRIBUTOR_HEADER_ALIASES[normalizeBulkHeaderKey(key)]);
        });
        if (recognized) hasRecognizedColumn = true;

        const rowNumber = index + 2;
        const mapped = mapCredentialDistributorRow(rawRow, rowNumber);
        const hasAnyContent = Object.values(mapped).some((value, valueIndex) => {
            if (valueIndex === 0) return false;
            return normalizeBulkText(value) !== '';
        });
        if (!hasAnyContent) return;

        rows.push(mapped);
    });

    return { rows, hasRecognizedColumn };
}

function downloadCredentialDistributorFailuresCsv(failures) {
    if (!Array.isArray(failures) || failures.length === 0) return;

    const header = ['row', 'email', 'reason'];
    const lines = [header.join(',')];
    failures.forEach(item => {
        lines.push([
            escapeCsvCell(item && item.rowNumber ? item.rowNumber : ''),
            escapeCsvCell(item && item.email ? item.email : ''),
            escapeCsvCell(item && item.reason ? item.reason : 'Unknown error')
        ].join(','));
    });

    const csvContent = '\uFEFF' + lines.join('\n');
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `credential_distribution_failures_${stamp}.csv`;
    document.body.appendChild(anchor);
    anchor.click();
    document.body.removeChild(anchor);
    URL.revokeObjectURL(url);
}

function setCredentialDistributorFeedback(type, message) {
    const feedback = document.getElementById('credential-distributor-feedback');
    if (!feedback) return;

    feedback.classList.remove('success', 'error', 'info');
    feedback.textContent = '';

    if (!message) {
        feedback.style.display = 'none';
        return;
    }

    feedback.classList.add(type || 'info');
    feedback.textContent = message;
    feedback.style.display = 'block';
}

function setCredentialDistributorBusyState(isBusy) {
    const selectBtn = document.getElementById('credential-distributor-select-btn');
    const sendBtn = document.getElementById('credential-distributor-send-btn');
    const input = document.getElementById('credential-distributor-input');

    [selectBtn, sendBtn].forEach(button => {
        if (button) button.disabled = !!isBusy;
    });
    if (input) input.disabled = !!isBusy;
}

function setCredentialDistributorLoadingState(isLoading, message, progressText) {
    const overlay = document.getElementById('credential-distributor-loading');
    const textEl = document.getElementById('credential-distributor-loading-text');
    const progressEl = document.getElementById('credential-distributor-loading-progress');

    if (overlay) {
        overlay.classList.toggle('active', Boolean(isLoading));
        overlay.setAttribute('aria-hidden', isLoading ? 'false' : 'true');
    }
    if (textEl && typeof message === 'string' && message.trim() !== '') {
        textEl.textContent = message;
    }
    if (progressEl) {
        progressEl.textContent = typeof progressText === 'string' ? progressText : '';
    }
}

function setupCredentialDistributor() {
    const selectFileBtn = document.getElementById('credential-distributor-select-btn');
    const fileInput = document.getElementById('credential-distributor-input');
    const fileNameLabel = document.getElementById('credential-distributor-file-name');
    const sendBtn = document.getElementById('credential-distributor-send-btn');
    const downloadFailuresBtn = document.getElementById('credential-download-failures-btn');

    if (!fileInput || !selectFileBtn || !sendBtn) {
        return;
    }

    const session = SharedData.getSession ? SharedData.getSession() : null;
    const actor = {
        userId: session && session.userId ? session.userId : '',
        email: session && session.email ? session.email : '',
        username: session && session.username ? session.username : '',
        role: session && session.role ? session.role : '',
    };

    function clearFailures() {
        credentialDistributorFailures = [];
        if (downloadFailuresBtn) {
            downloadFailuresBtn.style.display = 'none';
        }
    }

    selectFileBtn.addEventListener('click', () => {
        fileInput.click();
    });

    fileInput.addEventListener('change', async () => {
        const file = fileInput.files && fileInput.files[0];
        if (!file) return;

        clearFailures();
        credentialDistributorParsedRows = [];
        if (fileNameLabel) fileNameLabel.textContent = file.name;

        const lowerName = file.name.toLowerCase();
        const isExcel = lowerName.endsWith('.xlsx') || lowerName.endsWith('.xls');
        if (!isExcel) {
            setCredentialDistributorFeedback('error', 'Please select an Excel file (.xlsx or .xls).');
            fileInput.value = '';
            return;
        }
        if (typeof XLSX === 'undefined') {
            setCredentialDistributorFeedback('error', 'Excel parser is not loaded. Please refresh and try again.');
            fileInput.value = '';
            return;
        }

        setCredentialDistributorBusyState(true);
        setCredentialDistributorFeedback('info', 'Reading Excel file...');
        try {
            const rawRows = await readExcelRows(file);
            const parsed = extractCredentialDistributorRows(rawRows);
            if (!parsed.hasRecognizedColumn) {
                setCredentialDistributorFeedback('error', 'No recognized columns found. Expected headers like email/password.');
                fileInput.value = '';
                return;
            }
            if (!parsed.rows.length) {
                setCredentialDistributorFeedback('error', 'No importable rows found in the first worksheet.');
                fileInput.value = '';
                return;
            }

            credentialDistributorParsedRows = parsed.rows;
            setCredentialDistributorFeedback(
                'success',
                `Loaded ${credentialDistributorParsedRows.length} row(s). Click "Distribute Credentials" to send emails.`
            );
        } catch (error) {
            console.error('[AdminPanel] Failed to parse credential distributor file.', error);
            setCredentialDistributorFeedback('error', 'Failed to parse Excel file: ' + (error.message || 'Unknown error'));
        } finally {
            setCredentialDistributorBusyState(false);
            fileInput.value = '';
        }
    });

    sendBtn.addEventListener('click', () => {
        if (!Array.isArray(credentialDistributorParsedRows) || credentialDistributorParsedRows.length === 0) {
            setCredentialDistributorFeedback('error', 'Please upload a valid Excel file before distributing credentials.');
            return;
        }

        const rowCount = credentialDistributorParsedRows.length;
        setCredentialDistributorBusyState(true);
        clearFailures();
        setCredentialDistributorFeedback('', '');
        setCredentialDistributorLoadingState(
            true,
            `Sending login credentials to ${rowCount} recipient(s) via email. This may take a few minutes — please do not close this page.`,
            `Processing ${rowCount} row(s)...`
        );

        // Use setTimeout so the loading overlay renders before the synchronous XHR blocks
        setTimeout(() => {
            try {
                const response = SharedData.bulkDistributeCredentials(credentialDistributorParsedRows, actor);
                const summary = response && response.summary ? response.summary : { total: 0, sent: 0, failed: 0 };
                const failures = Array.isArray(response && response.failures) ? response.failures : [];
                credentialDistributorFailures = failures;

                const lines = [
                    'Credential distribution completed.',
                    `Total: ${summary.total || 0}`,
                    `Sent: ${summary.sent || 0}`,
                    `Failed: ${summary.failed || 0}`
                ];
                if (failures.length > 0) {
                    lines.push('');
                    lines.push('Top failures:');
                    failures.slice(0, 10).forEach(item => {
                        const rowText = item && item.rowNumber ? `Row ${item.rowNumber}` : 'Row ?';
                        const emailText = item && item.email ? item.email : 'no-email';
                        const reason = item && item.reason ? item.reason : 'Unknown error';
                        lines.push(`- ${rowText} (${emailText}): ${reason}`);
                    });
                    if (failures.length > 10) {
                        lines.push(`- ... and ${failures.length - 10} more`);
                    }
                }

                setCredentialDistributorFeedback(failures.length > 0 ? 'error' : 'success', lines.join('\n'));
                if (downloadFailuresBtn) {
                    downloadFailuresBtn.style.display = failures.length > 0 ? 'inline-flex' : 'none';
                }
            } catch (error) {
                console.error('[AdminPanel] Credential distribution failed.', error);
                setCredentialDistributorFeedback('error', 'Credential distribution failed: ' + (error.message || 'Unknown error'));
            } finally {
                setCredentialDistributorLoadingState(false);
                setCredentialDistributorBusyState(false);
            }
        }, 100);
    });

    if (downloadFailuresBtn) {
        downloadFailuresBtn.addEventListener('click', () => {
            if (!credentialDistributorFailures.length) return;
            downloadCredentialDistributorFailuresCsv(credentialDistributorFailures);
        });
    }
}

function buildBulkUserFromRow(row, rowNumber, existingUsersByEmail, fileEmailSet, campusMap, departmentMap, programMap, importSeed, importIndex) {
    const name = normalizeBulkText(row.name);
    const email = normalizeBulkText(row.email).toLowerCase();
    const role = normalizeRoleCode(row.role);
    const rawCampus = normalizeBulkText(row.campus);

    if (!name || !email || !role || !rawCampus) {
        return { error: `Row ${rowNumber}: name, email, role, and campus are required.` };
    }

    if (!BULK_ALLOWED_ROLES.has(role)) {
        return { error: `Row ${rowNumber}: invalid role "${row.role}".` };
    }

    if (!BULK_SIMPLE_EMAIL_PATTERN.test(email)) {
        return { error: `Row ${rowNumber}: invalid email format.` };
    }

    if (fileEmailSet.has(email)) {
        return { error: `Row ${rowNumber}: duplicate email in uploaded file.` };
    }

    const campus = campusMap.get(normalizeBulkLookupKey(rawCampus));
    if (!campus) {
        return { error: `Row ${rowNumber}: unknown campus "${row.campus}".` };
    }

    const department = normalizeBulkDepartment(row.department, campus, departmentMap);
    const employeeId = normalizeBulkText(row.employeeId);
    const studentNumber = normalizeBulkText(row.studentNumber);
    const yearSection = normalizeYearSection(row.yearSection);
    const roleRequiresProgram = role === 'student' || role === 'professor';
    const resolvedProgram = resolveBulkProgramRecord(campus, department, row.programCode, programMap);
    const existingUser = existingUsersByEmail.get(email) || null;

    if (role === 'student') {
        if (!studentNumber || !yearSection) {
            return { error: `Row ${rowNumber}: student requires studentNumber and yearSection in Y-S format (e.g., 3-1).` };
        }
    } else if (!employeeId) {
        return { error: `Row ${rowNumber}: ${role} requires employeeId.` };
    }

    if (roleRequiresProgram) {
        if (!department || department === BULK_UNASSIGNED_DEPARTMENT) {
            return { error: `Row ${rowNumber}: ${role} requires a valid department for program lookup.` };
        }
        if (!normalizeProgramCode(row.programCode)) {
            return { error: `Row ${rowNumber}: ${role} requires program_code.` };
        }
        if (!resolvedProgram) {
            return { error: `Row ${rowNumber}: unknown program "${row.programCode}" for ${campus}/${department}.` };
        }
    }

    const providedPassword = normalizeBulkText(row.password);
    let resolvedPassword = providedPassword;
    let generatedPassword = '';

    if (!resolvedPassword) {
        if (existingUser) {
            resolvedPassword = normalizeBulkText(existingUser.password);
        } else {
            generatedPassword = generateRandomPassword(6);
            resolvedPassword = generatedPassword;
        }
    }

    const position = role === 'student' ? '' : normalizeBulkText(row.position);
    const employmentType = normalizeBulkEmploymentType(row.employmentType, role);
    const userId = existingUser ? existingUser.id : `u${importSeed}_${importIndex}`;

    const user = Object.assign({}, existingUser || {}, {
        id: userId,
        name,
        email,
        password: resolvedPassword,
        role,
        campus,
        department,
        institute: department,
        yearSection: role === 'student' ? yearSection : '',
        studentNumber: role === 'student' ? studentNumber : '',
        employeeId: role === 'student' ? '' : employeeId,
        employmentType,
        position,
        programCode: roleRequiresProgram ? resolvedProgram.programCode : '',
        programName: roleRequiresProgram ? resolvedProgram.programName : '',
        status: 'active',
        isActive: true
    });

    return {
        user,
        password: resolvedPassword,
        email,
        isUpdate: !!existingUser,
        usedProvidedPassword: !!providedPassword,
        generatedPassword
    };
}

function setBulkRegisterLoadingState(isLoading, message) {
    const overlay = document.getElementById('bulk-register-loading');
    const messageEl = document.getElementById('bulk-register-loading-text');
    const triggerBtn = document.getElementById('bulk-register-btn');

    if (overlay) {
        overlay.classList.toggle('active', Boolean(isLoading));
        overlay.setAttribute('aria-hidden', isLoading ? 'false' : 'true');
    }
    if (messageEl && typeof message === 'string' && message.trim() !== '') {
        messageEl.textContent = message;
    }
    if (triggerBtn) {
        triggerBtn.disabled = Boolean(isLoading);
        triggerBtn.classList.toggle('is-loading', Boolean(isLoading));
    }
}

function setupBulkRegister() {
    const btn = document.getElementById('bulk-register-btn');
    const input = document.getElementById('bulk-register-input');
    if (!btn || !input) return;

    btn.addEventListener('click', () => {
        input.click();
    });

    input.addEventListener('change', async () => {
        const file = input.files && input.files[0];
        if (!file) return;

        const lowerName = file.name.toLowerCase();
        const isExcel = lowerName.endsWith('.xlsx') || lowerName.endsWith('.xls');
        if (!isExcel) {
            alert('Please select an Excel file (.xlsx or .xls).');
            input.value = '';
            return;
        }

        if (typeof XLSX === 'undefined') {
            alert('Excel parser is not loaded. Please refresh the page and try again.');
            input.value = '';
            return;
        }

        try {
            setBulkRegisterLoadingState(true, 'Please wait while the system validates and saves your Excel file.');
            const rawRows = await readExcelRows(file);
            if (!rawRows.length) {
                alert('No importable rows found in the first worksheet.');
                input.value = '';
                return;
            }

            const hasRecognizedColumn = rawRows.some(rawRow =>
                Object.keys(rawRow || {}).some(key => Boolean(BULK_HEADER_ALIASES[normalizeBulkHeaderKey(key)]))
            );
            if (!hasRecognizedColumn) {
                alert('No recognized columns found. Please use the required import headers.');
                input.value = '';
                return;
            }

            const existingUsers = SharedData.getUsers();
            const usersToPersist = existingUsers.map(user => ({ ...user }));
            const protectedAdminEmail = resolveProtectedAdminEmail(usersToPersist);
            const existingUserIndexByEmail = new Map();
            usersToPersist.forEach((user, index) => {
                const emailKey = normalizeBulkText(user.email).toLowerCase();
                if (emailKey) {
                    existingUserIndexByEmail.set(emailKey, index);
                }
            });
            const existingUsersByEmail = new Map(
                usersToPersist
                    .map(user => [normalizeBulkText(user.email).toLowerCase(), user])
                    .filter(([email]) => Boolean(email))
            );
            const fileEmailSet = new Set();
            const campusMap = buildBulkCampusMap(existingUsers);
            const departmentMap = buildBulkDepartmentMap(existingUsers);
            const programMap = buildBulkProgramMap();

            if (campusMap.size === 0) {
                alert('Bulk registration cannot proceed because no campuses are configured. Please refresh and try again.');
                input.value = '';
                return;
            }

            const importSeed = Date.now();
            const affectedEmails = new Set();
            let createdCount = 0;
            let updatedCount = 0;
            let inactivatedCount = 0;
            const credentialRows = [];
            const rowErrors = [];
            const postSaveWarnings = [];
            let processedRows = 0;

            rawRows.forEach((rawRow, index) => {
                const rowNumber = index + 2;
                const normalizedRow = mapBulkRowToCanonical(rawRow);
                const hasAnyValue = Object.values(normalizedRow).some(Boolean);
                if (!hasAnyValue) return;

                processedRows += 1;

                const result = buildBulkUserFromRow(
                    normalizedRow,
                    rowNumber,
                    existingUsersByEmail,
                    fileEmailSet,
                    campusMap,
                    departmentMap,
                    programMap,
                    importSeed,
                    index + 1
                );

                if (result.error) {
                    rowErrors.push({
                        rowNumber,
                        error: result.error
                    });
                    return;
                }

                const existingIndex = existingUserIndexByEmail.get(result.email);
                if (existingIndex !== undefined) {
                    usersToPersist[existingIndex] = result.user;
                    updatedCount += 1;
                } else {
                    usersToPersist.push(result.user);
                    existingUserIndexByEmail.set(result.email, usersToPersist.length - 1);
                    createdCount += 1;
                }

                existingUsersByEmail.set(result.email, result.user);
                affectedEmails.add(result.email);
                const idNumber = normalizeBulkText(result.user.employeeId || result.user.studentNumber);
                credentialRows.push({
                    name: result.user.name,
                    email: result.user.email,
                    role: result.user.role,
                    campus: result.user.campus,
                    idNumber,
                    password: result.password
                });
                fileEmailSet.add(result.email);
            });

            if (processedRows === 0) {
                alert('No importable rows with data were found in the worksheet.');
                input.value = '';
                return;
            }

            if (rowErrors.length > 0) {
                downloadBulkInvalidRowsCsv(rowErrors);
                const previewErrors = rowErrors
                    .slice(0, 20)
                    .map(item => `- ${item.error}`);
                if (rowErrors.length > 20) {
                    previewErrors.push(`- ... and ${rowErrors.length - 20} more`);
                }

                const errorSummary = [
                    'Bulk registration failed.',
                    `Rows processed: ${processedRows}`,
                    `Failed: ${rowErrors.length}`,
                    'No changes were saved because some rows are invalid.',
                    'An invalid-row CSV report was downloaded.'
                ];
                if (previewErrors.length > 0) {
                    errorSummary.push('', 'Errors:', ...previewErrors);
                }
                alert(errorSummary.join('\n'));
                input.value = '';
                return;
            }

            usersToPersist.forEach(user => {
                const emailKey = normalizeBulkText(user && user.email).toLowerCase();
                if (!emailKey || affectedEmails.has(emailKey)) {
                    return;
                }
                if (protectedAdminEmail && emailKey === protectedAdminEmail) {
                    return;
                }

                const currentStatus = normalizeBulkText(user.status).toLowerCase();
                if (currentStatus !== 'inactive') {
                    inactivatedCount += 1;
                }
                user.status = 'inactive';
                user.isActive = false;
            });

            if (processedRows > 0) {
                let savedUsers = [];
                try {
                    savedUsers = (typeof SharedData.setUsersStrict === 'function')
                        ? SharedData.setUsersStrict(usersToPersist)
                        : SharedData.setUsers(usersToPersist);
                } catch (persistError) {
                    console.error('[BulkRegister] Persist failed:', persistError);
                    alert(`Bulk registration failed while saving to database: ${persistError.message || 'Unknown persistence error'}`);
                    input.value = '';
                    return;
                }

                savedUsers = Array.isArray(savedUsers) ? savedUsers : [];
                adminUsers = savedUsers;

                const persistedEmailSet = new Set(
                    savedUsers
                        .map(user => normalizeBulkText(user.email).toLowerCase())
                        .filter(Boolean)
                );
                const missingAfterSave = Array.from(affectedEmails).filter(email => !persistedEmailSet.has(email));
                if (missingAfterSave.length === affectedEmails.size) {
                    alert('Bulk registration failed: no imported rows were persisted to database.');
                    input.value = '';
                    return;
                }
                if (missingAfterSave.length > 0) {
                    missingAfterSave.forEach(email => {
                        postSaveWarnings.push(`Post-save verification warning: ${email} not found after persistence.`);
                    });
                }

                renderOrganizationView(getActiveCampusFilter());
                updateOverviewCards();

                if (usersToPersist.some(user => normalizeRoleCode(user.role) === 'professor')) {
                    loadProfessorsData();
                    renderProfessors();
                }

                downloadBulkCredentialsCsv(credentialRows);
            }

            const summaryLines = [
                'Bulk registration complete.',
                `Rows processed: ${processedRows}`,
                `Created: ${createdCount}`,
                `Updated: ${updatedCount}`,
                `Inactivated: ${inactivatedCount}`,
                `Failed: ${rowErrors.length}`
            ];

            if (postSaveWarnings.length > 0) {
                summaryLines.push('', 'Warnings:');
                postSaveWarnings.forEach(warning => summaryLines.push(`- ${warning}`));
            }

            alert(summaryLines.join('\n'));
        } catch (error) {
            console.error('[BulkRegister] Import failed:', error);
            alert(`Bulk registration failed: ${error.message || 'Unknown error'}`);
        } finally {
            setBulkRegisterLoadingState(false);
            input.value = '';
        }
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

function setupProgramManager() {
    const manageBtn = document.getElementById('manage-programs-btn');
    const modal = document.getElementById('manage-programs-modal');
    const closeBtn = document.getElementById('close-manage-programs-modal');
    const campusSelect = document.getElementById('program-manager-campus');
    const departmentSelect = document.getElementById('program-manager-department');
    const form = document.getElementById('program-form');
    const idInput = document.getElementById('program-id');
    const codeInput = document.getElementById('program-code');
    const nameInput = document.getElementById('program-name');
    const submitBtn = document.getElementById('program-submit-btn');
    const cancelEditBtn = document.getElementById('program-cancel-edit-btn');
    const listBody = document.getElementById('program-list-body');

    if (!manageBtn || !modal || !campusSelect || !departmentSelect || !form || !idInput || !codeInput || !nameInput || !listBody) {
        return;
    }

    function resetProgramForm() {
        idInput.value = '';
        codeInput.value = '';
        nameInput.value = '';
        if (submitBtn) {
            submitBtn.innerHTML = '<i class="fas fa-plus-circle"></i> Save Program';
        }
        if (cancelEditBtn) {
            cancelEditBtn.style.display = 'none';
        }
    }

    function populateProgramCampusSelect(preferredCampus) {
        const campusOptions = getCampusOptions();
        const fallbackCampus = campusOptions[0] ? campusOptions[0].id : '';
        const targetCampus = preferredCampus && campusOptions.some(campus => campus.id === preferredCampus)
            ? preferredCampus
            : (campusSelect.value && campusOptions.some(campus => campus.id === campusSelect.value) ? campusSelect.value : fallbackCampus);

        campusSelect.innerHTML = campusOptions.map(campus => (
            `<option value="${campus.id}">${campus.name}</option>`
        )).join('');

        if (!campusOptions.length) {
            campusSelect.innerHTML = '<option value="">No campuses</option>';
            campusSelect.value = '';
            return;
        }

        campusSelect.value = targetCampus || fallbackCampus;
    }

    function populateProgramDepartmentSelect(preferredDepartment) {
        const campusSlug = campusSelect.value;
        const departments = getDepartmentsForCampus(campusSlug);
        const normalizedPreferred = String(preferredDepartment || '').toLowerCase();
        const normalizedCurrent = String(departmentSelect.value || '').toLowerCase();

        departmentSelect.innerHTML = departments.map(dept => (
            `<option value="${dept}">${getDepartmentLabel(dept)}</option>`
        )).join('');

        if (!departments.length) {
            departmentSelect.innerHTML = '<option value="">No departments</option>';
            departmentSelect.value = '';
            return;
        }

        const preferredMatch = departments.find(dept => String(dept).toLowerCase() === normalizedPreferred);
        if (preferredMatch) {
            departmentSelect.value = preferredMatch;
            return;
        }

        const currentMatch = departments.find(dept => String(dept).toLowerCase() === normalizedCurrent);
        departmentSelect.value = currentMatch || departments[0];
    }

    function getFilteredPrograms() {
        const selectedCampus = String(campusSelect.value || '').toLowerCase();
        const selectedDepartment = String(departmentSelect.value || '').toLowerCase();
        const programs = SharedData.getPrograms ? SharedData.getPrograms() : [];

        return (Array.isArray(programs) ? programs : [])
            .filter(program => {
                const campusSlug = String(program.campusSlug || '').toLowerCase();
                const departmentCode = String(program.departmentCode || '').toLowerCase();
                if (selectedCampus && campusSlug !== selectedCampus) return false;
                if (selectedDepartment && departmentCode !== selectedDepartment) return false;
                return true;
            })
            .sort((a, b) => String(a.programCode || '').localeCompare(String(b.programCode || '')));
    }

    function renderProgramRows() {
        const rows = getFilteredPrograms();
        if (!rows.length) {
            listBody.innerHTML = '<tr><td colspan="5" style="text-align:center; padding:14px;">No programs found for this campus/department.</td></tr>';
            return;
        }

        listBody.innerHTML = rows.map(program => `
            <tr>
                <td>${program.programCode || ''}</td>
                <td>${program.programName || ''}</td>
                <td>${String(program.campusSlug || '').toUpperCase()}</td>
                <td>${String(program.departmentCode || '').toUpperCase()}</td>
                <td>
                    <div class="professor-actions">
                        <button type="button" class="action-btn edit" data-action="edit-program" data-program-id="${program.id}" title="Edit Program">
                            <i class="fas fa-edit"></i>
                        </button>
                        <button type="button" class="action-btn delete" data-action="delete-program" data-program-id="${program.id}" title="Delete Program">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `).join('');
    }

    function refreshProgramManager(preferredCampus, preferredDepartment) {
        campusData = SharedData.getCampuses();
        populateProgramCampusSelect(preferredCampus);
        populateProgramDepartmentSelect(preferredDepartment);
        renderProgramRows();
    }

    function refreshViewsAfterProgramChange() {
        loadUsersByOrganization(getActiveCampusFilter());
        renderProfessorDepartmentOptions();
        renderProfessorDepartmentTabs();
        if (typeof refreshSubjectManagementView === 'function') {
            refreshSubjectManagementView();
        }
    }

    manageBtn.addEventListener('click', () => {
        const activeCampus = getActiveCampusFilter();
        const preferredCampus = activeCampus && activeCampus !== 'all' ? activeCampus : '';
        refreshProgramManager(preferredCampus, '');
        resetProgramForm();
        modal.classList.add('active');
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', () => modal.classList.remove('active'));
    }
    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            modal.classList.remove('active');
        }
    });

    campusSelect.addEventListener('change', () => {
        populateProgramDepartmentSelect('');
        renderProgramRows();
        resetProgramForm();
    });
    departmentSelect.addEventListener('change', () => {
        renderProgramRows();
        resetProgramForm();
    });

    form.addEventListener('submit', (event) => {
        event.preventDefault();

        const campusSlug = String(campusSelect.value || '').trim();
        const departmentCode = String(departmentSelect.value || '').trim();
        const programCode = normalizeProgramCode(codeInput.value);
        const programName = normalizeBulkText(nameInput.value);
        const parsedId = parseInt(idInput.value, 10);

        if (!campusSlug || !departmentCode || !programCode || !programName) {
            alert('Campus, department, program code, and program name are required.');
            return;
        }

        try {
            SharedData.upsertProgram({
                id: Number.isFinite(parsedId) ? parsedId : undefined,
                campusSlug,
                departmentCode,
                programCode,
                programName,
            });
            refreshProgramManager(campusSlug, departmentCode);
            resetProgramForm();
            refreshViewsAfterProgramChange();
        } catch (error) {
            alert('Unable to save program: ' + (error.message || 'Unknown error'));
        }
    });

    if (cancelEditBtn) {
        cancelEditBtn.addEventListener('click', () => resetProgramForm());
    }

    listBody.addEventListener('click', (event) => {
        const button = event.target.closest('button[data-action]');
        if (!button) return;

        const action = button.getAttribute('data-action');
        const programId = parseInt(button.getAttribute('data-program-id'), 10);
        if (!Number.isFinite(programId)) return;

        const allPrograms = SharedData.getPrograms ? SharedData.getPrograms() : [];
        const program = (Array.isArray(allPrograms) ? allPrograms : []).find(item => Number(item.id) === programId);
        if (!program) {
            alert('Program not found.');
            return;
        }

        if (action === 'edit-program') {
            refreshProgramManager(program.campusSlug, program.departmentCode);
            idInput.value = String(program.id);
            codeInput.value = String(program.programCode || '');
            nameInput.value = String(program.programName || '');
            if (submitBtn) {
                submitBtn.innerHTML = '<i class="fas fa-save"></i> Update Program';
            }
            if (cancelEditBtn) {
                cancelEditBtn.style.display = 'inline-flex';
            }
            codeInput.focus();
            return;
        }

        if (action === 'delete-program') {
            if (!confirm(`Delete program ${program.programCode}? Users under this program will be cleared from this program assignment.`)) {
                return;
            }
            try {
                SharedData.deleteProgram(program.id);
                refreshProgramManager(program.campusSlug, program.departmentCode);
                if (parseInt(idInput.value, 10) === program.id) {
                    resetProgramForm();
                }
                refreshViewsAfterProgramChange();
            } catch (error) {
                alert('Unable to delete program: ' + (error.message || 'Unknown error'));
            }
        }
    });
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
    const role = formData.get('role');
    const studentNumber = normalizeBulkText(formData.get('studentNumber'));
    const rawYearSection = formData.get('yearSection') || '';
    const yearSection = normalizeYearSection(rawYearSection);

    if (role === 'student') {
        if (!studentNumber) {
            alert('Student Number is required for student accounts.');
            return;
        }
        if (!yearSection || !isCanonicalYearSection(yearSection)) {
            alert('Year & Section must use Y-S format (e.g., 3-1).');
            return;
        }
    }

    const userData = {
        name: formData.get('name'),
        email: formData.get('email'),
        password: formData.get('password') || '',
        role: role,
        campus: formData.get('campus'),
        department: departmentValue,
        institute: departmentValue,
        yearSection: role === 'student' ? yearSection : '',
        studentNumber: role === 'student' ? studentNumber : '',
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
        if (
            key === SharedData.KEYS.USERS ||
            key === SharedData.KEYS.PROFESSORS ||
            key === SharedData.KEYS.EVALUATIONS ||
            key === SharedData.KEYS.SUBJECT_MANAGEMENT ||
            key === SharedData.KEYS.STUDENT_EVAL_DRAFTS ||
            key === SharedData.KEYS.CURRENT_SEMESTER ||
            key === SharedData.KEYS.SEMESTER_LIST ||
            key === SharedData.KEYS.QUESTIONNAIRES
        ) {
            updateOverviewCards();
            if (isContentViewVisible('dashboard-view')) {
                initializeCharts();
                loadReports();
            }
        }
    });
}

/**
 * Calculate dashboard stats from SharedData
 */
function getDashboardStats() {
    const users = SharedData.getUsers();
    const professors = SharedData.getProfessors();
    const registrationStats = getStudentRegistrationEvaluationStats();

    // Count active professors from users list
    const professorUsers = users.filter(function (u) {
        return normalizeRoleCode(u.role) === 'professor' && u.status === 'active';
    });
    // Use whichever source has more professors (users list or professors list)
    const professorCount = Math.max(professorUsers.length, professors.length);

    // Count active students from users list
    const studentCount = getActiveStudentCount(users);

    var completionRate = registrationStats.total > 0
        ? Math.min(100, Math.round((registrationStats.completed / registrationStats.total) * 100))
        : 0;

    return {
        professors: professorCount,
        students: studentCount,
        completionRate: completionRate,
        pendingEvaluations: registrationStats.pending
    };
}

function getStudentRegistrationEvaluationStats() {
    function normalizeDashboardToken(value) {
        return String(value || '').trim().toLowerCase();
    }

    const evaluations = SharedData.getEvaluations ? SharedData.getEvaluations() : [];
    const subjectManagement = SharedData.getSubjectManagement ? SharedData.getSubjectManagement() : { offerings: [], enrollments: [] };
    const currentSemester = String(SharedData.getCurrentSemester ? SharedData.getCurrentSemester() : '').trim();

    const activeOfferingIds = new Set(
        (subjectManagement.offerings || [])
            .filter(function (offering) { return offering && offering.isActive; })
            .map(function (offering) { return String(offering.id); })
    );

    const expectedPairs = new Set();
    (subjectManagement.enrollments || []).forEach(function (enrollment) {
        if (!enrollment) return;
        if (String(enrollment.status || '').toLowerCase() !== 'enrolled') return;
        const offeringId = String(enrollment.courseOfferingId || '').trim();
        const studentUserId = String(enrollment.studentUserId || '').trim();
        if (!offeringId || !studentUserId || !activeOfferingIds.has(offeringId)) return;
        expectedPairs.add(`${normalizeDashboardToken(studentUserId)}|${normalizeDashboardToken(offeringId)}`);
    });

    const completedPairs = new Set();
    (evaluations || []).forEach(function (ev) {
        if (!ev) return;
        const evalRole = String(ev.evaluatorRole || ev.evaluationType || '').toLowerCase();
        if (evalRole && evalRole !== 'student') return;

        const evalSemester = String(ev.semesterId || '').trim();
        if (currentSemester && evalSemester && evalSemester !== currentSemester) return;

        const offeringId = String(ev.courseOfferingId || '').trim();
        if (!offeringId || !activeOfferingIds.has(offeringId)) return;

        const studentToken = String(ev.studentUserId || ev.studentId || ev.evaluatorId || ev.evaluatorUsername || '').trim();
        if (!studentToken) return;

        const pairKey = `${normalizeDashboardToken(studentToken)}|${normalizeDashboardToken(offeringId)}`;
        if (expectedPairs.has(pairKey)) {
            completedPairs.add(pairKey);
        }
    });

    const total = expectedPairs.size;
    const completed = completedPairs.size;
    const pending = Math.max(total - completed, 0);

    return {
        total,
        completed,
        pending
    };
}

function getActiveStudentCount(usersInput) {
    const users = Array.isArray(usersInput) ? usersInput : SharedData.getUsers();
    return users.filter(function (u) {
        const role = normalizeRoleCode(u.role);
        const status = String(u.status || '').trim().toLowerCase();
        return role === 'student' && status === 'active';
    }).length;
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

function normalizeAdminAnalyticsToken(value) {
    return String(value || '').trim().toLowerCase();
}

function getAdminAnalyticsContext() {
    const evaluations = SharedData.getEvaluations ? SharedData.getEvaluations() : [];
    const studentEvaluationDrafts = SharedData.getStudentEvaluationDrafts ? SharedData.getStudentEvaluationDrafts() : [];
    const subjectManagement = SharedData.getSubjectManagement ? SharedData.getSubjectManagement() : { offerings: [], enrollments: [] };
    const semesterList = SharedData.getSemesterList ? SharedData.getSemesterList() : [];
    const questionnaires = SharedData.getQuestionnaires ? SharedData.getQuestionnaires() : {};
    const currentSemester = String(SharedData.getCurrentSemester ? SharedData.getCurrentSemester() : '').trim();

    const offerings = Array.isArray(subjectManagement.offerings) ? subjectManagement.offerings : [];
    const enrollments = Array.isArray(subjectManagement.enrollments) ? subjectManagement.enrollments : [];
    const offeringsById = {};
    offerings.forEach(offering => {
        const offeringId = String(offering && offering.id || '').trim();
        if (offeringId) offeringsById[offeringId] = offering;
    });

    return {
        evaluations: Array.isArray(evaluations) ? evaluations : [],
        studentEvaluationDrafts: Array.isArray(studentEvaluationDrafts) ? studentEvaluationDrafts : [],
        offerings,
        enrollments,
        offeringsById,
        semesterList: Array.isArray(semesterList) ? semesterList : [],
        questionnaires: questionnaires || {},
        currentSemester,
    };
}

function getAdminEvaluationTypeKey(evaluation) {
    const role = normalizeAdminAnalyticsToken(evaluation && (evaluation.evaluatorRole || evaluation.evaluationType));
    if (role === 'student' || role === 'student-to-professor') return 'student';
    if (role === 'professor' || role === 'peer' || role === 'professor-to-professor') return 'peer';
    if (role === 'dean' || role === 'hr' || role === 'supervisor' || role === 'supervisor-to-professor') return 'supervisor';
    return '';
}

function isAdminEvaluationInSemester(evaluation, semesterId) {
    const normalizedSemester = String(semesterId || '').trim();
    if (!normalizedSemester || normalizedSemester === 'all') return true;
    const evaluationSemester = String(evaluation && evaluation.semesterId || '').trim();
    if (!evaluationSemester) return true;
    return evaluationSemester === normalizedSemester;
}

function buildAdminExpectedStudentEvaluationPairs(context, semesterId) {
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
        if (normalizeAdminAnalyticsToken(enrollment.status) !== 'enrolled') return;

        const offeringId = String(enrollment.courseOfferingId || '').trim();
        if (!offeringId || !activeOfferingIds.has(offeringId)) return;

        const studentToken = normalizeAdminAnalyticsToken(
            enrollment.studentUserId || enrollment.studentId || enrollment.studentNumber || enrollment.studentName
        );
        if (!studentToken) return;

        expectedPairs.add(`${studentToken}|${normalizeAdminAnalyticsToken(offeringId)}`);
    });

    return {
        expectedPairs,
        activeOfferingIds,
    };
}

function buildAdminDashboardEvaluationOverview(context) {
    const semesterId = context.currentSemester || 'all';
    const expected = buildAdminExpectedStudentEvaluationPairs(context, semesterId);

    const completedPairs = new Set();
    (context.evaluations || []).forEach(evaluation => {
        if (getAdminEvaluationTypeKey(evaluation) !== 'student') return;
        if (!isAdminEvaluationInSemester(evaluation, semesterId)) return;

        const offeringId = String(evaluation.courseOfferingId || '').trim();
        if (!offeringId || !expected.activeOfferingIds.has(offeringId)) return;

        const studentToken = normalizeAdminAnalyticsToken(
            evaluation.studentUserId || evaluation.studentId || evaluation.evaluatorId || evaluation.evaluatorUsername
        );
        if (!studentToken) return;

        const pairKey = `${studentToken}|${normalizeAdminAnalyticsToken(offeringId)}`;
        if (expected.expectedPairs.has(pairKey)) {
            completedPairs.add(pairKey);
        }
    });

    const pendingPairs = new Set();
    (context.studentEvaluationDrafts || []).forEach(draft => {
        if (!draft) return;
        if (!isAdminEvaluationInSemester({ semesterId: draft.semesterId || '' }, semesterId)) return;

        const offeringId = String(draft.courseOfferingId || '').trim();
        if (!offeringId || !expected.activeOfferingIds.has(offeringId)) return;

        const studentToken = normalizeAdminAnalyticsToken(draft.studentUserId || draft.studentId);
        if (!studentToken) return;

        const pairKey = `${studentToken}|${normalizeAdminAnalyticsToken(offeringId)}`;
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
        completed,
        pending,
        notStarted,
        semesterId,
    };
}

function getAdminLatestSemestersForTrend(context, limit = 4) {
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
        if (getAdminEvaluationTypeKey(evaluation) !== 'student') return;
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

function buildAdminSemestralPerformanceData(context) {
    const semesters = getAdminLatestSemestersForTrend(context, 4);
    if (!semesters.length) {
        return {
            labels: ['No Semester Data'],
            values: [0],
        };
    }

    const values = semesters.map(semester => {
        const semesterId = String(semester.id || '').trim();
        const expected = buildAdminExpectedStudentEvaluationPairs(context, semesterId);
        const expectedByStudent = new Map();

        expected.expectedPairs.forEach(pairKey => {
            const [studentToken, offeringToken] = String(pairKey || '').split('|');
            const normalizedStudent = normalizeAdminAnalyticsToken(studentToken);
            const normalizedOffering = normalizeAdminAnalyticsToken(offeringToken);
            if (!normalizedStudent || !normalizedOffering) return;
            if (!expectedByStudent.has(normalizedStudent)) {
                expectedByStudent.set(normalizedStudent, new Set());
            }
            expectedByStudent.get(normalizedStudent).add(normalizedOffering);
        });

        const completedByStudent = new Map();
        (context.evaluations || []).forEach(evaluation => {
            if (getAdminEvaluationTypeKey(evaluation) !== 'student') return;
            if (!isAdminEvaluationInSemester(evaluation, semesterId)) return;

            const offeringId = normalizeAdminAnalyticsToken(evaluation && evaluation.courseOfferingId);
            if (!offeringId || !expected.activeOfferingIds.has(String(evaluation && evaluation.courseOfferingId || ''))) {
                return;
            }

            const studentToken = normalizeAdminAnalyticsToken(
                evaluation.studentUserId || evaluation.studentId || evaluation.evaluatorUserId || evaluation.evaluatorId || evaluation.evaluatorUsername
            );
            if (!studentToken || !expectedByStudent.has(studentToken)) return;
            if (!expectedByStudent.get(studentToken).has(offeringId)) return;

            if (!completedByStudent.has(studentToken)) {
                completedByStudent.set(studentToken, new Set());
            }
            completedByStudent.get(studentToken).add(offeringId);
        });

        let completedStudents = 0;
        expectedByStudent.forEach((expectedOfferings, studentToken) => {
            if (!expectedOfferings || expectedOfferings.size === 0) return;
            const completedOfferings = completedByStudent.get(studentToken) || new Set();
            const isFullyCompleted = Array.from(expectedOfferings).every(offeringToken => completedOfferings.has(offeringToken));
            if (isFullyCompleted) {
                completedStudents += 1;
            }
        });

        return completedStudents;
    });

    return {
        labels: semesters.map(semester => String(semester.label || semester.id || '').trim() || String(semester.id || '')),
        values,
    };
}

function renderAdminEvaluationOverviewChart(data) {
    if (typeof Chart === 'undefined') return;
    const evaluationCtx = document.getElementById('evaluation-chart');
    if (!evaluationCtx) return;

    if (adminEvaluationOverviewChartInstance) {
        adminEvaluationOverviewChartInstance.destroy();
        adminEvaluationOverviewChartInstance = null;
    }

    adminEvaluationOverviewChartInstance = new Chart(evaluationCtx, {
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
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' }
            }
        }
    });
}

function renderAdminSemestralPerformanceChart(data) {
    if (typeof Chart === 'undefined') return;
    const performanceCtx = document.getElementById('performance-chart');
    if (!performanceCtx) return;

    if (adminSemestralPerformanceChartInstance) {
        adminSemestralPerformanceChartInstance.destroy();
        adminSemestralPerformanceChartInstance = null;
    }

    adminSemestralPerformanceChartInstance = new Chart(performanceCtx, {
        type: 'bar',
        data: {
            labels: data.labels,
            datasets: [{
                label: 'Students Completed',
                data: data.values,
                backgroundColor: ['#667eea', '#7c8df0', '#5f78dd', '#4d66cf'],
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
                        maxRotation: 0,
                        minRotation: 0,
                        autoSkip: true,
                        maxTicksLimit: 6
                    }
                }
            }
        }
    });
}

/**
 * Initialize dashboard charts
 */
function initializeCharts() {
    const context = getAdminAnalyticsContext();
    const overview = buildAdminDashboardEvaluationOverview(context);
    const semestral = buildAdminSemestralPerformanceData(context);
    renderAdminEvaluationOverviewChart(overview);
    renderAdminSemestralPerformanceChart(semestral);
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

    const fromInput = document.getElementById('activity-from');
    const toInput = document.getElementById('activity-to');
    const searchBtn = document.getElementById('activity-search-btn');
    const typeSelect = document.getElementById('activity-type');
    const searchInput = document.getElementById('activity-search');
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
            console.error('[AdminPanel] Failed to search activity log.', error);
            renderPrompt('Failed to load activity records.');
        }
    };

    if (searchBtn) {
        searchBtn.onclick = runSearch;
    }

    renderPrompt('Click Search to load activity records.');
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

function normalizeCampusCode(value) {
    return String(value || '').trim().toLowerCase();
}

function normalizeDepartmentCode(value) {
    const trimmed = String(value || '').trim();
    return trimmed ? trimmed.toUpperCase() : BULK_UNASSIGNED_DEPARTMENT;
}

/**
 * Render the organization view with the current adminUsers data
 */
function renderOrganizationView(campusFilter = 'all') {
    const users = Array.isArray(adminUsers) ? adminUsers : [];
    const normalizedCampusFilter = normalizeCampusCode(campusFilter);

    // Filter by campus if not "all"
    let filteredUsers = normalizedCampusFilter === 'all'
        ? users
        : users.filter(u => normalizeCampusCode(u.campus) === normalizedCampusFilter);

    filteredUsers = filteredUsers.map(user => {
        const role = normalizeRoleCode(user.role);
        const campus = normalizeCampusCode(user.campus);
        const department = normalizeDepartmentCode(user.department || user.institute);
        return Object.assign({}, user, {
            role,
            campus,
            department,
            institute: department
        });
    });

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
    let hrUsers = filteredUsers.filter(u => normalizeRoleCode(u.role) === 'hr');
    hrUsers = sortUsersByStatusAndName(hrUsers);
    const hrCountEl = document.getElementById('hr-count');
    if (hrCountEl) hrCountEl.textContent = hrUsers.length;
    const hrList = document.getElementById('hr-user-list');
    if (hrList) {
        hrList.innerHTML = hrUsers.length > 0 ? hrUsers.map(u => createUserCard(u)).join('') : createEmptyState('No HR staff found');
    }

    // Populate Admin section
    let adminUsersList = filteredUsers.filter(u => normalizeRoleCode(u.role) === 'admin');
    adminUsersList = sortUsersByStatusAndName(adminUsersList);
    const adminCountEl = document.getElementById('admin-count');
    if (adminCountEl) adminCountEl.textContent = adminUsersList.length;
    const adminList = document.getElementById('admin-user-list');
    if (adminList) {
        adminList.innerHTML = adminUsersList.length > 0 ? adminUsersList.map(u => createUserCard(u)).join('') : createEmptyState('No administrators found');
    }

    // Populate VPAA section
    let vpaaUsersInfo = filteredUsers.filter(u => normalizeRoleCode(u.role) === 'vpaa');
    vpaaUsersInfo = sortUsersByStatusAndName(vpaaUsersInfo);
    const vpaaCountEl = document.getElementById('vpaa-count');
    if (vpaaCountEl) vpaaCountEl.textContent = vpaaUsersInfo.length;
    const vpaaList = document.getElementById('vpaa-user-list');
    if (vpaaList) {
        vpaaList.innerHTML = vpaaUsersInfo.length > 0 ? vpaaUsersInfo.map(u => createUserCard(u)).join('') : createEmptyState('No VPAA staff found');
    }

    // Populate OSA section
    let osaUsersInfo = filteredUsers.filter(u => normalizeRoleCode(u.role) === 'osa');
    osaUsersInfo = sortUsersByStatusAndName(osaUsersInfo);
    const osaCountEl = document.getElementById('osa-count');
    if (osaCountEl) osaCountEl.textContent = osaUsersInfo.length;
    const osaList = document.getElementById('osa-user-list');
    if (osaList) {
        osaList.innerHTML = osaUsersInfo.length > 0 ? osaUsersInfo.map(u => createUserCard(u)).join('') : createEmptyState('No OSA staff found');
    }

    // Departments based on selected campus + any unknown/missing from imported users
    const configuredDepartments = getDepartmentsForCampus(campusFilter).map(dept => normalizeDepartmentCode(dept));
    const departmentSet = new Set(configuredDepartments.map(dept => dept.toLowerCase()));
    const departments = [...configuredDepartments];
    const academicRoles = new Set(['dean', 'professor', 'student']);

    filteredUsers.forEach(user => {
        if (!academicRoles.has(normalizeRoleCode(user.role))) return;
        const department = normalizeDepartmentCode(user.department || user.institute);
        const key = department.toLowerCase();
        if (!departmentSet.has(key)) {
            departmentSet.add(key);
            departments.push(department);
        }
    });

    renderDepartmentSections(departments);

    departments.forEach(dept => {
        const deptKey = normalizeDepartmentCode(dept);
        const deptUsers = filteredUsers.filter(u => normalizeDepartmentCode(u.department || u.institute) === deptKey);
        const slug = slugifyDepartmentName(dept);

        // Deans
        let deans = deptUsers.filter(u => normalizeRoleCode(u.role) === 'dean');
        deans = sortUsersByStatusAndName(deans);
        const deanCount = document.getElementById(`${slug}-dean-count`);
        if (deanCount) deanCount.textContent = deans.length;
        const deanContainer = document.getElementById(`${slug}-deans`);
        if (deanContainer) deanContainer.innerHTML = deans.length > 0 ? deans.map(u => createUserCard(u)).join('') : createEmptyState('No deans');

        // Professors
        let profs = deptUsers.filter(u => normalizeRoleCode(u.role) === 'professor');
        profs = sortUsersByStatusAndName(profs);
        const profCount = document.getElementById(`${slug}-prof-count`);
        if (profCount) profCount.textContent = profs.length;
        const profContainer = document.getElementById(`${slug}-professors`);
        if (profContainer) profContainer.innerHTML = profs.length > 0 ? profs.map(u => createUserCard(u)).join('') : createEmptyState('No professors');

        // Students
        let students = deptUsers.filter(u => normalizeRoleCode(u.role) === 'student');
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
    const role = normalizeRoleCode(user.role);
    const idNumber = user.employeeId || user.studentNumber || '-';
    const institute = getInstituteLabel(user);
    const programCode = normalizeBulkText(user.programCode || '');
    const programName = normalizeBulkText(user.programName || '');
    const programLabel = programCode
        ? `${programCode}${programName ? ` - ${programName}` : ''}`
        : '-';
    const employmentType = role === 'student' ? 'Student' : (user.employmentType || 'Regular');
    const position = role === 'student' ? (user.yearSection || 'Student') : (user.position || getRoleLabel(role));
    const campusLabel = user.campus ? user.campus.charAt(0).toUpperCase() + user.campus.slice(1) : '-';
    const statusLabel = user.status === 'inactive' ? 'Inactive' : 'Active';

    return `
        <tr data-user-id="${user.id}">
            <td>${idNumber}</td>
            <td>${user.name}</td>
            <td>${user.email || '-'}</td>
            <td>${campusLabel}</td>
            <td>${institute}</td>
            <td>${programLabel}</td>
            <td>${employmentType}</td>
            <td>${position}</td>
            <td><span class="status-pill ${statusLabel.toLowerCase()}">${statusLabel}</span></td>
        </tr>
    `;
}

function toggleEditUserStudentFields(isStudent) {
    const employmentTypeGroup = document.getElementById('edit-user-employment-type-group');
    const positionGroup = document.getElementById('edit-user-position-group');
    const employmentTypeInput = document.getElementById('edit-user-employment-type');
    const positionInput = document.getElementById('edit-user-position');

    if (employmentTypeGroup) {
        employmentTypeGroup.style.display = isStudent ? 'none' : 'block';
    }
    if (positionGroup) {
        positionGroup.style.display = isStudent ? 'none' : 'block';
    }
    if (employmentTypeInput) {
        employmentTypeInput.required = !isStudent;
    }
    if (positionInput) {
        positionInput.required = false;
    }
}

function getInstituteLabel(user) {
    const role = normalizeRoleCode(user.role);
    if (user.institute) {
        const lower = String(user.institute || '').toLowerCase();
        if (['ics', 'ilas', 'engi'].includes(lower)) {
            return user.institute.toUpperCase();
        }
        return user.institute;
    }

    if (user.department) {
        return String(user.department).toUpperCase();
    }

    if (role === 'hr') return 'HR';
    if (role === 'admin') return 'Administration';
    if (role === 'vpaa') return 'VPAA';
    if (role === 'osa') return 'OSA';
    if (role === 'dean') return 'Dean Office';
    return BULK_UNASSIGNED_DEPARTMENT;
}
function getDepartmentLabel(dept) {
    // Dynamic label: uppercase the department key
    if (!dept) return '';
    return dept.toUpperCase();
}

function getRoleLabel(role) {
    const normalizedRole = normalizeRoleCode(role);
    const roles = {
        hr: 'HR Staff',
        admin: 'Administrator',
        dean: 'Dean',
        osa: 'OSA',
        vpaa: 'VPAA',
        professor: 'Professor',
        student: 'Student'
    };
    return roles[normalizedRole] || 'User';
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
    const isStudent = normalizeRoleCode(user.role) === 'student';

    editingUserId = userId;
    const modal = document.getElementById('edit-user-modal');
    if (!modal) return;

    const idLabel = document.getElementById('edit-user-id-label');
    if (idLabel) {
        idLabel.textContent = isStudent ? 'Student Number' : 'Employee ID';
    }

    const idInput = document.getElementById('edit-user-id-number');
    if (idInput) idInput.value = user.employeeId || user.studentNumber || '';
    const yearSectionGroup = document.getElementById('edit-user-year-section-group');
    const yearSectionInput = document.getElementById('edit-user-year-section');
    if (yearSectionGroup && yearSectionInput) {
        yearSectionGroup.style.display = isStudent ? 'block' : 'none';
        yearSectionInput.required = isStudent;
        yearSectionInput.value = isStudent ? (normalizeYearSection(user.yearSection) || '') : '';
    }
    toggleEditUserStudentFields(isStudent);
    const nameInput = document.getElementById('edit-user-name');
    if (nameInput) nameInput.value = user.name || '';
    const emailInput = document.getElementById('edit-user-email');
    if (emailInput) emailInput.value = user.email || '';
    const passwordInput = document.getElementById('edit-user-password');
    if (passwordInput) passwordInput.value = '';
    const campusInput = document.getElementById('edit-user-campus');
    if (campusInput) campusInput.value = user.campus || 'basa';
    const instituteInput = document.getElementById('edit-user-institute');
    if (instituteInput) instituteInput.value = user.institute || getInstituteLabel(user);
    const employmentTypeInput = document.getElementById('edit-user-employment-type');
    if (employmentTypeInput) employmentTypeInput.value = user.employmentType || (isStudent ? 'Student' : 'Regular');
    const positionInput = document.getElementById('edit-user-position');
    if (positionInput) positionInput.value = isStudent ? '' : (user.position || '');
    const statusInput = document.getElementById('edit-user-status');
    if (statusInput) statusInput.value = user.status || 'active';

    modal.classList.add('active');
}

function closeEditUserModal() {
    const modal = document.getElementById('edit-user-modal');
    if (modal) modal.classList.remove('active');
    toggleEditUserStudentFields(false);
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
    const yearSectionInput = document.getElementById('edit-user-year-section');

    const idNumber = idInput ? idInput.value.trim() : '';
    if (normalizeRoleCode(user.role) === 'student') {
        const normalizedYearSection = normalizeYearSection(yearSectionInput ? yearSectionInput.value : '');
        if (!normalizedYearSection || !isCanonicalYearSection(normalizedYearSection)) {
            alert('Year & Section must use Y-S format (e.g., 3-1).');
            return;
        }
        user.studentNumber = idNumber;
        user.employeeId = undefined;
        user.yearSection = normalizedYearSection;
    } else {
        user.employeeId = idNumber;
        user.studentNumber = undefined;
        user.yearSection = '';
    }

    if (nameInput) user.name = nameInput.value.trim();
    if (emailInput) user.email = emailInput.value.trim();
    if (passwordInput && passwordInput.value !== '') {
        user.password = passwordInput.value;
    }
    if (campusInput) user.campus = campusInput.value;
    if (instituteInput) {
        user.institute = instituteInput.value.trim();
        user.department = instituteInput.value.trim(); // Sync department to institute for consistency
    }
    if (normalizeRoleCode(user.role) === 'student') {
        user.employmentType = '';
        user.position = '';
    } else {
        if (employmentTypeInput) user.employmentType = employmentTypeInput.value;
        if (positionInput) user.position = positionInput.value.trim();
    }
    if (statusInput) user.status = statusInput.value;

    // Save directly to the local SharedData database
    SharedData.updateUser(user.id, user);

    alert('User updated successfully in local database!');
    closeEditUserModal();
    loadUsersByOrganization(getActiveCampusFilter());

    // Refresh Professor Management data if the updated user is a professor
    if (normalizeRoleCode(user.role) === 'professor') {
        loadProfessorsData();
        renderProfessors();
    }
}

/**
 * Create user card HTML
 */
function createUserCard(user) {
    const role = normalizeRoleCode(user.role);
    const initials = user.name.split(' ').map(n => n[0]).join('').substring(0, 2);
    const baseInfo = user.yearSection || (user.campus ? user.campus.charAt(0).toUpperCase() + user.campus.slice(1) : '');
    const idLabel = user.studentNumber ? `Student No: ${user.studentNumber}` : (user.employeeId ? `Employee No: ${user.employeeId}` : '');
    const info = [baseInfo, idLabel].filter(Boolean).join('  -  ');
    const departmentLabel = normalizeDepartmentCode(user.department || user.institute);
    const status = user.status || 'active';
    const statusLabel = status === 'inactive' ? 'Inactive' : 'Active';

    return `
        <div class="user-card-compact ${status === 'inactive' ? 'inactive' : ''}">
            <div class="user-avatar">${initials}</div>
            <div class="user-details">
                <div class="name">${user.name}</div>
                <div class="info">${info}</div>
                <div class="info">${getRoleLabel(role)} - ${departmentLabel}</div>
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
    const emailKey = normalizeBulkText(email).toLowerCase();

    // Find and update the user in SharedData
    let users = SharedData.getUsers();
    const userIndex = users.findIndex(u => normalizeBulkText(u.email).toLowerCase() === emailKey);

    if (userIndex !== -1) {
        users[userIndex].status = isActive ? 'inactive' : 'active';
        users[userIndex].isActive = !isActive;
        SharedData.updateUser(users[userIndex]);

        // Update local adminUsers cache
        adminUsers = SharedData.getUsers();

        // Also update professorsData if applicable to sync instantly to Professor Management
        if (normalizeRoleCode(users[userIndex].role) === 'professor') {
            const profIndex = professorsData.findIndex(p => normalizeBulkText(p.email).toLowerCase() === emailKey);
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

function normalizeAnnouncementComposerToken(value) {
    return String(value == null ? '' : value).trim().toLowerCase();
}

function populateAnnouncementComposerCampusOptions() {
    const campusSelect = document.getElementById('announcement-target-campus');
    if (!campusSelect) return;

    const campuses = (SharedData.getCampuses ? SharedData.getCampuses() : []) || [];
    const previous = String(campusSelect.value || '').trim().toLowerCase();
    const realCampuses = (Array.isArray(campuses) ? campuses : []).filter(campus => {
        const id = normalizeAnnouncementComposerToken(campus && campus.id);
        return id && id !== 'all';
    });

    campusSelect.innerHTML = '<option value="">All Campuses</option>' + realCampuses.map(campus => {
        const id = String(campus && campus.id || '').trim();
        const name = String(campus && (campus.name || campus.id) || id).trim() || id;
        return `<option value="${id}">${name}</option>`;
    }).join('');

    if (previous && realCampuses.some(campus => normalizeAnnouncementComposerToken(campus && campus.id) === previous)) {
        campusSelect.value = previous;
    } else {
        campusSelect.value = '';
    }
}

function populateAnnouncementComposerProgramOptions() {
    const campusSelect = document.getElementById('announcement-target-campus');
    const programSelect = document.getElementById('announcement-target-program');
    if (!programSelect) return;

    const selectedCampus = normalizeAnnouncementComposerToken(campusSelect ? campusSelect.value : '');
    const previousValue = String(programSelect.value || '').trim();
    const previous = normalizeAnnouncementComposerToken(previousValue);
    const programs = (SharedData.getPrograms ? SharedData.getPrograms() : []) || [];

    const filteredPrograms = (Array.isArray(programs) ? programs : [])
        .filter(program => {
            if (!program) return false;
            if (!selectedCampus) return true;
            return normalizeAnnouncementComposerToken(program.campusSlug) === selectedCampus;
        })
        .sort((a, b) => String(a && a.programCode || '').localeCompare(String(b && b.programCode || '')));

    programSelect.innerHTML = '<option value="">All Programs</option>' + filteredPrograms.map(program => {
        const code = String(program && program.programCode || '').trim();
        const name = String(program && program.programName || '').trim();
        return `<option value="${code}">${code}${name ? ' - ' + name : ''}</option>`;
    }).join('');

    const matchedPrevious = previous
        ? filteredPrograms.find(program => normalizeAnnouncementComposerToken(program && program.programCode) === previous)
        : null;
    if (matchedPrevious) {
        programSelect.value = String(matchedPrevious.programCode || '').trim();
    } else {
        programSelect.value = '';
    }
}

function syncAnnouncementStudentCompletionVisibility() {
    const roleSelect = document.getElementById('announcement-target-role');
    const completionWrap = document.getElementById('announcement-student-completion-wrap');
    const completionSelect = document.getElementById('announcement-student-completion');
    const isStudentTarget = normalizeAnnouncementComposerToken(roleSelect ? roleSelect.value : '') === 'student';
    if (completionWrap) completionWrap.style.display = isStudentTarget ? 'block' : 'none';
    if (completionSelect && !isStudentTarget) {
        completionSelect.value = 'all';
    }
}

function resetAnnouncementComposerForm() {
    const form = document.getElementById('announcement-compose-form');
    const feedback = document.getElementById('announcement-compose-feedback');
    if (form) form.reset();
    if (feedback) feedback.textContent = '';
    populateAnnouncementComposerCampusOptions();
    populateAnnouncementComposerProgramOptions();
    syncAnnouncementStudentCompletionVisibility();
}

function closeAnnouncementComposerModal() {
    const modal = document.getElementById('announcement-compose-modal');
    if (!modal) return;
    modal.classList.remove('active');
    resetAnnouncementComposerForm();
}

function openAnnouncementComposerModal() {
    const modal = document.getElementById('announcement-compose-modal');
    if (!modal) return;
    populateAnnouncementComposerCampusOptions();
    populateAnnouncementComposerProgramOptions();
    syncAnnouncementStudentCompletionVisibility();
    modal.classList.add('active');

    const titleInput = document.getElementById('announcement-compose-title');
    if (titleInput) titleInput.focus();
}

function handleAnnouncementComposeSubmit(event) {
    if (event) event.preventDefault();

    const titleInput = document.getElementById('announcement-compose-title');
    const messageInput = document.getElementById('announcement-compose-message');
    const roleSelect = document.getElementById('announcement-target-role');
    const campusSelect = document.getElementById('announcement-target-campus');
    const programSelect = document.getElementById('announcement-target-program');
    const completionSelect = document.getElementById('announcement-student-completion');
    const feedback = document.getElementById('announcement-compose-feedback');

    const title = String(titleInput ? titleInput.value : '').trim();
    const message = String(messageInput ? messageInput.value : '').trim();
    const role = normalizeAnnouncementComposerToken(roleSelect ? roleSelect.value : '');
    const campus = normalizeAnnouncementComposerToken(campusSelect ? campusSelect.value : '');
    const programCode = normalizeAnnouncementComposerToken(programSelect ? programSelect.value : '');
    const studentCompletion = role === 'student'
        ? normalizeAnnouncementComposerToken(completionSelect ? completionSelect.value : 'all')
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
            createdByRole: normalizeAnnouncementComposerToken(session.role || 'admin') || 'admin',
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
                role: String(session.role || 'admin').trim(),
            });
        }

        closeAnnouncementComposerModal();
        alert('Announcement published successfully.');
    } catch (error) {
        console.error('[AdminPanel] Failed to publish announcement.', error);
        if (feedback) {
            feedback.textContent = 'Failed to publish announcement. Please try again.';
        } else {
            alert('Failed to publish announcement.');
        }
    }
}

function setupAnnouncementComposer() {
    if (announcementComposerReady) return;

    const modal = document.getElementById('announcement-compose-modal');
    if (!modal) return;

    const closeBtn = document.getElementById('close-announcement-compose-modal');
    const cancelBtn = document.getElementById('cancel-announcement-compose-btn');
    const form = document.getElementById('announcement-compose-form');
    const roleSelect = document.getElementById('announcement-target-role');
    const campusSelect = document.getElementById('announcement-target-campus');

    if (closeBtn) closeBtn.addEventListener('click', closeAnnouncementComposerModal);
    if (cancelBtn) cancelBtn.addEventListener('click', closeAnnouncementComposerModal);
    if (form) form.addEventListener('submit', handleAnnouncementComposeSubmit);
    if (roleSelect) roleSelect.addEventListener('change', syncAnnouncementStudentCompletionVisibility);
    if (campusSelect) campusSelect.addEventListener('change', populateAnnouncementComposerProgramOptions);
    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeAnnouncementComposerModal();
        }
    });

    announcementComposerReady = true;
    resetAnnouncementComposerForm();
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
        case 'manage-subjects':
            switchToView('subject-management');
            break;
        case 'manage-campus':
            alert('Campus management coming soon!');
            break;
        case 'send-announcement':
            openAnnouncementComposerModal();
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

function isContentViewVisible(viewId) {
    const view = document.getElementById(viewId);
    return !!(view && view.style.display !== 'none');
}

function handleViewShown(viewId) {
    if (viewId === 'dashboard') {
        updateOverviewCards();
        initializeCharts();
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
    if (viewId === 'subject-management') {
        refreshSubjectManagementView();
    }
}

const SUBJECT_BULK_HEADER_ALIASES = {
    campus: 'campusSlug',
    campusid: 'campusSlug',
    campusname: 'campusSlug',
    department: 'departmentCode',
    dept: 'departmentCode',
    subjectcode: 'subjectCode',
    subjectname: 'subjectName',
    subjecttitle: 'subjectName',
};

const OFFERING_BULK_HEADER_ALIASES = {
    semesterslug: 'semesterSlug',
    semester: 'semesterSlug',
    campus: 'campusSlug',
    campusid: 'campusSlug',
    campusname: 'campusSlug',
    department: 'departmentCode',
    dept: 'departmentCode',
    program: 'programCode',
    programcode: 'programCode',
    subjectcode: 'subjectCode',
    section: 'sectionName',
    professoremployeeid: 'professorEmployeeId',
    professor_employee_id: 'professorEmployeeId',
    professorid: 'professorEmployeeId',
    professor_id: 'professorEmployeeId',
    professoruserid: 'professorEmployeeId',
    professor_user_id: 'professorEmployeeId',
    employeeid: 'professorEmployeeId',
    employee_id: 'professorEmployeeId',
};

function normalizeBulkProfessorEmployeeId(value) {
    return normalizeBulkText(value);
}

function setupSubjectManagement() {
    const subjectCampusFilter = document.getElementById('subject-campus-filter');
    const subjectDepartmentFilter = document.getElementById('subject-department-filter');
    const addSubjectBtn = document.getElementById('add-subject-btn');
    const subjectCatalogBody = document.getElementById('subject-catalog-body');
    const closeSubjectModalBtn = document.getElementById('close-subject-modal');
    const cancelSubjectFormBtn = document.getElementById('cancel-subject-form');
    const subjectForm = document.getElementById('subject-form');
    const subjectCampusSelect = document.getElementById('subject-campus');
    const bulkSubjectImportBtn = document.getElementById('bulk-subject-import-btn');
    const bulkSubjectImportInput = document.getElementById('bulk-subject-import-input');
    const bulkOfferingImportBtn = document.getElementById('bulk-offering-import-btn');
    const bulkOfferingImportInput = document.getElementById('bulk-offering-import-input');
    const offeringForm = document.getElementById('offering-form');
    const offeringCampus = document.getElementById('offering-campus');
    const offeringDepartment = document.getElementById('offering-department');
    const offeringProgram = document.getElementById('offering-program');
    const offeringSemester = document.getElementById('offering-semester');
    const offeringSearch = document.getElementById('offering-search');
    const offeringListBody = document.getElementById('offering-list-body');
    const closeOfferingStudentsBtn = document.getElementById('close-offering-students-modal');
    const cancelOfferingStudentsBtn = document.getElementById('cancel-offering-students-form');
    const saveOfferingStudentsBtn = document.getElementById('save-offering-students-btn');
    const offeringStudentsSearch = document.getElementById('offering-students-search');
    const offeringStudentsProgramFilter = document.getElementById('offering-students-program-filter');

    if (subjectCampusFilter) {
        subjectCampusFilter.addEventListener('change', () => {
            populateSubjectDepartmentFilter();
            renderSubjectCatalog();
        });
    }
    if (subjectDepartmentFilter) {
        subjectDepartmentFilter.addEventListener('change', renderSubjectCatalog);
    }

    if (addSubjectBtn) {
        addSubjectBtn.addEventListener('click', () => openSubjectModal(null));
    }
    if (closeSubjectModalBtn) {
        closeSubjectModalBtn.addEventListener('click', closeSubjectModal);
    }
    if (cancelSubjectFormBtn) {
        cancelSubjectFormBtn.addEventListener('click', closeSubjectModal);
    }
    if (subjectForm) {
        subjectForm.addEventListener('submit', handleSubjectFormSubmit);
    }
    if (subjectCampusSelect) {
        subjectCampusSelect.addEventListener('change', () => {
            populateSubjectDepartmentSelect();
        });
    }

    if (subjectCatalogBody) {
        subjectCatalogBody.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-action]');
            if (!button) return;
            const action = button.getAttribute('data-action');
            const subjectId = parseInt(button.getAttribute('data-subject-id'), 10);
            if (action === 'edit-subject') {
                const subject = (subjectManagementState.subjects || []).find(item => Number(item.id) === Number(subjectId));
                if (subject) {
                    openSubjectModal(subject);
                }
            }
        });
    }

    if (bulkSubjectImportBtn && bulkSubjectImportInput) {
        bulkSubjectImportBtn.addEventListener('click', () => bulkSubjectImportInput.click());
        bulkSubjectImportInput.addEventListener('change', handleBulkSubjectImport);
    }
    if (bulkOfferingImportBtn && bulkOfferingImportInput) {
        bulkOfferingImportBtn.addEventListener('click', () => bulkOfferingImportInput.click());
        bulkOfferingImportInput.addEventListener('change', handleBulkOfferingImport);
    }

    if (offeringCampus) {
        offeringCampus.addEventListener('change', () => {
            populateOfferingDepartmentSelect();
            populateOfferingProgramSelect();
            populateOfferingSubjectSelect();
            populateOfferingProfessorSelect();
        });
    }
    if (offeringDepartment) {
        offeringDepartment.addEventListener('change', () => {
            populateOfferingProgramSelect();
            populateOfferingSubjectSelect();
            populateOfferingProfessorSelect();
        });
    }
    if (offeringProgram) {
        offeringProgram.addEventListener('change', () => {
            populateOfferingProfessorSelect();
        });
    }
    if (offeringSemester) {
        offeringSemester.addEventListener('change', renderOfferingsTable);
    }
    if (offeringSearch) {
        offeringSearch.addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            offeringSearchAppliedTerm = String(offeringSearch.value || '').trim().toLowerCase();
            renderOfferingsTable();
        });
    }
    if (offeringForm) {
        offeringForm.addEventListener('submit', handleOfferingFormSubmit);
    }

    if (offeringListBody) {
        offeringListBody.addEventListener('click', (event) => {
            const button = event.target.closest('button[data-action]');
            if (!button) return;
            const action = button.getAttribute('data-action');
            const offeringId = parseInt(button.getAttribute('data-offering-id'), 10);
            if (action === 'manage-offering-students') {
                openOfferingStudentsModal(offeringId);
            }
            if (action === 'deactivate-offering') {
                deactivateOffering(offeringId);
            }
        });
    }

    if (closeOfferingStudentsBtn) {
        closeOfferingStudentsBtn.addEventListener('click', closeOfferingStudentsModal);
    }
    if (cancelOfferingStudentsBtn) {
        cancelOfferingStudentsBtn.addEventListener('click', closeOfferingStudentsModal);
    }
    if (saveOfferingStudentsBtn) {
        saveOfferingStudentsBtn.addEventListener('click', saveOfferingStudentsSelection);
    }
    if (offeringStudentsSearch) {
        offeringStudentsSearch.addEventListener('input', () => renderOfferingStudentsList());
    }
    if (offeringStudentsProgramFilter) {
        offeringStudentsProgramFilter.addEventListener('change', () => renderOfferingStudentsList());
    }

    window.addEventListener('click', (event) => {
        if (event.target && event.target.id === 'subject-modal') {
            closeSubjectModal();
        }
        if (event.target && event.target.id === 'offering-students-modal') {
            closeOfferingStudentsModal();
        }
    });

    SharedData.onDataChange(function (key) {
        if (
            key === SharedData.KEYS.SUBJECT_MANAGEMENT ||
            key === SharedData.KEYS.USERS ||
            key === SharedData.KEYS.CAMPUSES ||
            key === SharedData.KEYS.PROGRAMS ||
            key === SharedData.KEYS.CURRENT_SEMESTER ||
            key === SharedData.KEYS.SEMESTER_LIST
        ) {
            refreshSubjectManagementView();
        }
    });

    refreshSubjectManagementView();
}

function normalizeSubjectManagementState(snapshot) {
    const raw = snapshot || {};
    return {
        subjects: Array.isArray(raw.subjects) ? raw.subjects : [],
        offerings: Array.isArray(raw.offerings) ? raw.offerings : [],
        enrollments: Array.isArray(raw.enrollments) ? raw.enrollments : [],
    };
}

function refreshSubjectManagementData() {
    subjectManagementState = normalizeSubjectManagementState(
        SharedData.getSubjectManagement ? SharedData.getSubjectManagement() : null
    );
}

function refreshSubjectManagementView() {
    const view = document.getElementById('subject-management-view');
    if (!view) return;

    refreshSubjectManagementData();
    populateSubjectCampusFilter();
    populateSubjectDepartmentFilter();
    renderSubjectCatalog();
    populateOfferingSemesterSelect();
    populateOfferingCampusSelect();
    populateOfferingDepartmentSelect();
    populateOfferingProgramSelect();
    populateOfferingSubjectSelect();
    populateOfferingProfessorSelect();
    renderOfferingsTable();
}

function getCampusOptions() {
    return (SharedData.getCampuses() || []).filter(campus => campus && campus.id !== 'all');
}

function getProgramsForCampusDepartment(campusSlug, departmentCode) {
    const programs = SharedData.getPrograms ? SharedData.getPrograms() : [];
    return (Array.isArray(programs) ? programs : []).filter(program =>
        String(program.campusSlug || '').toLowerCase() === String(campusSlug || '').toLowerCase() &&
        String(program.departmentCode || '').toLowerCase() === String(departmentCode || '').toLowerCase()
    ).sort((a, b) => String(a.programCode || '').localeCompare(String(b.programCode || '')));
}

function populateSubjectCampusFilter() {
    const select = document.getElementById('subject-campus-filter');
    if (!select) return;
    const previous = select.value || 'all';
    const campusOptions = getCampusOptions();
    select.innerHTML = '<option value="all">All Campuses</option>' + campusOptions.map(campus => (
        `<option value="${campus.id}">${campus.name}</option>`
    )).join('');
    select.value = campusOptions.some(campus => campus.id === previous) || previous === 'all'
        ? previous
        : 'all';
}

function populateSubjectDepartmentFilter() {
    const campusSelect = document.getElementById('subject-campus-filter');
    const departmentSelect = document.getElementById('subject-department-filter');
    if (!campusSelect || !departmentSelect) return;

    const selectedCampus = campusSelect.value || 'all';
    const previous = departmentSelect.value || 'all';
    const departments = getDepartmentsForCampus(selectedCampus === 'all' ? 'all' : selectedCampus);
    departmentSelect.innerHTML = '<option value="all">All Departments</option>' + departments.map(dept => (
        `<option value="${dept}">${getDepartmentLabel(dept)}</option>`
    )).join('');
    departmentSelect.value = departments.some(dept => dept === previous) || previous === 'all'
        ? previous
        : 'all';
}

function renderSubjectCatalog() {
    const body = document.getElementById('subject-catalog-body');
    if (!body) return;

    const campusFilter = (document.getElementById('subject-campus-filter') || {}).value || 'all';
    const departmentFilter = (document.getElementById('subject-department-filter') || {}).value || 'all';

    let subjects = [...(subjectManagementState.subjects || [])];
    if (campusFilter !== 'all') {
        subjects = subjects.filter(subject => String(subject.campusSlug) === String(campusFilter));
    }
    if (departmentFilter !== 'all') {
        subjects = subjects.filter(subject => String(subject.departmentCode).toLowerCase() === String(departmentFilter).toLowerCase());
    }

    subjects.sort((a, b) => {
        const campusCompare = String(a.campusName || '').localeCompare(String(b.campusName || ''));
        if (campusCompare !== 0) return campusCompare;
        const deptCompare = String(a.departmentCode || '').localeCompare(String(b.departmentCode || ''));
        if (deptCompare !== 0) return deptCompare;
        return String(a.subjectCode || '').localeCompare(String(b.subjectCode || ''));
    });

    if (!subjects.length) {
        body.innerHTML = `
            <tr>
                <td colspan="5" style="text-align:center; padding:18px;">No subjects found for selected filters.</td>
            </tr>
        `;
        return;
    }

    body.innerHTML = subjects.map(subject => `
        <tr>
            <td>${subject.campusName || subject.campusSlug || 'N/A'}</td>
            <td>${getDepartmentLabel(subject.departmentCode || '')}</td>
            <td>${subject.subjectCode || ''}</td>
            <td>${subject.subjectName || ''}</td>
            <td>
                <div class="professor-actions">
                    <button type="button" class="action-btn edit" data-action="edit-subject" data-subject-id="${subject.id}" title="Edit Subject">
                        <i class="fas fa-edit"></i>
                    </button>
                </div>
            </td>
        </tr>
    `).join('');
}

function openSubjectModal(subject) {
    const modal = document.getElementById('subject-modal');
    const modalTitle = document.getElementById('subject-modal-title');
    const idInput = document.getElementById('subject-id');
    const campusSelect = document.getElementById('subject-campus');
    const codeInput = document.getElementById('subject-code');
    const nameInput = document.getElementById('subject-name');
    if (!modal || !modalTitle || !idInput || !campusSelect || !codeInput || !nameInput) return;

    editingSubjectId = subject ? Number(subject.id) : null;
    modalTitle.textContent = subject ? 'Edit Subject' : 'Add Subject';
    idInput.value = subject ? String(subject.id) : '';

    const campusOptions = getCampusOptions();
    campusSelect.innerHTML = '<option value="">Select Campus</option>' + campusOptions.map(campus => (
        `<option value="${campus.id}">${campus.name}</option>`
    )).join('');

    campusSelect.value = subject ? String(subject.campusSlug || '') : (campusOptions[0] ? campusOptions[0].id : '');
    populateSubjectDepartmentSelect(subject ? String(subject.departmentCode || '') : '');

    codeInput.value = subject ? String(subject.subjectCode || '') : '';
    nameInput.value = subject ? String(subject.subjectName || '') : '';
    modal.classList.add('active');
}

function closeSubjectModal() {
    const modal = document.getElementById('subject-modal');
    const form = document.getElementById('subject-form');
    if (form) form.reset();
    editingSubjectId = null;
    if (modal) modal.classList.remove('active');
}

function populateSubjectDepartmentSelect(selectedDepartment) {
    const campusSelect = document.getElementById('subject-campus');
    const departmentSelect = document.getElementById('subject-department');
    if (!campusSelect || !departmentSelect) return;

    const campusId = campusSelect.value;
    const departments = getDepartmentsForCampus(campusId);
    departmentSelect.innerHTML = departments.map(dept => (
        `<option value="${dept}">${getDepartmentLabel(dept)}</option>`
    )).join('');
    if (!departments.length) {
        departmentSelect.innerHTML = '<option value="">No departments</option>';
        departmentSelect.value = '';
        return;
    }

    if (selectedDepartment && departments.some(dept => String(dept).toLowerCase() === String(selectedDepartment).toLowerCase())) {
        departmentSelect.value = departments.find(dept => String(dept).toLowerCase() === String(selectedDepartment).toLowerCase());
    } else {
        departmentSelect.value = departments[0];
    }
}

function handleSubjectFormSubmit(event) {
    event.preventDefault();

    const idValue = (document.getElementById('subject-id') || {}).value || '';
    const campusSlug = ((document.getElementById('subject-campus') || {}).value || '').trim();
    const departmentCode = ((document.getElementById('subject-department') || {}).value || '').trim();
    const subjectCode = (((document.getElementById('subject-code') || {}).value || '').trim() || '').toUpperCase();
    const subjectName = ((document.getElementById('subject-name') || {}).value || '').trim();

    if (!campusSlug || !departmentCode || !subjectCode || !subjectName) {
        alert('Please complete all subject fields.');
        return;
    }

    try {
        SharedData.upsertSubject({
            id: idValue || undefined,
            campusSlug,
            departmentCode,
            subjectCode,
            subjectName,
        });
        closeSubjectModal();
        refreshSubjectManagementView();
    } catch (error) {
        alert('Unable to save subject: ' + (error.message || 'Unknown error'));
    }
}

function mapSubjectBulkRow(rawRow) {
    const mapped = {
        campusSlug: '',
        departmentCode: '',
        subjectCode: '',
        subjectName: '',
    };

    Object.entries(rawRow || {}).forEach(([key, value]) => {
        const normalizedKey = normalizeBulkHeaderKey(key);
        const canonicalKey = SUBJECT_BULK_HEADER_ALIASES[normalizedKey];
        if (!canonicalKey) return;
        mapped[canonicalKey] = normalizeBulkText(value);
    });

    return mapped;
}

async function handleBulkSubjectImport(event) {
    const input = event.target;
    const file = input && input.files && input.files[0];
    if (!file) return;

    try {
        if (typeof XLSX === 'undefined') {
            throw new Error('Excel parser is not loaded.');
        }

        const rows = await readExcelRows(file);
        if (!rows.length) {
            alert('No importable rows found in the first worksheet.');
            return;
        }

        const existingUsers = SharedData.getUsers();
        const campusMap = buildBulkCampusMap(existingUsers);
        const departmentMap = buildBulkDepartmentMap(existingUsers);

        const payloadRows = [];
        const rowErrors = [];
        rows.forEach((rawRow, index) => {
            const rowNumber = index + 2;
            const mapped = mapSubjectBulkRow(rawRow);
            const hasAnyValue = Object.values(mapped).some(Boolean);
            if (!hasAnyValue) return;

            const campusSlug = campusMap.get(normalizeBulkLookupKey(mapped.campusSlug));
            if (!campusSlug) {
                rowErrors.push(`Row ${rowNumber}: unknown campus "${mapped.campusSlug}".`);
                return;
            }

            const departmentCode = normalizeBulkDepartment(mapped.departmentCode, campusSlug, departmentMap);
            if (!departmentCode || departmentCode === BULK_UNASSIGNED_DEPARTMENT) {
                rowErrors.push(`Row ${rowNumber}: unknown department "${mapped.departmentCode}".`);
                return;
            }

            const subjectCode = normalizeBulkText(mapped.subjectCode).toUpperCase();
            const subjectName = normalizeBulkText(mapped.subjectName);
            if (!subjectCode || !subjectName) {
                rowErrors.push(`Row ${rowNumber}: subject_code and subject_name are required.`);
                return;
            }

            payloadRows.push({
                campusSlug,
                departmentCode,
                subjectCode,
                subjectName,
            });
        });

        if (!payloadRows.length) {
            const prefix = rowErrors.length ? 'No valid rows found.\n' : '';
            alert(prefix + rowErrors.join('\n'));
            return;
        }

        const result = SharedData.importSubjects(payloadRows);
        refreshSubjectManagementView();

        const summaryLines = [
            'Subject import complete.',
            `Created: ${result.created || 0}`,
            `Updated: ${result.updated || 0}`,
            `Failed: ${(result.failed || 0) + rowErrors.length}`,
        ];

        const backendErrors = Array.isArray(result.errors) ? result.errors : [];
        const combinedErrors = rowErrors.concat(backendErrors);
        if (combinedErrors.length) {
            summaryLines.push('', 'Errors:');
            combinedErrors.forEach(msg => summaryLines.push('- ' + msg));
        }

        alert(summaryLines.join('\n'));
    } catch (error) {
        alert('Bulk subject import failed: ' + (error.message || 'Unknown error'));
    } finally {
        if (input) input.value = '';
    }
}

function mapOfferingBulkRow(rawRow) {
    const mapped = {
        semesterSlug: '',
        campusSlug: '',
        departmentCode: '',
        programCode: '',
        subjectCode: '',
        sectionName: '',
        professorEmployeeId: '',
    };

    Object.entries(rawRow || {}).forEach(([key, value]) => {
        const normalizedKey = normalizeBulkHeaderKey(key);
        const canonicalKey = OFFERING_BULK_HEADER_ALIASES[normalizedKey];
        if (!canonicalKey) return;
        mapped[canonicalKey] = normalizeBulkText(value);
    });

    return mapped;
}

async function handleBulkOfferingImport(event) {
    const input = event.target;
    const file = input && input.files && input.files[0];
    if (!file) return;

    try {
        if (typeof XLSX === 'undefined') {
            throw new Error('Excel parser is not loaded.');
        }

        const rows = await readExcelRows(file);
        if (!rows.length) {
            alert('No importable rows found in the first worksheet.');
            return;
        }

        const users = SharedData.getUsers() || [];
        const existingUsers = Array.isArray(users) ? users : [];
        const campusMap = buildBulkCampusMap(existingUsers);
        const departmentMap = buildBulkDepartmentMap(existingUsers);
        const programMap = buildBulkProgramMap();
        const currentSemester = String(SharedData.getCurrentSemester ? SharedData.getCurrentSemester() : '').trim();

        const payloadRows = [];
        const rowErrors = [];

        rows.forEach((rawRow, index) => {
            const rowNumber = index + 2;
            const mapped = mapOfferingBulkRow(rawRow);
            const hasAnyValue = Object.values(mapped).some(Boolean);
            if (!hasAnyValue) return;

            const semesterSlug = normalizeBulkText(mapped.semesterSlug) || currentSemester;
            if (!semesterSlug) {
                rowErrors.push(`Row ${rowNumber}: semester_slug is required.`);
                return;
            }

            const campusSlug = campusMap.get(normalizeBulkLookupKey(mapped.campusSlug));
            if (!campusSlug) {
                rowErrors.push(`Row ${rowNumber}: unknown campus "${mapped.campusSlug}".`);
                return;
            }

            const departmentCode = normalizeBulkDepartment(mapped.departmentCode, campusSlug, departmentMap);
            if (!departmentCode || departmentCode === BULK_UNASSIGNED_DEPARTMENT) {
                rowErrors.push(`Row ${rowNumber}: unknown department "${mapped.departmentCode}".`);
                return;
            }

            const programRecord = resolveBulkProgramRecord(campusSlug, departmentCode, mapped.programCode, programMap);
            if (!programRecord) {
                rowErrors.push(`Row ${rowNumber}: unknown program "${mapped.programCode}" for ${campusSlug}/${departmentCode}.`);
                return;
            }

            const subjectCode = normalizeBulkText(mapped.subjectCode).toUpperCase();
            if (!subjectCode) {
                rowErrors.push(`Row ${rowNumber}: subject_code is required.`);
                return;
            }

            const sectionName = normalizeOfferingSection(mapped.sectionName);
            if (!sectionName) {
                rowErrors.push(`Row ${rowNumber}: section must be in Y/S format (example: 3/1; 3-1 is accepted).`);
                return;
            }

            const professorEmployeeId = normalizeBulkProfessorEmployeeId(mapped.professorEmployeeId);
            if (!professorEmployeeId) {
                rowErrors.push(`Row ${rowNumber}: professor_employee_id is required.`);
                return;
            }

            payloadRows.push({
                semesterSlug,
                campusSlug,
                departmentCode,
                programCode: programRecord.programCode,
                subjectCode,
                sectionName,
                professorEmployeeId,
            });
        });

        if (!payloadRows.length) {
            const prefix = rowErrors.length ? 'No valid rows found.\n' : '';
            alert(prefix + rowErrors.join('\n'));
            return;
        }

        const result = SharedData.importCourseOfferings(payloadRows, { replaceExisting: true });
        refreshSubjectManagementView();
        updateOverviewCards();

        const summaryLines = [
            'Offering import complete (existing assignments replaced).',
            `Offerings Created: ${result.createdOfferings || 0}`,
            `Offerings Updated: ${result.updatedOfferings || 0}`,
            `Auto-enrolled Students: ${result.autoEnrolledStudents || 0}`,
            `Failed: ${(result.failed || 0) + rowErrors.length}`,
        ];

        const backendErrors = Array.isArray(result.errors) ? result.errors : [];
        const combinedErrors = rowErrors.concat(backendErrors);
        if (combinedErrors.length) {
            summaryLines.push('', 'Errors:');
            combinedErrors.forEach(msg => summaryLines.push('- ' + msg));
        }

        alert(summaryLines.join('\n'));
    } catch (error) {
        alert('Bulk offering import failed: ' + (error.message || 'Unknown error'));
    } finally {
        if (input) input.value = '';
    }
}

function populateOfferingSemesterSelect() {
    const semesterSelect = document.getElementById('offering-semester');
    if (!semesterSelect) return;
    const currentSemester = String(SharedData.getCurrentSemester ? SharedData.getCurrentSemester() : '').trim();
    const semesterList = SharedData.getSemesterList ? SharedData.getSemesterList() : [];
    const currentItem = semesterList.find(item => String(item && item.value || '') === currentSemester);
    const currentLabel = currentItem && currentItem.label ? currentItem.label : (currentSemester || 'Current Semester');

    semesterSelect.innerHTML = `<option value="${currentSemester}">${currentLabel}</option>`;
    semesterSelect.value = currentSemester;
    semesterSelect.disabled = true;
}

function populateOfferingCampusSelect() {
    const campusSelect = document.getElementById('offering-campus');
    if (!campusSelect) return;
    const campusOptions = getCampusOptions();
    const previous = campusSelect.value;
    campusSelect.innerHTML = campusOptions.map(campus => (
        `<option value="${campus.id}">${campus.name}</option>`
    )).join('');
    if (!campusOptions.length) {
        campusSelect.innerHTML = '<option value="">No campuses</option>';
        campusSelect.value = '';
        return;
    }

    campusSelect.value = campusOptions.some(campus => campus.id === previous) ? previous : campusOptions[0].id;
}

function populateOfferingDepartmentSelect() {
    const campusSelect = document.getElementById('offering-campus');
    const departmentSelect = document.getElementById('offering-department');
    if (!campusSelect || !departmentSelect) return;
    const campusId = campusSelect.value;
    const departments = getDepartmentsForCampus(campusId);
    const previous = departmentSelect.value;
    departmentSelect.innerHTML = departments.map(dept => (
        `<option value="${dept}">${getDepartmentLabel(dept)}</option>`
    )).join('');
    if (!departments.length) {
        departmentSelect.innerHTML = '<option value="">No departments</option>';
        departmentSelect.value = '';
        return;
    }
    departmentSelect.value = departments.some(dept => dept === previous) ? previous : departments[0];
}

function populateOfferingProgramSelect() {
    const campusSelect = document.getElementById('offering-campus');
    const departmentSelect = document.getElementById('offering-department');
    const programSelect = document.getElementById('offering-program');
    if (!campusSelect || !departmentSelect || !programSelect) return;

    const campusSlug = campusSelect.value;
    const departmentCode = departmentSelect.value;
    const programs = getProgramsForCampusDepartment(campusSlug, departmentCode);
    const previous = normalizeProgramCode(programSelect.value);

    programSelect.innerHTML = programs.map(program => (
        `<option value="${program.programCode}">${program.programCode}${program.programName ? ` - ${program.programName}` : ''}</option>`
    )).join('');
    if (!programs.length) {
        programSelect.innerHTML = '<option value="">No programs available</option>';
        programSelect.value = '';
        return;
    }

    const found = programs.find(program => normalizeProgramCode(program.programCode) === previous);
    programSelect.value = found ? found.programCode : programs[0].programCode;
}

function populateOfferingSubjectSelect() {
    const campusSelect = document.getElementById('offering-campus');
    const departmentSelect = document.getElementById('offering-department');
    const subjectSelect = document.getElementById('offering-subject');
    if (!campusSelect || !departmentSelect || !subjectSelect) return;

    const campusSlug = campusSelect.value;
    const departmentCode = departmentSelect.value;
    const subjects = (subjectManagementState.subjects || []).filter(subject =>
        String(subject.campusSlug) === String(campusSlug) &&
        String(subject.departmentCode).toLowerCase() === String(departmentCode).toLowerCase()
    );
    const previous = subjectSelect.value;

    subjectSelect.innerHTML = subjects.map(subject => (
        `<option value="${subject.id}">${subject.subjectCode} - ${subject.subjectName}</option>`
    )).join('');
    if (!subjects.length) {
        subjectSelect.innerHTML = '<option value="">No subjects available</option>';
        subjectSelect.value = '';
        return;
    }

    subjectSelect.value = subjects.some(subject => String(subject.id) === String(previous)) ? previous : String(subjects[0].id);
}

function populateOfferingProfessorSelect() {
    const campusSelect = document.getElementById('offering-campus');
    const departmentSelect = document.getElementById('offering-department');
    const programSelect = document.getElementById('offering-program');
    const professorSelect = document.getElementById('offering-professor');
    if (!campusSelect || !departmentSelect || !programSelect || !professorSelect) return;

    const campusSlug = String(campusSelect.value || '').toLowerCase();
    const departmentCode = String(departmentSelect.value || '').toLowerCase();
    const programCode = normalizeProgramCode(programSelect.value);
    const professors = (SharedData.getUsers() || []).filter(user => (
        normalizeRoleCode(user.role) === 'professor' &&
        String(user.status || '').toLowerCase() === 'active' &&
        String(user.campus || '').toLowerCase() === campusSlug &&
        String(user.department || '').toLowerCase() === departmentCode &&
        normalizeProgramCode(user.programCode) === programCode &&
        normalizeBulkText(user.employeeId) !== ''
    ));
    const previous = professorSelect.value;

    professorSelect.innerHTML = professors.map(professor => (
        `<option value="${professor.employeeId}">${professor.name}</option>`
    )).join('');
    if (!professors.length) {
        professorSelect.innerHTML = '<option value="">No active professors with employee ID</option>';
        professorSelect.value = '';
        return;
    }

    professorSelect.value = professors.some(professor => String(professor.employeeId) === String(previous))
        ? previous
        : String(professors[0].employeeId);
}

function handleOfferingFormSubmit(event) {
    event.preventDefault();
    const semesterSlug = ((document.getElementById('offering-semester') || {}).value || '').trim();
    const subjectId = ((document.getElementById('offering-subject') || {}).value || '').trim();
    const programCode = normalizeProgramCode((document.getElementById('offering-program') || {}).value || '');
    const professorEmployeeId = ((document.getElementById('offering-professor') || {}).value || '').trim();
    const rawSectionName = ((document.getElementById('offering-section') || {}).value || '').trim();
    const sectionName = normalizeOfferingSection(rawSectionName);

    if (!semesterSlug || !subjectId || !programCode || !professorEmployeeId || !rawSectionName) {
        alert('Please complete all offering assignment fields.');
        return;
    }
    if (!sectionName) {
        alert('Section must be in Y/S format (example: 3/1). You may also enter 3-1 and it will be normalized.');
        return;
    }

    try {
        SharedData.upsertCourseOffering({
            semesterSlug,
            subjectId,
            programCode,
            professorEmployeeId,
            sectionName,
            isActive: true,
        });
        const sectionInput = document.getElementById('offering-section');
        if (sectionInput) sectionInput.value = '';
        refreshSubjectManagementView();
        updateOverviewCards();
    } catch (error) {
        alert('Unable to save offering assignment: ' + (error.message || 'Unknown error'));
    }
}

function renderOfferingsTable() {
    const body = document.getElementById('offering-list-body');
    const semesterSelect = document.getElementById('offering-semester');
    if (!body || !semesterSelect) return;

    const semesterSlug = semesterSelect.value || '';
    const searchTerm = offeringSearchAppliedTerm;
    const enrollments = subjectManagementState.enrollments || [];
    const enrollmentCountByOffering = {};
    enrollments.forEach(enrollment => {
        if (!enrollment) return;
        const offeringId = String(enrollment.courseOfferingId);
        if (String(enrollment.status || '').toLowerCase() !== 'enrolled') return;
        enrollmentCountByOffering[offeringId] = (enrollmentCountByOffering[offeringId] || 0) + 1;
    });

    let offerings = (subjectManagementState.offerings || [])
        .filter(offering => String(offering.semesterSlug || '') === String(semesterSlug))
        .sort((a, b) => {
            const campusCompare = String(a.campusSlug || '').localeCompare(String(b.campusSlug || ''));
            if (campusCompare !== 0) return campusCompare;
            const deptCompare = String(a.departmentCode || '').localeCompare(String(b.departmentCode || ''));
            if (deptCompare !== 0) return deptCompare;
            const subjectCompare = String(a.subjectCode || '').localeCompare(String(b.subjectCode || ''));
            if (subjectCompare !== 0) return subjectCompare;
            return String(a.sectionName || '').localeCompare(String(b.sectionName || ''));
        });

    if (searchTerm) {
        offerings = offerings.filter(offering => {
            const sectionName = String(offering.sectionName || '');
            const searchBlob = [
                offering.professorName,
                offering.professorEmployeeId,
                offering.subjectCode,
                offering.subjectName,
                sectionName,
                sectionName.replace(/\//g, '-')
            ].map(value => String(value || '').toLowerCase()).join(' ');
            return searchBlob.includes(searchTerm);
        });
    }

    if (!offerings.length) {
        body.innerHTML = `
            <tr>
                <td colspan="8" style="text-align:center; padding:18px;">${searchTerm ? 'No matching offerings found.' : 'No offerings assigned for this semester.'}</td>
            </tr>
        `;
        return;
    }

    body.innerHTML = offerings.map(offering => {
        const offeringId = String(offering.id);
        const assignedStudents = enrollmentCountByOffering[offeringId] || 0;
        return `
            <tr class="${offering.isActive ? '' : 'inactive'}">
                <td>${offering.subjectCode} - ${offering.subjectName}</td>
                <td>${offering.sectionName}</td>
                <td>${offering.professorName}</td>
                <td>${offering.professorEmployeeId || '-'}</td>
                <td>${offering.programCode || '-'}</td>
                <td>${String(offering.campusSlug || '').toUpperCase()} / ${String(offering.departmentCode || '').toUpperCase()}</td>
                <td>
                    <span class="status-pill ${offering.isActive ? 'active' : 'inactive'}">
                        ${offering.isActive ? 'Active' : 'Inactive'}
                    </span>
                    <div style="font-size:12px; color:#64748b; margin-top:4px;">Students: ${assignedStudents}</div>
                </td>
                <td>
                    <div class="professor-actions">
                        <button type="button" class="action-btn view" data-action="manage-offering-students" data-offering-id="${offering.id}" title="Manage Students">
                            <i class="fas fa-user-check"></i>
                        </button>
                        <button type="button" class="action-btn delete" data-action="deactivate-offering" data-offering-id="${offering.id}" title="Deactivate Offering" ${offering.isActive ? '' : 'disabled'}>
                            <i class="fas fa-ban"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }).join('');
}

function getOfferingById(offeringId) {
    return (subjectManagementState.offerings || []).find(offering => Number(offering.id) === Number(offeringId)) || null;
}

function getUserProgramCodeByUserId(userId) {
    const users = SharedData.getUsers() || [];
    const user = users.find(item => String(item.id) === String(userId));
    return normalizeProgramCode(user && user.programCode);
}

function populateOfferingStudentsProgramFilter() {
    const select = document.getElementById('offering-students-program-filter');
    if (!select || !selectedOfferingForStudents) return;

    const campusSlug = selectedOfferingForStudents.campusSlug;
    const departmentCode = selectedOfferingForStudents.departmentCode;
    const programs = getProgramsForCampusDepartment(campusSlug, departmentCode);
    const previous = normalizeProgramCode(select.value);
    const professorProgram = normalizeProgramCode(
        selectedOfferingForStudents.programCode || getUserProgramCodeByUserId(selectedOfferingForStudents.professorUserId)
    );

    select.innerHTML = '<option value="all">All Programs</option>' + programs.map(program => (
        `<option value="${program.programCode}">${program.programCode}${program.programName ? ` - ${program.programName}` : ''}</option>`
    )).join('');

    const preferred = programs.find(program => normalizeProgramCode(program.programCode) === professorProgram);
    if (preferred) {
        select.value = preferred.programCode;
        return;
    }

    const previousMatch = programs.find(program => normalizeProgramCode(program.programCode) === previous);
    select.value = previousMatch ? previousMatch.programCode : 'all';
}

function openOfferingStudentsModal(offeringId) {
    const offering = getOfferingById(offeringId);
    if (!offering) {
        alert('Offering not found.');
        return;
    }

    selectedOfferingForStudents = offering;
    const offeringIdInput = document.getElementById('students-offering-id');
    if (offeringIdInput) offeringIdInput.value = String(offering.id);
    const searchInput = document.getElementById('offering-students-search');
    if (searchInput) searchInput.value = '';
    populateOfferingStudentsProgramFilter();

    renderOfferingStudentsList();
    const modal = document.getElementById('offering-students-modal');
    if (modal) modal.classList.add('active');
}

function closeOfferingStudentsModal() {
    const modal = document.getElementById('offering-students-modal');
    if (modal) modal.classList.remove('active');
    const programFilter = document.getElementById('offering-students-program-filter');
    if (programFilter) programFilter.value = 'all';
    selectedOfferingForStudents = null;
}

function renderOfferingStudentsList() {
    const container = document.getElementById('offering-students-list');
    if (!container || !selectedOfferingForStudents) return;

    const searchTerm = String((document.getElementById('offering-students-search') || {}).value || '').trim().toLowerCase();
    const selectedProgram = String((document.getElementById('offering-students-program-filter') || {}).value || 'all').trim();
    const campus = String(selectedOfferingForStudents.campusSlug || '').toLowerCase();
    const department = String(selectedOfferingForStudents.departmentCode || '').toLowerCase();

    const users = SharedData.getUsers() || [];
    let students = users.filter(user => (
        normalizeRoleCode(user.role) === 'student' &&
        String(user.status || '').toLowerCase() === 'active' &&
        String(user.campus || '').toLowerCase() === campus &&
        String(user.department || '').toLowerCase() === department
    ));

    if (selectedProgram && selectedProgram !== 'all') {
        const normalizedSelectedProgram = normalizeProgramCode(selectedProgram);
        students = students.filter(student => normalizeProgramCode(student.programCode) === normalizedSelectedProgram);
    }

    if (searchTerm) {
        students = students.filter(student => (
            String(student.name || '').toLowerCase().includes(searchTerm) ||
            String(student.studentNumber || '').toLowerCase().includes(searchTerm)
        ));
    }

    students.sort((a, b) => String(a.name || '').localeCompare(String(b.name || '')));

    const assignedSet = new Set(
        (subjectManagementState.enrollments || [])
            .filter(enrollment =>
                Number(enrollment.courseOfferingId) === Number(selectedOfferingForStudents.id) &&
                String(enrollment.status || '').toLowerCase() !== 'dropped'
            )
            .map(enrollment => String(enrollment.studentUserId))
    );

    if (!students.length) {
        container.innerHTML = '<div style="padding:12px; color:#64748b;">No eligible students found.</div>';
        return;
    }

    container.innerHTML = students.map(student => {
        const checked = assignedSet.has(String(student.id)) ? 'checked' : '';
        return `
            <label class="offering-student-row">
                <div class="offering-student-meta">
                    <span class="name">${student.name || 'N/A'}</span>
                    <span class="sub">${student.studentNumber || 'No Student Number'}</span>
                </div>
                <input type="checkbox" class="offering-student-checkbox" value="${student.id}" ${checked}>
            </label>
        `;
    }).join('');
}

function saveOfferingStudentsSelection() {
    if (!selectedOfferingForStudents) return;
    const checkboxes = document.querySelectorAll('#offering-students-list .offering-student-checkbox:checked');
    const studentUserIds = Array.from(checkboxes).map(checkbox => checkbox.value);

    try {
        SharedData.setCourseOfferingStudents(selectedOfferingForStudents.id, studentUserIds);
        closeOfferingStudentsModal();
        refreshSubjectManagementView();
        updateOverviewCards();
    } catch (error) {
        alert('Unable to save assigned students: ' + (error.message || 'Unknown error'));
    }
}

function deactivateOffering(offeringId) {
    if (!confirm('Deactivate this offering? Assigned students will keep history, but no new assignments should use it.')) {
        return;
    }
    try {
        SharedData.deactivateCourseOffering(offeringId);
        refreshSubjectManagementView();
        updateOverviewCards();
    } catch (error) {
        alert('Unable to deactivate offering: ' + (error.message || 'Unknown error'));
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
        if (normalizeRoleCode(user.role) === 'professor') {
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

        // Use global active student accounts as the consistent base population.
        data.evaluatedCount = metrics.evaluatedCount;
        data.totalStudents = getActiveStudentCount();
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
        totalRaters = Math.max(getActiveStudentCount(), metrics.evaluatedCount);
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
        return SharedData.getUsers().filter(function (u) { return normalizeRoleCode(u.role) === 'professor'; });
    }
    function saveProfessorsToSharedData() {
        var allUsers = SharedData.getUsers();
        var nonProfessors = allUsers.filter(function (u) { return normalizeRoleCode(u.role) !== 'professor'; });
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
    // Always load from the canonical shared user snapshot.
    // Using filtered adminUsers can drop professors when later persisted.
    const sourceUsers = SharedData.getUsers();
    professorsData = sourceUsers.filter(function (u) {
        return String(u.role || '').toLowerCase() === 'professor';
    });
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

        if (!updated.department && updated.institute) {
            updated.department = updated.institute;
            changed = true;
        }
        if (updated.department) {
            const normalizedDepartment = String(updated.department).toUpperCase();
            if (updated.department !== normalizedDepartment) {
                updated.department = normalizedDepartment;
                changed = true;
            }
        }

        if (typeof updated.isActive !== 'boolean') {
            const normalizedStatus = String(updated.status || '').toLowerCase();
            updated.isActive = normalizedStatus === 'inactive' ? false : true;
            changed = true;
        }
        if (!updated.status) {
            updated.status = updated.isActive ? 'active' : 'inactive';
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
    fetchUsersFromApi('all', '')
        .finally(() => {
            loadProfessorsData();
            renderProfessors();
        });
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
                    ${filteredProfessors.map(professor => `
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
                            <td>${professor.evaluatedCount || professor.evaluationsCount || 0}</td>
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
                    `).join('')}
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

function resolveProfessorProgramLabel(professor) {
    if (!professor) return 'Not assigned';

    const programCode = normalizeProgramCode(
        professor.programCode || professor.program || ''
    );
    if (!programCode) {
        return 'Not assigned';
    }

    const directProgramName = normalizeBulkText(professor.programName || '');
    if (directProgramName) {
        return `${programCode} - ${directProgramName}`;
    }

    const campus = String(professor.campus || '').toLowerCase();
    const department = String(professor.department || professor.institute || '').toLowerCase();
    const programs = SharedData.getPrograms ? SharedData.getPrograms() : [];
    const matched = (Array.isArray(programs) ? programs : []).find(program =>
        String(program && program.campusSlug || '').toLowerCase() === campus &&
        String(program && program.departmentCode || '').toLowerCase() === department &&
        normalizeProgramCode(program && program.programCode) === programCode
    );

    const matchedName = normalizeBulkText(matched && matched.programName);
    return matchedName ? `${programCode} - ${matchedName}` : programCode;
}

/**
 * View professor details
 */
function viewProfessorDetails(professorId) {
    const professor = professorsData.find(t => String(t.id) === String(professorId));
    if (!professor) return;
    const programLabel = resolveProfessorProgramLabel(professor);

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
                        <label><i class="fas fa-graduation-cap"></i> Program:</label>
                        <span>${programLabel}</span>
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
                    <button class="btn-edit-detail" onclick="closeProfessorDetailsModal(); editProfessor(${JSON.stringify(String(professor.id))});">
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
    const evaluationData = generateEvaluationData();
    updateOverallStatus(evaluationData);
    renderStudentProfessorCharts(evaluationData.studentToProfessor);
    renderProfessorProfessorCharts(evaluationData.professorToProfessor);
    renderSupervisorProfessorCharts(evaluationData.supervisorToProfessor);
}

function getAdminQuestionnaireTypeCode(typeKey) {
    if (typeKey === 'student') return 'student-to-professor';
    if (typeKey === 'peer') return 'professor-to-professor';
    return 'supervisor-to-professor';
}

function buildAdminQuestionSectionLookup(typeKey, context, semesterId) {
    const questionnaireType = getAdminQuestionnaireTypeCode(typeKey);
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
        const title = String(section && (section.title || section.letter) || '').trim() || 'Unassigned';
        if (!categoryOrder.includes(title)) categoryOrder.push(title);
        const sectionIdToken = normalizeAdminAnalyticsToken(section && section.id);
        if (sectionIdToken) sectionTitleById[sectionIdToken] = title;
    });

    const questionToCategory = {};
    questions.forEach(question => {
        const questionToken = normalizeAdminAnalyticsToken(question && question.id);
        if (!questionToken) return;
        const sectionToken = normalizeAdminAnalyticsToken(question && question.sectionId);
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

function resolveAdminEvaluationTargetToken(evaluation, typeKey, context) {
    if (typeKey === 'student') {
        const offeringId = String(evaluation && evaluation.courseOfferingId || '').trim();
        const offering = context.offeringsById[offeringId];
        const fromOffering = normalizeAdminAnalyticsToken(offering && offering.professorUserId);
        if (fromOffering) return fromOffering;
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
        const token = normalizeAdminAnalyticsToken(candidates[index]);
        if (token) return token;
    }

    return '';
}

function aggregateAdminEvaluationTypeData(typeKey, context, semesterId) {
    const sectionLookup = buildAdminQuestionSectionLookup(typeKey, context, semesterId);
    const categoryStats = {};
    sectionLookup.categoryOrder.forEach(category => {
        categoryStats[category] = { sum: 0, count: 0 };
    });

    const ratingDistribution = { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
    const uniqueTargets = new Set();
    let totalEvaluations = 0;
    let totalRatingValue = 0;
    let totalRatingCount = 0;

    (context.evaluations || []).forEach(evaluation => {
        if (getAdminEvaluationTypeKey(evaluation) !== typeKey) return;
        if (!isAdminEvaluationInSemester(evaluation, semesterId)) return;

        totalEvaluations += 1;

        const targetToken = resolveAdminEvaluationTargetToken(evaluation, typeKey, context);
        if (targetToken) uniqueTargets.add(targetToken);

        const ratings = (evaluation && typeof evaluation.ratings === 'object' && evaluation.ratings) ? evaluation.ratings : {};
        const evaluationValues = [];
        Object.keys(ratings).forEach(questionId => {
            const parsed = parseFloat(ratings[questionId]);
            if (!Number.isFinite(parsed)) return;

            const numericRating = clampNumber(parsed, 1, 5);
            evaluationValues.push(numericRating);
            totalRatingValue += numericRating;
            totalRatingCount += 1;

            const questionToken = normalizeAdminAnalyticsToken(questionId);
            const category = sectionLookup.questionToCategory[questionToken] || sectionLookup.fallbackCategory;
            if (!categoryStats[category]) {
                categoryStats[category] = { sum: 0, count: 0 };
            }
            categoryStats[category].sum += numericRating;
            categoryStats[category].count += 1;
        });

        if (evaluationValues.length > 0) {
            const average = evaluationValues.reduce((sum, value) => sum + value, 0) / evaluationValues.length;
            const ratingBucket = clampNumber(Math.round(average), 1, 5);
            ratingDistribution[ratingBucket] = (ratingDistribution[ratingBucket] || 0) + 1;
        }
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
    if (categoryScores.length === 0) {
        categoryScores = [{ category: sectionLookup.fallbackCategory, score: 0 }];
    }

    return {
        categoryScores,
        ratingDistribution,
        averageRating: totalRatingCount > 0 ? parseFloat((totalRatingValue / totalRatingCount).toFixed(1)) : 0,
        totalEvaluations,
        evaluatedCount: uniqueTargets.size,
    };
}

function generateEvaluationTypeData(type, contextInput, semesterIdInput) {
    const typeToken = normalizeAdminAnalyticsToken(type);
    let typeKey = 'student';
    if (typeToken === 'peer' || typeToken.includes('professor')) typeKey = 'peer';
    if (typeToken === 'supervisor' || typeToken.includes('dean') || typeToken.includes('hr')) typeKey = 'supervisor';

    const context = contextInput || getAdminAnalyticsContext();
    const semesterId = semesterIdInput || context.currentSemester || 'all';
    return aggregateAdminEvaluationTypeData(typeKey, context, semesterId);
}

function generateEvaluationData() {
    const context = getAdminAnalyticsContext();
    const semesterId = context.currentSemester || 'all';
    const registrationStats = getStudentRegistrationEvaluationStats();
    const completionRate = registrationStats.total > 0
        ? Math.round((registrationStats.completed / registrationStats.total) * 100)
        : 0;

    return {
        overall: {
            total: registrationStats.total,
            completed: registrationStats.completed,
            pending: registrationStats.pending,
            completionRate,
        },
        studentToProfessor: generateEvaluationTypeData('student', context, semesterId),
        professorToProfessor: generateEvaluationTypeData('peer', context, semesterId),
        supervisorToProfessor: generateEvaluationTypeData('supervisor', context, semesterId),
    };
}

function updateOverallStatus(data) {
    const completedEl = document.getElementById('completed-count');
    const pendingEl = document.getElementById('pending-count');
    const totalEl = document.getElementById('total-count');
    const rateEl = document.getElementById('completion-rate');

    if (completedEl) completedEl.textContent = data.overall.completed;
    if (pendingEl) pendingEl.textContent = data.overall.pending;
    if (totalEl) totalEl.textContent = data.overall.total;
    if (rateEl) rateEl.textContent = data.overall.completionRate + '%';
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

