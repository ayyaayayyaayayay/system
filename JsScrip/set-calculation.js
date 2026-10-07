(function initSetCalculation(root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.SetCalculation = api;
    }
})(typeof window !== 'undefined' ? window : globalThis, function buildSetCalculationApi() {
    'use strict';

    function token(value) {
        return String(value == null ? '' : value).trim().toLowerCase();
    }

    function userToken(value) {
        const valueToken = token(value);
        if (/^u\d+$/.test(valueToken)) return valueToken.slice(1);
        if (/^\d+$/.test(valueToken)) return String(parseInt(valueToken, 10));
        return valueToken;
    }

    function isEligibleEnrollmentStatus(value) {
        const status = token(value);
        return status === 'enrolled' || status === 'completed';
    }

    function isStudentEvaluation(evaluation) {
        const type = token(evaluation && (evaluation.evaluatorRole || evaluation.evaluationType));
        return type === 'student' || type === 'student-professor' || type === 'student-to-professor';
    }

    function isSubmittedEvaluation(evaluation) {
        const status = token(evaluation && evaluation.status);
        return status === 'submitted';
    }

    function evaluationStudentToken(evaluation) {
        const candidates = [
            evaluation && evaluation.studentUserId,
            evaluation && evaluation.evaluatorUserId,
            evaluation && evaluation.studentId,
            evaluation && evaluation.evaluatorId,
            evaluation && evaluation.evaluatorStudentNumber,
            evaluation && evaluation.evaluatorUsername,
            evaluation && evaluation.evaluatorEmail,
        ];
        for (let index = 0; index < candidates.length; index += 1) {
            const value = userToken(candidates[index]);
            if (value) return value;
        }
        return '';
    }

    function enrollmentStudentToken(enrollment) {
        return userToken(
            enrollment && (
                enrollment.studentUserId ||
                enrollment.studentId ||
                enrollment.studentNumber ||
                enrollment.studentName
            )
        );
    }

    function validRatings(evaluation) {
        const ratings = evaluation && evaluation.ratings && typeof evaluation.ratings === 'object'
            ? Object.values(evaluation.ratings)
            : [];
        return ratings.map(Number).filter(function (value) {
            return Number.isFinite(value) && value >= 1 && value <= 5;
        });
    }

    function questionnaireAverage(evaluation) {
        const ratings = validRatings(evaluation);
        if (!ratings.length) return null;
        return ratings.reduce(function (sum, value) { return sum + value; }, 0) / ratings.length;
    }

    function evaluationOrder(evaluation) {
        const timestamp = Date.parse(String(
            evaluation && (evaluation.submittedAt || evaluation.timestamp) || ''
        ));
        const databaseId = Number(evaluation && (evaluation.databaseEvaluationId || evaluation.id));
        return {
            timestamp: Number.isFinite(timestamp) ? timestamp : 0,
            databaseId: Number.isFinite(databaseId) ? databaseId : 0,
            id: token(evaluation && evaluation.id),
        };
    }

    function isLaterEvaluation(candidate, current) {
        if (!current) return true;
        const left = evaluationOrder(candidate);
        const right = evaluationOrder(current);
        if (left.timestamp !== right.timestamp) return left.timestamp > right.timestamp;
        if (left.databaseId !== right.databaseId) return left.databaseId > right.databaseId;
        return left.id > right.id;
    }

    function calculateProfessorSetMetrics(input) {
        const options = input && typeof input === 'object' ? input : {};
        const professorId = userToken(options.professorUserId || options.professorId);
        const semesterId = token(options.semesterId);
        const offerings = Array.isArray(options.offerings) ? options.offerings : [];
        const enrollments = Array.isArray(options.enrollments) ? options.enrollments : [];
        const evaluations = Array.isArray(options.evaluations) ? options.evaluations : [];
        const completedOfferingIds = new Set();
        enrollments.forEach(function (enrollment) {
            if (token(enrollment && enrollment.status) !== 'completed') return;
            const offeringId = token(enrollment && enrollment.courseOfferingId);
            if (offeringId) completedOfferingIds.add(offeringId);
        });

        const offeringsById = new Map();
        offerings.forEach(function (offering) {
            if (!offering) return;
            const offeringId = token(offering.id || offering.courseOfferingId);
            if (!offeringId) return;
            if (professorId && userToken(offering.professorUserId || offering.professorId) !== professorId) return;
            const offeringSemester = token(offering.semesterSlug || offering.semesterId);
            if (semesterId && semesterId !== 'all' && offeringSemester && offeringSemester !== semesterId) return;
            if (
                offering.isActive === false
                && options.includeInactiveOfferings !== true
                && !completedOfferingIds.has(offeringId)
            ) return;
            offeringsById.set(offeringId, offering);
        });

        const expectedByOffering = new Map();
        enrollments.forEach(function (enrollment) {
            if (!enrollment || !isEligibleEnrollmentStatus(enrollment.status)) return;
            const offeringId = token(enrollment.courseOfferingId);
            if (!offeringsById.has(offeringId)) return;
            const studentId = enrollmentStudentToken(enrollment);
            if (!studentId) return;
            if (!expectedByOffering.has(offeringId)) expectedByOffering.set(offeringId, new Set());
            expectedByOffering.get(offeringId).add(studentId);
        });

        const latestByPair = new Map();
        evaluations.forEach(function (evaluation) {
            if (!evaluation || !isStudentEvaluation(evaluation) || !isSubmittedEvaluation(evaluation)) return;
            const evaluationSemester = token(evaluation.semesterId || evaluation.semesterSlug);
            if (semesterId && semesterId !== 'all' && evaluationSemester !== semesterId) return;
            const offeringId = token(evaluation.courseOfferingId);
            const offering = offeringsById.get(offeringId);
            const targetProfessorId = userToken(
                evaluation.evaluateeUserId
                || evaluation.targetProfessorId
                || evaluation.professorUserId
                || evaluation.professorId
                || evaluation.targetId
            );
            const offeringProfessorId = userToken(offering && (offering.professorUserId || offering.professorId));
            if (targetProfessorId && offeringProfessorId && targetProfessorId !== offeringProfessorId) return;
            const expectedStudents = expectedByOffering.get(offeringId);
            if (!expectedStudents) return;
            const studentId = evaluationStudentToken(evaluation);
            if (!studentId || !expectedStudents.has(studentId)) return;
            if (questionnaireAverage(evaluation) === null) return;
            const pairKey = `${studentId}|${offeringId}`;
            const current = latestByPair.get(pairKey);
            if (isLaterEvaluation(evaluation, current)) latestByPair.set(pairKey, evaluation);
        });

        const byOffering = [];
        let totalRegistered = 0;
        let totalCompleted = 0;
        let totalValidRatings = 0;
        let totalWeightedScore = 0;
        let scorableRegistered = 0;
        let excludedRegistered = 0;
        let registeredClassCount = 0;
        let scorableClassCount = 0;
        let excludedClassCount = 0;

        offeringsById.forEach(function (offering, offeringId) {
            const expectedStudents = expectedByOffering.get(offeringId) || new Set();
            const submitted = [];
            expectedStudents.forEach(function (studentId) {
                const evaluation = latestByPair.get(`${studentId}|${offeringId}`);
                if (evaluation) submitted.push(evaluation);
            });

            const questionnaireAverages = submitted.map(questionnaireAverage).filter(function (value) {
                return value !== null;
            });
            const registered = expectedStudents.size;
            const completed = questionnaireAverages.length;
            const validRatingCount = submitted.reduce(function (sum, evaluation) {
                return sum + validRatings(evaluation).length;
            }, 0);
            const available = registered > 0 && completed > 0;
            const averageRating = available
                ? questionnaireAverages.reduce(function (sum, value) { return sum + value; }, 0) / completed
                : null;
            const weightedScore = available ? registered * averageRating : null;

            totalRegistered += registered;
            totalCompleted += completed;
            totalValidRatings += validRatingCount;
            if (registered > 0) {
                registeredClassCount += 1;
                if (available) {
                    scorableRegistered += registered;
                    scorableClassCount += 1;
                    totalWeightedScore += weightedScore;
                } else {
                    excludedRegistered += registered;
                    excludedClassCount += 1;
                }
            }

            byOffering.push({
                courseOfferingId: offeringId,
                professorUserId: userToken(offering.professorUserId || offering.professorId),
                subjectCode: String(offering.subjectCode || '').trim(),
                subjectName: String(offering.subjectName || '').trim(),
                sectionName: String(offering.sectionName || '').trim(),
                semesterId: String(offering.semesterSlug || offering.semesterId || '').trim(),
                registered,
                completed,
                respondentCount: completed,
                pending: Math.max(registered - completed, 0),
                completionRate: registered > 0 ? (completed / registered) * 100 : 0,
                validRatingCount,
                averageRating,
                averageRatingPercent: averageRating === null ? null : averageRating * 20,
                weightedScore,
                weightedScorePercent: weightedScore === null ? null : weightedScore * 20,
                available,
                exclusionReason: available
                    ? ''
                    : (registered > 0 ? 'no-valid-responses' : 'no-registered-students'),
            });
        });

        byOffering.sort(function (left, right) {
            return String(left.subjectCode).localeCompare(String(right.subjectCode))
                || String(left.sectionName).localeCompare(String(right.sectionName))
                || String(left.courseOfferingId).localeCompare(String(right.courseOfferingId));
        });

        // Annex C requires the entire registered population in the denominator.
        // Without an average for every enrolled class, the overall SET is unknown.
        const available = totalRegistered > 0 && excludedClassCount === 0;
        const partial = scorableRegistered > 0 && excludedClassCount > 0;
        return {
            byOffering,
            registered: totalRegistered,
            completed: totalCompleted,
            respondentCount: totalCompleted,
            pending: Math.max(totalRegistered - totalCompleted, 0),
            completionRate: totalRegistered > 0 ? (totalCompleted / totalRegistered) * 100 : 0,
            validRatingCount: totalValidRatings,
            totalWeightedScore: available ? totalWeightedScore : null,
            totalWeightedScorePercent: available ? totalWeightedScore * 20 : null,
            averageRating: available ? totalWeightedScore / totalRegistered : null,
            averageRatingPercent: available ? (totalWeightedScore / totalRegistered) * 20 : null,
            scorableRegistered,
            excludedRegistered,
            registeredClassCount,
            scorableClassCount,
            excludedClassCount,
            partial,
            available,
        };
    }

    return {
        calculateProfessorSetMetrics,
        questionnaireAverage,
        isEligibleEnrollmentStatus,
    };
});
