'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const SetCalculation = require('../JsScrip/set-calculation.js');
const { chromium } = require('../.codex/credibility-ui-test/node_modules/playwright');
const root = path.resolve(__dirname, '..');
const read = name => fs.readFileSync(path.join(root, name), 'utf8').replace(/\r\n/g, '\n');
const source = read('JsScrip/profesorpanel.js');
const shared = read('JsScrip/db-data.js');
const policyStart = shared.indexOf('    // Mirror api/feedback_classification_policy.json.');
const policyEnd = shared.indexOf('    async function analyzeBiasComments(', policyStart);

function extract(name) {
    const match = new RegExp(`^(?:async )?function ${name}\\(`, 'm').exec(source);
    assert(match, `Missing function ${name}`);
    const next = /\n(?:async )?function /.exec(source.slice(match.index + 1));
    assert(next, `Missing end of ${name}`);
    return source.slice(match.index, match.index + 1 + next.index);
}

const offering = (id, subjectCode, sectionName, extra = {}) => ({
    id, subjectCode, subjectName: `${subjectCode} subject`, sectionName, programCode: 'BSAT',
    professorUserId: 'u500', semesterSlug: 'term', isActive: true, ...extra,
});
const evaluation = (id, courseOfferingId, text, extra = {}) => ({
    id, databaseEvaluationId: id, courseOfferingId, comments: text,
    qualitative: { q2: `Qualitative ${text}` }, ratings: { q1: 4.5 },
    evaluatorRole: 'student', evaluatorUserId: 'u1', evaluateeUserId: 'u500',
    evaluatorName: 'PRIVATE STUDENT NAME', evaluatorId: 'PRIVATE ID',
    status: 'submitted', semesterId: 'term', submittedAt: '2026-09-20T08:00:00+08:00',
    ...extra,
});
const context = {
    professor: { id: 'u500' }, currentSemester: 'term',
    offerings: [
        offering(101, 'AVT101', '1/1'), offering(102, 'AVT101', '1/2'),
        offering(103, 'AVT101', '1/3'), offering(104, 'TOUR101', '1/1'),
        offering(105, 'OTHER', '1/1', { professorUserId: 'u501' }),
        offering(106, 'OLD', '2/1', { semesterSlug: 'old', isActive: false }),
        offering(107, '<img src=x onerror="window.injected=true">', '<svg onload="window.injected=true">'),
        offering(108, 'ZERO', '1/4', { programCode: '' }),
    ],
    evaluations: [
        evaluation(1, 101, 'Section A1'), evaluation(2, 102, 'Section A2'),
        evaluation(3, 104, 'Section B1'),
        evaluation(4, 105, 'Other professor', { evaluateeUserId: 'u501' }),
        evaluation(5, 106, 'Historical', { semesterId: 'old' }),
        evaluation(6, 107, '<img src=x onerror="window.injected=true">'),
    ],
    lookupMaps: { byId: {} }, offeringsById: {},
};
context.enrollments = context.offerings.filter(row => row.id !== 108).flatMap(row =>
    Array.from({length:row.id === 101 ? 40 : 1}, (_, index) => ({ courseOfferingId: row.id, studentUserId: `u${index + 1}`, status:'enrolled' }))
);
context.offeringsById = Object.fromEntries(context.offerings.map(row => [row.id, row]));
const calculationContext = {
    window: { SetCalculation }, professorPanelState: { currentSelection: { semesterId:'term' } },
    getAggregateEvaluationType: row => row.evaluatorRole,
    isEvaluationInSemester: (row, term) => row.semesterId === term,
    resolveEvaluationTargetProfessorId: row => row.evaluateeUserId,
    buildQuestionMeta: () => ({ categoryOrder:['Teaching'], byQuestionId:{ q1:{category:'Teaching'} } }),
    normalizeFacultyPaperLoadType: type => type || 'main', formatDisplaySection: section => section,
};
vm.createContext(calculationContext);
vm.runInContext(['normalizeToken', 'normalizeUserIdToken', 'clampNumber', 'getEvaluationTypeMeta',
    'collectEvaluationComments', 'computeAverageRatingFromEvaluations', 'fetchFacultySummaryFromSql'].map(extract).join('\n'), calculationContext);

async function main() {
    const summary = await calculationContext.fetchFacultySummaryFromSql({context, semesterId:'term', evaluationType:'student'});
    const supervisorContext = {...context,lookupMaps:{byId:{}},evaluations:[
        evaluation(20,null,'Supervisor feedback',{evaluatorRole:'supervisor',credibilityStatus:'AUTO_ACCEPTED',ratings:{q1:4.6}}),
        evaluation(21,null,'Other professor',{evaluatorRole:'supervisor',credibilityStatus:'AUTO_ACCEPTED',evaluateeUserId:'u501',ratings:{q1:1}}),
        evaluation(22,null,'Historical supervisor',{evaluatorRole:'supervisor',credibilityStatus:'AUTO_ACCEPTED',semesterId:'old',ratings:{q1:2}}),
        evaluation(23,null,'Draft supervisor',{evaluatorRole:'supervisor',credibilityStatus:'AUTO_ACCEPTED',status:'draft',ratings:{q1:1}}),
    ]};
    const sefSummary = await calculationContext.fetchFacultySummaryFromSql({context:supervisorContext,semesterId:'term',evaluationType:'supervisor'});
    assert.equal(sefSummary.sefRating,92,'SEF must use only valid submitted supervisor ratings for the selected professor and semester.');
    const pendingContext = {...supervisorContext,evaluations:[evaluation(24,null,'Pending supervisor',{
        evaluatorRole:'supervisor',credibilityStatus:'PENDING_HR_REVIEW',ratings:{q1:4,q2:2,q3:2}})]};
    const pendingSummary = await calculationContext.fetchFacultySummaryFromSql({context:pendingContext,semesterId:'term',evaluationType:'supervisor'});
    assert.equal(pendingSummary.totals.averageScore.toFixed(2),'2.67');
    assert.equal(pendingSummary.sefRating,null);
    assert.equal(pendingSummary.sefUnavailableReason,'Awaiting HR review');
    assert.equal(summary.setRows.length, 6);
    assert.deepEqual(Array.from(summary.setRows, row => row.seq), [1,2,3,4,5,6]);
    assert.equal(summary.setRows.filter(row => row.courseCode === 'AVT101').length, 3);
    const first = summary.setRows.find(row => row.courseOfferingId === '101');
    assert.equal(first.yearSection, 'BSAT1/1');
    assert.equal(first.students, 40);
    assert.equal(first.averageSetRating, 90);
    assert.equal(first.weightedSetScore, 3600);
    assert.equal(summary.setRows.find(row => row.courseOfferingId === '103').averageSetRating, null);
    const zero = summary.setRows.find(row => row.courseOfferingId === '108');
    assert.equal(zero.students, 0); assert.equal(zero.weightedSetScore, null); assert.equal(zero.yearSection, '1/4');
    assert.equal(summary.setTotals.students, 44); assert.equal(summary.setTotals.weightedScore, null);
    assert.equal(summary.setTotals.excludedClassCount, 1);
    const a = summary.breakdownRows.find(row => row.subject === 'AVT101 - AVT101 subject');
    assert.equal(a.required, 42); assert.equal(a.received, 2); assert.equal(a.avgRating, null); assert.equal(a.partial, true);
    assert(!summary.setRows.some(row => ['105','106'].includes(row.courseOfferingId)));
    assert.equal(summary.comments.length, 8);
    assert(!summary.comments.some(row => /Other professor|Historical/.test(row.text)));
    const historical = await calculationContext.fetchFacultySummaryFromSql({context, semesterId:'old', evaluationType:'student'});
    assert.equal(historical.setRows.length, 1); assert.equal(historical.setRows[0].yearSection, 'BSAT2/1');
    assert.equal(historical.setTotals.weightedScore, 90);
    const completeContext = {...context, evaluations:[...context.evaluations, evaluation(7,103,'Section A3')]};
    const complete = await calculationContext.fetchFacultySummaryFromSql({context:completeContext, semesterId:'term', evaluationType:'student'});
    assert.equal(complete.setTotals.weightedScore, 3960);
    // Fractional averages are multiplied and summed before display rounding.
    const fractionalContext = {...context, offerings:[context.offerings[0]], evaluations:[evaluation(8,101,'Fractional',{ratings:{q1:4.3333333333}})]};
    const fractional = await calculationContext.fetchFacultySummaryFromSql({context:fractionalContext, semesterId:'term', evaluationType:'student'});
    assert(Math.abs(fractional.setTotals.weightedScore - 3466.66666664) < 1e-6);

    const html = read('html/profesorpanel.html');
    const start = html.indexOf('<section class="detail-report-section">', html.indexOf('id="reportsContent"'));
    const section = html.slice(start, html.indexOf('</section>',start) + 10);
    const browser = await chromium.launch({channel:'chrome', headless:true});
    try {
        const page = await browser.newPage({viewport:{width:1280,height:1000}});
        const errors = []; page.on('pageerror', error => errors.push(error.message));
        await page.route('**/*', route => route.abort());
        await page.setContent('<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="professor-panel"><main style="max-width:1120px;margin:24px auto;padding:0 16px">' + section + '</main></body></html>');
        for (const css of ['css/panel-theme.css','css/profesorpanel.css','css/feedback-intelligence.css']) await page.addStyleTag({content:read(css)});
        await page.addScriptTag({content:shared.slice(policyStart, policyEnd)+`
            let professorFeedbackRequestId = 0;
            let professorFeedbackFiltersLoading = false;
            const PROFESSOR_PANEL_EMPTY_SUMMARY = {comments:[],commentBuckets:{},breakdownRows:[],setRows:[]};
            const professorPanelState = {
                currentSelection:{evaluationType:'student',semesterId:'term',semesterLabel:'Fixture Semester'},
                feedbackPanel:{items:[],evaluationType:'student'},
                summaryByType:{student:${JSON.stringify(summary)},professor:{comments:[{text:'Peer comment'}]},supervisor:{comments:[{text:'Supervisor comment'}]}}
            };
            window.completeSummary = ${JSON.stringify(complete)};
            window.supervisorExampleSef = ${SetCalculation.calculateSupervisorSefRating([{status:'submitted',credibilityStatus:'AUTO_ACCEPTED',ratings:[4,2,2]}])};
            window.aiRequests = []; window.aiMode = 'deferred';
            const SharedData = {
                prepareFeedbackSummaryComments, buildFeedbackTeachingImprovements,
                renderFeedbackIntelligence,
                classifyFeedbackCommentByRules, detectFeedbackCommentTopics, getFeedbackClassificationTone,
                summarizeFeedbackComments:async payload => {
                    window.aiRequests.push(payload);
                    if (window.aiMode === 'reject') throw new Error('Fixture API failure');
                    if (window.aiMode === 'disabled') return {disabled:true};
                    return new Promise(resolve => {window.resolveAi = resolve;});
                }
            };
        ` + ['escapeHTML','normalizeToken','getEvaluationTypeMeta','renderBreakdownTable','formatProfessorSetValue',
            'setupProfessorSubjectComments','setProfessorCommentsAiSummary','getProfessorFeedbackAiSummarySourceNote','renderProfessorOverallRatings',
            'classifyFeedbackCommentBiasByRules','setProfessorCommentsListVisibility','detectStudentCommentTopics',
            'buildStudentFeedbackAiSummary','resetProfessorSubjectCommentsPanel','fetchSectionSummaryFromSql'].map(extract).join('\n') + `
            renderBreakdownTable(professorPanelState.summaryByType.student.setRows,'student',professorPanelState.summaryByType.student.setTotals);
            renderProfessorOverallRatings();
            setupProfessorSubjectComments(); resetProfessorSubjectCommentsPanel();
            document.getElementById('semesterFilter').innerHTML = '<option value="term">Fixture Semester</option>';
            document.getElementById('selectedSemester').textContent = 'Fixture Semester';
        `});
        const table = page.locator('#facultyBreakdownTable');
        const list = page.locator('#profSubjectCommentsList');
        const ai = page.locator('#profSubjectCommentsAiSummarize');
        const view = page.locator('#profSubjectCommentsView');
        const close = page.locator('#profSubjectCommentsClose');
        const aiSummary = page.locator('#profSubjectCommentsAiSummary');
        const note = page.locator('#profSetCalculationNote');
        const setOverall = page.locator('#profOverallSetRating');
        const sefOverall = page.locator('#profOverallSefRating');
        assert.equal(await setOverall.textContent(),'N/A');assert.equal(await sefOverall.textContent(),'N/A');
        await page.evaluate(()=>renderProfessorOverallRatings({student:{setTotals:{students:100,weightedScore:9004,excludedClassCount:0}},supervisor:{sefRating:92}}));
        assert.equal(await setOverall.textContent(),'90.04');assert.equal(await sefOverall.textContent(),'92.00');
        // Same totals as the reported issue; use the unrounded supervisor mean.
        await page.evaluate(()=>renderProfessorOverallRatings({student:{setTotals:{students:81,weightedScore:5354.11,excludedClassCount:0}},supervisor:{sefRating:window.supervisorExampleSef}}));
        assert.equal(await setOverall.textContent(),'66.10');assert.equal(await sefOverall.textContent(),'53.33');
        await page.evaluate(()=>renderProfessorOverallRatings({student:{setTotals:{students:3,weightedScore:270.125,excludedClassCount:0}},supervisor:{sefRating:null}}));
        assert.equal(await setOverall.textContent(),'90.04');assert.equal(await sefOverall.textContent(),'N/A');
        await page.evaluate(()=>renderProfessorOverallRatings({student:{setTotals:{students:100,weightedScore:9004,excludedClassCount:1}},supervisor:{sefRating:92}}));
        assert.equal(await setOverall.textContent(),'N/A');assert.equal(await sefOverall.textContent(),'92.00');
        await page.evaluate(()=>renderProfessorOverallRatings({}));
        assert.equal(await setOverall.textContent(),'N/A');assert.equal(await sefOverall.textContent(),'N/A');
        await page.evaluate(()=>renderProfessorOverallRatings({student:{setTotals:{students:100,weightedScore:9004,excludedClassCount:0}},supervisor:{sefRating:92}}));
        const waitForText = text => page.waitForFunction(text => document.getElementById('profSubjectCommentsList').textContent.includes(text),text);
        assert.equal(await table.locator('thead th').count(),6);
        assert.equal(await table.locator('tbody tr').count(),6);
        assert.equal(await table.locator('tbody tr').filter({hasText:'BSAT1/1'}).filter({hasText:'AVT101'}).locator('td').nth(5).textContent(),'3,600.00');
        assert.equal(await table.locator('tfoot td').first().textContent(),'44');
        assert.equal(await table.locator('tfoot td').last().textContent(),'N/A'); assert(await note.isVisible());
        assert.equal(await table.locator('img,svg,button').count(),0);
        assert.equal(await page.evaluate(() => window.injected),undefined);
        await page.evaluate(() => renderBreakdownTable(window.completeSummary.setRows,'student',window.completeSummary.setTotals));
        assert.equal(await table.locator('tfoot td').last().textContent(),'3,960.00'); assert(await note.isHidden());
        await page.evaluate(() => renderBreakdownTable([], 'student', {students:0,weightedScore:null}));
        assert.equal(await table.locator('tbody td').getAttribute('colspan'),'6'); assert.equal(await table.locator('tfoot tr').count(),0);
        await page.evaluate(() => renderBreakdownTable(professorPanelState.summaryByType.student.setRows,'student',professorPanelState.summaryByType.student.setTotals));
        assert(!(await ai.isDisabled())); assert(!(await view.isDisabled()));
        await view.focus(); await page.keyboard.press('Enter'); await waitForText('Section B1');
        assert((await list.textContent()).includes('Section A1')); assert((await list.textContent()).includes('Section A2'));
        assert(!(await list.textContent()).includes('PRIVATE')); assert.equal(await list.locator('img,svg').count(),0);
        assert.equal(await page.locator('#profSubjectCommentsTitle').textContent(),'Anonymous Feedback');
        assert((await page.locator('#profSubjectCommentsMeta').textContent()).includes('Fixture Semester'));
        await ai.click();
        assert.equal(await page.evaluate(() => window.aiRequests[0].comments.length),8);
        assert.equal(await page.evaluate(() => window.aiRequests[0].semesterId),'term');
        assert.equal(await page.evaluate(() => window.aiRequests[0].includeTeachingImprovements),true);
        assert((await page.evaluate(() => window.aiRequests[0].comments.map(row => row.text))).includes('Section B1'));
        await page.evaluate(() => window.resolveAi({success:true,summary:{summaryLine:'General semester summary',total:8,source:'openai'}}));
        await page.waitForFunction(() => document.getElementById('profSubjectCommentsAiSummary').textContent.includes('General semester summary'));
        assert(await list.isHidden()); await view.click(); await waitForText('Section B1'); assert(await list.isVisible());
        // View invalidates an AI request while it is still running.
        await ai.click(); await view.click(); await waitForText('Section B1');
        await page.evaluate(() => window.resolveAi({success:true,summary:{summaryLine:'STALE VIEW RESULT'}}));
        assert(!(await aiSummary.textContent()).includes('STALE')); assert(await list.isVisible());
        for (const mode of ['disabled','reject']) {
            await page.evaluate(mode => {window.aiMode = mode;},mode); await ai.click();
            await page.waitForFunction(() => document.getElementById('profSubjectCommentsAiSummary').textContent.includes('Summary of 8 student comments'));
            assert(await list.isHidden()); await view.click(); await waitForText('Section B1');
        }
        await page.evaluate(() => {window.aiMode = 'deferred';}); await ai.click(); await close.click();
        await page.evaluate(() => window.resolveAi({success:true,summary:{summaryLine:'STALE CLOSE RESULT'}}));
        assert(!(await aiSummary.textContent()).includes('STALE')); assert(await list.isVisible());
        assert.equal(await list.textContent(),'Press View to load feedback.');
        // Late comment responses cannot reopen feedback after Close.
        await page.evaluate(() => {
            window.originalFetch = fetchSectionSummaryFromSql;
            fetchSectionSummaryFromSql = () => new Promise(resolve => {window.resolveComments = resolve;});
        });
        await view.click(); await close.click();
        await page.evaluate(() => {window.resolveComments([{text:'STALE COMMENTS'}]);fetchSectionSummaryFromSql = window.originalFetch;});
        assert(!(await list.textContent()).includes('STALE'));
        // Filter loading clears state and blocks access to the old summary.
        await ai.click();
        await page.evaluate(() => {
            professorPanelState.currentSelection.semesterId = 'other'; resetProfessorSubjectCommentsPanel(true);
            window.resolveAi({success:true,summary:{summaryLine:'STALE SEMESTER'}});
        });
        assert(await ai.isDisabled()); assert(await view.isDisabled()); assert(!(await aiSummary.textContent()).includes('STALE'));
        await close.click(); assert(await view.isDisabled());
        for (const type of ['professor','supervisor']) {
            await page.evaluate(type => {
                professorPanelState.currentSelection.evaluationType = type; resetProfessorSubjectCommentsPanel();
                renderBreakdownTable([{employeeId:'EMP001',avgRating:4.5}],type);
            },type);
            assert.equal(await table.locator('thead th').count(),2); assert.equal(await table.locator('tfoot tr').count(),0);
            assert(await note.isHidden()); await view.click(); await waitForText(type === 'professor' ? 'Peer comment' : 'Supervisor comment');
        }
        await page.evaluate(() => {
            professorPanelState.currentSelection = {evaluationType:'student',semesterId:'term',semesterLabel:'Fixture Semester'};
            window.savedComments = professorPanelState.summaryByType.student.comments;
            professorPanelState.summaryByType.student.comments = []; resetProfessorSubjectCommentsPanel();
        });
        await view.click(); await waitForText('No anonymized feedback available.');
        const requestCount = await page.evaluate(() => window.aiRequests.length); await ai.click();
        assert.equal(await page.evaluate(() => window.aiRequests.length),requestCount);
        assert((await aiSummary.textContent()).includes('No student comments'));
        await page.evaluate(() => {
            professorPanelState.summaryByType.student.comments = window.savedComments;
            renderBreakdownTable(professorPanelState.summaryByType.student.setRows,'student',professorPanelState.summaryByType.student.setTotals);
            resetProfessorSubjectCommentsPanel();
        });
        await view.click(); await waitForText('Section B1');
        const output = path.join(root,'output','professor-set-table'); fs.mkdirSync(output,{recursive:true});
        await page.screenshot({path:path.join(output,'desktop.png'),fullPage:true});
        for (const viewport of [{width:390,height:844},{width:844,height:390}]) {
            await page.setViewportSize(viewport);
            assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),'Page overflows horizontally');
            assert(await page.evaluate(() => {
                const footer = document.querySelector('#facultyBreakdownTable tfoot').getBoundingClientRect();
                const feedback = document.getElementById('profSubjectCommentsPanel').getBoundingClientRect();
                return feedback.top >= footer.bottom;
            }),'SET table overlaps feedback');
            assert(await table.locator('tfoot td').last().isVisible()); assert(await list.isVisible());
            await page.screenshot({path:path.join(output,viewport.width === 390 ? 'mobile.png' : 'landscape.png'),fullPage:true});
        }
        await page.evaluate(()=>{
            professorPanelState.summaryByType.student.comments = [
                {text:'Please provide more worked examples.'},
                {text:'Thank you for the helpful learning materials.'},
                {text:'Bobo ka sir.'},
                {text:'Repeated neutral entry.'},{text:'Repeated neutral entry.'},
            ];
            window.aiMode = 'reject'; resetProfessorSubjectCommentsPanel();
        });
        await ai.click();
        await page.waitForFunction(()=>document.querySelector('.feedback-intelligence-source')?.textContent === 'Rule-based');
        assert.deepEqual(await aiSummary.locator('.feedback-intelligence-metric strong').allTextContents(),['3','1','0','1']);
        assert((await aiSummary.textContent()).includes('3 of 5 available'));
        assert.equal(await aiSummary.locator('.feedback-intelligence-suggestions li').count(),1);
        assert(!(await aiSummary.locator('.feedback-intelligence-suggestions').textContent()).includes('materials'));
        await view.click(); await waitForText('Repeated neutral entry.'); assert.equal(await list.locator('li').count(),5);
        await page.evaluate(()=>{
            professorPanelState.summaryByType.student.comments = [{text:'Please provide more worked examples.'}];
            window.aiMode = 'deferred'; resetProfessorSubjectCommentsPanel();
        });
        await ai.click();
        await page.evaluate(()=>window.resolveAi({success:true,summary:{total:1,constructive:1,positive:0,neutral:0,critical:0,biased:0,
            source:'openai',summaryLine:'Summary of 1 student comments: asks for examples.',topics:[{label:'examples',count:1}]}}));
        await page.waitForFunction(()=>document.querySelector('.feedback-intelligence-source')?.textContent === 'AI assisted');
        assert.equal(await aiSummary.locator('.feedback-intelligence-suggestions li').count(),1);
        await page.evaluate(()=>{professorPanelState.summaryByType.student.comments = window.savedComments;});
        await page.evaluate(() => {
            window.intelligenceFixture = {
                total:40,constructive:9,positive:20,neutral:4,critical:7,biased:0,source:'openai',
                summaryLine:'Based on 40 student comments, recurring feedback highlights opportunities for clearer explanations, additional practical examples, and more interactive classroom discussions.',
                toneLine:'Overall feedback includes appreciation alongside specific teaching suggestions.',
                topics:[{label:'Interactive discussions',count:4},{label:'Clearer explanations',count:11},
                    {label:'More practical examples',count:5},{label:'Learning materials',count:1}],
                teachingImprovements:['Explain complex topics using step-by-step explanations.',
                    'Provide additional real-world examples connected to the lesson.',
                    'Encourage participation through interactive class discussions.'],
            };
            setProfessorCommentsAiSummary(window.intelligenceFixture,'success');
            setProfessorCommentsListVisibility(false);
        });
        const metrics = aiSummary.locator('.feedback-intelligence-metric');
        assert.deepEqual(await metrics.locator('strong').allTextContents(),['40','9','3','0']);
        assert.equal(await aiSummary.locator('progress').count(),3);
        assert.deepEqual(await aiSummary.locator('progress').evaluateAll(rows=>rows.map(row=>[row.value,row.max])),[[11,40],[5,40],[4,40]]);
        assert((await aiSummary.textContent()).includes('27.5%'));
        assert((await aiSummary.textContent()).includes('12.5%'));
        assert((await aiSummary.textContent()).includes('10%'));
        assert.equal(await aiSummary.locator('.feedback-intelligence-source').textContent(),'AI generated');
        assert.equal(await aiSummary.locator('.feedback-intelligence-suggestions li').count(),3);
        const intelligenceOutput = path.join(root,'output','professor-feedback-intelligence'); fs.mkdirSync(intelligenceOutput,{recursive:true});
        for (const viewport of [{width:1280,height:1000},{width:390,height:844},{width:844,height:390}]) {
            await page.setViewportSize(viewport);
            assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'Intelligence dashboard overflows');
            assert.equal(await metrics.first().evaluate(el=>getComputedStyle(el.parentElement).gridTemplateColumns.split(' ').length),viewport.width===390?1:2);
            assert(await aiSummary.evaluate(el=>el.scrollHeight<=el.clientHeight+1),'Intelligence dashboard clips content');
            await page.screenshot({path:path.join(intelligenceOutput,viewport.width===1280?'desktop.png':viewport.width===390?'mobile.png':'landscape.png'),fullPage:true});
        }
        await page.evaluate(()=>setProfessorCommentsAiSummary({...window.intelligenceFixture,total:5,constructive:1,biased:1,
            summaryLine:'<img src=x onerror="window.injected=true">',
            topics:[{label:'<svg onload="window.injected=true">',count:2}],
            teachingImprovements:['<img src=x onerror="window.injected=true">']},'success'));
        assert.equal(await aiSummary.locator('img,svg').count(),0);
        assert.equal(await page.evaluate(()=>window.injected),undefined);
        assert((await aiSummary.textContent()).includes('5 of 8 available'));
        await page.evaluate(()=>setProfessorCommentsAiSummary({...window.intelligenceFixture,total:1,constructive:0,critical:0,positive:1,
            topics:[{label:'Explanations',count:1}],teachingImprovements:[]},'success'));
        assert.equal(await aiSummary.locator('progress').count(),0);
        assert((await aiSummary.textContent()).includes('No recurring themes found'));
        assert((await aiSummary.textContent()).includes('No specific teaching improvement'));
        await close.click(); assert.equal(await aiSummary.locator('.feedback-intelligence-header').count(),0);
        assert.deepEqual(errors,[]);
        console.log('Professor SET table and general comments: calculations, totals, missing ratings, historical scope, AI scope/fallbacks, resets, anonymity, keyboard, desktop/mobile/landscape passed.');
    } finally { await browser.close(); }
}
main().catch(error => {console.error(error);process.exitCode = 1;});
