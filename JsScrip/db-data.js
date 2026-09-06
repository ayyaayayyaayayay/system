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
        manualMessage: '',
        networkMessage: NETWORK_MESSAGE,
    };

    let overlayEl = null;
    let textEl = null;
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

        const hasActivity = state.manualInFlight > 0 || state.networkInFlight > 0;
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
        renderOverlay();
        return true;
    }

    function endNetworkRequest() {
        state.networkInFlight = Math.max(0, state.networkInFlight - 1);
        if (state.networkInFlight === 0) {
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
            return /\/api\//i.test(parsed.pathname);
        } catch (_error) {
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

            ctx.save();
            ctx.textAlign = 'center';
            ctx.textBaseline = 'middle';
            ctx.fillStyle = muted ? '#94a3b8' : '#111827';
            ctx.font = '700 24px Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(title, centerX, centerY - 8);
            ctx.fillStyle = muted ? '#cbd5e1' : '#64748b';
            ctx.font = '600 11px Inter, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif';
            ctx.fillText(subtitle, centerX, centerY + 14);
            ctx.restore();
        }
    };

    function renderRatingDistributionChart(canvas, options) {
        if (!canvas || typeof Chart === 'undefined') return null;

        const config = options || {};
        const existingChart = Chart.getChart(canvas);
        if (existingChart) existingChart.destroy();

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

        return new Chart(canvas, {
            type: 'doughnut',
            data: {
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
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                cutout: '64%',
                radius: '86%',
                rotation: -90,
                layout: {
                    padding: { top: 8, right: 14, bottom: 4, left: 14 }
                },
                plugins: {
                    appRatingDistributionCenterText: {
                        title: hasData && averageRating > 0 ? averageRating.toFixed(2) : 'No data',
                        subtitle: `${total} ${total === 1 ? totalLabel : totalLabel + 's'}`,
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
            },
            plugins: [centerTextPlugin]
        });
    }

    function renderDoughnutMetricChart(canvas, options) {
        if (!canvas || typeof Chart === 'undefined') return null;

        const config = options || {};
        const existingChart = Chart.getChart(canvas);
        if (existingChart) existingChart.destroy();

        const values = Array.isArray(config.values) ? config.values.map(value => Number(value) || 0) : [];
        const total = values.reduce((sum, value) => sum + value, 0);
        const hasData = total > 0;
        const labels = Array.isArray(config.labels) && config.labels.length ? config.labels : ['No data'];
        const colors = Array.isArray(config.colors) && config.colors.length
            ? config.colors
            : ['#059669', '#f59e0b', '#ef4444', '#3b82f6', '#8b5cf6'];
        const title = config.centerTitle || String(total || 0);
        const subtitle = config.centerSubtitle || 'Total';

        return new Chart(canvas, {
            type: 'doughnut',
            data: {
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
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
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
            },
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
        PROFESSORS: 'professorsData',
        USERS: 'sharedUsersData',
        CAMPUSES: 'sharedCampusData',
        CURRENT_SEMESTER: 'currentSemester',
        QUESTIONNAIRES: 'questionnairesBySemester',
        ACTIVITY_LOG: 'sharedActivityLog',
        ANNOUNCEMENTS: 'sharedAnnouncements',
        SETTINGS: 'sharedSettings',
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
    const SESSION_IDLE_WINDOW_MS = 5 * 60 * 1000;
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
        facultyAcknowledgementPapers: [],
        profileData: null,
        profilePhotos: null,
    };

    let initialized = false;
    let usersLastSyncedAt = 0;
    let lastUserActivityAt = Date.now();
    let lastHeartbeatSentAt = 0;
    let heartbeatTimerId = null;
    let heartbeatInFlight = false;
    let heartbeatListenersAttached = false;
    let sessionCsrfToken = '';
    const clockState = {
        baseUnixMs: null,
        capturedAtMs: 0,
        source: 'browser',
        timezone: PHILIPPINE_TIMEZONE,
    };

    function deepClone(value) {
        return value == null ? value : JSON.parse(JSON.stringify(value));
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

    function storeSessionPayload(payload) {
        setClockReference(payload);
        const session = normalizeSessionPayload(payload);
        if (!session) {
            return null;
        }
        setJSON(KEYS.USER_SESSION, sanitizeSessionForStorage(session));
        startSessionHeartbeat();
        return session;
    }

    function clearSessionCache() {
        sessionCsrfToken = '';
        remove(KEYS.USER_SESSION);
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
        heartbeatInFlight = false;
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
                recordUserActivity();
            }
        });

        heartbeatListenersAttached = true;
    }

    function recordUserActivity() {
        lastUserActivityAt = Date.now();
        if (isAuthenticated()) {
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
        if (heartbeatTimerId !== null) {
            return;
        }

        heartbeatTimerId = window.setInterval(function () {
            sendSessionHeartbeat(false);
        }, SESSION_HEARTBEAT_CHECK_MS);
    }

    function sendSessionHeartbeat(force) {
        const session = getSession();
        if (!session || session.isAuthenticated !== true || !session.csrfToken) {
            stopSessionHeartbeat();
            return;
        }

        const now = Date.now();
        if (!force) {
            if ((now - lastHeartbeatSentAt) < SESSION_HEARTBEAT_INTERVAL_MS) {
                return;
            }
            if (lastHeartbeatSentAt > 0 && lastUserActivityAt <= lastHeartbeatSentAt) {
                return;
            }
            if ((now - lastUserActivityAt) > SESSION_IDLE_WINDOW_MS) {
                return;
            }
        }
        if (heartbeatInFlight) {
            return;
        }

        heartbeatInFlight = true;
        lastHeartbeatSentAt = now;

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
                    if (result.ok && result.data && result.data.session) {
                        storeSessionPayload(result.data.session);
                        return;
                    }
                    if (result.status === 401 || result.status === 403) {
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
            if (xhr.status >= 200 && xhr.status < 300) {
                try {
                    const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
                    if (response && response.session) {
                        storeSessionPayload(response.session);
                    }
                } catch (_error) {
                    // Ignore malformed heartbeat responses.
                }
                return;
            }
            if (xhr.status === 401 || xhr.status === 403) {
                handleServerEndedSession();
            }
        };
        xhr.onerror = function () {
            heartbeatInFlight = false;
        };
        xhr.send(payload);
    }

    function syncRequest(method, action, payload) {
        const xhr = new XMLHttpRequest();
        let url = API_URL + '?action=' + encodeURIComponent(action);
        if (method === 'GET') {
            url += '&_ts=' + Date.now();
        }
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
            if (xhr.responseText) {
                try {
                    const parsed = JSON.parse(xhr.responseText);
                    message = parsed && parsed.error ? String(parsed.error) : xhr.responseText;
                } catch (_error) {
                    message = xhr.responseText;
                }
            }
            if (xhr.status === 401 || xhr.status === 403) {
                initialized = false;
                clearSessionCache();
                clearProfilePhotoState();
            }
            const error = new Error(message);
            error.status = xhr.status;
            throw error;
        }

        const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
        setClockReference(response);
        if (response && response.session) {
            storeSessionPayload(response.session);
        }
        return response;
    }

    function asyncRequest(method, action, payload) {
        bootstrap();
        let url = API_URL + '?action=' + encodeURIComponent(action);
        if (method === 'GET') {
            url += '&_ts=' + Date.now();
        }

        if (typeof fetch !== 'function') {
            return Promise.resolve(syncRequest(method, action, payload));
        }

        const headers = {
            'Content-Type': 'application/json',
        };
        if (method !== 'GET') {
            const session = getSession();
            const csrfToken = String(session && session.csrfToken || '').trim();
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
                    if (response.status === 401 || response.status === 403) {
                        initialized = false;
                        clearSessionCache();
                        clearProfilePhotoState();
                    }
                    const error = new Error(message);
                    error.status = response.status;
                    throw error;
                }

                setClockReference(parsed);
                if (parsed && parsed.session) {
                    storeSessionPayload(parsed.session);
                }
                return parsed;
            });
        });
    }

    function applyBootstrap(snapshot) {
        setClockReference(snapshot);
        state.users = Array.isArray(snapshot.users) ? snapshot.users : [];
        usersLastSyncedAt = state.users.length ? Date.now() : 0;
        state.programs = Array.isArray(snapshot.programs) ? snapshot.programs : [];
        state.campuses = Array.isArray(snapshot.campuses) && snapshot.campuses.length
            ? snapshot.campuses
            : state.campuses;
        state.currentSemester = snapshot.currentSemester || '';
        state.questionnaires = snapshot.questionnaires || {};
        state.activityLog = Array.isArray(snapshot.activityLog) ? snapshot.activityLog : [];
        state.announcements = normalizeAnnouncementList(snapshot.announcements);
        state.settings = Object.assign({}, state.settings, snapshot.settings || {});
        state.evalPeriods = Object.assign({}, state.evalPeriods, snapshot.evalPeriods || {});
        state.semesterList = Array.isArray(snapshot.semesterList) ? snapshot.semesterList : [];
        state.evaluations = Array.isArray(snapshot.evaluations) ? snapshot.evaluations : [];
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
        state.facultyAcknowledgementPapers = Array.isArray(snapshot.facultyAcknowledgementPapers)
            ? snapshot.facultyAcknowledgementPapers
            : [];
        state.profileData = snapshot.currentUserProfileData && typeof snapshot.currentUserProfileData === 'object'
            ? snapshot.currentUserProfileData
            : null;
        state.profilePhotos = typeof snapshot.currentUserProfileImageUrl === 'string' && snapshot.currentUserProfileImageUrl
            ? snapshot.currentUserProfileImageUrl
            : (typeof snapshot.currentUserProfilePhoto === 'string'
                ? snapshot.currentUserProfilePhoto
                : null);
    }

    function uploadProfilePhoto(file) {
        bootstrap();

        if (!file) {
            throw new Error('Please choose an image file to upload.');
        }

        const xhr = new XMLHttpRequest();
        xhr.open('POST', PROFILE_IMAGE_UPLOAD_URL, false);

        const session = getSession();
        const csrfToken = String(session && session.csrfToken || '').trim();
        if (csrfToken) {
            xhr.setRequestHeader('X-CSRF-Token', csrfToken);
        }

        const formData = new FormData();
        formData.append('profile_image', file);
        xhr.send(formData);

        if (xhr.status < 200 || xhr.status >= 300) {
            let message = 'Upload failed with status ' + xhr.status;
            if (xhr.responseText) {
                try {
                    const parsed = JSON.parse(xhr.responseText);
                    message = parsed && parsed.error ? String(parsed.error) : xhr.responseText;
                } catch (_error) {
                    message = xhr.responseText;
                }
            }
            if (xhr.status === 401 || xhr.status === 403) {
                initialized = false;
                clearSessionCache();
                clearProfilePhotoState();
            }
            const error = new Error(message);
            error.status = xhr.status;
            throw error;
        }

        const response = xhr.responseText ? JSON.parse(xhr.responseText) : {};
        if (response && response.session) {
            storeSessionPayload(response.session);
        }

        state.profilePhotos = typeof response.profileImageUrl === 'string'
            ? response.profileImageUrl
            : (typeof response.profilePhoto === 'string' ? response.profilePhoto : '');
        dispatchChange('profilePhoto', state.profilePhotos);

        return state.profilePhotos || null;
    }

    function clearProfilePhotoState() {
        state.profilePhotos = null;
        dispatchChange('profilePhoto', state.profilePhotos);
    }

    function setProfilePhoto(role, dataUrl) {
        if (typeof File !== 'undefined' && dataUrl instanceof File) {
            return uploadProfilePhoto(dataUrl);
        }

        if (dataUrl && typeof dataUrl === 'object' && typeof dataUrl.name === 'string') {
            return uploadProfilePhoto(dataUrl);
        }

        bootstrap();
        state.profilePhotos = dataUrl || '';
        dispatchChange('profilePhoto', state.profilePhotos);
        try {
            const response = syncRequest('POST', 'setProfilePhoto', { dataUrl: dataUrl || '' });
            if (response && Object.prototype.hasOwnProperty.call(response, 'profilePhoto')) {
                state.profilePhotos = response.profilePhoto || '';
                dispatchChange('profilePhoto', state.profilePhotos);
            }
        } catch (error) {
            console.error('[DBData] Failed to persist profile photo.', error);
        }

        return state.profilePhotos || null;
    }

    function getProfilePhoto() {
        bootstrap();
        return state.profilePhotos || null;
    }

    function setProfilePhotoFromResponse(urlValue) {
        state.profilePhotos = typeof urlValue === 'string' && urlValue.trim() ? urlValue.trim() : null;
        dispatchChange('profilePhoto', state.profilePhotos);
        return state.profilePhotos;
    }

    function clearSession(options) {
        const session = getSession();
        const config = options && typeof options === 'object' ? options : {};
        if (!config.localOnly && session && session.isAuthenticated === true) {
            try {
                const xhr = new XMLHttpRequest();
                xhr.open('POST', LOGIN_API_URL, false);
                xhr.setRequestHeader('Content-Type', 'application/json');
                if (session.csrfToken) {
                    xhr.setRequestHeader('X-CSRF-Token', session.csrfToken);
                }
                xhr.send(JSON.stringify({ action: 'logout' }));
            } catch (error) {
                console.warn('[DBData] Logout request failed.', error);
            }
        }
        initialized = false;
        usersLastSyncedAt = 0;
        clearSessionCache();
        clearProfilePhotoState();
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
            return storeSessionPayload(payload);
        }

        if (xhr.status === 401 || xhr.status === 403) {
            initialized = false;
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

    function bootstrap(forceRefresh) {
        if (initialized && !forceRefresh) {
            return true;
        }

        try {
            const response = syncRequest('GET', 'bootstrap');
            if (response && response.success && response.state) {
                applyBootstrap(response.state);
                if (response.session) {
                    storeSessionPayload(response.session);
                }
                initialized = true;
                return true;
            }
        } catch (error) {
            if (!error || (error.status !== 401 && error.status !== 403)) {
                console.warn(
                    '[DBData] Bootstrap failed. Open the site through Apache/XAMPP over http://localhost so ../api/app_state.php can run.',
                    error
                );
            }
        }

        initialized = true;
        return false;
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
        bootstrap();
        return state.profileData || null;
    }

    function setProfileData(role, data) {
        bootstrap();
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
        bootstrap();
        return state.users;
    }

    function getPrograms() {
        bootstrap();
        return state.programs || [];
    }

    function filterCachedUsers(filters) {
        const cfg = filters && typeof filters === 'object' ? filters : {};
        const campus = String(cfg.campus || '').trim().toLowerCase();
        const search = String(cfg.search || '').trim().toLowerCase();
        const users = Array.isArray(state.users) ? state.users : [];

        return users.filter(function (user) {
            if (campus && campus !== 'all' && String(user && user.campus || '').trim().toLowerCase() !== campus) {
                return false;
            }

            if (!search) {
                return true;
            }

            const haystacks = [
                String(user && user.name || '').trim().toLowerCase(),
                String(user && user.email || '').trim().toLowerCase(),
                String(user && user.role || '').trim().toLowerCase(),
                String(user && user.department || '').trim().toLowerCase(),
                String(user && user.employeeId || '').trim().toLowerCase(),
                String(user && user.studentNumber || '').trim().toLowerCase(),
            ];

            return haystacks.some(function (value) {
                return value && value.indexOf(search) !== -1;
            });
        });
    }

    function applyUsersResponse(response) {
        if (response && Array.isArray(response.users)) {
            state.users = response.users;
            usersLastSyncedAt = Date.now();
            dispatchChange(KEYS.USERS, deepClone(state.users));
        }
        return state.users;
    }

    function listUsers(filters) {
        bootstrap();
        const normalizedFilters = filters && typeof filters === 'object' ? filters : {};
        const shouldUseCache = Array.isArray(state.users)
            && state.users.length > 0
            && (Date.now() - usersLastSyncedAt) < USERS_CACHE_TTL_MS
            && normalizedFilters.forceRefresh !== true;

        if (shouldUseCache) {
            return filterCachedUsers(normalizedFilters);
        }

        const response = syncRequest('POST', 'listUsers', { filters: normalizedFilters });
        applyUsersResponse(response);
        return filterCachedUsers(normalizedFilters);
    }

    function bulkUpsertUsers(users) {
        bootstrap();
        const response = syncRequest('POST', 'bulkUpsertUsers', {
            users: Array.isArray(users) ? users : [],
        });
        return applyUsersResponse(response);
    }

    function setUsers(users) {
        return bulkUpsertUsers(users);
    }

    function setUsersStrict(users) {
        return bulkUpsertUsers(users);
    }

    function addUser(user) {
        bootstrap();
        const response = syncRequest('POST', 'createUser', { user: user || {} });
        if (response && response.user) {
            updateCachedUserRecord(response.user);
        }
        return applyUsersResponse(response);
    }

    function updateUser(idOrUser, updatedData) {
        bootstrap();

        let id = idOrUser;
        let patch = updatedData;
        if (typeof idOrUser === 'object' && idOrUser !== null) {
            id = idOrUser.id;
            patch = idOrUser;
        }

        const response = syncRequest('POST', 'updateUser', {
            userId: id,
            user: patch || {},
        });
        if (response && response.user) {
            updateCachedUserRecord(response.user);
        }
        return applyUsersResponse(response);
    }

    function deleteUser(id) {
        bootstrap();
        const response = syncRequest('POST', 'deleteUser', { userId: id });
        return applyUsersResponse(response);
    }

    function getCampuses() {
        bootstrap();
        return state.campuses;
    }

    function setCampuses(campuses) {
        bootstrap();
        state.campuses = Array.isArray(campuses) ? campuses : state.campuses;
        dispatchChange(KEYS.CAMPUSES, deepClone(state.campuses));
        try {
            syncRequest('POST', 'setCampuses', { campuses: state.campuses });
        } catch (error) {
            console.error('[DBData] Failed to persist campuses.', error);
        }
    }

    function upsertProgram(program) {
        bootstrap();
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
        bootstrap();
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
        bootstrap();
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
        bootstrap();
        return state.users.filter(function (user) {
            return user.role === 'professor';
        });
    }

    function setProfessors(professors) {
        bootstrap();
        const nonProfessors = state.users.filter(function (user) {
            return user.role !== 'professor';
        });
        const professorUsers = Array.isArray(professors) ? professors.map(function (professor) {
            return Object.assign({}, professor, { role: 'professor' });
        }) : [];
        state.users = nonProfessors.concat(professorUsers);
        return persistUsers();
    }

    function getCurrentSemester() {
        bootstrap();
        return state.currentSemester || '';
    }

    function setCurrentSemester(value) {
        bootstrap();
        state.currentSemester = value || '';
        dispatchChange(KEYS.CURRENT_SEMESTER, state.currentSemester);
        try {
            syncRequest('POST', 'setCurrentSemester', { value: state.currentSemester });
        } catch (error) {
            console.error('[DBData] Failed to persist current semester.', error);
        }
    }

    function getQuestionnaires() {
        bootstrap();
        return state.questionnaires || {};
    }

    function setQuestionnaires(data) {
        bootstrap();
        state.questionnaires = data || {};
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
                dispatchChange(KEYS.QUESTIONNAIRES, deepClone(state.questionnaires));
            }
            return deepClone(state.questionnaires);
        } catch (error) {
            console.error('[DBData] Failed to persist questionnaires.', error);
            return false;
        }
    }

    function getEvaluations() {
        bootstrap();
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

    function addEvaluation(evalData) {
        bootstrap();
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

        const response = syncRequest('POST', 'addEvaluation', { evaluation: payload });
        if (!response || response.success !== true || !response.evaluation) {
            throw new Error(response && response.error ? response.error : 'Failed to save evaluation.');
        }

        state.evaluations.push(response.evaluation);
        dispatchChange(KEYS.EVALUATIONS, deepClone(state.evaluations));
        return response.evaluation;
    }

    function getStudentEvaluationDrafts() {
        bootstrap();
        return deepClone(state.studentEvaluationDrafts || []);
    }

    function upsertStudentEvaluationDraft(draft) {
        bootstrap();
        const response = syncRequest('POST', 'upsertStudentEvaluationDraft', { draft: draft || {} });
        if (Array.isArray(response && response.studentEvaluationDrafts)) {
            state.studentEvaluationDrafts = response.studentEvaluationDrafts;
            dispatchChange(KEYS.STUDENT_EVAL_DRAFTS, deepClone(state.studentEvaluationDrafts));
        } else if (response && response.draft) {
            const next = Array.isArray(state.studentEvaluationDrafts) ? [...state.studentEvaluationDrafts] : [];
            const savedDraft = response.draft;
            const savedKey = String(savedDraft.draftKey || '').trim().toLowerCase();
            const savedStudentUserId = String(savedDraft.studentUserId || '').trim().toLowerCase();
            const savedStudentId = String(savedDraft.studentId || '').trim().toLowerCase();
            const index = next.findIndex(function (item) {
                if (!item) return false;
                const itemKey = String(item.draftKey || '').trim().toLowerCase();
                if (itemKey !== savedKey) return false;
                const itemStudentUserId = String(item.studentUserId || '').trim().toLowerCase();
                const itemStudentId = String(item.studentId || '').trim().toLowerCase();
                return (savedStudentUserId && itemStudentUserId === savedStudentUserId)
                    || (savedStudentId && itemStudentId === savedStudentId);
            });
            if (index >= 0) {
                next[index] = savedDraft;
            } else {
                next.push(savedDraft);
            }
            state.studentEvaluationDrafts = next;
            dispatchChange(KEYS.STUDENT_EVAL_DRAFTS, deepClone(state.studentEvaluationDrafts));
        }
        return response || {};
    }

    function removeStudentEvaluationDraft(draftKey, studentIdentity) {
        bootstrap();
        const payload = {
            draftKey: draftKey,
            studentUserId: studentIdentity && studentIdentity.studentUserId ? studentIdentity.studentUserId : '',
            studentId: studentIdentity && studentIdentity.studentId ? studentIdentity.studentId : '',
        };
        const response = syncRequest('POST', 'removeStudentEvaluationDraft', payload);
        if (Array.isArray(response && response.studentEvaluationDrafts)) {
            state.studentEvaluationDrafts = response.studentEvaluationDrafts;
            dispatchChange(KEYS.STUDENT_EVAL_DRAFTS, deepClone(state.studentEvaluationDrafts));
        }
        return response || {};
    }

    function getDataPrivacyConsentNotice(questionnaireType) {
        bootstrap();
        const typeToken = String(questionnaireType || '').trim();
        if (typeToken && state.dataPrivacyConsentNotices && state.dataPrivacyConsentNotices[typeToken]) {
            return deepClone(state.dataPrivacyConsentNotices[typeToken] || {});
        }
        return deepClone(state.dataPrivacyConsentNotice || {});
    }

    function getStudentDataPrivacyConsents() {
        bootstrap();
        return deepClone(state.studentDataPrivacyConsents || []);
    }

    function hasStudentDataPrivacyConsent(semesterId, consentVersion, questionnaireType) {
        bootstrap();
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
        bootstrap();
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
        bootstrap();
        return deepClone(state.osaStudentClearances || []);
    }

    function upsertOsaStudentClearance(record) {
        bootstrap();
        const body = Object.assign({ record: record || {} }, buildActorPayload(record || {}));
        const response = syncRequest('POST', 'upsertOsaStudentClearance', body);
        if (Array.isArray(response && response.osaStudentClearances)) {
            state.osaStudentClearances = response.osaStudentClearances;
            dispatchChange(KEYS.OSA_STUDENT_CLEARANCES, deepClone(state.osaStudentClearances));
        } else if (response && response.record) {
            const next = Array.isArray(state.osaStudentClearances) ? [...state.osaStudentClearances] : [];
            const recordItem = response.record;
            const recordSemester = String(recordItem.semesterId || '').trim().toLowerCase();
            const recordUser = String(recordItem.studentUserId || '').trim().toLowerCase();
            const recordNumber = String(recordItem.studentNumber || '').trim().toLowerCase();
            const idx = next.findIndex(function (item) {
                if (!item) return false;
                const sameSemester = String(item.semesterId || '').trim().toLowerCase() === recordSemester;
                if (!sameSemester) return false;
                const itemUser = String(item.studentUserId || '').trim().toLowerCase();
                const itemNumber = String(item.studentNumber || '').trim().toLowerCase();
                return (recordUser && itemUser && recordUser === itemUser)
                    || (recordNumber && itemNumber && recordNumber === itemNumber);
            });
            if (idx >= 0) {
                next[idx] = recordItem;
            } else {
                next.push(recordItem);
            }
            state.osaStudentClearances = next;
            dispatchChange(KEYS.OSA_STUDENT_CLEARANCES, deepClone(state.osaStudentClearances));
        }
        return response || {};
    }

    function getStudentEvaluationProofRequests() {
        bootstrap();
        return deepClone(state.studentEvaluationProofRequests || []);
    }

    function submitStudentEvaluationProof(record) {
        bootstrap();
        const body = Object.assign({ record: record || {} }, buildActorPayload(record || {}));
        const response = syncRequest('POST', 'submitStudentEvaluationProof', body);

        if (Array.isArray(response && response.studentEvaluationProofRequests)) {
            state.studentEvaluationProofRequests = response.studentEvaluationProofRequests;
            dispatchChange(KEYS.STUDENT_EVAL_PROOF_REQUESTS, deepClone(state.studentEvaluationProofRequests));
        }
        if (Array.isArray(response && response.osaStudentClearances)) {
            state.osaStudentClearances = response.osaStudentClearances;
            dispatchChange(KEYS.OSA_STUDENT_CLEARANCES, deepClone(state.osaStudentClearances));
        }

        return response || {};
    }

    function reviewStudentEvaluationProof(payload) {
        bootstrap();
        const body = Object.assign({ payload: payload || {} }, buildActorPayload(payload || {}));
        const response = syncRequest('POST', 'reviewStudentEvaluationProof', body);

        if (Array.isArray(response && response.studentEvaluationProofRequests)) {
            state.studentEvaluationProofRequests = response.studentEvaluationProofRequests;
            dispatchChange(KEYS.STUDENT_EVAL_PROOF_REQUESTS, deepClone(state.studentEvaluationProofRequests));
        }
        if (Array.isArray(response && response.osaStudentClearances)) {
            state.osaStudentClearances = response.osaStudentClearances;
            dispatchChange(KEYS.OSA_STUDENT_CLEARANCES, deepClone(state.osaStudentClearances));
        }

        return response || {};
    }

    function getSubjectManagement() {
        bootstrap();
        return deepClone(state.subjectManagement);
    }

    function upsertSubject(subject) {
        bootstrap();
        const response = syncRequest('POST', 'upsertSubject', { subject: subject || {} });
        applySubjectManagementSnapshot(response);
        return response;
    }

    function importSubjects(rows) {
        bootstrap();
        const response = syncRequest('POST', 'importSubjects', { rows: Array.isArray(rows) ? rows : [] });
        applySubjectManagementSnapshot(response);
        return response;
    }

    function upsertCourseOffering(offering) {
        bootstrap();
        const response = syncRequest('POST', 'upsertCourseOffering', { offering: offering || {} });
        applySubjectManagementSnapshot(response);
        return response;
    }

    function importCourseOfferings(rows, options) {
        bootstrap();
        const payload = {
            rows: Array.isArray(rows) ? rows : [],
            replaceExisting: !!(options && options.replaceExisting),
        };
        const response = syncRequest('POST', 'importCourseOfferings', payload);
        applySubjectManagementSnapshot(response);
        return response;
    }

    function markExcessCourseOfferings(rows) {
        bootstrap();
        const response = syncRequest('POST', 'markExcessCourseOfferings', {
            rows: Array.isArray(rows) ? rows : [],
        });
        applySubjectManagementSnapshot(response);
        return response;
    }

    function setCourseOfferingStudents(courseOfferingId, studentUserIds) {
        bootstrap();
        const response = syncRequest('POST', 'setCourseOfferingStudents', {
            courseOfferingId: courseOfferingId,
            studentUserIds: Array.isArray(studentUserIds) ? studentUserIds : [],
        });
        applySubjectManagementSnapshot(response);
        return response;
    }

    function deactivateCourseOffering(courseOfferingId) {
        bootstrap();
        const response = syncRequest('POST', 'deactivateCourseOffering', {
            courseOfferingId: courseOfferingId,
        });
        applySubjectManagementSnapshot(response);
        return response;
    }

    function getActivityLog() {
        bootstrap();
        return state.activityLog || [];
    }

    function searchActivityLog(filters) {
        bootstrap();
        const response = syncRequest('POST', 'searchActivityLog', {
            filters: Object.assign({}, filters || {}),
        });
        return Array.isArray(response && response.activityLog) ? response.activityLog : [];
    }

    function addActivityLogEntry(entry) {
        bootstrap();
        const payload = Object.assign({}, entry || {});

        let logEntry = null;
        try {
            const response = syncRequest('POST', 'addActivityLogEntry', { entry: payload });
            if (response && response.entry) {
                logEntry = response.entry;
            }
        } catch (error) {
            console.error('[DBData] Failed to persist activity log entry.', error);
            return null;
        }

        if (!logEntry) {
            return null;
        }

        state.activityLog.unshift(logEntry);
        if (state.activityLog.length > 200) {
            state.activityLog.length = 200;
        }
        dispatchChange(KEYS.ACTIVITY_LOG, deepClone(state.activityLog));

        return logEntry;
    }

    function getCredentialDistributorConfig(actor) {
        bootstrap();
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
        };
    }

    function saveCredentialDistributorConfig(config, actor) {
        bootstrap();
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
        bootstrap();
        const body = buildActorPayload(actor || {});
        const response = syncRequest('POST', 'getOpenAiConfig', body);
        const config = response && response.config ? response.config : {};
        return {
            model: String(config.model || 'gpt-5.6-luna'),
            timeoutMs: Number(config.timeoutMs || 30000),
            hasApiKey: !!config.hasApiKey,
            source: String(config.source || 'database'),
            panelAccess: normalizeOpenAiPanelAccessConfig(config.panelAccess),
        };
    }

    function saveOpenAiConfig(config, actor) {
        bootstrap();
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
            panelAccess: normalizeOpenAiPanelAccessConfig(savedConfig.panelAccess),
        };
    }

    const getGeminiConfig = getOpenAiConfig;
    const saveGeminiConfig = saveOpenAiConfig;

    function getOpenAiPanelAccess(actor) {
        bootstrap();
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
        bootstrap();
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            payload: payload && typeof payload === 'object' ? payload : {},
        });
        return asyncRequest('POST', 'summarizeFeedbackComments', body).then(function (response) {
            return {
                success: response && response.success === true,
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
        bootstrap();
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
        bootstrap();
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
        bootstrap();
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

    function analyzeBiasComments(filters, actor) {
        bootstrap();
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            filters: Object.assign({}, filters || {}),
        });
        const response = syncRequest('POST', 'analyzeBiasComments', body);
        return {
            success: response && response.success === true,
            summary: response && response.summary ? response.summary : { total: 0, constructive: 0, neutral: 0, biased: 0, source: 'rule' },
            items: Array.isArray(response && response.items) ? response.items : [],
        };
    }

    function analyzeEvaluationExplainability(payload, actor) {
        bootstrap();
        const body = Object.assign({}, buildActorPayload(actor || {}), {
            payload: payload && typeof payload === 'object' ? payload : {},
        });
        const response = syncRequest('POST', 'analyzeEvaluationExplainability', body);
        const fallbackInsight = {
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
            },
        };

        return {
            success: response && response.success === true,
            source: String(response && response.source || 'rule'),
            insight: response && response.insight && typeof response.insight === 'object'
                ? response.insight
                : fallbackInsight,
        };
    }

    function generateFacultyPaperSectionCRecommendations(payload) {
        bootstrap();
        const response = syncRequest('POST', 'generateFacultyPaperSectionCRecommendations', payload || {});
        return {
            success: response && response.success === true,
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
        entry.read = isAnnouncementReadForUser(entry, userKey);
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
        bootstrap();
        state.announcements = normalizeAnnouncementList(state.announcements || []);
        return deepClone(state.announcements);
    }

    function getAnnouncementsForCurrentUser(options) {
        bootstrap();
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
        bootstrap();
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
        bootstrap();
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
        bootstrap();
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

    function showUnreadAnnouncementLoginPopup(options) {
        bootstrap();
        if (typeof document === 'undefined' || !document.body) return [];

        const cfg = options && typeof options === 'object' ? options : {};
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

    function getSettings() {
        bootstrap();
        return Object.assign({}, state.settings);
    }

    function updateSettings(partial) {
        bootstrap();
        state.settings = Object.assign({}, state.settings, partial || {});
        dispatchChange(KEYS.SETTINGS, deepClone(state.settings));
        try {
            syncRequest('POST', 'updateSettings', { settings: partial || {} });
        } catch (error) {
            console.error('[DBData] Failed to persist settings.', error);
        }
        return state.settings;
    }

    function getEvalPeriods() {
        bootstrap();
        return Object.assign({}, state.evalPeriods);
    }

    function setEvalPeriods(periods) {
        bootstrap();
        state.evalPeriods = Object.assign({}, state.evalPeriods, periods || {});
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
        bootstrap();
        return state.semesterList || [];
    }

    function setSemesterList(list) {
        bootstrap();
        state.semesterList = Array.isArray(list) ? list : [];
        dispatchChange(KEYS.SEMESTER_LIST, deepClone(state.semesterList));
    }

    function addSemester(value, label) {
        bootstrap();
        if (!state.semesterList.find(function (item) { return item.value === value; })) {
            state.semesterList.push({ value, label });
            dispatchChange(KEYS.SEMESTER_LIST, deepClone(state.semesterList));
            try {
                syncRequest('POST', 'addSemester', { value, label });
            } catch (error) {
                console.error('[DBData] Failed to persist semester.', error);
            }
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
        if (index < 0) return;

        state.users[index] = Object.assign({}, state.users[index], updatedUser);
        dispatchChange(KEYS.USERS, deepClone(state.users));
    }

    function changeOwnEmail(currentEmail, newEmail, actor) {
        bootstrap();
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

    function changeOwnPassword(currentPassword, newPassword, actor) {
        bootstrap();
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

    function generateDeanProgramPeerAssignments(payload) {
        bootstrap();
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'generateDeanProgramPeerAssignments', body);
    }

    function generateCoordinatorProgramPeerAssignments(payload) {
        bootstrap();
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'generateCoordinatorProgramPeerAssignments', body);
    }

    function listDeanProgramPeerAssignmentsCurrent(actor) {
        bootstrap();
        return syncRequest('POST', 'listDeanProgramPeerAssignmentsCurrent', buildActorPayload(actor || {}));
    }

    function listCoordinatorProgramPeerAssignmentsCurrent(actor) {
        bootstrap();
        return syncRequest('POST', 'listCoordinatorProgramPeerAssignmentsCurrent', buildActorPayload(actor || {}));
    }

    function listDeanProgramPeerAssignmentDetailsCurrent(payload) {
        bootstrap();
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'listDeanProgramPeerAssignmentDetailsCurrent', body);
    }

    function listCoordinatorProgramPeerAssignmentDetailsCurrent(payload) {
        bootstrap();
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'listCoordinatorProgramPeerAssignmentDetailsCurrent', body);
    }

    function autoGeneratePeerRoom(payload) {
        bootstrap();
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'autoGeneratePeerRoom', body);
    }

    function listDeanPeerRoomsCurrent(actor) {
        bootstrap();
        return syncRequest('POST', 'listDeanPeerRoomsCurrent', buildActorPayload(actor || {}));
    }

    function listProfessorPeerAssignmentsCurrent(actor) {
        bootstrap();
        return syncRequest('POST', 'listProfessorPeerAssignmentsCurrent', buildActorPayload(actor || {}));
    }

    function listDeanPeerRoomMembersCurrent(actor, roomId) {
        bootstrap();
        const body = Object.assign({ roomId: roomId }, buildActorPayload(actor || {}));
        return syncRequest('POST', 'listDeanPeerRoomMembersCurrent', body);
    }

    function listDeanPeerRoomEligibleProfessorsCurrent(actor, roomId) {
        bootstrap();
        const body = Object.assign({ roomId: roomId }, buildActorPayload(actor || {}));
        return syncRequest('POST', 'listDeanPeerRoomEligibleProfessorsCurrent', body);
    }

    function addDeanPeerRoomMembers(payload) {
        bootstrap();
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'addDeanPeerRoomMembers', body);
    }

    function removeDeanPeerRoomMember(payload) {
        bootstrap();
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'removeDeanPeerRoomMember', body);
    }

    function dismantleDeanPeerRoom(payload) {
        bootstrap();
        const body = Object.assign({}, payload || {}, buildActorPayload(payload || {}));
        return syncRequest('POST', 'dismantleDeanPeerRoom', body);
    }

    function listFacultyPapers(actorRole, actorUserId) {
        bootstrap();
        const response = syncRequest('POST', 'listFacultyPapers', {});
        state.facultyAcknowledgementPapers = Array.isArray(response.papers) ? response.papers : [];
        dispatchChange(KEYS.FACULTY_PAPERS, deepClone(state.facultyAcknowledgementPapers));
        return deepClone(state.facultyAcknowledgementPapers);
    }

    function getFacultyPapers() {
        bootstrap();
        return deepClone(state.facultyAcknowledgementPapers);
    }

    function upsertFacultyPaperDraft(payload) {
        bootstrap();
        const response = syncRequest('POST', 'upsertFacultyPaperDraft', payload || {});
        if (response && response.paper) {
            dispatchChange(KEYS.FACULTY_PAPERS, response.paper);
        }
        return response || {};
    }

    function archiveFacultyPaper(payload) {
        bootstrap();
        const response = syncRequest('POST', 'archiveFacultyPaper', payload || {});
        if (Array.isArray(response && response.papers)) {
            state.facultyAcknowledgementPapers = response.papers;
            dispatchChange(KEYS.FACULTY_PAPERS, deepClone(state.facultyAcknowledgementPapers));
        }
        return response || {};
    }

    function sendFacultyPaper(payload) {
        bootstrap();
        const response = syncRequest('POST', 'sendFacultyPaper', payload || {});
        if (Array.isArray(response && response.papers)) {
            state.facultyAcknowledgementPapers = response.papers;
            dispatchChange(KEYS.FACULTY_PAPERS, deepClone(state.facultyAcknowledgementPapers));
        }
        return response || {};
    }

    function saveFacultyPaperSectionC(payload) {
        bootstrap();
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

    bootstrap(false);

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
        refreshSession,
        requireSession,
        setSession,
        clearSession,
        isAuthenticated,
        getRole,
        getUsername,
        getProfilePhoto,
        setProfilePhoto,
        uploadProfilePhoto,
        getProfileData,
        setProfileData,
        getUsers,
        listUsers,
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
        addEvaluation,
        getStudentEvaluationDrafts,
        upsertStudentEvaluationDraft,
        removeStudentEvaluationDraft,
        getDataPrivacyConsentNotice,
        getStudentDataPrivacyConsents,
        hasStudentDataPrivacyConsent,
        recordStudentDataPrivacyConsent,
        getOsaStudentClearances,
        upsertOsaStudentClearance,
        getStudentEvaluationProofRequests,
        submitStudentEvaluationProof,
        reviewStudentEvaluationProof,
        getSubjectManagement,
        upsertSubject,
        importSubjects,
        upsertCourseOffering,
        importCourseOfferings,
        markExcessCourseOfferings,
        setCourseOfferingStudents,
        deactivateCourseOffering,
        getActivityLog,
        searchActivityLog,
        addActivityLogEntry,
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
        analyzeBiasComments,
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
        getEvalPeriods,
        setEvalPeriods,
        isEvalPeriodOpen,
        getEvalPeriodDates,
        getSemesterList,
        setSemesterList,
        addSemester,
        changeOwnEmail,
        changeOwnPassword,
        generateDeanProgramPeerAssignments,
        generateCoordinatorProgramPeerAssignments,
        listDeanProgramPeerAssignmentsCurrent,
        listCoordinatorProgramPeerAssignmentsCurrent,
        listDeanProgramPeerAssignmentDetailsCurrent,
        listCoordinatorProgramPeerAssignmentDetailsCurrent,
        autoGeneratePeerRoom,
        listDeanPeerRoomsCurrent,
        listProfessorPeerAssignmentsCurrent,
        listDeanPeerRoomMembersCurrent,
        listDeanPeerRoomEligibleProfessorsCurrent,
        addDeanPeerRoomMembers,
        removeDeanPeerRoomMember,
        dismantleDeanPeerRoom,
        getFacultyPapers,
        listFacultyPapers,
        upsertFacultyPaperDraft,
        archiveFacultyPaper,
        sendFacultyPaper,
        saveFacultyPaperSectionC,
        onDataChange,
        bootstrap,
    };
})();
