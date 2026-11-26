const fs=require('node:fs'),path=require('node:path');
const {chromium}=require(path.resolve('.tools/node_modules/playwright'));
const output={recorded_at_utc:new Date().toISOString(),conditions:{browser:'Chrome installed, headless',viewport:'1366x768',scale:1,throttling:'none',sql_suite_running:true,scope:'public login laboratory, warm session n=5; no real INP or Lighthouse'},performance:[],contrast:[],checks:[]};
const passed=(name,value)=>output.checks.push({name,passed:!!value});
(async()=>{const b=await chromium.launch({headless:true,executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe'});try{
 const c=await b.newContext({viewport:{width:1366,height:768}});const p=await c.newPage();
 await p.goto('http://127.0.0.1:8080/');await p.keyboard.press('Tab');await p.keyboard.press('Enter');passed('Saltar al contenido enfoca main',await p.locator('#ui-main').evaluate(e=>e===document.activeElement));passed('Foco main visible',await p.locator('#ui-main').evaluate(e=>getComputedStyle(e).outlineStyle==='solid'));
 await p.addInitScript(()=>{
  window.ui03Metrics={lcp:0,cls:0};
  new PerformanceObserver(list=>{for(const e of list.getEntries())window.ui03Metrics.lcp=e.startTime}).observe({type:'largest-contentful-paint',buffered:true});
  new PerformanceObserver(list=>{for(const e of list.getEntries())if(!e.hadRecentInput)window.ui03Metrics.cls+=e.value}).observe({type:'layout-shift',buffered:true});
 });
 for(let i=0;i<5;i++){await p.goto('http://127.0.0.1:8080/login',{waitUntil:'networkidle'});await p.evaluate(()=>document.fonts.ready);output.performance.push(await p.evaluate(()=>({...window.ui03Metrics,dom_content_loaded:performance.getEntriesByType('navigation')[0].domContentLoadedEventEnd})));}
 for(const theme of ['light','dark']){
  await p.goto('http://127.0.0.1:8093/admin.html',{waitUntil:'networkidle'});await p.selectOption('[data-ui-theme-select]',theme);
  const pairs=await p.evaluate(()=>{
   const canvas=document.createElement('canvas');canvas.width=canvas.height=1;const ctx=canvas.getContext('2d',{willReadFrequently:true});
   const rgb=v=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=v;ctx.fillRect(0,0,1,1);return [...ctx.getImageData(0,0,1,1).data].slice(0,3)};
   const l=rgb=>rgb.map(v=>v/255).map(v=>v<=.04045?v/12.92:((v+.055)/1.055)**2.4).reduce((a,v,i)=>a+v*[.2126,.7152,.0722][i],0);
   const color=e=>{for(let n=e;n;n=n.parentElement){const bg=getComputedStyle(n).backgroundColor;if(bg!=='rgba(0, 0, 0, 0)'&&bg!=='transparent')return bg}return '#fff'};
   return [['Navegación',document.querySelector('.ui-nav-link')],['Navegación activa',document.querySelector('.ui-nav-link[aria-current]')],['Marca',document.querySelector('.ui-brand')]].map(([name,e])=>{const foreground=getComputedStyle(e).color,background=color(e),lum=[l(rgb(foreground)),l(rgb(background))].sort((a,b)=>b-a),ratio=(lum[0]+.05)/(lum[1]+.05);return {name,foreground,background,ratio,minimum:4.5,passed:ratio>=4.5}});
  });output.contrast.push({theme,pairs});
 }
 await p.setViewportSize({width:320,height:844});await p.goto('http://127.0.0.1:8093/admin.html');await p.locator('[data-ui-menu-toggle]').click();await p.keyboard.press('Escape');passed('Enlace de salto no queda oculto por header',await p.locator('#ui-main').evaluate(e=>parseFloat(getComputedStyle(e).scrollMarginTop)>=72));
 output.browser_version=b.version();output.lcp_summary={min:Math.min(...output.performance.map(x=>x.lcp)),median:output.performance.map(x=>x.lcp).sort((a,b)=>a-b)[2],max:Math.max(...output.performance.map(x=>x.lcp))};
 fs.writeFileSync('docs/ui-ux/qa/ui-03/additional-results.json',JSON.stringify(output,null,2)+'\n');if(output.checks.some(x=>!x.passed)||output.contrast.some(x=>x.pairs.some(y=>!y.passed)))process.exitCode=1;console.log(JSON.stringify(output));
}finally{await b.close()}})().catch(e=>{console.error(e.message);process.exitCode=1});
