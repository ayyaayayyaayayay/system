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
                '  <div class="app-loading-overlay-spinner" aria-hidden="true"></div>',
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
        OSA_STUDENT_CLEARANCES: 'osaStudentClearances',
        STUDENT_EVAL_PROOF_REQUESTS: 'studentEvaluationProofRequests',
        SUBJECT_MANAGEMENT: 'subjectManagement',
        PROGRAMS: 'sharedProgramsData',
        FACULTY_PAPERS: 'facultyAcknowledgementPapers',
    };

    const API_URL = '../api/app_state.php';
    const LOGIN_API_URL = '../api/login.php';
    const SESSION_URL = LOGIN_API_URL + '?action=session';
    const PROFILE_IMAGE_UPLOAD_URL = '../api/profile_image_upload.php';
    const USERS_CACHE_TTL_MS = 30000;

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

    function deepClone(value) {
        return value == null ? value : JSON.parse(JSON.stringify(value));
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
            csrfToken: String(source.csrfToken || '').trim(),
            loginTime: String(source.loginTime || new Date().toISOString()).trim(),
            isAuthenticated: true,
        };
    }

    function storeSessionPayload(payload) {
        const session = normalizeSessionPayload(payload);
        if (!session) {
            return null;
        }
        setJSON(KEYS.USER_SESSION, session);
        return session;
    }

    function clearSessionCache() {
        remove(KEYS.USER_SESSION);
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
        if (response && response.session) {
            storeSessionPayload(response.session);
        }
        return response;
    }

    function applyBootstrap(snapshot) {
        state.users = Array.isArray(snapshot.users) ? snapshot.users : [];
        usersLastSyncedAt = state.users.length ? Date.now() : 0;
        state.programs = Array.isArray(snapshot.programs) ? snapshot.programs : [];
        state.campuses = Array.isArray(snapshot.campuses) && snapshot.campuses.length
            ? snapshot.campuses
            : state.campuses;
        state.currentSemester = snapshot.currentSemester || '';
        state.questionnaires = snapshot.questionnaires || {};
        state.activityLog = Array.isArray(snapshot.activityLog) ? snapshot.activityLog : [];
        state.announcements = Array.isArray(snapshot.announcements) ? snapshot.announcements : [];
        state.settings = Object.assign({}, state.settings, snapshot.settings || {});
        state.evalPeriods = Object.assign({}, state.evalPeriods, snapshot.evalPeriods || {});
        state.semesterList = Array.isArray(snapshot.semesterList) ? snapshot.semesterList : [];
        state.evaluations = Array.isArray(snapshot.evaluations) ? snapshot.evaluations : [];
        state.studentEvaluationDrafts = Array.isArray(snapshot.studentEvaluationDrafts) ? snapshot.studentEvaluationDrafts : [];
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
        return getJSON(KEYS.USER_SESSION, null);
    }

    function setSession(username, role, extra = {}) {
        if (username && typeof username === 'object') {
            return storeSessionPayload(username);
        }
        const session = Object.assign({
            username,
            role,
            loginTime: new Date().toISOString(),
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
            senderEmail: String(config.senderEmail || ''),
            senderName: String(config.senderName || ''),
            hasAppPassword: !!config.hasAppPassword,
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
            senderEmail: String(savedConfig.senderEmail || ''),
            senderName: String(savedConfig.senderName || ''),
            hasAppPassword: !!savedConfig.hasAppPassword,
        };
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

    function normalizeAnnouncementUserId(value) {
        const raw = normalizeAnnouncementToken(value);
        if (!raw) return '';
        if (/^u\d+$/.test(raw)) return raw;
        if (/^\d+$/.test(raw)) return 'u' + String(parseInt(raw, 10));
        return raw;
    }

    function normalizeAnnouncementAudience(input) {
        const source = input && typeof input === 'object' ? input : {};
        const role = normalizeAnnouncementToken(source.role || source.targetRole || '');
        const campus = normalizeAnnouncementToken(source.campus || source.campusSlug || '');
        const programCode = normalizeAnnouncementToken(source.programCode || source.program || '');
        const studentCompletionRaw = normalizeAnnouncementToken(
            source.studentCompletion || source.completion || 'all'
        );
        const studentCompletion = studentCompletionRaw === 'completed' || studentCompletionRaw === 'not_completed'
            ? studentCompletionRaw
            : 'all';

        return {
            role: role === 'all' ? '' : role,
            campus: campus === 'all' ? '' : campus,
            programCode: programCode === 'all' ? '' : programCode,
            studentCompletion: studentCompletion,
        };
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
        return state.announcements || [];
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

        const announcements = Array.isArray(state.announcements) ? state.announcements : [];
        const visible = announcements.filter(function (item) {
            return announcementMatchesCurrentUser(item, context);
        });

        const limit = Number(cfg.limit);
        if (Number.isFinite(limit) && limit > 0) {
            return deepClone(visible.slice(0, limit));
        }

        return deepClone(visible);
    }

    function persistAnnouncements() {
        try {
            syncRequest('POST', 'setAnnouncements', { announcements: state.announcements });
            dispatchChange(KEYS.ANNOUNCEMENTS, deepClone(state.announcements));
        } catch (error) {
            console.error('[DBData] Failed to persist announcements.', error);
        }
    }

    function addAnnouncement(announcement) {
        bootstrap();
        const session = getSession() || {};
        const nowIso = new Date().toISOString();
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
        entry.createdAt = String(entry.createdAt || entry.timestamp || nowIso);
        entry.timestamp = entry.createdAt;
        entry.createdByRole = normalizeAnnouncementToken(entry.createdByRole || session.role || '');
        entry.createdByUserId = String(entry.createdByUserId || session.userId || '').trim();
        entry.audience = normalizeAnnouncementAudience(entry.audience || {});
        state.announcements.unshift(entry);
        if (state.announcements.length > 50) {
            state.announcements.length = 50;
        }
        persistAnnouncements();
        return entry;
    }

    function markAnnouncementRead(id) {
        bootstrap();
        const item = state.announcements.find(function (announcement) {
            return announcement.id === id;
        });
        if (item) {
            item.read = true;
            persistAnnouncements();
        }
    }

    function getUnreadAnnouncementCount() {
        bootstrap();
        const visibleAnnouncements = getAnnouncementsForCurrentUser();
        return visibleAnnouncements.filter(function (announcement) {
            return !announcement.read;
        }).length;
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

        const today = new Date();
        today.setHours(0, 0, 0, 0);
        const start = new Date(period.start + 'T00:00:00');
        const end = new Date(period.end + 'T23:59:59');
        return today >= start && today <= end;
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
        setJSON(KEYS.USER_SESSION, next);
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
        setCourseOfferingStudents,
        deactivateCourseOffering,
        getActivityLog,
        searchActivityLog,
        addActivityLogEntry,
        getCredentialDistributorConfig,
        saveCredentialDistributorConfig,
        bulkDistributeCredentials,
        sendBulkTestGmail,
        analyzeBiasComments,
        analyzeEvaluationExplainability,
        generateFacultyPaperSectionCRecommendations,
        getAnnouncements,
        getAnnouncementsForCurrentUser,
        addAnnouncement,
        markAnnouncementRead,
        getUnreadAnnouncementCount,
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
        autoGeneratePeerRoom,
        listDeanPeerRoomsCurrent,
        listProfessorPeerAssignmentsCurrent,
        listDeanPeerRoomMembersCurrent,
        listDeanPeerRoomEligibleProfessorsCurrent,
        addDeanPeerRoomMembers,
        removeDeanPeerRoomMember,
        dismantleDeanPeerRoom,
        listFacultyPapers,
        upsertFacultyPaperDraft,
        archiveFacultyPaper,
        sendFacultyPaper,
        saveFacultyPaperSectionC,
        onDataChange,
        bootstrap,
    };
})();
