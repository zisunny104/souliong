// 隔離資料與模擬地圖引擎，驗證實際後台設定及前台 DOM。
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn,execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_PATH||'playwright');
(async()=>{
 const root=path.resolve(__dirname,'..'),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'souliong-display-')),port=24100+Math.floor(Math.random()*500),base='http://127.0.0.1:'+port;
 let server,browser;
 try {
  for(const dir of ['api','pages','assets','lang','layers','docs'])fs.cpSync(path.join(root,dir),path.join(tmp,dir),{recursive:true});
  for(const file of ['index.php','config.php'])fs.copyFileSync(path.join(root,file),path.join(tmp,file));
  fs.mkdirSync(path.join(tmp,'projects/test'),{recursive:true});fs.mkdirSync(path.join(tmp,'state'));
  fs.writeFileSync(path.join(tmp,'api/config.php'),'<?php $c=require '+JSON.stringify(path.join(root,'api/config.example.php'))+'; $c["projects_dir"]='+JSON.stringify(tmp+'/projects')+'; $c["state_dir"]='+JSON.stringify(tmp+'/state')+'; $c["primary_pin"]=""; $c["ip_salt"]="isolated-display-test"; return $c;');
  const meta={title:'測試地圖',features:{upload:true,contentEdit:false,delegation:false,identity:false,map3d:false},contributionAccess:{enabled:true,starts_at:null,expires_at:null},contrib:{kinds:['text'],newSpot:'contributor'}};
  fs.writeFileSync(tmp+'/projects/test/meta.json',JSON.stringify(meta));
  const spot={id:'spot1',kind:'spot',project:'test',num:1,item_num:1,title:'點位一',cat:'test',catLabel:'測試類別',color:'#aa3311',name:'管理者',lat:24,lon:120,content:[{id:'text1',kind:'text',comment:'初版'}],created_at:'2026-10-07T10:00:00Z'};
  const edit={...spot,id:'spot2',edit_of:'spot1',content:[{id:'text1',kind:'text',comment:'點位介紹'},{id:'audio1',kind:'audio',media:'sound.wav',comment:'聲音說明'}],created_at:'2026-10-07T11:00:00Z'};delete edit.num;
  const entry={id:'entry1',kind:'text',project:'test',item_num:1,name:'投稿者',license:'cc-by-nc',author_url:'https://example.com/author',comment:'原始投稿',created_at:'2026-10-07T10:00:00Z'};
  const entryEdit={...entry,id:'entry2',edit_of:'entry1',comment:'目前投稿',created_at:'2026-10-07T11:00:00Z'};
  fs.writeFileSync(tmp+'/projects/test/spots.jsonl',[spot,edit,entry,entryEdit].map(JSON.stringify).join('\n')+'\n');
  fs.writeFileSync(tmp+'/router.php','<?php $p=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); if(is_file(__DIR__.$p))return false; require __DIR__."/index.php";');
  server=spawn('php',['-S','127.0.0.1:'+port,'-t',tmp,tmp+'/router.php'],{stdio:'ignore'});
  for(let i=0;i<50;i++){try{await fetch(base);break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
  const context=await browser.newContext({timezoneId:'Asia/Taipei',locale:'zh-TW'}),page=await context.newPage();
  await context.addInitScript(()=>{window.maplibregl={};});
  const errors=[];page.on('pageerror',e=>errors.push(e.message));
  await context.route('**/*',async route=>{
   const url=new URL(route.request().url());if(url.origin!==base)return route.abort();
   if((url.searchParams.get('f')||url.pathname).endsWith('/maplibre-engine.js'))return route.fulfill({contentType:'application/javascript',body:'window.MapLibreEngine=class {constructor(){this.supportsSnapshot=false;} getCenter(){return {lat:24,lon:120};} getZoom(){return window.testZoom||14;} markerElements(){return new Map();} mountControls(){} onZoomEnd(fn){window.testZoomEnd=fn;} onZoomThresholdCross(z,fn){window.testZoomCross=fn;} fitBounds(){} setMarkerLayer(key,specs){(window.testLayers||=( {} ))[key]=specs;} clearMarkerLayer(key){(window.testLayers||=( {} ))[key]=[];} onBackgroundClick(){} panTo(){} applyTheme(){} setView(){} createMiniPicker(){return {onChange(){},setPosition(){},setDraggable(){},destroy(){}}}};'});
   return route.continue();
  });
  assert.ok(process.env.AXE_PATH,'AXE_PATH is required');
  const failures=[];
  async function audit(label){
   await page.addScriptTag({path:process.env.AXE_PATH});
   const result=await page.evaluate(()=>axe.run(document));
   const violations=result.violations.map(v=>({id:v.id,nodes:v.nodes.map(n=>({target:n.target,reason:n.failureSummary}))}));
   console.log(label,JSON.stringify(violations));failures.push({label,violations});
  }
  for (const width of [390,1280]) {
   await page.setViewportSize({width,height:900});
   for(const route of ['/','/privacy','/manager']){await page.goto(base+route);await audit(route+' '+width);if(route==='/manager'){await page.locator('#toAcctLogin').focus();await page.keyboard.press('Enter');await page.locator('#loginAcctFields').waitFor({state:'visible'});await audit('帳號登入 '+width);
    await page.locator('[name=userid]').first().fill('invalid-user');await page.locator('[name=pw]').first().fill('wrong-password');
    await Promise.all([page.waitForNavigation(),page.locator('#panel-login > button').click()]);
    assert.equal(await page.locator('#loginMode').inputValue(),'account');assert.equal(await page.locator('#loginAcctFields [name=userid]').inputValue(),'invalid-user');assert.equal(await page.locator('#loginAcctFields [name=pw]').inputValue(),'');await audit('帳號登入錯誤 '+width);
    await page.reload();assert.equal(await page.locator('#loginMode').inputValue(),'account');
    await page.goto(base+'/manager/test');assert.equal(await page.locator('#loginMode').inputValue(),'account');
    await page.locator('#toPinLogin').focus();await page.keyboard.press('Enter');await page.locator('#loginPinFields').waitFor({state:'visible'});assert.equal(await page.locator('#loginAcctFields [name=userid]').isDisabled(),true);await page.locator('#loginPinFields [name=pin]').fill('9999');await Promise.all([page.waitForNavigation(),page.locator('#panel-login > button').click()]);assert.equal(await page.locator('#loginMode').inputValue(),'pin');await audit('PIN 登入錯誤 '+width);assert.equal(await page.evaluate(()=>document.documentElement.scrollWidth <= innerWidth),true);}}
   await page.goto(base+'/test');await page.waitForSelector('#uploadBtn');await page.waitForTimeout(400);await audit('地圖 '+width);
   await page.evaluate(()=>MapApp.openPanel(MapApp.effectiveSpots()[0]));await page.waitForTimeout(350);await audit('點位 '+width);
   await page.evaluate(()=>MapApp.closePanel());await page.locator('#uploadBtn').click();await page.locator('#addMoreBtn').click();await audit('投稿 '+width);
   await page.locator('#queue [data-markdown-help]').click();await audit('Markdown '+width);await page.keyboard.press('Escape');
   await page.evaluate(()=>MapApp.closeModal());await page.locator('#createBtn').click();await page.locator('#spotAddMore').click();await audit('建立點位 '+width);
   await page.goto(base+'/test?embed=1&ui=submit');await page.locator('#contribModal.open').waitFor();await page.locator('#addMoreBtn').click();await audit('嵌入投稿 '+width);
   await page.goto(base+'/test?embed=1&ui=bare');await page.waitForFunction(()=>!!window.MapApp);await audit('純地圖 '+width);
  }
  const auth=JSON.parse(execFileSync('php',['-r','require '+JSON.stringify(tmp+'/api/security.php')+'; $cfg=require '+JSON.stringify(tmp+'/api/config.php')+'; $token=primary_session_issue($cfg,"cfg"); echo json_encode(["name"=>PRIMARY_COOKIE,"token"=>$token]);'],{encoding:'utf8'}));
  await context.addCookies([{name:auth.name,value:auth.token,url:base}]);
  for(const width of [390,1280]){
   await page.setViewportSize({width,height:900});
   for(const route of ['/manager','/manager/test','/manager/test/access','/manager/test/records','/manager/test/tools']){await page.goto(base+route);await page.evaluate(()=>document.querySelectorAll('details').forEach(el=>el.open=true));await audit(route+' '+width);if(route==='/manager/test'){for(const id of await page.locator('dialog').evaluateAll(els=>els.map(el=>el.id))){await page.evaluate(id=>document.getElementById(id).showModal(),id);await audit(id+' '+width);await page.keyboard.press('Escape');}}}
  }
  await page.goto(base+'/manager/test');
  const settings=await page.locator('#metadlg-test form').evaluate(form=>Object.fromEntries(new FormData(form)));
  let saved=await context.request.post(base+'/manager/test',{form:{...settings,title:'管理者 A 的設定'}});assert.equal(saved.status(),200);
  saved=await context.request.post(base+'/manager/test',{form:{...settings,title:'管理者 B 的舊表單'}});assert.equal(saved.status(),409);
  assert.equal(JSON.parse(fs.readFileSync(tmp+'/projects/test/meta.json','utf8')).title,'管理者 A 的設定');
  console.log('PASS: 專案設定舊表單回應 409，保留其他管理者已儲存的變更');
  fs.writeFileSync('/tmp/souliong-accessibility-audit.json',JSON.stringify(failures,null,2));
  if(failures.some(r=>r.violations.length))process.exitCode=1;
 } finally {if(browser)await browser.close();if(server)server.kill();fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exitCode=1;});
