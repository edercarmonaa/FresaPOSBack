const fs=require('node:fs'),path=require('node:path'),{chromium}=require(path.resolve('.tools/node_modules/playwright'));
const result={checks:[],contrast:[]},out='docs/ui-ux/evidencias/ui-07b',check=(name,passed)=>result.checks.push({name,passed:!!passed});
(async()=>{
const b=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
try{
 const p=await b.newPage({viewport:{width:390,height:844}});await p.goto('http://127.0.0.1:8098/after-submitted.html',{waitUntil:'networkidle'});
 await p.locator('[wire\\:loading]').evaluate(e=>e.style.setProperty('display','block','important'));await p.locator('[wire\\:offline]').evaluate(e=>e.style.setProperty('display','block','important'));
 for(const theme of ['light','dark']){
  await p.selectOption('[data-ui-theme-select]',theme);await p.waitForTimeout(200);
  result.contrast.push({theme,pairs:await p.evaluate(()=>{
   const ctx=document.createElement('canvas').getContext('2d',{willReadFrequently:true}),lum=v=>{ctx.clearRect(0,0,1,1);ctx.fillStyle=v;ctx.fillRect(0,0,1,1);return [...ctx.getImageData(0,0,1,1).data].slice(0,3).map(x=>x/255).map(x=>x<=.04045?x/12.92:((x+.055)/1.055)**2.4).reduce((a,x,i)=>a+x*[.2126,.7152,.0722][i],0)};
   return ['.ui-reasons-status','.ui-reasons-pending','.ui-reasons-offline'].map(selector=>{const s=getComputedStyle(document.querySelector(selector)),l=[lum(s.color),lum(s.backgroundColor)].sort((a,b)=>b-a);return{selector,foreground:s.color,background:s.backgroundColor,ratio:(l[0]+.05)/(l[1]+.05),minimum:4.5}});
  })});
 }
 await p.goto('http://127.0.0.1:8098/after-edit.html',{waitUntil:'networkidle'});await p.locator('#reason-name').focus();await p.keyboard.press('Tab');check('Tab reaches description after enabled name',await p.locator('#reason-description').evaluate(e=>e===document.activeElement));
 check('summary error retains live role',await p.goto('http://127.0.0.1:8098/after-error.html',{waitUntil:'networkidle'}).then(()=>p.locator('.ui-reasons-errors').getAttribute('role'))==='alert');
 await p.setViewportSize({width:320,height:844});await p.evaluate(()=>{document.querySelector('.ui-reasons-card code').textContent='X'.repeat(64);document.querySelector('.ui-reasons-card h2').textContent='N'.repeat(150);document.querySelectorAll('.ui-reasons-card p')[1].textContent='D'.repeat(500)});
 check('long values reflow at 320',await p.evaluate(()=>document.documentElement.scrollWidth<=innerWidth+1));await p.screenshot({path:path.join(out,'long-values-320.png'),fullPage:true});
}finally{await b.close()}
check('all message contrast pairs pass',result.contrast.every(x=>x.pairs.every(y=>y.ratio>=y.minimum)));fs.writeFileSync('docs/ui-ux/qa/ui-07b/additional-results.json',JSON.stringify(result,null,2));console.log(JSON.stringify(result.checks));if(result.checks.some(x=>!x.passed))process.exitCode=1;
})().catch(e=>{console.error(e);process.exitCode=1});
