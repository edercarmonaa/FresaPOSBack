/* Desde la raíz, con Playwright ya existente y servidor documental 8092 activo. */
const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(path.resolve('.tools/node_modules/playwright'));
const OUT=path.resolve('docs/ui-ux/evidencias/ui-02');fs.mkdirSync(OUT,{recursive:true});
const origin='http://127.0.0.1:8092';
const results={origin,started_at_utc:new Date().toISOString(),engines:[],screens:[],interactions:[],contrast:[],errors:[],console_errors:[],unexpected_requests:[],violations:[],headless:true,device_scale_factor:1,throttling:'none',axe:false,lighthouse:false,screen_reader:false};
const check=(name,passed,details={})=>{results.interactions.push({name,passed:!!passed,...details});if(!passed)results.violations.push({name,...details})};
async function prepare(context){
 await context.route('**/*',r=>{const q=r.request();if(!q.url().startsWith(origin+'/')||q.method()!=='GET'){results.unexpected_requests.push({url:q.url().split('?')[0],method:q.method()});return r.abort()}return r.continue()});
 context.on('page',p=>{p.on('pageerror',e=>results.errors.push(e.message));p.on('console',m=>{if(m.type()==='error')results.console_errors.push(m.text())})});
}
async function settle(p){await p.evaluate(()=>document.fonts.ready);await p.evaluate(()=>new Promise(r=>requestAnimationFrame(()=>requestAnimationFrame(r))))}
async function geometry(p){return p.evaluate(()=>{
 const visible=e=>!!e.getClientRects().length&&getComputedStyle(e).visibility!=='hidden';
 const controls=[...document.querySelectorAll('button,input,select,textarea')].filter(e=>visible(e)&&!e.disabled);
 const name=e=>e.getAttribute('aria-label')||[...(e.labels||[])].map(l=>l.textContent).join(' ')||e.textContent.trim();
 const ids=[...document.querySelectorAll('[id]')].map(e=>e.id);
 return {overflow:document.documentElement.scrollWidth>innerWidth,document_width:document.documentElement.scrollWidth,visible_h1:document.querySelectorAll('h1').length,duplicate_ids:ids.filter((v,i)=>ids.indexOf(v)!==i),unnamed_controls:controls.filter(e=>!name(e)).map(e=>e.id),undersized_controls:controls.filter(e=>{const b=e.getBoundingClientRect();return b.width<43.5||b.height<43.5}).map(e=>e.outerHTML.slice(0,150)),font:document.fonts.check('400 14px Inter'),theme:document.documentElement.dataset.uiTheme};
})}
async function contrast(p){return p.evaluate(()=>{
 const css=getComputedStyle(document.documentElement),canvas=document.createElement('canvas');canvas.width=canvas.height=1;const ctx=canvas.getContext('2d',{willReadFrequently:true});
 const rgb=v=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=v;ctx.fillRect(0,0,1,1);return [...ctx.getImageData(0,0,1,1).data].slice(0,3)};
 const lum=rgb=>rgb.map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4).reduce((a,v,i)=>a+v*[.2126,.7152,.0722][i],0);
 return [['Texto','--ui-text','--ui-surface',4.5],['Secundario','--ui-muted','--ui-surface',4.5],['Suave','--ui-muted','--ui-surface-soft',4.5],['Acción','--ui-on-primary','--ui-primary',4.5],['Selección','--ui-selected-text','--ui-selected',4.5],['Éxito','--ui-success','--ui-success-bg',4.5],['Advertencia','--ui-warning','--ui-warning-bg',4.5],['Error','--ui-error','--ui-error-bg',4.5],['Borde','--ui-control-border','--ui-surface',3],['Foco','--ui-focus','--ui-surface',3]].map(([name,fg,bg,minimum])=>{const foreground=css.getPropertyValue(fg).trim(),background=css.getPropertyValue(bg).trim(),ls=[lum(rgb(foreground)),lum(rgb(background))].sort((a,b)=>b-a),ratio=(ls[0]+.05)/(ls[1]+.05);return {name,foreground,background,minimum,ratio,passed:ratio>=minimum}});
})}
async function interactions(browser){
 const context=await browser.newContext({viewport:{width:1366,height:768},colorScheme:'light'});await prepare(context);const p=await context.newPage();await p.goto(origin);await settle(p);
 await p.emulateMedia({colorScheme:'dark'});await p.waitForFunction(()=>document.documentElement.dataset.uiTheme==='dark');check('Automático sigue sistema oscuro',await p.getAttribute('html','data-ui-theme')==='dark');
 await p.selectOption('#theme','light');await p.emulateMedia({colorScheme:'dark'});check('Selección explícita prevalece',await p.getAttribute('html','data-ui-theme')==='light');
 await p.reload();await settle(p);check('Tema persiste al recargar',await p.getAttribute('html','data-ui-theme')==='light');
 const stored=await p.evaluate(()=>JSON.parse(localStorage.getItem('fresapos.ui.visual.v1')));check('Solo persiste theme visual',Object.keys(stored).join(',')==='theme');
 await p.selectOption('#density','compact');const compact=await p.locator('tbody tr').first().evaluate(e=>e.getBoundingClientRect().height);await p.selectOption('#density','comfortable');const comfortable=await p.locator('tbody tr').first().evaluate(e=>e.getBoundingClientRect().height);check('Densidad respeta mínimo táctil y cambia filas',compact<comfortable,{compact,comfortable});
 await p.emulateMedia({reducedMotion:'reduce'});await settle(p);check('Movimiento reducido desactiva transición',await p.locator('.ui-button').first().evaluate(e=>getComputedStyle(e).transitionDuration.split(',').every(v=>parseFloat(v)===0)));
 await p.locator('.ui-button').first().focus();check('Foco de teclado visible',await p.locator('.ui-button').first().evaluate(e=>getComputedStyle(e).outlineStyle==='solid'&&parseFloat(getComputedStyle(e).outlineWidth)>=3));
 await p.locator('.ui-button').first().click();check('Botón de muestra solo explica',await p.locator('#catalog-notice').isVisible()&&p.url()===origin+'/');
 await p.setViewportSize({width:320,height:844});await p.evaluate(()=>document.documentElement.style.fontSize='28px');await settle(p);check('Texto al 200 por ciento refluye a 320',!(await geometry(p)).overflow);await p.screenshot({path:path.join(OUT,'chrome-text-200-320.png'),fullPage:true});
 await p.evaluate(()=>localStorage.setItem('fresapos.ui.visual.v1','{invalid'));await p.reload();await settle(p);check('JSON inválido vuelve a automático',await p.getAttribute('html','data-ui-theme-mode')==='auto');
 await p.evaluate(()=>localStorage.setItem('fresapos.ui.visual.v1',JSON.stringify({theme:'unknown',token:'synthetic-must-disappear'})));await p.reload();await settle(p);check('Valores y claves ajenas se descartan',await p.evaluate(()=>localStorage.getItem('fresapos.ui.visual.v1')==='{"theme":"auto"}'));
 await context.close();
 const blocked=await browser.newContext();await prepare(blocked);await blocked.addInitScript(()=>{for(const name of ['getItem','setItem','removeItem'])Object.defineProperty(Storage.prototype,name,{value(){throw new DOMException('Blocked','SecurityError')}})});const b=await blocked.newPage();await b.goto(origin);await settle(b);await b.selectOption('#theme','dark');check('Storage bloqueado permite tema en memoria',await b.getAttribute('html','data-ui-theme')==='dark');await blocked.close();
}
(async()=>{
 for(const [engine,executablePath] of [['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe'],['edge','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']]){
  const browser=await chromium.launch({headless:true,executablePath});results.engines.push({engine,version:browser.version()});
  try{
   for(const theme of ['light','dark']){
    const context=await browser.newContext({colorScheme:theme,deviceScaleFactor:1});await prepare(context);const p=await context.newPage();await p.goto(origin);await p.selectOption('#theme',theme);await settle(p);const pairs=await contrast(p);results.contrast.push({engine,theme,pairs});for(const pair of pairs)if(!pair.passed)results.violations.push({engine,theme,contrast:pair});
    for(const [width,height] of engine==='chrome'?[[2560,1440],[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[320,844]]:[[1366,768],[390,844]]){
     await p.setViewportSize({width,height});await settle(p);await p.evaluate(()=>document.activeElement instanceof HTMLElement&&document.activeElement.blur());const state=await geometry(p),screenshot=`${engine}-catalog-${theme}-${width}x${height}.png`;await p.screenshot({path:path.join(OUT,screenshot),fullPage:true});results.screens.push({engine,theme,width,height,screenshot,...state});if(state.overflow||state.visible_h1!==1||state.duplicate_ids.length||state.unnamed_controls.length||state.undersized_controls.length||!state.font)results.violations.push({engine,theme,width,height,state});
    }await context.close();
   }
   if(engine==='chrome')await interactions(browser);
  }finally{await browser.close()}
 }
 results.completed_at_utc=new Date().toISOString();if(results.errors.length||results.console_errors.length||results.unexpected_requests.length)results.violations.push({errors:results.errors,console_errors:results.console_errors,requests:results.unexpected_requests});fs.writeFileSync(path.join(OUT,'browser-results.json'),JSON.stringify(results,null,2)+'\n');console.log(JSON.stringify({screens:results.screens.length,interactions:results.interactions.length,violations:results.violations}));if(results.violations.length)process.exitCode=1;
})().catch(e=>{results.execution_error=e.message;fs.writeFileSync(path.join(OUT,'browser-results.json'),JSON.stringify(results,null,2)+'\n');console.error(e);process.exitCode=1});
