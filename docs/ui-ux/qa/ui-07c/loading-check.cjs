const fs=require('node:fs'),path=require('node:path'),{chromium}=require(path.resolve('.tools/node_modules/playwright'));
(async()=>{
 const b=await chromium.launch({executablePath:'C:/Program Files/Google/Chrome/Application/chrome.exe',headless:true});
 try{
  const p=await b.newPage();await p.goto('http://127.0.0.1:8099/after-open.html',{waitUntil:'networkidle'});
  const checks=[
   {name:'delayed loading hidden at rest',passed:await p.locator('[wire\\:loading\\.delay]').isHidden()},
   {name:'all ordinary loading indicators hidden at rest',passed:await p.locator('[wire\\:loading]').evaluateAll(es=>es.length>0&&es.every(e=>getComputedStyle(e).display==='none'))},
   {name:'offline indicators hidden at rest',passed:await p.locator('[wire\\:offline]').isHidden()},
  ];
  fs.writeFileSync('docs/ui-ux/qa/ui-07c/loading-results.json',JSON.stringify({checks},null,2));console.log(JSON.stringify(checks));if(checks.some(x=>!x.passed))process.exitCode=1;
 }finally{await b.close()}
})().catch(e=>{console.error(e);process.exitCode=1});
