'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const root = path.resolve(__dirname, '..');
const osaSource = fs.readFileSync(path.join(root, 'JsScrip/osapanel.js'), 'utf8');
const vpaaSource = fs.readFileSync(path.join(root, 'JsScrip/vpaapanel.js'), 'utf8')
    .replace(/\ninit\(\);\s*$/, '');

function buildOsaRowCount(enrollmentStatus) {
    const SharedData = {
        getCurrentSemester: () => 'sem-1',
        getCachedUsers: () => [{
            id: 'u1', role: 'student', status: 'active', studentNumber: 'S1', name: 'Student One',
            department: 'D', programCode: 'P', campus: 'campus-a',
        }],
        getCachedSubjectManagement: () => ({
            offerings: [{ id: 'o1', isActive: true, semesterSlug: 'sem-1' }],
            enrollments: [{
                courseOfferingId: 'o1', studentUserId: 'u1', studentNumber: 'S1', status: enrollmentStatus,
            }],
        }),
        getCachedEvaluations: () => [{
            courseOfferingId: 'o1', studentUserId: 'u1', semesterId: 'sem-1',
            status: 'submitted', evaluatorRole: 'student',
        }],
        getOsaStudentClearances: () => [],
        getStudentEvaluationProofRequests: () => [],
        getEvalPeriodDates: () => ({ end: '2026-09-01' }),
        getCurrentPhilippineDateYmd: () => '2026-10-01',
    };
    const context = { SharedData, document: { addEventListener: () => {} }, window: {}, console };
    vm.createContext(context);
    vm.runInContext(osaSource, context);
    return vm.runInContext('buildStatusRows().rows.length', context);
}

function createNode(id) {
    return {
        id,
        textContent: '',
        value: id === 'semesterFilter' ? 'sem-1' : 'all',
        innerHTML: '',
        options: [],
        appendChild: () => {},
        classList: { add: () => {}, remove: () => {}, toggle: () => {}, contains: () => false },
        setAttribute: () => {},
        removeAttribute: () => {},
        querySelector: () => null,
        querySelectorAll: () => [],
        closest: () => null,
    };
}

function buildVpaaContext(enrollmentStatus = 'enrolled') {
    const nodes = new Map();
    const getNode = (id) => {
        if (!nodes.has(id)) nodes.set(id, createNode(id));
        return nodes.get(id);
    };
    const users = [
        { id: 's1', role: 'student', status: 'active', campus: 'campus-a', department: 'D' },
        { id: 's2', role: 'student', status: 'active', campus: 'campus-b', department: 'D' },
    ];
    const SharedData = {
        getCurrentSemester: () => 'sem-1',
        getSemesterList: () => [{ value: 'sem-1' }, { value: 'sem-2' }],
        getCachedUsers: () => users,
        getUsers: () => users,
        getCachedSubjectManagement: () => ({
            offerings: [{ id: 'o1', isActive: true, semesterSlug: 'sem-1' }],
            enrollments: [{ courseOfferingId: 'o1', studentUserId: 's1', status: enrollmentStatus }],
        }),
        getCachedEvaluations: () => [{
            id: 'e1', courseOfferingId: 'o1', studentUserId: 's1', semesterId: 'sem-1',
            status: 'submitted', evaluatorRole: 'student',
        }],
        getQuestionnaires: () => ({}),
        getCampuses: () => [],
        fetchSubjectManagementSnapshot: ({ semesterId }) => Promise.resolve({
            subjects: [],
            offerings: [{ id: `offering-${semesterId}`, isActive: true, semesterSlug: semesterId }],
            enrollments: [{ id: `enrollment-${semesterId}`, courseOfferingId: `offering-${semesterId}`, studentUserId: 's1', status: 'enrolled' }],
        }),
        fetchEvaluationsSnapshot: ({ semesterId }) => Promise.resolve([{
            id: `evaluation-${semesterId}`, semesterId, evaluatorRole: 'student', status: 'submitted',
        }]),
        fetchVpaaPeerAssignmentCounts: ({ semesterId }) => Promise.resolve({
            semesterId,
            professors: [{ professorUserId: 'p1', required: 2, submitted: 1 }],
        }),
    };
    const context = {
        SharedData,
        document: {
            getElementById: getNode,
            querySelector: () => null,
            querySelectorAll: () => [],
            createElement: () => createNode('created'),
        },
        window: { SetCalculation: {} },
        Chart: function Chart() {},
        console,
    };
    vm.createContext(context);
    vm.runInContext(vpaaSource, context);
    return context;
}

(async function run() {
    assert.equal(buildOsaRowCount('enrolled'), 1);
    assert.equal(buildOsaRowCount('completed'), 1, 'OSA must retain completed enrollments in clearance monitoring.');

    const completedContext = buildVpaaContext('completed');
    assert.equal(
        vm.runInContext('buildVpaaStudentAnalyticsRows().length', completedContext),
        1,
        'VPAA descriptive analytics must retain completed enrollments.'
    );

    const summary = JSON.parse(vm.runInContext(`
        updateSummary([{
            userId: 'p1', isActive: true, students: 10,
            requiredEvaluations: 13, evaluations: 12
        }], { campus: 'all', department: 'all' });
        JSON.stringify({
            rate: elements.completionRate.textContent,
            pending: elements.pendingEvaluations.textContent
        });
    `, completedContext));
    assert.deepEqual(summary, { rate: '92%', pending: '1' });

    const professorCount = vm.runInContext(`
        updateSummary([
            { userId: 'p1', isActive: true, requiredEvaluations: 5, evaluations: 5 },
            { userId: 'p1', isActive: true, requiredEvaluations: 5, evaluations: 5 },
            { userId: 'p2', isActive: false, requiredEvaluations: 5, evaluations: 5 }
        ], { campus: 'all', department: 'all' });
        elements.activeProfessors.textContent;
    `, completedContext);
    assert.equal(professorCount, '1', 'Active professor count must be unique and exclude inactive faculty.');

    const scopedSummary = JSON.parse(vm.runInContext(`
        elements.searchInput.value = '';
        elements.semesterFilter.value = 'sem-1';
        elements.campusFilter.value = 'campus-a';
        elements.departmentFilter.value = 'all';
        elements.sortFilter.value = 'name';
        allProfessorData = [
            { userId: 'p1', name: 'A', employeeId: '1', subjects: [], semester: 'sem-1', campus: 'campus-a', department: 'D', overall: 4, responseRate: 100, students: 5, requiredEvaluations: 5, evaluations: 5, isActive: true },
            { userId: 'p2', name: 'B', employeeId: '2', subjects: [], semester: 'sem-1', campus: 'campus-b', department: 'D', overall: 3, responseRate: 0, students: 50, requiredEvaluations: 50, evaluations: 0, isActive: true }
        ];
        refreshDashboardChartsForSemester = () => {};
        renderDashboardCharts = () => {};
        closeReportModal = () => {};
        updateWordFrequency = () => {};
        renderKeyHighlights = () => {};
        renderProfessors = () => {};
        applyFilters();
        JSON.stringify({
            students: elements.totalStudents.textContent,
            rate: elements.completionRate.textContent,
            professors: elements.activeProfessors.textContent
        });
    `, completedContext));
    assert.deepEqual(scopedSummary, { students: '1', rate: '100%', professors: '1' });

    const allSemesterContext = buildVpaaContext();
    await vm.runInContext('refreshVpaaSemesterDataSelection("all", true)', allSemesterContext);
    const historicalCounts = JSON.parse(vm.runInContext(`JSON.stringify({
        offerings: vpaaSubjectManagementSnapshot.offerings.length,
        evaluations: vpaaEvaluationsSnapshot.length,
        peerAssignments: vpaaPeerAssignmentCountsSnapshot.length
    })`, allSemesterContext));
    assert.deepEqual(historicalCounts, { offerings: 2, evaluations: 2, peerAssignments: 2 });

    const peerCounts = JSON.parse(vm.runInContext(`
        JSON.stringify((() => {
            const context = buildVpaaDatabaseContext();
            return context.peerAssignmentCountsByProfessorSemester['sem-1|p1'];
        })())
    `, allSemesterContext));
    assert.deepEqual(peerCounts, { required: 2, submitted: 1 });

    const canonicalSummary = JSON.parse(vm.runInContext(`
        vpaaInstitutionDashboardSummary = {
            semesterId: 'sem-1',
            users: { students: 1013, professors: 80 },
            studentRegistration: { total: 2139, completed: 50, pending: 2089, completionRate: 2 },
            evaluationReports: {
                studentToProfessor: { totalEvaluations: 50, evaluatedCount: 12, averageRating: 4.2, ratingDistribution: { 5: 30, 4: 20 } },
                professorToProfessor: { totalEvaluations: 8, evaluatedCount: 6, averageRating: 4.1, ratingDistribution: { 4: 8 } },
                supervisorToProfessor: { totalEvaluations: 5, evaluatedCount: 5, averageRating: 4.0, ratingDistribution: { 4: 5 } }
            }
        };
        elements.semesterFilter.value = 'sem-1';
        elements.campusFilter.value = 'all';
        elements.departmentFilter.value = 'all';
        elements.searchInput.value = '';
        updateSummary([], { campus: 'all', department: 'all' });
        refreshDashboardChartsForSemester('sem-1');
        JSON.stringify({
            students: elements.totalStudents.textContent,
            rate: elements.completionRate.textContent,
            pending: elements.pendingEvaluations.textContent,
            professors: elements.activeProfessors.textContent,
            studentEvaluations: vpaaChartDataByType.student.totalEvaluations,
            peerEvaluations: vpaaChartDataByType.professor.totalEvaluations,
            supervisorEvaluations: vpaaChartDataByType.supervisor.totalEvaluations
        });
    `, allSemesterContext));
    assert.deepEqual(canonicalSummary, {
        students: '1,013',
        rate: '2%',
        pending: '2,089',
        professors: '80',
        studentEvaluations: 50,
        peerEvaluations: 8,
        supervisorEvaluations: 5,
    });

    assert.equal(
        vpaaSource.includes('Math.max(activeProfessorCount - 1, 0)'),
        false,
        'VPAA pending totals must not assume every professor evaluates every other professor.'
    );

    console.log('VPAA and OSA panel regression tests passed.');
})().catch((error) => {
    console.error(error);
    process.exitCode = 1;
});
