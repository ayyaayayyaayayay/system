const assert = require('assert');
const fs = require('fs');
const path = require('path');
const SetCalculation = require('../JsScrip/set-calculation.js');

function enrollments(offeringId, count) {
    return Array.from({ length: count }, (_, index) => ({
        courseOfferingId: offeringId,
        studentUserId: `u${index + 1}`,
        status: 'enrolled',
    }));
}

function evaluation(id, offeringId, studentId, rating, status = 'submitted') {
    return {
        id,
        databaseEvaluationId: id,
        evaluatorRole: 'student',
        status,
        courseOfferingId: offeringId,
        semesterId: '1st-2026',
        evaluateeUserId: 'u500',
        evaluatorUserId: `u${studentId}`,
        submittedAt: `2026-09-${String(Math.min(id, 28)).padStart(2, '0')}T08:00:00+08:00`,
        ratings: { q1: rating, q2: rating },
    };
}

const offerings = [
    { id: 101, professorUserId: 'u500', subjectCode: 'AIS311', sectionName: '3/1', semesterSlug: '1st-2026', isActive: true },
    { id: 102, professorUserId: 'u500', subjectCode: 'IS314', sectionName: '3/1', semesterSlug: '1st-2026', isActive: true },
];
const enrollmentRows = [
    ...enrollments(101, 20),
    ...enrollments(102, 30),
    { courseOfferingId: 101, studentUserId: 'u1', status: 'completed' },
    { courseOfferingId: 101, studentUserId: 'u999', status: 'dropped' },
    { courseOfferingId: 101, studentUserId: 'u998', status: 'inactive' },
    { courseOfferingId: 101, studentUserId: 'u997', status: 'cancelled' },
    { courseOfferingId: 101, studentUserId: 'u996', status: '' },
];
const evaluations = [];
for (let studentId = 1; studentId <= 10; studentId += 1) {
    evaluations.push(evaluation(studentId, 101, studentId, 4.5));
    evaluations.push(evaluation(10 + studentId, 102, studentId, 4.25));
}
evaluations.push(evaluation(21, 101, 999, 5));
evaluations.push(evaluation(22, 101, 11, 5, 'draft'));
evaluations.push({ ...evaluation(23, 101, 12, 5), status: '' });
evaluations.push({ ...evaluation(24, 101, 13, 5), ratings: { q1: 0, q2: 6 } });
evaluations.push(evaluation(25, 999, 1, 5));
evaluations.push({ ...evaluation(26, 101, 15, 5), evaluateeUserId: 'u501' });
evaluations.push({ ...evaluation(27, 101, 16, 5), semesterId: '2nd-2026' });

const result = SetCalculation.calculateProfessorSetMetrics({
    professorUserId: 'u500',
    semesterId: '1st-2026',
    offerings,
    enrollments: enrollmentRows,
    evaluations,
});

assert.strictEqual(result.registered, 50);
assert.strictEqual(result.completed, 20);
assert.strictEqual(result.pending, 30);
assert.strictEqual(result.byOffering[0].registered, 20);
assert.strictEqual(result.byOffering[0].completed, 10);
assert.strictEqual(result.byOffering[0].completionRate, 50);
assert.strictEqual(result.byOffering[0].averageRatingPercent, 90);
assert.strictEqual(result.byOffering[0].weightedScorePercent, 1800);
assert.strictEqual(result.totalWeightedScorePercent, 4350);
assert.strictEqual(result.averageRatingPercent, 87);
assert.strictEqual(result.byOffering[0].respondentCount, 10);
assert.strictEqual(result.byOffering[0].validRatingCount, 20);

const partial = SetCalculation.calculateProfessorSetMetrics({
    professorUserId: 'u500',
    semesterId: '1st-2026',
    offerings: [
        ...offerings,
        { id: 106, professorUserId: 'u500', subjectCode: 'NO-RESPONSE', sectionName: '3/2', semesterSlug: '1st-2026', isActive: true },
    ],
    enrollments: [
        ...enrollmentRows,
        ...enrollments(106, 25),
    ],
    evaluations,
});
const noResponseClass = partial.byOffering.find(item => item.courseOfferingId === '106');
assert.strictEqual(partial.registered, 75);
assert.strictEqual(partial.completed, 20);
assert.strictEqual(partial.pending, 55);
assert.strictEqual(partial.scorableRegistered, 50);
assert.strictEqual(partial.excludedRegistered, 25);
assert.strictEqual(partial.registeredClassCount, 3);
assert.strictEqual(partial.scorableClassCount, 2);
assert.strictEqual(partial.excludedClassCount, 1);
assert.strictEqual(partial.partial, true);
assert.strictEqual(partial.available, true);
assert.strictEqual(partial.averageRatingPercent, 87);
assert.strictEqual(partial.totalWeightedScorePercent, 4350);
assert.strictEqual(noResponseClass.registered, 25);
assert.strictEqual(noResponseClass.completed, 0);
assert.strictEqual(noResponseClass.averageRating, null);
assert.strictEqual(noResponseClass.weightedScore, null);
assert.strictEqual(noResponseClass.exclusionReason, 'no-valid-responses');

const duplicate = SetCalculation.calculateProfessorSetMetrics({
    professorUserId: 'u500',
    semesterId: '1st-2026',
    offerings: [offerings[0]],
    enrollments: enrollments(101, 1),
    evaluations: [evaluation(1, 101, 1, 1), evaluation(2, 101, 1, 5)],
});
assert.strictEqual(duplicate.completed, 1);
assert.strictEqual(duplicate.averageRating, 5);

const unavailable = SetCalculation.calculateProfessorSetMetrics({
    professorUserId: 'u500',
    semesterId: '1st-2026',
    offerings: [offerings[0]],
    enrollments: enrollments(101, 20),
    evaluations: [],
});
assert.strictEqual(unavailable.averageRating, null);
assert.strictEqual(unavailable.totalWeightedScore, null);
assert.strictEqual(unavailable.available, false);

const scoped = SetCalculation.calculateProfessorSetMetrics({
    professorUserId: 'u500',
    semesterId: '1st-2026',
    offerings: [
        offerings[0],
        { id: 103, professorUserId: 'u501', subjectCode: 'WRONG-PROF', sectionName: '3/1', semesterSlug: '1st-2026', isActive: true },
        { id: 104, professorUserId: 'u500', subjectCode: 'WRONG-TERM', sectionName: '3/1', semesterSlug: '2nd-2026', isActive: true },
        { id: 105, professorUserId: 'u500', subjectCode: 'INACTIVE', sectionName: '3/1', semesterSlug: '1st-2026', isActive: false },
    ],
    enrollments: [
        ...enrollments(101, 1),
        { courseOfferingId: 103, studentUserId: 'u1', status: 'enrolled' },
        { courseOfferingId: 104, studentUserId: 'u1', status: 'enrolled' },
        { courseOfferingId: 105, studentUserId: 'u1', status: 'enrolled' },
    ],
    evaluations: [
        evaluation(1, 101, 1, 4),
        evaluation(2, 103, 1, 5),
        evaluation(3, 104, 1, 5),
        evaluation(4, 105, 1, 5),
    ],
});
assert.strictEqual(scoped.byOffering.length, 1);
assert.strictEqual(scoped.registered, 1);
assert.strictEqual(scoped.completed, 1);
assert.strictEqual(scoped.averageRating, 4);

const historical = SetCalculation.calculateProfessorSetMetrics({
    professorUserId: 'u500',
    semesterId: 'historical-2025',
    offerings: [
        { id: 201, professorUserId: 'u500', subjectCode: 'HIST', sectionName: '4/1', semesterSlug: 'historical-2025', isActive: false },
    ],
    enrollments: [{ courseOfferingId: 201, studentUserId: 'u1', status: 'completed' }],
    evaluations: [{ ...evaluation(5, 201, 1, 4.5), semesterId: 'historical-2025' }],
});
assert.strictEqual(historical.registered, 1);
assert.strictEqual(historical.completed, 1);
assert.strictEqual(historical.averageRating, 4.5);

const root = path.resolve(__dirname, '..');
[
    ['html/profesorpanel.html', 'JsScrip/profesorpanel.js'],
    ['html/daenpanel.html', 'JsScrip/daenpanel.js'],
    ['html/procoorpanel.html', 'JsScrip/daenpanel.js'],
    ['html/hrpanel.html', 'JsScrip/hrpanel.js'],
    ['html/vpaapanel.html', 'JsScrip/vpaapanel.js'],
    ['html/adminpanel.html', 'JsScrip/adminpanel.js'],
].forEach(([htmlPath, scriptPath]) => {
    const html = fs.readFileSync(path.join(root, htmlPath), 'utf8');
    const script = fs.readFileSync(path.join(root, scriptPath), 'utf8');
    assert(html.includes('set-calculation.js?v=20260929b'), `${htmlPath} does not load the shared SET calculator.`);
    assert(script.includes('SetCalculation.calculateProfessorSetMetrics'), `${scriptPath} does not use the shared SET calculator.`);
});

const professorPanel = fs.readFileSync(path.join(root, 'JsScrip/profesorpanel.js'), 'utf8');
assert(professorPanel.includes('sections rated'), 'The professor panel does not disclose partial section coverage.');
assert(
    !professorPanel.includes('Partial &middot; ${scorableClassCount}/${registeredClassCount} sections rated'),
    'The professor dashboard still renders the overall partial-section badge.'
);
assert(
    professorPanel.includes('Partial &middot; ${ratedSections}/${coverageTotal} sections rated'),
    'The professor subject rows no longer disclose partial section coverage.'
);
assert(
    professorPanel.includes('previewDraftData ? previewDraftData.set_rating : (paper.set_rating || \'N/A\')'),
    'Draft faculty-paper previews do not prefer the live scoped SET calculation.'
);

const deanPanel = fs.readFileSync(path.join(root, 'JsScrip/daenpanel.js'), 'utf8');
assert(
    deanPanel.includes('<td data-label="Average Score">\' + averageText + \'</td>'),
    'The dean Faculty Response Rate & Counts table does not render the numeric average score.'
);
assert(
    !deanPanel.includes('<td data-label="Average Score">\' + averageText + partialText + \'</td>'),
    'The dean Faculty Response Rate & Counts table still renders the partial-section badge.'
);

console.log('SET calculation JavaScript tests passed.');
