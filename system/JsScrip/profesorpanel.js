// Professor Panel JavaScript - Dashboard Functionality

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
 * Check if user is authenticated and is a professor
 * @returns {boolean} - True if user is authenticated as professor
 */
function checkAuthentication() {
    const session = SharedData.getSession();
    if (!session) {
        return false;
    }

    try {
        // Check if user is authenticated and is a professor
        return session.isAuthenticated === true && session.role === 'professor';
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
 * Initialize the professor dashboard
 */
function initializeDashboard() {
    loadUserInfo();
    setupNavigation();
    setupLogout();
    setupHeaderPanels();
    setupActionButtons();
    setupTableActions();
    if (!setupSemesterFilter()) {
        loadFacultySummary();
    }
    setupProfessorSubjectComments();
    setupProfilePhotoUpload();
    setupPeerEvaluationForm();
    setupProfileActions();
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
                const formattedName = username.charAt(0).toUpperCase() + username.slice(1) + ' Professor';
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

    if (viewName === 'dashboard') {
        if (dashboardView) dashboardView.style.display = 'block';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (reportsView) reportsView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        closeAllPanels();
    } else if (viewName === 'peerEvaluation') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'block';
        if (reportsView) reportsView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';

        loadDynamicPeerQuestionnaire();

        window.scrollTo(0, 0);
        closeAllPanels();
    } else if (viewName === 'reports') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (reportsView) reportsView.style.display = 'block';
        if (profileView) profileView.style.display = 'none';
        // Scroll to top
        window.scrollTo(0, 0);
        closeAllPanels();
    } else if (viewName === 'profile') {
        if (dashboardView) dashboardView.style.display = 'none';
        if (peerEvaluationView) peerEvaluationView.style.display = 'none';
        if (reportsView) reportsView.style.display = 'none';
        if (profileView) profileView.style.display = 'block';
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
        initializeSupervisorCharts();
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

function initializeSupervisorCharts() {
    const barCtx = document.getElementById('supervisorBarChart');
    const pieCtx = document.getElementById('supervisorPieChart');
    if (barCtx) {
        if (window.supervisorBarChartInstance) window.supervisorBarChartInstance.destroy();
        window.supervisorBarChartInstance = new Chart(barCtx, {
            type: 'bar',
            data: {
                labels: ['Leadership', 'Strategic Planning', 'Mentorship', 'Compliance'],
                datasets: [{
                    label: 'Supervisor Avg Score',
                    data: [4.5, 4.4, 4.6, 4.3],
                    backgroundColor: '#60a5fa',
                    borderColor: '#2563eb',
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
        if (window.supervisorPieChartInstance) window.supervisorPieChartInstance.destroy();
        window.supervisorPieChartInstance = new Chart(pieCtx, {
            type: 'pie',
            data: {
                labels: ['5 Stars', '4 Stars', '3 Stars', '2 Stars', '1 Star'],
                datasets: [{
                    data: [50, 28, 12, 6, 4],
                    backgroundColor: ['#2563eb', '#60a5fa', '#f59e0b', '#f97316', '#ef4444'],
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
 * Initialize bar chart
 */
function initializeBarChart() {
    const ctx = document.getElementById('barChart');
    if (!ctx) return;

    // Destroy existing chart if it exists
    if (window.barChartInstance) {
        window.barChartInstance.destroy();
    }

    window.barChartInstance = new Chart(ctx, {
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

/**
 * Initialize pie chart
 */
function initializePieChart() {
    const ctx = document.getElementById('pieChart');
    if (!ctx) return;

    // Destroy existing chart if it exists
    if (window.pieChartInstance) {
        window.pieChartInstance.destroy();
    }

    window.pieChartInstance = new Chart(ctx, {
        type: 'pie',
        data: {
            labels: ['5 Stars', '4 Stars', '3 Stars', '2 Stars', '1 Star'],
            datasets: [{
                data: [45, 30, 15, 7, 3],
                backgroundColor: [
                    '#10b981',
                    '#34d399',
                    '#fbbf24',
                    '#f59e0b',
                    '#ef4444'
                ],
                borderWidth: 2,
                borderColor: '#ffffff'
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

    if (actionTitle === 'View Reports' || actionTitle === 'Generate Paper') {
        openProfessorReportPdf();
        return;
    }
}

/**
 * Open the professor report PDF in a new tab
 * Mirrors the dean panel behavior so the dashboard button works.
 */
function openProfessorReportPdf() {
    // Reuse the sample report asset until a generated file is available
    const pdfPath = 'files/sample file.pdf';
    const pdfUrl = encodeURI(pdfPath);

    const newTab = window.open(pdfUrl, '_blank', 'noopener');
    if (!newTab) {
        alert('Please allow pop-ups to view or download the generated paper.');
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
    const studentsCard = document.querySelector('.summary-card.students .card-number');
    const responseCard = document.querySelector('.summary-card.response .card-number');

    if (evaluationsCard) evaluationsCard.textContent = `${stats.received}/${stats.required}`;
    if (scoreCard) scoreCard.textContent = `${stats.averageScore.toFixed(1)}/5.0`;
    if (studentsCard) studentsCard.textContent = stats.required;
    if (responseCard) responseCard.textContent = `${stats.responseRate}%`;
}

/**
 * Setup peer evaluation form functionality
 */
function setupPeerEvaluationForm() {
    const form = document.getElementById('peerEvaluationForm');
    const cancelBtn = document.getElementById('cancelPeerBtn');
    if (!form) return;

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

/**
 * Dynamically load and render the Peer Evaluation Questionnaire
 */
function loadDynamicPeerQuestionnaire() {
    const container = document.getElementById('dynamic-peer-questions-container');
    if (!container) return;

    // Fetch active questionnaires
    const allData = SharedData.getQuestionnaires();
    const currentSemester = SharedData.getCurrentSemester();

    console.log('[Professor] loadDynamicPeerQuestionnaire — semester:', JSON.stringify(currentSemester), '| available keys:', Object.keys(allData));

    // Safety check - we fallback to an empty format if nothing is active
    let dataToUse = null;
    if (currentSemester && allData[currentSemester]) {
        dataToUse = allData[currentSemester];
    } else {
        // Find latest available semester as fallback
        const semesters = Object.keys(allData).sort().reverse();
        if (semesters.length > 0) {
            dataToUse = allData[semesters[0]];
        }
    }

    const peerData = (dataToUse && dataToUse['professor-to-professor']) || { sections: [], questions: [], header: {} };

    // Update Header
    const dynamicTitle = document.getElementById('dynamic-form-title');
    const dynamicDesc = document.getElementById('dynamic-form-description');

    if (dynamicTitle && peerData.header && peerData.header.title) {
        dynamicTitle.textContent = peerData.header.title;
    } else if (dynamicTitle) {
        dynamicTitle.textContent = 'Professor Peer Evaluation Form';
    }

    if (dynamicDesc && peerData.header && peerData.header.description) {
        dynamicDesc.textContent = peerData.header.description;
    } else if (dynamicDesc) {
        dynamicDesc.textContent = 'Evaluate a colleague based on workplace conduct, collaboration, and professionalism.';
    }

    // Render questions
    const sections = peerData.sections || [];
    const questions = peerData.questions || [];

    if (sections.length === 0 && questions.length === 0) {
        container.innerHTML = `
            <div class="empty-state" style="text-align: center; padding: 3rem 1rem;">
                <i class="fas fa-info-circle" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                <p>No questions configured for peer evaluation yet.</p>
            </div>`;
        return;
    }

    let html = '';

    // Group questions by section ID
    const groupedQuestions = {};
    sections.forEach(sec => groupedQuestions[sec.id] = []);
    questions.forEach(q => {
        if (groupedQuestions[q.sectionId]) {
            groupedQuestions[q.sectionId].push(q);
        } else {
            if (!groupedQuestions['unassigned']) groupedQuestions['unassigned'] = [];
            groupedQuestions['unassigned'].push(q);
        }
    });

    let globalQuestionIndex = 1;

    // Render unassigned questions first if any
    if (groupedQuestions['unassigned'] && groupedQuestions['unassigned'].length > 0) {
        html += `<div class="form-section">`;
        groupedQuestions['unassigned'].forEach(q => {
            html += renderPeerQuestionHTML(q, globalQuestionIndex++);
        });
        html += `</div>`;
    }

    // Render sections
    sections.forEach(section => {
        const sectionQuestions = groupedQuestions[section.id] || [];
        if (sectionQuestions.length === 0) return;

        html += `
            <div class="form-section">
                <h3 class="section-title purple">${escapeHTML(section.title)}</h3>
        `;
        sectionQuestions.forEach(q => {
            html += renderPeerQuestionHTML(q, globalQuestionIndex++);
        });
        html += `</div>`;
    });
    container.innerHTML = html;
}

/**
 * Generate HTML snippet for a single question based on type
 * @param {Object} question - The question object
 * @param {Number} index - The global count for IDs
 */
function renderPeerQuestionHTML(question, index) {
    const isRequired = question.required ? 'required' : '';
    const qid = String(question.id);

    // Qualitative Text Area
    if (question.type === 'qualitative') {
        return `
            <div class="question-group" style="margin-bottom: 24px;">
                <label class="question-label" for="q-${qid}">${escapeHTML(question.text)} ${question.required ? '<span style="color:var(--danger)">*</span>' : ''}</label>
                <div class="form-group" style="margin-top: 8px;">
                    <textarea 
                        id="q-${qid}" 
                        name="${qid}" 
                        class="form-textarea" 
                        rows="4" 
                        placeholder="Type your response here..." 
                        ${isRequired}></textarea>
                </div>
            </div>
        `;
    }

    // Rating Scale (Radio Buttons)
    return `
        <div class="question-group">
            <label class="question-label" for="q-${qid}">${escapeHTML(question.text)} ${question.required ? '<span style="color:var(--danger)">*</span>' : ''}</label>
            <div class="rating-scale">
                <input type="radio" name="${qid}" id="q${qid}-1" value="1" ${isRequired}>
                <label for="q${qid}-1" class="rating-option">1</label>
                <input type="radio" name="${qid}" id="q${qid}-2" value="2" ${isRequired}>
                <label for="q${qid}-2" class="rating-option">2</label>
                <input type="radio" name="${qid}" id="q${qid}-3" value="3" ${isRequired}>
                <label for="q${qid}-3" class="rating-option">3</label>
                <input type="radio" name="${qid}" id="q${qid}-4" value="4" ${isRequired}>
                <label for="q${qid}-4" class="rating-option">4</label>
                <input type="radio" name="${qid}" id="q${qid}-5" value="5" ${isRequired}>
                <label for="q${qid}-5" class="rating-option">5</label>
            </div>
            <p class="rating-legend">5 = Excellent, 1 = Poor</p>
        </div>
    `;
}

/**
 * Basic HTML escaping to prevent XSS in dynamic titles/questions
 */
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
 * Handle Peer Evaluation Submission (Dynamic Data Extraction)
 */
function handlePeerEvaluation() {
    const form = document.getElementById('peerEvaluationForm');
    if (!form) return;

    // ── Evaluation period gate ──
    if (!SharedData.isEvalPeriodOpen('professor-professor')) {
        const dates = SharedData.getEvalPeriodDates('professor-professor');
        let msg = 'The Professor to Professor evaluation period is not currently open.';
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

    // Get definition from SharedData to separate ratings vs qualitative
    const allData = SharedData.getQuestionnaires();
    const currentSemester = SharedData.getCurrentSemester();
    let dataToUse = null;
    if (currentSemester && allData[currentSemester]) {
        dataToUse = allData[currentSemester];
    } else {
        const semesters = Object.keys(allData).sort().reverse();
        if (semesters.length > 0) {
            dataToUse = allData[semesters[0]];
        }
    }
    const peerData = (dataToUse && dataToUse['professor-to-professor']) || { questions: [] };
    const allQuestions = peerData.questions || [];

    const ratingsGroup = {};
    const qualitativeGroup = {};

    for (let [key, value] of formData.entries()) {
        if (key === 'peerProfessor' || key === 'peerComments') continue;

        let questionDef = allQuestions.find(q => String(q.id) === key);
        if (questionDef && questionDef.type === 'qualitative') {
            qualitativeGroup[key] = value;
        } else {
            // Default to rating if not found or explicitly rating
            ratingsGroup[key] = value;
        }
    }

    const session = getUserSession() || {};
    const payload = {
        evaluatorId: session.username || '',
        evaluatorName: session.fullName || 'Anonymous Professor',
        evaluatorRole: 'professor',
        evaluationType: 'peer',
        colleagueId: formData.get('peerProfessor'),
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
        title: 'Peer Evaluation Submitted',
        user: payload.evaluatorName,
        role: 'professor',
        date: new Date().toISOString()
    });

    console.log('Peer evaluation submitted to local database:', payload);
    showFormMessage(form, 'Peer evaluation submitted successfully to local database.', 'success');

    // Small delay to let the user see the success message
    setTimeout(() => {
        form.reset();
        switchView('dashboard');
        updateNavigation('dashboard');
    }, 1500);
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
    const professorId = session ? session.username : '';
    const ay = selection.ay || '2025-2026';
    const sem = selection.sem || '2';
    const evaluationType = selection.evaluationType || 'student';

    fetchFacultySummaryFromSql({
        professorId,
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
    placeholder.textContent = buildInitials(fullName) || 'PP';

    const storedPhoto = SharedData.getProfilePhoto('professor');
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
            SharedData.setProfilePhoto('professor', reader.result);
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

/**
 * SQL-ready fetch for faculty summary data
 */
function fetchFacultySummaryFromSql(query) {
    // Example SQL-backed API request:
    // return fetch('/api/professor/evaluations/summary', {
    //     method: 'POST',
    //     headers: { 'Content-Type': 'application/json' },
    //     body: JSON.stringify(query)
    // }).then(res => res.json());

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
            : evalType === 'supervisor'
                ? [
                    { employeeId: 'EMP-50011', avgRating: 4.4, required: 15, received: 12 }
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

    if (evaluationType !== 'student') {
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

    const colCount = evaluationType === 'student' ? 6 : 3;

    if (!subjects.length) {
        tbody.innerHTML = `<tr><td colspan="${colCount}">No evaluation data available.</td></tr>`;
        return;
    }

    tbody.innerHTML = subjects.map(item => {
        const responseRate = item.required ? Math.round((item.received / item.required) * 100) : 0;

        if (evaluationType !== 'student') {
            return `
                <tr data-required="${item.required || 0}" data-received="${item.received || 0}" data-avg="${item.avgRating}">
                    <td>${item.employeeId}</td>
                    <td>${item.avgRating.toFixed(1)}</td>
                    <td><button type="button" class="btn-submit faculty-comments-btn js-prof-comments" data-eval-type="${evaluationType}" data-subject="${item.employeeId}" data-section="">View</button></td>
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
                <td><button type="button" class="btn-submit faculty-comments-btn js-prof-comments" data-eval-type="student" data-subject="${item.subject}" data-section="${item.section}">View</button></td>
            </tr>
        `;
    }).join('');
}

/**
 * Setup section feedback summary per subject/section
 */
function setupProfessorSubjectComments() {
    const table = document.getElementById('facultyBreakdownTable');
    const panel = document.getElementById('profSubjectCommentsPanel');
    const title = document.getElementById('profSubjectCommentsTitle');
    const meta = document.getElementById('profSubjectCommentsMeta');
    const list = document.getElementById('profSubjectCommentsList');
    const closeBtn = document.getElementById('profSubjectCommentsClose');

    if (!table || !panel || !title || !meta || !list || !closeBtn) return;

    closeBtn.addEventListener('click', function () {
        panel.classList.remove('active');
    });

    table.addEventListener('click', function (e) {
        const target = e.target;
        if (!target || !target.classList || !target.classList.contains('js-prof-comments')) return;

        const subject = target.getAttribute('data-subject');
        const section = target.getAttribute('data-section');
        const evalType = target.getAttribute('data-eval-type') || 'student';
        if (!subject) return;

        if (evalType === 'professor') {
            title.textContent = 'Professor Feedback Summary';
        } else if (evalType === 'supervisor') {
            title.textContent = 'Supervisor Feedback Summary';
        } else {
            title.textContent = 'Anonymous Feedback';
        }
        const scope = section ? `${subject} • ${section}` : subject;
        meta.textContent = evalType === 'professor'
            ? (scope || subject || 'Professor feedback')
            : evalType === 'supervisor'
                ? (scope || subject || 'Supervisor feedback')
                : (scope ? `${scope} — student comments are anonymized.` : 'Anonymized comments');

        fetchSectionSummaryFromSql({
            subject,
            section,
            ay: '2025-2026',
            sem: '2',
            evaluationType: evalType
        }).then(summaries => {
            if (!summaries.length) {
                list.innerHTML = '<li class="faculty-comments-empty">No anonymized feedback available.</li>';
            } else {
                list.innerHTML = summaries.map(item =>
                    '<li>' +
                    '<div class="faculty-comment-text">' + item.text + '</div>' +
                    '</li>'
                ).join('');
            }
            panel.classList.add('active');
        }).catch(() => {
            list.innerHTML = '<li class="faculty-comments-empty">Unable to load anonymized feedback.</li>';
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
            { text: 'Strong clarity on core topics and helpful practical examples.' },
            { text: 'Students want more hands-on activities for deeper practice.' }
        ],
        'IT 303 - Event-Driven Programming|BSIT 3A': [
            { text: 'Lab exercises are effective and guidance is appreciated.' }
        ],
        'IT 305 - Integrative Programming|BSIT 3A': [
            { text: 'Requests for more feedback on coding style and structure.' }
        ]
    };

    const professorMock = {
        'FAC-20451': [
            { text: 'Peer feedback highlights strong leadership and support.' }
        ],
        'FAC-21014': [
            { text: 'Praised for collaborative work across departments.' }
        ],
        'FAC-19881': [
            { text: 'Recognized for mentoring junior faculty effectively.' }
        ]
    };

    const supervisorMock = {
        'EMP-50011': [
            { text: 'Supervises team workloads efficiently and fairly.' }
        ]
    };

    console.log('Ready for SQL integration: /api/professor/subjects/summary', {
        ...query,
        evaluationType: evalType
    });

    if (evalType === 'professor') {
        return Promise.resolve(professorMock[query.subject || ''] || []);
    }

    if (evalType === 'supervisor') {
        return Promise.resolve(supervisorMock[query.subject || ''] || []);
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
        const required = parseInt(row.dataset.required, 10) || 0;
        const received = parseInt(row.dataset.received, 10) || 0;
        const avgRating = parseFloat(row.dataset.avg) || 0;
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

    console.log('Ready for SQL integration: /api/professor/change-email', payload);
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

    console.log('Ready for SQL integration: /api/professor/change-password', payload);
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








