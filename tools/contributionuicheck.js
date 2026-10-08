// 隔離資料與模擬地圖引擎，驗證實際後台設定及前台 DOM。
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn,execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_PATH||'playwright');
(async()=>{
 const root=path.resolve(__dirname,'..'),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'souliong-display-')),port=24100+Math.floor(Math.random()*500),base='http://127.0.0.1:'+port;
 let server,browser;
 try {
  for(const dir of ['api','pages','assets','lang','layers'])fs.cpSync(path.join(root,dir),path.join(tmp,dir),{recursive:true});
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
   if((url.searchParams.get('f')||url.pathname).endsWith('/maplibre-engine.js'))return route.fulfill({contentType:'application/javascript',body:'window.MapLibreEngine=class {constructor(){this.supportsSnapshot=false;} getCenter(){return {lat:24,lon:120};} getZoom(){return window.testZoom||14;} markerElements(){return new Map();} mountControls(){} onZoomEnd(fn){window.testZoomEnd=fn;} onZoomThresholdCross(z,fn){window.testZoomCross=fn;} fitBounds(){} setMarkerLayer(key,specs){(window.testLayers||=( {} ))[key]=specs;} clearMarkerLayer(key){(window.testLayers||=( {} ))[key]=[];} onBackgroundClick(){} panTo(){} applyTheme(){} setView(){} createMiniPicker(){return {onChange(){},setPosition(){},destroy(){}}}};'});
   return route.continue();
  });
  async function open(){await page.goto(base+'/test');await page.waitForFunction(()=>window.MapApp?.effectiveSpots().length===1,null,{timeout:10000}).catch(e=>{console.error(errors);throw e;});await page.evaluate(()=>MapApp.openPanel(MapApp.effectiveSpots()[0]));}
  for (const width of [320,390,1280]) for (const theme of ['light','dark']) {
   await page.setViewportSize({width,height:844});
   await page.goto(base+'/test');
   await page.waitForSelector('#uploadBtn');
   await page.evaluate(theme=>document.documentElement.dataset.theme=theme,theme);
   await page.locator('#uploadBtn').click();
   const modal=page.locator('#contribModal');
   assert.equal(await modal.locator('#ccByChk').inputValue(),'cc0');
   await modal.locator('#modalName').fill('測試作者');
   assert.equal(await modal.locator('#ccByChk').inputValue(),'cc-by-sa');
   assert.ok((await modal.locator('#licenseSummary').textContent()).includes('可商用'));
   await modal.locator('#ccByChk').selectOption('cc-by-nc');
   assert.ok((await modal.locator('#licenseSummary').textContent()).includes('非商業'));
   await modal.locator('#modalName').fill('');
   assert.equal(await modal.locator('#ccByChk').inputValue(),'cc0');
   await modal.locator('#modalName').fill('測試作者');
   assert.equal(await modal.locator('#ccByChk').inputValue(),'cc-by-nc');
   assert.equal(await modal.locator('.author-link').evaluate(el=>el.open),false);
   await modal.locator('.author-link summary').click();
   await modal.locator('#authorUrl').fill('https://example.com/profile');
   assert.ok(await modal.evaluate(el=>el.querySelector('.modal-box').getBoundingClientRect().right<=innerWidth));
   assert.ok(await modal.evaluate(el=>el.querySelector('.modal-content').scrollWidth<=el.querySelector('.modal-content').clientWidth+1));
   assert.ok(await modal.locator('#submitAllBtn').evaluate(el=>{const r=el.getBoundingClientRect();return r.bottom<=innerHeight&&r.top>=0}));
   if(width===390&&theme==='light')await page.screenshot({path:'/tmp/souliong-submission-modal.png'});
   await page.evaluate(()=>MapApp.closeModal());
   await page.locator('#createBtn').click();
   const spotModal=page.locator('#spotModal');
   await spotModal.waitFor({state:'visible'});
   assert.equal(await spotModal.locator('.modal-head input').count(),0);
   await spotModal.locator('#spotAddMore').click();
   await spotModal.locator('.c-title').fill('新點位測試');
   await spotModal.locator('.c-cmt').fill('點位說明');
   assert.ok(await spotModal.evaluate(el=>el.querySelector('.modal-content').scrollWidth<=el.querySelector('.modal-content').clientWidth+1));
   if(width===390&&theme==='light')await page.screenshot({path:'/tmp/souliong-create-modal.png'});
   assert.ok(await spotModal.evaluate(el=>el.querySelector('.modal-box').getBoundingClientRect().right<=innerWidth));
   await page.evaluate(()=>MapApp.closeSpotModal());
   await page.evaluate(()=>{localStorage.removeItem('myName');localStorage.removeItem('prefLicense');});
  }
  await page.goto(base+'/test');await page.waitForSelector('#uploadBtn');await page.locator('#uploadBtn').click();
  await page.locator('#modalName').fill('筆名作者');await page.locator('.author-link summary').click();await page.locator('#authorUrl').fill('https://example.com/profile');
  await page.locator('#addMoreBtn').click();await page.locator('#queue .c-cmt').fill('測試署名投稿');
  assert.equal(await page.locator('#queue .c-name').inputValue(),'筆名作者');
  await Promise.all([page.waitForResponse(r=>r.url().includes('api=upload')&&r.request().method()==='POST'),page.locator('#submitAllBtn').click()]);
  await page.waitForSelector('#queue .card.done');
  const rows=fs.readFileSync(tmp+'/projects/test/entries.jsonl','utf8').trim().split('\n').map(JSON.parse);
  const uploaded=rows.find(row=>row.comment==='測試署名投稿');
  assert.equal(uploaded.name,'筆名作者');assert.equal(uploaded.license,'cc-by-sa');assert.equal(uploaded.author_url,'https://example.com/profile');
  await page.reload();await page.waitForSelector('#uploadBtn');await page.locator('#uploadBtn').click();
  assert.equal(await page.locator('#modalName').inputValue(),'筆名作者');
  assert.equal(await page.locator('#ccByChk').inputValue(),'cc-by-sa');
  await page.locator('#modalName').fill('');await page.locator('#addMoreBtn').click();
  await page.locator('#queue .c-cmt').fill('未署名投稿測試');
  assert.equal(await page.locator('#ccByChk').inputValue(),'cc0');
  await Promise.all([page.waitForResponse(r=>r.url().includes('api=upload')&&r.request().method()==='POST'),page.locator('#submitAllBtn').click()]);
  await page.waitForSelector('#queue .card.done');
  const anonymous=fs.readFileSync(tmp+'/projects/test/entries.jsonl','utf8').trim().split('\n').map(JSON.parse).find(row=>row.comment==='未署名投稿測試');
  assert.equal(anonymous.name,'匿名');assert.equal(anonymous.license,'cc0');
  assert.deepEqual(errors,[]);
  console.log('PASS: 投稿與建立點位手機／桌面、深淺色樣式；署名切換、預設與記憶授權、連結收合及真實 API 儲存');
 } finally {if(browser)await browser.close();if(server)server.kill();fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exitCode=1;});
