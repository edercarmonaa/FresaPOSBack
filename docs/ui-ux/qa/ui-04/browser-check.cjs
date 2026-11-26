/* QA de Auth: GET de /login y fixtures aisladas. No envía credenciales ni operaciones. */
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(path.resolve('.tools/node_modules/playwright'));
const out='docs/ui-ux/evidencias/ui-04';
const checksOnly=process.argv.includes('--checks-only');
const actual='http://127.0.0.1:8080',fixture='http://127.0.0.1:8094';
const result={started_at:new Date().toISOString(),engines:[],screens:[],checks:[],violations:[],errors:[],performance:[],authenticated_browser_e2e:false,axe:false,screen_reader:false};
const check=(name,passed,detail)=>{result.checks.push({name,passed:!!passed,detail});if(!passed)result.violations.push({name,detail})};
async function prepare(c){await c.route('**/*',r=>{const q=r.request();if(q.method()!=='GET'||![actual+'/',fixture+'/','https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4.1.18'].some(x=>q.url().startsWith(x))){result.errors.push('Unexpected request: '+q.method()+' '+new URL(q.url()).origin);return r.abort()}return r.continue()});c.on('page',p=>{p.on('pageerror',e=>result.errors.push(e.message));p.on('console',m=>{if(m.type()==='error')result.errors.push(m.text().slice(0,200))})})}
async function settle(p){await p.evaluate(()=>document.fonts.ready);await p.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))))}
async function geometry(p){return p.evaluate(()=>{
 const visible=e=>!!e.getClientRects().length&&getComputedStyle(e).visibility!=='hidden';
 const controls=[...document.querySelectorAll('input:not([type=hidden]),button,select,a[href]')].filter(visible);
 const ids=[...document.querySelectorAll('[id]')].map(e=>e.id);
 const missing=controls.filter(e=>!(e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||[...(e.labels||[])].some(l=>l.textContent.trim())||e.textContent.trim())).map(e=>e.outerHTML.slice(0,180));
 const small=controls.filter(e=>{let r=e.getBoundingClientRect();return r.width<43.5||r.height<43.5}).map(e=>({tag:e.tagName,text:e.textContent.trim().slice(0,40)}));
 return {overflow:document.documentElement.scrollWidth>innerWidth+1,duplicate_ids:ids.filter((id,i)=>ids.indexOf(id)!==i),missing_names:missing,small_controls:small,h1:[...document.querySelectorAll('h1')].filter(visible).length,font:document.fonts.check('16px Inter'),theme:document.documentElement.dataset.uiTheme};
 })}
(async()=>{
 for(const [engine,executablePath,widths] of [
  ['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe',[[2560,1440],[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[320,844]]],
  ['edge','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe',[[1366,768],[390,844]]]]){
  if(checksOnly&&engine==='edge')continue;
  const b=await chromium.launch({executablePath,headless:true});result.engines.push({engine,version:b.version()});
  try{const c=await b.newContext({viewport:{width:1366,height:768}});await prepare(c);const p=await c.newPage();
   if(!checksOnly)for(const theme of ['light','dark'])for(const [width,height] of widths){await p.setViewportSize({width,height});for(const name of ['real-login','login','password','account','admin']){
    const url=name==='real-login'?actual+'/login':fixture+'/after-'+name+'.html';await p.goto(url,{waitUntil:'networkidle'});await p.selectOption('[data-ui-theme-select]',theme);await settle(p);
    const state=await geometry(p),screenshot=`${engine}-${name}-${theme}-${width}x${height}.png`;await p.screenshot({path:path.join(out,screenshot),fullPage:true});result.screens.push({engine,name,theme,width,height,screenshot,...state});
    if(state.overflow||state.duplicate_ids.length||state.missing_names.length||state.small_controls.length||state.h1!==1||!state.font)result.violations.push({engine,name,theme,width,height,state});
   }}
   if(engine==='chrome'){
    if(!checksOnly)for(const width of [1366,390])for(const name of ['login','password','account','admin']){await p.setViewportSize({width,height:844});await p.goto(fixture+'/before-'+name+'.html',{waitUntil:'networkidle'});await p.selectOption('[data-ui-theme-select]','light');await settle(p);await p.screenshot({path:path.join(out,`before-${name}-${width}.png`),fullPage:true})}
    await p.setViewportSize({width:390,height:844});await p.goto(actual+'/login',{waitUntil:'networkidle'});
    check('native required validation',await p.locator('form[wire\\:submit="login"]').evaluate(f=>!f.checkValidity()));
    await p.locator('#username').focus();await p.keyboard.press('Tab');check('keyboard reaches password',await p.locator('#password').evaluate(e=>document.activeElement===e));
    const outline=await p.locator('#password').evaluate(e=>{const s=getComputedStyle(e);return {style:s.outlineStyle,width:s.outlineWidth}});check('keyboard focus visible',outline.style!=='none'&&parseFloat(outline.width)>=2,outline);
    await p.locator('#password').fill('UI04-NON-CREDENTIAL');check('password remains masked',await p.locator('#password').getAttribute('type')==='password');await p.locator('#password').fill('');
    await p.emulateMedia({colorScheme:'dark'});await p.selectOption('[data-ui-theme-select]','auto');check('automatic theme follows OS dark',await p.locator('html').getAttribute('data-ui-theme')==='dark');
    await p.goto(fixture+'/after-account.html',{waitUntil:'networkidle'});await p.locator('.ui-auth-table').focus();await p.keyboard.press('ArrowRight');await p.waitForTimeout(100);check('session table keyboard scroll',await p.locator('.ui-auth-table').evaluate(e=>e.scrollLeft>0));
    await p.evaluate(()=>FresaUIPreferences.set('text','large'));await settle(p);check('large text no page overflow',!(await geometry(p)).overflow);
    await p.evaluate(()=>FresaUIPreferences.set('density','comfortable'));check('table density preference',await p.locator('td').first().evaluate(e=>getComputedStyle(e).paddingTop)==='22px');
    await p.emulateMedia({reducedMotion:'reduce'});await p.waitForFunction(()=>document.documentElement.dataset.uiMotion==='reduced');check('system reduced motion retained',await p.locator('html').getAttribute('data-ui-motion')==='reduced');
    const blocked=await b.newContext({viewport:{width:320,height:844}});await prepare(blocked);await blocked.addInitScript(()=>{Object.defineProperty(window,'localStorage',{get(){throw new DOMException('Blocked','SecurityError')}})});const bp=await blocked.newPage();await bp.goto(actual+'/login',{waitUntil:'networkidle'});await bp.selectOption('[data-ui-theme-select]','dark');check('blocked storage fallback',await bp.locator('html').getAttribute('data-ui-theme')==='dark'&&!(await geometry(bp)).overflow);await blocked.close();
    await p.setViewportSize({width:1366,height:768});await p.emulateMedia({colorScheme:'light',reducedMotion:'no-preference'});await p.evaluate(()=>FresaUIPreferences.reset());
    await p.addInitScript(()=>{window.__ui04metrics={cls:0,lcp:null};new PerformanceObserver(l=>{for(const e of l.getEntries())window.__ui04metrics.lcp=e.startTime}).observe({type:'largest-contentful-paint',buffered:true});new PerformanceObserver(l=>{for(const e of l.getEntries())if(!e.hadRecentInput)window.__ui04metrics.cls+=e.value}).observe({type:'layout-shift',buffered:true})});
    for(const phase of ['before','after']){await p.goto(fixture+'/'+phase+'-login.html',{waitUntil:'networkidle'});for(let i=0;i<5;i++){await p.reload({waitUntil:'networkidle'});await settle(p);result.performance.push({phase,iteration:i+1,...await p.evaluate(()=>window.__ui04metrics)})}}
   }await c.close();
  }finally{await b.close()}
 }
 result.completed_at=new Date().toISOString();if(result.errors.length)result.violations.push({errors:result.errors});fs.writeFileSync(path.join(out,checksOnly?'browser-checks-final.json':'browser-results.json'),JSON.stringify(result,null,2));console.log(JSON.stringify({screens:result.screens.length,checks:result.checks.length,violations:result.violations}));if(result.violations.length)process.exitCode=1;
})().catch(e=>{result.execution_error=e.stack;fs.writeFileSync(path.join(out,checksOnly?'browser-checks-final.json':'browser-results.json'),JSON.stringify(result,null,2));console.error(e);process.exitCode=1});
