(function () {
    'use strict';

    const REPORT_TYPES = ['Bug', 'System Error', 'Wrong Data', 'Suggestion', 'Other'];
    const MODAL_ID = 'system-report-modal';
    const STATUS_ID = 'system-report-status';

    function onReady(callback) {
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', callback);
        } else {
            callback();
        }
    }

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function getDirectActionContainer(header) {
        const children = Array.from(header.children || []);
        return children.find(child => child.classList && child.classList.contains('header-right'))
            || children.find(child => child.classList && child.classList.contains('user-info'))
            || null;
    }

    function ensureActionContainer(header) {
        let container = getDirectActionContainer(header);
        if (container) {
            return container;
        }

        container = document.createElement('div');
        container.className = 'header-right';

        const movable = Array.from(header.children || []).filter(child => {
            if (!child.classList) return false;
            return child.classList.contains('user-profile')
                || child.classList.contains('notification-wrapper')
                || child.classList.contains('notification-icon')
                || child.classList.contains('user-action-btn');
        });

        movable.forEach(child => container.appendChild(child));
        header.appendChild(container);
        return container;
    }

    function createReportButton() {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'user-action-btn system-report-btn';
        button.setAttribute('aria-label', 'Report an issue');
        button.setAttribute('title', 'Report an issue');
        button.innerHTML = '<i class="fas fa-flag" aria-hidden="true"></i>';
        button.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            openReportModal(button);
        });
        return button;
    }

    function insertReportButton(header, container) {
        if (container.querySelector('.system-report-btn')) {
            return;
        }

        const button = createReportButton();
        const children = Array.from(container.children || []);
        const notification = children.find(child => {
            if (!child.classList) return false;
            return child.classList.contains('notification-wrapper')
                || child.classList.contains('notification-icon')
                || child.classList.contains('js-notification-btn')
                || child.id === 'notificationBtn'
                || child.id === 'notification-icon';
        });
        const profile = children.find(child => child.classList && child.classList.contains('user-profile'));

        if (notification) {
            container.insertBefore(button, notification.nextSibling);
        } else if (profile) {
            container.insertBefore(button, profile);
        } else {
            container.appendChild(button);
        }

        header.classList.add('has-system-report-button');
    }

    function setupReportButtons() {
        document.querySelectorAll('.top-header').forEach(header => {
            const container = ensureActionContainer(header);
            container.classList.add('system-report-actions');
            insertReportButton(header, container);
        });
    }

    function buildReportModalMarkup() {
        const options = REPORT_TYPES.map(type => `<option value="${escapeHtml(type)}">${escapeHtml(type)}</option>`).join('');
        return [
            '<div class="system-report-dialog" role="dialog" aria-modal="true" aria-labelledby="system-report-title">',
            '  <div class="system-report-header">',
            '    <div>',
            '      <h2 id="system-report-title">Report an Issue</h2>',
            '      <p id="system-report-context"></p>',
            '    </div>',
            '    <button type="button" class="system-report-close" aria-label="Close report form">',
            '      <i class="fas fa-times" aria-hidden="true"></i>',
            '    </button>',
            '  </div>',
            `  <form class="system-report-form" id="system-report-form" novalidate>`,
            '    <label for="system-report-type">Report Type</label>',
            `    <select id="system-report-type" name="reportType">${options}</select>`,
            '    <label for="system-report-subject">Subject</label>',
            '    <input id="system-report-subject" name="subject" type="text" maxlength="200" autocomplete="off" required>',
            '    <label for="system-report-message">Message</label>',
            '    <textarea id="system-report-message" name="message" rows="6" maxlength="6000" required></textarea>',
            `    <div class="system-report-status" id="${STATUS_ID}" aria-live="polite"></div>`,
            '    <div class="system-report-actions-row">',
            '      <button type="button" class="system-report-secondary" data-report-cancel>Cancel</button>',
            '      <button type="submit" class="system-report-primary">Send Report</button>',
            '    </div>',
            '  </form>',
            '</div>',
        ].join('');
    }

    function ensureReportModal() {
        let modal = document.getElementById(MODAL_ID);
        if (modal) {
            return modal;
        }

        modal = document.createElement('div');
        modal.id = MODAL_ID;
        modal.className = 'system-report-modal';
        modal.hidden = true;
        modal.innerHTML = buildReportModalMarkup();
        document.body.appendChild(modal);

        modal.querySelector('.system-report-close').addEventListener('click', closeReportModal);
        modal.querySelector('[data-report-cancel]').addEventListener('click', closeReportModal);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) {
                closeReportModal();
            }
        });

        modal.querySelector('#system-report-form').addEventListener('submit', handleReportSubmit);
        return modal;
    }

    function getHeaderTitle(trigger) {
        const header = trigger && trigger.closest ? trigger.closest('.top-header') : null;
        const title = header ? header.querySelector('.page-title') : null;
        return title ? String(title.textContent || '').trim() : '';
    }

    function getPageContext(trigger) {
        const headerTitle = getHeaderTitle(trigger);
        const activeNav = document.querySelector('.nav-link.active span, .nav-link[aria-current="page"] span');
        const activeText = activeNav ? String(activeNav.textContent || '').trim() : '';
        return headerTitle || activeText || String(document.title || '').trim() || 'Current page';
    }

    function setStatus(type, message) {
        const status = document.getElementById(STATUS_ID);
        if (!status) return;
        status.className = 'system-report-status';
        if (type) {
            status.classList.add('is-' + type);
        }
        status.textContent = message || '';
    }

    function setBusy(isBusy) {
        const modal = document.getElementById(MODAL_ID);
        if (!modal) return;

        modal.querySelectorAll('input, select, textarea, button').forEach(control => {
            control.disabled = !!isBusy;
        });
        const submit = modal.querySelector('.system-report-primary');
        if (submit) {
            submit.innerHTML = isBusy
                ? '<i class="fas fa-spinner fa-spin" aria-hidden="true"></i><span>Sending...</span>'
                : '<span>Send Report</span>';
        }
    }

    function openReportModal(trigger) {
        const modal = ensureReportModal();
        const context = getPageContext(trigger);
        modal.dataset.pageTitle = context;
        closeHeaderDropdowns();

        const contextEl = modal.querySelector('#system-report-context');
        if (contextEl) {
            contextEl.textContent = context;
        }

        const form = modal.querySelector('#system-report-form');
        if (form) {
            form.reset();
        }
        setStatus('', '');
        modal.hidden = false;
        modal.classList.add('is-open');

        window.setTimeout(function () {
            const subject = modal.querySelector('#system-report-subject');
            if (subject) {
                subject.focus();
            }
        }, 0);
    }

    function closeHeaderDropdowns() {
        document.querySelectorAll('.dropdown-panel.active, .notification-dropdown.active').forEach(panel => {
            panel.classList.remove('active');
            panel.setAttribute('aria-hidden', 'true');
        });
    }

    function closeReportModal() {
        const modal = document.getElementById(MODAL_ID);
        if (!modal) return;
        modal.classList.remove('is-open');
        modal.hidden = true;
        setBusy(false);
        setStatus('', '');
    }

    function getSession() {
        const sharedData = typeof SharedData !== 'undefined' ? SharedData : null;
        if (!sharedData || typeof sharedData.getSession !== 'function') {
            return null;
        }
        return sharedData.getSession();
    }

    function buildPayload(modal, form) {
        const formData = new FormData(form);
        const pageTitle = String(modal.dataset.pageTitle || getPageContext(null)).trim();
        return {
            reportType: String(formData.get('reportType') || 'Other').trim(),
            subject: String(formData.get('subject') || '').trim(),
            message: String(formData.get('message') || '').trim(),
            pageTitle: pageTitle,
            pageUrl: window.location && window.location.href ? window.location.href : '',
            userAgent: typeof navigator !== 'undefined' && navigator.userAgent ? navigator.userAgent : '',
        };
    }

    function validatePayload(payload) {
        if (!payload.subject) {
            return 'Report subject is required.';
        }
        if (!payload.message) {
            return 'Report message is required.';
        }
        if (payload.subject.length > 200) {
            return 'Report subject must be 200 characters or fewer.';
        }
        if (payload.message.length > 6000) {
            return 'Report message must be 6000 characters or fewer.';
        }
        return '';
    }

    function handleReportSubmit(event) {
        event.preventDefault();

        const modal = ensureReportModal();
        const form = event.currentTarget;
        const session = getSession();
        if (!session || session.isAuthenticated !== true) {
            setStatus('error', 'Please log in before sending a report.');
            return;
        }
        const sharedData = typeof SharedData !== 'undefined' ? SharedData : null;
        if (!sharedData || typeof sharedData.submitSystemReport !== 'function') {
            setStatus('error', 'Report service is unavailable.');
            return;
        }

        const payload = buildPayload(modal, form);
        const validationError = validatePayload(payload);
        if (validationError) {
            setStatus('error', validationError);
            return;
        }

        setBusy(true);
        setStatus('info', 'Sending report...');

        let request;
        try {
            request = sharedData.submitSystemReport(payload);
        } catch (error) {
            setStatus('error', error && error.message ? error.message : 'Report could not be sent.');
            setBusy(false);
            return;
        }

        Promise.resolve(request)
            .then(function (result) {
                if (result && result.success) {
                    const code = result.reportCode ? ' Reference: ' + result.reportCode + '.' : '';
                    setStatus('success', 'Report sent successfully.' + code);
                    form.reset();
                    return;
                }

                const message = result && result.error
                    ? result.error
                    : 'Report was saved, but the email could not be sent.';
                setStatus('error', message);
            })
            .catch(function (error) {
                setStatus('error', error && error.message ? error.message : 'Report could not be sent.');
            })
            .finally(function () {
                setBusy(false);
            });
    }

    onReady(function () {
        setupReportButtons();
        ensureReportModal();

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeReportModal();
            }
        });
    });
})();
