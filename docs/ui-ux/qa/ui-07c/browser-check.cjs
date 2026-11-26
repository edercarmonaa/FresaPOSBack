const fs=require('node:fs'),path=require('node:path'),{chromium}=require(path.resolve('.tools/node_modules/playwright'));
const origin='http://127.0.0.1:8099',out='docs/ui-ux/evidencias/ui-07c';
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
  for(const state of ['ready','open','blind','pending','movement-pending','blocked','closed','error','read-only']){
   await p.goto(origin+'/after-'+state+'.html',{waitUntil:'networkidle'});
   for(const theme of ['light','dark']){
    await p.selectOption('[data-ui-theme-select]',theme);await settle(p);const g=await geometry(p),screenshot=`${engine}-${state}-${theme}-${width}.png`;
    result.screens.push({engine,state,theme,width,height,screenshot,...g});if(g.overflow||g.small.length)result.violations.push({screenshot,...g});await p.screenshot({path:path.join(out,screenshot),fullPage:true});
   }
  }
 }
 if(engine==='chrome'){
  await p.setViewportSize({width:1366,height:768});
  for(const phase of ['before','after']){await p.goto(origin+'/'+phase+'-open.html',{waitUntil:'networkidle'});await p.selectOption('[data-ui-theme-select]','light');await settle(p);await p.screenshot({path:path.join(out,phase+'-open-1366.png'),fullPage:true})}
  await p.goto(origin+'/after-ready.html',{waitUntil:'networkidle'});
  check('opening preserves decimal text input',await p.locator('#cash-fund').getAttribute('type')==='text'&&await p.locator('#cash-fund').getAttribute('inputmode')==='decimal');
  check('network alert is hidden at rest',await p.locator('[data-cash-network]').isHidden());
  await p.goto(origin+'/after-blind.html',{waitUntil:'networkidle'});
  check('blind count hides opening fund label',await p.getByText('Fondo inicial',{exact:true}).count()===0);
  check('blind count hides movement balances and panel',await p.locator('.cash-movements').count()===0);
  check('blind count has count form and no current receipt',await p.locator('#closing-counted').count()===1&&await p.locator('[data-closing-receipt]').count()===0);
  check('count and observation remain required',await p.locator('#closing-counted').getAttribute('required')!==null&&await p.locator('#closing-observation').getAttribute('required')!==null);
  await p.locator('#closing-counted').focus();await p.keyboard.press('Tab');check('Tab reaches count observation',await p.locator('#closing-observation').evaluate(e=>e===document.activeElement));
  check('count observation has focus outline',await p.locator('#closing-observation').evaluate(e=>getComputedStyle(e).outlineStyle!=='none'&&parseFloat(getComputedStyle(e).outlineWidth)>=2));
  await p.goto(origin+'/after-pending.html',{waitUntil:'networkidle'});
  check('pending close disables prepare and submit',await p.locator('[wire\\:click="beginClosing"]').isDisabled()&&await p.locator('[wire\\:submit="confirmClosing"] button').isDisabled());
  check('pending close keeps state query available',await p.locator('[wire\\:click="queryClosing"]').isEnabled());
  await p.goto(origin+'/after-movement-pending.html',{waitUntil:'networkidle'});check('pending movement only permits query without new form',await p.locator('.cash-movements form').count()===0&&await p.locator('.cash-movements [wire\\:click="resolveMovement"]').count()===1);
  await p.goto(origin+'/after-resolved.html',{waitUntil:'networkidle'});check('resolved movement fields remain disabled',await p.locator('.cash-movements input,.cash-movements select,.cash-movements textarea,.cash-movements [type=submit]').evaluateAll(es=>es.length===4&&es.every(e=>e.matches(':disabled'))));check('resolved movement has explicit new intention button',await p.locator('[wire\\:click="prepareMovement"]').isEnabled());
  await p.goto(origin+'/after-blocked.html',{waitUntil:'networkidle'});check('pending sales block count form',await p.locator('#closing-counted').count()===0&&await p.getByText('Cierre bloqueado: 26 ventas pendientes.',{exact:true}).count()===1);check('pending sales preserve POS links',await p.locator('.ui-cash-warning a').first().getAttribute('href').then(x=>x.includes('/pos?sale=')));
  await p.goto(origin+'/after-read-only.html',{waitUntil:'networkidle'});check('read-only state has no financial write forms or closing panel',await p.locator('main form').count()===0&&await p.locator('.cash-closing').count()===0);
  await p.goto(origin+'/after-closed.html',{waitUntil:'networkidle'});check('historical shortage and signed difference stay visible',await p.locator('#closing-result-title').innerText().then(x=>x.includes('Faltante'))&&await p.locator('[data-closing-receipt]').innerText().then(x=>x.includes('-1.0000')));
  await p.goto(origin+'/after-error.html',{waitUntil:'networkidle'});check('visible controls have explicit labels',await p.locator('main input,main select,main textarea').evaluateAll(es=>es.every(e=>e.labels.length>0)));check('movement validation keeps invalid state',await p.locator('#cash-movement-amount').getAttribute('aria-invalid')==='true');
  for(const theme of ['light','dark']){await p.selectOption('[data-ui-theme-select]',theme);await settle(p);result.contrast.push({state:'error',theme,pairs:await contrast(p,[['h1'],['.ui-cash-help'],['.ui-cash-badge'],['.ui-cash-error'],['.ui-button--primary'],['.ui-button--secondary'],['#cash-movement-amount','borderTopColor',3],['#cash-movement-type','borderTopColor',3]])})}
  await p.goto(origin+'/after-pending.html',{waitUntil:'networkidle'});for(const theme of ['light','dark']){await p.selectOption('[data-ui-theme-select]',theme);await settle(p);result.contrast.push({state:'pending',theme,pairs:await contrast(p,[['.ui-cash-warning']])})}
  await p.goto(origin+'/after-movement-pending.html',{waitUntil:'networkidle'});for(const theme of ['light','dark']){await p.selectOption('[data-ui-theme-select]',theme);await settle(p);result.contrast.push({state:'movement-pending',theme,pairs:await contrast(p,[['.ui-cash-status']])})}
  await p.goto(origin+'/after-open.html',{waitUntil:'networkidle'});await p.locator('[data-cash-network]').evaluate(e=>e.hidden=false);await p.locator('[wire\\:offline]').evaluate(e=>e.style.setProperty('display','inline-flex','important'));
  for(const theme of ['light','dark']){await p.selectOption('[data-ui-theme-select]',theme);await settle(p);result.contrast.push({state:'network',theme,pairs:await contrast(p,[['[data-cash-network]'],['[data-cash-network] a'],['.ui-cash-offline']])});await p.screenshot({path:path.join(out,'network-'+theme+'.png'),fullPage:true})}
  check('network notice does not erase entered amount',await p.locator('#cash-movement-amount').inputValue()==='10.2500');
  await p.selectOption('[data-ui-theme-select]','auto');await p.emulateMedia({colorScheme:'dark'});await p.waitForFunction(()=>document.documentElement.dataset.uiTheme==='dark');check('auto theme follows system',true);await p.emulateMedia({reducedMotion:'reduce'});await p.waitForFunction(()=>document.documentElement.dataset.uiMotion==='reduced');check('system reduced motion retained',true);
  await p.setViewportSize({width:320,height:844});await p.goto(origin+'/after-blind.html',{waitUntil:'networkidle'});await p.evaluate(()=>document.documentElement.style.fontSize=parseFloat(getComputedStyle(document.documentElement).fontSize)*2+'px');check('blind count reflows with font doubled at 320',!(await geometry(p)).overflow);await p.screenshot({path:path.join(out,'font-200-320.png'),fullPage:true});
  await p.goto(origin+'/after-open.html',{waitUntil:'networkidle'});await p.evaluate(()=>{document.querySelector('.cash-movement-table tbody td:last-child').firstChild.textContent='C'.repeat(200)});check('long movement concept reflows at 320',!(await geometry(p)).overflow);check('mobile keeps semantic table headers available',await p.locator('thead').evaluate(e=>getComputedStyle(e).display!=='none'&&e.querySelectorAll('th[scope=col]').length===3));await p.screenshot({path:path.join(out,'long-concept-320.png'),fullPage:true});
  for(const state of ['unprovisioned','empty']){await p.goto(origin+'/after-'+state+'.html',{waitUntil:'networkidle'});check(state+' has no page overflow',!(await geometry(p)).overflow);await p.screenshot({path:path.join(out,state+'-320.png'),fullPage:true})}
  const blocked=await b.newContext({viewport:{width:320,height:844}});await prepare(blocked);await blocked.addInitScript(()=>Object.defineProperty(window,'localStorage',{get(){throw new DOMException('Blocked','SecurityError')}}));const bp=await blocked.newPage();await bp.goto(origin+'/after-ready.html',{waitUntil:'networkidle'});await bp.selectOption('[data-ui-theme-select]','dark');check('blocked storage fallback remains usable',await bp.locator('html').getAttribute('data-ui-theme')==='dark'&&!(await geometry(bp)).overflow);await blocked.close();
 }
 await c.close();
 if(engine==='chrome'){
  const pc=await b.newContext({viewport:{width:1366,height:768}});await prepare(pc);const pp=await pc.newPage();await pp.addInitScript(()=>{window.__metrics={lcp:null,cls:0};new PerformanceObserver(l=>{for(const e of l.getEntries())window.__metrics.lcp=e.startTime}).observe({type:'largest-contentful-paint',buffered:true});new PerformanceObserver(l=>{for(const e of l.getEntries())if(!e.hadRecentInput)window.__metrics.cls+=e.value}).observe({type:'layout-shift',buffered:true})});
  for(const phase of ['before','after']){await pp.goto(origin+'/'+phase+'-open.html',{waitUntil:'networkidle'});for(let i=0;i<5;i++){await pp.reload({waitUntil:'networkidle'});await settle(pp);result.performance.push({phase,iteration:i+1,...await pp.evaluate(()=>window.__metrics)})}}await pc.close();
 }
 }finally{await b.close()}
}
if(result.errors.length)result.violations.push({errors:result.errors});if(result.contrast.some(x=>x.pairs.some(y=>y.ratio<y.minimum)))result.violations.push('contrast');fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify(result,null,2));console.log(JSON.stringify({screens:result.screens.length,checks:result.checks.length,violations:result.violations}));if(result.violations.length)process.exitCode=1;
})().catch(e=>{result.execution_error=e.stack;fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify(result,null,2));console.error(e);process.exitCode=1});
