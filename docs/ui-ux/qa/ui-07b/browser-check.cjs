const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(path.resolve('.tools/node_modules/playwright'));
const origin='http://127.0.0.1:8098',out='docs/ui-ux/evidencias/ui-07b';
const reviewOnly=process.argv.includes('--submitted-only');
const result={scope:'Fixtures Blade estáticas, sin hidratación Livewire ni operaciones. Valores ficticios.',screens:[],checks:[],contrast:[],performance:[],engines:[],errors:[],violations:[]};
const check=(name,passed)=>{result.checks.push({name,passed:!!passed});if(!passed)result.violations.push(name)};
const sizes=[[2560,1440],[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[320,844]];
async function geometry(p){return p.evaluate(()=>({overflow:document.documentElement.scrollWidth>innerWidth+1,small:[...document.querySelectorAll('main button,main input,main select,main textarea')].filter(e=>e.getClientRects().length&&getComputedStyle(e).visibility!=='hidden').filter(e=>{let r=e.getBoundingClientRect();return r.width<43||r.height<43}).map(e=>e.id||e.textContent.trim())}))}
async function settle(p){await p.evaluate(()=>document.fonts.ready);await p.waitForTimeout(200)}
(async()=>{
for(const [engine,exe] of [['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe'],['edge','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']]){
 const b=await chromium.launch({executablePath:exe,headless:true});result.engines.push({engine,version:b.version()});
 try{
 const c=await b.newContext();await c.route('**/*',r=>{const q=r.request();if(q.method()!=='GET'||![origin+'/', 'https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.18'].some(x=>q.url().startsWith(x))){result.errors.push('Blocked '+q.method()+' '+new URL(q.url()).origin);return r.abort()}return r.continue()});
 const p=await c.newPage();p.on('pageerror',e=>result.errors.push(e.message));p.on('console',m=>{if(m.type()==='error')result.errors.push(m.text())});
 for(const [width,height] of engine==='chrome'?sizes:[[1366,768],[390,844]]){
  await p.setViewportSize({width,height});
  for(const state of reviewOnly?['submitted']:['list','create','edit','submitted','activate','error','empty']){
   await p.goto(origin+'/after-'+state+'.html',{waitUntil:'networkidle'});
   for(const theme of ['light','dark']){
    await p.selectOption('[data-ui-theme-select]',theme);await settle(p);
    const g=await geometry(p),screenshot=`${reviewOnly?'review-':''}${engine}-${state}-${theme}-${width}.png`;
    result.screens.push({engine,state,theme,width,height,screenshot,...g});if(g.overflow||g.small.length)result.violations.push({screenshot,...g});
    await p.screenshot({path:path.join(out,screenshot),fullPage:true});
   }
  }
 }
 if(engine==='chrome'&&!reviewOnly){
 await p.setViewportSize({width:1366,height:768});
 for(const phase of ['before','after']){await p.goto(origin+'/'+phase+'-list.html',{waitUntil:'networkidle'});await p.selectOption('[data-ui-theme-select]','light');await settle(p);await p.screenshot({path:path.join(out,phase+'-list-1366.png'),fullPage:true})}
 await p.goto(origin+'/after-submitted.html',{waitUntil:'networkidle'});
 check('submitted fieldset remains disabled',await p.locator('fieldset input,fieldset select,fieldset textarea').evaluateAll(es=>es.length===5&&es.every(e=>e.matches(':disabled'))));
 check('recovery action remains available',await p.locator('[wire\\:click="queryOperation"]').count()===1);
 check('native confirmation text preserved',await p.locator('[type=submit]').getAttribute('wire:confirm')==='¿Confirmas este cambio del catálogo de motivos?');
 await p.goto(origin+'/after-edit.html',{waitUntil:'networkidle'});
 check('stable code and operation disabled in edit',await p.locator('#reason-code').isDisabled()&&await p.locator('#reason-operation').isDisabled());
 check('editable name remains enabled',await p.locator('#reason-name').isEnabled());
 check('all visible form fields have labels',await p.locator('main input,main select,main textarea').evaluateAll(es=>es.every(e=>e.labels.length>0)));
 await p.locator('#reason-name').focus();check('keyboard focus has visible outline',await p.locator('#reason-name').evaluate(e=>getComputedStyle(e).outlineStyle!=='none'&&parseFloat(getComputedStyle(e).outlineWidth)>=2));
 await p.goto(origin+'/after-last-page.html',{waitUntil:'networkidle'});check('last page has disabled next and enabled previous',await p.locator('nav[aria-label="Paginación de motivos"] button').last().isDisabled()&&await p.locator('nav[aria-label="Paginación de motivos"] button').first().isEnabled());
 await p.goto(origin+'/after-error.html',{waitUntil:'networkidle'});
 for(const theme of ['light','dark']){
  await p.selectOption('[data-ui-theme-select]',theme);await settle(p);
  result.contrast.push({theme,pairs:await p.evaluate(()=>{
   const ctx=document.createElement('canvas').getContext('2d',{willReadFrequently:true}),rgb=v=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=v;ctx.fillRect(0,0,1,1);return [...ctx.getImageData(0,0,1,1).data].slice(0,3)},lum=v=>rgb(v).map(x=>x/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4).reduce((a,x,i)=>a+x*[.2126,.7152,.0722][i],0),bg=e=>{for(let n=e;n;n=n.parentElement){let v=getComputedStyle(n).backgroundColor;if(v!=='rgba(0, 0, 0, 0)'&&v!=='transparent')return v}return '#fff'},pair=(selector,prop='color',minimum=4.5)=>{let e=document.querySelector(selector),s=getComputedStyle(e),foreground=s[prop],background=bg(e),l=[lum(foreground),lum(background)].sort((a,b)=>b-a);return{selector,property:prop,foreground,background,ratio:(l[0]+.05)/(l[1]+.05),minimum}};
   return ['h1','header p','.ui-field','.ui-reasons-errors','.ui-button--primary','.ui-button--secondary','.ui-reasons-card code'].map(x=>pair(x)).concat([pair('#reason-name','borderTopColor',3)]);
  })});
 }
 await p.goto(origin+'/after-submitted.html',{waitUntil:'networkidle'});
 await p.locator('[wire\\:loading]').evaluate(e=>e.style.setProperty('display','block','important'));await p.locator('[wire\\:offline]').evaluate(e=>e.style.setProperty('display','block','important'));
 for(const theme of ['light','dark']){await p.selectOption('[data-ui-theme-select]',theme);await settle(p);await p.screenshot({path:path.join(out,'messages-'+theme+'.png'),fullPage:true})}
 check('loading and offline roles retained',await p.locator('[wire\\:loading]').getAttribute('role')==='status'&&await p.locator('[wire\\:offline]').getAttribute('role')==='alert');
 await p.selectOption('[data-ui-theme-select]','auto');await p.emulateMedia({colorScheme:'dark'});await p.waitForFunction(()=>document.documentElement.dataset.uiTheme==='dark');check('automatic theme follows dark system',true);
 await p.emulateMedia({reducedMotion:'reduce'});await p.waitForFunction(()=>document.documentElement.dataset.uiMotion==='reduced');check('reduced motion follows system',true);
 await p.setViewportSize({width:320,height:844});await p.goto(origin+'/after-edit.html',{waitUntil:'networkidle'});await p.evaluate(()=>document.documentElement.style.fontSize=parseFloat(getComputedStyle(document.documentElement).fontSize)*2+'px');check('font doubled reflows at 320px',!(await geometry(p)).overflow);await p.screenshot({path:path.join(out,'font-200-320.png'),fullPage:true});
 await p.addInitScript(()=>Object.defineProperty(window,'localStorage',{get(){throw new DOMException('Blocked','SecurityError')}}));await p.goto(origin+'/after-create.html',{waitUntil:'networkidle'});await p.selectOption('[data-ui-theme-select]','dark');check('blocked storage remains usable',await p.locator('html').getAttribute('data-ui-theme')==='dark'&&!(await geometry(p)).overflow);
 }
 await c.close();
 if(engine==='chrome'&&!reviewOnly){
 const c2=await b.newContext({viewport:{width:1366,height:768}}),p2=await c2.newPage();
 await p2.addInitScript(()=>{window.__metrics={lcp:null,cls:0};new PerformanceObserver(l=>{for(const e of l.getEntries())window.__metrics.lcp=e.startTime}).observe({type:'largest-contentful-paint',buffered:true});new PerformanceObserver(l=>{for(const e of l.getEntries())if(!e.hadRecentInput)window.__metrics.cls+=e.value}).observe({type:'layout-shift',buffered:true})});
 for(const phase of ['before','after']){await p2.goto(origin+'/'+phase+'-list.html',{waitUntil:'networkidle'});for(let i=0;i<5;i++){await p2.reload({waitUntil:'networkidle'});await settle(p2);result.performance.push({phase,iteration:i+1,...await p2.evaluate(()=>window.__metrics)})}}await c2.close();
 }
 }finally{await b.close()}
}
if(result.errors.length)result.violations.push({errors:result.errors});if(result.contrast.some(x=>x.pairs.some(y=>y.ratio<y.minimum)))result.violations.push('contrast');
fs.writeFileSync(path.join(out,reviewOnly?'submitted-review-results.json':'browser-results.json'),JSON.stringify(result,null,2));console.log(JSON.stringify({screens:result.screens.length,checks:result.checks.length,violations:result.violations}));if(result.violations.length)process.exitCode=1;
})().catch(e=>{result.execution_error=e.stack;fs.writeFileSync(path.join(out,'browser-results.json'),JSON.stringify(result,null,2));console.error(e);process.exitCode=1});
