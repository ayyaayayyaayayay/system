/**
 * Database-backed SharedData compatibility layer.
 * Replaces localStorage app-state persistence with PHP/MySQL persistence.
 */

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
    };

    const ROLE_KEYS = ['admin', 'hr', 'dean', 'professor', 'vpaa', 'osa', 'student'];
    const API_URL = '../api/app_state.php';

    const state = {
        users: [],
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
        profileData: {},
        profilePhotos: {},
    };

    let initialized = false;

    function deepClone(value) {
        return value == null ? value : JSON.parse(JSON.stringify(value));
    }

    function dispatchChange(key, value) {
        window.dispatchEvent(new CustomEvent('shareddata:change', {
            detail: { key, value }
        }));
    }

    function syncRequest(method, action, payload) {
        const xhr = new XMLHttpRequest();
        let url = API_URL + '?action=' + encodeURIComponent(action);
        if (method === 'GET') {
            url += '&_ts=' + Date.now();
        }
        xhr.open(method, url, false);
        xhr.setRequestHeader('Content-Type', 'application/json');
        xhr.send(payload ? JSON.stringify(payload) : null);

        if (xhr.status < 200 || xhr.status >= 300) {
            throw new Error(xhr.responseText || ('Request failed with status ' + xhr.status));
        }

        return xhr.responseText ? JSON.parse(xhr.responseText) : {};
    }

    function applyBootstrap(snapshot) {
        state.users = Array.isArray(snapshot.users) ? snapshot.users : [];
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
        state.profileData = snapshot.profileData || {};
        state.profilePhotos = snapshot.profilePhotos || {};
    }

    function bootstrap(forceRefresh) {
        if (initialized && !forceRefresh) {
            return true;
        }

        try {
            const response = syncRequest('GET', 'bootstrap');
            if (response && response.success && response.state) {
                applyBootstrap(response.state);
                initialized = true;
                return true;
            }
        } catch (error) {
            console.warn(
                '[DBData] Bootstrap failed. Open the site through Apache/XAMPP over http://localhost so ../api/app_state.php can run.',
                error
            );
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
        const session = Object.assign({
            username,
            role,
            loginTime: new Date().toISOString(),
            isAuthenticated: true,
        }, extra);
        setJSON(KEYS.USER_SESSION, session);
        return session;
    }

    function clearSession() {
        remove(KEYS.USER_SESSION);
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

    function getProfilePhoto(role) {
        bootstrap();
        return state.profilePhotos[role || getRole()] || null;
    }

    function setProfilePhoto(role, dataUrl) {
        bootstrap();
        const finalRole = role || getRole();
        if (!finalRole) return;
        state.profilePhotos[finalRole] = dataUrl;
        dispatchChange('profilePhoto:' + finalRole, dataUrl);
        try {
            syncRequest('POST', 'setProfilePhoto', { role: finalRole, dataUrl });
        } catch (error) {
            console.error('[DBData] Failed to persist profile photo.', error);
        }
    }

    function getProfileData(role) {
        bootstrap();
        return state.profileData[role || getRole()] || null;
    }

    function setProfileData(role, data) {
        bootstrap();
        const finalRole = role || getRole();
        if (!finalRole) return;
        state.profileData[finalRole] = data;
        dispatchChange('profileData:' + finalRole, data);
        try {
            syncRequest('POST', 'setProfileData', { role: finalRole, data });
        } catch (error) {
            console.error('[DBData] Failed to persist profile data.', error);
        }
    }

    function getUsers() {
        bootstrap();
        return state.users;
    }

    function persistUsers() {
        try {
            const response = syncRequest('POST', 'setUsers', { users: state.users });
            if (response && Array.isArray(response.users)) {
                state.users = response.users;
            }
            dispatchChange(KEYS.USERS, deepClone(state.users));
        } catch (error) {
            console.error('[DBData] Failed to persist users.', error);
        }
        return state.users;
    }

    function setUsers(users) {
        bootstrap();
        state.users = Array.isArray(users) ? users : [];
        return persistUsers();
    }

    function addUser(user) {
        bootstrap();
        state.users.push(user);
        return persistUsers();
    }

    function updateUser(idOrUser, updatedData) {
        bootstrap();

        let id = idOrUser;
        let patch = updatedData;
        if (typeof idOrUser === 'object' && idOrUser !== null) {
            id = idOrUser.id;
            patch = idOrUser;
        }

        const index = state.users.findIndex(function (user) {
            return user.id === id;
        });

        if (index !== -1) {
            state.users[index] = Object.assign({}, state.users[index], patch || {});
            persistUsers();
        }

        return state.users;
    }

    function deleteUser(id) {
        bootstrap();
        state.users = state.users.filter(function (user) {
            return user.id !== id;
        });
        return persistUsers();
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
            syncRequest('POST', 'setEvaluations', { evaluations: state.evaluations });
            dispatchChange(KEYS.EVALUATIONS, deepClone(state.evaluations));
        } catch (error) {
            console.error('[DBData] Failed to persist evaluations.', error);
        }
    }

    function addEvaluation(evalData) {
        bootstrap();
        state.evaluations.push(Object.assign({
            id: 'eval_' + Date.now() + '_' + Math.floor(Math.random() * 1000),
            timestamp: new Date().toISOString(),
        }, evalData || {}));
        persistEvaluations();
        return true;
    }

    function getActivityLog() {
        bootstrap();
        return state.activityLog || [];
    }

    function addActivityLogEntry(entry) {
        bootstrap();
        const logEntry = Object.assign({
            id: 'LOG-' + String(state.activityLog.length + 1).padStart(4, '0'),
            timestamp: new Date().toISOString(),
        }, entry || {});

        state.activityLog.unshift(logEntry);
        if (state.activityLog.length > 200) {
            state.activityLog.length = 200;
        }

        dispatchChange(KEYS.ACTIVITY_LOG, deepClone(state.activityLog));
        try {
            syncRequest('POST', 'addActivityLogEntry', { entry: logEntry });
        } catch (error) {
            console.error('[DBData] Failed to persist activity log entry.', error);
        }

        return logEntry;
    }

    function getAnnouncements() {
        bootstrap();
        return state.announcements || [];
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
        const entry = Object.assign({
            id: 'ANN-' + Date.now(),
            timestamp: new Date().toISOString(),
            read: false,
        }, announcement || {});
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
        return state.announcements.filter(function (announcement) {
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
        setSession,
        clearSession,
        isAuthenticated,
        getRole,
        getUsername,
        getProfilePhoto,
        setProfilePhoto,
        getProfileData,
        setProfileData,
        getUsers,
        setUsers,
        addUser,
        updateUser,
        deleteUser,
        getCampuses,
        setCampuses,
        getAllDepartments,
        getProfessors,
        setProfessors,
        getCurrentSemester,
        setCurrentSemester,
        getQuestionnaires,
        setQuestionnaires,
        getEvaluations,
        addEvaluation,
        getActivityLog,
        addActivityLogEntry,
        getAnnouncements,
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
        onDataChange,
        bootstrap,
    };
})();
