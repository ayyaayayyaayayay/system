'use strict';
// Actual shared Dean/Coordinator handlers and renderers, isolated from live data and AI.
const {chromium} = require('../.codex/credibility-ui-test/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname,'..');
const read = file => fs.readFileSync(path.join(root,file),'utf8').replace(/\r\n/g,'\n');
const source = read('JsScrip/daenpanel.js');
function extract(name) {
    const match = new RegExp(`^(?:async )?function ${name}\\(`,'m').exec(source);
    assert(match,'Missing '+name);
    const next = /\n(?:async )?function /.exec(source.slice(match.index+1));
    assert(next,'Missing boundary '+name);
    return source.slice(match.index,match.index+1+next.index);
}
const shared = read('JsScrip/db-data.js');
const policy = shared.slice(shared.indexOf('    // Mirror api/feedback_classification_policy.json.'), shared.indexOf('    async function analyzeBiasComments('));
const output = path.join(root,'output','dean-feedback-intelligence');
const alpha = ['Please provide more worked examples.','Additional examples please.',
    'Please explain the lessons more clearly.','Sana po mas malinaw ang paliwanag.',
    'Thank you for the helpful learning materials.','<img src=x onerror="window.injected=true">','Bobo ka sir.'];
const summary = {total:7,constructive:4,positive:1,neutral:1,critical:0,biased:1,source:'openai',
    summaryLine:'Summary of 7 student comments: students request clearer explanations and more examples, alongside appreciation for the learning materials.',
    toneLine:'Overall feedback is mostly constructive.',topics:[{label:'Lesson explanations',count:2},{label:'Examples and practice',count:2}],
    teachingImprovements:['Explain difficult topics step by step and check understanding.','Include worked examples and guided practice.']};
async function main() {
    fs.mkdirSync(output,{recursive:true});
    const browser = await chromium.launch({channel:'chrome',headless:true});
    try {
        for (const role of ['dean','procoor']) {
            const page = await browser.newPage({viewport:{width:1280,height:1000}});
            const errors = []; page.on('pageerror',error=>errors.push(error.message));
            await page.route('**/*',route=>route.abort());
            const html = read('html/'+(role==='dean'?'daenpanel':'procoorpanel')+'.html');
            const a = html.lastIndexOf('<section class="detail-report-section">',html.indexOf('id="facultySearchInput"'));
            const section = html.slice(a,html.indexOf('</section>',a)+10);
            assert(a>=0 && section.includes('facultyCommentsView'));
            await page.setContent('<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="dean-panel '+(role==='procoor'?'procoor-panel':'')+'"><main style="max-width:1120px;margin:24px auto;padding:0 16px">'+section+'</main></body></html>');
            for (const css of ['css/panel-theme.css','css/daenpanel.css','css/feedback-intelligence.css']) await page.addStyleTag({content:read(css)});
            await page.addScriptTag({content:policy+`
                const SUPERVISOR_ROLE = ${JSON.stringify(role)};
                let deanSummaryState = {selectedSemesterId:'t1',selectedSemesterLabel:'Semester One',selectedEvaluationType:'student'};
                let deanFacultyFeedbackState = {loadedComments:[],lastTrendRows:[]};
                let deanFacultyResponseRefreshHandler;
                const rows = [
                    {professorId:'EMP1',professorUserId:'u1',professorName:'Alpha Professor',institute:'ILAS',received:7,required:10,avgScore:4,status:'active'},
                    {professorId:'EMP2',professorUserId:'u2',professorName:'Beta Professor',institute:'ILAS',received:1,required:10,avgScore:4,status:'active'},
                    {professorId:'EMP3',professorUserId:'u3',professorName:'Empty Professor',institute:'ILAS',received:0,required:10,avgScore:null,status:'active'},
                ];
                const evaluations = ${JSON.stringify(alpha)}.map((text,i)=>({id:'alpha-'+i,targetProfessorId:'u1',evaluationType:'student',semesterId:'t1',comments:text,evaluatorStudentNumber:'PRIVATE-'+i}));
                evaluations.push(
                    {targetProfessorId:'u2',evaluationType:'student',semesterId:'t1',comments:'Beta only feedback.'},
                    {targetProfessorId:'u1',evaluationType:'student',semesterId:'t2',comments:'Historical semester feedback.'},
                    {targetProfessorId:'u1',evaluationType:'professor',semesterId:'t1',comments:'Please provide more peer learning examples.'},
                    {targetProfessorId:'u1',evaluationType:'supervisor',semesterId:'t1',comments:'Please improve the learning materials.'}
                );
                function getUserSession(){return {username:'fixture',role:SUPERVISOR_ROLE};}
                function getDeanAssignedInstitutes(){return ['ILAS'];}
                function resolveSelectedSemesterId(value){return value || 't1';}
                function getSemesterLabelById(value){return value==='t2'?'Semester Two':'Semester One';}
                function buildDeanPanelContext(){return {semesterList:[{value:'t1',label:'Semester One'},{value:'t2',label:'Semester Two'}],currentSemester:'t1',scopedProfessors:[],evaluations};}
                function getDeanEvaluationTypeMeta(view){return {id:view==='peer'?'professor':view};}
                function getDeanSummaryForType(){return {breakdownRows:[],commentBuckets:{}};}
                function ensureDeanFacultyResponseData(){return Promise.resolve();}
                function loadFacultySummary(){}
                function fetchDeanProfessorResultsFromSql(){return Promise.resolve(rows);}
                function fetchDeanPeerEvaluationResultsFromSql(){return Promise.resolve(rows);}
                function fetchDeanSupervisorEvaluationResultsFromSql(){return Promise.resolve(rows);}
                function updateFacultySearchResult(){document.getElementById('facultySearchResult').textContent='Fixture faculty results';}
                function resetDeanProfessorSemestralTrend(){}
                function setDeanSemestralTrendVisibility(){}
                function renderDeanProfessorSemestralTrend(){}
                function formatDisplayDate(){return 'Fixture date';}
                function normalizeDeanToken(value){return String(value||'').trim().toLowerCase();}
                function resolveEvaluationTypeToken(row){return row.evaluationType;}
                function isEvaluationInSemester(row,semester){return row.semesterId===semester;}
                function resolveDeanTargetProfessorId(row){return row.targetProfessorId;}
                window.aiMode='deferred';window.requests=[];window.resolvers=[];
                const SharedData={classifyFeedbackCommentByRules,detectFeedbackCommentTopics,getFeedbackClassificationTone,
                    prepareFeedbackSummaryComments,buildFeedbackTeachingImprovements,renderFeedbackIntelligence,
                    summarizeFeedbackComments:async (payload,actor)=>{
                        window.requests.push({payload,actor});
                        if(window.aiMode==='reject')throw Error('Fixture failure');
                        if(window.aiMode==='disabled')return {disabled:true};
                        return new Promise(resolve=>window.resolvers.push(resolve));
                    }};
            `+['escapeHTML','normalizeUserIdToken','normalizeRoleToken','isProgramScopedSupervisorPanel','shouldHideStudentCommentIdentity',
                'collectEvaluationComments','classifyDeanFeedbackCommentBiasByRules','setDeanCommentsAiSummary','getDeanFeedbackAiSummarySourceNote',
                'setDeanCommentsListVisibility','detectDeanFeedbackTopics','buildDeanFeedbackAiSummary','buildDeanCommentIdentityFields',
                'buildDeanCommentRecord','resolveDeanCommentStudentNumber','fetchFacultyCommentsFromSql','renderFacultyResponseTable','setupFacultyResponseView'].map(extract).join('\n')+`
                window.ready=setupFacultyResponseView();
            `});
            await page.evaluate(()=>window.ready);
            const panel = page.locator('#facultyCommentsPanel');
            const list = page.locator('#facultyCommentsList');
            const outputEl = page.locator('#facultyCommentsAiSummary');
            const ai = page.locator('#facultyCommentsAiSummarize');
            const view = page.locator('#facultyCommentsView');
            const close = page.locator('#facultyCommentsClose');
            const faculty = id => page.locator('.js-view-comments[data-professor-id="'+id+'"]');
            const loaded = text => page.waitForFunction(text=>document.getElementById('facultyCommentsList').textContent.includes(text),text);
            assert(await panel.isHidden());
            await faculty('EMP1').focus(); await page.keyboard.press('Enter'); await loaded('Additional examples');
            assert.equal(await list.locator('li').count(),7);
            assert.equal(await list.locator('img').count(),0);
            assert.equal((await list.textContent()).includes('Student Number: PRIVATE'),role==='dean');
            await ai.click();
            assert(await list.isHidden()); assert.equal(await outputEl.getAttribute('aria-busy'),'true');
            const firstRequest = await page.evaluate(()=>window.requests[0]);
            assert.equal(firstRequest.payload.semesterId,'t1');assert.equal(firstRequest.payload.includeTeachingImprovements,true);
            assert.equal(firstRequest.actor.role,role);assert.equal(firstRequest.payload.comments.length,7);
            assert(!JSON.stringify(firstRequest.payload).includes('PRIVATE'));
            assert(!firstRequest.payload.comments.some(row=>/Beta|Historical|peer learning/.test(row.text)));
            await page.evaluate(result=>window.resolvers.shift()({success:true,summary:result}),summary);
            await page.waitForFunction(()=>document.querySelector('.feedback-intelligence-source')?.textContent==='AI generated');
            assert.deepEqual(await outputEl.locator('.feedback-intelligence-metric strong').allTextContents(),['7','4','2','1']);
            assert((await outputEl.textContent()).includes('Alpha Professor'));
            assert((await outputEl.textContent()).includes('Semester One'));
            assert.equal(await outputEl.locator('progress').count(),2);
            for (const viewport of [{width:1280,height:1000},{width:390,height:844},{width:844,height:390}]) {
                await page.setViewportSize(viewport);
                assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),role+' page overflows');
                assert(await panel.evaluate(el=>el.scrollHeight<=el.clientHeight+1),'Panel clips results');
                await page.screenshot({path:path.join(output,role+'-'+(viewport.width===1280?'desktop':viewport.width===390?'mobile':'landscape')+'.png'),fullPage:true});
            }
            await view.focus(); await page.keyboard.press('Enter'); await loaded('Additional examples');assert(await list.isVisible());
            assert.equal(await outputEl.locator('.feedback-intelligence-header').count(),0);
            for(const mode of ['disabled','reject']) {
                await page.evaluate(mode=>window.aiMode=mode,mode);await ai.click();
                await page.waitForFunction(()=>document.querySelector('.feedback-intelligence-source')?.textContent==='Rule-based');
                assert(await list.isHidden());assert.equal(await outputEl.locator('.feedback-intelligence-suggestions li').count(),2);
                await view.click();await loaded('Additional examples');
            }
            await page.evaluate(()=>window.aiMode='deferred');await ai.click();await view.click();
            await page.evaluate(()=>window.resolvers.shift()({success:true,summary:{summaryLine:'STALE VIEW'}}));
            assert(!(await outputEl.textContent()).includes('STALE'));assert(await list.isVisible());
            await ai.click();await faculty('EMP2').click();await loaded('Beta only');
            await page.evaluate(()=>window.resolvers.shift()({success:true,summary:{summaryLine:'STALE PROFESSOR'}}));
            assert(!(await outputEl.textContent()).includes('STALE'));assert.equal(await list.locator('li').count(),1);
            await faculty('EMP1').click();await loaded('Additional examples');await ai.click();await close.click();
            await page.evaluate(()=>window.resolvers.shift()({success:true,summary:{summaryLine:'STALE CLOSE'}}));assert(await panel.isHidden());
            await faculty('EMP1').click();await loaded('Additional examples');await ai.click();
            await page.locator('#facultyResponseSemesterFilter').selectOption('t2');
            await page.evaluate(()=>window.resolvers.shift()({success:true,summary:{summaryLine:'STALE SEMESTER'}}));assert(await panel.isHidden());
            await faculty('EMP1').click();await loaded('Historical semester');assert.equal(await list.locator('li').count(),1);
            await page.locator('#facultyResponseSemesterFilter').selectOption('t1');await faculty('EMP1').click();await loaded('Additional examples');await ai.click();
            await page.locator('#peerResultsBtn').click();
            await page.evaluate(()=>window.resolvers.shift()({success:true,summary:{summaryLine:'STALE SOURCE'}}));assert(await panel.isHidden());
            await faculty('EMP1').click();await loaded('peer learning');await ai.click();
            assert.equal(await page.evaluate(()=>window.requests.at(-1).payload.evaluationLabel),'Professor Evaluation');
            assert.equal(await page.evaluate(()=>window.requests.at(-1).payload.comments.length),1);
            await page.evaluate(()=>window.resolvers.shift()({success:true,summary:{total:1,constructive:1,source:'openai',summaryLine:'Peer feedback.',teachingImprovements:['Use examples.']}}));
            await page.waitForFunction(()=>document.querySelector('.feedback-intelligence-header p')?.textContent.includes('Professor Evaluation'));
            await page.locator('#supervisorResultsBtn').click();await faculty('EMP1').click();await loaded('improve the learning materials');
            await ai.click();assert.equal(await page.evaluate(()=>window.requests.at(-1).payload.evaluationLabel),'Supervisor Evaluation');
            await close.click();await page.evaluate(()=>window.resolvers.shift()({success:true,summary:{summaryLine:'STALE SUPERVISOR'}}));
            await page.locator('#studentResultsBtn').click();await faculty('EMP3').click();await loaded('No comments available.');
            assert(await ai.isDisabled());assert.equal(await outputEl.locator('.feedback-intelligence-header').count(),0);
            // Pending comment loads are invalidated by another professor or Close.
            await page.evaluate(()=>{
                window.realFetch=fetchFacultyCommentsFromSql;window.commentResolvers=[];
                fetchFacultyCommentsFromSql=()=>new Promise(resolve=>window.commentResolvers.push(resolve));
            });
            await faculty('EMP1').click();assert(await ai.isDisabled());assert(await view.isDisabled());
            await faculty('EMP2').click();
            await page.evaluate(()=>window.commentResolvers.shift()([{text:'STALE COMMENTS'}]));
            assert(!(await list.textContent()).includes('STALE'));
            await page.evaluate(()=>window.commentResolvers.shift()([{text:'Fresh Beta feedback.'}]));await loaded('Fresh Beta');
            await faculty('EMP1').click();await close.click();
            await page.evaluate(()=>window.commentResolvers.shift()([{text:'STALE CLOSED COMMENTS'}]));assert(await panel.isHidden());
            await page.evaluate(()=>{fetchFacultyCommentsFromSql=()=>Promise.reject(Error('Fixture comment failure'));});
            await faculty('EMP1').click();await loaded('Unable to load comments.');assert(await ai.isDisabled());
            await page.evaluate(()=>setDeanCommentsAiSummary({total:2,constructive:1,critical:0,source:'openai',summaryLine:'<svg onload="window.injected=true">',
                topics:[{label:'<img src=x onerror="window.injected=true">',count:2}],teachingImprovements:['<img src=x onerror="window.injected=true">']},'success'));
            assert.equal(await outputEl.locator('img,svg').count(),0);assert.equal(await page.evaluate(()=>window.injected),undefined);
            assert.deepEqual(errors,[]);await page.close();
        }
        console.log('PASS: Dean/Coordinator intelligence layout, scoped requests, privacy policy, source/semester isolation, View/Close, AI fallback, empty/error states, stale requests, keyboard, escaping, and mobile.');
    } finally {await browser.close();}
}
main().catch(error=>{console.error(error);process.exitCode=1;});
