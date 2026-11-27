const fs=require('node:fs'),path=require('node:path'),{chromium}=require(path.resolve('.tools/node_modules/playwright'));
(async()=>{
 const browser=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 const context=await browser.newContext({viewport:{width:1366,height:768}}),page=await context.newPage(),samples=[];
 await context.addInitScript(()=>{localStorage.setItem('fresapos.ui.visual.v1',JSON.stringify({theme:'light'}));window.__metrics={lcp:null,cls:0};new PerformanceObserver(l=>{for(const e of l.getEntries())window.__metrics.lcp=e.startTime}).observe({type:'largest-contentful-paint',buffered:true});new PerformanceObserver(l=>{for(const e of l.getEntries())if(!e.hadRecentInput)window.__metrics.cls+=e.value}).observe({type:'layout-shift',buffered:true})});
 try{
  for(let iteration=0;iteration<11;iteration++)for(const phase of iteration%2===0?['before','after']:['after','before']){
   await page.goto('http://127.0.0.1:8101/'+phase+'-open.html',{waitUntil:'networkidle'});await page.evaluate(()=>document.fonts.ready);await page.waitForTimeout(200);
   if(iteration>0)samples.push({phase,iteration,...await page.evaluate(()=>window.__metrics)});
  }
  fs.writeFileSync('docs/ui-ux/evidencias/ui-08a/performance-isolated.json',JSON.stringify({scope:'Fixture local, diez muestras calientes alternadas por fase, sin suite SQL concurrente; no representa tráfico autenticado.',engine:browser.version(),viewport:[1366,768],samples},null,2));
  console.log(JSON.stringify(samples));
 }finally{await browser.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
