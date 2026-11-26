const fs=require('node:fs'), path=require('node:path'), assert=require('node:assert/strict');
const {chromium}=require(path.resolve('.tools/node_modules/playwright'));
const OUT=path.resolve('docs/ui-ux/evidencias/ui-01');fs.mkdirSync(OUT,{recursive:true});
const views=['reportes','productos','pos','acceso','componentes'];
const widths=[[2560,1440],[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[320,844]];
const result={started_at_utc:new Date().toISOString(),prototype_origin:'http://127.0.0.1:8091',device_scale_factor:1,throttling:'none',engines:[],screens:[],interactions:[],violations:[],page_errors:[],console_errors:[],unexpected_requests:[],contrast:[],field_metrics:false,axe_executed:false,lighthouse_executed:false,screen_reader_tested:false};
const check=(name,condition,details={})=>{result.interactions.push({name,passed:!!condition,...details});if(!condition)result.violations.push({name,...details})};
async function prepare(context){
 await context.route('**/*',route=>{const r=route.request();if(!r.url().startsWith(result.prototype_origin+'/')||r.method()!=='GET'){result.unexpected_requests.push({url:r.url().split('?')[0],method:r.method()});return route.abort();}return route.continue();});
 context.on('page',p=>{p.on('pageerror',e=>result.page_errors.push(e.name+': '+e.message));p.on('console',m=>{if(m.type()==='error')result.console_errors.push(m.text().slice(0,400));});});
}
async function settle(page){await page.evaluate(()=>document.fonts.ready);await page.evaluate(()=>new Promise(resolve=>requestAnimationFrame(()=>requestAnimationFrame(resolve))));}
async function geometry(page){return page.evaluate(()=>{
 const visible=e=>!!(e.getClientRects().length&&getComputedStyle(e).visibility!=='hidden');
 const controls=[...document.querySelectorAll('button,input:not([type=hidden]),select,textarea,a[href]')].filter(e=>visible(e)&&!e.disabled);
 const name=e=>e.getAttribute('aria-label')||e.getAttribute('aria-labelledby')||[...(e.labels||[])].map(n=>n.textContent.trim()).join(' ')||e.textContent.trim();
 const ids=[...document.querySelectorAll('[id]')].map(e=>e.id);
 return {viewport:{width:innerWidth,height:innerHeight},document_width:document.documentElement.scrollWidth,overflow:document.documentElement.scrollWidth>innerWidth,
   visible_h1:[...document.querySelectorAll('h1')].filter(visible).length,unnamed_controls:controls.filter(e=>!name(e)).map(e=>({tag:e.tagName,id:e.id})),
   duplicate_ids:ids.filter((id,i)=>ids.indexOf(id)!==i),theme:document.documentElement.dataset.theme,font:document.fonts.check('400 14px Inter'),
   undersized_controls:controls.filter(e=>e.tagName!=='A').filter(e=>{const r=e.getBoundingClientRect();return r.width<43.5||r.height<43.5}).map(e=>({tag:e.tagName,id:e.id,name:name(e).slice(0,60),width:e.getBoundingClientRect().width,height:e.getBoundingClientRect().height})),
   title:document.title};
 });}
async function contrast(page){return page.evaluate(()=>{
 const css=getComputedStyle(document.documentElement),c=document.createElement('canvas');c.width=c.height=1;const ctx=c.getContext('2d',{willReadFrequently:true});
 const rgb=v=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=v;ctx.fillRect(0,0,1,1);return [...ctx.getImageData(0,0,1,1).data].slice(0,3)};
 const l=rgb=>rgb.map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4).reduce((s,v,i)=>s+v*[.2126,.7152,.0722][i],0);
 const value=n=>css.getPropertyValue(n).trim();
 return [['Texto','--text','--surface',4.5],['Secundario','--muted','--surface',4.5],['Secundario suave','--muted','--surface-soft',4.5],['Acción','--on-primary','--primary',4.5],['Seleccionado','--selected-text','--selected',4.5],['Éxito','--success','--success-bg',4.5],['Advertencia','--warning','--warning-bg',4.5],['Error','--error','--error-bg',4.5],['Borde control','--line-strong','--surface',3],['Foco','--focus','--surface',3]].map(([name,fg,bg,minimum])=>{const lum=[l(rgb(value(fg))),l(rgb(value(bg)))].sort((a,b)=>b-a);const ratio=(lum[0]+.05)/(lum[1]+.05);return {name,foreground:value(fg),background:value(bg),ratio,minimum,passed:ratio>=minimum};});
 });}
async function interactions(browser){
 const context=await browser.newContext({viewport:{width:1366,height:768},colorScheme:'light'});await prepare(context);const p=await context.newPage();
 await p.goto(result.prototype_origin+'/#productos');await settle(p);
 await p.selectOption('#prototype-theme','auto');await p.emulateMedia({colorScheme:'dark'});await p.waitForFunction(()=>document.documentElement.dataset.theme==='dark');check('Automático sigue SO oscuro',await p.getAttribute('html','data-theme')==='dark');
 await p.emulateMedia({colorScheme:'light'});await p.waitForFunction(()=>document.documentElement.dataset.theme==='light');check('Automático sigue cambio SO claro',await p.getAttribute('html','data-theme')==='light');
 await p.selectOption('#prototype-theme','dark');await p.emulateMedia({colorScheme:'light'});check('Elección explícita prevalece al SO',await p.getAttribute('html','data-theme')==='dark');
 await p.locator('#sidebar-toggle').click();const collapsed=await p.getAttribute('html','data-sidebar');await p.reload();await settle(p);check('Menú colapsado persiste',collapsed==='collapsed'&&await p.getAttribute('html','data-sidebar')==='collapsed');
 await p.locator('[data-open=preferences]').first().click();check('Preferencias abre dialog nativo',await p.locator('#preferences').evaluate(e=>e.open));
 await p.selectOption('#preference-density','compact');await p.keyboard.press('Escape');const compact=await p.locator('#catalog-current tbody tr').first().evaluate(e=>e.getBoundingClientRect().height);
 await p.locator('[data-open=preferences]').first().click();await p.selectOption('#preference-density','comfortable');await p.keyboard.press('Escape');const comfortable=await p.locator('#catalog-current tbody tr').first().evaluate(e=>e.getBoundingClientRect().height);check('Densidad cambia solo geometría',compact<comfortable,{compact,comfortable});
 await p.locator('[data-open=preferences]').first().click();await p.selectOption('#preference-text','large');await p.selectOption('#preference-motion','auto');await p.emulateMedia({reducedMotion:'reduce'});await p.waitForFunction(()=>document.documentElement.dataset.motion==='reduced');check('Reducción del SO respetada',await p.getAttribute('html','data-motion')==='reduced');await p.keyboard.press('Escape');
 await p.setViewportSize({width:320,height:844});await settle(p);check('Texto ampliado a 320 sin overflow',!(await geometry(p)).overflow);
 await p.locator('[data-open=nav-drawer]').click();let trapped=true;for(let i=0;i<16;i++){await p.keyboard.press('Tab');trapped&&=await p.locator('#nav-drawer').evaluate(e=>e.contains(document.activeElement));}check('Drawer conserva foco',trapped);await p.keyboard.press('Escape');check('Escape devuelve foco al disparador',await p.locator('[data-open=nav-drawer]').evaluate(e=>e===document.activeElement));
 await p.selectOption('#prototype-view','pos');await p.locator('#show-ticket').click();check('Resumen móvil abre dialog',await p.locator('#ticket-dialog').evaluate(e=>e.open));trapped=true;for(let i=0;i<16;i++){await p.keyboard.press('Tab');trapped&&=await p.locator('#ticket-dialog').evaluate(e=>e.contains(document.activeElement));}check('Resumen móvil conserva foco',trapped);await p.screenshot({path:path.join(OUT,'chrome-pos-ticket-dark-large-320.png'),fullPage:true});await p.keyboard.press('Escape');check('Resumen devuelve foco y aria-expanded',await p.locator('#show-ticket').evaluate(e=>e===document.activeElement&&e.getAttribute('aria-expanded')==='false'));
 await p.selectOption('#prototype-view','componentes');await p.locator('[data-open=confirmation]').click();check('Confirmación crítica no ejecutable',await p.locator('#confirmation .button.danger').isDisabled());await p.keyboard.press('Escape');
 await p.locator('[data-open=preferences]').first().click();await p.locator('#reset-preferences').click();await p.keyboard.press('Escape');const stored=await p.evaluate(()=>JSON.parse(localStorage.getItem('fresapos.ui01.visual.v1')));check('Storage contiene solo cinco preferencias visuales',Object.keys(stored).sort().join(',')==='density,motion,sidebar,text,theme');
 await p.evaluate(()=>localStorage.setItem('fresapos.ui01.visual.v1','{invalid'));await p.reload();await settle(p);check('JSON inválido usa defaults',await p.getAttribute('html','data-theme')==='light');
 await p.evaluate(()=>localStorage.setItem('fresapos.ui01.visual.v1',JSON.stringify({theme:'<script>',density:'unknown',password:'should-not-persist'})));await p.reload();await settle(p);const sanitized=await p.evaluate(()=>JSON.parse(localStorage.getItem('fresapos.ui01.visual.v1')));check('Valores inválidos y claves ajenas descartados',sanitized.theme==='auto'&&sanitized.density==='standard'&&!('password'in sanitized));
 await p.selectOption('#prototype-view','productos');await p.locator('[data-catalog-tab=current]').focus();await p.keyboard.press('ArrowRight');check('Pestañas permiten flechas y estado vacío',await p.locator('#catalog-trash').isVisible());
 await p.selectOption('#prototype-view','acceso');check('Acceso solo lectura',await p.locator('#demo-username').evaluate(e=>e.readOnly)&&await p.locator('#demo-password').evaluate(e=>e.readOnly));await p.locator('.auth-form').evaluate(e=>e.requestSubmit());check('Formulario no navega',p.url().startsWith(result.prototype_origin+'/'));
 await context.close();
 const blocked=await browser.newContext({viewport:{width:390,height:844}});await prepare(blocked);await blocked.addInitScript(()=>{Object.defineProperty(Storage.prototype,'getItem',{value(){throw new DOMException('Blocked','SecurityError')}});Object.defineProperty(Storage.prototype,'setItem',{value(){throw new DOMException('Blocked','SecurityError')}});Object.defineProperty(Storage.prototype,'removeItem',{value(){throw new DOMException('Blocked','SecurityError')}});});const pb=await blocked.newPage();await pb.goto(result.prototype_origin+'/#reportes');await settle(pb);await pb.selectOption('#prototype-theme','dark');check('Storage bloqueado permite tema en memoria',await pb.getAttribute('html','data-theme')==='dark');await blocked.close();
}
(async()=>{
 for(const [engine,executable] of [['chrome','C:/Program Files/Google/Chrome/Application/chrome.exe'],['edge','C:/Program Files (x86)/Microsoft/Edge/Application/msedge.exe']]){
  const browser=await chromium.launch({headless:true,executablePath:executable});result.engines.push({engine,version:browser.version(),headless:true});
  try{
   for(const theme of ['light','dark']){
    const context=await browser.newContext({viewport:{width:1366,height:768},deviceScaleFactor:1,colorScheme:theme});await prepare(context);const p=await context.newPage();await p.goto(result.prototype_origin+'/#reportes');await p.selectOption('#prototype-theme',theme);await settle(p);result.contrast.push({engine,theme,pairs:await contrast(p)});
    for(const [width,height] of engine==='chrome'?widths:[[1366,768],[390,844]]){
     await p.setViewportSize({width,height});
     for(const view of views){await p.goto(result.prototype_origin+'/#'+view);await settle(p);await p.evaluate(()=>document.activeElement instanceof HTMLElement&&document.activeElement.blur());const state=await geometry(p);const screenshot=`${engine}-${view}-${theme}-${width}x${height}.png`;await p.screenshot({path:path.join(OUT,screenshot),fullPage:true});result.screens.push({engine,view,theme,width,height,screenshot,...state});if(state.overflow||state.duplicate_ids.length||state.unnamed_controls.length||state.visible_h1!==1||state.undersized_controls.length||!state.font)result.violations.push({engine,view,theme,width,height,state});}
    }await context.close();
   }
   if(engine==='chrome')await interactions(browser);
  }finally{await browser.close()}
 }
 result.completed_at_utc=new Date().toISOString();for(const g of result.contrast)for(const pair of g.pairs)if(!pair.passed)result.violations.push({contrast:pair,engine:g.engine,theme:g.theme});
 if(result.page_errors.length||result.console_errors.length||result.unexpected_requests.length)result.violations.push({page_errors:result.page_errors,console_errors:result.console_errors,requests:result.unexpected_requests});
 fs.writeFileSync(path.join(OUT,'browser-results.json'),JSON.stringify(result,null,2)+'\n');console.log(JSON.stringify({screens:result.screens.length,interactions:result.interactions.length,violations:result.violations.length,errors:result.page_errors,console_errors:result.console_errors}));if(result.violations.length)process.exitCode=1;
})().catch(e=>{result.execution_error=e.message;fs.writeFileSync(path.join(OUT,'browser-results.json'),JSON.stringify(result,null,2)+'\n');console.error(e);process.exitCode=1});
