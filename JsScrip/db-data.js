/**
 * Database-backed SharedData compatibility layer.
 * Replaces localStorage app-state persistence with PHP/MySQL persistence.
 */

(function initAppLoadingOverlay() {
    if (typeof window === 'undefined' || window.AppLoadingOverlay) {
        return;
    }

    const DEFAULT_MESSAGE = 'Loading, please wait...';
    const NETWORK_MESSAGE = 'Processing request...';
    const NETWORK_OVERLAY_DELAY_MS = 350;
    const DEDICATED_OVERLAY_IDS = ['bulk-register-loading', 'credential-distributor-loading'];

    function buildHourglassMarkup(size) {
        const normalizedSize = String(size || 'compact').trim().toLowerCase();
        const allowedSize = ['compact', 'small', 'tiny'].indexOf(normalizedSize) !== -1
            ? normalizedSize
            : 'compact';
        return [
            '<div class="loading-hourglass-frame loading-hourglass-frame--' + allowedSize + '">',
            '  <div class="hourglassBackground">',
            '    <div class="hourglassContainer">',
            '      <div class="hourglassCurves"></div>',
            '      <div class="hourglassCapTop"></div>',
            '      <div class="hourglassGlassTop"></div>',
            '      <div class="hourglassSand"></div>',
            '      <div class="hourglassSandStream"></div>',
            '      <div class="hourglassCapBottom"></div>',
            '      <div class="hourglassGlass"></div>',
            '    </div>',
            '  </div>',
            '</div>'
        ].join('');
    }

    const HOURGLASS_MARKUP = buildHourglassMarkup('compact');

    const state = {
        manualInFlight: 0,
        networkInFlight: 0,
        networkVisible: false,
        manualMessage: '',
        networkMessage: NETWORK_MESSAGE,
    };

    let overlayEl = null;
    let textEl = null;
    let networkOverlayTimer = null;
    let dedicatedOverlayObserver = null;

    function normalizeMessage(value, fallback) {
        const message = String(value || '').trim();
        return message || fallback;
    }

    function isDedicatedOverlayActive() {
        return DEDICATED_OVERLAY_IDS.some(function (id) {
            const element = document.getElementById(id);
            return !!(element && element.classList && element.classList.contains('active'));
        });
    }

    function ensureOverlayElement() {
        if (overlayEl && document.body && document.body.contains(overlayEl)) {
            return overlayEl;
        }
        if (!document.body) {
            return null;
        }

        overlayEl = document.getElementById('app-global-loading-overlay');
        if (!overlayEl) {
            overlayEl = document.createElement('div');
            overlayEl.id = 'app-global-loading-overlay';
            overlayEl.className = 'app-loading-overlay';
            overlayEl.setAttribute('aria-hidden', 'true');
            overlayEl.innerHTML = [
                '<div class="app-loading-overlay-card" role="status" aria-live="polite" aria-label="Loading in progress">',
                HOURGLASS_MARKUP,
                '  <p class="app-loading-overlay-text" id="app-global-loading-text">Loading, please wait...</p>',
                '</div>'
            ].join('');
            document.body.appendChild(overlayEl);
        }

        textEl = overlayEl.querySelector('#app-global-loading-text');
        return overlayEl;
    }

    function renderOverlay() {
        const overlay = ensureOverlayElement();
        if (!overlay) {
            return;
        }

        const hasActivity = state.manualInFlight > 0 || (state.networkInFlight > 0 && state.networkVisible);
        const shouldShow = hasActivity && !isDedicatedOverlayActive();
        const message = state.manualMessage || state.networkMessage || DEFAULT_MESSAGE;

        overlay.classList.toggle('active', shouldShow);
        overlay.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
        if (textEl) {
            textEl.textContent = normalizeMessage(message, DEFAULT_MESSAGE);
        }
    }

    function show(message) {
        state.manualInFlight += 1;
        if (typeof message === 'string' && message.trim() !== '') {
            state.manualMessage = message.trim();
        }
        renderOverlay();
    }

    function hide() {
        state.manualInFlight = Math.max(0, state.manualInFlight - 1);
        if (state.manualInFlight === 0) {
            state.manualMessage = '';
        }
        renderOverlay();
    }

    function beginNetworkRequest(url) {
        if (isDedicatedOverlayActive()) {
            return false;
        }
        state.networkInFlight += 1;
        state.networkMessage = NETWORK_MESSAGE;
        if (!state.networkVisible && !networkOverlayTimer) {
            networkOverlayTimer = setTimeout(function () {
                networkOverlayTimer = null;
                if (state.networkInFlight > 0) {
                    state.networkVisible = true;
                    renderOverlay();
                }
            }, NETWORK_OVERLAY_DELAY_MS);
        }
        renderOverlay();
        return true;
    }

    function endNetworkRequest() {
        state.networkInFlight = Math.max(0, state.networkInFlight - 1);
        if (state.networkInFlight === 0) {
            if (networkOverlayTimer) {
                clearTimeout(networkOverlayTimer);
                networkOverlayTimer = null;
            }
            state.networkVisible = false;
            state.networkMessage = NETWORK_MESSAGE;
        }
        renderOverlay();
    }

    function resolveRequestUrl(input) {
        if (typeof input === 'string') {
            return input;
        }
        if (input && typeof input.url === 'string') {
            return input.url;
        }
        return '';
    }

    function isApiRequestUrl(url) {
        const raw = String(url || '').trim();
        if (!raw) {
            return false;
        }
        try {
            const parsed = new URL(raw, window.location.href);
            if (parsed.searchParams.get('_heartbeat') === '1') {
                return false;
            }
            if (parsed.searchParams.get('_background') === '1') {
                return false;
            }
            return /\/api\//i.test(parsed.pathname);
        } catch (_error) {
            if (/[?&]_background=1(?:&|$)/.test(raw) || /[?&]_heartbeat=1(?:&|$)/.test(raw)) {
                return false;
            }
            return /\/api\//i.test(raw);
        }
    }

    function patchFetch() {
        const nativeFetch = window.fetch;
        if (typeof nativeFetch !== 'function' || nativeFetch.__appLoadingPatched) {
            return;
        }

        const patchedFetch = function () {
            const url = resolveRequestUrl(arguments[0]);
            const shouldTrack = isApiRequestUrl(url);
            const tracked = shouldTrack ? beginNetworkRequest(url) : false;

            let requestPromise;
            try {
                requestPromise = nativeFetch.apply(this, arguments);
            } catch (error) {
                if (tracked) {
                    endNetworkRequest();
                }
                throw error;
            }

            if (!tracked) {
                return requestPromise;
            }

            return Promise.resolve(requestPromise).finally(function () {
                endNetworkRequest();
            });
        };

        patchedFetch.__appLoadingPatched = true;
        window.fetch = patchedFetch;
    }

    function finalizeTrackedXhr(xhr) {
        if (!xhr || !xhr.__appLoadingTracked || xhr.__appLoadingCompleted) {
            return;
        }

        xhr.__appLoadingCompleted = true;
        if (typeof xhr.__appLoadingCleanup === 'function') {
            xhr.__appLoadingCleanup();
        }
        xhr.__appLoadingCleanup = null;
        endNetworkRequest();
    }

    function patchXmlHttpRequest() {
        const xhrProto = window.XMLHttpRequest && window.XMLHttpRequest.prototype;
        if (!xhrProto || xhrProto.__appLoadingPatched) {
            return;
        }

        const nativeOpen = xhrProto.open;
        const nativeSend = xhrProto.send;
        const nativeAbort = xhrProto.abort;

        xhrProto.open = function (method, url, async) {
            this.__appLoadingUrl = url;
            this.__appLoadingShouldTrack = isApiRequestUrl(url);
            this.__appLoadingTracked = false;
            this.__appLoadingCompleted = false;
            this.__appLoadingAsync = async !== false;
            return nativeOpen.apply(this, arguments);
        };

        xhrProto.send = function () {
            if (this.__appLoadingShouldTrack && !this.__appLoadingTracked) {
                this.__appLoadingTracked = beginNetworkRequest(this.__appLoadingUrl);

                if (this.__appLoadingTracked) {
                    const xhr = this;
                    const onLoadEnd = function () {
                        finalizeTrackedXhr(xhr);
                    };
                    const onError = function () {
                        finalizeTrackedXhr(xhr);
                    };
                    const onAbort = function () {
                        finalizeTrackedXhr(xhr);
                    };

                    xhr.addEventListener('loadend', onLoadEnd);
                    xhr.addEventListener('error', onError);
                    xhr.addEventListener('abort', onAbort);

                    xhr.__appLoadingCleanup = function () {
                        xhr.removeEventListener('loadend', onLoadEnd);
                        xhr.removeEventListener('error', onError);
                        xhr.removeEventListener('abort', onAbort);
                    };
                }
            }

            try {
                const result = nativeSend.apply(this, arguments);
                if (this.__appLoadingTracked && this.__appLoadingAsync === false && this.readyState === 4) {
                    finalizeTrackedXhr(this);
                }
                return result;
            } catch (error) {
                finalizeTrackedXhr(this);
                throw error;
            }
        };

        xhrProto.abort = function () {
            try {
                return nativeAbort.apply(this, arguments);
            } finally {
                finalizeTrackedXhr(this);
            }
        };

        xhrProto.__appLoadingPatched = true;
    }

    function watchDedicatedOverlays() {
        if (typeof MutationObserver === 'undefined') {
            return;
        }
        if (dedicatedOverlayObserver) {
            dedicatedOverlayObserver.disconnect();
        }

        dedicatedOverlayObserver = new MutationObserver(function () {
            renderOverlay();
        });

        DEDICATED_OVERLAY_IDS.forEach(function (id) {
            const element = document.getElementById(id);
            if (element) {
                dedicatedOverlayObserver.observe(element, {
                    attributes: true,
                    attributeFilter: ['class', 'aria-hidden'],
                });
            }
        });
    }

    window.AppLoadingOverlay = {
        show: show,
        hide: hide,
        _trackNetworkStart: beginNetworkRequest,
        _trackNetworkEnd: endNetworkRequest,
    };
    window.AppHourglassMarkup = buildHourglassMarkup;

    patchFetch();
    patchXmlHttpRequest();

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            ensureOverlayElement();
            watchDedicatedOverlays();
            renderOverlay();
        });
    } else {
        ensureOverlayElement();
        watchDedicatedOverlays();
        renderOverlay();
    }
})();

window.StudentEvaluationReminderSettings = window.StudentEvaluationReminderSettings || (() => {
    const DEFAULT_CONFIG = {
        enabled: true,
        frequencyDays: 7,
        sendTime: '07:00',
        subject: 'NAAP Evaluation Reminder: Please Complete Your Evaluation',
        body: 'Please complete your evaluation while the student evaluation period is open.\n'
            + 'Log in to the NAAP Evaluation System and submit your pending evaluation today.',
    };
    const ALLOWED_PLACEHOLDERS = [
        'student_name',
        'evaluation_end_date',
        'academic_year',
        'semester',
    ];
    let bound = false;

    function getElements() {
        return {
            enabled: document.getElementById('student-eval-reminder-enabled'),
            frequency: document.getElementById('reminder-freq'),
            sendTime: document.getElementById('student-eval-reminder-time'),
            subject: document.getElementById('student-eval-reminder-subject'),
            body: document.getElementById('student-eval-reminder-body'),
            reset: document.getElementById('student-eval-reminder-reset-btn'),
            save: document.getElementById('student-eval-reminder-save-btn'),
            status: document.getElementById('student-eval-reminder-status'),
        };
    }

    function setStatus(element, message, type) {
        if (!element) return;
        element.textContent = String(message || '');
        element.classList.remove('is-success', 'is-error', 'is-info');
        if (type) element.classList.add('is-' + type);
    }

    function render(config, elements) {
        const value = Object.assign({}, DEFAULT_CONFIG, config || {});
        elements.enabled.checked = value.enabled === true;
        elements.frequency.value = String(Number(value.frequencyDays) || DEFAULT_CONFIG.frequencyDays);
        elements.sendTime.value = String(value.sendTime || DEFAULT_CONFIG.sendTime);
        elements.subject.value = String(value.subject || DEFAULT_CONFIG.subject);
        elements.body.value = String(value.body || DEFAULT_CONFIG.body);
    }

    function validatePlaceholders(value, label) {
        const source = String(value || '');
        const pattern = /\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/gi;
        let match;
        while ((match = pattern.exec(source)) !== null) {
            const placeholder = String(match[1] || '').toLowerCase();
            if (!ALLOWED_PLACEHOLDERS.includes(placeholder)) {
                throw new Error(label + ' contains an unsupported placeholder: {{' + placeholder + '}}.');
            }
        }
        const remaining = source.replace(pattern, '');
        if (remaining.includes('{{') || remaining.includes('}}')) {
            throw new Error(label + ' contains an invalid placeholder.');
        }
    }

    function collect(elements) {
        const frequencyDays = Number(elements.frequency.value);
        if (!Number.isInteger(frequencyDays) || frequencyDays < 1 || frequencyDays > 365) {
            throw new Error('Reminder frequency must be a whole number between 1 and 365 days.');
        }

        const sendTime = String(elements.sendTime.value || '');
        if (!/^(?:[01][0-9]|2[0-3]):[0-5][0-9]$/.test(sendTime)) {
            throw new Error('Select a valid reminder send time.');
        }

        const subject = String(elements.subject.value || '').trim();
        const body = String(elements.body.value || '').trim();
        if (!subject) throw new Error('Reminder email subject is required.');
        if (!body) throw new Error('Reminder email message is required.');
        if (subject.length > 200) throw new Error('Reminder email subject must not exceed 200 characters.');
        if (/\r|\n/.test(subject)) throw new Error('Reminder email subject must be a single line.');
        if (body.length > 6000) throw new Error('Reminder email message must not exceed 6,000 characters.');
        validatePlaceholders(subject, 'Reminder email subject');
        validatePlaceholders(body, 'Reminder email message');

        return {
            enabled: elements.enabled.checked === true,
            frequencyDays,
            sendTime,
            subject,
            body,
        };
    }

    function setup() {
        if (bound) return;
        const elements = getElements();
        if (!elements.enabled || !elements.frequency || !elements.sendTime || !elements.subject || !elements.body
            || !elements.reset || !elements.save) {
            return;
        }
        bound = true;

        render(SharedData.getStudentEvaluationReminderConfig(), elements);
        SharedData.onDataChange(function (key, value) {
            if (key === SharedData.KEYS.STUDENT_EVAL_REMINDER_CONFIG && value && typeof value === 'object') {
                render(value, elements);
            }
        });

        elements.reset.addEventListener('click', function () {
            render(DEFAULT_CONFIG, elements);
            setStatus(elements.status, 'Defaults restored locally. Save to apply them.', 'info');
        });

        elements.save.addEventListener('click', async function () {
            let config;
            try {
                config = collect(elements);
            } catch (error) {
                setStatus(elements.status, error && error.message ? error.message : 'Invalid reminder settings.', 'error');
                return;
            }

            const originalText = elements.save.textContent;
            elements.save.disabled = true;
            elements.save.textContent = 'Saving...';
            setStatus(elements.status, 'Saving reminder settings...', 'info');
            try {
                const saved = await SharedData.updateStudentEvaluationReminderConfigAsync(config);
                render(saved, elements);
                setStatus(elements.status, 'Student evaluation reminder settings saved.', 'success');
            } catch (error) {
                setStatus(
                    elements.status,
                    error && error.message ? error.message : 'Failed to save reminder settings.',
                    'error'
                );
            } finally {
                elements.save.disabled = false;
                elements.save.textContent = originalText || 'Save Reminder Settings';
            }
        });
    }

    return { setup };
})();

window.AppChartDesign = window.AppChartDesign || (() => {
    const RATING_LABELS = ['5 Stars', '4 Stars', '3 Stars', '2 Stars', '1 Star'];
    const RATING_COLORS = ['#059669', '#22c55e', '#f59e0b', '#f97316', '#ef4444'];

    const centerTextPlugin = {
        id: 'appRatingDistributionCenterText',
        afterDraw(chart, args, pluginOptions) {
            const chartArea = chart.chartArea;
            if (!chartArea) return;

            const ctx = chart.ctx;
            const centerX = (chartArea.left + chartArea.right) / 2;
            const centerY = (chartArea.top + chartArea.bottom) / 2;
            const title = pluginOptions && pluginOptions.title ? pluginOptions.title : 'No data';
            const subtitle = pluginOptions && pluginOptions.subtitle ? pluginOptions.subtitle : '0 ratings';
            const muted = Boolean(pluginOptions && pluginOptions.muted);
            const titleFontSize = Number(pluginOptions && pluginOptions.titleFontSize) || 24;
            const subtitleFontSize = Number(pluginOptions && pluginOptions.subtitleFontSize) || 11;
            const titleOffset = Number(pluginOptions && pluginOptions.titleOffset);
            const subtitleOffset = Number(pluginOptions && pluginOptions.subtitleOffset);

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = muted ? '#94a3b8' : '#111827';
            ctx.font = `700 ${titleFontSize}px Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
            ctx.fillText(title, centerX, centerY + (Number.isFinite(titleOffset) ? titleOffset : -8));
            ctx.fillStyle = muted ? '#cbd5e1' : '#64748b';
            ctx.font = `600 ${subtitleFontSize}px Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif`;
            ctx.fillText(subtitle, centerX, centerY + (Number.isFinite(subtitleOffset) ? subtitleOffset : 14));
            ctx.restore();
        }
    };

    function renderRatingDistributionChart(canvas, options) {
        if (!canvas || typeof Chart === 'undefined') return null;

        const config = options || {};
        const sourceDistribution = config.ratingDistribution || {};
        const values = Array.isArray(config.values)
            ? config.values.map(value => Number(value) || 0)
            : [5, 4, 3, 2, 1].map(rating => Number(sourceDistribution[rating]) || 0);
        const total = values.reduce((sum, value) => sum + value, 0);
        const hasData = total > 0;
        const averageRating = Number(config.averageRating) || 0;
        const labels = config.labels || RATING_LABELS;
        const colors = config.colors || RATING_COLORS;
        const totalLabel = config.totalLabel || 'rating';

        const chartData = {
            labels: hasData ? labels : ['No ratings yet'],
            datasets: [{
                data: hasData ? values : [1],
                backgroundColor: hasData ? colors : ['#e5e7eb'],
                borderColor: '#ffffff',
                borderWidth: 4,
                borderRadius: hasData ? 10 : 0,
                hoverBorderWidth: 4,
                hoverOffset: hasData ? 12 : 0,
                spacing: hasData ? 3 : 0
            }]
        };
        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            cutout: config.cutout || '64%',
            radius: config.radius || '86%',
            rotation: -90,
            layout: {
                padding: config.layoutPadding || { top: 8, right: 14, bottom: 4, left: 14 }
            },
            plugins: {
                appRatingDistributionCenterText: {
                    title: hasData && averageRating > 0 ? averageRating.toFixed(2) : 'No data',
                    subtitle: `${total} ${total === 1 ? totalLabel : totalLabel + 's'}`,
                    muted: !hasData,
                    titleFontSize: config.centerTitleFontSize,
                    subtitleFontSize: config.centerSubtitleFontSize,
                    titleOffset: config.centerTitleOffset,
                    subtitleOffset: config.centerSubtitleOffset
                },
                legend: {
                    display: hasData && config.showLegend !== false,
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'rectRounded',
                        boxWidth: 10,
                        boxHeight: 10,
                        padding: 16,
                        color: '#475569',
                        font: { size: 12, weight: 600 }
                    }
                },
                tooltip: {
                    enabled: hasData,
                    backgroundColor: '#111827',
                    borderColor: 'rgba(255, 255, 255, 0.18)',
                    borderWidth: 1,
                    padding: 12,
                    displayColors: true,
                    callbacks: {
                        label(context) {
                            const value = Number(context.parsed) || 0;
                            const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                            return `${context.label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            }
        };
        const existingChart = Chart.getChart(canvas);
        if (existingChart && existingChart.config && existingChart.config.type === 'doughnut') {
            existingChart.data = chartData;
            existingChart.options = chartOptions;
            existingChart.update('none');
            return existingChart;
        }
        if (existingChart) existingChart.destroy();

        return new Chart(canvas, {
            type: 'doughnut',
            data: chartData,
            options: chartOptions,
            plugins: [centerTextPlugin]
        });
    }

    function renderDoughnutMetricChart(canvas, options) {
        if (!canvas || typeof Chart === 'undefined') return null;

        const config = options || {};
        const values = Array.isArray(config.values) ? config.values.map(value => Number(value) || 0) : [];
        const total = values.reduce((sum, value) => sum + value, 0);
        const hasData = total > 0;
        const labels = Array.isArray(config.labels) && config.labels.length ? config.labels : ['No data'];
        const colors = Array.isArray(config.colors) && config.colors.length
            ? config.colors
            : ['#059669', '#f59e0b', '#ef4444', '#3b82f6', '#8b5cf6'];
        const title = config.centerTitle || String(total || 0);
        const subtitle = config.centerSubtitle || 'Total';

        const chartData = {
            labels: hasData ? labels : ['No data'],
            datasets: [{
                data: hasData ? values : [1],
                backgroundColor: hasData ? colors : ['#e5e7eb'],
                borderColor: '#ffffff',
                borderWidth: 4,
                borderRadius: hasData ? 10 : 0,
                hoverBorderWidth: 4,
                hoverOffset: hasData ? 12 : 0,
                spacing: hasData ? 3 : 0
            }]
        };
        const chartOptions = {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            cutout: '64%',
            radius: '86%',
            rotation: -90,
            layout: {
                padding: { top: 8, right: 14, bottom: 4, left: 14 }
            },
            plugins: {
                appRatingDistributionCenterText: {
                    title: hasData ? title : 'No data',
                    subtitle,
                    muted: !hasData
                },
                legend: {
                    display: hasData,
                    position: 'bottom',
                    labels: {
                        usePointStyle: true,
                        pointStyle: 'rectRounded',
                        boxWidth: 10,
                        boxHeight: 10,
                        padding: 16,
                        color: '#475569',
                        font: { size: 12, weight: 600 }
                    }
                },
                tooltip: {
                    enabled: hasData,
                    backgroundColor: '#111827',
                    borderColor: 'rgba(255, 255, 255, 0.18)',
                    borderWidth: 1,
                    padding: 12,
                    displayColors: true,
                    callbacks: {
                        label(context) {
                            const value = Number(context.parsed) || 0;
                            const percentage = total > 0 ? Math.round((value / total) * 100) : 0;
                            return `${context.label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            }
        };
        const existingChart = Chart.getChart(canvas);
        if (existingChart && existingChart.config && existingChart.config.type === 'doughnut') {
            existingChart.data = chartData;
            existingChart.options = chartOptions;
            existingChart.update('none');
            return existingChart;
        }
        if (existingChart) existingChart.destroy();

        return new Chart(canvas, {
            type: 'doughnut',
            data: chartData,
            options: chartOptions,
            plugins: [centerTextPlugin]
        });
    }

    function createBarGradient(context, colors) {
        const chart = context.chart;
        const chartArea = chart.chartArea;
        if (!chartArea) return colors[0];

        const gradient = chart.ctx.createLinearGradient(0, chartArea.bottom, 0, chartArea.top);
        gradient.addColorStop(0, colors[0]);
        gradient.addColorStop(1, colors[1]);
        return gradient;
    }

    function renderBarChart(canvas, options) {
        if (!canvas || typeof Chart === 'undefined') return null;

        const config = options || {};
        const existingChart = Chart.getChart(canvas);
        if (existingChart) existingChart.destroy();

        const labels = Array.isArray(config.labels) && config.labels.length ? config.labels : ['No data'];
        const values = Array.isArray(config.values) && config.values.length
            ? config.values.map(value => Number(value) || 0)
            : [0];
        const colors = config.colors || ['#4f46e5', '#22c55e'];
        const maxValue = Number(config.maxValue);
        const stepSize = Number(config.stepSize);

        return new Chart(canvas, {
            type: 'bar',
            data: {
                labels,
                datasets: [{
                    label: config.label || 'Value',
                    data: values,
                    backgroundColor: context => createBarGradient(context, colors),
                    borderColor: colors[1],
                    borderWidth: 1,
                    borderRadius: 12,
                    borderSkipped: false,
                    hoverBackgroundColor: colors[1],
                    barPercentage: 0.72,
                    categoryPercentage: 0.64
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: { top: 8, right: 12, bottom: 2, left: 4 }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: {
                            maxRotation: 0,
                            minRotation: 0,
                            autoSkip: Boolean(config.autoSkipX),
                            maxTicksLimit: config.maxTicksLimit || 6,
                            color: '#64748b',
                            font: { size: 11, weight: 600 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        max: Number.isFinite(maxValue) ? maxValue : undefined,
                        grid: {
                            color: 'rgba(148, 163, 184, 0.22)',
                            drawTicks: false
                        },
                        border: { display: false },
                        ticks: {
                            stepSize: Number.isFinite(stepSize) ? stepSize : undefined,
                            color: '#64748b',
                            padding: 8,
                            font: { size: 11, weight: 600 }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: config.showLegend !== false,
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'rectRounded',
                            boxWidth: 10,
                            boxHeight: 10,
                            padding: 16,
                            color: '#475569',
                            font: { size: 12, weight: 600 }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#111827',
                        borderColor: 'rgba(255, 255, 255, 0.18)',
                        borderWidth: 1,
                        padding: 12,
                        displayColors: true,
                        callbacks: {
                            title(items) {
                                const item = Array.isArray(items) && items.length ? items[0] : null;
                                const index = item ? item.dataIndex : -1;
                                return config.fullLabels && config.fullLabels[index]
                                    ? config.fullLabels[index]
                                    : (item ? item.label : '');
                            },
                            label(context) {
                                const value = Number(context.parsed.y) || 0;
                                const suffix = config.valueSuffix || '';
                                const decimals = Number(config.tooltipDecimals);
                                const displayValue = Number.isFinite(decimals)
                                    ? value.toFixed(Math.max(0, decimals))
                                    : String(value);
                                return `${context.dataset.label}: ${displayValue}${suffix}`;
                            }
                        }
                    }
                }
            }
        });
    }

    function buildSectionSeries(items, options) {
        const config = options || {};
        const labelKey = config.labelKey || 'category';
        const valueKey = config.valueKey || 'score';
        const source = Array.isArray(items) && items.length ? items : [{ [labelKey]: 'No data', [valueKey]: 0 }];
        const offset = Number(config.startIndex) || 0;

        const entries = source.map((item, index) => {
            const sectionIndex = index + offset;
            const sectionName = `Section ${String.fromCharCode(65 + sectionIndex)}`;
            return {
                display: sectionName,
                full: item && item[labelKey] ? item[labelKey] : sectionName,
                value: item && Number.isFinite(Number(item[valueKey])) ? Number(item[valueKey]) : 0
            };
        });

        return {
            labels: entries.map(item => item.display),
            fullLabels: entries.map(item => item.full),
            values: entries.map(item => item.value)
        };
    }

    function createLineGradient(context, color) {
        const chart = context.chart;
        const chartArea = chart.chartArea;
        if (!chartArea) return color;

        const gradient = chart.ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
        gradient.addColorStop(0, color);
        gradient.addColorStop(1, 'rgba(255, 255, 255, 0)');
        return gradient;
    }

    function renderLineChart(canvas, options) {
        if (!canvas || typeof Chart === 'undefined') return null;

        const config = options || {};
        const existingChart = Chart.getChart(canvas);
        if (existingChart) existingChart.destroy();

        const labels = Array.isArray(config.labels) && config.labels.length ? config.labels : ['No data'];
        const values = Array.isArray(config.values) && config.values.length
            ? config.values.map(value => Number(value) || 0)
            : [0];
        const lineColor = config.lineColor || '#4f46e5';
        const fillColor = config.fillColor || 'rgba(79, 70, 229, 0.18)';
        const maxValue = Number(config.maxValue);
        const stepSize = Number(config.stepSize);

        return new Chart(canvas, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: config.label || 'Value',
                    data: values,
                    borderColor: lineColor,
                    backgroundColor: context => createLineGradient(context, fillColor),
                    fill: true,
                    tension: 0.35,
                    borderWidth: 3,
                    pointRadius: 4,
                    pointHoverRadius: 6,
                    pointBackgroundColor: '#ffffff',
                    pointBorderColor: lineColor,
                    pointBorderWidth: 3
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: {
                    padding: { top: 8, right: 12, bottom: 2, left: 4 }
                },
                scales: {
                    x: {
                        grid: { display: false },
                        border: { display: false },
                        ticks: {
                            maxRotation: 0,
                            minRotation: 0,
                            autoSkip: true,
                            maxTicksLimit: config.maxTicksLimit || 6,
                            color: '#64748b',
                            font: { size: 11, weight: 600 }
                        }
                    },
                    y: {
                        beginAtZero: true,
                        max: Number.isFinite(maxValue) ? maxValue : undefined,
                        grid: {
                            color: 'rgba(148, 163, 184, 0.22)',
                            drawTicks: false
                        },
                        border: { display: false },
                        ticks: {
                            stepSize: Number.isFinite(stepSize) ? stepSize : undefined,
                            color: '#64748b',
                            padding: 8,
                            font: { size: 11, weight: 600 }
                        }
                    }
                },
                plugins: {
                    legend: {
                        display: config.showLegend !== false,
                        position: 'bottom',
                        labels: {
                            usePointStyle: true,
                            pointStyle: 'rectRounded',
                            boxWidth: 10,
                            boxHeight: 10,
                            padding: 16,
                            color: '#475569',
                            font: { size: 12, weight: 600 }
                        }
                    },
                    tooltip: {
                        backgroundColor: '#111827',
                        borderColor: 'rgba(255, 255, 255, 0.18)',
                        borderWidth: 1,
                        padding: 12,
                        displayColors: true,
                        callbacks: {
                            label(context) {
                                const value = Number(context.parsed.y) || 0;
                                const suffix = config.valueSuffix || '';
                                const decimals = Number(config.tooltipDecimals);
                                const displayValue = Number.isFinite(decimals)
                                    ? value.toFixed(Math.max(0, decimals))
                                    : String(value);
                                return `${context.dataset.label}: ${displayValue}${suffix}`;
                            }
                        }
                    }
                }
            }
        });
    }

    return {
        renderRatingDistributionChart,
        renderDoughnutMetricChart,
        renderBarChart,
        renderLineChart,
        buildSectionSeries
    };
})();

const SharedData = (() => {
    const KEYS = {
        USER_SESSION: 'userSession',
        SESSION_ACTIVITY: 'naapSessionActivity',
        PROFESSORS: 'professorsData',
        USERS: 'sharedUsersData',
        CAMPUSES: 'sharedCampusData',
        CURRENT_SEMESTER: 'currentSemester',
        QUESTIONNAIRES: 'questionnairesBySemester',
        ACTIVITY_LOG: 'sharedActivityLog',
        ANNOUNCEMENTS: 'sharedAnnouncements',
        SETTINGS: 'sharedSettings',
        STUDENT_EVAL_REMINDER_CONFIG: 'studentEvaluationReminderConfig',
        EVAL_PERIODS: 'sharedEvalPeriods',
        SEMESTER_LIST: 'sharedSemesterList',
        EVALUATIONS: 'sharedEvaluations',
        STUDENT_EVAL_DRAFTS: 'studentEvaluationDrafts',
        STUDENT_DATA_PRIVACY_CONSENTS: 'studentDataPrivacyConsents',
        OSA_STUDENT_CLEARANCES: 'osaStudentClearances',
        STUDENT_EVAL_PROOF_REQUESTS: 'studentEvaluationProofRequests',
        SUBJECT_MANAGEMENT: 'subjectManagement',
        PROGRAMS: 'sharedProgramsData',
        FACULTY_PAPERS: 'facultyAcknowledgementPapers',
        FACULTY_REPORT_ACCESS: 'facultyReportAccess',
        PROFESSOR_EVALUATION_COUNTS: 'professorEvaluationCounts',
        ADMIN_DASHBOARD_SUMMARY: 'adminDashboardSummary',
        LOGOUT_PENDING: 'naapLogoutPending',
    };

    const API_URL = '../api/app_state.php';
    const LOGIN_API_URL = '../api/login.php';
    const HEARTBEAT_API_URL = LOGIN_API_URL + '?_heartbeat=1';
    const SESSION_URL = LOGIN_API_URL + '?action=session';
    const PROFILE_IMAGE_UPLOAD_URL = '../api/profile_image_upload.php';
    const USERS_CACHE_TTL_MS = 30000;
    const PHILIPPINE_TIMEZONE = 'Asia/Manila';
    const SESSION_HEARTBEAT_INTERVAL_MS = 60000;
    const SESSION_HEARTBEAT_CHECK_MS = 15000;
    const SESSION_IDLE_WINDOW_MS = 10 * 60 * 1000;
    const ANNOUNCEMENT_ALLOWED_ROLES = ['admin', 'hr', 'vpaa', 'osa', 'dean', 'procoor', 'professor', 'student'];
    const ANNOUNCEMENT_ROLE_LABELS = {
        admin: 'Administrator',
        hr: 'HR Staff',
        vpaa: 'VPAA',
        osa: 'OSA',
        dean: 'Dean',
        procoor: 'Program Coordinator',
        professor: 'Professor',
        student: 'Student',
    };
    const announcementPopupShownIds = new Set();

    const state = {
        users: [],
        programs: [],
        campuses: [
            { id: 'all', name: 'All Campuses', departments: [] },
        ],
        currentSemester: '',
        questionnaires: {},
        activityLog: [],
        announcements: [],
        settings: {
            evaluationPeriodOpen: false,
            systemName: 'Student Professor Evaluation System',
            academicYear: '2025-2026',
            institutionName: 'National Aviation Academy of the Philippines',
            systemEmail: '',
            mainCampus: 'villamor',
        },
        studentEvaluationReminderConfig: {
            enabled: true,
            frequencyDays: 7,
        sendTime: '07:00',
            subject: 'NAAP Evaluation Reminder: Please Complete Your Evaluation',
            body: 'Please complete your evaluation while the student evaluation period is open. Log in to the NAAP Evaluation System and submit your pending evaluation today.',
            allowedPlaceholders: ['student_name', 'evaluation_end_date', 'academic_year', 'semester'],
            updatedAt: '',
            updatedByUserId: '',
        },
        evalPeriods: {
            'student-professor': { start: '', end: '' },
            'professor-professor': { start: '', end: '' },
            'supervisor-professor': { start: '', end: '' },
        },
        semesterList: [],
        evaluations: [],
        studentEvaluationDrafts: [],
        studentDataPrivacyConsents: [],
        dataPrivacyConsentNotice: null,
        dataPrivacyConsentNotices: {},
        osaStudentClearances: [],
        studentEvaluationProofRequests: [],
        subjectManagement: {
            subjects: [],
            offerings: [],
            enrollments: [],
        },
        adminDashboardSummary: null,
        facultyAcknowledgementPapers: [],
        facultyPaperListMeta: { total: 0, limit: 0, offset: 0, page: 1, hasMore: false },
        facultyReportAccess: { enabled: true, departmentCode: '', updatedAt: '' },
        professorEvaluationCounts: { semesterId: '', received: 0, required: 0, responseRate: 0 },
        profileData: null,
        profilePhotos: null,
        bootstrapMeta: {},
        userListMeta: { total: 0, limit: 0, offset: 0, page: 1, hasMore: false },
    };

    let initialized = false;
    let bootstrapPromise = null;
    const refreshPromises = {
        users: null,
        evaluations: null,
        subjectManagement: null,
        osaStudentClearances: null,
        studentEvaluationProofRequests: null,
        adminDashboardSummary: null,
        facultyPapers: null,
    };
    const refreshPromiseKeys = {
        users: '',
        evaluations: '',
        subjectManagement: '',
        osaStudentClearances: '',
        studentEvaluationProofRequests: '',
        adminDashboardSummary: '',
        facultyPapers: '',
    };
    let usersLastSyncedAt = 0;
    let evaluationsLastSyncedAt = 0;
    let subjectManagementLastSyncedAt = 0;
    let lastUserActivityAt = Date.now();
    let sessionActivity = null;
    let lastHeartbeatSentAt = 0;
    let heartbeatTimerId = null;
    let idleTimerId = null;
    let heartbeatInFlight = false;
    let heartbeatListenersAttached = false;
    let sessionCsrfToken = '';
    let sessionClearGeneration = 0;
    let sessionLogoutInProgress = false;
    let profilePhotoStateVersion = 0;
    const clockState = {
        baseUnixMs: null,
        capturedAtMs: 0,
        source: 'browser',
        timezone: PHILIPPINE_TIMEZONE,
    };

    function deepClone(value) {
        return value == null ? value : JSON.parse(JSON.stringify(value));
    }

    function getBootstrapDatasetMeta(key) {
        const meta = state.bootstrapMeta && typeof state.bootstrapMeta === 'object'
            ? state.bootstrapMeta[key]
            : null;
        return meta && typeof meta === 'object' ? meta : {};
    }

    function isBootstrapDatasetPartial(key) {
        return getBootstrapDatasetMeta(key).partial === true;
    }

    function markBootstrapDatasetComplete(key) {
        if (!state.bootstrapMeta || typeof state.bootstrapMeta !== 'object') {
            state.bootstrapMeta = {};
        }
        const current = getBootstrapDatasetMeta(key);
        state.bootstrapMeta[key] = Object.assign({}, current, { partial: false });
    }

    function markBootstrapDatasetPartial(key) {
        if (!state.bootstrapMeta || typeof state.bootstrapMeta !== 'object') {
            state.bootstrapMeta = {};
        }
        const current = getBootstrapDatasetMeta(key);
        state.bootstrapMeta[key] = Object.assign({}, current, { partial: true });
    }

    function getMonotonicNow() {
        return typeof performance !== 'undefined' && performance && typeof performance.now === 'function'
            ? performance.now()
            : Date.now();
    }

    function resolveClockPayload(payload) {
        if (!payload || typeof payload !== 'object') {
            return null;
        }
        if (payload.clock && typeof payload.clock === 'object') {
            return payload.clock;
        }
        if (payload.user && typeof payload.user === 'object' && payload.user.clock && typeof payload.user.clock === 'object') {
            return payload.user.clock;
        }
        return null;
    }

    function setClockReference(payload) {
        const clock = resolveClockPayload(payload) || (payload && typeof payload === 'object' ? payload : null);
        if (!clock || typeof clock !== 'object') {
            return false;
        }

        let baseUnixMs = Number(clock.unixMs);
        if (!Number.isFinite(baseUnixMs)) {
            const iso = String(clock.iso || '').trim();
            const parsed = iso ? Date.parse(iso) : NaN;
            if (Number.isFinite(parsed)) {
                baseUnixMs = parsed;
            }
        }

        if (!Number.isFinite(baseUnixMs)) {
            return false;
        }

        clockState.baseUnixMs = baseUnixMs;
        clockState.capturedAtMs = getMonotonicNow();
        clockState.source = String(clock.source || 'server').trim() || 'server';
        clockState.timezone = String(clock.timezone || PHILIPPINE_TIMEZONE).trim() || PHILIPPINE_TIMEZONE;
        return true;
    }

    function getNowMs() {
        if (!Number.isFinite(clockState.baseUnixMs)) {
            return Date.now();
        }
        return clockState.baseUnixMs + (getMonotonicNow() - clockState.capturedAtMs);
    }

    function getNowDate() {
        return new Date(getNowMs());
    }

    function getNowIsoString() {
        return new Date(getNowMs()).toISOString();
    }

    function getPhilippineDateParts(value) {
        const date = value instanceof Date ? value : getNowDate();
        const formatter = new Intl.DateTimeFormat('en-US', {
            timeZone: clockState.timezone || PHILIPPINE_TIMEZONE,
            year: 'numeric',
            month: '2-digit',
            day: '2-digit',
        });
        const parts = formatter.formatToParts(date);
        const map = {};
        parts.forEach(function (part) {
            if (part.type !== 'literal') {
                map[part.type] = part.value;
            }
        });
        return {
            year: String(map.year || ''),
            month: String(map.month || ''),
            day: String(map.day || ''),
        };
    }

    function getCurrentPhilippineDateYmd() {
        const parts = getPhilippineDateParts(getNowDate());
        if (!parts.year || !parts.month || !parts.day) {
            return '';
        }
        return `${parts.year}-${parts.month}-${parts.day}`;
    }

    function getCurrentPhilippineYear() {
        return parseInt(getPhilippineDateParts(getNowDate()).year || '0', 10) || getNowDate().getUTCFullYear();
    }

    function parsePhilippineDateBoundary(dateString, boundary) {
        const raw = String(dateString || '').trim();
        if (!raw) return null;
        const suffix = boundary === 'end' ? 'T23:59:59+08:00' : 'T00:00:00+08:00';
        const parsed = new Date(raw + suffix);
        return Number.isNaN(parsed.getTime()) ? null : parsed;
    }

    function resolveDateValue(value, options) {
        if (value instanceof Date) {
            return Number.isNaN(value.getTime()) ? null : value;
        }

        const raw = String(value || '').trim();
        if (!raw) {
            return null;
        }

        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) {
            return parsePhilippineDateBoundary(raw, options && options.boundary === 'end' ? 'end' : 'start');
        }

        if (/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(?::\d{2}(?:\.\d+)?)?$/.test(raw)) {
            const normalized = raw.replace(' ', 'T');
            return new Date(normalized + '+08:00');
        }

        const parsed = new Date(raw);
        if (!Number.isNaN(parsed.getTime())) {
            return parsed;
        }

        const altParsed = new Date(raw.replace(' ', 'T'));
        return Number.isNaN(altParsed.getTime()) ? null : altParsed;
    }

    function formatDateTimeInPhilippines(value, locale, options) {
        const parsed = resolveDateValue(value, options);
        if (!parsed) return String(value || '');
        const formatOptions = Object.assign({
            timeZone: clockState.timezone || PHILIPPINE_TIMEZONE,
        }, options || {});
        delete formatOptions.boundary;
        return parsed.toLocaleString(locale || undefined, formatOptions);
    }

    function formatDateInPhilippines(value, locale, options) {
        const parsed = resolveDateValue(value, options);
        if (!parsed) return String(value || '');
        const formatOptions = Object.assign({
            timeZone: clockState.timezone || PHILIPPINE_TIMEZONE,
        }, options || {});
        delete formatOptions.boundary;
        return parsed.toLocaleDateString(locale || undefined, formatOptions);
    }

    function dispatchChange(key, value) {
        window.dispatchEvent(new CustomEvent('shareddata:change', {
            detail: { key, value }
        }));
    }

    function normalizeSessionPayload(payload) {
        const source = payload && typeof payload === 'object'
            ? (payload.user && typeof payload.user === 'object'
                ? Object.assign({}, payload.user, {
                    csrfToken: payload.csrfToken || (payload.user && payload.user.csrfToken) || '',
                })
                : payload)
            : null;
        if (!source || typeof source !== 'object') {
            return null;
        }

        const role = String(source.role || '').trim();
        const username = String(source.username || '').trim();
        if (!role || !username) {
            return null;
        }

        const csrfToken = String(source.csrfToken || '').trim();
        if (csrfToken) {
            sessionCsrfToken = csrfToken;
        }

        return {
            username: username,
            role: role,
            fullName: String(source.fullName || username).trim(),
            userId: String(source.userId || '').trim(),
            email: String(source.email || '').trim(),
            studentNumber: String(source.studentNumber || '').trim(),
            employeeId: String(source.employeeId || '').trim(),
            campus: String(source.campus || source.campusSlug || '').trim(),
            department: String(source.department || source.institute || '').trim(),
            institute: String(source.institute || source.department || '').trim(),
            programCode: String(source.programCode || source.program || '').trim(),
            programName: String(source.programName || '').trim(),
            position: String(source.position || '').trim(),
            status: String(source.status || 'active').trim().toLowerCase() === 'inactive' ? 'inactive' : 'active',
            profileImage: String(source.profileImage || '').trim(),
            profileImageUrl: String(source.profileImageUrl || source.profilePhoto || '').trim(),
            csrfToken: csrfToken,
            loginTime: String(source.loginTime || getNowIsoString()).trim(),
            isAuthenticated: true,
        };
    }

    function sanitizeSessionForStorage(session) {
        const storedSession = Object.assign({}, session || {});
        delete storedSession.csrfToken;
        return storedSession;
    }

    function hasLogoutPendingMarker() {
        try {
            const storage = getSessionStorage();
            return storage.getItem(KEYS.LOGOUT_PENDING) === '1';
        } catch (_error) {
            return false;
        }
    }

    function setLogoutPendingMarker() {
        try {
            const storage = getSessionStorage();
            storage.setItem(KEYS.LOGOUT_PENDING, '1');
        } catch (_error) {
            // Logout must continue even if local storage is unavailable.
        }
    }

    function clearLogoutPendingMarker() {
        try {
            const storage = getSessionStorage();
            storage.removeItem(KEYS.LOGOUT_PENDING);
        } catch (_error) {
            // Logout marker cleanup is best-effort.
        }
    }

    function consumeLogoutPendingMarker() {
        const pending = hasLogoutPendingMarker();
        if (pending) {
            clearLogoutPendingMarker();
        }
        return pending;
    }

    function storeSessionPayload(payload) {
        if (sessionLogoutInProgress || hasLogoutPendingMarker()) {
            return null;
        }
        setClockReference(payload);
        const session = normalizeSessionPayload(payload);
        if (!session) {
            return null;
        }
        setJSON(KEYS.USER_SESSION, sanitizeSessionForStorage(session));
        const sessionPhoto = String(session.profileImageUrl || session.profilePhoto || session.photoData || '').trim();
        profilePhotoStateVersion += 1;
        state.profilePhotos = sessionPhoto ? appendProfilePhotoCacheBust(sessionPhoto) : null;
        dispatchChange('profilePhoto', state.profilePhotos);
        startSessionHeartbeat();
        return session;
    }

    function extractSessionPayloadFromResponse(payload) {
        if (!payload || typeof payload !== 'object') {
            return null;
        }
        const source = payload.user && typeof payload.user === 'object'
            ? payload.user
            : (payload.session && typeof payload.session === 'object' ? payload.session : payload);
        if (!source || typeof source !== 'object') {
            return null;
        }
        const sessionPayload = Object.assign({}, source);
        const responseCsrfToken = String(payload.csrfToken || '').trim();
        if (responseCsrfToken && !sessionPayload.csrfToken) {
            sessionPayload.csrfToken = responseCsrfToken;
        }
        return sessionPayload;
    }

    function clearSessionCache() {
        sessionClearGeneration += 1;
        sessionCsrfToken = '';
        remove(KEYS.USER_SESSION);
        remove(KEYS.SESSION_ACTIVITY);
        sessionActivity = null;
        remove('currentUser');
        try {
            if (typeof window !== 'undefined' && window.sessionStorage) {
                window.sessionStorage.removeItem('selectedProfessor');
                window.sessionStorage.removeItem('selectedCourse');
                window.sessionStorage.removeItem('selectedEvaluationTarget');
                window.sessionStorage.removeItem('selectedCourseOfferingId');
            }
        } catch (_error) {
            // Session-scoped navigation hints are best-effort cleanup.
        }
        stopSessionHeartbeat();
    }

    function resolveLoginRedirectPath() {
        if (typeof window === 'undefined' || !window.location) {
            return 'mainpage.html';
        }
        const path = String(window.location.pathname || '').toLowerCase();
        if (path.indexOf('/html/') !== -1 || path.endsWith('/html')) {
            return 'mainpage.html';
        }
        return 'html/mainpage.html';
    }

    function handleServerEndedSession() {
        initialized = false;
        usersLastSyncedAt = 0;
        evaluationsLastSyncedAt = 0;
        subjectManagementLastSyncedAt = 0;
        clearSessionCache();
        clearProfilePhotoState();

        if (typeof window === 'undefined' || !window.location) {
            return;
        }
        const currentPath = String(window.location.pathname || '').toLowerCase();
        if (currentPath.endsWith('/mainpage.html')) {
            return;
        }
        window.location.href = resolveLoginRedirectPath();
    }

    function stopSessionHeartbeat() {
        if (heartbeatTimerId !== null && typeof window !== 'undefined') {
            window.clearInterval(heartbeatTimerId);
        }
        heartbeatTimerId = null;
        if (idleTimerId !== null && typeof window !== 'undefined') {
            window.clearTimeout(idleTimerId);
        }
        idleTimerId = null;
        heartbeatInFlight = false;
    }

    function getSessionActivityAt(session) {
        const activity = getJSON(KEYS.SESSION_ACTIVITY, sessionActivity);
        if (activity && activity.userId === session.userId
            && activity.role === session.role && activity.loginTime === session.loginTime
            && Number.isFinite(activity.at)) {
            lastUserActivityAt = activity.at;
        } else {
            lastUserActivityAt = Date.now();
            lastHeartbeatSentAt = 0;
            saveSessionActivity(session);
        }
        return lastUserActivityAt;
    }

    function saveSessionActivity(session) {
        sessionActivity = {
            userId: session.userId,
            role: session.role,
            loginTime: session.loginTime,
            at: lastUserActivityAt,
        };
        writeLocalFallbackJSON(KEYS.SESSION_ACTIVITY, sessionActivity);
    }

    function checkSessionIdleTimeout() {
        const session = getSession();
        if (!session || session.isAuthenticated !== true
            || String(session.role || '').trim().toLowerCase() === 'admin') {
            return false;
        }
        if (Date.now() - getSessionActivityAt(session) < SESSION_IDLE_WINDOW_MS) {
            return false;
        }

        // End the server session even when no further API request is made.
        clearSession();
        window.location.href = resolveLoginRedirectPath();
        return true;
    }

    function scheduleSessionIdleTimeout() {
        if (idleTimerId !== null) {
            window.clearTimeout(idleTimerId);
            idleTimerId = null;
        }
        const session = getSession();
        if (!session || session.isAuthenticated !== true
            || String(session.role || '').trim().toLowerCase() === 'admin') {
            return;
        }
        const remaining = SESSION_IDLE_WINDOW_MS - (Date.now() - getSessionActivityAt(session));
        idleTimerId = window.setTimeout(function () {
            idleTimerId = null;
            if (!checkSessionIdleTimeout()) {
                scheduleSessionIdleTimeout();
            }
        }, Math.max(0, remaining));
    }

    function attachHeartbeatActivityListeners() {
        if (heartbeatListenersAttached || typeof document === 'undefined') {
            return;
        }

        const activityEvents = ['mousemove', 'mousedown', 'keydown', 'touchstart', 'scroll', 'click'];
        activityEvents.forEach(function (eventName) {
            document.addEventListener(eventName, recordUserActivity, {
                passive: true,
                capture: true,
            });
        });
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                // Resuming a suspended tab must not revive an expired session.
                sendSessionHeartbeat(false);
            }
        });

        heartbeatListenersAttached = true;
    }

    function recordUserActivity() {
        if (checkSessionIdleTimeout()) {
            return;
        }
        if (isAuthenticated()) {
            lastUserActivityAt = Date.now();
            saveSessionActivity(getSession());
            startSessionHeartbeat();
            sendSessionHeartbeat(false);
        }
    }

    function startSessionHeartbeat() {
        if (typeof window === 'undefined') {
            return;
        }
        if (!isAuthenticated()) {
            stopSessionHeartbeat();
            return;
        }

        attachHeartbeatActivityListeners();
        if (checkSessionIdleTimeout()) {
            return;
        }
        scheduleSessionIdleTimeout();
        if (heartbeatTimerId !== null) {
            return;
        }

        heartbeatTimerId = window.setInterval(function () {
            sendSessionHeartbeat(false);
        }, SESSION_HEARTBEAT_CHECK_MS);
    }

    function sendSessionHeartbeat(force) {
        if (checkSessionIdleTimeout()) {
            return;
        }
        const session = getSession();
        if (!session || session.isAuthenticated !== true) {
            stopSessionHeartbeat();
            return;
        }
        if (!session.csrfToken) {
            return;
        }

        const now = Date.now();
        const isAdmin = String(session.role || '').trim().toLowerCase() === 'admin';
        if (!force) {
            if ((now - lastHeartbeatSentAt) < SESSION_HEARTBEAT_INTERVAL_MS) {
                return;
            }
            if (!isAdmin && lastHeartbeatSentAt > 0 && lastUserActivityAt <= lastHeartbeatSentAt) {
                return;
            }
            if (!isAdmin && (now - lastUserActivityAt) > SESSION_IDLE_WINDOW_MS) {
                return;
            }
        }
        if (heartbeatInFlight) {
            return;
        }

        heartbeatInFlight = true;
        lastHeartbeatSentAt = now;
        const requestSessionGeneration = sessionClearGeneration;

        const payload = JSON.stringify({ action: 'heartbeat' });
        const headers = {
            'Content-Type': 'application/json',
            'X-CSRF-Token': session.csrfToken,
        };

        if (typeof fetch === 'function') {
            fetch(HEARTBEAT_API_URL, {
                method: 'POST',
                headers: headers,
                body: payload,
                credentials: 'same-origin',
            })
                .then(function (response) {
                    return response.text().then(function (text) {
                        let data = {};
                        if (text) {
                            try {
                                data = JSON.parse(text);
                            } catch (_error) {
                                data = {};
                            }
                        }
                        return {
                            ok: response.ok,
                            status: response.status,
                            data: data,
                        };
                    });
                })
                .then(function (result) {
                    if (requestSessionGeneration !== sessionClearGeneration || sessionLogoutInProgress) {
                        return;
                    }
                    if (result.ok && result.data && result.data.session) {
                        storeSessionPayload(result.data.session);
                        return;
                    }
                    if (responseEndedAuthenticatedSession(result.status, result.data)) {
                        handleServerEndedSession();
                    }
                })
                .catch(function () {
                    // Network loss should not clear the local session by itself.
                })
                .finally(function () {
                    heartbeatInFlight = false;
                });
            return;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', HEARTBEAT_API_URL, true);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.setRequestHeader('X-CSRF-Token', session.csrfToken);
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) {
                return;
            }
            heartbeatInFlight = false;
            let response = {};
            try {
                response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            } catch (_error) {
                response = {};
            }
            if (xhr.status >= 200 && xhr.status < 300) {
                if (requestSessionGeneration === sessionClearGeneration && !sessionLogoutInProgress && response && response.session) {
                    storeSessionPayload(response.session);
                }
                return;
            }
            if (responseEndedAuthenticatedSession(xhr.status, response)) {
                handleServerEndedSession();
            }
        };
        xhr.onerror = function () {
            heartbeatInFlight = false;
        };
        xhr.send(payload);
    }

    function handleRequestAuthFailure() {
        initialized = false;
        usersLastSyncedAt = 0;
        evaluationsLastSyncedAt = 0;
        subjectManagementLastSyncedAt = 0;
        clearSessionCache();
        clearProfilePhotoState();
    }

    function responseEndedAuthenticatedSession(status, payload) {
        const statusCode = Number(status) || 0;
        if (statusCode === 401) {
            return true;
        }
        if (statusCode !== 403 || !payload || typeof payload !== 'object') {
            return false;
        }

        return payload.authenticated === false
            || payload.signedInElsewhere === true
            || payload.idleTimeout === true;
    }

    function requestJson(method, action, payload, options) {
        if (typeof fetch !== 'function') {
            return Promise.reject(new Error('This browser does not support asynchronous SharedData requests.'));
        }

        const opts = options && typeof options === 'object' ? options : {};
        let url = API_URL + '?action=' + encodeURIComponent(action);
        if (method === 'GET') {
            url += '&_ts=' + Date.now();
        }
        if (opts.background === true) {
            url += '&_background=1';
        }
        const requestSessionGeneration = sessionClearGeneration;

        const headers = {
            'Content-Type': 'application/json',
        };
        if (method !== 'GET') {
            const session = getSession();
            const csrfToken = String(session && session.csrfToken || '').trim();
            if (!csrfToken && opts.skipBootstrapWait !== true) {
                return startBootstrap(false).then(function () {
                    return requestJson(method, action, payload, Object.assign({}, opts, { skipBootstrapWait: true }));
                });
            }
            if (!csrfToken) {
                return Promise.reject(new Error('Authentication token is not ready. Please refresh and sign in again.'));
            }
            if (csrfToken) {
                headers['X-CSRF-Token'] = csrfToken;
            }
        }

        return fetch(url, {
            method,
            headers,
            credentials: 'same-origin',
            body: method === 'GET' ? null : (payload ? JSON.stringify(payload) : null),
        }).then(function (response) {
            return response.text().then(function (text) {
                let parsed = {};
                if (text) {
                    try {
                        parsed = JSON.parse(text);
                    } catch (_error) {
                        parsed = {};
                    }
                }

                if (response.status < 200 || response.status >= 300) {
                    const message = parsed && parsed.error
                        ? String(parsed.error)
                        : (text || ('Request failed with status ' + response.status));
                    if (responseEndedAuthenticatedSession(response.status, parsed)) {
                        handleRequestAuthFailure();
                    }
                    const error = new Error(message);
                    error.status = response.status;
                    throw error;
                }

                setClockReference(parsed);
                if (requestSessionGeneration === sessionClearGeneration && !sessionLogoutInProgress && parsed && parsed.session) {
                    storeSessionPayload(parsed.session);
                }
                return parsed;
            });
        });
    }

    function syncRequest(method, action, payload) {
        const xhr = new XMLHttpRequest();
        let url = API_URL + '?action=' + encodeURIComponent(action);
        if (method === 'GET') {
            url += '&_ts=' + Date.now();
        }
        const requestSessionGeneration = sessionClearGeneration;
        xhr.open(method, url, false);
        xhr.setRequestHeader('Content-Type', 'application/json');
        if (method !== 'GET') {
            const session = getSession();
            const csrfToken = String(session && session.csrfToken || '').trim();
            if (csrfToken) {
                xhr.setRequestHeader('X-CSRF-Token', csrfToken);
            }
        }
        xhr.send(payload ? JSON.stringify(payload) : null);

        if (xhr.status < 200 || xhr.status >= 300) {
            let message = 'Request failed with status ' + xhr.status;
            let parsedError = {};
            if (xhr.responseText) {
                try {
                    parsedError = JSON.parse(xhr.responseText);
                    message = parsedError && parsedError.error ? String(parsedError.error) : xhr.responseText;
                } catch (_error) {
                    message = xhr.responseText;
                }
            }
            if (responseEndedAuthenticatedSession(xhr.status, parsedError)) {
                handleRequestAuthFailure();
            }
            const error = new Error(message);
            error.status = xhr.status;
            error.response = parsedError;
            throw error;
        }

        const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
        setClockReference(response);
        if (requestSessionGeneration === sessionClearGeneration && !sessionLogoutInProgress && response && response.session) {
            storeSessionPayload(response.session);
        }
        return response;
    }

    function asyncRequest(method, action, payload, options) {
        startBootstrap(false);
        return requestJson(method, action, payload, options);
    }

    function applyBootstrap(snapshot, options) {
        const preserveProfilePhoto = Boolean(options && options.preserveProfilePhoto);
        setClockReference(snapshot);
        state.bootstrapMeta = snapshot.bootstrapMeta && typeof snapshot.bootstrapMeta === 'object'
            ? snapshot.bootstrapMeta
            : {};
        state.users = Array.isArray(snapshot.users) ? snapshot.users : [];
        state.userListMeta = snapshot.userListMeta && typeof snapshot.userListMeta === 'object'
            ? snapshot.userListMeta
            : { total: state.users.length, limit: 0, offset: 0, page: 1, hasMore: false };
        usersLastSyncedAt = state.users.length && !isBootstrapDatasetPartial('users') ? Date.now() : 0;
        state.programs = Array.isArray(snapshot.programs) ? snapshot.programs : [];
        state.campuses = Array.isArray(snapshot.campuses) && snapshot.campuses.length
            ? snapshot.campuses
            : state.campuses;
        state.currentSemester = snapshot.currentSemester || '';
        state.questionnaires = snapshot.questionnaires || {};
        state.activityLog = Array.isArray(snapshot.activityLog) ? snapshot.activityLog : [];
        state.announcements = normalizeAnnouncementList(snapshot.announcements);
        state.settings = Object.assign({}, state.settings, snapshot.settings || {});
        state.studentEvaluationReminderConfig = Object.assign(
            {},
            state.studentEvaluationReminderConfig,
            snapshot.studentEvaluationReminderConfig || {}
        );
        state.evalPeriods = Object.assign({}, state.evalPeriods, snapshot.evalPeriods || {});
        state.semesterList = Array.isArray(snapshot.semesterList) ? snapshot.semesterList : [];
        state.evaluations = Array.isArray(snapshot.evaluations) ? snapshot.evaluations : [];
        evaluationsLastSyncedAt = state.evaluations.length && !isBootstrapDatasetPartial('evaluations') ? Date.now() : 0;
        state.studentEvaluationDrafts = Array.isArray(snapshot.studentEvaluationDrafts) ? snapshot.studentEvaluationDrafts : [];
        state.studentDataPrivacyConsents = Array.isArray(snapshot.studentDataPrivacyConsents) ? snapshot.studentDataPrivacyConsents : [];
        state.dataPrivacyConsentNotice = snapshot.dataPrivacyConsentNotice && typeof snapshot.dataPrivacyConsentNotice === 'object'
            ? snapshot.dataPrivacyConsentNotice
            : null;
        state.dataPrivacyConsentNotices = snapshot.dataPrivacyConsentNotices && typeof snapshot.dataPrivacyConsentNotices === 'object'
            ? snapshot.dataPrivacyConsentNotices
            : {};
        state.osaStudentClearances = Array.isArray(snapshot.osaStudentClearances) ? snapshot.osaStudentClearances : [];
        state.studentEvaluationProofRequests = Array.isArray(snapshot.studentEvaluationProofRequests) ? snapshot.studentEvaluationProofRequests : [];
        const subjectManagement = snapshot.subjectManagement || {};
        state.subjectManagement = {
            subjects: Array.isArray(subjectManagement.subjects) ? subjectManagement.subjects : [],
            offerings: Array.isArray(subjectManagement.offerings) ? subjectManagement.offerings : [],
            enrollments: Array.isArray(subjectManagement.enrollments) ? subjectManagement.enrollments : [],
        };
        subjectManagementLastSyncedAt = !isBootstrapDatasetPartial('subjectManagement') ? Date.now() : 0;
        state.facultyAcknowledgementPapers = Array.isArray(snapshot.facultyAcknowledgementPapers)
            ? snapshot.facultyAcknowledgementPapers
            : [];
        state.facultyPaperListMeta = snapshot.facultyAcknowledgementPapersMeta && typeof snapshot.facultyAcknowledgementPapersMeta === 'object'
            ? snapshot.facultyAcknowledgementPapersMeta
            : { total: state.facultyAcknowledgementPapers.length, limit: 0, offset: 0, page: 1, hasMore: false };
        state.facultyReportAccess = Object.assign(
            { enabled: true, departmentCode: '', updatedAt: '' },
            snapshot.facultyReportAccess && typeof snapshot.facultyReportAccess === 'object'
                ? snapshot.facultyReportAccess
                : {}
        );
        state.professorEvaluationCounts = Object.assign(
            { semesterId: '', received: 0, required: 0, responseRate: 0 },
            snapshot.professorEvaluationCounts && typeof snapshot.professorEvaluationCounts === 'object'
                ? snapshot.professorEvaluationCounts
                : {}
        );
        state.profileData = snapshot.currentUserProfileData && typeof snapshot.currentUserProfileData === 'object'
            ? snapshot.currentUserProfileData
            : null;
        const bootstrapProfilePhoto = typeof snapshot.currentUserProfileImageUrl === 'string' && snapshot.currentUserProfileImageUrl
            ? snapshot.currentUserProfileImageUrl
            : (typeof snapshot.currentUserProfilePhoto === 'string'
                ? snapshot.currentUserProfilePhoto
                : null);
        if (!preserveProfilePhoto || (!state.profilePhotos && bootstrapProfilePhoto)) {
            state.profilePhotos = bootstrapProfilePhoto;
        }

        persistLocalSettingsFallback();

        dispatchChange(KEYS.USERS, deepClone(state.users));
        dispatchChange(KEYS.PROGRAMS, deepClone(state.programs));
        dispatchChange(KEYS.CAMPUSES, deepClone(state.campuses));
        dispatchChange(KEYS.CURRENT_SEMESTER, state.currentSemester);
        dispatchChange(KEYS.QUESTIONNAIRES, deepClone(state.questionnaires));
        dispatchChange(KEYS.ACTIVITY_LOG, deepClone(state.activityLog));
        dispatchChange(KEYS.ANNOUNCEMENTS, deepClone(state.announcements));
        dispatchChange(KEYS.SETTINGS, deepClone(state.settings));
        dispatchChange(
            KEYS.STUDENT_EVAL_REMINDER_CONFIG,
            deepClone(state.studentEvaluationReminderConfig)
        );
        dispatchChange(KEYS.EVAL_PERIODS, deepClone(state.evalPeriods));
        dispatchChange(KEYS.SEMESTER_LIST, deepClone(state.semesterList));
        dispatchChange(KEYS.EVALUATIONS, deepClone(state.evaluations));
        dispatchChange(KEYS.STUDENT_EVAL_DRAFTS, deepClone(state.studentEvaluationDrafts));
        dispatchChange(KEYS.STUDENT_DATA_PRIVACY_CONSENTS, deepClone(state.studentDataPrivacyConsents));
        dispatchChange(KEYS.OSA_STUDENT_CLEARANCES, deepClone(state.osaStudentClearances));
        dispatchChange(KEYS.STUDENT_EVAL_PROOF_REQUESTS, deepClone(state.studentEvaluationProofRequests));
        dispatchChange(KEYS.SUBJECT_MANAGEMENT, deepClone(state.subjectManagement));
        dispatchChange(KEYS.FACULTY_PAPERS, deepClone(state.facultyAcknowledgementPapers));
        dispatchChange(KEYS.FACULTY_REPORT_ACCESS, deepClone(state.facultyReportAccess));
        dispatchChange(KEYS.PROFESSOR_EVALUATION_COUNTS, deepClone(state.professorEvaluationCounts));
        dispatchChange('profileData', deepClone(state.profileData));
        dispatchChange('profilePhoto', state.profilePhotos);
    }

    function uploadProfilePhoto(file) {
        startBootstrap(false);

        if (!file) {
            throw new Error('Please choose an image file to upload.');
        }

        let session = getSessionWithCsrfTokenSync();
        let response;
        try {
            response = sendProfilePhotoUploadRequest(file, session.csrfToken);
        } catch (error) {
            if (isInvalidCsrfResponse(error && error.status, error && error.message)) {
                session = getSessionWithCsrfTokenSync(true);
                response = sendProfilePhotoUploadRequest(file, session.csrfToken);
            } else {
                if (error && responseEndedAuthenticatedSession(error.status, error.response)) {
                    handleRequestAuthFailure();
                }
                throw error;
            }
        }

        return applyProfilePhotoUploadResponse(response);
    }

    function uploadProfilePhotoAsync(file, options) {
        startBootstrap(false);

        if (!file) {
            return Promise.reject(new Error('Please choose an image file to upload.'));
        }

        const opts = options && typeof options === 'object' ? options : {};
        const loadingOverlay = typeof window !== 'undefined' ? window.AppLoadingOverlay : null;
        const useOverlay = opts.showOverlay !== false
            && loadingOverlay
            && typeof loadingOverlay.show === 'function'
            && typeof loadingOverlay.hide === 'function';
        if (useOverlay) {
            loadingOverlay.show(opts.message || 'Uploading profile photo...');
        }

        return new Promise(function (resolve) {
            setTimeout(resolve, 0);
        }).then(function () {
            const session = getSessionWithCsrfTokenSync();
            return sendProfilePhotoUploadRequestAsync(file, session.csrfToken)
                .catch(function (error) {
                    if (!isInvalidCsrfResponse(error && error.status, error && error.message)) {
                        if (error && responseEndedAuthenticatedSession(error.status, error.response)) {
                            handleRequestAuthFailure();
                        }
                        throw error;
                    }

                    const refreshedSession = getSessionWithCsrfTokenSync(true);
                    return sendProfilePhotoUploadRequestAsync(file, refreshedSession.csrfToken);
                });
        }).then(function (response) {
            return applyProfilePhotoUploadResponse(response);
        }).finally(function () {
            if (useOverlay) {
                loadingOverlay.hide();
            }
        });
    }

    function applyProfilePhotoUploadResponse(response) {
        if (response && response.session) {
            storeSessionPayload(response.session);
        }

        const savedUrl = typeof response.profileImageUrl === 'string'
            ? response.profileImageUrl
            : (typeof response.profilePhoto === 'string' ? response.profilePhoto : '');
        profilePhotoStateVersion += 1;
        state.profilePhotos = appendProfilePhotoCacheBust(savedUrl);
        if (response && response.user && typeof response.user === 'object') {
            updateCachedUserRecord(Object.assign({}, response.user, {
                profileImageUrl: state.profilePhotos,
                profilePhoto: state.profilePhotos,
                photoData: state.profilePhotos,
            }));
        }
        if (state.profilePhotos) {
            patchSessionData({
                profileImageUrl: state.profilePhotos,
                profilePhoto: state.profilePhotos,
                profileImage: '',
            });
        }
        dispatchChange('profilePhoto', state.profilePhotos);

        return state.profilePhotos || null;
    }

    function clearProfilePhotoState() {
        profilePhotoStateVersion += 1;
        state.profilePhotos = null;
        dispatchChange('profilePhoto', state.profilePhotos);
    }

    function isInvalidCsrfResponse(status, message) {
        return Number(status) === 403 && /invalid\s+csrf\s+token/i.test(String(message || ''));
    }

    function appendProfilePhotoCacheBust(urlValue) {
        const rawUrl = String(urlValue || '').trim();
        if (!rawUrl) {
            return '';
        }
        if (/^data:/i.test(rawUrl)) {
            return rawUrl;
        }

        const version = String(Date.now());
        if (typeof URL === 'function' && typeof window !== 'undefined' && window.location) {
            try {
                const parsed = new URL(rawUrl, window.location.href);
                parsed.searchParams.set('_profile_ts', version);
                if (parsed.origin === window.location.origin) {
                    return parsed.pathname + parsed.search + parsed.hash;
                }
                return parsed.toString();
            } catch (_error) {
                // Fall through to the string-based cache buster.
            }
        }

        const hashIndex = rawUrl.indexOf('#');
        const hash = hashIndex >= 0 ? rawUrl.slice(hashIndex) : '';
        const base = hashIndex >= 0 ? rawUrl.slice(0, hashIndex) : rawUrl;
        return base + (base.indexOf('?') >= 0 ? '&' : '?') + '_profile_ts=' + encodeURIComponent(version) + hash;
    }

    function getSessionWithCsrfTokenSync(forceRefresh) {
        const force = forceRefresh === true;
        let session = getSession();
        if (!force && session && session.isAuthenticated === true && String(session.csrfToken || '').trim()) {
            return session;
        }

        try {
            session = refreshSession(true);
        } catch (error) {
            throw error;
        }

        if (session && session.isAuthenticated === true && String(session.csrfToken || '').trim()) {
            return session;
        }

        throw new Error('Authentication token is not ready. Please refresh and sign in again.');
    }

    function sendProfilePhotoUploadRequest(file, csrfToken) {
        const xhr = new XMLHttpRequest();
        xhr.open('POST', PROFILE_IMAGE_UPLOAD_URL, false);

        const token = String(csrfToken || '').trim();
        if (token) {
            xhr.setRequestHeader('X-CSRF-Token', token);
        }

        const formData = new FormData();
        formData.append('profile_image', file);
        xhr.send(formData);

        let parsed = {};
        if (xhr.responseText) {
            try {
                parsed = JSON.parse(xhr.responseText);
            } catch (_error) {
                parsed = {};
            }
        }

        if (xhr.status < 200 || xhr.status >= 300) {
            const message = parsed && parsed.error
                ? String(parsed.error)
                : (xhr.responseText || ('Upload failed with status ' + xhr.status));
            const error = new Error(message);
            error.status = xhr.status;
            error.response = parsed;
            throw error;
        }

        return parsed;
    }

    function sendProfilePhotoUploadRequestAsync(file, csrfToken) {
        const token = String(csrfToken || '').trim();
        const headers = {};
        if (token) {
            headers['X-CSRF-Token'] = token;
        }

        const formData = new FormData();
        formData.append('profile_image', file);

        return fetch(PROFILE_IMAGE_UPLOAD_URL, {
            method: 'POST',
            headers,
            body: formData,
            credentials: 'same-origin',
        }).then(function (response) {
            return response.text().then(function (text) {
                let parsed = {};
                if (text) {
                    try {
                        parsed = JSON.parse(text);
                    } catch (_error) {
                        parsed = {};
                    }
                }

                if (!response.ok) {
                    const message = parsed && parsed.error
                        ? String(parsed.error)
                        : (text || ('Upload failed with status ' + response.status));
                    const error = new Error(message);
                    error.status = response.status;
                    error.response = parsed;
                    throw error;
                }

                return parsed;
            });
        });
    }

    function setProfilePhoto(role, dataUrl) {
        if (typeof File !== 'undefined' && dataUrl instanceof File) {
            return uploadProfilePhoto(dataUrl);
        }

        if (dataUrl && typeof dataUrl === 'object' && typeof dataUrl.name === 'string') {
            return uploadProfilePhoto(dataUrl);
        }

        startBootstrap(false);
        profilePhotoStateVersion += 1;
        state.profilePhotos = dataUrl || '';
        dispatchChange('profilePhoto', state.profilePhotos);
        try {
            const response = syncRequest('POST', 'setProfilePhoto', { dataUrl: dataUrl || '' });
            if (response && Object.prototype.hasOwnProperty.call(response, 'profilePhoto')) {
                profilePhotoStateVersion += 1;
                state.profilePhotos = appendProfilePhotoCacheBust(response.profilePhoto || '');
                dispatchChange('profilePhoto', state.profilePhotos);
            }
        } catch (error) {
            console.error('[DBData] Failed to persist profile photo.', error);
        }

        return state.profilePhotos || null;
    }

    function getProfilePhoto() {
        startBootstrap(false);
        if (state.profilePhotos) {
            return state.profilePhotos;
        }

        const session = getSession();
        const sessionPhoto = String(session && (session.profileImageUrl || session.profilePhoto) || '').trim();
        if (sessionPhoto) {
            state.profilePhotos = sessionPhoto;
            return state.profilePhotos;
        }

        return null;
    }

    function setProfilePhotoFromResponse(urlValue) {
        profilePhotoStateVersion += 1;
        state.profilePhotos = typeof urlValue === 'string' && urlValue.trim() ? appendProfilePhotoCacheBust(urlValue) : null;
        dispatchChange('profilePhoto', state.profilePhotos);
        return state.profilePhotos;
    }

    function sendLogoutRequest(session) {
        const payload = JSON.stringify({ action: 'logout' });
        const headers = {
            'Content-Type': 'application/json',
        };
        const csrfToken = String(session && session.csrfToken || '').trim();
        if (csrfToken) {
            headers['X-CSRF-Token'] = csrfToken;
        }

        if (typeof fetch === 'function') {
            return fetch(LOGIN_API_URL, {
                method: 'POST',
                headers: headers,
                body: payload,
                credentials: 'same-origin',
                keepalive: true,
            }).then(function (response) {
                return response.ok;
            }).catch(function (error) {
                console.warn('[DBData] Logout request failed.', error);
                return false;
            });
        }

        return new Promise(function (resolve) {
            try {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', LOGIN_API_URL, true);
                xhr.setRequestHeader('Content-Type', 'application/json');
                if (csrfToken) {
                    xhr.setRequestHeader('X-CSRF-Token', csrfToken);
                }
                xhr.onreadystatechange = function () {
                    if (xhr.readyState === 4) {
                        resolve(xhr.status >= 200 && xhr.status < 300);
                    }
                };
                xhr.onerror = function () {
                    resolve(false);
                };
                xhr.send(payload);
            } catch (error) {
                console.warn('[DBData] Logout request failed.', error);
                resolve(false);
            }
        });
    }

    function clearSession(options) {
        const config = options && typeof options === 'object' ? options : {};
        const session = getSession();
        const notifyServer = !config.localOnly;
        let logoutPromise = Promise.resolve(true);
        if (notifyServer) {
            setLogoutPendingMarker();
            logoutPromise = sendLogoutRequest(session).finally(function () {
                sessionLogoutInProgress = false;
            });
        }

        sessionLogoutInProgress = true;
        try {
            initialized = false;
            usersLastSyncedAt = 0;
            evaluationsLastSyncedAt = 0;
            subjectManagementLastSyncedAt = 0;
            clearSessionCache();
            clearProfilePhotoState();
        } finally {
            if (!notifyServer) {
                sessionLogoutInProgress = false;
            }
        }
        return logoutPromise;
    }
    

    function applySubjectManagementSnapshot(payload) {
        const snapshot = payload && payload.subjectManagement ? payload.subjectManagement : payload;
        if (!snapshot || typeof snapshot !== 'object') {
            return state.subjectManagement;
        }

        state.subjectManagement = {
            subjects: Array.isArray(snapshot.subjects) ? snapshot.subjects : [],
            offerings: Array.isArray(snapshot.offerings) ? snapshot.offerings : [],
            enrollments: Array.isArray(snapshot.enrollments) ? snapshot.enrollments : [],
        };
        subjectManagementLastSyncedAt = Date.now();
        markBootstrapDatasetComplete('subjectManagement');
        dispatchChange(KEYS.SUBJECT_MANAGEMENT, deepClone(state.subjectManagement));
        return state.subjectManagement;
    }

    function refreshSession(forceRefresh) {
        const cached = getSession();
        if (cached && !forceRefresh) {
            return cached;
        }

        const xhr = new XMLHttpRequest();
        xhr.open('GET', SESSION_URL + '&_ts=' + Date.now(), false);
        xhr.send(null);

        if (xhr.status >= 200 && xhr.status < 300) {
            const payload = xhr.responseText ? JSON.parse(xhr.responseText) : {};
            setClockReference(payload);
            if (payload && payload.authenticated === false) {
                clearSessionCache();
                clearProfilePhotoState();
                return null;
            }
            const sessionPayload = extractSessionPayloadFromResponse(payload);
            if (!sessionPayload) {
                clearSessionCache();
                clearProfilePhotoState();
                return null;
            }
            return storeSessionPayload(sessionPayload);
        }

        if (xhr.status === 401 || xhr.status === 403) {
            initialized = false;
            usersLastSyncedAt = 0;
            evaluationsLastSyncedAt = 0;
            subjectManagementLastSyncedAt = 0;
            clearSessionCache();
            clearProfilePhotoState();
            return null;
        }

        let message = 'Session validation failed with status ' + xhr.status;
        if (xhr.responseText) {
            try {
                const parsed = JSON.parse(xhr.responseText);
                message = parsed && parsed.error ? String(parsed.error) : message;
            } catch (_error) {
                message = xhr.responseText;
            }
        }
        throw new Error(message);
    }

    function requireSession(expectedRole) {
        let session = null;
        try {
            session = refreshSession(true);
        } catch (error) {
            console.warn('[DBData] Session validation failed.', error);
            return null;
        }
        if (!session || session.isAuthenticated !== true) {
            return null;
        }
        const requiredRole = String(expectedRole || '').trim().toLowerCase();
        if (requiredRole && String(session.role || '').trim().toLowerCase() !== requiredRole) {
            return null;
        }
        return session;
    }

    function startBootstrap(forceRefresh) {
        if (initialized && !forceRefresh) {
            return Promise.resolve(true);
        }
        if (bootstrapPromise && !forceRefresh) {
            return bootstrapPromise;
        }

        const requestSessionGeneration = sessionClearGeneration;
        const requestProfilePhotoVersion = profilePhotoStateVersion;
        bootstrapPromise = requestJson('GET', 'bootstrap', null, { background: true })
            .then(function (response) {
                if (response && response.success && response.state) {
                    applyBootstrap(response.state, {
                        preserveProfilePhoto: profilePhotoStateVersion !== requestProfilePhotoVersion,
                    });
                    if (requestSessionGeneration === sessionClearGeneration && !sessionLogoutInProgress && response.session) {
                        storeSessionPayload(response.session);
                    }
                    initialized = true;
                    return true;
                }
                initialized = true;
                return false;
            })
            .catch(function (error) {
                if (!error || (error.status !== 401 && error.status !== 403)) {
                    console.warn(
                        '[DBData] Bootstrap failed. Open the site through Apache/XAMPP over http://localhost so ../api/app_state.php can run.',
                        error
                    );
                }
                initialized = true;
                return false;
            })
            .finally(function () {
                bootstrapPromise = null;
            });

        return bootstrapPromise;
    }

    function refreshBootstrap(forceRefresh) {
        return startBootstrap(forceRefresh !== false);
    }

    function bootstrap(forceRefresh) {
        startBootstrap(!!forceRefresh);
        return initialized;
    }

    function getSessionStorage() {
        return window.localStorage;
    }

    function getJSON(key, fallback = null) {
        try {
            const storage = getSessionStorage();
            const raw = storage.getItem(key);
            if (raw === null) return fallback;
            return JSON.parse(raw);
        } catch (error) {
            return fallback;
        }
    }

    function setJSON(key, value) {
        const storage = getSessionStorage();
        storage.setItem(key, JSON.stringify(value));
        dispatchChange(key, value);
    }

    function writeLocalFallbackJSON(key, value) {
        try {
            const storage = getSessionStorage();
            storage.setItem(key, JSON.stringify(value));
        } catch (_error) {
            // Local fallback is best-effort only.
        }
    }

    function hydrateLocalFallbackState() {
        const localSettings = getJSON(KEYS.SETTINGS, null);
        if (localSettings && typeof localSettings === 'object') {
            state.settings = Object.assign({}, state.settings, localSettings);
        }

        const localEvalPeriods = getJSON(KEYS.EVAL_PERIODS, null);
        if (localEvalPeriods && typeof localEvalPeriods === 'object') {
            state.evalPeriods = Object.assign({}, state.evalPeriods, localEvalPeriods);
        }

        const localSemesterList = getJSON(KEYS.SEMESTER_LIST, null);
        if (Array.isArray(localSemesterList)) {
            state.semesterList = localSemesterList;
        }

        const localCurrentSemester = getJSON(KEYS.CURRENT_SEMESTER, null);
        if (typeof localCurrentSemester === 'string') {
            state.currentSemester = localCurrentSemester;
        }

        const localQuestionnaires = getJSON(KEYS.QUESTIONNAIRES, null);
        if (localQuestionnaires && typeof localQuestionnaires === 'object') {
            state.questionnaires = localQuestionnaires;
        }
    }

    function persistLocalSettingsFallback() {
        writeLocalFallbackJSON(KEYS.SETTINGS, state.settings || {});
        writeLocalFallbackJSON(KEYS.EVAL_PERIODS, state.evalPeriods || {});
        writeLocalFallbackJSON(KEYS.SEMESTER_LIST, state.semesterList || []);
        writeLocalFallbackJSON(KEYS.CURRENT_SEMESTER, state.currentSemester || '');
        writeLocalFallbackJSON(KEYS.QUESTIONNAIRES, state.questionnaires || {});
    }

    function remove(key) {
        const storage = getSessionStorage();
        storage.removeItem(key);
        dispatchChange(key, null);
    }

    function getSession() {
        const session = getJSON(KEYS.USER_SESSION, null);
        if (!session || typeof session !== 'object') {
            return session;
        }

        const storedToken = String(session.csrfToken || '').trim();
        let cleanedSession = session;
        if (storedToken) {
            if (!sessionCsrfToken) {
                sessionCsrfToken = storedToken;
            }
            cleanedSession = sanitizeSessionForStorage(session);
            setJSON(KEYS.USER_SESSION, cleanedSession);
        }

        if (sessionCsrfToken) {
            return Object.assign({}, cleanedSession, { csrfToken: sessionCsrfToken });
        }

        return cleanedSession;
    }

    function setSession(username, role, extra = {}) {
        clearLogoutPendingMarker();
        if (username && typeof username === 'object') {
            return storeSessionPayload(username);
        }
        const session = Object.assign({
            username,
            role,
            loginTime: getNowIsoString(),
            isAuthenticated: true,
        }, extra);
        return storeSessionPayload(session);
    }

    function isAuthenticated() {
        const session = getSession();
        return !!(session && session.isAuthenticated === true && session.role);
    }

    function getRole() {
        const session = getSession();
        return session ? session.role : null;
    }

    function getUsername() {
        const session = getSession();
        return session ? session.username : null;
    }

    function getProfileData() {
        startBootstrap(false);
        return state.profileData || null;
    }

    function setProfileData(role, data) {
        startBootstrap(false);
        state.profileData = data && typeof data === 'object' ? data : null;
        dispatchChange('profileData', deepClone(state.profileData));
        try {
            const response = syncRequest('POST', 'setProfileData', { data: data || null });
            if (response && Object.prototype.hasOwnProperty.call(response, 'profileData')) {
                state.profileData = response.profileData && typeof response.profileData === 'object'
                    ? response.profileData
                    : null;
                dispatchChange('profileData', deepClone(state.profileData));
            }
        } catch (error) {
            console.error('[DBData] Failed to persist profile data.', error);
        }
    }

    function getUsers() {
        startBootstrap(false);
        const sessionRole = String((getSession() || {}).role || '').trim().toLowerCase();
        const canRequestUserDirectory = ['admin', 'hr', 'vpaa', 'osa'].includes(sessionRole);
        if (
            canRequestUserDirectory
            && (isBootstrapDatasetPartial('users') || (Date.now() - usersLastSyncedAt) >= USERS_CACHE_TTL_MS)
        ) {
            scheduleUsersRefresh({ forceRefresh: true, limit: 100, page: 1 });
        }
        return state.users;
    }

    function getCachedUsers() {
        return state.users;
    }

    function getUserListMeta() {
        return state.userListMeta && typeof state.userListMeta === 'object'
            ? Object.assign({}, state.userListMeta)
            : { total: Array.isArray(state.users) ? state.users.length : 0, limit: 0, offset: 0, page: 1, hasMore: false };
    }

    function getLastUsersPageMeta() {
        return getUserListMeta();
    }

    function getPrograms() {
        startBootstrap(false);
        return state.programs || [];
    }

    function buildRefreshKey(filters) {
        const source = filters && typeof filters === 'object' ? filters : {};
        return JSON.stringify(Object.keys(source).sort().reduce(function (output, key) {
            output[key] = source[key];
            return output;
        }, {}));
    }

    function filterCachedUsers(filters) {
        const cfg = filters && typeof filters === 'object' ? filters : {};
        const campus = String(cfg.campus || '').trim().toLowerCase();
        const search = String(cfg.search || cfg.term || '').trim().toLowerCase();
        const department = String(cfg.department || cfg.departmentCode || '').trim().toLowerCase();
        const program = String(cfg.program || cfg.programCode || '').trim().toLowerCase();
        const status = String(cfg.status || 'active').trim().toLowerCase();
        const roleValues = Array.isArray(cfg.roles)
            ? cfg.roles
            : (cfg.role ? [cfg.role] : []);
        const roleSet = roleValues.reduce(function (set, value) {
            const token = String(value || '').trim().toLowerCase();
            if (token && token !== 'all') {
                set[token] = true;
            }
            return set;
        }, {});
        const idValues = Array.isArray(cfg.userIds)
            ? cfg.userIds
            : (Array.isArray(cfg.ids) ? cfg.ids : (cfg.userId ? [cfg.userId] : []));
        const idSet = idValues.reduce(function (set, value) {
            const token = String(value || '').trim().toLowerCase();
            if (token) {
                set[token] = true;
                set[token.replace(/^u/i, '')] = true;
                set['u' + token.replace(/^u/i, '')] = true;
            }
            return set;
        }, {});
        const hasRoleFilter = Object.keys(roleSet).length > 0;
        const hasIdFilter = Object.keys(idSet).length > 0;
        const users = Array.isArray(state.users) ? state.users : [];

        const filtered = users.filter(function (user) {
            if (campus && campus !== 'all' && String(user && user.campus || '').trim().toLowerCase() !== campus) {
                return false;
            }
            if (hasRoleFilter && !roleSet[String(user && user.role || '').trim().toLowerCase()]) {
                return false;
            }
            if (department && department !== 'all') {
                const userDepartment = String((user && (user.department || user.institute)) || '').trim().toLowerCase();
                if (userDepartment !== department) return false;
            }
            if (program && program !== 'all') {
                const userProgram = String((user && (user.programCode || user.program)) || '').trim().toLowerCase();
                if (userProgram !== program) return false;
            }
            if (status && status !== 'all') {
                const userStatus = String(user && user.status || 'active').trim().toLowerCase();
                const normalizedStatus = userStatus === 'inactive' || (user && user.isActive === false) ? 'inactive' : 'active';
                if (normalizedStatus !== (status === 'inactive' ? 'inactive' : 'active')) return false;
            }
            if (hasIdFilter) {
                const userId = String(user && user.id || '').trim().toLowerCase();
                if (!idSet[userId] && !idSet[userId.replace(/^u/i, '')]) return false;
            }

            if (!search) {
                return true;
            }

            const haystacks = [
                String(user && user.name || '').trim().toLowerCase(),
                String(user && user.email || '').trim().toLowerCase(),
                String(user && user.role || '').trim().toLowerCase(),
                String(user && user.department || '').trim().toLowerCase(),
                String(user && user.institute || '').trim().toLowerCase(),
                String(user && user.programCode || '').trim().toLowerCase(),
                String(user && user.programName || '').trim().toLowerCase(),
                String(user && user.employeeId || '').trim().toLowerCase(),
                String(user && user.studentNumber || '').trim().toLowerCase(),
            ];

            return haystacks.some(function (value) {
                return value && value.indexOf(search) !== -1;
            });
        });

        const limit = Math.max(0, Number(cfg.limit) || 0);
        if (limit <= 0) {
            return filtered;
        }
        const page = Math.max(1, Number(cfg.page) || 1);
        const offset = Math.max(0, Number(cfg.offset) || ((page - 1) * limit));
        return filtered.slice(offset, offset + limit);
    }

    function hasMeaningfulUserListFilters(filters) {
        const cfg = filters && typeof filters === 'object' ? filters : {};
        const tokenKeys = ['campus', 'role', 'status', 'department', 'departmentCode', 'program', 'programCode', 'search', 'term', 'userId'];
        if (Array.isArray(cfg.roles) && cfg.roles.some(function (role) {
            const token = String(role || '').trim().toLowerCase();
            return token && token !== 'all';
        })) {
            return true;
        }
        if (Array.isArray(cfg.userIds) && cfg.userIds.length > 0) return true;
        if (Array.isArray(cfg.ids) && cfg.ids.length > 0) return true;

        return tokenKeys.some(function (key) {
            const value = String(cfg[key] == null ? '' : cfg[key]).trim().toLowerCase();
            return value !== '' && value !== 'all';
        });
    }

    function applyUsersResponse(response, options) {
        const opts = options && typeof options === 'object' ? options : {};
        if (response && Array.isArray(response.users)) {
            state.users = response.users;
            const responseLimit = Number(response.limit) || 0;
            const responseOffset = Number(response.offset) || 0;
            const responsePage = Number(response.page) || (responseLimit > 0 ? Math.floor(responseOffset / responseLimit) + 1 : 1);
            const responseTotal = Number(response.total);
            const total = Number.isFinite(responseTotal) && responseTotal >= 0 ? responseTotal : state.users.length;
            const hasMore = response.hasMore === true;
            state.userListMeta = {
                total,
                limit: responseLimit,
                offset: responseOffset,
                page: responsePage,
                hasMore,
            };
            usersLastSyncedAt = Date.now();
            if (opts.filtered || (responseLimit > 0 && (hasMore || responseOffset > 0 || total > state.users.length))) {
                markBootstrapDatasetPartial('users');
            } else {
                markBootstrapDatasetComplete('users');
            }
            dispatchChange(KEYS.USERS, deepClone(state.users));
        } else if (response && response.user && typeof response.user === 'object') {
            updateCachedUserRecord(response.user);
        } else if (response && response.softDeleted && response.deactivatedUserId) {
            updateCachedUserRecord({ id: response.deactivatedUserId, status: 'inactive', isActive: false });
        } else if (response && (response.deletedUserId || response.userId)) {
            removeCachedUserRecord(response.deletedUserId || response.userId);
        } else if (response && response.summary) {
            markBootstrapDatasetPartial('users');
        }
        return state.users;
    }

    function buildUsersPageResult(response, filters) {
        const users = response && Array.isArray(response.users)
            ? response.users
            : filterCachedUsers(filters);
        const meta = getUserListMeta();
        return {
            users,
            total: Number(meta.total) || users.length,
            limit: Number(meta.limit) || 0,
            offset: Number(meta.offset) || 0,
            page: Number(meta.page) || 1,
            hasMore: meta.hasMore === true,
        };
    }

    function refreshUsers(filters) {
        const normalizedFilters = filters && typeof filters === 'object' ? filters : {};
        const refreshKey = buildRefreshKey(normalizedFilters);
        if (refreshPromises.users && refreshPromiseKeys.users === refreshKey) {
            return refreshPromises.users;
        }

        startBootstrap(false);
        refreshPromiseKeys.users = refreshKey;
        refreshPromises.users = requestJson('POST', 'listUsers', { filters: normalizedFilters }, { background: true })
            .then(function (response) {
                applyUsersResponse(response, { filtered: hasMeaningfulUserListFilters(normalizedFilters) });
                return buildUsersPageResult(response, normalizedFilters);
            })
            .finally(function () {
                refreshPromises.users = null;
                refreshPromiseKeys.users = '';
        });
        return refreshPromises.users;
    }

    function refreshUserCount(filters) {
        const normalizedFilters = Object.assign({}, filters && typeof filters === 'object' ? filters : {});
        delete normalizedFilters.all;
        delete normalizedFilters.includeAll;
        delete normalizedFilters.offset;
        normalizedFilters.limit = 1;
        normalizedFilters.page = 1;

        return requestJson('POST', 'listUsers', { filters: normalizedFilters }, { background: true })
            .then(function (response) {
                return Math.max(0, Number(response && response.total) || 0);
            });
    }

    function fetchUsersPage(filters) {
        const normalizedFilters = Object.assign({}, filters && typeof filters === 'object' ? filters : {});
        delete normalizedFilters.all;
        delete normalizedFilters.includeAll;
        return requestJson('POST', 'listUsers', { filters: normalizedFilters }, { background: true })
            .then(function (response) {
                const users = Array.isArray(response && response.users) ? response.users : [];
                return {
                    users: users,
                    total: Math.max(0, Number(response && response.total) || users.length),
                    limit: Math.max(0, Number(response && response.limit) || 0),
                    offset: Math.max(0, Number(response && response.offset) || 0),
                    page: Math.max(1, Number(response && response.page) || Number(normalizedFilters.page) || 1),
                    hasMore: response && response.hasMore === true,
                };
            });
    }

    function scheduleUsersRefresh(filters) {
        refreshUsers(filters).catch(function (error) {
            console.warn('[DBData] Failed to refresh users.', error);
        });
    }

    function listUsers(filters) {
        startBootstrap(false);
        const normalizedFilters = filters && typeof filters === 'object' ? filters : {};
        const filterKeys = Object.keys(normalizedFilters).filter(function (key) {
            return key !== 'forceRefresh';
        });
        const shouldUseCache = Array.isArray(state.users)
            && state.users.length > 0
            && !isBootstrapDatasetPartial('users')
            && (Date.now() - usersLastSyncedAt) < USERS_CACHE_TTL_MS
            && filterKeys.length === 0
            && normalizedFilters.forceRefresh !== true;

        if (shouldUseCache) {
            return filterCachedUsers(normalizedFilters);
        }

        scheduleUsersRefresh(normalizedFilters);
        return filterCachedUsers(normalizedFilters);
    }

    function bulkUpsertUsers(users, meta) {
        const options = meta && typeof meta === 'object' ? meta : {};
        const body = Object.assign({}, meta && typeof meta === 'object' ? meta : {}, {
            users: Array.isArray(users) ? users : [],
        });
        startBootstrap(false);
        return requestJson('POST', 'bulkUpsertUsers', body).then(function (response) {
            const failedRows = Number(response && response.summary && response.summary.failed) || 0;
            if (failedRows > 0 && options.allowPartial !== true) {
                throw new Error(`${failedRows} user row(s) failed to save.`);
            }
            applyUsersResponse(response);
            return response || {};
        });
    }

    function bulkUpsertUsersLegacy(users) {
        return bulkUpsertUsers(users).then(function (response) {
            return applyUsersResponse(response);
        });
    }

    function setUsers(users) {
        return bulkUpsertUsersLegacy(users);
    }

    function setUsersStrict(users) {
        return bulkUpsertUsersLegacy(users);
    }

    function addUser(user) {
        startBootstrap(false);
        return requestJson('POST', 'createUser', { user: user || {} }).then(function (response) {
            if (response && response.user) {
                updateCachedUserRecord(response.user);
            }
            applyUsersResponse(response);
            return response && response.user ? response.user : null;
        });
    }

    function updateUser(idOrUser, updatedData) {
        startBootstrap(false);

        let id = idOrUser;
        let patch = updatedData;
        if (typeof idOrUser === 'object' && idOrUser !== null) {
            id = idOrUser.id;
            patch = idOrUser;
        }

        return requestJson('POST', 'updateUser', {
            userId: id,
            user: patch || {},
        }).then(function (response) {
            if (response && response.user) {
                updateCachedUserRecord(response.user);
            }
            applyUsersResponse(response);
            return response && response.user ? response.user : null;
        });
    }

    function deleteUser(id) {
        startBootstrap(false);
        return requestJson('POST', 'deleteUser', { userId: id }).then(function (response) {
            applyUsersResponse(response);
            return response || {};
        });
    }

    function getCampuses() {
        startBootstrap(false);
        return state.campuses;
    }

    function setCampuses(campuses) {
        startBootstrap(false);
        const requestedCampuses = Array.isArray(campuses)
            ? deepClone(campuses)
            : deepClone(state.campuses);
        const response = syncRequest('POST', 'setCampuses', { campuses: requestedCampuses });
        state.campuses = response && Array.isArray(response.campuses)
            ? response.campuses
            : requestedCampuses;
        dispatchChange(KEYS.CAMPUSES, deepClone(state.campuses));
        return state.campuses;
    }

    function upsertProgram(program) {
        startBootstrap(false);
        const response = syncRequest('POST', 'upsertProgram', { program: program || {} });
        if (response && Array.isArray(response.programs)) {
            state.programs = response.programs;
            dispatchChange(KEYS.PROGRAMS, deepClone(state.programs));
        }
        if (response && Array.isArray(response.users)) {
            state.users = response.users;
            dispatchChange(KEYS.USERS, deepClone(state.users));
        }
        return response || {};
    }

    function deleteProgram(programId) {
        startBootstrap(false);
        const response = syncRequest('POST', 'deleteProgram', { programId: programId });
        if (response && Array.isArray(response.programs)) {
            state.programs = response.programs;
            dispatchChange(KEYS.PROGRAMS, deepClone(state.programs));
        }
        if (response && Array.isArray(response.users)) {
            state.users = response.users;
            dispatchChange(KEYS.USERS, deepClone(state.users));
        }
        return response || {};
    }

    function getAllDepartments() {
        startBootstrap(false);
        const deptSet = new Set();
        state.campuses.forEach(function (campus) {
            if (!campus || campus.id === 'all' || !Array.isArray(campus.departments)) return;
            campus.departments.forEach(function (dept) {
                if (dept) {
                    deptSet.add(String(dept).trim().toUpperCase());
                }
            });
        });
        return Array.from(deptSet).sort();
    }

    function getProfessors() {
        startBootstrap(false);
        return state.users.filter(function (user) {
            return user.role === 'professor';
        });
    }

    function setProfessors(professors) {
        startBootstrap(false);
        const nonProfessors = state.users.filter(function (user) {
            return user.role !== 'professor';
        });
        const professorUsers = Array.isArray(professors) ? professors.map(function (professor) {
            return Object.assign({}, professor, { role: 'professor' });
        }) : [];
        state.users = nonProfessors.concat(professorUsers);
        return bulkUpsertUsers(professorUsers);
    }

    function getCurrentSemester() {
        startBootstrap(false);
        return state.currentSemester || '';
    }

    function setCurrentSemester(value) {
        startBootstrap(false);
        const response = syncRequest('POST', 'setCurrentSemester', { value: value || '' });
        state.currentSemester = response.currentSemester || value || '';
        writeLocalFallbackJSON(KEYS.CURRENT_SEMESTER, state.currentSemester);
        if (response.changed) {
            state.users = state.users.map(function (user) {
                const role = String(user && user.role || '').trim().toLowerCase();
                return role === 'admin' || role === 'hr'
                    ? user
                    : Object.assign({}, user, { status: 'inactive', isActive: false });
            });
            usersLastSyncedAt = 0;
            markBootstrapDatasetPartial('users');
            dispatchChange(KEYS.USERS, deepClone(state.users));
        }
        dispatchChange(KEYS.CURRENT_SEMESTER, state.currentSemester);
        return response;
    }

    function getQuestionnaires() {
        startBootstrap(false);
        return state.questionnaires || {};
    }

    function setQuestionnaires(data) {
        startBootstrap(false);
        state.questionnaires = data || {};
        writeLocalFallbackJSON(KEYS.QUESTIONNAIRES, state.questionnaires);
        dispatchChange(KEYS.QUESTIONNAIRES, deepClone(state.questionnaires));
        try {
            const response = syncRequest('POST', 'setQuestionnaires', { data: state.questionnaires });
            if (response && response.success && response.questionnaires) {
                state.questionnaires = response.questionnaires || {};
                if (response.dataPrivacyConsentNotice && typeof response.dataPrivacyConsentNotice === 'object') {
                    state.dataPrivacyConsentNotice = response.dataPrivacyConsentNotice;
                }
                if (response.dataPrivacyConsentNotices && typeof response.dataPrivacyConsentNotices === 'object') {
                    state.dataPrivacyConsentNotices = response.dataPrivacyConsentNotices;
                }
                writeLocalFallbackJSON(KEYS.QUESTIONNAIRES, state.questionnaires);
                dispatchChange(KEYS.QUESTIONNAIRES, deepClone(state.questionnaires));
            }
            return deepClone(state.questionnaires);
        } catch (error) {
            console.error('[DBData] Failed to persist questionnaires.', error);
            return false;
        }
    }

    function getEvaluations() {
        startBootstrap(false);
        if (isBootstrapDatasetPartial('evaluations') && (Date.now() - evaluationsLastSyncedAt) >= USERS_CACHE_TTL_MS) {
            scheduleEvaluationsRefresh({ forceRefresh: true, limit: 100, offset: 0 });
        }
        return state.evaluations || [];
    }

    function getCachedEvaluations() {
        return state.evaluations || [];
    }

    function applyEvaluationsResponse(response, options) {
        const opts = options && typeof options === 'object' ? options : {};
        if (response && Array.isArray(response.evaluations)) {
            state.evaluations = response.evaluations;
            evaluationsLastSyncedAt = Date.now();
            if (opts.partial || Number(response.limit) > 0 || Number(response.offset) > 0 || response.hasMore === true) {
                markBootstrapDatasetPartial('evaluations');
            } else {
                markBootstrapDatasetComplete('evaluations');
            }
            dispatchChange(KEYS.EVALUATIONS, deepClone(state.evaluations));
        }
        return state.evaluations || [];
    }

    function hasMeaningfulEvaluationListFilters(filters) {
        const cfg = filters && typeof filters === 'object' ? filters : {};
        const scalarKeys = [
            'semester',
            'semesterId',
            'evaluationType',
            'evaluatorUserId',
            'evaluateeUserId',
            'involvedUserId',
            'courseOfferingId',
        ];
        if (cfg.forceEmpty) return true;
        if (Array.isArray(cfg.scopeEvaluateeUserIds) && cfg.scopeEvaluateeUserIds.length > 0) return true;
        if (Array.isArray(cfg.courseOfferingIds) && cfg.courseOfferingIds.length > 0) return true;
        return scalarKeys.some(function (key) {
            const value = String(cfg[key] == null ? '' : cfg[key]).trim().toLowerCase();
            return value !== '' && value !== 'all';
        });
    }

    function refreshEvaluations(filters) {
        const normalizedFilters = Object.assign({}, filters || {});
        const refreshKey = buildRefreshKey(normalizedFilters);
        if (refreshPromises.evaluations && refreshPromiseKeys.evaluations === refreshKey) {
            return refreshPromises.evaluations;
        }

        startBootstrap(false);
        refreshPromiseKeys.evaluations = refreshKey;
        refreshPromises.evaluations = requestJson('POST', 'listEvaluations', {
            filters: normalizedFilters,
        }, { background: true }).then(function (response) {
            return applyEvaluationsResponse(response, {
                partial: Number(normalizedFilters.limit) > 0
                    || Number(normalizedFilters.offset) > 0
                    || hasMeaningfulEvaluationListFilters(normalizedFilters),
            });
        }).finally(function () {
            refreshPromises.evaluations = null;
            refreshPromiseKeys.evaluations = '';
        });
        return refreshPromises.evaluations;
    }

    function fetchEvaluationsSnapshot(filters) {
        const normalizedFilters = Object.assign({}, filters || {});
        startBootstrap(false);
        return requestJson('POST', 'listEvaluations', {
            filters: normalizedFilters,
        }, { background: true }).then(function (response) {
            return deepClone(Array.isArray(response && response.evaluations) ? response.evaluations : []);
        });
    }

    function fetchHrBehaviorAnalysis(filters) {
        startBootstrap(false);
        return requestJson('POST', 'analyzeEvaluationBehavior', {
            filters: Object.assign({}, filters || {}),
        }, { background: true }).then(function (response) {
            return deepClone(Array.isArray(response && response.evaluations) ? response.evaluations : []);
        });
    }

    function scheduleEvaluationsRefresh(filters) {
        refreshEvaluations(filters).catch(function (error) {
            console.warn('[DBData] Failed to refresh evaluations.', error);
        });
    }

    function listEvaluations(filters) {
        scheduleEvaluationsRefresh(Object.assign({}, filters || {}));
        return state.evaluations || [];
    }

    function persistEvaluations() {
        try {
            const session = getSession() || {};
            syncRequest('POST', 'setEvaluations', {
                evaluations: state.evaluations,
                allowBulkWrite: true,
                actorRole: session.role || '',
            });
            dispatchChange(KEYS.EVALUATIONS, deepClone(state.evaluations));
        } catch (error) {
            console.error('[DBData] Failed to persist evaluations.', error);
        }
    }

    function buildEvaluationPayload(evalData) {
        const session = getSession() || {};
        const payload = Object.assign({}, evalData || {});

        if (!payload.evaluatorUserId && session.userId) payload.evaluatorUserId = session.userId;
        if (!payload.evaluatorEmail && session.email) payload.evaluatorEmail = session.email;
        if (!payload.evaluatorUsername && session.username) payload.evaluatorUsername = session.username;
        if (!payload.evaluatorStudentNumber && session.studentNumber) payload.evaluatorStudentNumber = session.studentNumber;
        if (!payload.evaluatorEmployeeId && session.employeeId) payload.evaluatorEmployeeId = session.employeeId;
        if (!payload.evaluatorName && (session.fullName || session.username)) {
            payload.evaluatorName = session.fullName || session.username;
        }
        if (!payload.evaluatorRole && session.role) payload.evaluatorRole = session.role;
        return payload;
    }

    function addEvaluation(evalData) {
        startBootstrap(false);
        const payload = buildEvaluationPayload(evalData);

        const response = syncRequest('POST', 'addEvaluation', { evaluation: payload });
        if (!response || response.success !== true || !response.evaluation) {
            throw new Error(response && response.error ? response.error : 'Failed to save evaluation.');
        }

        state.evaluations.push(response.evaluation);
        dispatchChange(KEYS.EVALUATIONS, deepClone(state.evaluations));
        if (response.clearance) {
            applyOsaStudentClearanceRecord(response.clearance);
        }
        return response.evaluation;
    }

    function addEvaluationAsync(evalData) {
        startBootstrap(false);
        const payload = buildEvaluationPayload(evalData);

        return asyncRequest('POST', 'addEvaluation', { evaluation: payload }).then(function (response) {
            if (!response || response.success !== true || !response.evaluation) {
                throw new Error(response && response.error ? response.error : 'Failed to save evaluation.');
            }

            state.evaluations.push(response.evaluation);
            dispatchChange(KEYS.EVALUATIONS, deepClone(state.evaluations));
            if (response.clearance) {
                applyOsaStudentClearanceRecord(response.clearance);
            }
            return response.evaluation;
        });
    }

    function getStudentEvaluationDrafts() {
        startBootstrap(false);
        return deepClone(state.studentEvaluationDrafts || []);
    }

    function findStudentEvaluationDraftIndex(drafts, savedDraft) {
        const savedKey = String(savedDraft && savedDraft.draftKey || '').trim().toLowerCase();
        const savedStudentUserId = String(savedDraft && savedDraft.studentUserId || '').trim().toLowerCase();
        const savedStudentId = String(savedDraft && savedDraft.studentId || '').trim().toLowerCase();
        return drafts.findIndex(function (item) {
            if (!item) return false;
            const itemKey = String(item.draftKey || '').trim().toLowerCase();
            if (itemKey !== savedKey) return false;
            const itemStudentUserId = String(item.studentUserId || '').trim().toLowerCase();
            const itemStudentId = String(item.studentId || '').trim().toLowerCase();
            return (savedStudentUserId && itemStudentUserId === savedStudentUserId)
                || (savedStudentId && itemStudentId === savedStudentId);
        });
    }

    function applyStudentEvaluationDraftResponse(response) {
        if (response && response.draft) {
            const next = Array.isArray(state.studentEvaluationDrafts) ? [...state.studentEvaluationDrafts] : [];
            const savedDraft = response.draft;
            const index = findStudentEvaluationDraftIndex(next, savedDraft);
            if (index >= 0) {
                next[index] = savedDraft;
            } else {
                next.push(savedDraft);
            }
            state.studentEvaluationDrafts = next;
            markBootstrapDatasetComplete('studentEvaluationDrafts');
            dispatchChange(KEYS.STUDENT_EVAL_DRAFTS, deepClone(state.studentEvaluationDrafts));
        } else if (Array.isArray(response && response.studentEvaluationDrafts)) {
            state.studentEvaluationDrafts = response.studentEvaluationDrafts;
            markBootstrapDatasetComplete('studentEvaluationDrafts');
            dispatchChange(KEYS.STUDENT_EVAL_DRAFTS, deepClone(state.studentEvaluationDrafts));
        }
        return response || {};
    }

    function upsertStudentEvaluationDraft(draft) {
        startBootstrap(false);
        return asyncRequest('POST', 'upsertStudentEvaluationDraft', {
            draft: draft || {},
            includeDrafts: false,
        }, { background: true })
            .then(function (response) {
                return applyStudentEvaluationDraftResponse(response);
            });
    }

    function removeStudentEvaluationDraft(draftKey, studentIdentity) {
        startBootstrap(false);
        const payload = {
            draftKey: draftKey,
            studentUserId: studentIdentity && studentIdentity.studentUserId ? studentIdentity.studentUserId : '',
            studentId: studentIdentity && studentIdentity.studentId ? studentIdentity.studentId : '',
        };
        return asyncRequest('POST', 'removeStudentEvaluationDraft', payload, { background: true })
            .then(function (response) {
                if (Array.isArray(response && response.studentEvaluationDrafts)) {
                    state.studentEvaluationDrafts = response.studentEvaluationDrafts;
                    markBootstrapDatasetComplete('studentEvaluationDrafts');
                    dispatchChange(KEYS.STUDENT_EVAL_DRAFTS, deepClone(state.studentEvaluationDrafts));
                } else if (!response || response.success !== false) {
                    const draftKeyToken = String(draftKey || '').trim().toLowerCase();
                    const studentUserIdToken = String(payload.studentUserId || '').trim().toLowerCase();
                    const studentIdToken = String(payload.studentId || '').trim().toLowerCase();
                    state.studentEvaluationDrafts = (Array.isArray(state.studentEvaluationDrafts) ? state.studentEvaluationDrafts : []).filter(function (item) {
                        if (!item) return false;
                        if (String(item.draftKey || '').trim().toLowerCase() !== draftKeyToken) return true;
                        const itemUserId = String(item.studentUserId || '').trim().toLowerCase();
                        const itemStudentId = String(item.studentId || '').trim().toLowerCase();
                        return !((studentUserIdToken && itemUserId === studentUserIdToken)
                            || (studentIdToken && itemStudentId === studentIdToken));
                    });
                    markBootstrapDatasetComplete('studentEvaluationDrafts');
                    dispatchChange(KEYS.STUDENT_EVAL_DRAFTS, deepClone(state.studentEvaluationDrafts));
                }
                return response || {};
            });
    }

    function getDataPrivacyConsentNotice(questionnaireType) {
        startBootstrap(false);
        const typeToken = String(questionnaireType || '').trim();
        if (typeToken && state.dataPrivacyConsentNotices && state.dataPrivacyConsentNotices[typeToken]) {
            return deepClone(state.dataPrivacyConsentNotices[typeToken] || {});
        }
        return deepClone(state.dataPrivacyConsentNotice || {});
    }

    function getStudentDataPrivacyConsents() {
        startBootstrap(false);
        return deepClone(state.studentDataPrivacyConsents || []);
    }

    function hasStudentDataPrivacyConsent(semesterId, consentVersion, questionnaireType) {
        startBootstrap(false);
        const semesterToken = String(semesterId || state.currentSemester || '').trim().toLowerCase();
        const typeToken = String(questionnaireType || 'student-to-professor').trim();
        const notice = typeToken && state.dataPrivacyConsentNotices && state.dataPrivacyConsentNotices[typeToken]
            ? state.dataPrivacyConsentNotices[typeToken]
            : (state.dataPrivacyConsentNotice || {});
        if (notice && notice.enabled === false) {
            return true;
        }
        const versionToken = String(consentVersion || notice.version || '').trim().toLowerCase();
        if (!semesterToken || !versionToken) {
            return false;
        }

        return (state.studentDataPrivacyConsents || []).some(function (row) {
            if (!row) return false;
            return String(row.semesterId || '').trim().toLowerCase() === semesterToken
                && String(row.questionnaireType || 'student-to-professor').trim().toLowerCase() === typeToken.toLowerCase()
                && String(row.consentVersion || '').trim().toLowerCase() === versionToken;
        });
    }

    function recordStudentDataPrivacyConsent(payload) {
        startBootstrap(false);
        const response = syncRequest('POST', 'recordStudentDataPrivacyConsent', payload || {});
        if (Array.isArray(response && response.studentDataPrivacyConsents)) {
            state.studentDataPrivacyConsents = response.studentDataPrivacyConsents;
        } else if (response && response.consent) {
            const next = Array.isArray(state.studentDataPrivacyConsents) ? [...state.studentDataPrivacyConsents] : [];
            const consent = response.consent;
            const semesterToken = String(consent.semesterId || '').trim().toLowerCase();
            const typeToken = String(consent.questionnaireType || 'student-to-professor').trim().toLowerCase();
            const versionToken = String(consent.consentVersion || '').trim().toLowerCase();
            const index = next.findIndex(function (row) {
                return row
                    && String(row.semesterId || '').trim().toLowerCase() === semesterToken
                    && String(row.questionnaireType || 'student-to-professor').trim().toLowerCase() === typeToken
                    && String(row.consentVersion || '').trim().toLowerCase() === versionToken;
            });
            if (index >= 0) {
                next[index] = consent;
            } else {
                next.push(consent);
            }
            state.studentDataPrivacyConsents = next;
        }
        dispatchChange(KEYS.STUDENT_DATA_PRIVACY_CONSENTS, deepClone(state.studentDataPrivacyConsents));
        return response || {};
    }

    function getOsaStudentClearances() {
        startBootstrap(false);
        if (isBootstrapDatasetPartial('osaStudentClearances')) {
            scheduleOsaStudentClearancesRefresh();
        }
        return deepClone(state.osaStudentClearances || []);
    }

    function applyOsaStudentClearanceRecord(recordItem) {
        if (!recordItem || typeof recordItem !== 'object') return;
        const next = Array.isArray(state.osaStudentClearances) ? [...state.osaStudentClearances] : [];
        const recordReference = String(recordItem.clearanceReference || '').trim().toLowerCase();
        const recordPeriod = String(recordItem.evaluationPeriodId || '').trim();
        const recordSemester = String(recordItem.semesterId || '').trim().toLowerCase();
        const recordUser = String(recordItem.studentUserId || '').trim().toLowerCase();
        const idx = next.findIndex(function (item) {
            if (!item) return false;
            const itemReference = String(item.clearanceReference || '').trim().toLowerCase();
            if (recordReference && itemReference === recordReference) return true;
            const itemPeriod = String(item.evaluationPeriodId || '').trim();
            const itemSemester = String(item.semesterId || '').trim().toLowerCase();
            const itemUser = String(item.studentUserId || '').trim().toLowerCase();
            return recordUser && itemUser === recordUser && (
                (recordPeriod && itemPeriod === recordPeriod)
                || (!recordPeriod && recordSemester && itemSemester === recordSemester)
            );
        });
        if (idx >= 0) {
            next[idx] = recordItem;
        } else {
            next.push(recordItem);
        }
        state.osaStudentClearances = next;
        markBootstrapDatasetComplete('osaStudentClearances');
        dispatchChange(KEYS.OSA_STUDENT_CLEARANCES, deepClone(state.osaStudentClearances));
    }

    function refreshOsaStudentClearances() {
        if (refreshPromises.osaStudentClearances) {
            return refreshPromises.osaStudentClearances;
        }

        startBootstrap(false);
        refreshPromises.osaStudentClearances = requestJson(
            'POST',
            'listOsaStudentClearances',
            {},
            { background: true }
        ).then(function (response) {
            state.osaStudentClearances = Array.isArray(response && response.osaStudentClearances)
                ? response.osaStudentClearances
                : [];
            markBootstrapDatasetComplete('osaStudentClearances');
            dispatchChange(KEYS.OSA_STUDENT_CLEARANCES, deepClone(state.osaStudentClearances));
            return deepClone(state.osaStudentClearances);
        }).finally(function () {
            refreshPromises.osaStudentClearances = null;
        });

        return refreshPromises.osaStudentClearances;
    }

    function scheduleOsaStudentClearancesRefresh() {
        refreshOsaStudentClearances().catch(function (error) {
            console.warn('[DBData] Failed to refresh OSA student clearances.', error);
        });
    }

    function upsertOsaStudentClearance(record) {
        startBootstrap(false);
        const body = Object.assign({ record: record || {} }, buildActorPayload(record || {}));
        const response = syncRequest('POST', 'upsertOsaStudentClearance', body);
        if (Array.isArray(response && response.osaStudentClearances)) {
            state.osaStudentClearances = response.osaStudentClearances;
            markBootstrapDatasetComplete('osaStudentClearances');
            dispatchChange(KEYS.OSA_STUDENT_CLEARANCES, deepClone(state.osaStudentClearances));
        } else if (response && response.record) {
            applyOsaStudentClearanceRecord(response.record);
        }
        return response || {};
    }

    function verifyOsaStudentClearance(reference) {
        startBootstrap(false);
        return requestJson('POST', 'verifyOsaStudentClearance', {
            reference: String(reference || '').trim(),
        });
    }

    function getStudentEvaluationProofRequests() {
        startBootstrap(false);
        if (isBootstrapDatasetPartial('studentEvaluationProofRequests')) {
            scheduleStudentEvaluationProofRequestsRefresh();
        }
        return deepClone(state.studentEvaluationProofRequests || []);
    }

    function isStudentEvaluationProofRequestsReady() {
        return initialized && getBootstrapDatasetMeta('studentEvaluationProofRequests').partial === false;
    }

    function refreshStudentEvaluationProofRequests() {
        if (refreshPromises.studentEvaluationProofRequests) {
            return refreshPromises.studentEvaluationProofRequests;
        }

        startBootstrap(false);
        refreshPromises.studentEvaluationProofRequests = requestJson(
            'POST',
            'listStudentEvaluationProofRequests',
            {},
            { background: true }
        ).then(function (response) {
            state.studentEvaluationProofRequests = Array.isArray(response && response.studentEvaluationProofRequests)
                ? response.studentEvaluationProofRequests
                : [];
            markBootstrapDatasetComplete('studentEvaluationProofRequests');
            dispatchChange(
                KEYS.STUDENT_EVAL_PROOF_REQUESTS,
                deepClone(state.studentEvaluationProofRequests)
            );
            return deepClone(state.studentEvaluationProofRequests);
        }).finally(function () {
            refreshPromises.studentEvaluationProofRequests = null;
        });

        return refreshPromises.studentEvaluationProofRequests;
    }

    function scheduleStudentEvaluationProofRequestsRefresh() {
        refreshStudentEvaluationProofRequests().catch(function (error) {
            console.warn('[DBData] Failed to refresh student evaluation proof requests.', error);
        });
    }

    function submitStudentEvaluationProof(record) {
        startBootstrap(false);
        const body = Object.assign({ record: record || {} }, buildActorPayload(record || {}));
        const response = syncRequest('POST', 'submitStudentEvaluationProof', body);

        if (Array.isArray(response && response.studentEvaluationProofRequests)) {
            state.studentEvaluationProofRequests = response.studentEvaluationProofRequests;
            markBootstrapDatasetComplete('studentEvaluationProofRequests');
            dispatchChange(KEYS.STUDENT_EVAL_PROOF_REQUESTS, deepClone(state.studentEvaluationProofRequests));
        }
        if (Array.isArray(response && response.osaStudentClearances)) {
            state.osaStudentClearances = response.osaStudentClearances;
            markBootstrapDatasetComplete('osaStudentClearances');
            dispatchChange(KEYS.OSA_STUDENT_CLEARANCES, deepClone(state.osaStudentClearances));
        }

        return response || {};
    }

    function reviewStudentEvaluationProof(payload) {
        startBootstrap(false);
        const body = Object.assign({ payload: payload || {} }, buildActorPayload(payload || {}));
        const response = syncRequest('POST', 'reviewStudentEvaluationProof', body);

        if (Array.isArray(response && response.studentEvaluationProofRequests)) {
            state.studentEvaluationProofRequests = response.studentEvaluationProofRequests;
            markBootstrapDatasetComplete('studentEvaluationProofRequests');
            dispatchChange(KEYS.STUDENT_EVAL_PROOF_REQUESTS, deepClone(state.studentEvaluationProofRequests));
        }
        if (Array.isArray(response && response.osaStudentClearances)) {
            state.osaStudentClearances = response.osaStudentClearances;
            markBootstrapDatasetComplete('osaStudentClearances');
            dispatchChange(KEYS.OSA_STUDENT_CLEARANCES, deepClone(state.osaStudentClearances));
        }

        return response || {};
    }

    function getSubjectManagement() {
        startBootstrap(false);
        if (isBootstrapDatasetPartial('subjectManagement') && (Date.now() - subjectManagementLastSyncedAt) >= USERS_CACHE_TTL_MS) {
            scheduleSubjectManagementRefresh({});
        }
        return deepClone(state.subjectManagement);
    }

    function getCachedSubjectManagement() {
        return deepClone(state.subjectManagement);
    }

    function refreshSubjectManagement(filters) {
        const normalizedFilters = Object.assign({}, filters || {});
        const refreshKey = buildRefreshKey(normalizedFilters);
        if (refreshPromises.subjectManagement && refreshPromiseKeys.subjectManagement === refreshKey) {
            return refreshPromises.subjectManagement;
        }

        startBootstrap(false);
        refreshPromiseKeys.subjectManagement = refreshKey;
        refreshPromises.subjectManagement = requestJson('POST', 'listSubjectManagement', normalizedFilters, { background: true })
            .then(function (response) {
                return applySubjectManagementSnapshot(response);
            })
            .finally(function () {
                refreshPromises.subjectManagement = null;
                refreshPromiseKeys.subjectManagement = '';
            });
        return refreshPromises.subjectManagement;
    }

    function fetchSubjectManagementSnapshot(filters) {
        const normalizedFilters = Object.assign({}, filters || {});
        startBootstrap(false);
        return requestJson('POST', 'listSubjectManagement', normalizedFilters, { background: true })
            .then(function (response) {
                const snapshot = response && response.subjectManagement
                    ? response.subjectManagement
                    : response;
                return deepClone({
                    subjects: Array.isArray(snapshot && snapshot.subjects) ? snapshot.subjects : [],
                    offerings: Array.isArray(snapshot && snapshot.offerings) ? snapshot.offerings : [],
                    enrollments: Array.isArray(snapshot && snapshot.enrollments) ? snapshot.enrollments : [],
                });
            });
    }

    function scheduleSubjectManagementRefresh(filters) {
        refreshSubjectManagement(filters).catch(function (error) {
            console.warn('[DBData] Failed to refresh subject management data.', error);
        });
    }

    function getAdminDashboardSummary() {
        return state.adminDashboardSummary ? deepClone(state.adminDashboardSummary) : null;
    }

    function applyAdminDashboardSummaryResponse(response) {
        const summary = response && response.dashboardSummary && typeof response.dashboardSummary === 'object'
            ? response.dashboardSummary
            : (response && response.summary && typeof response.summary === 'object' ? response.summary : null);
        if (!summary) {
            return state.adminDashboardSummary ? deepClone(state.adminDashboardSummary) : null;
        }

        state.adminDashboardSummary = summary;
        dispatchChange(KEYS.ADMIN_DASHBOARD_SUMMARY, deepClone(state.adminDashboardSummary));
        return deepClone(state.adminDashboardSummary);
    }

    function refreshAdminDashboardSummary(filters) {
        const normalizedFilters = Object.assign({}, filters || {});
        const refreshKey = buildRefreshKey(normalizedFilters);
        if (refreshPromises.adminDashboardSummary && refreshPromiseKeys.adminDashboardSummary === refreshKey) {
            return refreshPromises.adminDashboardSummary;
        }

        startBootstrap(false);
        refreshPromiseKeys.adminDashboardSummary = refreshKey;
        refreshPromises.adminDashboardSummary = requestJson('POST', 'getAdminDashboardSummary', {
            filters: normalizedFilters,
        }, { background: true })
            .then(function (response) {
                return applyAdminDashboardSummaryResponse(response);
            })
            .finally(function () {
                refreshPromises.adminDashboardSummary = null;
                refreshPromiseKeys.adminDashboardSummary = '';
            });
        return refreshPromises.adminDashboardSummary;
    }

    function upsertSubject(subject) {
        startBootstrap(false);
        const response = syncRequest('POST', 'upsertSubject', { subject: subject || {} });
        applySubjectManagementSnapshot(response);
        return response;
    }

    function importSubjects(rows) {
        startBootstrap(false);
        const response = syncRequest('POST', 'importSubjects', { rows: Array.isArray(rows) ? rows : [] });
        applySubjectManagementSnapshot(response);
        return response;
    }

    function upsertCourseOffering(offering) {
        startBootstrap(false);
        const response = syncRequest('POST', 'upsertCourseOffering', { offering: offering || {} });
        applySubjectManagementSnapshot(response);
        return response;
    }

    function importCourseOfferings(rows, options) {
        startBootstrap(false);
        const payload = {
            rows: Array.isArray(rows) ? rows : [],
            replaceExisting: !!(options && options.replaceExisting),
        };
        const response = syncRequest('POST', 'importCourseOfferings', payload);
        applySubjectManagementSnapshot(response);
        return response;
    }

    function markExcessCourseOfferings(rows) {
        startBootstrap(false);
        const response = syncRequest('POST', 'markExcessCourseOfferings', {
            rows: Array.isArray(rows) ? rows : [],
        });
        applySubjectManagementSnapshot(response);
        return response;
    }

    function setCourseOfferingStudents(courseOfferingId, studentUserIds) {
        startBootstrap(false);
        return requestJson('POST', 'setCourseOfferingStudents', {
            courseOfferingId: courseOfferingId,
            studentUserIds: Array.isArray(studentUserIds) ? studentUserIds : [],
        }).then(function (response) {
            applySubjectManagementSnapshot(response);
            return response;
        });
    }

    function deactivateCourseOffering(courseOfferingId) {
        startBootstrap(false);
        const response = syncRequest('POST', 'deactivateCourseOffering', {
            courseOfferingId: courseOfferingId,
        });
        applySubjectManagementSnapshot(response);
        return response;
    }

    function getActivityLog() {
        startBootstrap(false);
        return state.activityLog || [];
    }

    function searchActivityLog(filters) {
        startBootstrap(false);
        const response = syncRequest('POST', 'searchActivityLog', {
            filters: Object.assign({}, filters || {}),
        });
        return Array.isArray(response && response.activityLog) ? response.activityLog : [];
    }

    function getCredentialDistributorConfig(actor) {
        startBootstrap(false);
        const body = buildActorPayload(actor || {});
        const response = syncRequest('POST', 'getCredentialDistributorConfig', body);
        const config = response && response.config ? response.config : {};
        return {
            host: String(config.host || ''),
            port: Number(config.port || 0),
            encryption: String(config.encryption || 'tls'),
            auth: config.auth !== false,
            username: String(config.username || ''),
            fromEmail: String(config.fromEmail || config.senderEmail || ''),
            fromName: String(config.fromName || config.senderName || ''),
            timeout: Number(config.timeout || 20),
            hasPassword: !!(config.hasPassword || config.hasAppPassword),
            source: String(config.source || 'database'),
            secretStatus: String(config.secretStatus || 'missing'),
            migrationRequired: !!config.migrationRequired,
        };
    }

    function saveCredentialDistributorConfig(config, actor) {
        startBootstrap(false);
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            config: Object.assign({}, config || {}),
        });
        const response = syncRequest('POST', 'saveCredentialDistributorConfig', body);
        const savedConfig = response && response.config ? response.config : {};
        return {
            host: String(savedConfig.host || ''),
            port: Number(savedConfig.port || 0),
            encryption: String(savedConfig.encryption || 'tls'),
            auth: savedConfig.auth !== false,
            username: String(savedConfig.username || ''),
            fromEmail: String(savedConfig.fromEmail || savedConfig.senderEmail || ''),
            fromName: String(savedConfig.fromName || savedConfig.senderName || ''),
            timeout: Number(savedConfig.timeout || 20),
            hasPassword: !!(savedConfig.hasPassword || savedConfig.hasAppPassword),
            source: String(savedConfig.source || 'database'),
            secretStatus: String(savedConfig.secretStatus || 'missing'),
            migrationRequired: !!savedConfig.migrationRequired,
        };
    }

    function normalizeOpenAiPanelAccessConfig(input) {
        const defaults = {
            admin: true,
            hr: true,
            vpaa: true,
            dean: true,
            procoor: true,
            professor: true,
        };
        const source = input && typeof input === 'object' ? input : {};
        Object.keys(defaults).forEach(function (role) {
            if (Object.prototype.hasOwnProperty.call(source, role)) {
                defaults[role] = source[role] !== false;
            }
        });
        return defaults;
    }

    function getOpenAiConfig(actor) {
        startBootstrap(false);
        const body = buildActorPayload(actor || {});
        const response = syncRequest('POST', 'getOpenAiConfig', body);
        const config = response && response.config ? response.config : {};
        return {
            model: String(config.model || 'gpt-5.6-luna'),
            timeoutMs: Number(config.timeoutMs || 30000),
            hasApiKey: !!config.hasApiKey,
            source: String(config.source || 'database'),
            secretStatus: String(config.secretStatus || 'missing'),
            migrationRequired: !!config.migrationRequired,
            panelAccess: normalizeOpenAiPanelAccessConfig(config.panelAccess),
        };
    }

    function saveOpenAiConfig(config, actor) {
        startBootstrap(false);
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            config: Object.assign({}, config || {}),
        });
        const response = syncRequest('POST', 'saveOpenAiConfig', body);
        const savedConfig = response && response.config ? response.config : {};
        return {
            model: String(savedConfig.model || 'gpt-5.6-luna'),
            timeoutMs: Number(savedConfig.timeoutMs || 30000),
            hasApiKey: !!savedConfig.hasApiKey,
            source: String(savedConfig.source || 'database'),
            secretStatus: String(savedConfig.secretStatus || 'missing'),
            migrationRequired: !!savedConfig.migrationRequired,
            panelAccess: normalizeOpenAiPanelAccessConfig(savedConfig.panelAccess),
        };
    }

    const getGeminiConfig = getOpenAiConfig;
    const saveGeminiConfig = saveOpenAiConfig;

    function getOpenAiPanelAccess(actor) {
        startBootstrap(false);
        const response = syncRequest('POST', 'getOpenAiPanelAccess', buildActorPayload(actor || {}));
        const access = response && response.access && typeof response.access === 'object'
            ? response.access
            : {};
        return {
            role: String(access.role || ''),
            enabled: access.enabled !== false,
        };
    }

    function summarizeFeedbackComments(payload, actor) {
        startBootstrap(false);
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            payload: payload && typeof payload === 'object' ? payload : {},
        });
        return asyncRequest('POST', 'summarizeFeedbackComments', body).then(function (response) {
            return {
                success: response && response.success === true,
                auditId: String(response && response.auditId || ''),
                disabled: !!(response && response.disabled),
                source: String(response && response.source || (response && response.summary && response.summary.source) || 'rule'),
                warning: String(response && response.warning || (response && response.summary && response.summary.warning) || ''),
                error: String(response && response.error || ''),
                summary: response && response.summary && typeof response.summary === 'object'
                    ? response.summary
                    : null,
            };
        });
    }

    function bulkDistributeCredentials(rows, actor) {
        startBootstrap(false);
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            rows: Array.isArray(rows) ? rows : [],
        });
        const response = syncRequest('POST', 'bulkDistributeCredentials', body);
        return {
            summary: response && response.summary ? response.summary : { total: 0, sent: 0, failed: 0 },
            failures: Array.isArray(response && response.failures) ? response.failures : [],
        };
    }

    function sendBulkTestGmail(subject, message, actor) {
        startBootstrap(false);
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            subject: String(subject || ''),
            message: String(message || ''),
        });
        const response = syncRequest('POST', 'sendBulkTestGmail', body);
        return {
            summary: response && response.summary ? response.summary : { total: 0, sent: 0, failed: 0 },
            failures: Array.isArray(response && response.failures) ? response.failures : [],
        };
    }

    function sendTestSmtpEmail(recipientEmail, subject, message, actor) {
        startBootstrap(false);
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            recipientEmail: String(recipientEmail || ''),
            subject: String(subject || ''),
            message: String(message || ''),
        });
        const response = syncRequest('POST', 'sendTestSmtpEmail', body);
        return {
            success: response && response.success === true,
            message: String(response && response.message || ''),
        };
    }

    function submitSystemReport(report) {
        startBootstrap(false);
        const payload = Object.assign({}, report || {});
        return asyncRequest('POST', 'submitSystemReport', { report: payload })
            .then(function (response) {
                return {
                    success: response && response.success === true,
                    reportCode: String(response && response.reportCode || ''),
                    emailStatus: String(response && response.emailStatus || 'failed'),
                    recipientEmail: String(response && response.recipientEmail || ''),
                    error: String(response && response.error || ''),
                };
            });
    }

    function listSystemHealthChecks(limit) {
        startBootstrap(false);
        const response = syncRequest('POST', 'listSystemHealthChecks', {
            limit: Math.max(1, Math.min(50, Number(limit) || 10)),
        });
        return {
            success: response && response.success === true,
            latest: response && response.latest ? response.latest : null,
            history: Array.isArray(response && response.history) ? response.history : [],
        };
    }

    function runSystemHealthCheck() {
        startBootstrap(false);
        const response = syncRequest('POST', 'runSystemHealthCheck', {});
        return {
            success: response && response.success === true,
            result: response && response.result ? response.result : null,
            history: Array.isArray(response && response.history) ? response.history : [],
        };
    }

    function analyzeBiasComments(filters, actor) {
        startBootstrap(false);
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            filters: Object.assign({}, filters || {}),
        });
        const response = syncRequest('POST', 'analyzeBiasComments', body);
        return {
            success: response && response.success === true,
            auditId: String(response && response.auditId || ''),
            summary: response && response.summary ? response.summary : { total: 0, constructive: 0, neutral: 0, biased: 0, source: 'rule' },
            items: Array.isArray(response && response.items) ? response.items : [],
        };
    }

    function listCredibilityReviews(filters) {
        return requestJson('POST', 'listCredibilityReviews', { filters: filters || {} }, { background: true });
    }
    function getCredibilityReview(evaluationId) {
        return requestJson('POST', 'getCredibilityReview', { evaluationId }, { background: true });
    }
    function reviewCredibilityEvaluations(evaluationIds, decision, note) {
        return requestJson('POST', 'reviewCredibilityEvaluations', { evaluationIds, decision, note: note || '' });
    }
    function getProfessorAnalyticsPayload(payload) {
        return syncRequest('POST', 'getProfessorAnalyticsPayload', { payload }).payload;
    }
    function evaluationTimingKey(type, target, semester) {
        const session = getSession() || {};
        return 'evaluationBehavior:' + [session.userId, semester, type, target].join('|');
    }
    function startEvaluationTiming(type, target, semester) {
        if (!target) return;
        const key = evaluationTimingKey(type, target, semester);
        const stored = sessionStorage.getItem(key);
        const age = Date.now() - Date.parse(stored || '');
        if (!Number.isFinite(age) || age < 0 || age >= 86400000) sessionStorage.setItem(key, getNowIsoString());
    }
    function buildEvaluationTiming(payload, questions) {
        const key = evaluationTimingKey(payload.evaluationType, payload.targetProfessorId, payload.semesterId);
        const startedAt = sessionStorage.getItem(key);
        if (!startedAt) throw new Error('Please select the evaluation target again to start timing. Your answers are preserved.');
        const duration = (Date.parse(payload.submittedAt) - Date.parse(startedAt)) / 1000;
        const answered = new Set(Object.keys(payload.ratings || {}).concat(Object.keys(payload.qualitative || {}))
            .filter(id => String((payload.ratings || {})[id] || (payload.qualitative || {})[id] || '').trim()));
        return { captureVersion: 1, startedAt, submittedAt: payload.submittedAt, durationSeconds: Number(duration.toFixed(3)),
            questionCount: questions.length, answeredCount: answered.size, secondsPerQuestion: Number((duration / answered.size).toFixed(6)) };
    }
    function clearEvaluationTiming(payload) {
        sessionStorage.removeItem(evaluationTimingKey(payload.evaluationType, payload.targetProfessorId, payload.semesterId));
    }

    async function analyzeEvaluationExplainability(payload, actor) {
        startBootstrap(false);
        // Only the scope is needed; the server loads all authorized feedback.
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            payload: {
                professor: { id: payload && payload.professor && payload.professor.id || '' },
                semesterId: payload && payload.semesterId || 'all',
            },
        });
        const response = await requestJson('POST', 'analyzeEvaluationExplainability', body, { background: true });
        const fallbackInsight = {
            ratingReview: 'No numeric rating review is available.',
            keywords: [],
            clusters: [],
            reasoning: ['No explainability details available.'],
            judgment: {
                label: 'Needs Improvement',
                rationale: 'Insufficient AI explainability data.',
                confidence: 0,
            },
            stats: {
                totalComments: 0,
                sourceCounts: {},
                overallRating: null,
                combinedAverage: null,
                averagesBySource: { student: null, professor: null, supervisor: null },
                responseRate: null,
                totalEvaluations: 0,
            },
        };

        return {
            success: response && response.success === true,
            auditId: String(response && response.auditId || ''),
            source: String(response && response.source || 'rule'),
            insight: response && response.insight && typeof response.insight === 'object'
                ? response.insight
                : fallbackInsight,
        };
    }

    function generateFacultyPaperSectionCRecommendations(payload) {
        startBootstrap(false);
        const response = syncRequest('POST', 'generateFacultyPaperSectionCRecommendations', payload || {});
        return {
            success: response && response.success === true,
            auditId: String(response && response.auditId || ''),
            source: String(response && response.source || 'rule'),
            weakAreas: Array.isArray(response && response.weakAreas) ? response.weakAreas : [],
            sectionC: response && response.sectionC && typeof response.sectionC === 'object'
                ? {
                    areas: String(response.sectionC.areas || ''),
                    activities: String(response.sectionC.activities || ''),
                    actionPlan: String(response.sectionC.actionPlan || ''),
                }
                : { areas: '', activities: '', actionPlan: '' },
            reasoning: Array.isArray(response && response.reasoning) ? response.reasoning : [],
            error: response && response.error ? String(response.error) : '',
        };
    }

    function normalizeAnnouncementToken(value) {
        return String(value == null ? '' : value).trim().toLowerCase();
    }

    function normalizeAnnouncementRole(value) {
        const role = normalizeAnnouncementToken(value);
        if (!role || role === 'all' || role === 'all-users' || role === 'all_users') {
            return '';
        }
        return ANNOUNCEMENT_ALLOWED_ROLES.includes(role) ? role : role;
    }

    function normalizeAnnouncementUserId(value) {
        const raw = normalizeAnnouncementToken(value);
        if (!raw) return '';
        if (/^u\d+$/.test(raw)) return raw;
        if (/^\d+$/.test(raw)) return 'u' + String(parseInt(raw, 10));
        return raw;
    }

    function normalizeAnnouncementReadBy(input) {
        const source = input && typeof input === 'object' && !Array.isArray(input) ? input : {};
        const readBy = {};
        Object.keys(source).forEach(function (key) {
            const normalizedKey = normalizeAnnouncementToken(key);
            if (!normalizedKey) return;
            const timestamp = String(source[key] || '').trim();
            readBy[normalizedKey] = timestamp || getNowIsoString();
        });
        return readBy;
    }

    function normalizeAnnouncementAudience(input) {
        const source = input && typeof input === 'object' ? input : {};
        const role = normalizeAnnouncementRole(source.role || source.targetRole || '');
        const campus = normalizeAnnouncementToken(source.campus || source.campusSlug || '');
        const programCode = normalizeAnnouncementToken(source.programCode || source.program || '');
        const studentCompletionRaw = normalizeAnnouncementToken(
            source.studentCompletion || source.completion || 'all'
        );
        const studentCompletion = studentCompletionRaw === 'completed' || studentCompletionRaw === 'not_completed'
            ? studentCompletionRaw
            : 'all';

        return {
            role: role,
            campus: campus === 'all' ? '' : campus,
            programCode: programCode === 'all' ? '' : programCode,
            studentCompletion: studentCompletion,
        };
    }

    function normalizeAnnouncementEntry(input, index) {
        const source = input && typeof input === 'object' ? input : {};
        const nowIso = getNowIsoString();
        const createdAt = String(source.createdAt || source.timestamp || nowIso).trim() || nowIso;
        const id = String(source.id || ('ANN-' + Date.now() + '-' + (Number(index) || 0))).trim();
        const audienceSource = source.audience && typeof source.audience === 'object' ? source.audience : source;

        return Object.assign({}, source, {
            id: id,
            title: String(source.title || '').trim() || 'Announcement',
            message: String(source.message || '').trim() || 'No details available.',
            timestamp: createdAt,
            createdAt: createdAt,
            createdByRole: normalizeAnnouncementToken(source.createdByRole || ''),
            createdByUserId: String(source.createdByUserId || '').trim(),
            audience: normalizeAnnouncementAudience(audienceSource),
            read: !!source.read,
            readBy: normalizeAnnouncementReadBy(source.readBy || {}),
        });
    }

    function normalizeAnnouncementList(items) {
        return (Array.isArray(items) ? items : [])
            .map(function (item, index) {
                return normalizeAnnouncementEntry(item, index);
            })
            .slice(0, 50);
    }

    function resolveCurrentUserFromSession(users, session) {
        const list = Array.isArray(users) ? users : [];
        const activeSession = session && typeof session === 'object' ? session : {};
        if (!list.length) return null;

        const sessionUserId = normalizeAnnouncementUserId(activeSession.userId);
        if (sessionUserId) {
            const byId = list.find(function (user) {
                return normalizeAnnouncementUserId(user && user.id) === sessionUserId;
            });
            if (byId) return byId;
        }

        const sessionEmail = normalizeAnnouncementToken(activeSession.email);
        if (sessionEmail) {
            const byEmail = list.find(function (user) {
                return normalizeAnnouncementToken(user && user.email) === sessionEmail;
            });
            if (byEmail) return byEmail;
        }

        const sessionEmployeeId = normalizeAnnouncementToken(activeSession.employeeId);
        if (sessionEmployeeId) {
            const byEmployeeId = list.find(function (user) {
                return normalizeAnnouncementToken(user && user.employeeId) === sessionEmployeeId;
            });
            if (byEmployeeId) return byEmployeeId;
        }

        const sessionStudentNumber = normalizeAnnouncementToken(activeSession.studentNumber);
        if (sessionStudentNumber) {
            const byStudentNumber = list.find(function (user) {
                return normalizeAnnouncementToken(user && user.studentNumber) === sessionStudentNumber;
            });
            if (byStudentNumber) return byStudentNumber;
        }

        const sessionUsername = normalizeAnnouncementToken(activeSession.username);
        if (sessionUsername) {
            const byName = list.find(function (user) {
                return normalizeAnnouncementToken(user && user.name) === sessionUsername;
            });
            if (byName) return byName;

            const byEmailAlias = list.find(function (user) {
                return normalizeAnnouncementToken(user && user.email) === sessionUsername;
            });
            if (byEmailAlias) return byEmailAlias;
        }

        const sessionFullName = normalizeAnnouncementToken(activeSession.fullName);
        if (sessionFullName) {
            const byFullName = list.find(function (user) {
                return normalizeAnnouncementToken(user && user.name) === sessionFullName;
            });
            if (byFullName) return byFullName;
        }

        return null;
    }

    function collectAnnouncementIdentityTokens(user, session) {
        const tokens = new Set();
        const add = function (value, isUserId) {
            const token = isUserId ? normalizeAnnouncementUserId(value) : normalizeAnnouncementToken(value);
            if (!token) return;
            tokens.add(token);
        };

        add(user && user.id, true);
        add(user && user.studentNumber, false);
        add(user && user.email, false);
        add(user && user.name, false);
        add(session && session.userId, true);
        add(session && session.studentNumber, false);
        add(session && session.email, false);
        add(session && session.username, false);

        return tokens;
    }

    function getAnnouncementCurrentUserKey(context) {
        const cfg = context && typeof context === 'object' ? context : {};
        const session = cfg.session || getSession() || {};
        const currentUser = cfg.currentUser || resolveCurrentUserFromSession(state.users || [], session);
        const userId = normalizeAnnouncementUserId((currentUser && currentUser.id) || session.userId);
        if (userId) return userId;

        const email = normalizeAnnouncementToken((currentUser && currentUser.email) || session.email);
        if (email) return 'email:' + email;

        return '';
    }

    function isAnnouncementReadForUser(announcement, userKey) {
        const key = normalizeAnnouncementToken(userKey);
        if (!key) return false;
        const readBy = normalizeAnnouncementReadBy(announcement && announcement.readBy);
        return Object.prototype.hasOwnProperty.call(readBy, key);
    }

    function decorateAnnouncementForUser(announcement, userKey) {
        const entry = normalizeAnnouncementEntry(announcement, 0);
        // Non-admin bootstrap responses intentionally omit the complete readBy map
        // and expose only the authenticated user's projected `read` state. Preserve
        // that server-authoritative value while still supporting admin/HR snapshots,
        // where readBy is available for client-side decoration.
        entry.read = entry.read || isAnnouncementReadForUser(entry, userKey);
        return entry;
    }

    function isAnnouncementStudentEvaluationRecord(evaluation) {
        const token = normalizeAnnouncementToken(
            (evaluation && evaluation.evaluatorRole) || (evaluation && evaluation.evaluationType)
        );
        return token === 'student' || token === 'student-to-professor';
    }

    function isAnnouncementRecordInSemester(recordSemesterValue, targetSemesterId) {
        const target = normalizeAnnouncementToken(targetSemesterId);
        if (!target) return true;
        const recordSemester = normalizeAnnouncementToken(recordSemesterValue);
        if (!recordSemester) return true;
        return recordSemester === target;
    }

    function resolveStudentCompletionStatusForUser(user, session, options) {
        const cfg = options && typeof options === 'object' ? options : {};
        const targetSemesterId = normalizeAnnouncementToken(cfg.semesterId || state.currentSemester || '');
        const subjectManagement = state.subjectManagement || {};
        const offerings = Array.isArray(subjectManagement.offerings) ? subjectManagement.offerings : [];
        const enrollments = Array.isArray(subjectManagement.enrollments) ? subjectManagement.enrollments : [];
        const evaluations = Array.isArray(state.evaluations) ? state.evaluations : [];

        const studentTokens = collectAnnouncementIdentityTokens(user, session);
        const activeOfferingIds = new Set();
        offerings.forEach(function (offering) {
            if (!offering || !offering.isActive) return;
            if (!isAnnouncementRecordInSemester(offering.semesterSlug, targetSemesterId)) return;
            const offeringId = normalizeAnnouncementToken(offering.id);
            if (offeringId) activeOfferingIds.add(offeringId);
        });

        const expectedPairs = new Set();
        enrollments.forEach(function (enrollment) {
            if (!enrollment) return;
            if (normalizeAnnouncementToken(enrollment.status) !== 'enrolled') return;
            const offeringId = normalizeAnnouncementToken(enrollment.courseOfferingId);
            if (!offeringId || !activeOfferingIds.has(offeringId)) return;

            const enrollmentTokens = [
                normalizeAnnouncementUserId(enrollment.studentUserId || enrollment.studentId),
                normalizeAnnouncementToken(enrollment.studentNumber),
                normalizeAnnouncementToken(enrollment.studentName)
            ].filter(Boolean);
            const matched = enrollmentTokens.some(function (token) {
                return studentTokens.has(token);
            });
            if (!matched) return;
            expectedPairs.add(offeringId);
        });

        const completedPairs = new Set();
        evaluations.forEach(function (evaluation) {
            if (!evaluation) return;
            if (!isAnnouncementStudentEvaluationRecord(evaluation)) return;
            if (!isAnnouncementRecordInSemester(evaluation.semesterId, targetSemesterId)) return;

            const offeringId = normalizeAnnouncementToken(evaluation.courseOfferingId);
            if (!offeringId || !expectedPairs.has(offeringId)) return;

            const evaluationTokens = [
                normalizeAnnouncementUserId(
                    evaluation.studentUserId
                    || evaluation.studentId
                    || evaluation.evaluatorUserId
                    || evaluation.evaluatorId
                ),
                normalizeAnnouncementToken(evaluation.evaluatorStudentNumber),
                normalizeAnnouncementToken(evaluation.studentNumber),
                normalizeAnnouncementToken(evaluation.evaluatorEmail),
                normalizeAnnouncementToken(evaluation.evaluatorUsername),
                normalizeAnnouncementToken(evaluation.evaluatorName)
            ].filter(Boolean);
            const matched = evaluationTokens.some(function (token) {
                return studentTokens.has(token);
            });
            if (!matched) return;
            completedPairs.add(offeringId);
        });

        const totalExpected = expectedPairs.size;
        const totalCompleted = completedPairs.size;
        const isCompleted = totalExpected > 0 && totalCompleted >= totalExpected;
        return {
            status: isCompleted ? 'completed' : 'not_completed',
            totalExpected: totalExpected,
            totalCompleted: totalCompleted,
            isCompleted: isCompleted,
        };
    }

    function announcementMatchesCurrentUser(announcement, context) {
        const entry = announcement && typeof announcement === 'object' ? announcement : {};
        const audience = normalizeAnnouncementAudience(entry.audience || {});
        const roleConstraint = audience.role;
        const campusConstraint = audience.campus;
        const programConstraint = audience.programCode;
        const completionConstraint = audience.studentCompletion;

        const session = context && context.session ? context.session : {};
        const currentUser = context && context.currentUser ? context.currentUser : null;
        const roleToken = normalizeAnnouncementToken(
            (currentUser && currentUser.role)
            || session.role
        );
        const campusToken = normalizeAnnouncementToken(
            (currentUser && (currentUser.campus || currentUser.campusSlug))
            || session.campus
            || session.campusSlug
        );
        const programToken = normalizeAnnouncementToken(
            (currentUser && (currentUser.programCode || currentUser.program))
            || session.programCode
            || session.program
        );

        if (roleConstraint && roleConstraint !== roleToken) return false;
        if (campusConstraint && campusConstraint !== campusToken) return false;
        if (programConstraint && programConstraint !== programToken) return false;

        if (completionConstraint !== 'all') {
            if (roleToken !== 'student') return false;
            const studentCompletion = resolveStudentCompletionStatusForUser(currentUser, session, context || {});
            if (studentCompletion.status !== completionConstraint) return false;
        }

        return true;
    }

    function getAnnouncements() {
        startBootstrap(false);
        state.announcements = normalizeAnnouncementList(state.announcements || []);
        return deepClone(state.announcements);
    }

    function getAnnouncementsForCurrentUser(options) {
        startBootstrap(false);
        const cfg = options && typeof options === 'object' ? options : {};
        const session = getSession() || {};
        const users = Array.isArray(state.users) ? state.users : [];
        const currentUser = resolveCurrentUserFromSession(users, session);
        const context = {
            session: session,
            currentUser: currentUser,
            semesterId: cfg.semesterId || state.currentSemester || '',
        };
        const userKey = getAnnouncementCurrentUserKey(context);

        state.announcements = normalizeAnnouncementList(state.announcements || []);
        const announcements = Array.isArray(state.announcements) ? state.announcements : [];
        const visible = announcements.filter(function (item) {
            return announcementMatchesCurrentUser(item, context);
        }).map(function (item) {
            return decorateAnnouncementForUser(item, userKey);
        });

        const limit = Number(cfg.limit);
        if (Number.isFinite(limit) && limit > 0) {
            return deepClone(visible.slice(0, limit));
        }

        return deepClone(visible);
    }

    function getUnreadAnnouncementsForCurrentUser(options) {
        const visible = getAnnouncementsForCurrentUser(options);
        return visible.filter(function (announcement) {
            return !announcement.read;
        });
    }

    function persistAnnouncements() {
        try {
            state.announcements = normalizeAnnouncementList(state.announcements || []);
            const response = syncRequest('POST', 'setAnnouncements', { announcements: state.announcements });
            if (response && Array.isArray(response.announcements)) {
                state.announcements = normalizeAnnouncementList(response.announcements);
            }
            dispatchChange(KEYS.ANNOUNCEMENTS, deepClone(state.announcements));
        } catch (error) {
            console.error('[DBData] Failed to persist announcements.', error);
        }
    }

    function addAnnouncement(announcement) {
        startBootstrap(false);
        const session = getSession() || {};
        const nowIso = getNowIsoString();
        const entry = Object.assign({
            id: 'ANN-' + Date.now(),
            timestamp: nowIso,
            createdAt: nowIso,
            createdByRole: normalizeAnnouncementToken(session.role || ''),
            createdByUserId: String(session.userId || '').trim(),
            audience: {
                role: '',
                campus: '',
                programCode: '',
                studentCompletion: 'all',
            },
            read: false,
        }, announcement || {});
        entry.readBy = normalizeAnnouncementReadBy(entry.readBy || {});
        entry.createdAt = String(entry.createdAt || entry.timestamp || nowIso);
        entry.timestamp = entry.createdAt;
        entry.createdByRole = normalizeAnnouncementToken(entry.createdByRole || session.role || '');
        entry.createdByUserId = String(entry.createdByUserId || session.userId || '').trim();
        entry.audience = normalizeAnnouncementAudience(entry.audience || {});
        const normalizedEntry = normalizeAnnouncementEntry(entry, 0);
        state.announcements.unshift(normalizedEntry);
        if (state.announcements.length > 50) {
            state.announcements.length = 50;
        }
        persistAnnouncements();
        return deepClone(normalizedEntry);
    }

    function markAnnouncementsRead(ids) {
        startBootstrap(false);
        const targetIds = (Array.isArray(ids) ? ids : [ids])
            .map(function (id) { return String(id || '').trim(); })
            .filter(Boolean);
        if (!targetIds.length) {
            return getAnnouncementsForCurrentUser();
        }

        const userKey = getAnnouncementCurrentUserKey();
        if (!userKey) {
            return getAnnouncementsForCurrentUser();
        }

        const targetIdSet = new Set(targetIds);
        const nowIso = getNowIsoString();
        state.announcements = normalizeAnnouncementList(state.announcements || []);
        state.announcements.forEach(function (announcement) {
            if (!targetIdSet.has(String(announcement.id || '').trim())) return;
            announcement.readBy = normalizeAnnouncementReadBy(announcement.readBy || {});
            announcement.readBy[userKey] = announcement.readBy[userKey] || nowIso;
        });

        try {
            const response = syncRequest('POST', 'markAnnouncementsRead', { ids: targetIds });
            if (response && Array.isArray(response.announcements)) {
                state.announcements = normalizeAnnouncementList(response.announcements);
            }
        } catch (error) {
            console.error('[DBData] Failed to mark announcements read.', error);
        }

        dispatchChange(KEYS.ANNOUNCEMENTS, deepClone(state.announcements));
        return getAnnouncementsForCurrentUser();
    }

    function markAnnouncementRead(id) {
        return markAnnouncementsRead([id]);
    }

    function getUnreadAnnouncementCount() {
        startBootstrap(false);
        return getUnreadAnnouncementsForCurrentUser().length;
    }

    function escapeAnnouncementHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function formatAnnouncementDateLabel(value) {
        const raw = String(value || '').trim();
        if (!raw) return 'Recent update';
        const parsed = new Date(raw);
        if (Number.isNaN(parsed.getTime())) return raw;
        return formatDateTimeInPhilippines(parsed);
    }

    function ensureAnnouncementLoginModal() {
        if (typeof document === 'undefined') return null;
        let modal = document.getElementById('naap-announcement-login-modal');
        if (modal) return modal;

        modal = document.createElement('div');
        modal.id = 'naap-announcement-login-modal';
        modal.className = 'naap-announcement-login-modal';
        modal.hidden = true;
        modal.innerHTML = `
            <div class="naap-announcement-login-dialog" role="dialog" aria-modal="true" aria-labelledby="naapAnnouncementLoginTitle">
                <div class="naap-announcement-login-header">
                    <div class="naap-announcement-login-title-wrap">
                        <span class="naap-announcement-login-icon" aria-hidden="true">
                            <i class="fas fa-bullhorn"></i>
                        </span>
                        <div>
                            <h2 id="naapAnnouncementLoginTitle">Announcements</h2>
                            <p>Important updates for your account.</p>
                        </div>
                    </div>
                    <button type="button" class="naap-announcement-login-close" aria-label="Dismiss announcements">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
                <div class="naap-announcement-login-list"></div>
                <div class="naap-announcement-login-actions">
                    <button type="button" class="naap-announcement-login-dismiss">Dismiss</button>
                </div>
            </div>
        `;
        document.body.appendChild(modal);

        const dismiss = function () {
            if (typeof modal.__announcementDismiss === 'function') {
                modal.__announcementDismiss();
            }
        };
        const closeBtn = modal.querySelector('.naap-announcement-login-close');
        const dismissBtn = modal.querySelector('.naap-announcement-login-dismiss');
        if (closeBtn) closeBtn.addEventListener('click', dismiss);
        if (dismissBtn) dismissBtn.addEventListener('click', dismiss);
        modal.addEventListener('click', function (event) {
            if (event.target === modal) dismiss();
        });
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && !modal.hidden) dismiss();
        });

        return modal;
    }

    function presentUnreadAnnouncementLoginPopup(cfg) {
        const session = getSession();
        if (!session || session.isAuthenticated !== true) return [];

        const unread = getUnreadAnnouncementsForCurrentUser({ limit: cfg.limit || 20 })
            .filter(function (announcement) {
                const id = String(announcement && announcement.id || '').trim();
                return id && !announcementPopupShownIds.has(id);
            });
        if (!unread.length) return [];

        unread.forEach(function (announcement) {
            announcementPopupShownIds.add(String(announcement.id || '').trim());
        });

        const modal = ensureAnnouncementLoginModal();
        if (!modal) return [];

        const list = modal.querySelector('.naap-announcement-login-list');
        const ids = unread.map(function (announcement) {
            return String(announcement.id || '').trim();
        }).filter(Boolean);

        if (list) {
            list.innerHTML = unread.map(function (announcement) {
                return `
                    <article class="naap-announcement-login-item">
                        <div class="naap-announcement-login-item-head">
                            <h3>${escapeAnnouncementHtml(announcement.title || 'Announcement')}</h3>
                            <span>${escapeAnnouncementHtml(formatAnnouncementDateLabel(announcement.timestamp || announcement.createdAt))}</span>
                        </div>
                        <p>${escapeAnnouncementHtml(announcement.message || 'No details available.')}</p>
                    </article>
                `;
            }).join('');
        }

        modal.__announcementDismiss = function () {
            modal.hidden = true;
            modal.classList.remove('is-open');
            markAnnouncementsRead(ids);
            if (typeof cfg.onDismiss === 'function') {
                cfg.onDismiss();
            }
        };
        modal.hidden = false;
        modal.classList.add('is-open');

        const dismissBtn = modal.querySelector('.naap-announcement-login-dismiss');
        if (dismissBtn) dismissBtn.focus();

        return deepClone(unread);
    }

    function showUnreadAnnouncementLoginPopup(options) {
        if (typeof document === 'undefined' || !document.body) return [];

        const cfg = options && typeof options === 'object' ? options : {};
        const bootstrapWasReady = initialized;
        const pendingBootstrap = startBootstrap(false);

        if (!bootstrapWasReady) {
            Promise.resolve(pendingBootstrap)
                .then(function () {
                    presentUnreadAnnouncementLoginPopup(cfg);
                })
                .catch(function (error) {
                    console.warn('[DBData] Unable to load login announcements.', error);
                });
            return [];
        }

        return presentUnreadAnnouncementLoginPopup(cfg);
    }

    function getSettings() {
        startBootstrap(false);
        return Object.assign({}, state.settings);
    }

    function updateSettings(partial) {
        startBootstrap(false);
        state.settings = Object.assign({}, state.settings, partial || {});
        writeLocalFallbackJSON(KEYS.SETTINGS, state.settings);
        dispatchChange(KEYS.SETTINGS, deepClone(state.settings));
        try {
            const response = syncRequest('POST', 'updateSettings', { settings: partial || {} });
            if (response && response.settings && typeof response.settings === 'object') {
                state.settings = Object.assign({}, state.settings, response.settings);
                writeLocalFallbackJSON(KEYS.SETTINGS, state.settings);
                dispatchChange(KEYS.SETTINGS, deepClone(state.settings));
            }
        } catch (error) {
            console.error('[DBData] Failed to persist settings.', error);
        }
        return state.settings;
    }

    function updateSettingsAsync(partial) {
        startBootstrap(false);
        const requestedSettings = partial && typeof partial === 'object' ? partial : {};
        return requestJson('POST', 'updateSettings', { settings: requestedSettings }).then(function (response) {
            const savedSettings = response && response.settings && typeof response.settings === 'object'
                ? response.settings
                : requestedSettings;
            state.settings = Object.assign({}, state.settings, savedSettings);
            writeLocalFallbackJSON(KEYS.SETTINGS, state.settings);
            dispatchChange(KEYS.SETTINGS, deepClone(state.settings));
            return Object.assign({}, state.settings);
        });
    }

    function getStudentEvaluationReminderConfig() {
        startBootstrap(false);
        return Object.assign({}, state.studentEvaluationReminderConfig, {
            allowedPlaceholders: Array.isArray(state.studentEvaluationReminderConfig.allowedPlaceholders)
                ? state.studentEvaluationReminderConfig.allowedPlaceholders.slice()
                : [],
        });
    }

    function updateStudentEvaluationReminderConfigAsync(config) {
        startBootstrap(false);
        const requestedConfig = config && typeof config === 'object' ? config : {};
        return requestJson('POST', 'updateStudentEvaluationReminderConfig', {
            config: requestedConfig,
        }).then(function (response) {
            const savedConfig = response && response.config && typeof response.config === 'object'
                ? response.config
                : requestedConfig;
            state.studentEvaluationReminderConfig = Object.assign(
                {},
                state.studentEvaluationReminderConfig,
                savedConfig
            );
            dispatchChange(
                KEYS.STUDENT_EVAL_REMINDER_CONFIG,
                deepClone(state.studentEvaluationReminderConfig)
            );
            return getStudentEvaluationReminderConfig();
        });
    }

    function getEvalPeriods() {
        startBootstrap(false);
        return Object.assign({}, state.evalPeriods);
    }

    function setEvalPeriods(periods) {
        startBootstrap(false);
        state.evalPeriods = Object.assign({}, state.evalPeriods, periods || {});
        writeLocalFallbackJSON(KEYS.EVAL_PERIODS, state.evalPeriods);
        dispatchChange(KEYS.EVAL_PERIODS, deepClone(state.evalPeriods));
        try {
            syncRequest('POST', 'setEvalPeriods', { periods: state.evalPeriods });
        } catch (error) {
            console.error('[DBData] Failed to persist evaluation periods.', error);
        }
    }

    function isEvalPeriodOpen(type) {
        const periods = getEvalPeriods();
        const period = periods[type];
        if (!period || !period.start || !period.end) return false;

        const today = getCurrentPhilippineDateYmd();
        return today !== '' && today >= period.start && today <= period.end;
    }

    function getEvalPeriodDates(type) {
        const periods = getEvalPeriods();
        return periods[type] || { start: '', end: '' };
    }

    function getSemesterList() {
        startBootstrap(false);
        return state.semesterList || [];
    }

    function setSemesterList(list) {
        startBootstrap(false);
        state.semesterList = Array.isArray(list) ? list : [];
        writeLocalFallbackJSON(KEYS.SEMESTER_LIST, state.semesterList);
        dispatchChange(KEYS.SEMESTER_LIST, deepClone(state.semesterList));
    }

    function addSemester(value, label) {
        startBootstrap(false);
        if (!state.semesterList.find(function (item) { return item.value === value; })) {
            syncRequest('POST', 'addSemester', { value, label });
            state.semesterList.push({ value, label });
            writeLocalFallbackJSON(KEYS.SEMESTER_LIST, state.semesterList);
            dispatchChange(KEYS.SEMESTER_LIST, deepClone(state.semesterList));
        }
    }

    function buildActorPayload(actor) {
        const session = getSession() || {};
        const source = actor && typeof actor === 'object' ? actor : {};
        return {
            userId: source.userId || source.actorUserId || session.userId || '',
            email: source.email || source.actorEmail || session.email || '',
            username: source.username || source.actorUsername || session.username || '',
            employeeId: source.employeeId || source.actorEmployeeId || session.employeeId || '',
            role: source.role || source.actorRole || session.role || '',
            fullName: source.fullName || source.actorName || session.fullName || session.username || '',
        };
    }

    function patchSessionData(partial) {
        const current = getSession();
        if (!current || typeof current !== 'object') return null;
        const next = Object.assign({}, current, partial || {});
        setJSON(KEYS.USER_SESSION, sanitizeSessionForStorage(next));
        return next;
    }

    function updateCachedUserRecord(updatedUser) {
        if (!updatedUser || typeof updatedUser !== 'object') return;
        const targetId = String(updatedUser.id || '').trim();
        const targetEmail = String(updatedUser.email || '').trim().toLowerCase();
        if (!targetId && !targetEmail) return;

        const index = state.users.findIndex(function (user) {
            const userId = String(user && user.id || '').trim();
            const userEmail = String(user && user.email || '').trim().toLowerCase();
            return (targetId && userId === targetId) || (targetEmail && userEmail === targetEmail);
        });
        if (index < 0) {
            state.users.push(Object.assign({}, updatedUser));
            usersLastSyncedAt = Date.now();
            dispatchChange(KEYS.USERS, deepClone(state.users));
            return;
        }

        state.users[index] = Object.assign({}, state.users[index], updatedUser);
        usersLastSyncedAt = Date.now();
        dispatchChange(KEYS.USERS, deepClone(state.users));
    }

    function removeCachedUserRecord(userId) {
        const targetId = String(userId || '').trim();
        if (!targetId) return;
        const nextUsers = state.users.filter(function (user) {
            return String(user && user.id || '').trim() !== targetId;
        });
        if (nextUsers.length === state.users.length) return;

        state.users = nextUsers;
        usersLastSyncedAt = Date.now();
        dispatchChange(KEYS.USERS, deepClone(state.users));
    }

    function changeOwnEmail(currentEmail, newEmail, actor) {
        startBootstrap(false);
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            currentEmail: String(currentEmail || '').trim(),
            newEmail: String(newEmail || '').trim(),
        });

        const response = syncRequest('POST', 'changeOwnEmail', body);
        const updatedUser = response && response.user && typeof response.user === 'object'
            ? response.user
            : null;
        const updatedEmail = String(response && response.email || body.newEmail || '').trim();

        if (updatedUser) {
            updateCachedUserRecord(updatedUser);
        }

        if (updatedEmail) {
            const currentSession = getSession() || {};
            const sessionUserId = String(currentSession.userId || '').trim();
            const updatedUserId = String(updatedUser && updatedUser.id || '').trim();
            const shouldPatchSession =
                (sessionUserId && updatedUserId && sessionUserId === updatedUserId) ||
                (sessionUserId === '' && sessionUserId === updatedUserId);
            if (shouldPatchSession || !updatedUserId) {
                patchSessionData({ email: updatedEmail });
            }
        }

        return {
            success: !!(response && response.success !== false),
            email: updatedEmail,
            user: updatedUser,
        };
    }

    function changeOwnEmailAsync(currentEmail, newEmail, actor) {
        startBootstrap(false);
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            currentEmail: String(currentEmail || '').trim(),
            newEmail: String(newEmail || '').trim(),
        });

        return requestJson('POST', 'changeOwnEmail', body).then(function (response) {
            const updatedUser = response && response.user && typeof response.user === 'object'
                ? response.user
                : null;
            const updatedEmail = String(response && response.email || body.newEmail || '').trim();

            if (updatedUser) {
                updateCachedUserRecord(updatedUser);
            }

            if (updatedEmail) {
                const currentSession = getSession() || {};
                const sessionUserId = String(currentSession.userId || '').trim();
                const updatedUserId = String(updatedUser && updatedUser.id || '').trim();
                const shouldPatchSession =
                    (sessionUserId && updatedUserId && sessionUserId === updatedUserId) ||
                    (sessionUserId === '' && sessionUserId === updatedUserId);
                if (shouldPatchSession || !updatedUserId) {
                    patchSessionData({ email: updatedEmail });
                }
            }

            return {
                success: !!(response && response.success !== false),
                email: updatedEmail,
                user: updatedUser,
            };
        });
    }

    function changeOwnPassword(currentPassword, newPassword, actor) {
        startBootstrap(false);
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            currentPassword: String(currentPassword || ''),
            newPassword: String(newPassword || ''),
        });
        const response = syncRequest('POST', 'changeOwnPassword', body);
        return {
            success: !!(response && response.success !== false),
            updated: !!(response && response.updated),
        };
    }

    function changeOwnPasswordAsync(currentPassword, newPassword, actor) {
        startBootstrap(false);
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            currentPassword: String(currentPassword || ''),
            newPassword: String(newPassword || ''),
        });
        return requestJson('POST', 'changeOwnPassword', body).then(function (response) {
            return {
                success: !!(response && response.success !== false),
                updated: !!(response && response.updated),
            };
        });
    }

    function generateDeanProgramPeerAssignments(payload) {
        startBootstrap(false);
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'generateDeanProgramPeerAssignments', body);
    }

    function generateCoordinatorProgramPeerAssignments(payload) {
        startBootstrap(false);
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'generateCoordinatorProgramPeerAssignments', body);
    }

    function listDeanProgramPeerAssignmentsCurrent(actor) {
        startBootstrap(false);
        return syncRequest('POST', 'listDeanProgramPeerAssignmentsCurrent', buildActorPayload(actor || {}));
    }

    function listCoordinatorProgramPeerAssignmentsCurrent(actor) {
        startBootstrap(false);
        return syncRequest('POST', 'listCoordinatorProgramPeerAssignmentsCurrent', buildActorPayload(actor || {}));
    }

    function listDeanProgramPeerAssignmentDetailsCurrent(payload) {
        startBootstrap(false);
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'listDeanProgramPeerAssignmentDetailsCurrent', body);
    }

    function listCoordinatorProgramPeerAssignmentDetailsCurrent(payload) {
        startBootstrap(false);
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'listCoordinatorProgramPeerAssignmentDetailsCurrent', body);
    }

    function autoGeneratePeerRoom(payload) {
        startBootstrap(false);
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'autoGeneratePeerRoom', body);
    }

    function listDeanPeerRoomsCurrent(actor) {
        startBootstrap(false);
        return syncRequest('POST', 'listDeanPeerRoomsCurrent', buildActorPayload(actor || {}));
    }

    function listProfessorPeerAssignmentsCurrent(actor) {
        startBootstrap(false);
        return syncRequest('POST', 'listProfessorPeerAssignmentsCurrent', buildActorPayload(actor || {}));
    }

    function fetchVpaaPeerAssignmentCounts(filters) {
        startBootstrap(false);
        return requestJson('POST', 'getVpaaPeerAssignmentCounts', Object.assign({}, filters || {}), { background: true });
    }

    function listDeanPeerRoomMembersCurrent(actor, roomId) {
        startBootstrap(false);
        const body = Object.assign({ roomId: roomId }, buildActorPayload(actor || {}));
        return syncRequest('POST', 'listDeanPeerRoomMembersCurrent', body);
    }

    function listDeanPeerRoomEligibleProfessorsCurrent(actor, roomId) {
        startBootstrap(false);
        const body = Object.assign({ roomId: roomId }, buildActorPayload(actor || {}));
        return syncRequest('POST', 'listDeanPeerRoomEligibleProfessorsCurrent', body);
    }

    function addDeanPeerRoomMembers(payload) {
        startBootstrap(false);
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'addDeanPeerRoomMembers', body);
    }

    function removeDeanPeerRoomMember(payload) {
        startBootstrap(false);
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'removeDeanPeerRoomMember', body);
    }

    function dismantleDeanPeerRoom(payload) {
        startBootstrap(false);
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'dismantleDeanPeerRoom', body);
    }

    function normalizeFacultyReportAccess(value) {
        const source = value && typeof value === 'object' ? value : {};
        return {
            enabled: source.enabled !== false,
            evaluationPeriodsComplete: source.evaluationPeriodsComplete !== false,
            departmentCode: String(source.departmentCode || '').trim(),
            updatedAt: String(source.updatedAt || '').trim(),
        };
    }

    function normalizeProfessorEvaluationCounts(value) {
        const source = value && typeof value === 'object' ? value : {};
        return {
            semesterId: String(source.semesterId || '').trim(),
            received: Math.max(0, Number(source.received) || 0),
            required: Math.max(0, Number(source.required) || 0),
            responseRate: Math.max(0, Math.min(100, Number(source.responseRate) || 0)),
        };
    }

    function applyFacultyReportAccessResponse(response) {
        const payload = response && typeof response === 'object' ? response : {};
        state.facultyReportAccess = normalizeFacultyReportAccess(payload.facultyReportAccess);
        if (payload.professorEvaluationCounts && typeof payload.professorEvaluationCounts === 'object') {
            state.professorEvaluationCounts = normalizeProfessorEvaluationCounts(payload.professorEvaluationCounts);
            dispatchChange(KEYS.PROFESSOR_EVALUATION_COUNTS, deepClone(state.professorEvaluationCounts));
        }
        dispatchChange(KEYS.FACULTY_REPORT_ACCESS, deepClone(state.facultyReportAccess));
        return deepClone(state.facultyReportAccess);
    }

    function getFacultyReportAccess() {
        startBootstrap(false);
        return deepClone(state.facultyReportAccess);
    }

    function getProfessorEvaluationCounts() {
        startBootstrap(false);
        return deepClone(state.professorEvaluationCounts);
    }

    function refreshFacultyReportAccess() {
        return asyncRequest('POST', 'getFacultyReportAccess', {}, { background: true })
            .then(applyFacultyReportAccessResponse);
    }

    function setDepartmentFacultyReportAccess(enabled) {
        return asyncRequest('POST', 'setDepartmentFacultyReportAccess', { enabled: enabled === true })
            .then(applyFacultyReportAccessResponse);
    }

    function applyFacultyPapersResponse(response) {
        const payload = response && typeof response === 'object' ? response : {};
        state.facultyAcknowledgementPapers = Array.isArray(payload.papers) ? payload.papers : [];
        const responseLimit = Number(payload.limit) || 0;
        const responseOffset = Number(payload.offset) || 0;
        const responsePage = Number(payload.page) || (responseLimit > 0 ? Math.floor(responseOffset / responseLimit) + 1 : 1);
        const responseTotal = Number(payload.total);
        state.facultyPaperListMeta = {
            total: Number.isFinite(responseTotal) && responseTotal >= 0 ? responseTotal : state.facultyAcknowledgementPapers.length,
            limit: responseLimit,
            offset: responseOffset,
            page: responsePage,
            hasMore: payload.hasMore === true,
        };
        if (responseLimit > 0 && (state.facultyPaperListMeta.hasMore || responseOffset > 0 || state.facultyPaperListMeta.total > state.facultyAcknowledgementPapers.length)) {
            markBootstrapDatasetPartial('facultyAcknowledgementPapers');
        } else {
            markBootstrapDatasetComplete('facultyAcknowledgementPapers');
        }
        dispatchChange(KEYS.FACULTY_PAPERS, deepClone(state.facultyAcknowledgementPapers));
        return deepClone(state.facultyAcknowledgementPapers);
    }

    function buildFacultyPapersPageResult(response) {
        return {
            papers: response && Array.isArray(response.papers)
                ? response.papers
                : deepClone(state.facultyAcknowledgementPapers),
            total: Number(state.facultyPaperListMeta.total) || 0,
            limit: Number(state.facultyPaperListMeta.limit) || 0,
            offset: Number(state.facultyPaperListMeta.offset) || 0,
            page: Number(state.facultyPaperListMeta.page) || 1,
            hasMore: state.facultyPaperListMeta.hasMore === true,
        };
    }

    function refreshFacultyPapers(filters) {
        const normalizedFilters = filters && typeof filters === 'object' ? filters : {};
        const refreshKey = buildRefreshKey(normalizedFilters);
        if (refreshPromises.facultyPapers && refreshPromiseKeys.facultyPapers === refreshKey) {
            return refreshPromises.facultyPapers;
        }

        startBootstrap(false);
        refreshPromiseKeys.facultyPapers = refreshKey;
        refreshPromises.facultyPapers = requestJson('POST', 'listFacultyPapers', { filters: normalizedFilters }, { background: true })
            .then(function (response) {
                applyFacultyPapersResponse(response || {});
                return buildFacultyPapersPageResult(response || {});
            })
            .finally(function () {
                refreshPromises.facultyPapers = null;
                refreshPromiseKeys.facultyPapers = '';
            });
        return refreshPromises.facultyPapers;
    }

    function listFacultyPapers(actorRole, actorUserId) {
        startBootstrap(false);
        const filters = actorRole && typeof actorRole === 'object'
            ? actorRole
            : (arguments.length >= 3 && arguments[2] && typeof arguments[2] === 'object' ? arguments[2] : {});
        const response = syncRequest('POST', 'listFacultyPapers', { filters });
        applyFacultyPapersResponse(response || {});
        return deepClone(state.facultyAcknowledgementPapers);
    }

    function getFacultyPaperListMeta() {
        return state.facultyPaperListMeta && typeof state.facultyPaperListMeta === 'object'
            ? Object.assign({}, state.facultyPaperListMeta)
            : { total: Array.isArray(state.facultyAcknowledgementPapers) ? state.facultyAcknowledgementPapers.length : 0, limit: 0, offset: 0, page: 1, hasMore: false };
    }

    function getFacultyPapers() {
        startBootstrap(false);
        return deepClone(state.facultyAcknowledgementPapers);
    }

    function upsertFacultyPaperDraft(payload) {
        startBootstrap(false);
        const response = syncRequest('POST', 'upsertFacultyPaperDraft', payload || {});
        if (response && response.paper) {
            dispatchChange(KEYS.FACULTY_PAPERS, response.paper);
        }
        return response || {};
    }

    function archiveFacultyPaper(payload) {
        startBootstrap(false);
        const response = syncRequest('POST', 'archiveFacultyPaper', payload || {});
        if (Array.isArray(response && response.papers)) {
            state.facultyAcknowledgementPapers = response.papers;
            dispatchChange(KEYS.FACULTY_PAPERS, deepClone(state.facultyAcknowledgementPapers));
        }
        return response || {};
    }

    function sendFacultyPaper(payload) {
        startBootstrap(false);
        const response = syncRequest('POST', 'sendFacultyPaper', payload || {});
        if (Array.isArray(response && response.papers)) {
            state.facultyAcknowledgementPapers = response.papers;
            dispatchChange(KEYS.FACULTY_PAPERS, deepClone(state.facultyAcknowledgementPapers));
        }
        return response || {};
    }

    function saveFacultyPaperSectionC(payload) {
        startBootstrap(false);
        const response = syncRequest('POST', 'saveFacultyPaperSectionC', payload || {});
        if (response && response.paper) {
            dispatchChange(KEYS.FACULTY_PAPERS, response.paper);
        }
        return response || {};
    }

    function onDataChange(callback) {
        window.addEventListener('shareddata:change', function (event) {
            callback(event.detail.key, event.detail.value);
        });
        window.addEventListener('storage', function (event) {
            if (event.key && event.newValue !== null) {
                try {
                    callback(event.key, JSON.parse(event.newValue));
                } catch (_error) {
                    callback(event.key, event.newValue);
                }
            }
        });
    }

    hydrateLocalFallbackState();
    startBootstrap(false);

    return {
        KEYS,
        getJSON,
        setJSON,
        remove,
        getSession,
        getNowDate,
        getNowIsoString,
        getCurrentPhilippineDateYmd,
        getCurrentPhilippineYear,
        parsePhilippineDateBoundary,
        formatDateTimeInPhilippines,
        formatDateInPhilippines,
        refreshBootstrap,
        refreshSession,
        requireSession,
        setSession,
        clearSession,
        consumeLogoutPendingMarker,
        isAuthenticated,
        getRole,
        getUsername,
        getProfilePhoto,
        setProfilePhoto,
        uploadProfilePhoto,
        uploadProfilePhotoAsync,
        getProfileData,
        setProfileData,
        getUsers,
        getCachedUsers,
        getUserListMeta,
        getLastUsersPageMeta,
        listUsers,
        refreshUsers,
        refreshUserCount,
        fetchUsersPage,
        getPrograms,
        bulkUpsertUsers,
        setUsers,
        setUsersStrict,
        addUser,
        updateUser,
        deleteUser,
        getCampuses,
        setCampuses,
        upsertProgram,
        deleteProgram,
        getAllDepartments,
        getProfessors,
        setProfessors,
        getCurrentSemester,
        setCurrentSemester,
        getQuestionnaires,
        setQuestionnaires,
        getEvaluations,
        getCachedEvaluations,
        listEvaluations,
        refreshEvaluations,
        fetchEvaluationsSnapshot,
        fetchHrBehaviorAnalysis,
        addEvaluation,
        addEvaluationAsync,
        getStudentEvaluationDrafts,
        upsertStudentEvaluationDraft,
        removeStudentEvaluationDraft,
        getDataPrivacyConsentNotice,
        getStudentDataPrivacyConsents,
        hasStudentDataPrivacyConsent,
        recordStudentDataPrivacyConsent,
        getOsaStudentClearances,
        refreshOsaStudentClearances,
        upsertOsaStudentClearance,
        verifyOsaStudentClearance,
        getStudentEvaluationProofRequests,
        isStudentEvaluationProofRequestsReady,
        refreshStudentEvaluationProofRequests,
        submitStudentEvaluationProof,
        reviewStudentEvaluationProof,
        getAdminDashboardSummary,
        refreshAdminDashboardSummary,
        getSubjectManagement,
        getCachedSubjectManagement,
        refreshSubjectManagement,
        fetchSubjectManagementSnapshot,
        upsertSubject,
        importSubjects,
        upsertCourseOffering,
        importCourseOfferings,
        markExcessCourseOfferings,
        setCourseOfferingStudents,
        deactivateCourseOffering,
        getActivityLog,
        searchActivityLog,
        getCredentialDistributorConfig,
        saveCredentialDistributorConfig,
        getOpenAiConfig,
        saveOpenAiConfig,
        getGeminiConfig,
        saveGeminiConfig,
        getOpenAiPanelAccess,
        summarizeFeedbackComments,
        bulkDistributeCredentials,
        sendBulkTestGmail,
        sendTestSmtpEmail,
        submitSystemReport,
        listSystemHealthChecks,
        runSystemHealthCheck,
        analyzeBiasComments,
        listCredibilityReviews, getCredibilityReview, reviewCredibilityEvaluations, getProfessorAnalyticsPayload,
        startEvaluationTiming, buildEvaluationTiming, clearEvaluationTiming,
        analyzeEvaluationExplainability,
        generateFacultyPaperSectionCRecommendations,
        getAnnouncements,
        getAnnouncementsForCurrentUser,
        getUnreadAnnouncementsForCurrentUser,
        addAnnouncement,
        markAnnouncementRead,
        markAnnouncementsRead,
        getUnreadAnnouncementCount,
        showUnreadAnnouncementLoginPopup,
        getSettings,
        updateSettings,
        updateSettingsAsync,
        getStudentEvaluationReminderConfig,
        updateStudentEvaluationReminderConfigAsync,
        getEvalPeriods,
        setEvalPeriods,
        isEvalPeriodOpen,
        getEvalPeriodDates,
        getSemesterList,
        setSemesterList,
        addSemester,
        changeOwnEmail,
        changeOwnEmailAsync,
        changeOwnPassword,
        changeOwnPasswordAsync,
        generateDeanProgramPeerAssignments,
        generateCoordinatorProgramPeerAssignments,
        listDeanProgramPeerAssignmentsCurrent,
        listCoordinatorProgramPeerAssignmentsCurrent,
        listDeanProgramPeerAssignmentDetailsCurrent,
        listCoordinatorProgramPeerAssignmentDetailsCurrent,
        autoGeneratePeerRoom,
        listDeanPeerRoomsCurrent,
        listProfessorPeerAssignmentsCurrent,
        fetchVpaaPeerAssignmentCounts,
        listDeanPeerRoomMembersCurrent,
        listDeanPeerRoomEligibleProfessorsCurrent,
        addDeanPeerRoomMembers,
        removeDeanPeerRoomMember,
        dismantleDeanPeerRoom,
        getFacultyPapers,
        getFacultyPaperListMeta,
        refreshFacultyPapers,
        listFacultyPapers,
        getFacultyReportAccess,
        getProfessorEvaluationCounts,
        refreshFacultyReportAccess,
        setDepartmentFacultyReportAccess,
        upsertFacultyPaperDraft,
        archiveFacultyPaper,
        sendFacultyPaper,
        saveFacultyPaperSectionC,
        onDataChange,
        bootstrap,
    };
})();
