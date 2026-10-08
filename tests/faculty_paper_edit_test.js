'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const read = file => fs.readFileSync(path.join(__dirname, '..', file), 'utf8');
function between(source, start, end) {
    const offset = source.indexOf(start);
    const endOffset = source.indexOf(end, offset);
    assert(offset >= 0 && endOffset > offset, `Missing marker: ${start}`);
    return source.slice(offset, endOffset);
}
function elements() {
    const nodes = {};
    return { nodes, document: { getElementById(id) {
        return nodes[id] ||= { style: {}, value: '', checked: false, disabled: false, textContent: '', innerHTML: '', querySelectorAll: () => [], addEventListener(event, callback) { this[event] = callback; } };
    } } };
}

async function main() {
    const professorUi = elements();
    const saved = [];
    const previews = [];
    const alerts = [];
    let storedPreviews = 0;
    const professor = {
        document: professorUi.document,
        professorPanelState: { facultyPaper: { aiAuditReceipts: {} } },
        normalizeToken: value => String(value || '').toLowerCase(),
        normalizePaperTimestamp: value => value || '',
        resolvePaperStatusLabel: value => value,
        getFacultyPaperLoadTypeLabel: () => 'Main Load',
        normalizeFacultyPaperLoadType: () => 'main',
        setSelectedFacultyPaperLoadType: () => {},
        resolveFacultyPaperApprovalFlag: (paper, key) => !!paper[key],
        buildFacultyPaperData: () => ({ set_rating: '90.00' }),
        setFacultyPaperAiFeedback: () => {},
        getProfessorPaperActor: () => ({ role: 'professor', actorUserId: 'u10' }),
        SharedData: { saveFacultyPaperSectionC: payload => { saved.push(payload); return { success: true }; } },
        renderProfessorFacultyPaperList: async () => {},
        openFacultyAcknowledgementPdf: async payload => previews.push(payload),
        openProfessorStoredPaperPdf: async () => { storedPreviews += 1; },
        alert: value => alerts.push(value),
    };
    vm.createContext(professor);
    const professorSource = read('JsScrip/profesorpanel.js');
    vm.runInContext(between(professorSource, 'function renderProfessorFacultyPaperDetail(', '\nasync function renderProfessorFacultyPaperList('), professor);
    for (const status of ['draft', 'sent', 'completed']) {
        professor.renderProfessorFacultyPaperDetail({ id: 'P1', status, canCurrentActorEdit: true, latest_file_path: 'old.pdf', section_c_areas: 'Existing' });
        if (status !== 'draft') {
            assert.equal(professorUi.nodes.fpDetailSaveSectionCBtn.disabled, true);
            assert.equal(professorUi.nodes.fpSectionCAreasInput.disabled, true);
            assert.equal(professorUi.nodes.fpSectionCActivitiesInput.disabled, true);
            assert.equal(professorUi.nodes.fpSectionCActionPlanInput.disabled, true);
            assert.equal(professorUi.nodes.fpDetailAiRecommendBtn.disabled, true);
            assert.equal(professorUi.nodes.fpApprovalNamesAutoFillInput.disabled, true);
            assert.equal(professorUi.nodes.fpApprovalDatesAutoFillInput.disabled, true);
            assert.equal(professorUi.nodes.fpDetailSendBtn.disabled, true);
            await professorUi.nodes.fpDetailPreviewBtn.onclick();
            continue;
        }
        assert.equal(professorUi.nodes.fpDetailSaveSectionCBtn.disabled, false);
        assert.equal(professorUi.nodes.fpSectionCAreasInput.disabled, false);
        assert.equal(professorUi.nodes.fpDetailSendBtn.disabled, status !== 'draft');
        professorUi.nodes.fpSectionCAreasInput.value = 'Written by professor';
        await professorUi.nodes.fpDetailSaveSectionCBtn.onclick();
        assert.equal(saved.at(-1).section_c.areas, 'Written by professor');
        assert.equal(saved.at(-1).aiGenerationAuditId, '');
        await professorUi.nodes.fpDetailPreviewBtn.onclick();
        assert.equal(previews.at(-1).section_c_areas, 'Written by professor');
    }
    assert.equal(saved.length, 1, 'Manual draft saves must reach the API without AI generation.');
    assert.equal(storedPreviews, 2, 'Submitted papers should preview the saved PDF.');
    assert(alerts.every(message => message === 'Section C saved successfully.'));
    professor.renderProfessorFacultyPaperDetail({
        id: 'EXCESS', status: 'draft', load_type: 'excess', set_rating: '80.00',
        set_rating_note: '1 eligible evaluations recorded; 1 of 1 enrolled classes rated.',
    });
    assert.equal(professorUi.nodes.fpDetailSetRating.textContent, '80.00', 'Browser calculations must not replace the server excess-load rating.');
    assert.equal(professorUi.nodes.fpDetailSetRatingNote.hidden, false);
    assert.match(professorUi.nodes.fpDetailSetRatingNote.textContent, /1 eligible evaluations recorded/);
    await professorUi.nodes.fpDetailPreviewBtn.onclick();
    assert.equal(previews.at(-1).set_rating, '80.00', 'Preview must use the same authoritative rating as the detail screen.');
    professor.renderProfessorFacultyPaperDetail({ id: 'EXCESS', status: 'draft', set_rating: 'N/A', set_rating_note: '1 of 2 enrolled classes rated.' });
    assert.equal(professorUi.nodes.fpDetailSetRating.textContent, 'N/A', 'An incomplete load must retain the server N/A result.');
    professor.renderProfessorFacultyPaperDetail({ id: 'EXCESS', status: 'sent', set_rating: '75.00' });
    assert.equal(professorUi.nodes.fpDetailSetRating.textContent, '75.00');
    assert.equal(professorUi.nodes.fpDetailSetRatingNote.hidden, true, 'Draft calculation notes must clear for submitted papers.');
    professor.renderProfessorFacultyPaperDetail({ id: 'P1', status: 'archived' });
    assert.equal(professorUi.nodes.fpDetailSaveSectionCBtn.disabled, true);
    professor.renderProfessorFacultyPaperDetail({ id: 'P1', status: 'sent', canCurrentActorEdit: false });
    assert.equal(professorUi.nodes.fpDetailSaveSectionCBtn.disabled, true);

    const supervisorSource = read('JsScrip/daenpanel.js');
    for (const role of ['dean', 'procoor']) {
        const ui = elements();
        const papers = [
            { id: 'SENT', status: 'sent', canCurrentActorEdit: true },
            { id: 'NEXT', status: 'sent', canCurrentActorEdit: true },
            { id: 'COMPLETED', status: 'completed', canCurrentActorEdit: true },
            { id: 'ARCHIVED', status: 'archived' },
        ];
        const notifications = [];
        const submissions = [];
        let saveFails = false;
        let reportRefreshes = 0;
        const supervisor = {
            document: ui.document, SUPERVISOR_ROLE: role,
            deanFacultyPaperState: { papers: [], selectedId: '' },
            normalizeDeanToken: value => String(value || '').toLowerCase(),
            formatDeanPaperTimestamp: value => value || '',
            mapDeanPaperStatus: value => value,
            resolveDeanFacultyPaperSupervisorNameFlag: () => false,
            resolveDeanFacultyPaperSupervisorDateFlag: () => false,
            resolveCurrentDeanActorUserId: () => 'u20',
            listDeanFacultyPapersForSupervisor: () => papers,
            escapeHTML: value => value,
            SharedData: { saveFacultyPaperSectionC(payload) {
                submissions.push(payload);
                if (saveFails) return { success: false, error: 'Save failed.' };
                const paper = papers.find(item => item.id === payload.paper_id);
                paper.status = 'completed';
                return { success: true, paper };
            } },
            refreshDeanIferDirectory: () => { reportRefreshes += 1; },
            alert: message => notifications.push(message),
        };
        vm.createContext(supervisor);
        vm.runInContext(between(supervisorSource, 'function renderDeanFacultyPaperDetail(', '\n/**\n * Setup table actions'), supervisor);
        supervisor.renderDeanFacultyPaperInbox();
        assert(ui.nodes.deanFacultyPaperTableBody.innerHTML.includes('SENT'));
        assert(!ui.nodes.deanFacultyPaperTableBody.innerHTML.includes('COMPLETED'), 'Completed papers must leave the inbox.');
        assert(!ui.nodes.deanFacultyPaperTableBody.innerHTML.includes('ARCHIVED'));
        for (const status of ['sent', 'completed']) {
            supervisor.renderDeanFacultyPaperDetail({ id: 'P1', status, canCurrentActorEdit: true });
            assert.equal(ui.nodes.deanSectionCAreas.disabled, false, `${role} cannot edit ${status}.`);
            assert.equal(ui.nodes.deanFacultyPaperSaveBtn.disabled, false);
            supervisor.renderDeanFacultyPaperDetail({ id: 'P1', status, canCurrentActorEdit: false });
            assert.equal(ui.nodes.deanFacultyPaperSaveBtn.disabled, true, 'UI must honor API permission.');
        }

        supervisor.renderDeanFacultyPaperInbox();
        supervisor.setupDeanFacultyPaperInbox();
        ui.nodes.deanSectionCAreas.value = 'Supervisor recommendation';
        saveFails = true;
        ui.nodes.deanFacultyPaperSectionCForm.submit({ preventDefault() {} });
        assert.equal(papers[0].status, 'sent', 'A failed save must keep the paper pending.');
        assert.equal(supervisor.deanFacultyPaperState.selectedId, 'SENT');
        assert.equal(reportRefreshes, 0);
        assert.equal(notifications.at(-1), 'Save failed.');

        saveFails = false;
        ui.nodes.deanFacultyPaperSectionCForm.submit({ preventDefault() {} });
        assert.equal(submissions.at(-1).actor_role, role);
        assert.equal(submissions.at(-1).section_c.areas, 'Supervisor recommendation');
        assert.equal(papers[0].status, 'completed');
        assert(!ui.nodes.deanFacultyPaperTableBody.innerHTML.includes('data-paper-id="SENT"'), 'Saved paper remains in the inbox.');
        assert.equal(supervisor.deanFacultyPaperState.selectedId, 'NEXT', 'Select the next pending paper after saving.');
        assert.equal(reportRefreshes, 1, 'Completed reports must refresh after saving.');
        assert.match(notifications.at(-1), /Paper completed successfully/);

        ui.nodes.deanFacultyPaperSectionCForm.submit({ preventDefault() {} });
        supervisor.renderDeanFacultyPaperInbox();
        assert.equal(supervisor.deanFacultyPaperState.selectedId, '');
        assert.equal(ui.nodes.deanFacultyPaperDetailCard.style.display, 'none', 'Hide the detail when no papers remain.');
        assert.match(ui.nodes.deanFacultyPaperTableBody.innerHTML, /No faculty papers assigned/);
        assert.equal(supervisor.deanFacultyPaperState.papers.filter(paper => paper.status === 'completed').length, 3, 'Completed papers must remain available to reports.');
    }
    console.log('Faculty paper edit UI tests passed.');
}
main().catch(error => { console.error(error); process.exitCode = 1; });
