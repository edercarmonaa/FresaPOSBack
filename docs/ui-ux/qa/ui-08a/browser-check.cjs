const fs=require('node:fs'),path=require('node:path'),{chromium}=require(path.resolve('.tools/node_modules/playwright'));
const origin='http://127.0.0.1:8101',out='docs/ui-ux/evidencias/ui-08a';
const result={scope:'Fixtures Blade ficticias, sin SQL ni hidrataci?n Livewire; Alpine real para el panel m?vil; no se ejecutan acciones de negocio.',engines:[],screens:[],checks:[],contrast:[],performance:[],errors:[],violations:[]};
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
  for(const state of ['open','tables','empty']){
   await p.goto(origin+'/after-'+state+'.html',{waitUntil:'networkidle'});
   for(const theme of ['light','dark']){
    await p.selectOption('[data-ui-theme-select]',theme);await settle(p);
    const g=await geometry(p),screenshot=`${engine}-${state}-${theme}-${width}.png`;
    result.screens.push({engine,state,theme,width,height,screenshot,...g});if(g.overflow||g.small.length)result.violations.push({screenshot,...g});await p.screenshot({path:path.join(out,screenshot),fullPage:true});
    if(width<1024&&state==='open'){
     await p.locator('.pos-mobile-ticket').click();await settle(p);
     check(`${engine} ${theme} ${width} mobile opens dialog`,await p.locator('.pos-ticket').getAttribute('role')==='dialog'&&await p.locator('.pos-ticket').getAttribute('aria-modal')==='true');
     check(`${engine} ${theme} ${width} heading focus`,await p.locator('#pos-sale-title').evaluate(e=>e===document.activeElement));
     const panel=await geometry(p);check(`${engine} ${theme} ${width} panel geometry`,!panel.overflow&&!panel.small.length);
     await p.screenshot({path:path.join(out,`${engine}-panel-${theme}-${width}.png`),fullPage:true});
     await p.keyboard.press('Tab');check(`${engine} ${theme} ${width} focus stays in panel`,await p.evaluate(()=>document.querySelector('.pos-ticket').contains(document.activeElement)));
     await p.keyboard.press('Escape');await settle(p);check(`${engine} ${theme} ${width} Escape closes panel`,await p.locator('.pos-mobile-ticket').getAttribute('aria-expanded')==='false');
     await p.locator('.pos-mobile-ticket').click();await p.getByRole('button',{name:'Cerrar panel',exact:true}).click();await settle(p);check(`${engine} ${theme} ${width} close returns focus`,await p.locator('.pos-mobile-ticket').evaluate(e=>e===document.activeElement));
    }
   }
  }
 }
 if(engine==='chrome'){
  await p.setViewportSize({width:1366,height:768});await p.goto(origin+'/after-open.html',{waitUntil:'networkidle'});
  for(const theme of ['light','dark']){await p.selectOption('[data-ui-theme-select]',theme);await settle(p);result.contrast.push({state:'open',theme,pairs:await contrast(p,[['h1'],['.pos-muted'],['.pos-tabs [aria-pressed=true]'],['.pos-product button:not(:disabled)'],['.pos-total'],['.pos-label'],['#pos-search','borderTopColor',3]])});
   check(theme+' financial frame retains light background',await p.locator('.ui-pos-financial').evaluate(e=>getComputedStyle(e).backgroundColor==='rgb(255, 255, 255)'));
   check(theme+' catalog inherits selected theme',await p.locator('.pos-main .pos-card').first().evaluate((e,theme)=>getComputedStyle(e).backgroundColor===(theme==='dark'?'rgb(21, 31, 48)':'rgb(255, 255, 255)'),theme));
  }
  check('inactive table remains disabled',await (async()=>{await p.goto(origin+'/after-tables.html');return p.locator('.pos-inactive').isDisabled()})());
  await p.setViewportSize({width:390,height:844});
  for(const state of ['table-selected','paused','closed','cancelled','pending','stale','no-cash','no-critical','read-only','add-editor','item-editor','error','long']){
   await p.goto(origin+'/after-'+state+'.html',{waitUntil:'networkidle'});
   for(const theme of ['light','dark']){await p.selectOption('[data-ui-theme-select]',theme);await settle(p);if(await p.locator('.pos-mobile-ticket').isVisible()&&await p.locator('.pos-mobile-ticket').getAttribute('aria-expanded')!=='true'){await p.locator('.pos-mobile-ticket').click();await settle(p)}const g=await geometry(p);check(`${state} ${theme} reflow`,!g.overflow&&!g.small.length);await p.screenshot({path:path.join(out,`${state}-${theme}-390.png`),fullPage:true})}
  }
  await p.setViewportSize({width:320,height:844});await p.goto(origin+'/after-long.html',{waitUntil:'networkidle'});await p.evaluate(()=>document.documentElement.style.fontSize='200%');check('200 percent text at 320 reflows',!(await geometry(p)).overflow);await p.screenshot({path:path.join(out,'text-200-320.png'),fullPage:true});
  await p.emulateMedia({colorScheme:'dark',reducedMotion:'reduce'});await p.goto(origin+'/after-open.html',{waitUntil:'networkidle'});await p.selectOption('[data-ui-theme-select]','auto');check('automatic dark theme',await p.locator('html').getAttribute('data-ui-theme')==='dark');check('reduced motion',await p.locator('html').getAttribute('data-ui-motion')==='reduced');
  const blocked=await b.newContext({viewport:{width:320,height:844}});await prepare(blocked);await blocked.addInitScript(()=>Object.defineProperty(window,'localStorage',{get(){throw new DOMException('Blocked','SecurityError')}}));const bp=await blocked.newPage();await bp.goto(origin+'/after-open.html',{waitUntil:'networkidle'});await bp.selectOption('[data-ui-theme-select]','dark');check('blocked storage remains usable',await bp.locator('html').getAttribute('data-ui-theme')==='dark'&&!(await geometry(bp)).overflow);await blocked.close();
  await p.setViewportSize({width:1366,height:768});
  for(const phase of ['before','after']){await p.goto(origin+'/'+phase+'-open.html',{waitUntil:'networkidle'});await p.selectOption('[data-ui-theme-select]','light');await settle(p);await p.screenshot({path:path.join(out,phase+'-open-1366.png'),fullPage:true})}
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
