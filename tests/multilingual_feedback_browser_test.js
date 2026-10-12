'use strict';
// Uses the existing optional Playwright test dependency; never connects to application data.
const { chromium } = require('../.codex/credibility-ui-test/node_modules/playwright');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const read = name => fs.readFileSync(path.join(root,name),'utf8').replace(/\r\n/g,'\n');
const output = path.join(root,'output','multilingual-feedback');
function extract(source,name) {
    let start=source.indexOf('function '+name+'(');
    if (source.slice(start-6,start)==='async ') start-=6;
    const match=/\n(?:async )?function /.exec(source.slice(start+1));
    assert(start>=0 && match,name);
    return source.slice(start,start+1+match.index);
}
const shared=read('JsScrip/db-data.js');
const policyStart=shared.indexOf('    // Mirror api/feedback_classification_policy.json.');
const policyEnd=shared.indexOf('    async function analyzeBiasComments(',policyStart);
const policy=shared.slice(policyStart,policyEnd);
const fixtures=JSON.parse(read('tests/fixtures/multilingual_feedback.json'));
const comments=[0,1,2,3,4].map((index,i)=>({id:'ui-'+i,text:fixtures[index].text}));
comments[0].text='  Sana po\n\tmas maraming halimbawa.  ';
comments.push({id:'ui-escape',text:'<img src=x onerror="window.injected=true">'});
const hrSource=read('JsScrip/hrpanel.js');
const hrHtml=read('html/hrpanel.html');
const hrStart=hrHtml.lastIndexOf('<section class="admin-section ai-insights-section">',hrHtml.indexOf('Bias Detection'));
const hrSection=hrHtml.slice(hrStart,hrHtml.indexOf('</section>',hrStart)+10);
async function main() {
    fs.mkdirSync(output,{recursive:true});
    const browser=await chromium.launch({channel:'chrome',headless:true});
    try {
        const page=await browser.newPage({viewport:{width:1280,height:1000}});
        const errors=[];page.on('pageerror',e=>errors.push(e.message));
        await page.route('**/*',route=>route.abort());
        await page.setContent('<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body class="hr-panel"><main class="test-shell">'+hrSection+'</main></body></html>');
        for (const css of ['css/panel-theme.css','css/hrpanel.css']) await page.addStyleTag({content:read(css)});
        await page.addStyleTag({content:'.test-shell{max-width:1120px;margin:24px auto;padding:0 16px;}'});
        await page.addScriptTag({content:policy+`
            const HR_PHONE_BREAKPOINT=640;
            const fixtureComments=${JSON.stringify(comments)};
            const SharedData={formatDateTimeInPhilippines:()=>'-',analyzeBiasComments:async()=>{
                const items=fixtureComments.map(row=>({id:row.id,comment:row.text,...classifyFeedbackCommentByRules(row.text)}));
                const summary={total:items.length,constructive:0,positive:0,neutral:0,critical:0,biased:0,source:'rule'};
                items.forEach(row=>summary[row.label.toLowerCase()]++);
                return {success:true,items,summary};
            }};
            function runHrWithGlobalLoading(message,callback){return callback();}
            async function mapHrAnalysisRows(items,callback){return items.map(callback);}
        `+['isHrPhoneViewport','escapeHrHtml','formatAiInsightsDate','setHrResponsiveTableMode','renderHrResponsiveTablePrompt','renderHrResponsiveTableCards','buildHrMobileDataField','runAiBiasDetection'].map(name=>extract(hrSource,name)).join('\n')});
        assert.equal(await page.evaluate(()=>runAiBiasDetection()),true);
        assert.equal(await page.locator('#hr-ai-bias-body .ai-insights-tag').count(),6);
        for (const label of ['Constructive','Positive','Neutral','Critical','Biased']) {
            assert((await page.locator('#hr-ai-bias-summary').textContent()).includes(label+': '));
            assert(await page.locator('.feedback-'+label.toLowerCase()).count()>0);
        }
        assert.equal(await page.locator('#hr-ai-bias-body tr').first().locator('td').first().textContent(),comments[0].text);
        assert.equal(await page.locator('#hr-ai-bias-body img').count(),0,'Feedback was inserted as HTML.');
        assert.equal(await page.evaluate(()=>window.injected),undefined);
        await page.screenshot({path:path.join(output,'hr-desktop.png'),fullPage:true});
        await page.setViewportSize({width:390,height:844});
        assert.equal(await page.evaluate(()=>runAiBiasDetection()),true);
        assert.equal(await page.locator('#hr-ai-bias-body .hr-mobile-data-card').count(),6);
        assert.equal(await page.locator('#hr-ai-bias-body .feedback-comment-text').first().textContent(),comments[0].text);
        assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),'HR mobile layout overflows horizontally.');
        assert(await page.locator('.hr-mobile-card-wrap').evaluate(el=>el.scrollWidth<=el.clientWidth+1), 'Mobile feedback cards exceed the visible container width.');
        assert(await page.locator('#hr-ai-bias-body .hr-mobile-card-value').evaluateAll(elements=>elements.every(el=>el.scrollWidth<=el.clientWidth+1)), 'Mobile feedback or reasons are clipped.');
        await page.screenshot({path:path.join(output,'hr-mobile.png'),fullPage:true});
        for (const [role,sourceFile,css,id,summary,renderer,sourceNote,normalize,topics,classifier] of [
            ['dean','JsScrip/daenpanel.js','css/daenpanel.css','facultyCommentsAiSummary','buildDeanFeedbackAiSummary','setDeanCommentsAiSummary','getDeanFeedbackAiSummarySourceNote','normalizeDeanToken','detectDeanFeedbackTopics','classifyDeanFeedbackCommentBiasByRules'],
            ['professor','JsScrip/profesorpanel.js','css/profesorpanel.css','profSubjectCommentsAiSummary','buildStudentFeedbackAiSummary','setProfessorCommentsAiSummary','getProfessorFeedbackAiSummarySourceNote','normalizeToken','detectStudentCommentTopics','classifyFeedbackCommentBiasByRules'],
        ]) {
            const source=read(sourceFile);
            await page.setContent('<html><head><meta name="viewport" content="width=device-width,initial-scale=1"></head><body><main class="test-shell"><h2>Faculty feedback</h2><div class="faculty-comments-ai-summary" id="'+id+'"></div></main></body></html>');
            for (const sheet of ['css/panel-theme.css',css,'css/feedback-intelligence.css']) await page.addStyleTag({content:read(sheet)});
            await page.addStyleTag({content:'.test-shell{max-width:1000px;margin:24px auto;padding:0 16px;}'});
            await page.addScriptTag({content:"(function(){"+policy+`
                const SharedData={classifyFeedbackCommentByRules,getFeedbackClassificationTone,detectFeedbackCommentTopics,
                    prepareFeedbackSummaryComments,buildFeedbackTeachingImprovements,renderFeedbackIntelligence};
                const fixtureComments=${JSON.stringify(comments)};
                function ${normalize}(value){return String(value||'').trim().toLowerCase();}
            `+[classifier,topics,summary,'escapeHTML',sourceNote,renderer].map(name=>extract(source,name)).join('\n')+`
                ${renderer}(${summary}(fixtureComments),'success');
            `+'})();'});
            for (const width of [1280,390]) {
                await page.setViewportSize({width,height:844});
                {
                    const metrics = page.locator('#'+id+' .feedback-intelligence-metric');
                    assert.equal(await metrics.count(),4);
                    assert.deepEqual(await metrics.locator('strong').allTextContents(),['6','1','2','1']);
                    assert((await page.locator('#'+id).textContent()).includes('Rule-based'));
                }
                assert(await page.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1),role+' layout overflows.');
                await page.screenshot({path:path.join(output,role+'-'+(width===390?'mobile':'desktop')+'.png'),fullPage:true});
            }
        }
        assert.deepEqual(errors,[],'Browser renderer errors.');
        console.log('PASS: actual HR/faculty renderers, feedback intelligence metrics, desktop/mobile layouts, original whitespace, and HTML escaping; screenshots in output/multilingual-feedback.');
    } finally { await browser.close(); }
}
main().catch(error=>{console.error(error);process.exitCode=1;});
