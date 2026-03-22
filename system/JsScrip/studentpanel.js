// Student Panel JavaScript - Dashboard Functionality

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
 * Check if user is authenticated
 * @returns {boolean} - True if user is authenticated
 */
/**
 * Check if user is authenticated
 * @returns {boolean} - True if user is authenticated
 */
function checkAuthentication() {
    const session = SharedData.getSession();
    if (!session) {
        return false;
    }

    try {
        // Strict role check for student
        return session.isAuthenticated === true && session.role === 'student';
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
 * Initialize the dashboard
 */
function initializeDashboard() {
    loadUserInfo();
    setupNavigation();
    setupLogout();
    setupEvaluationButtons();
    setupSubmitNewButton();
    updateSummaryCards();
    setupEvaluationForm();
    prefillProfessorSelection();
    setupHeaderPanels();
    setupProfileActions();
    setupChangePasswordForm();
    setupPasswordToggles();
    setupHistoryView();
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
                const formattedName = username.charAt(0).toUpperCase() + username.slice(1) + ' Student';
                userProfileSpan.textContent = formattedName;
            }

            const profileName = document.getElementById('profileStudentName');
            if (profileName) {
                const formattedName = username.charAt(0).toUpperCase() + username.slice(1) + ' Student';
                profileName.textContent = formattedName;
            }

            const profileId = document.getElementById('profileStudentId');
            if (profileId) {
                profileId.textContent = username;
            }

            const profileMiniId = document.getElementById('profileStudentIdMini');
            if (profileMiniId) {
                profileMiniId.textContent = username;
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
    const notificationBtn = document.getElementById('notificationBtn');
    const profileBtn = document.getElementById('profileBtn');
    const announcementPanel = document.getElementById('announcementPanel');
    const profilePanel = document.getElementById('profilePanel');

    if (!notificationBtn || !profileBtn || !announcementPanel || !profilePanel) {
        return;
    }

    notificationBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        togglePanel(announcementPanel, profilePanel);
    });

    profileBtn.addEventListener('click', function (e) {
        e.stopPropagation();
        togglePanel(profilePanel, announcementPanel);
    });

    document.addEventListener('click', function () {
        announcementPanel.classList.remove('active');
        profilePanel.classList.remove('active');
    });

    announcementPanel.addEventListener('click', function (e) {
        e.stopPropagation();
    });

    profilePanel.addEventListener('click', function (e) {
        e.stopPropagation();
    });
}

/**
 * Toggle a panel and close the other one
 * @param {HTMLElement} panelToToggle
 * @param {HTMLElement} panelToClose
 */
function togglePanel(panelToToggle, panelToClose) {
    const isActive = panelToToggle.classList.contains('active');
    panelToClose.classList.remove('active');
    panelToToggle.classList.toggle('active', !isActive);
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

            // Switch views
            if (view) {
                switchView(view);
            }
        });
    });
}

/**
 * Switch between dashboard and evaluation form views
 * @param {string} viewName - Name of the view to show ('dashboard' or 'evaluationForm')
 */
function switchView(viewName) {
    const dashboardView = document.getElementById('dashboardView');
    const evaluationFormView = document.getElementById('evaluationFormView');
    const profileView = document.getElementById('profileView');
    const historyView = document.getElementById('historyView');
    const pageTitle = document.getElementById('mainPageTitle');

    if (viewName === 'dashboard') {
        if (pageTitle) pageTitle.textContent = 'Student Dashboard';
        dashboardView.style.display = 'block';
        evaluationFormView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (historyView) historyView.style.display = 'none';
    } else if (viewName === 'evaluationForm') {
        if (pageTitle) pageTitle.textContent = 'Evaluation Form';
        dashboardView.style.display = 'none';
        evaluationFormView.style.display = 'block';
        if (profileView) profileView.style.display = 'none';
        if (historyView) historyView.style.display = 'none';
        // Scroll to top
        window.scrollTo(0, 0);

        // Load dynamic questionnaire
        if (typeof loadDynamicQuestionnaire === 'function') {
            loadDynamicQuestionnaire();
        }
    } else if (viewName === 'profile') {
        if (pageTitle) pageTitle.textContent = 'Profile';
        dashboardView.style.display = 'none';
        evaluationFormView.style.display = 'none';
        if (profileView) profileView.style.display = 'block';
        if (historyView) historyView.style.display = 'none';
        window.scrollTo(0, 0);
    } else if (viewName === 'history') {
        if (pageTitle) pageTitle.textContent = 'History';
        dashboardView.style.display = 'none';
        evaluationFormView.style.display = 'none';
        if (profileView) profileView.style.display = 'none';
        if (historyView) historyView.style.display = 'block';
        window.scrollTo(0, 0);
    }
}

/**
 * Handle navigation to different sections
 * @param {string} section - Section name
 */
function handleNavigation(section) {
    // Placeholder for future navigation functionality

    // Example: You can add logic here to show/hide different sections
    // or load different content based on the section
    switch (section) {
        case 'Home':
            // Already on home page
            break;
        case 'Evaluation Forms':
            // Load evaluation forms
            break;
        case 'History':
            // Load history
            break;
        default:
            break;
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
    // You can add a toast notification here
}

/**
 * Setup evaluation action buttons
 */
function setupEvaluationButtons() {
    const startButtons = document.querySelectorAll('.btn-start');

    startButtons.forEach(button => {
        button.addEventListener('click', function () {
            const evaluationItem = this.closest('.evaluation-item');
            const professorName = evaluationItem.querySelector('.professor-name').textContent;
            const courseCode = evaluationItem.querySelector('.course-code').textContent;

            // Handle start evaluation
            handleStartEvaluation(professorName, courseCode);
        });
    });
}

/**
 * Handle start evaluation action
 * @param {string} professorName - Professor's name
 * @param {string} courseCode - Course code
 */
function handleStartEvaluation(professorName, courseCode) {
    // ── Evaluation period gate ──
    if (!SharedData.isEvalPeriodOpen('student-professor')) {
        const dates = SharedData.getEvalPeriodDates('student-professor');
        let msg = 'The Student to Professor evaluation period is not currently open.';
        if (dates.start && dates.end) {
            msg += '\nEvaluation period: ' + dates.start + ' to ' + dates.end + '.';
        } else {
            msg += '\nNo evaluation period has been set by the administrator yet.';
        }
        alert(msg);
        return;
    }

    // Store professor and course info for the form to use
    sessionStorage.setItem('selectedProfessor', professorName);
    sessionStorage.setItem('selectedCourse', courseCode);

    // Switch to evaluation form view
    switchView('evaluationForm');

    // Update navigation active state
    const navLinks = document.querySelectorAll('.nav-link:not(.logout)');
    navLinks.forEach(link => {
        link.classList.remove('active');
        if (link.getAttribute('data-view') === 'evaluationForm') {
            link.classList.add('active');
        }
    });

    // Pre-fill professor selection
    prefillProfessorSelection();
}

/**
 * Setup submit new evaluation button
 */
function setupSubmitNewButton() {
    const submitNewBtn = document.querySelector('.btn-submit-new');

    if (submitNewBtn) {
        submitNewBtn.addEventListener('click', function () {
            handleSubmitNewEvaluation();
        });
    }
}

/**
 * Handle submit new evaluation action
 */
function handleSubmitNewEvaluation() {
    // ── Evaluation period gate ──
    if (!SharedData.isEvalPeriodOpen('student-professor')) {
        const dates = SharedData.getEvalPeriodDates('student-professor');
        let msg = 'The Student to Professor evaluation period is not currently open.';
        if (dates.start && dates.end) {
            msg += '\nEvaluation period: ' + dates.start + ' to ' + dates.end + '.';
        } else {
            msg += '\nNo evaluation period has been set by the administrator yet.';
        }
        alert(msg);
        return;
    }

    // Switch to evaluation form view
    switchView('evaluationForm');

    // Update navigation active state
    updateNavigation('evaluationForm');

    // Scroll to top
    window.scrollTo(0, 0);
}

/**
 * Update summary cards with dynamic data
 */
function updateSummaryCards() {
    // Count pending and completed evaluations
    const pendingCount = document.querySelectorAll('.status-badge.pending').length;
    const completedCount = document.querySelectorAll('.status-badge.completed').length;
    const totalCount = pendingCount + completedCount;

    // Update card numbers
    const pendingCard = document.querySelector('.summary-card.pending .card-number');
    const completedCard = document.querySelector('.summary-card.completed .card-number');
    const totalCard = document.querySelector('.summary-card.total .card-number');

    if (pendingCard) pendingCard.textContent = pendingCount;
    if (completedCard) completedCard.textContent = completedCount;
    if (totalCard) totalCard.textContent = totalCount;
}

/**
 * Refresh evaluation list (for future use)
 */
function refreshEvaluationList() {
    // Placeholder for future API call to refresh evaluations

    // Future: Fetch from API and update DOM
    // fetchEvaluations().then(data => {
    //     renderEvaluations(data);
    // });
}

/**
 * Setup evaluation form functionality
 */
function setupEvaluationForm() {
    const form = document.getElementById('evaluationForm');
    if (!form) return;

    setupFormSubmission();
    setupCancelButton();
    if (typeof setupRatingInputs === 'function') {
        setupRatingInputs();
    }
}

/**
 * Load dynamic questionnaire from SharedData
 */
function loadDynamicQuestionnaire() {
    const container = document.getElementById('dynamic-questions-container');
    if (!container) return;

    const currentSemester = SharedData.getCurrentSemester();
    const questionnaires = SharedData.getQuestionnaires();

    console.log('[Student] loadDynamicQuestionnaire — semester:', JSON.stringify(currentSemester), '| available keys:', Object.keys(questionnaires));

    let data = null;
    if (currentSemester && questionnaires[currentSemester]) {
        data = questionnaires[currentSemester];
    } else {
        // Find latest available semester as fallback
        const semesters = Object.keys(questionnaires).sort().reverse();
        if (semesters.length > 0) {
            data = questionnaires[semesters[0]];
        } else {
            data = {};
        }
    }

    const questionnaire = data['student-to-professor'] || { sections: [], questions: [], header: {} };

    // Update headers if they exist
    const dynamicTitle = document.getElementById('dynamic-form-title');
    const dynamicDesc = document.getElementById('dynamic-form-description');

    if (dynamicTitle && questionnaire.header && questionnaire.header.title) {
        dynamicTitle.textContent = questionnaire.header.title;
    } else if (dynamicTitle) {
        dynamicTitle.textContent = 'Student Professor Evaluation Form';
    }

    if (dynamicDesc && questionnaire.header && questionnaire.header.description) {
        dynamicDesc.textContent = questionnaire.header.description;
    } else if (dynamicDesc) {
        dynamicDesc.textContent = 'Please rate your Professor honestly and constructively. Your feedback helps improve the quality of education.';
    }

    if ((!questionnaire.sections || questionnaire.sections.length === 0) && (!questionnaire.questions || questionnaire.questions.length === 0)) {
        container.innerHTML = `
            <div class="empty-state" style="text-align: center; padding: 3rem 1rem;">
                <i class="fas fa-clipboard-list" style="font-size: 3rem; color: #cbd5e1; margin-bottom: 1rem;"></i>
                <p>No evaluation questionnaire available for this semester.</p>
            </div>
        `;
        return;
    }

    const sortedSections = [...(questionnaire.sections || [])].sort((a, b) => (a.letter || '').localeCompare(b.letter || ''));

    let html = '';
    let globalIndex = 0;

    sortedSections.forEach(section => {
        const sectionQuestions = (questionnaire.questions || [])
            .filter(q => q.sectionId === section.id)
            .sort((a, b) => (a.order || 0) - (b.order || 0));

        html += `
            <div class="question-section">
                <div class="section-header">
                    <div class="section-title-group">
                        <h2 class="section-letter">${section.letter}.</h2>
                        <div class="section-title-content">
                            <h2 class="section-title">${section.title}</h2>
                            <p class="section-description">${section.description}</p>
                        </div>
                    </div>
                </div>
                <div class="section-questions">
        `;

        if (sectionQuestions.length > 0) {
            sectionQuestions.forEach(question => {
                globalIndex++;
                html += renderQuestionHTML(question, globalIndex);
            });
        }

        html += `
                </div>
            </div>
        `;
    });

    const questionsWithoutSection = (questionnaire.questions || []).filter(q => !q.sectionId).sort((a, b) => (a.order || 0) - (b.order || 0));
    if (questionsWithoutSection.length > 0) {
        html += `
            <div class="form-section">
                <h3 class="section-title">General Questions</h3>
        `;
        questionsWithoutSection.forEach(question => {
            globalIndex++;
            html += renderQuestionHTML(question, globalIndex);
        });
        html += `</div>`;
    }
    container.innerHTML = html;

    if (typeof setupRatingInputs === 'function') {
        setupRatingInputs();
    }
}

/**
 * Render single question HTML snippet
 */
function renderQuestionHTML(question, index) {
    if (question.type === 'rating') {
        const maxRating = parseInt(question.ratingScale.split('-')[1]) || 5;
        let ratingHtml = `
            <div class="question-group">
                <label class="question-label">${index}. ${question.text}${question.required ? ' <span style="color:red">*</span>' : ''}</label>
                <div class="rating-scale">
        `;

        for (let i = 1; i <= maxRating; i++) {
            ratingHtml += `
                    <input type="radio" name="${question.id}" id="${question.id}-${i}" value="${i}" ${question.required ? 'required' : ''}>
                    <label for="${question.id}-${i}" class="rating-option">${i}</label>
            `;
        }

        ratingHtml += `
                </div>
                <p class="rating-legend">${maxRating} = Excellent, 1 = Poor</p>
            </div>
        `;
        return ratingHtml;
    } else {
        return `
            <div class="question-group">
                <label class="question-label" for="${question.id}">${index}. ${question.text}${question.required ? ' <span style="color:red">*</span>' : ''}</label>
                <textarea id="${question.id}" name="${question.id}" class="form-textarea" rows="4" placeholder="Your answer..." ${question.required ? 'required' : ''}></textarea>
            </div>
        `;
    }
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
        showErrorMessage('Please fill out all password fields.');
        return;
    }

    if (newPassword !== confirmPassword) {
        showErrorMessage('New password and confirmation do not match.');
        return;
    }

    const payload = {
        username: getUserSession() ? getUserSession().username : '',
        currentPassword,
        newPassword
    };

    showSuccessMessage('Password update request ready for SQL connection.');

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
 * Setup history view (filters + search + read-only answers)
 */
function setupHistoryView() {
    const historyList = document.getElementById('historyList');
    const filterBtn = document.getElementById('historyFilterBtn');
    const aySelect = document.getElementById('historyAy');
    const semSelect = document.getElementById('historySem');
    const searchInput = document.getElementById('historySearch');
    const modal = document.getElementById('historyModal');
    const modalClose = document.getElementById('historyModalClose');

    if (!historyList || !filterBtn || !aySelect || !semSelect || !searchInput) {
        return;
    }

    const applyFilters = () => {
        const filters = {
            ay: aySelect.value,
            sem: semSelect.value,
            term: searchInput.value.trim()
        };
        loadHistory(filters);
    };

    filterBtn.addEventListener('click', applyFilters);
    aySelect.addEventListener('change', applyFilters);
    semSelect.addEventListener('change', applyFilters);
    searchInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            applyFilters();
        }
    });

    if (modal && modalClose) {
        modalClose.addEventListener('click', closeHistoryModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal) closeHistoryModal();
        });
    }

    // Initial load
    applyFilters();
}

let cachedHistoryRecords = [];

/**
 * Load history records (SQL-ready placeholder)
 */
function loadHistory(filters) {
    const session = getUserSession();
    const studentId = session ? session.username : '';

    fetchHistoryFromSql({
        studentId,
        ay: filters.ay,
        sem: filters.sem,
        term: filters.term
    }).then(records => {
        cachedHistoryRecords = records;
        renderHistoryList(records);
    }).catch(() => {
        renderHistoryList([]);
    });
}

/**
 * SQL-ready fetch (replace with real API call)
 */
function fetchHistoryFromSql(query) {
    // Example SQL-backed API request:
    // return fetch('/api/student/history', {
    //     method: 'POST',
    //     headers: { 'Content-Type': 'application/json' },
    //     body: JSON.stringify(query)
    // }).then(res => res.json());

    // SQL-ready: return empty until real API is connected
    const mockRecords = [];

    const filtered = mockRecords.filter(record => {
        const ayMatch = query.ay === 'all' || record.ay === query.ay;
        const semMatch = query.sem === 'all' || record.sem === query.sem;
        const term = (query.term || '').toLowerCase();
        const termMatch = !term ||
            record.faculty.toLowerCase().includes(term) ||
            record.subject.toLowerCase().includes(term);
        const studentMatch = query.studentId ? true : true;
        return ayMatch && semMatch && termMatch && studentMatch;
    });

    return Promise.resolve(filtered);
}

/**
 * Render history list
 */
function renderHistoryList(records) {
    const historyList = document.getElementById('historyList');
    if (!historyList) return;

    if (!records.length) {
        historyList.innerHTML = '<div class="history-empty">No submissions found for the selected filters.</div>';
        return;
    }

    historyList.innerHTML = records.map(record => `
        <div class="history-item">
            <div class="history-info">
                <div class="history-faculty">${record.faculty}</div>
                <div class="history-subject">${record.subject}</div>
                <div class="history-date">Submitted: ${record.submittedAt}</div>
            </div>
            <button class="btn-view-answers" data-id="${record.id}">View Answers</button>
        </div>
    `).join('');

    historyList.querySelectorAll('.btn-view-answers').forEach(button => {
        button.addEventListener('click', function () {
            const id = parseInt(this.getAttribute('data-id'), 10);
            const record = cachedHistoryRecords.find(item => item.id === id);
            if (record) openHistoryModal(record);
        });
    });
}

/**
 * Open history modal (read-only)
 */
function openHistoryModal(record) {
    const modal = document.getElementById('historyModal');
    const modalBody = document.getElementById('historyModalBody');
    if (!modal || !modalBody) return;

    modalBody.innerHTML = `
        <div class="answer-item">
            <h4>Faculty</h4>
            <p>${record.faculty}</p>
        </div>
        <div class="answer-item">
            <h4>Subject</h4>
            <p>${record.subject}</p>
        </div>
        ${record.answers.map(ans => `
            <div class="answer-item">
                <h4>${ans.question}</h4>
                <p>${ans.answer}</p>
            </div>
        `).join('')}
    `;

    modal.classList.add('active');
    modal.setAttribute('aria-hidden', 'false');
}

function closeHistoryModal() {
    const modal = document.getElementById('historyModal');
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
}

/**
 * Setup form submission
 */
function setupFormSubmission() {
    const form = document.getElementById('evaluationForm');
    if (!form) return;

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        handleFormSubmission();
    });
}

/**
 * Handle form submission
 */
function handleFormSubmission() {
    // ── Evaluation period gate ──
    if (!SharedData.isEvalPeriodOpen('student-professor')) {
        const dates = SharedData.getEvalPeriodDates('student-professor');
        let msg = 'The Student to Professor evaluation period is not currently open.';
        if (dates.start && dates.end) {
            msg += '\nEvaluation period: ' + dates.start + ' to ' + dates.end + '.';
        } else {
            msg += '\nNo evaluation period has been set by the administrator yet.';
        }
        showErrorMessage(msg);
        return;
    }

    const form = document.getElementById('evaluationForm');
    const submitBtn = form.querySelector('.btn-submit');
    const originalText = submitBtn.textContent;

    // Validate form
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    // Get form data
    const formData = collectFormData();

    // Show loading state
    submitBtn.disabled = true;
    submitBtn.textContent = 'Submitting...';

    // Simulate API call (replace with actual API call)
    setTimeout(() => {
        // Submit to API
        submitEvaluation(formData)
            .then(response => {
                showSuccessMessage('Evaluation submitted successfully!');
                // Reset form after short delay
                setTimeout(() => {
                    form.reset();
                    submitBtn.disabled = false;
                    submitBtn.textContent = originalText;
                    // Switch back to dashboard
                    switchView('dashboard');
                    updateNavigation('dashboard');
                    updateSummaryCards();
                }, 2000);
            })
            .catch(error => {
                showErrorMessage('Failed to submit evaluation. Please try again.');
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            });
    }, 1500);
}

/**
 * Collect form data
 * @returns {Object} - Form data object
 */
function collectFormData() {
    const form = document.getElementById('evaluationForm');
    const formData = new FormData(form);

    const ratingsGroup = {};
    const qualitativeGroup = {};

    // Get current questionnaire to tell apart rating vs. qualitative types
    const currentSemester = SharedData.getCurrentSemester();
    const questionnaires = SharedData.getQuestionnaires();
    const qData = questionnaires[currentSemester] || {};
    const questionnaire = qData['student-to-professor'] || { questions: [] };
    const allQuestions = questionnaire.questions || [];

    // Dynamically collect elements
    for (let [key, value] of formData.entries()) {
        if (key === 'professorSubject' || key === 'comments') continue;

        let questionDef = allQuestions.find(q => String(q.id) === key);
        if (questionDef && questionDef.type === 'qualitative') {
            qualitativeGroup[key] = value;
        } else {
            // Treat as rating or default
            ratingsGroup[key] = value;
        }
    }

    const data = {
        professorSubject: formData.get('professorSubject'),
        ratings: ratingsGroup,
        qualitative: qualitativeGroup,
        comments: formData.get('comments') || '',
        submittedAt: new Date().toISOString()
    };

    // Get user session
    const session = getUserSession();
    if (session) {
        data.studentId = session.username;
    }

    return data;
}

/**
 * Submit evaluation to API
 * @param {Object} data - Form data
 * @returns {Promise} - API response
 */
function submitEvaluation(data) {
    return new Promise((resolve, reject) => {
        try {
            // Get user session metadata
            const session = SharedData.getSession() || {};
            const evaluatorData = {
                evaluatorRole: 'student',
                evaluatorName: session.fullName || 'Anonymous Student',
                evaluatorUsername: session.username || 'unknown',
                evaluationType: 'student',
                ...data
            };

            // Save via centralized API
            SharedData.addEvaluation(evaluatorData);

            // Add to activity log
            SharedData.addActivityLogEntry({
                type: 'evaluation_submitted',
                title: 'Evaluation Submitted',
                user: evaluatorData.evaluatorName,
                role: 'student',
                date: new Date().toISOString()
            });

            setTimeout(() => {
                resolve({ success: true, message: 'Evaluation submitted successfully to local database' });
            }, 600); // UI feedback delay
        } catch (error) {
            reject(error);
        }
    });
}

/**
 * Setup cancel button
 */
function setupCancelButton() {
    const cancelBtn = document.getElementById('cancelBtn');
    if (!cancelBtn) return;

    cancelBtn.addEventListener('click', function () {
        if (confirm('Are you sure you want to cancel? All unsaved changes will be lost.')) {
            const form = document.getElementById('evaluationForm');
            if (form) {
                form.reset();
            }
            switchView('dashboard');
            updateNavigation('dashboard');
        }
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
 * Pre-fill professor selection if coming from "Start Evaluation" button
 */
function prefillProfessorSelection() {
    const selectedProfessor = sessionStorage.getItem('selectedProfessor');
    const selectedCourse = sessionStorage.getItem('selectedCourse');

    if (selectedProfessor && selectedCourse) {
        const select = document.getElementById('professorSubject');
        if (!select) return;

        const options = Array.from(select.options);

        // Try to find a match
        const match = options.find(option => {
            const text = option.text.toLowerCase();
            return text.includes(selectedProfessor.toLowerCase()) ||
                text.includes(selectedCourse.toLowerCase());
        });

        if (match) {
            select.value = match.value;
        }

        // Clear sessionStorage after use
        sessionStorage.removeItem('selectedProfessor');
        sessionStorage.removeItem('selectedCourse');
    }
}

/**
 * Setup rating inputs for better UX
 */
function setupRatingInputs() {
    const ratingInputs = document.querySelectorAll('.rating-scale input[type="radio"]');

    ratingInputs.forEach(input => {
        input.addEventListener('change', function () {
            // Add visual feedback
            const label = this.nextElementSibling;
            if (label) {
                // Animate the selected rating
                label.style.transform = 'scale(1.1)';
                setTimeout(() => {
                    label.style.transform = 'scale(1.05)';
                }, 200);
            }
        });
    });
}

/**
 * Show success message
 * @param {string} message - Success message
 */
function showSuccessMessage(message) {
    // Remove existing messages
    const existingMessage = document.querySelector('.form-message');
    if (existingMessage) {
        existingMessage.remove();
    }

    // Create success message
    const messageDiv = document.createElement('div');
    messageDiv.className = 'form-message success';
    messageDiv.textContent = message;
    messageDiv.style.cssText = `
        background-color: #d1fae5;
        color: #065f46;
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        text-align: center;
        font-weight: 600;
        border: 1px solid #10b981;
        animation: fadeIn 0.3s ease;
    `;

    const form = document.getElementById('evaluationForm');
    if (form) {
        form.insertBefore(messageDiv, form.firstChild);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            messageDiv.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => messageDiv.remove(), 300);
        }, 5000);
    }
}

/**
 * Show error message
 * @param {string} message - Error message
 */
function showErrorMessage(message) {
    // Remove existing messages
    const existingMessage = document.querySelector('.form-message');
    if (existingMessage) {
        existingMessage.remove();
    }

    // Create error message
    const messageDiv = document.createElement('div');
    messageDiv.className = 'form-message error';
    messageDiv.textContent = message;
    messageDiv.style.cssText = `
        background-color: #fee2e2;
        color: #991b1b;
        padding: 16px 20px;
        border-radius: 12px;
        margin-bottom: 24px;
        text-align: center;
        font-weight: 600;
        border: 1px solid #ef4444;
        animation: fadeIn 0.3s ease;
    `;

    const form = document.getElementById('evaluationForm');
    if (form) {
        form.insertBefore(messageDiv, form.firstChild);

        // Auto-remove after 5 seconds
        setTimeout(() => {
            messageDiv.style.animation = 'fadeOut 0.3s ease';
            setTimeout(() => messageDiv.remove(), 300);
        }, 5000);
    }
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
        handleStartEvaluation,
        handleSubmitNewEvaluation,
        updateSummaryCards
    };
}

