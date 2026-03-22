// Dean Panel JavaScript - Dashboard Functionality

let deanProfessorCount = 0;

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
 * Check if user is authenticated and is a dean
 * @returns {boolean} - True if user is authenticated as dean
 */
function checkAuthentication() {
    const session = SharedData.getSession();
    if (!session) {
        return false;
    }

    try {
        // Check if user is authenticated and is a dean
        return session.isAuthenticated === true && (session.role === 'dean' || session.role === 'daen');
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
 * Initialize the dean dashboard
 */
function initializeDashboard() {
    loadUserInfo();
    setupNavigation();
    setupLogout();
    setupHeaderPanels();
    setupActionButtons();
    setupTableActions();
    const hasSemesterFilter = setupSemesterFilter();
    if (!hasSemesterFilter) {
        loadFacultySummary();
    }
    loadProfessorCount();
    setupFacultyResponseView();
    setupPeerManagementView();
    setupDeanSubjectComments();
    updateSummaryCards();
    setupPeerEvaluationForm();
    setupProfileActions();
    setupProfilePhotoUpload();
    setupChangeEmailForm();
    setupChangePasswordForm();
    setupPasswordToggles();
    applyReportBlackout();
    initializeReports();
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
            const userProfileSpans = document.querySelectorAll('.user-profile span');
            if (userProfileSpans.length) {
                // Format username: capitalize first letter
                const formattedName = username.charAt(0).toUpperCase() + username.slice(1) + ' Dean';
                userProfileSpans.forEach(span => {
                    span.textContent = formattedName;
                });
            }
        } catch (e) {
            console.error('Error loading user info:', e);
        }
    }
}

/**
 * Setup announcement and profile panels
 */
function setupHeaderPanels() {
    const headerBlocks = document.querySelectorAll('.user-info');
    if (!headerBlocks.length) return;

    headerBlocks.forEach(block => {
        const notificationBtn = block.querySelector('.js-notification-btn');
        const profileBtn = block.querySelector('.js-profile-btn');
        const announcementPanel = block.querySelector('.js-announcement-panel');
        const profilePanel = block.querySelector('.js-profile-panel');

        if (!notificationBtn || !profileBtn || !announcementPanel || !profilePanel) {
            return;
        }

        notificationBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            togglePanel(announcementPanel);
        });

        profileBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            togglePanel(profilePanel);
        });

        announcementPanel.addEventListener('click', function (e) {
            e.stopPropagation();
        });

        profilePanel.addEventListener('click', function (e) {
            e.stopPropagation();
        });
    });

    document.addEventListener('click', function () {
        closeAllPanels();
    });
}

/**
 * Toggle a panel and close the rest
 * @param {HTMLElement} panelToToggle
 */
function togglePanel(panelToToggle) {
    const isActive = panelToToggle.classList.contains('active');
    closeAllPanels();
    if (!isActive) {
        panelToToggle.classList.add('active');
    }
}

/**
 * Close all open dropdown panels
 */
function closeAllPanels() {
    document.querySelectorAll('.dropdown-panel').forEach(panel => {
        panel.classList.remove('active');
    });
}

/**
 * Setup navigation links
 */
function setupNavigation() {
    const navLinks = document.querySelectorAll('.nav-link:not(.logout)');

    navLinks.forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault();

            // Get view to show
            const view = this.getAttribute('data-view');

            // Remove active class from all links
            navLinks.forEach(l => l.classList.remove('active'));

            // Add active class to clicked link
            this.classList.add('active');

            // Handle navigation (for future implementation)
            if (view) {
                handleNavigation(view);
            }
        });
    });
}

/**
 * Handle navigation to different sections
 * @param {string} section - Section name
 */
function handleNavigation(section) {
    switch (section) {
        case 'dashboard':
            switchView('dashboard');
            break;
        case 'peerEvaluation':
            switchView('peerEvaluation');
            break;
        case 'reports':
            switchView('reports');
            break;
        case 'profile':
            switchView('profile');
            break;
        case 'facultyResponse':
            switchView('facultyResponse');
            break;
        case 'peerManagement':
            switchView('peerManagement');
            break;
        default:
            break;
    }
}

/**
 * Switch between dashboard and reports views
 * @param {string} viewName - Name of the view to show ('dashboard' or 'reports')
 */
function switchView(viewName) {
    const dashboardView = document.getElementById('dashboardView');
    const peerEvaluationView = document.getElementById('peerEvaluationView');
    const reportsView = document.getElementById('reportsView');
    const profileView = document.getElementById('profileView');
    const facultyResponseView = document.getElementById('facultyResponseView');
    const peerManagementView = document.getElementById('peerManagementView');

    if (viewName === 'dashboard') {
        if (dashboardView) dashboardView.style.display = 'block';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (reportsView) reportsView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (facultyResponseView) facultyResponseView.style.display = 'none';
        if (peerManagementView) peerManagementView.style.display = 'none';
        closeAllPanels();
    } else if (viewName === 'peerEvaluation') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'block';
        if (reportsView) reportsView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (facultyResponseView) facultyResponseView.style.display = 'none';
        if (peerManagementView) peerManagementView.style.display = 'none';
        window.scrollTo(0, 0);
        closeAllPanels();
        loadDynamicSupervisorQuestionnaire();
    } else if (viewName === 'reports') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (reportsView) reportsView.style.display = 'block';
        if (profileView) profileView.style.display = 'none';
        if (facultyResponseView) facultyResponseView.style.display = 'none';
        if (peerManagementView) peerManagementView.style.display = 'none';
        // Scroll to top
        window.scrollTo(0, 0);
        closeAllPanels();
    } else if (viewName === 'profile') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (reportsView) reportsView.style.display = 'none';
        if (profileView) profileView.style.display = 'block';
        if (facultyResponseView) facultyResponseView.style.display = 'none';
        if (peerManagementView) peerManagementView.style.display = 'none';
        window.scrollTo(0, 0);
        closeAllPanels();
    } else if (viewName === 'facultyResponse') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (reportsView) reportsView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (facultyResponseView) facultyResponseView.style.display = 'block';
        if (peerManagementView) peerManagementView.style.display = 'none';
        window.scrollTo(0, 0);
        closeAllPanels();
    } else if (viewName === 'peerManagement') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (reportsView) reportsView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (facultyResponseView) facultyResponseView.style.display = 'none';
        if (peerManagementView) peerManagementView.style.display = 'block';
        window.scrollTo(0, 0);
        closeAllPanels();
    }
}

/**
 * Initialize reports view with charts
 */
function initializeReports() {
    // Wait a bit for the view to be visible
    setTimeout(() => {
        initializeStudentCharts();
        initializePeerCharts();
    }, 100);
}

function initializeStudentCharts() {
    const barCtx = document.getElementById('studentBarChart');
    const pieCtx = document.getElementById('studentPieChart');

    if (barCtx) {
        if (window.studentBarChartInstance) window.studentBarChartInstance.destroy();
        window.studentBarChartInstance = new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: ['Teaching Effectiveness', 'Classroom Management', 'Student Engagement', 'Communication Skills', 'Assessment Methods'],
                datasets: [{
                    label: 'Average Score',
                    data: [4.7, 4.5, 4.8, 4.6, 4.4],
                    backgroundColor: '#667eea',
                    borderColor: '#764ba2',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, max: 5, ticks: { stepSize: 1 } }
                },
                plugins: { legend: { display: true, position: 'bottom' } }
            }
        });
    }

    if (pieCtx) {
        if (window.studentPieChartInstance) window.studentPieChartInstance.destroy();
        window.studentPieChartInstance = new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: ['5 Stars', '4 Stars', '3 Stars', '2 Stars', '1 Star'],
                datasets: [{
                    data: [45, 30, 15, 7, 3],
                    backgroundColor: ['#10b981', '#34d399', '#fbbf24', '#f59e0b', '#ef4444'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true, position: 'bottom' } }
            }
        });
    }
}

function initializePeerCharts() {
    const barCtx = document.getElementById('peerBarChart');
    const pieCtx = document.getElementById('peerPieChart');

    if (barCtx) {
        if (window.peerBarChartInstance) window.peerBarChartInstance.destroy();
        window.peerBarChartInstance = new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: ['Collaboration', 'Resource Sharing', 'Feedback Quality', 'Professionalism'],
                datasets: [{
                    label: 'Peer Avg Score',
                    data: [4.6, 4.5, 4.7, 4.8],
                    backgroundColor: '#34d399',
                    borderColor: '#059669',
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                scales: {
                    y: { beginAtZero: true, max: 5, ticks: { stepSize: 1 } }
                },
                plugins: { legend: { display: true, position: 'bottom' } }
            }
        });
    }

    if (pieCtx) {
        if (window.peerPieChartInstance) window.peerPieChartInstance.destroy();
        window.peerPieChartInstance = new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: ['5 Stars', '4 Stars', '3 Stars', '2 Stars', '1 Star'],
                datasets: [{
                    data: [38, 34, 18, 7, 3],
                    backgroundColor: ['#10b981', '#34d399', '#fbbf24', '#f59e0b', '#ef4444'],
                    borderWidth: 2,
                    borderColor: '#ffffff'
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: { legend: { display: true, position: 'bottom' } }
            }
        });
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
 * Setup action buttons
 */
function setupActionButtons() {
    const actionButtons = document.querySelectorAll('.btn-action');

    actionButtons.forEach(button => {
        button.addEventListener('click', function () {
            const actionCard = this.closest('.action-card');
            const actionTitle = actionCard.querySelector('h3').textContent;
            handleActionButton(actionTitle);
        });
    });
}

/**
 * Handle action button click
 * @param {string} actionTitle - Title of the action
 */
function handleActionButton(actionTitle) {
    // Placeholder for future action functionality
    console.log(`Action clicked: ${actionTitle}`);

    if (actionTitle === 'View Reports') {
        openDeanReportPdf();
        return;
    } else if (actionTitle === 'Generate Summary') {
        alert('Generate Summary feature will be implemented soon!');
    }
}

// Open the dean report PDF in a new browser tab
function openDeanReportPdf() {
    const pdfPath = 'files/sample file.pdf';
    const pdfUrl = encodeURI(pdfPath);

    const newTab = window.open(pdfUrl, '_blank', 'noopener');
    if (!newTab) {
        alert('Please allow pop-ups to view the report PDF.');
    }
}

/**
 * Setup table actions
 */
function setupTableActions() {
    const viewAllBtn = document.querySelector('.btn-view-all');
    const viewDetailsLinks = document.querySelectorAll('.view-details-link');

    // View All button
    if (viewAllBtn) {
        viewAllBtn.addEventListener('click', function () {
            handleViewAll();
        });
    }

    // View Details links
    viewDetailsLinks.forEach(link => {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            const row = this.closest('tr');
            const subjectCell = row.querySelector('td:first-child');
            // Extract subject name (remove the evaluation progress text)
            const subjectText = subjectCell.textContent;
            const subject = subjectText.split(' student evaluated')[0].trim();
            handleViewDetails(subject);
        });
    });
}

/**
 * Handle view all submissions
 */
function handleViewAll() {
    // Placeholder for future functionality
    console.log('Viewing all submissions...');
    alert('View All feature will be implemented soon!');
}

/**
 * Handle view details action
 * @param {string} subject - Subject name
 */
function handleViewDetails(subject) {
    // Placeholder for future functionality
    console.log(`Viewing details for ${subject}`);
    alert(`View Details for ${subject}\n\nThis feature will be implemented soon!`);
}

/**
 * Update summary cards with dynamic data
 */
function updateSummaryCards() {
    const stats = getFacultySummaryTotals();

    // Update card numbers
    const evaluationsCard = document.querySelector('.summary-card.evaluations .card-number');
    const scoreCard = document.querySelector('.summary-card.score .score-text');
    const professorsCard = document.querySelector('.summary-card.professors .card-number');
    const responseCard = document.querySelector('.summary-card.response .card-number');

    if (evaluationsCard) evaluationsCard.textContent = `${stats.received}/${stats.required}`;
    if (scoreCard) scoreCard.textContent = `${stats.averageScore.toFixed(1)}/5.0`;
    if (professorsCard) professorsCard.textContent = String(deanProfessorCount);
    if (responseCard) responseCard.textContent = `${stats.responseRate}%`;
}

/**
 * Setup peer evaluation form functionality
 */
function setupPeerEvaluationForm() {
    const form = document.getElementById('peerEvaluationForm');
    const cancelBtn = document.getElementById('cancelPeerBtn');
    if (!form) return;

    // Force supervisor mode
    const evaluationTypeInput = document.getElementById('evaluationType');
    const targetLabel = document.getElementById('peerTargetLabel');
    const placeholder = document.getElementById('peerTargetPlaceholder');
    const endpoint = document.getElementById('peerEvaluationEndpoint');

    if (evaluationTypeInput) evaluationTypeInput.value = 'supervisor';
    if (form) form.dataset.evalType = 'supervisor';
    if (targetLabel) targetLabel.textContent = 'Select Employee';
    if (placeholder) placeholder.textContent = 'Choose an employee to evaluate';
    if (endpoint) endpoint.textContent = 'SQL Ready: connect to /api/dean/supervisor-evaluations/submit (POST)';

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        handlePeerEvaluation();
    });

    if (cancelBtn) {
        cancelBtn.addEventListener('click', function () {
            form.reset();
            switchView('dashboard');
            updateNavigation('dashboard');
        });
    }
}

function setupEvaluationToggle(form) {
    // Peer toggle removed; supervisor mode enforced in setupPeerEvaluationForm
}

/**
 * Placeholder peer evaluation handler (SQL-ready)
 */
function handlePeerEvaluation() {
    const form = document.getElementById('peerEvaluationForm');
    if (!form) return;

    // ── Evaluation period gate ──
    if (!SharedData.isEvalPeriodOpen('supervisor-professor')) {
        const dates = SharedData.getEvalPeriodDates('supervisor-professor');
        let msg = 'The Supervisor to Professor evaluation period is not currently open.';
        if (dates.start && dates.end) {
            msg += '\nEvaluation period: ' + dates.start + ' to ' + dates.end + '.';
        } else {
            msg += '\nNo evaluation period has been set by the administrator yet.';
        }
        showFormMessage(form, msg, 'error');
        return;
    }

    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    const formData = new FormData(form);
    const evaluationType = 'supervisor';

    // Fetch data definitions to separate ratings from qualitative
    const questionnaires = SharedData.getQuestionnaires() || {};
    const semester = SharedData.getCurrentSemester() || '';
    let dataToUse = null;
    if (semester && questionnaires[semester]) {
        dataToUse = questionnaires[semester];
    } else {
        const semesters = Object.keys(questionnaires).sort().reverse();
        if (semesters.length > 0) dataToUse = questionnaires[semesters[0]];
    }
    const supervisorData = (dataToUse && dataToUse['supervisor-to-professor']) || { sections: [], questions: [], header: {} };
    const allQuestions = supervisorData.questions || [];

    const ratingsGroup = {};
    const qualitativeGroup = {};

    for (let [key, value] of formData.entries()) {
        if (key === 'evaluationType' || key === 'peerProfessor' || key === 'peerComments') continue;

        // Find the question definition
        let questionDef = allQuestions.find(q => String(q.id) === key);

        if (questionDef && questionDef.type === 'qualitative') {
            qualitativeGroup[key] = value;
        } else {
            ratingsGroup[key] = value;
        }
    }

    const session = SharedData.getSession() || {};
    const payload = {
        evaluatorId: session.username || '',
        evaluatorName: session.fullName || 'Anonymous Dean',
        evaluatorRole: 'dean',
        evaluationType: 'supervisor',
        targetId: formData.get('peerProfessor'),
        ratings: ratingsGroup,
        qualitative: qualitativeGroup,
        comments: formData.get('peerComments') || '',
        submittedAt: new Date().toISOString()
    };

    // Save via centralized API
    SharedData.addEvaluation(payload);

    // Add to activity log
    SharedData.addActivityLogEntry({
        type: 'evaluation_submitted',
        title: 'Supervisor Evaluation Submitted',
        user: payload.evaluatorName,
        role: 'dean',
        date: new Date().toISOString()
    });

    console.log('Supervisor evaluation submitted to local database:', payload);
    showFormMessage(
        form,
        'Supervisor evaluation submitted successfully to local database.',
        'success'
    );

    // Auto redirect after briefly showing the success state
    setTimeout(() => {
        form.reset();
        const evaluationTypeInput = document.getElementById('evaluationType');
        if (evaluationTypeInput) evaluationTypeInput.value = evaluationType;
        switchView('dashboard');

        // Find and update the active nav link for dashboard
        const navLinks = document.querySelectorAll('.nav-link:not(.logout)');
        navLinks.forEach(l => l.classList.remove('active'));
        const dashboardLink = document.querySelector('.nav-link[data-view="dashboard"]');
        if (dashboardLink) dashboardLink.classList.add('active');
    }, 1500);
}

/**
 * Load Dynamic Supervisor Questionnaire from Shared Data
 */
function loadDynamicSupervisorQuestionnaire() {
    const container = document.getElementById('dynamic-supervisor-questions-container');
    if (!container) return;

    const questionnaires = SharedData.getQuestionnaires() || {};
    const semester = SharedData.getCurrentSemester() || '';

    console.log('[Dean] loadDynamicSupervisorQuestionnaire — semester:', JSON.stringify(semester), '| available keys:', Object.keys(questionnaires));

    let dataToUse = null;
    if (semester && questionnaires[semester]) {
        dataToUse = questionnaires[semester];
    } else {
        // Fallback to latest available semester
        const semesters = Object.keys(questionnaires).sort().reverse();
        if (semesters.length > 0) {
            dataToUse = questionnaires[semesters[0]];
            console.log('[Dean] Semester key not found, falling back to:', semesters[0]);
        }
    }

    // Fallback if structure is missing
    const supervisorData = (dataToUse && dataToUse['supervisor-to-professor']) || { sections: [], questions: [], header: {} };

    // Update Form Header
    const dynamicTitle = document.getElementById('dynamic-form-title');
    if (dynamicTitle) {
        dynamicTitle.textContent = supervisorData.header && supervisorData.header.title ? supervisorData.header.title : 'Supervisor Evaluation Form';
    }

    const dynamicDesc = document.getElementById('dynamic-form-description');
    if (dynamicDesc) {
        dynamicDesc.textContent = supervisorData.header && supervisorData.header.description ? supervisorData.header.description : 'Evaluate an employee based on workplace conduct, collaboration, and professionalism.';
    }

    if (!supervisorData || !supervisorData.sections || supervisorData.sections.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="text-align: center; padding: 3rem 1rem; background: #fff; border-radius: 12px; border: 1px solid #e2e8f0;">
                <i class="fas fa-clipboard-list" style="font-size: 3rem; color: #94a3b8; margin-bottom: 1rem;"></i>
                <h3 style="color: #475569; margin-bottom: 0.5rem;">No Evaluation Form Configured</h3>
                <p style="color: #64748b; font-size: 0.95rem;">Please configure the Supervisor-to-Professor evaluation form in the Admin Panel.</p>
            </div>
        `;
        return;
    }

    let html = '';

    supervisorData.sections.forEach(section => {
        const sectionHasContent = supervisorData.questions && supervisorData.questions.some(q => q.sectionId === section.id);

        if (!sectionHasContent) return;

        html += `
            <div class="form-section">
                <h3 class="section-title purple">${escapeHTML(section.title)}</h3>
                ${section.description ? `<p style="color: #64748b; margin-bottom: 1rem; font-size: 0.9rem;">${escapeHTML(section.description)}</p>` : ''}
        `;

        const sectionQuestions = supervisorData.questions.filter(q => q.sectionId === section.id);

        sectionQuestions.forEach((question, index) => {
            html += renderSupervisorQuestionHTML(question, index);
        });

        html += `</div>`;
    });
    container.innerHTML = html;
}

function renderSupervisorQuestionHTML(question, index) {
    const isRequired = question.required ? 'required' : '';
    const qid = String(question.id);

    if (question.type === 'qualitative') {
        return `
            <div class="question-group" style="margin-bottom: 24px;">
                <label class="question-label" for="q-${qid}">${escapeHTML(question.text)} ${question.required ? '<span style="color:var(--danger)">*</span>' : ''}</label>
                <div class="form-group" style="margin-top: 8px;">
                    <textarea id="q-${qid}" name="${qid}" class="form-textarea" rows="4" placeholder="Type your response here..." ${isRequired}></textarea>
                </div>
            </div>
        `;
    }

    // Default to rating scale
    return `
        <div class="question-group">
            <label class="question-label">${escapeHTML(question.text)} ${question.required ? '<span style="color:var(--danger)">*</span>' : ''}</label>
            <div class="rating-scale">
                <input type="radio" name="${qid}" id="q-${qid}-1" value="1" ${isRequired}>
                <label for="q-${qid}-1" class="rating-option">1</label>
                <input type="radio" name="${qid}" id="q-${qid}-2" value="2" ${isRequired}>
                <label for="q-${qid}-2" class="rating-option">2</label>
                <input type="radio" name="${qid}" id="q-${qid}-3" value="3" ${isRequired}>
                <label for="q-${qid}-3" class="rating-option">3</label>
                <input type="radio" name="${qid}" id="q-${qid}-4" value="4" ${isRequired}>
                <label for="q-${qid}-4" class="rating-option">4</label>
                <input type="radio" name="${qid}" id="q-${qid}-5" value="5" ${isRequired}>
                <label for="q-${qid}-5" class="rating-option">5</label>
            </div>
            <p class="rating-legend">5 = Excellent, 1 = Poor</p>
        </div>
    `;
}

function escapeHTML(str) {
    if (!str) return '';
    return str.replace(/[&<>'"]/g,
        tag => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            "'": '&#39;',
            '"': '&quot;'
        }[tag] || tag)
    );
}

/**
 * Show a form message
 */
function showFormMessage(form, message, type) {
    const existing = form.querySelector('.form-message');
    if (existing) existing.remove();

    const messageDiv = document.createElement('div');
    messageDiv.className = `form-message ${type}`;
    messageDiv.textContent = message;
    messageDiv.style.cssText = `
        background-color: ${type === 'error' ? '#fee2e2' : '#d1fae5'};
        color: ${type === 'error' ? '#991b1b' : '#065f46'};
        padding: 14px 18px;
        border-radius: 12px;
        margin-bottom: 20px;
        text-align: center;
        font-weight: 600;
        border: 1px solid ${type === 'error' ? '#ef4444' : '#10b981'};
        animation: fadeIn 0.3s ease;
    `;

    form.insertBefore(messageDiv, form.firstChild);

    setTimeout(() => {
        if (messageDiv.parentNode) {
            messageDiv.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => messageDiv.remove(), 300);
        }
    }, 4000);
}

/**
 * Load faculty summary data (SQL-ready placeholder)
 */
function loadFacultySummary(selection = {}) {
    const session = getUserSession();
    const deanId = session ? session.username : '';
    const ay = selection.ay || '2025-2026';
    const sem = selection.sem || '2';
    const evaluationType = selection.evaluationType || 'student';

    fetchFacultySummaryFromSql({
        deanId,
        ay,
        sem,
        evaluationType
    }).then(summary => {
        renderCriteriaSummary(summary.criteriaAverages);
        renderBreakdownTable(summary.subjects, evaluationType);
        renderEvaluationCount(summary.subjects);
        if (evaluationType === 'student') {
            updateSubmissionProgress(summary.subjects);
        }
        updateSummaryCards();
    }).catch(() => {
        renderCriteriaSummary([]);
        renderBreakdownTable([], evaluationType);
        updateSummaryCards();
    });
}

/**
 * Setup semester filter for faculty summary
 * @returns {boolean} - True if filter is present
 */
function setupSemesterFilter() {
    const filter = document.getElementById('semesterFilter');
    const evalFilter = document.getElementById('evaluationTypeFilter');
    if (!filter) return false;

    const applySelection = () => {
        const selectedOption = filter.options[filter.selectedIndex];
        const value = filter.value || '';
        const [ay, sem] = value.split('|');
        const label = selectedOption ? selectedOption.textContent.trim() : '';
        const evalValue = evalFilter ? evalFilter.value : 'student';
        const evalLabel = evalFilter
            ? (evalFilter.options[evalFilter.selectedIndex]?.textContent || '').trim()
            : 'Student Evaluation';

        updateSemesterLabels(label, evalLabel);
        loadFacultySummary({ ay, sem, evaluationType: evalValue });
    };

    filter.addEventListener('change', applySelection);
    if (evalFilter) {
        evalFilter.addEventListener('change', applySelection);
    }
    applySelection();
    return true;
}

/**
 * Update semester labels in the UI
 */
function updateSemesterLabels(label, evaluationLabel) {
    const display = document.getElementById('selectedSemester');
    const summary = document.getElementById('summarySemester');
    const evalDisplay = document.getElementById('selectedEvalType');

    if (display) {
        display.textContent = label || 'Selected semester';
    }

    if (summary) {
        summary.textContent = label || 'Selected semester';
    }

    if (evalDisplay) {
        evalDisplay.textContent = evaluationLabel || 'Student Evaluation';
    }
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
    placeholder.textContent = buildInitials(fullName) || 'DP';

    const storedPhoto = SharedData.getProfilePhoto('dean');
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
            SharedData.setProfilePhoto('dean', reader.result);
        };
        reader.readAsDataURL(file);
    });
}

function getProfileFullName() {
    const items = document.querySelectorAll('#profileView .profile-item');
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

function loadProfessorCount() {
    const session = getUserSession();
    const deanId = session ? session.username : '';
    const assignedInstitutes = getDeanAssignedInstitutes(session);

    return fetchDeanProfessorResultsFromSql({
        deanId,
        assignedInstitutes,
        ay: '2025-2026',
        sem: '2'
    }).then(results => {
        deanProfessorCount = Array.isArray(results) ? results.length : 0;
        updateSummaryCards();
    }).catch(() => {
        deanProfessorCount = 0;
        updateSummaryCards();
    });
}

/**
 * SQL-ready fetch for faculty summary data
 */
function fetchFacultySummaryFromSql(query) {
    // Example SQL-backed API request:
    // return fetch('/api/dean/evaluations/summary', {
    //     method: 'POST',
    //     headers: { 'Content-Type': 'application/json' },
    //     body: JSON.stringify(query)
    // }).then(res => res.json());

    // Mock can be scoped by evaluationType when integrating
    const evalType = query && query.evaluationType ? query.evaluationType : 'student';

    const mockSummary = {
        criteriaAverages: [
            { name: 'Teaching Effectiveness', average: 4.7 },
            { name: 'Classroom Management', average: 4.5 },
            { name: 'Student Engagement', average: 4.8 },
            { name: 'Communication Skills', average: 4.6 },
            { name: 'Assessment Methods', average: 4.4 }
        ],
        subjects: evalType === 'professor'
            ? [
                { employeeId: 'FAC-20451', avgRating: 4.7, required: 12, received: 9 },
                { employeeId: 'FAC-21014', avgRating: 4.5, required: 10, received: 7 },
                { employeeId: 'FAC-19881', avgRating: 4.6, required: 9, received: 8 }
            ]
            : [
                {
                    subject: 'IT 307 - Web Systems and Technologies 2',
                    section: 'BSIT 3A',
                    required: 40,
                    received: 18,
                    avgRating: 4.6
                },
                {
                    subject: 'IT 303 - Event-Driven Programming',
                    section: 'BSIT 3A',
                    required: 35,
                    received: 17,
                    avgRating: 4.8
                },
                {
                    subject: 'IT 305 - Integrative Programming',
                    section: 'BSIT 3A',
                    required: 25,
                    received: 10,
                    avgRating: 4.5
                }
            ]
    };

    console.log('Ready for SQL integration: /api/dean/evaluations/summary', {
        ...query,
        evaluationType: evalType
    });

    return Promise.resolve(mockSummary);
}

/**
 * Render average rating per criteria
 */
function renderCriteriaSummary(criteria) {
    const list = document.getElementById('criteriaList');
    if (!list) return;

    if (!criteria.length) {
        list.innerHTML = '<li><span>No data available</span><strong>-</strong></li>';
        return;
    }

    list.innerHTML = criteria.map(item => `
        <li><span>${item.name}</span><strong>${item.average.toFixed(1)}</strong></li>
    `).join('');
}

/**
 * Render breakdown table per subject/section
 */
function renderBreakdownTable(subjects, evaluationType = 'student') {
    const table = document.getElementById('facultyBreakdownTable');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    const thead = table.querySelector('thead');
    if (!tbody || !thead) return;

    // Build dynamic headers based on evaluation type
    if (evaluationType === 'professor') {
        thead.innerHTML = `
            <tr>
                <th>Employee Number</th>
                <th>Avg Rating</th>
                <th>Comments</th>
            </tr>
        `;
    } else {
        thead.innerHTML = `
            <tr>
                <th>Subject</th>
                <th>Section</th>
                <th>Evaluations Received</th>
                <th>Response Rate</th>
                <th>Avg Rating</th>
                <th>Comments</th>
            </tr>
        `;
    }

    const colCount = evaluationType === 'professor' ? 3 : 6;

    if (!subjects.length) {
        tbody.innerHTML = `<tr><td colspan="${colCount}">No evaluation data available.</td></tr>`;
        return;
    }

    tbody.innerHTML = subjects.map(item => {
        const responseRate = item.required ? Math.round((item.received / item.required) * 100) : 0;

        if (evaluationType === 'professor') {
            return `
                <tr data-required="${item.required || 0}" data-received="${item.received || 0}" data-avg="${item.avgRating}">
                    <td>${item.employeeId}</td>
                    <td>${item.avgRating.toFixed(1)}</td>
                    <td><button type="button" class="btn-submit faculty-comments-btn js-dean-comments" data-eval-type="professor" data-subject="${item.employeeId}" data-section="">View</button></td>
                </tr>
            `;
        }

        return `
            <tr data-required="${item.required}" data-received="${item.received}" data-avg="${item.avgRating}">
                <td>${item.subject}</td>
                <td>${item.section}</td>
                <td><span class="count-pill">${item.received}/${item.required}</span></td>
                <td>${responseRate}%</td>
                <td>${item.avgRating.toFixed(1)}</td>
                <td><button type="button" class="btn-submit faculty-comments-btn js-dean-comments" data-eval-type="student" data-subject="${item.subject}" data-section="${item.section}">View</button></td>
            </tr>
        `;
    }).join('');
}

/**
 * Setup section feedback summary per subject/section
 */
function setupDeanSubjectComments() {
    const table = document.getElementById('facultyBreakdownTable');
    const panel = document.getElementById('deanSubjectCommentsPanel');
    const title = document.getElementById('deanSubjectCommentsTitle');
    const meta = document.getElementById('deanSubjectCommentsMeta');
    const list = document.getElementById('deanSubjectCommentsList');
    const closeBtn = document.getElementById('deanSubjectCommentsClose');

    if (!table || !panel || !title || !meta || !list || !closeBtn) return;

    closeBtn.addEventListener('click', function () {
        panel.classList.remove('active');
    });

    table.addEventListener('click', function (e) {
        const target = e.target;
        if (!target || !target.classList || !target.classList.contains('js-dean-comments')) return;

        const subject = target.getAttribute('data-subject');
        const section = target.getAttribute('data-section');
        const evalType = target.getAttribute('data-eval-type') || 'student';
        if (!subject) return;

        title.textContent = evalType === 'professor' ? 'Professor Feedback Summary' : 'Section Feedback Summary';
        meta.textContent = section ? `${subject} | ${section}` : subject;

        fetchSectionSummaryFromSql({
            subject,
            section,
            ay: '2025-2026',
            sem: '2',
            evaluationType: evalType
        }).then(summaries => {
            if (!summaries.length) {
                list.innerHTML = '<li class="faculty-comments-empty">No summaries available.</li>';
            } else {
                const firstOnly = summaries.slice(0, 1); // show just one comment
                list.innerHTML = firstOnly.map(item => {
                    const cleanText = (item.text || '').replace(/^Summary:\s*/i, '');
                    return '<li>' +
                        '<div class="faculty-comment-text">' + cleanText + '</div>' +
                        '</li>';
                }).join('');
            }
            panel.classList.add('active');
        }).catch(() => {
            list.innerHTML = '<li class="faculty-comments-empty">Unable to load summaries.</li>';
            panel.classList.add('active');
        });
    });
}

/**
 * SQL-ready placeholder for section feedback summaries
 */
function fetchSectionSummaryFromSql(query) {
    const evalType = query && query.evaluationType ? query.evaluationType : 'student';

    const studentMock = {
        'IT 307 - Web Systems and Technologies 2|BSIT 3A': [
            { text: 'Summary: Strong clarity on core topics and helpful practical examples.' },
            { text: 'Summary: Students want more hands-on activities for deeper practice.' }
        ],
        'IT 303 - Event-Driven Programming|BSIT 3A': [
            { text: 'Summary: Lab exercises are effective and guidance is appreciated.' }
        ],
        'IT 305 - Integrative Programming|BSIT 3A': [
            { text: 'Summary: Requests for more feedback on coding style and structure.' }
        ]
    };

    const professorMock = {
        'FAC-20451': [
            { text: 'Summary: Peer feedback highlights strong leadership and support.' },
            { text: 'Summary: Consider scheduling more peer coaching sessions.' }
        ],
        'FAC-21014': [
            { text: 'Summary: Excellent collaboration within the department.' }
        ],
        'FAC-19881': [
            { text: 'Summary: Recognized for mentoring junior faculty effectively.' }
        ]
    };

    console.log('Ready for SQL integration: /api/dean/subjects/summary', {
        ...query,
        evaluationType: evalType
    });

    if (evalType === 'professor') {
        return Promise.resolve(professorMock[query.subject || ''] || []);
    }

    const key = `${query.subject || ''}|${query.section || ''}`;
    return Promise.resolve(studentMock[key] || []);
}

/**
 * Render evaluations received count
 */
function renderEvaluationCount(subjects) {
    const countEl = document.getElementById('evaluationCount');
    if (!countEl) return;
    const totals = computeTotals(subjects);
    countEl.textContent = `${totals.received}/${totals.required}`;
}

/**
 * Update submission table progress text based on subject data
 */
function updateSubmissionProgress(subjects) {
    const rows = document.querySelectorAll('.submissions-table tbody tr');
    if (!rows.length || !subjects.length) return;

    rows.forEach(row => {
        const subjectCell = row.querySelector('td');
        const progressEl = row.querySelector('.evaluation-progress');
        if (!subjectCell || !progressEl) return;

        const subjectText = subjectCell.childNodes[0].textContent.trim();
        const match = subjects.find(item => item.subject === subjectText);
        if (!match) return;

        progressEl.textContent = `${match.received}/${match.required} evaluations received`;
    });
}

/**
 * Compute totals for summary cards
 */
function computeTotals(subjects) {
    return subjects.reduce((acc, item) => {
        acc.required += item.required;
        acc.received += item.received;
        return acc;
    }, { required: 0, received: 0 });
}

/**
 * Get totals for summary cards (uses mock data if needed)
 */
function getFacultySummaryTotals() {
    const table = document.getElementById('facultyBreakdownTable');
    if (!table) {
        return { required: 0, received: 0, responseRate: 0, averageScore: 0 };
    }

    const rows = Array.from(table.querySelectorAll('tbody tr'));
    if (!rows.length) {
        return { required: 0, received: 0, responseRate: 0, averageScore: 0 };
    }

    let requiredTotal = 0;
    let receivedTotal = 0;
    let ratingTotal = 0;
    let ratingCount = 0;

    rows.forEach(row => {
        const cells = row.querySelectorAll('td');
        if (cells.length < 5) return;

        const countEl = row.querySelector('.count-pill');
        let required = 0;
        let received = 0;
        if (countEl) {
            const parts = countEl.textContent.split('/');
            received = parseInt(parts[0], 10) || 0;
            required = parseInt(parts[1], 10) || 0;
        }

        const avgRating = parseFloat(cells[4].textContent) || 0;
        requiredTotal += required;
        receivedTotal += received;
        if (avgRating) {
            ratingTotal += avgRating;
            ratingCount += 1;
        }
    });

    const responseRate = requiredTotal ? Math.round((receivedTotal / requiredTotal) * 100) : 0;
    const averageScore = ratingCount ? ratingTotal / ratingCount : 0;

    return {
        required: requiredTotal,
        received: receivedTotal,
        responseRate,
        averageScore
    };
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

    console.log('Ready for SQL integration: /api/dean/change-email', payload);
    alert('Email update request ready for SQL connection.');

    const form = document.getElementById('changeEmailForm');
    if (form) form.reset();
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

    console.log('Ready for SQL integration: /api/dean/change-password', payload);
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
        if (link.getAttribute('data-view') === viewName) {
            link.classList.add('active');
        }
    });
}

/**
 * Apply reports blackout placeholder (disabled by default)
 */
function applyReportBlackout() {
    const blackoutConfig = {
        enabled: false,
        endDate: '2026-02-10'
    };

    const blackoutEl = document.getElementById('reportsBlackout');
    const contentEl = document.getElementById('reportsContent');
    const unlockDateEl = document.getElementById('reportUnlockDate');

    if (!blackoutEl || !contentEl || !unlockDateEl) return;
    unlockDateEl.textContent = formatDisplayDate(blackoutConfig.endDate);

    if (!blackoutConfig.enabled) {
        blackoutEl.style.display = 'none';
        contentEl.style.display = 'block';
        return;
    }

    const today = new Date();
    const endDate = new Date(`${blackoutConfig.endDate}T23:59:59`);

    if (today <= endDate) {
        blackoutEl.style.display = 'block';
        contentEl.style.display = 'none';
    } else {
        blackoutEl.style.display = 'none';
        contentEl.style.display = 'block';
    }
}

function formatDisplayDate(dateString) {
    const date = new Date(dateString);
    if (Number.isNaN(date.getTime())) return dateString;
    return date.toLocaleDateString('en-US', {
        year: 'numeric',
        month: 'short',
        day: 'numeric'
    });
}

/**
 * Setup dean-only professor evaluation visibility by institute
 */
function setupDeanEvaluationResults() {
    const instituteFilter = document.getElementById('deanInstituteFilter');
    const table = document.getElementById('deanProfessorResultsTable');
    if (!instituteFilter || !table) return;
    const session = getUserSession();
    const deanId = session ? session.username : '';
    const assignedInstitutes = getDeanAssignedInstitutes(session);
    fetchDeanProfessorResultsFromSql({
        deanId,
        assignedInstitutes,
        ay: '2025-2026',
        sem: '2'
    }).then(results => {
        const institutes = Array.from(new Set(results.map(item => item.institute))).sort();
        instituteFilter.innerHTML = '<option value="all">All assigned institutes</option>' + institutes.map(institute =>
            '<option value="' + institute + '">' + institute + '</option>'
        ).join('');
        renderDeanProfessorResults(results, instituteFilter.value);
        instituteFilter.addEventListener('change', function () {
            renderDeanProfessorResults(results, this.value);
        });
    }).catch(() => {
        const tbody = table.querySelector('tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8">No professor evaluation results available.</td></tr>';
    });
}

/**
 * Resolve assigned institute scope for the current dean
 */
function getDeanAssignedInstitutes(sessionOrUsername) {
    if (
        sessionOrUsername &&
        typeof sessionOrUsername === 'object' &&
        Array.isArray(sessionOrUsername.assignedInstitutes) &&
        sessionOrUsername.assignedInstitutes.length
    ) {
        return sessionOrUsername.assignedInstitutes;
    }

    const username = typeof sessionOrUsername === 'string'
        ? sessionOrUsername
        : (sessionOrUsername && sessionOrUsername.username) ? sessionOrUsername.username : '';
    const normalized = String(username || '').toLowerCase();

    const explicitMap = {
        dean: ['ICS'],
        daen: ['ENGI'],
        deanics: ['ICS'],
        deancs: ['ICS'],
        deaneng: ['ENGI'],
        deanie: ['ENGI']
    };

    if (explicitMap[normalized]) {
        return explicitMap[normalized];
    }

    if (normalized.includes('ics') || normalized.includes('computer') || normalized.includes('cs')) {
        return ['ICS'];
    }

    if (normalized.includes('engineering') || normalized.includes('eng') || normalized.includes('ie')) {
        return ['ENGI'];
    }

    return [];

    return [];
}

/**
 * SQL-ready fetch for dean-level professor results
 */
function fetchDeanProfessorResultsFromSql(query) {
    // Example SQL-backed API request:
    // return fetch('/api/dean/professors/evaluations/results', {
    //     method: 'POST',
    //     headers: { 'Content-Type': 'application/json' },
    //     body: JSON.stringify(query)
    // }).then(res => res.json());
    // Scope enforcement: each dean can only view assigned institute records.
    const assignedInstitutes = Array.isArray(query && query.assignedInstitutes)
        ? query.assignedInstitutes
        : getDeanAssignedInstitutes(query ? query.deanId : '');

    let scopedResults = [];
    if (typeof SharedData !== 'undefined' && SharedData.getUsers) {
        const allUsers = SharedData.getUsers();
        scopedResults = allUsers
            .filter(u => u.role === 'professor' && u.department && assignedInstitutes.includes(u.department.toUpperCase()))
            .map(prof => ({
                professorId: prof.employeeId || prof.id || 'N/A',
                professorName: prof.name || 'Unknown',
                institute: (prof.institute || prof.department || '').toUpperCase(),
                employmentType: prof.employmentType || 'Regular',
                position: prof.position || 'Instructor',
                required: prof.totalStudents || 100,
                received: prof.evaluatedCount || 0,
                avgScore: prof.averageRating || 0,
                lastUpdated: new Date().toISOString().split('T')[0],
                status: prof.status || 'Active'
            }));
    }

    return Promise.resolve(scopedResults);
}
/**
 * Render dean view table and metrics
 */
function renderDeanProfessorResults(results, selectedInstitute) {
    const table = document.getElementById('deanProfessorResultsTable');
    const professorCountEl = document.getElementById('deanProfessorCount');
    const averageScoreEl = document.getElementById('deanAverageScore');
    const responseRateEl = document.getElementById('deanResponseRate');
    const scopeBadge = document.getElementById('deanScopeBadge');
    if (!table) return;
    const tbody = table.querySelector('tbody');
    if (!tbody) return;
    const filtered = selectedInstitute && selectedInstitute !== 'all'
        ? results.filter(item => item.institute === selectedInstitute)
        : results.slice();
    if (!filtered.length) {
        tbody.innerHTML = '<tr><td colspan="8">No professor evaluation results in this scope.</td></tr>';
        if (professorCountEl) professorCountEl.textContent = '0';
        if (averageScoreEl) averageScoreEl.textContent = '0.0/5.0';
        if (responseRateEl) responseRateEl.textContent = '0%';
        if (scopeBadge) scopeBadge.textContent = 'Scope: ' + (selectedInstitute === 'all' ? 'All assigned institutes' : selectedInstitute);
        return;
    }
    tbody.innerHTML = filtered.map(item => {
        const rowResponseRate = item.required ? Math.round((item.received / item.required) * 100) : 0;
        const normalizedStatus = String(item.status || '').toLowerCase();
        const statusText = (normalizedStatus === 'inactive' || normalizedStatus === 'needs attention')
            ? 'Inactive'
            : 'Active';
        const statusClass = (statusText === 'Inactive')
            ? 'inactive'
            : 'active';
        return '<tr>' +
            '<td>' + item.professorId + '</td>' +
            '<td>' + item.professorName + '</td>' +
            '<td>' + item.institute + '</td>' +
            '<td><span class=\"count-pill\">' + item.received + '/' + item.required + '</span></td>' +
            '<td>' + rowResponseRate + '%</td>' +
            '<td>' + item.avgScore.toFixed(1) + '</td>' +
            '<td>' + formatDisplayDate(item.lastUpdated) + '</td>' +
            '<td><span class=\"dean-status-pill ' + statusClass + '\">' + statusText + '</span></td>' +
            '</tr>';
    }).join('');
    const totalRequired = filtered.reduce((sum, item) => sum + item.required, 0);
    const totalReceived = filtered.reduce((sum, item) => sum + item.received, 0);
    const averageScore = filtered.reduce((sum, item) => sum + item.avgScore, 0) / filtered.length;
    const responseRate = totalRequired ? Math.round((totalReceived / totalRequired) * 100) : 0;
    if (professorCountEl) professorCountEl.textContent = String(filtered.length);
    if (averageScoreEl) averageScoreEl.textContent = averageScore.toFixed(1) + '/5.0';
    if (responseRateEl) responseRateEl.textContent = responseRate + '%';
    if (scopeBadge) scopeBadge.textContent = 'Scope: ' + (selectedInstitute === 'all' ? 'All assigned institutes' : selectedInstitute);
}

/**
 * Setup faculty response rate view search and table rendering
 */
function setupFacultyResponseView() {
    const searchInput = document.getElementById('facultySearchInput');
    const searchBtn = document.getElementById('facultySearchBtn');
    const resetBtn = document.getElementById('facultyResetBtn');
    const studentResultsBtn = document.getElementById('studentResultsBtn');
    const peerResultsBtn = document.getElementById('peerResultsBtn');
    const supervisorResultsBtn = document.getElementById('supervisorResultsBtn');
    const resultEl = document.getElementById('facultySearchResult');
    const table = document.getElementById('facultyResponseCountsTable');
    const commentsPanel = document.getElementById('facultyCommentsPanel');
    const commentsTitle = document.getElementById('facultyCommentsTitle');
    const commentsMeta = document.getElementById('facultyCommentsMeta');
    const commentsList = document.getElementById('facultyCommentsList');
    const commentsClose = document.getElementById('facultyCommentsClose');

    if (!searchInput || !searchBtn || !resetBtn || !resultEl || !table || !commentsPanel || !commentsTitle || !commentsMeta || !commentsList || !commentsClose) return;

    const session = getUserSession();
    const deanId = session ? session.username : '';
    const assignedInstitutes = getDeanAssignedInstitutes(session);
    let sourceData = [];
    let currentView = 'student';

    function fetchResults(view) {
        const fetcher = view === 'peer'
            ? fetchDeanPeerEvaluationResultsFromSql
            : view === 'supervisor'
                ? fetchDeanSupervisorEvaluationResultsFromSql
                : fetchDeanProfessorResultsFromSql;

        return fetcher({
            deanId,
            assignedInstitutes,
            ay: '2025-2026',
            sem: '2'
        });
    }

    function applyFilter(items) {
        const keyword = searchInput.value.trim().toLowerCase();
        if (!keyword) {
            return { filtered: items, keyword: '' };
        }

        const filtered = items.filter(item => {
            const facultyName = (item.professorName || '').toLowerCase();
            const employeeId = (item.professorId || '').toLowerCase();
            return facultyName.includes(keyword) || employeeId.includes(keyword);
        });

        return { filtered, keyword };
    }

    function setToggleState(button, isActive) {
        if (!button) return;
        if (isActive) {
            button.classList.add('btn-submit', 'active');
            button.classList.remove('btn-cancel');
            button.setAttribute('aria-pressed', 'true');
        } else {
            button.classList.add('btn-cancel');
            button.classList.remove('btn-submit', 'active');
            button.setAttribute('aria-pressed', 'false');
        }
    }

    function setResultsView(view) {
        currentView = view === 'peer' ? 'peer' : view === 'supervisor' ? 'supervisor' : 'student';
        setToggleState(studentResultsBtn, currentView === 'student');
        setToggleState(peerResultsBtn, currentView === 'peer');
        setToggleState(supervisorResultsBtn, currentView === 'supervisor');

        if (commentsPanel) commentsPanel.classList.remove('active');
        if (commentsList) commentsList.innerHTML = '<li class="faculty-comments-empty">No comments loaded yet.</li>';

        fetchResults(currentView).then(results => {
            sourceData = Array.isArray(results) ? results : [];
            const { filtered, keyword } = applyFilter(sourceData);
            renderFacultyResponseTable(filtered);
            attachFacultyCommentButtons(filtered);
            updateFacultySearchResult(filtered.length, sourceData.length, keyword, currentView);
        }).catch(() => {
            renderFacultyResponseTable([]);
            attachFacultyCommentButtons([]);
            updateFacultySearchResult(0, 0, '', currentView);
        });
    }

    function runSearch() {
        const { filtered, keyword } = applyFilter(sourceData);
        renderFacultyResponseTable(filtered);
        attachFacultyCommentButtons(filtered);
        updateFacultySearchResult(filtered.length, sourceData.length, keyword, currentView);
    }

    searchBtn.addEventListener('click', runSearch);
    resetBtn.addEventListener('click', function () {
        searchInput.value = '';
        renderFacultyResponseTable(sourceData);
        attachFacultyCommentButtons(sourceData);
        updateFacultySearchResult(sourceData.length, sourceData.length, '', currentView);
    });

    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            runSearch();
        }
    });

    commentsClose.addEventListener('click', function () {
        commentsPanel.classList.remove('active');
    });

    if (studentResultsBtn) {
        studentResultsBtn.addEventListener('click', function () {
            setResultsView('student');
        });
    }

    if (peerResultsBtn) {
        peerResultsBtn.addEventListener('click', function () {
            setResultsView('peer');
        });
    }

    if (supervisorResultsBtn) {
        supervisorResultsBtn.addEventListener('click', function () {
            setResultsView('supervisor');
        });
    }

    function attachFacultyCommentButtons(items) {
        const buttons = table.querySelectorAll('.js-view-comments');
        buttons.forEach(button => {
            button.addEventListener('click', function () {
                const professorId = this.getAttribute('data-professor-id');
                const professor = items.find(item => item.professorId === professorId);
                if (!professor) return;

                commentsTitle.textContent = 'Comments for ' + professor.professorName;
                commentsMeta.textContent = professor.professorId + ' | ' + professor.institute;

                fetchFacultyCommentsFromSql({
                    professorId: professor.professorId,
                    deanId,
                    ay: '2025-2026',
                    sem: '2',
                    source: currentView
                }).then(comments => {
                    if (!comments.length) {
                        commentsList.innerHTML = '<li class="faculty-comments-empty">No comments available.</li>';
                    } else {
                        commentsList.innerHTML = comments.map(comment =>
                            '<li>' +
                            '<div class="faculty-comment-text">"' + comment.text + '"</div>' +
                            '<div class="faculty-comment-meta">' + comment.source + ' • ' + formatDisplayDate(comment.date) + '</div>' +
                            '</li>'
                        ).join('');
                    }
                    commentsPanel.classList.add('active');
                }).catch(() => {
                    commentsList.innerHTML = '<li class="faculty-comments-empty">Unable to load comments.</li>';
                    commentsPanel.classList.add('active');
                });
            });
        });
    }

    setResultsView(currentView);
}

/**
 * Render faculty response count table rows
 */
function renderFacultyResponseTable(items) {
    const table = document.getElementById('facultyResponseCountsTable');
    if (!table) return;

    const tbody = table.querySelector('tbody');
    if (!tbody) return;

    if (!items.length) {
        tbody.innerHTML = '<tr><td colspan="10">No faculty records found.</td></tr>';
        return;
    }

    tbody.innerHTML = items.map(item => {
        const responseRate = item.required ? Math.round((item.received / item.required) * 100) : 0;
        const normalizedStatus = String(item.status || '').toLowerCase();
        const statusText = (normalizedStatus === 'inactive' || normalizedStatus === 'needs attention')
            ? 'Inactive'
            : 'Active';
        const statusClass = (statusText === 'Inactive')
            ? 'inactive'
            : 'active';
        const employmentType = item.employmentType || '-';
        const position = item.position || '-';
        return '<tr>' +
            '<td>' + item.professorId + '</td>' +
            '<td>' + item.professorName + '</td>' +
            '<td>' + item.institute + '</td>' +
            '<td>' + employmentType + '</td>' +
            '<td>' + position + '</td>' +
            '<td><span class="count-pill">' + item.received + '/' + item.required + '</span></td>' +
            '<td>' + responseRate + '%</td>' +
            '<td>' + item.avgScore.toFixed(1) + '</td>' +
            '<td><span class="dean-status-pill ' + statusClass + '">' + statusText + '</span></td>' +
            '<td><button type="button" class="btn-submit faculty-comments-btn js-view-comments" data-professor-id="' + item.professorId + '">View</button></td>' +
            '</tr>';
    }).join('');
}

/**
 * SQL-ready placeholder for faculty comments
 */
function fetchFacultyCommentsFromSql(query) {
    console.log('Ready for SQL integration: /api/dean/faculty/comments', query);
    return Promise.resolve([]);
}

/**
 * Show current search scope and result count
 */
function updateFacultySearchResult(count, total, keyword, view) {
    const resultEl = document.getElementById('facultySearchResult');
    if (!resultEl) return;

    const label = view === 'peer'
        ? 'Peer Evaluation Results'
        : view === 'supervisor'
            ? 'Supervisor Evaluation Results'
            : 'Student Evaluation Results';

    if (!keyword) {
        resultEl.textContent = 'Showing ' + label + ' under assigned institutes. Total: ' + total;
        return;
    }

    resultEl.textContent = 'Found ' + count + ' of ' + total + ' faculty record(s) for "' + keyword + '" in ' + label + '.';
}

/**
 * SQL-ready placeholder for peer evaluation results
 */
function fetchDeanPeerEvaluationResultsFromSql(query) {
    const assignedInstitutes = Array.isArray(query && query.assignedInstitutes)
        ? query.assignedInstitutes
        : getDeanAssignedInstitutes(query ? query.deanId : '');

    let scopedResults = [];
    if (typeof SharedData !== 'undefined' && SharedData.getUsers) {
        const allUsers = SharedData.getUsers();
        scopedResults = allUsers
            .filter(u => u.role === 'professor' && u.department && assignedInstitutes.includes(u.department.toUpperCase()))
            .map(prof => ({
                professorId: prof.employeeId || prof.id || 'N/A',
                professorName: prof.name || 'Unknown',
                institute: (prof.institute || prof.department || '').toUpperCase(),
                employmentType: prof.employmentType || 'Regular',
                position: prof.position || 'Instructor',
                required: 10,  // Peer evaluations usually have a smaller requirements pool
                received: prof.evaluatedCount ? Math.floor(prof.evaluatedCount / 10) : 0,
                avgScore: prof.averageRating || 0,
                lastUpdated: new Date().toISOString().split('T')[0],
                status: prof.status || 'Active'
            }));
    }

    return Promise.resolve(scopedResults);
}

/**
 * SQL-ready placeholder for supervisor evaluation results (single record)
 */
function fetchDeanSupervisorEvaluationResultsFromSql(query) {
    const assignedInstitutes = Array.isArray(query && query.assignedInstitutes)
        ? query.assignedInstitutes
        : getDeanAssignedInstitutes(query ? query.deanId : '');

    let scopedResults = [];
    if (typeof SharedData !== 'undefined' && SharedData.getUsers) {
        const allUsers = SharedData.getUsers();
        scopedResults = allUsers
            .filter(u => u.role === 'professor' && assignedInstitutes.includes(u.department))
            .map(prof => ({
                professorId: prof.employeeId || prof.id || 'N/A',
                professorName: prof.name || 'Unknown',
                institute: prof.department || '',
                employmentType: prof.employmentType || 'Regular',
                position: prof.position || 'Instructor',
                required: 1,  // Supervisor evaluate once
                received: prof.evaluatedCount ? 1 : 0,
                avgScore: prof.averageRating || 0,
                lastUpdated: new Date().toISOString().split('T')[0],
                status: prof.status || 'Active'
            }));
    }

    return Promise.resolve(scopedResults);
}

/**
 * Setup faculty peer-to-peer room management
 * Rule: professor can only be registered in one room at a time.
 */
function setupPeerManagementView() {
    const roomNameInput = document.getElementById('peerMgmtRoomName');
    const directorySearchInput = document.getElementById('peerMgmtDirectorySearch');
    const pickerContainer = document.getElementById('peerMgmtProfessorPicker');
    const createRoomBtn = document.getElementById('peerMgmtCreateRoomBtn');
    const clearSelectionBtn = document.getElementById('peerMgmtClearSelectionBtn');
    const messageEl = document.getElementById('peerMgmtMessage');
    const roomsTable = document.getElementById('peerMgmtRoomsTable');
    const activeRoomTitle = document.getElementById('peerMgmtActiveRoomTitle');
    const addProfessorInput = document.getElementById('peerMgmtAddProfessorInput');
    const addProfessorList = document.getElementById('peerMgmtAddProfessorList');
    const addProfessorBtn = document.getElementById('peerMgmtAddProfessorBtn');
    const membersTable = document.getElementById('peerMgmtMembersTable');
    const coordinatorLabel = document.getElementById('peerMgmtCoordinatorLabel');

    if (
        !roomNameInput || !directorySearchInput ||
        !pickerContainer || !createRoomBtn || !clearSelectionBtn || !messageEl ||
        !roomsTable || !activeRoomTitle || !addProfessorInput || !addProfessorList || !addProfessorBtn || !membersTable
    ) {
        return;
    }

    const session = getUserSession();
    const deanId = session ? session.username : '';
    const assignedInstitutes = getDeanAssignedInstitutes(session);
    const selectedProfessorIds = new Set();
    const state = {
        rooms: [],
        professorDirectory: [],
        activeRoomId: null,
        nextRoomId: 1
    };
    let availableProfessors = [];

    function setMessage(text, type) {
        messageEl.textContent = text;
        messageEl.classList.remove('success', 'error', 'info');
        messageEl.classList.add(type || 'info');
    }

    function getProfessorById(professorId) {
        return state.professorDirectory.find(item => item.id === professorId) || null;
    }

    function getRoomById(roomId) {
        return state.rooms.find(room => room.id === roomId) || null;
    }

    function findRoomContainingProfessor(professorId) {
        return state.rooms.find(room => room.memberIds.includes(professorId)) || null;
    }

    function renderProfessorPicker() {
        const keyword = directorySearchInput.value.trim().toLowerCase();
        const filtered = state.professorDirectory.filter(professor => {
            if (!keyword) return true;
            const name = (professor.name || '').toLowerCase();
            const id = (professor.id || '').toLowerCase();
            const institute = (professor.institute || '').toLowerCase();
            return name.includes(keyword) || id.includes(keyword) || institute.includes(keyword);
        });

        if (!filtered.length) {
            pickerContainer.innerHTML = '<p class="peer-mgmt-empty">No professors found.</p>';
            return;
        }

        pickerContainer.innerHTML = filtered.map(professor => {
            const assignedRoom = findRoomContainingProfessor(professor.id);
            const assignedText = assignedRoom ? 'Already in room: ' + assignedRoom.name : 'Available';
            const checked = selectedProfessorIds.has(professor.id) ? 'checked' : '';
            const disabled = assignedRoom ? 'disabled' : '';

            return '<label class="peer-mgmt-prof-item">' +
                '<input type="checkbox" class="peer-mgmt-prof-checkbox" data-professor-id="' + professor.id + '" ' + checked + ' ' + disabled + '>' +
                '<div class="peer-mgmt-prof-info">' +
                '<strong>' + professor.name + '</strong>' +
                '<span>' + professor.id + ' | ' + professor.institute + '</span>' +
                '<small class="' + (assignedRoom ? 'peer-mgmt-status-busy' : 'peer-mgmt-status-open') + '">' + assignedText + '</small>' +
                '</div>' +
                '</label>';
        }).join('');

        pickerContainer.querySelectorAll('.peer-mgmt-prof-checkbox').forEach(checkbox => {
            checkbox.addEventListener('change', function () {
                const professorId = this.getAttribute('data-professor-id');
                if (!professorId) return;
                if (this.checked) {
                    selectedProfessorIds.add(professorId);
                } else {
                    selectedProfessorIds.delete(professorId);
                }
            });
        });
    }

    function renderRoomsTable() {
        const tbody = roomsTable.querySelector('tbody');
        if (!tbody) return;

        if (!state.rooms.length) {
            tbody.innerHTML = '<tr><td colspan="5">No rooms created yet.</td></tr>';
            return;
        }

        tbody.innerHTML = state.rooms.map(room => {
            const count = room.memberIds.length;
            return '<tr>' +
                '<td>' + room.name + '</td>' +
                '<td>' + room.scope + '</td>' +
                '<td>' + count + ' professor(s)</td>' +
                '<td>' +
                '<button type="button" class="btn-submit peer-mgmt-inline-btn js-manage-room-btn" data-room-id="' + room.id + '">Manage</button> ' +
                '<button type="button" class="btn-cancel peer-mgmt-inline-btn js-delete-room-btn" data-room-id="' + room.id + '">Delete</button>' +
                '</td>' +
                '</tr>';
        }).join('');

        tbody.querySelectorAll('.js-manage-room-btn').forEach(button => {
            button.addEventListener('click', function () {
                const roomId = parseInt(this.getAttribute('data-room-id'), 10);
                if (!roomId) return;
                state.activeRoomId = roomId;
                renderManagePanel();
                renderAddProfessorSelect();
                setMessage('Managing selected room.', 'info');
            });
        });

        tbody.querySelectorAll('.js-delete-room-btn').forEach(button => {
            button.addEventListener('click', function () {
                const roomId = parseInt(this.getAttribute('data-room-id'), 10);
                if (!roomId) return;
                const room = getRoomById(roomId);
                if (!room) return;

                const shouldDelete = confirm('Delete room "' + room.name + '"?');
                if (!shouldDelete) return;

                state.rooms = state.rooms.filter(item => item.id !== roomId);
                if (state.activeRoomId === roomId) {
                    state.activeRoomId = null;
                }

                console.log('Ready for SQL integration: /api/dean/peer-management/rooms/delete', {
                    deanId,
                    roomId
                });

                renderProfessorPicker();
                renderRoomsTable();
                renderManagePanel();
                renderAddProfessorSelect();
                setMessage('Room deleted.', 'success');
            });
        });
    }

    function renderManagePanel() {
        const tbody = membersTable.querySelector('tbody');
        if (!tbody) return;

        const activeRoom = getRoomById(state.activeRoomId);
        if (!activeRoom) {
            activeRoomTitle.textContent = 'Manage Room Members';
            if (coordinatorLabel) coordinatorLabel.textContent = 'None assigned';
            tbody.innerHTML = '<tr><td colspan="4">Select a room to manage members.</td></tr>';
            return;
        }

        activeRoomTitle.textContent = 'Manage Room Members: ' + activeRoom.name;
        const coordinator = activeRoom.coordinatorId ? getProfessorById(activeRoom.coordinatorId) : null;
        if (coordinatorLabel) {
            coordinatorLabel.textContent = coordinator ? coordinator.name : 'None assigned';
        }
        if (!activeRoom.memberIds.length) {
            tbody.innerHTML = '<tr><td colspan="4">No members in this room.</td></tr>';
            return;
        }

        tbody.innerHTML = activeRoom.memberIds.map(memberId => {
            const professor = getProfessorById(memberId);
            if (!professor) return '';
            const isCoordinator = activeRoom.coordinatorId === professor.id;
            return '<tr>' +
                '<td>' + professor.id + '</td>' +
                '<td>' + professor.name + '</td>' +
                '<td>' + professor.institute + '</td>' +
                '<td>' +
                '<button type="button" class="btn-submit peer-mgmt-inline-btn js-set-coordinator-btn" data-professor-id="' + professor.id + '"' + (isCoordinator ? ' disabled' : '') + '>' + (isCoordinator ? 'Coordinator' : 'Set Coordinator') + '</button> ' +
                '<button type="button" class="btn-cancel peer-mgmt-inline-btn js-remove-member-btn" data-professor-id="' + professor.id + '">Remove</button>' +
                '</td>' +
                '</tr>';
        }).join('');

        tbody.querySelectorAll('.js-set-coordinator-btn').forEach(button => {
            button.addEventListener('click', function () {
                const professorId = this.getAttribute('data-professor-id');
                handleSetCoordinator(professorId);
            });
        });

        tbody.querySelectorAll('.js-remove-member-btn').forEach(button => {
            button.addEventListener('click', function () {
                const professorId = this.getAttribute('data-professor-id');
                const room = getRoomById(state.activeRoomId);
                if (!professorId || !room) return;

                room.memberIds = room.memberIds.filter(id => id !== professorId);
                if (room.coordinatorId === professorId) {
                    room.coordinatorId = null;
                }

                console.log('Ready for SQL integration: /api/dean/peer-management/rooms/update-members', {
                    deanId,
                    roomId: room.id,
                    action: 'remove',
                    professorId
                });

                renderProfessorPicker();
                renderRoomsTable();
                renderManagePanel();
                renderAddProfessorSelect();
                setMessage('Professor removed from room.', 'success');
            });
        });
    }

    function renderAddProfessorSelect() {
        const activeRoom = getRoomById(state.activeRoomId);
        if (!activeRoom) {
            availableProfessors = [];
            addProfessorList.innerHTML = '';
            addProfessorInput.value = '';
            addProfessorInput.placeholder = 'Select a room first';
            addProfessorInput.disabled = true;
            addProfessorBtn.disabled = true;
            return;
        }

        const available = state.professorDirectory.filter(professor => !findRoomContainingProfessor(professor.id));
        availableProfessors = available;
        if (!available.length) {
            addProfessorList.innerHTML = '';
            addProfessorInput.value = '';
            addProfessorInput.placeholder = 'No available professors';
            addProfessorInput.disabled = true;
            addProfessorBtn.disabled = true;
            return;
        }

        addProfessorList.innerHTML = available.map(professor =>
            '<option value="' + professor.id + ' - ' + professor.name + '"></option>'
        ).join('');
        addProfessorInput.placeholder = 'Search employee ID or full name';
        addProfessorInput.disabled = false;
        addProfessorBtn.disabled = false;
    }

    function handleSetCoordinator(professorId) {
        const room = getRoomById(state.activeRoomId);
        if (!room) return;
        if (!room.memberIds.includes(professorId)) {
            setMessage('Select a member in this room to assign as coordinator.', 'error');
            return;
        }

        const password = prompt('Enter password to assign coordinator:');
        if (password === null) {
            setMessage('Coordinator assignment cancelled.', 'info');
            return;
        }
        if (password !== 'dean') {
            setMessage('Incorrect password. Coordinator not assigned.', 'error');
            return;
        }

        room.coordinatorId = professorId;

        console.log('Ready for SQL integration: /api/dean/peer-management/rooms/update-members', {
            deanId,
            roomId: room.id,
            action: 'setCoordinator',
            coordinatorId: professorId
        });

        renderManagePanel();
        setMessage('Coordinator assigned.', 'success');
    }

    function handleCreateRoom() {
        const roomName = roomNameInput.value.trim();
        const selectedIds = Array.from(selectedProfessorIds);

        if (!roomName) {
            setMessage('Room name is required.', 'error');
            return;
        }

        if (selectedIds.length < 2) {
            setMessage('Select at least 2 professors to create a room.', 'error');
            return;
        }

        const occupiedIds = selectedIds.filter(professorId => findRoomContainingProfessor(professorId));
        if (occupiedIds.length) {
            const occupiedNames = occupiedIds.map(professorId => {
                const professor = getProfessorById(professorId);
                return professor ? professor.name : professorId;
            });
            setMessage('Cannot create room. Already assigned: ' + occupiedNames.join(', '), 'error');
            return;
        }

        const newRoom = {
            id: state.nextRoomId,
            name: roomName,
            scope: 'All',
            memberIds: selectedIds.slice(),
            coordinatorId: null
        };

        state.nextRoomId += 1;
        state.rooms.push(newRoom);
        state.activeRoomId = newRoom.id;

        console.log('Ready for SQL integration: /api/dean/peer-management/rooms/create', {
            deanId,
            room: newRoom
        });

        roomNameInput.value = '';
        selectedProfessorIds.clear();

        renderProfessorPicker();
        renderRoomsTable();
        renderManagePanel();
        renderAddProfessorSelect();
        setMessage('Room created successfully.', 'success');
    }

    function handleAddProfessorToActiveRoom() {
        const activeRoom = getRoomById(state.activeRoomId);
        if (!activeRoom) {
            setMessage('Select a room to manage first.', 'error');
            return;
        }

        const rawValue = addProfessorInput.value.trim();
        if (!rawValue) {
            setMessage('Enter an employee ID or full name.', 'error');
            return;
        }

        const resolved = resolveProfessorFromInput(rawValue, availableProfessors);
        if (!resolved) {
            setMessage('No matching professor found. Please select from the list.', 'error');
            return;
        }

        const professorId = resolved.id;

        const currentRoom = findRoomContainingProfessor(professorId);
        if (currentRoom && currentRoom.id !== activeRoom.id) {
            const professor = getProfessorById(professorId);
            const name = professor ? professor.name : professorId;
            setMessage('Cannot add ' + name + '. Already assigned to room "' + currentRoom.name + '".', 'error');
            return;
        }

        if (!activeRoom.memberIds.includes(professorId)) {
            activeRoom.memberIds.push(professorId);
        }

        console.log('Ready for SQL integration: /api/dean/peer-management/rooms/update-members', {
            deanId,
            roomId: activeRoom.id,
            action: 'add',
            professorId
        });

        addProfessorInput.value = '';
        renderProfessorPicker();
        renderRoomsTable();
        renderManagePanel();
        renderAddProfessorSelect();
        setMessage('Professor added to room.', 'success');
    }

    createRoomBtn.addEventListener('click', handleCreateRoom);
    clearSelectionBtn.addEventListener('click', function () {
        selectedProfessorIds.clear();
        renderProfessorPicker();
        setMessage('Selection cleared.', 'info');
    });
    directorySearchInput.addEventListener('input', renderProfessorPicker);
    addProfessorBtn.addEventListener('click', handleAddProfessorToActiveRoom);

    fetchPeerManagementDirectoryFromSql({
        deanId,
        ay: '2025-2026',
        sem: '2'
    }).then(payload => {
        const directory = Array.isArray(payload.professors) ? payload.professors : [];
        if (assignedInstitutes.length) {
            const instituteSet = new Set(assignedInstitutes.map(i => String(i).toLowerCase()));
            state.professorDirectory = directory.filter(item =>
                instituteSet.has(String(item.institute || '').toLowerCase())
            );
        } else {
            state.professorDirectory = directory;
        }
        const rooms = Array.isArray(payload.rooms) ? payload.rooms : [];
        state.rooms = rooms.map(room => ({
            id: room.id,
            name: room.name,
            scope: room.scope || 'All',
            memberIds: Array.isArray(room.memberIds) ? room.memberIds.slice() : [],
            coordinatorId: room.coordinatorId || null
        }));
        state.nextRoomId = state.rooms.reduce((maxId, room) => Math.max(maxId, room.id || 0), 0) + 1;

        renderProfessorPicker();
        renderRoomsTable();
        renderManagePanel();
        renderAddProfessorSelect();
        setMessage('Peer management ready.', 'info');
    }).catch(() => {
        state.professorDirectory = [];
        state.rooms = [];
        renderProfessorPicker();
        renderRoomsTable();
        renderManagePanel();
        renderAddProfessorSelect();
        setMessage('Unable to load peer management data.', 'error');
    });
}

function resolveProfessorFromInput(rawValue, available) {
    if (!rawValue || !available.length) return null;
    const value = rawValue.trim().toLowerCase();

    let match = available.find(professor => professor.id.toLowerCase() === value);
    if (match) return match;

    match = available.find(professor => (professor.name || '').toLowerCase() === value);
    if (match) return match;

    match = available.find(professor =>
        value.includes(professor.id.toLowerCase()) ||
        value.includes((professor.name || '').toLowerCase())
    );

    return match || null;
}

/**
 * SQL-ready placeholder data for peer room management
 */
function fetchPeerManagementDirectoryFromSql(query) {
    // Example SQL-backed API requests:
    // POST /api/dean/peer-management/professors/list
    // POST /api/dean/peer-management/rooms/list
    // Both should include dean scope fields (ay/sem, deanId).
    // Server must enforce: one professor can belong to one room only.
    const payload = {
        professors: [
            { id: 'FAC-20451', name: 'Efrhain Louis Pajota', institute: 'Institute of Computer Studies' },
            { id: 'FAC-21014', name: 'Maria Santos', institute: 'Institute of Computer Studies' },
            { id: 'FAC-21440', name: 'Leo Ramos', institute: 'Institute of Computer Studies' },
            { id: 'FAC-21888', name: 'Catherine Dela Cruz', institute: 'Institute of Computer Studies' },
            { id: 'FAC-21990', name: 'Ivan Tan', institute: 'Institute of Computer Studies' },
            { id: 'FAC-18934', name: 'John Mendoza', institute: 'Institute of Engineering' },
            { id: 'FAC-19881', name: 'Ana Garcia', institute: 'Institute of Engineering' },
            { id: 'FAC-17710', name: 'Riza Delos Reyes', institute: 'Institute of Engineering' },
            { id: 'FAC-30022', name: 'Paul Reyes', institute: 'Institute of Business and Accountancy' },
            { id: 'FAC-30108', name: 'Lena Cruz', institute: 'Institute of Liberal Arts' }
        ],
        rooms: [
            // Start with no existing rooms; user-created only
        ]
    };

    console.log('Ready for SQL integration: /api/dean/peer-management/professors/list, /api/dean/peer-management/rooms/list', query);
    return Promise.resolve(payload);
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

// Export functions for future use
if (typeof module !== 'undefined' && module.exports) {
    module.exports = {
        checkAuthentication,
        handleLogout,
        clearUserSession,
        getUserSession,
        handleActionButton,
        handleViewDetails,
        updateSummaryCards
    };
}


