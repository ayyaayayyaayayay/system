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
        STUDENT_EVAL_DRAFTS: 'studentEvaluationDrafts',
        OSA_STUDENT_CLEARANCES: 'osaStudentClearances',
        SUBJECT_MANAGEMENT: 'subjectManagement',
        PROGRAMS: 'sharedProgramsData',
        FACULTY_PAPERS: 'facultyAcknowledgementPapers',
    };

    const ROLE_KEYS = ['admin', 'hr', 'dean', 'professor', 'vpaa', 'osa', 'student'];
    const API_URL = '../api/app_state.php';

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
        subjectManagement: {
            subjects: [],
            offerings: [],
            enrollments: [],
        },
        facultyAcknowledgementPapers: [],
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
            let message = 'Request failed with status ' + xhr.status;
            if (xhr.responseText) {
                try {
                    const parsed = JSON.parse(xhr.responseText);
                    message = parsed && parsed.error ? String(parsed.error) : xhr.responseText;
                } catch (_error) {
                    message = xhr.responseText;
                }
            }
            const error = new Error(message);
            error.status = xhr.status;
            throw error;
        }

        return xhr.responseText ? JSON.parse(xhr.responseText) : {};
    }

    function applyBootstrap(snapshot) {
        state.users = Array.isArray(snapshot.users) ? snapshot.users : [];
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
        const subjectManagement = snapshot.subjectManagement || {};
        state.subjectManagement = {
            subjects: Array.isArray(subjectManagement.subjects) ? subjectManagement.subjects : [],
            offerings: Array.isArray(subjectManagement.offerings) ? subjectManagement.offerings : [],
            enrollments: Array.isArray(subjectManagement.enrollments) ? subjectManagement.enrollments : [],
        };
        state.facultyAcknowledgementPapers = Array.isArray(snapshot.facultyAcknowledgementPapers)
            ? snapshot.facultyAcknowledgementPapers
            : [];
        state.profileData = snapshot.profileData || {};
        state.profilePhotos = snapshot.profilePhotos || {};
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

    function getPrograms() {
        bootstrap();
        return state.programs || [];
    }

    function persistUsers() {
        try {
            return persistUsersStrict();
        } catch (error) {
            console.error('[DBData] Failed to persist users.', error);
        }
        return state.users;
    }

    function persistUsersStrict() {
        const response = syncRequest('POST', 'setUsers', { users: state.users });
        if (response && Array.isArray(response.users)) {
            state.users = response.users;
        }
        dispatchChange(KEYS.USERS, deepClone(state.users));
        return state.users;
    }

    function setUsers(users) {
        bootstrap();
        state.users = Array.isArray(users) ? users : [];
        return persistUsers();
    }

    function setUsersStrict(users) {
        bootstrap();
        state.users = Array.isArray(users) ? users : [];
        return persistUsersStrict();
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
        const response = syncRequest('POST', 'upsertOsaStudentClearance', { record: record || {} });
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
        const response = syncRequest('POST', 'listFacultyPapers', {
            actor_role: actorRole,
            actor_user_id: actorUserId,
        });
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
        getPrograms,
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
