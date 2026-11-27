const fs=require('node:fs'),path=require('node:path'),{chromium}=require(path.resolve('.tools/node_modules/playwright'));
const origin='http://127.0.0.1:8100',out='docs/ui-ux/evidencias/ui-07d';
const result={scope:'Fixtures Blade ficticias, sin SQL, sesión ni hidratación Livewire; no se ejecutan acciones de negocio.',engines:[],screens:[],checks:[],contrast:[],performance:[],errors:[],violations:[]};
const check=(name,passed)=>{result.checks.push({name,passed:!!passed});if(!passed)result.violations.push(name)};
const sizes=[[2560,1440],[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[320,844]];
async function geometry(p){return p.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth+1,small:[...document.querySelectorAll('main button,main input,main select,main textarea,main a')].filter(e=>e.getClientRects().length&&getComputedStyle(e).visibility!=='hidden').filter(e=>{let r=e.getBoundingClientRect();return r.width<43||r.height<43}).map(e=>e.id||e.textContent.trim())}))}
async function settle(p){await p.evaluate(()=>document.fonts.ready);await p.waitForTimeout(200)}
async function contrast(p,selectors){return p.evaluate(selectors=>{
 const ctx=document.createElement('canvas').getContext('2d',{willReadFrequently:true}),lum=v=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=v;ctx.fillRect(0,0,1,1);return [...ctx.getImageData(0,0,1,1).data].slice(0,3).map(x=>x/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4).reduce((a,x,i)=>a+x*[.2126,.7152,.0722][i],0)},bg=e=>{for(let n=e;n;n=n.parentElement){let v=getComputedStyle(n).backgroundColor;if(v!=='rgba(0, 0, 0, 0)'&&v!=='transparent')return v}return '#fff'};
 return selectors.map(([selector,property='color',minimum=4.5])=>{let e=document.querySelector(selector),s=getComputedStyle(e),foreground=s[property],background=bg(e),l=[lum(foreground),lum(background)].sort((a,b)=>b-a);return{selector,property,foreground,background,ratio:(l[0]+.05)/(l[1]+.05),minimum}});
},selectors)}
async function prepare(c){await c.route('**/*',r=>{const q=r.request();if(q.method()!=='GET'||![origin+'/','https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.18'].some(x=>q.url().startsWith(x))){result.errors.push('Blocked '+q.method()+' '+new URL(q.url()).origin);return r.abort()}return r.continue()});c.on('page',p=>{p.on('pageerror',e=>result.errors.push(e.message));p.on('console',m=>{if(m.type()==='error')result.errors.push(m.text())})})}
(async()=>{
for(const [engine,exe] of [['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe'],['edge','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']]){
 const b=await chromium.launch({executablePath:exe,headless:true});result.engines.push({engine,version:b.version()});
 try{
 const c=await b.newContext();await prepare(c);const p=await c.newPage();
 for(const [width,height] of engine==='chrome'?sizes:[[1366,768],[390,844]]){
  await p.setViewportSize({width,height});
  for(const state of ['summary','sales','products','payments','cash-movements','cash-sessions','cash-reconciliations','discounts','corrections']){
   await p.goto(origin+'/after-'+state+'.html',{waitUntil:'networkidle'});
   for(const theme of ['light','dark']){
    await p.selectOption('[data-ui-theme-select]',theme);await settle(p);const g=await geometry(p),screenshot=`${engine}-${state}-${theme}-${width}.png`;
    result.screens.push({engine,state,theme,width,height,screenshot,...g});if(g.overflow||g.small.length)result.violations.push({screenshot,...g});await p.screenshot({path:path.join(out,screenshot),fullPage:true});
   }
  }
 }
 if(engine==='chrome'){
  await p.setViewportSize({width:1366,height:768});
  for(const phase of ['before','after']){await p.goto(origin+'/'+phase+'-sales.html',{waitUntil:'networkidle'});await p.selectOption('[data-ui-theme-select]','light');await settle(p);await p.screenshot({path:path.join(out,phase+'-sales-1366.png'),fullPage:true})}
  await p.goto(origin+'/after-sales.html',{waitUntil:'networkidle'});
  check('all nine report choices remain present',await p.locator('.report-nav a').count()===9);
  check('current report has aria-current',await p.locator('.report-nav [aria-current=page]').innerText()==='Ventas');
  check('visible fields have wrapping labels',await p.locator('.report-filters input,.report-filters select').evaluateAll(es=>es.every(e=>e.labels.length>0)));
  await p.selectOption('[data-report-range]','today');check('preset disables both date fields',await p.locator('[data-report-date]').evaluateAll(es=>es.length===2&&es.every(e=>e.disabled)));
  await p.selectOption('[data-report-range]','custom');check('custom enables both required date fields',await p.locator('[data-report-date]').evaluateAll(es=>es.length===2&&es.every(e=>!e.disabled&&e.required)));
  const queries=[];
  for(const phase of ['before','after']){
   await p.goto(origin+'/'+phase+'-sales.html',{waitUntil:'networkidle'});
   queries.push(await p.locator('.report-filters').evaluate(form=>{
    form.querySelector('[name=employee_id]').value='01ARZ3NDEKTSV4RRFFQ69G5FAV';
    let values;form.addEventListener('submit',event=>{event.preventDefault();values=[...new FormData(form).entries()]},{once:true});form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));return values;
   }));
  }
  check('filter script preserves submitted names and values',JSON.stringify(queries[0])===JSON.stringify(queries[1]));
  check('filter script omits empty optional IDs',queries[1].some(([k])=>k==='employee_id')&&!queries[1].some(([k])=>k==='product_id'));
  await p.goto(origin+'/after-sales.html',{waitUntil:'networkidle'});const region=p.locator('.ui-report-table-scroll');await region.focus();await p.keyboard.press('ArrowRight');await p.waitForTimeout(100);check('wide table scrolls using keyboard',await region.evaluate(e=>e.scrollLeft>0));check('table region has visible keyboard focus',await region.evaluate(e=>parseFloat(getComputedStyle(e).outlineWidth)>=3));
  await p.evaluate(()=>FresaUIPreferences.set('density','comfortable'));check('comfortable table density retained',await p.locator('tbody td').first().evaluate(e=>getComputedStyle(e).paddingTop)==='22px');await p.evaluate(()=>FresaUIPreferences.reset());
  for(const theme of ['light','dark']){await p.selectOption('[data-ui-theme-select]',theme);await settle(p);result.contrast.push({state:'sales',theme,pairs:await contrast(p,[['h1'],['.report-kpi dt'],['.report-kpi dd'],['.report-nav [aria-current=page]'],['.report-action.primary'],['.report-filters label'],['.report-links a'],['.report-filters input','borderTopColor',3]])})}
  for(const [state,expected] of [['pdf-limit',[false,false]],['pdf-overflow',[true,false]],['xlsx-limit',[true,false]],['limits',[true,true]]]){await p.goto(origin+'/after-'+state+'.html',{waitUntil:'networkidle'});check(state+' retains exact export button availability',await p.locator('main form[method=post] button').evaluateAll((es,expected)=>es.length===2&&es.every((e,i)=>e.disabled===expected[i]),expected))}
  await p.goto(origin+'/after-no-export.html',{waitUntil:'networkidle'});check('no-export permission state has no POST forms',await p.locator('main form[method=post]').count()===0);
  await p.goto(origin+'/after-last-page.html',{waitUntil:'networkidle'});check('last page only has previous link',await p.locator('.report-pager a').count()===1&&await p.locator('.report-pager a').innerText()==='Anterior');
  await p.goto(origin+'/after-cash-sessions.html',{waitUntil:'networkidle'});check('unavailable closing KPI is not replaced by zero',await p.locator('.report-kpi').last().innerText().then(x=>x.includes('No disponible')));
  await p.goto(origin+'/after-summary.html',{waitUntil:'networkidle'});check('daily series remains native SVG from supplied data',await p.locator('.report-chart rect').count()===1&&await p.locator('.report-chart rect').getAttribute('width')==='1200.5000');
  for(const theme of ['light','dark']){await p.selectOption('[data-ui-theme-select]',theme);await settle(p);result.contrast.push({state:'summary',theme,pairs:await contrast(p,[['.report-chart svg','color',3]])})}
  await p.setViewportSize({width:390,height:844});
  for(const state of ['empty','no-export','limits','error','error-validation']){
   await p.goto(origin+'/after-'+state+'.html',{waitUntil:'networkidle'});
   for(const theme of ['light','dark']){await p.selectOption('[data-ui-theme-select]',theme);await settle(p);check(state+' '+theme+' reflows on mobile',!(await geometry(p)).overflow);await p.screenshot({path:path.join(out,state+'-'+theme+'-390.png'),fullPage:true});if(state==='error-validation')result.contrast.push({state,theme,pairs:await contrast(p,[['.ui-report-warning'],['.ui-report-error li'],['.report-action']])})}
  }
  check('validation keeps alert role',await p.locator('[role=alert]').count()===1);
  await p.goto(origin+'/after-preset.html',{waitUntil:'networkidle'});check('preset initializes disabled dates without a request',await p.locator('[data-report-date]').evaluateAll(es=>es.every(e=>e.disabled)));
  await p.selectOption('[data-ui-theme-select]','auto');await p.emulateMedia({colorScheme:'dark'});await p.waitForFunction(()=>document.documentElement.dataset.uiTheme==='dark');check('auto theme follows system',true);await p.emulateMedia({reducedMotion:'reduce'});await p.waitForFunction(()=>document.documentElement.dataset.uiMotion==='reduced');check('reduced motion retained',true);
  await p.setViewportSize({width:320,height:844});await p.goto(origin+'/after-sales.html',{waitUntil:'networkidle'});await p.evaluate(()=>document.documentElement.style.fontSize=parseFloat(getComputedStyle(document.documentElement).fontSize)*2+'px');check('font doubled reflows at 320',!(await geometry(p)).overflow);await p.screenshot({path:path.join(out,'font-200-320.png'),fullPage:true});
  await p.goto(origin+'/after-products.html',{waitUntil:'networkidle'});await p.locator('tbody td').first().evaluate(e=>e.textContent='P'.repeat(200));check('long product name reflows at 320',!(await geometry(p)).overflow);check('mobile table preserves scoped headers',await p.locator('thead').evaluate(e=>getComputedStyle(e).display!=='none'&&e.querySelectorAll('th[scope=col]').length>0));await p.screenshot({path:path.join(out,'long-product-320.png'),fullPage:true});
  const blocked=await b.newContext({viewport:{width:320,height:844}});await prepare(blocked);await blocked.addInitScript(()=>Object.defineProperty(window,'localStorage',{get(){throw new DOMException('Blocked','SecurityError')}}));const bp=await blocked.newPage();await bp.goto(origin+'/after-summary.html',{waitUntil:'networkidle'});await bp.selectOption('[data-ui-theme-select]','dark');check('blocked storage remains usable',await bp.locator('html').getAttribute('data-ui-theme')==='dark'&&!(await geometry(bp)).overflow);await blocked.close();
 }

 await c.close();
 if(engine==='chrome'){
  const pc=await b.newContext({viewport:{width:1366,height:768}});await prepare(pc);const pp=await pc.newPage();await pp.addInitScript(()=>{window.__metrics={lcp:null,cls:0};new PerformanceObserver(l=>{for(const e of l.getEntries())window.__metrics.lcp=e.startTime}).observe({type:'largest-contentful-paint',buffered:true});new PerformanceObserver(l=>{for(const e of l.getEntries())if(!e.hadRecentInput)window.__metrics.cls+=e.value}).observe({type:'layout-shift',buffered:true})});
  for(const phase of ['before','after']){await pp.goto(origin+'/'+phase+'-sales.html',{waitUntil:'networkidle'});for(let i=0;i<5;i++){await pp.reload({waitUntil:'networkidle'});await settle(pp);result.performance.push({phase,iteration:i+1,...await pp.evaluate(()=>window.__metrics)})}}await pc.close();
 }
 }finally{await b.close()}
}
if(result.errors.length)result.violations.push({errors:result.errors});if(result.contrast.some(x=>x.pairs.some(y=>y.ratio<y.minimum)))result.violations.push('contrast');fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify(result,null,2));console.log(JSON.stringify({screens:result.screens.length,checks:result.checks.length,violations:result.violations}));if(result.violations.length)process.exitCode=1;
})().catch(e=>{result.execution_error=e.stack;fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify(result,null,2));console.error(e);process.exitCode=1});
