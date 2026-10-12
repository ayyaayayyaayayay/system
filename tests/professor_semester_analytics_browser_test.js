'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const { chromium } = require('../.codex/credibility-ui-test/node_modules/playwright');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root,file),'utf8').replace(/\r\n/g,'\n');
const source = read('JsScrip/profesorpanel.js');
function extract(name) {
    const match = new RegExp(`^(?:async )?function ${name}\\(`,'m').exec(source);
    assert(match, `Missing ${name}`);
    const next = /\n(?:async )?function /.exec(source.slice(match.index + 1));
    assert(next, `Missing end of ${name}`);
    return source.slice(match.index,match.index + 1 + next.index);
}
const empty = {criteriaAverages:[],breakdownRows:[],setRows:[],setTotals:{students:0,weightedScore:null,excludedClassCount:0},
    ratingDistribution:{1:0,2:0,3:0,4:0,5:0},comments:[],commentBuckets:{},detailedRows:[],
    totals:{required:0,received:0,responseRate:0,averageScore:null}};
function fixture(term,type) {
    if (term === 'empty') return structuredClone(empty);
    const isA = term === 'a';
    const score = type === 'student' ? (isA ? 4.5 : 4) : type === 'professor' ? (isA ? 3 : 4.25) : (isA ? 4 : 3.75);
    return {...structuredClone(empty), criteriaAverages:[{name:'Teaching effectiveness',average:score}],
        setRows:type === 'student' ? [{seq:1,courseCode:isA ? 'AVT101' : 'TOUR102',yearSection:'BSAT1/1',students:isA ? 40 : 30,averageSetRating:score * 20,weightedSetScore:(isA ? 40 : 30) * score * 20}] : [],
        setTotals:{students:isA ? 40 : 30,weightedScore:isA ? 3600 : 2400,excludedClassCount:0},
        sefRating:type === 'supervisor' && term !== 'review' ? score * 20 : null,
        sefUnavailableReason:type === 'supervisor' && term === 'review' ? 'Awaiting HR review' : '',
        breakdownRows:[{required:40,received:2}],comments:[{text:`${term} ${type} feedback`}],
        ratingDistribution:{1:0,2:0,3:0,4:2,5:1},
        totals:{required:type === 'student' ? (isA ? 40 : 30) : 2,received:type === 'supervisor' ? 1 : 2,responseRate:50,averageScore:score}};
}
async function main() {
    const html = read('html/profesorpanel.html');
    const start = html.indexOf('<div class="reports-content"');
    const end = html.indexOf('<!-- Faculty Paper View -->',start);
    assert(start >= 0 && end > start);
    const fixtures = Object.fromEntries(['a','b','empty','review'].map(term => [term,Object.fromEntries(['student','professor','supervisor'].map(type => [type,fixture(term,type)]))]));
    const browser = await chromium.launch({channel:'chrome',headless:true});
    try {
        const page = await browser.newPage({viewport:{width:1280,height:1000}});
        const errors = []; page.on('pageerror',error => errors.push(error.message));
        await page.route('**/*',route => route.abort());
        await page.setContent('<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="professor-panel"><main style="max-width:1120px;margin:24px auto;padding:0 16px">' + html.slice(start,end) + '</main></body></html>');
        for (const css of ['css/panel-theme.css','css/profesorpanel.css']) await page.addStyleTag({content:read(css)});
        await page.addScriptTag({content:`
            const PROFESSOR_PANEL_EMPTY_SUMMARY = ${JSON.stringify(empty)};
            const professorPanelState = {context:{professor:{id:'u1'},currentSemester:'a'},
                currentSelection:{semesterId:'a',semesterLabel:'Semester A',evaluationType:'student'},
                summaryByType:${JSON.stringify(fixtures.a)},semesterSnapshots:{},feedbackPanel:{items:[],evaluationType:'student'}};
            const fixtures = ${JSON.stringify(fixtures)};
            let professorReportSelectionRequestId = 0;
            let professorTrendSnapshotRequestId = 0;
            let professorFeedbackRequestId = 0;
            let professorFeedbackFiltersLoading = false;
            let professorReportAccessVerified = true;
            let professorReportAccessRefreshPromise = null;
            window.control = {holdSnapshots:'',holdSummaries:'',failSnapshots:false,accessEnabled:true};
            window.loads = []; window.rebuilds = 0; window.chartValues = {};
            const SharedData = {
                refreshFacultyReportAccess:async () => {
                    if (window.control.holdAccess) await new Promise(resolve => {window.releaseAccess = resolve;});
                    return getProfessorFacultyReportAccessState();
                },
                fetchSubjectManagementSnapshot:async ({semesterId}) => {
                    if (window.control.failSnapshots) throw new Error('Fixture snapshot failure');
                    return {subjects:[],offerings:[],enrollments:[]};
                },
                fetchEvaluationsSnapshot:async ({semesterId}) => {
                    if (window.control.holdSnapshots === semesterId) await new Promise(resolve => {window.releaseSnapshot = resolve;});
                    return [{semesterId}];
                },
                classifyFeedbackCommentByRules:()=>({label:'Neutral'}),
                getSemesterList:()=>[{value:'a',label:'Semester A'},{value:'b',label:'Semester B'},{value:'empty',label:'Empty Semester'}]
            };
            window.AppChartDesign = {
                buildSectionSeries:items => ({labels:items.map(row=>row.name),values:items.map(row=>row.average),fullLabels:items.map(row=>row.name)}),
                renderBarChart:(canvas,config) => {window.chartValues[canvas.id] = config.values; return config;},
                renderRatingDistributionChart:(canvas,config) => {window.chartValues[canvas.id] = config.averageRating;return config;}
            };
            function getProfessorFacultyReportAccessState(){return {enabled:window.control.accessEnabled,evaluationPeriodsComplete:window.control.accessEnabled};}
            function resolveReportsGateState(){return {locked:!window.control.accessEnabled};}
            async function applyReportBlackout(){document.getElementById('reportsContent').hidden = !window.control.accessEnabled;}
            function clearRestrictedProfessorReportData(){professorPanelState.summaryByType = {student:PROFESSOR_PANEL_EMPTY_SUMMARY,professor:PROFESSOR_PANEL_EMPTY_SUMMARY,supervisor:PROFESSOR_PANEL_EMPTY_SUMMARY};}
            async function refreshProfessorPanelData(){window.rebuilds++;document.getElementById('semesterFilter').value = 'a';}
            async function resolveActiveProfessorAccount(context){return {linked:true,context};}
            async function buildProfessorPanelContext(){return {professor:{id:'u1'},currentSemester:'a',semesterList:SharedData.getSemesterList()};}
            async function fetchFacultySummaryFromSql(query){
                window.loads.push({semester:query.semesterId,type:query.evaluationType,contextSemester:query.context.evaluations[0]?.semesterId});
                if (window.control.holdSummaries === query.semesterId && query.evaluationType === 'student') await new Promise(resolve => {window.releaseSummary = resolve;});
                return structuredClone(fixtures[query.semesterId][query.evaluationType]);
            }
            async function updateSummaryCards(totals){
                window.summaryCardScore = totals.averageScore;
                if (window.control.holdDashboard) await new Promise(resolve => {window.releaseDashboard = resolve;});
            }
            async function renderSemestralEvaluationTrend(term){window.trendSemester = term;}
            function scheduleProfessorTrendSnapshotRefresh(){}
            function scheduleProfessorReportViewportRefresh(){}
            function renderSemestralTrendChart(){}
        ` + ['getEvaluationTypeMeta','escapeHTML','normalizeToken','formatSemesterLabelFromSlug','getSemesterLabelById',
            'cacheProfessorSemesterSnapshot','buildProfessorContextForSemester','renderBreakdownTable','formatProfessorSetValue',
            'renderCriteriaSummary','renderEvaluationCount','computeTotals','initializeReports','initializeStudentCharts',
            'initializeEvaluationCharts','renderPeerSupervisorAnalytics','renderProfessorOverallRatings',
            'resetProfessorSubjectCommentsPanel','setProfessorCommentsListVisibility','setProfessorCommentsAiSummary',
            'fetchSectionSummaryFromSql','classifyFeedbackCommentBiasByRules','getProfessorFeedbackAiSummarySourceNote',
            'refreshProfessorReportAccessState','loadFacultySummary','setProfessorReportsStatus','updateSemesterLabels',
            'setupSemesterFilter'].map(extract).join('\n') + `
            document.getElementById('semesterFilter').innerHTML = '<option value="a">Semester A</option><option value="b">Semester B</option><option value="empty">Empty Semester</option><option value="review">Review Semester</option>';
            professorPanelState.context.evaluations = [{semesterId:'a'}];
            setupSemesterFilter();
            renderBreakdownTable(professorPanelState.summaryByType.student.setRows,'student',professorPanelState.summaryByType.student.setTotals);
            initializeReports();
        `});
        const filter = page.locator('#semesterFilter');
        const peerRows = page.locator('#peerSupervisorSummaryBody tr');
        const status = page.locator('#professorReportsStatus');
        const waitReady = term => page.waitForFunction(term => professorPanelState.currentSelection.semesterId === term && !professorFeedbackFiltersLoading && document.getElementById('professorReportsStatus').hidden,term);
        assert.equal(await page.locator('#evaluationTypeFilter').count(),0);
        assert.equal(await page.locator('#peerSupervisorAnalytics canvas, #peerSupervisorAnalytics .chart-card').count(),0);
        assert(await page.locator('#peerSupervisorAnalytics').evaluate(section =>
            section.previousElementSibling.querySelector('#facultyBreakdownTable') !== null
        ), 'Peer and supervisor results must be directly below Faculty Response Rate & Counts');
        assert.equal(await peerRows.first().locator('td').nth(2).textContent(),'3.00');
        assert.equal(await peerRows.last().locator('td').nth(2).textContent(),'4.00');
        assert.equal(await page.locator('#profOverallSetRating').textContent(),'90.00');
        assert.equal(await page.locator('#profOverallSefRating').textContent(),'80.00');
        // Report data must be published together, before unrelated dashboard work resolves.
        await page.evaluate(() => {window.control.holdDashboard = true;}); await filter.selectOption('b');
        await page.waitForFunction(() => typeof window.releaseDashboard === 'function');
        assert((await page.locator('#facultyBreakdownTable tbody').textContent()).includes('TOUR102'));
        assert.equal(await page.locator('#profOverallSetRating').textContent(),'80.00');
        assert.equal(await page.locator('#profOverallSefRating').textContent(),'75.00');
        assert.equal(await peerRows.first().locator('td').nth(2).textContent(),'4.25');
        await page.evaluate(() => {window.control.holdDashboard = false;window.releaseDashboard();}); await waitReady('b');
        await filter.selectOption('a'); await waitReady('a');
        // Hold access verification to assert selection is read before any await.
        await page.evaluate(() => {window.control.holdAccess = true;}); await filter.selectOption('b');
        await page.waitForFunction(() => typeof window.releaseAccess === 'function');
        assert.equal(await page.evaluate(() => professorPanelState.currentSelection.semesterId),'b');
        assert.equal(await page.locator('#selectedSemester').textContent(),'Semester B');
        assert(await status.isVisible());
        assert.equal(await page.locator('#profOverallSetRating').textContent(),'N/A');
        assert.equal(await page.locator('#profOverallSefRating').textContent(),'N/A');
        await page.evaluate(() => {window.control.holdAccess = false;window.releaseAccess();}); await waitReady('b');
        assert.equal(await filter.inputValue(),'b'); assert.equal(await page.evaluate(() => window.rebuilds),0);
        assert((await page.locator('#facultyBreakdownTable tbody').textContent()).includes('TOUR102'));
        assert.equal(await peerRows.first().locator('td').nth(2).textContent(),'4.25');
        assert.equal(await page.locator('#profOverallSetRating').textContent(),'80.00');
        assert.equal(await page.locator('#profOverallSefRating').textContent(),'75.00');
        assert.equal(await peerRows.last().locator('td').nth(2).textContent(),'3.75');
        assert.equal(await page.locator('#peerSupervisorSemester').textContent(),'Semester B');
        await page.waitForFunction(() => window.chartValues.studentBarChart[0] === 4);
        assert.equal(await page.evaluate(() => window.trendSemester),'b');
        assert.equal(await page.evaluate(async () => (await fetchSectionSummaryFromSql({evaluationType:'student'}))[0].text),'b student feedback');
        assert.deepEqual(await page.evaluate(() => window.loads.slice(-3).map(row=>row.contextSemester)),['b','b','b']);
        // Late snapshot results must not undo a newer choice.
        await page.evaluate(() => {window.control.holdSnapshots = 'a';}); await filter.selectOption('a');
        await page.waitForFunction(() => typeof window.releaseSnapshot === 'function');
        await filter.selectOption('b'); await waitReady('b');
        await page.evaluate(() => {window.control.holdSnapshots = '';window.releaseSnapshot();});
        assert.equal(await filter.inputValue(),'b'); assert.equal(await page.locator('#selectedSemester').textContent(),'Semester B');
        // Late summary calculations must not commit older source metrics.
        await page.evaluate(() => {window.control.holdSummaries = 'a';}); await filter.selectOption('a');
        await page.waitForFunction(() => typeof window.releaseSummary === 'function');
        await filter.selectOption('b'); await waitReady('b');
        await page.evaluate(() => {window.control.holdSummaries = '';window.releaseSummary();});
        assert.equal(await peerRows.first().locator('td').nth(2).textContent(),'4.25');
        await filter.selectOption('empty'); await waitReady('empty');
        assert((await page.locator('#facultyBreakdownTable tbody').textContent()).includes('No evaluation data'));
        assert.equal(await peerRows.first().locator('td').nth(2).textContent(),'N/A');
        assert.equal(await peerRows.last().locator('td').nth(3).textContent(),'No responses');
        assert.equal(await page.locator('#profOverallSetRating').textContent(),'N/A');
        assert.equal(await page.locator('#profOverallSefRating').textContent(),'N/A');
        await filter.selectOption('review'); await waitReady('review');
        assert.equal(await page.locator('#profOverallSetRating').textContent(),'80.00');
        assert.equal(await page.locator('#profOverallSefRating').textContent(),'N/A');
        assert.equal(await peerRows.last().locator('td').nth(2).textContent(),'N/A');
        assert.equal(await peerRows.last().locator('td').nth(3).textContent(),'Awaiting HR review');
        assert(await page.locator('#profOverallRatingsStatus').isVisible());
        assert((await page.locator('#profOverallRatingsStatus').textContent()).includes('Awaiting HR review'));
        // Failure is visible and Retry reuses the selected semester.
        await page.evaluate(() => {window.control.failSnapshots = true;}); await filter.selectOption('a');
        await page.waitForFunction(() => !document.getElementById('professorReportsRetry').hidden);
        assert((await status.textContent()).includes('Unable to load results for Semester A'));
        assert.equal(await page.locator('#profOverallSetRating').textContent(),'N/A');
        assert.equal(await page.locator('#profOverallSefRating').textContent(),'N/A');
        assert.equal(await peerRows.first().locator('td').nth(2).textContent(),'N/A');
        await page.evaluate(() => {window.control.failSnapshots = false;}); await page.locator('#professorReportsRetry').click(); await waitReady('a');
        assert((await page.locator('#facultyBreakdownTable tbody').textContent()).includes('AVT101'));
        assert(await page.locator('#profOverallRatingsStatus').isHidden());
        assert.equal(await peerRows.first().locator('td').nth(2).textContent(),'3.00');
        const output = path.join(root,'output','professor-semester-analytics');fs.mkdirSync(output,{recursive:true});
        await page.locator('#peerSupervisorAnalytics').screenshot({path:path.join(output,'desktop.png')});
        await page.setViewportSize({width:390,height:844});
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1),'Mobile reports overflow horizontally');
        await page.locator('#peerSupervisorAnalytics').screenshot({path:path.join(output,'mobile.png')});
        assert.deepEqual(errors,[]);
        console.log('Professor semester analytics: selector removal, source comparison, access-reset regression, latest-selection wins, matching charts/feedback, empty results, retry, and mobile layout passed.');
    } finally {await browser.close();}
}
main().catch(error => {console.error(error);process.exitCode = 1;});
