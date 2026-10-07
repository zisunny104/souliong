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
  const meta={title:'測試地圖',features:{upload:false,contentEdit:false,delegation:false,identity:false,map3d:false},contrib:{kinds:['text']}};
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
   if((url.searchParams.get('f')||url.pathname).endsWith('/maplibre-engine.js'))return route.fulfill({contentType:'application/javascript',body:'window.MapLibreEngine=class {constructor(){this.supportsSnapshot=false;} getZoom(){return 14;} mountControls(){} onZoomThresholdCross(){} fitBounds(){} setMarkerLayer(){} clearMarkerLayer(){} onBackgroundClick(){} panTo(){} applyTheme(){} setView(){}};'});
   return route.continue();
  });
  async function open(){await page.goto(base+'/test');await page.waitForFunction(()=>window.MapApp?.effectiveSpots().length===1,null,{timeout:10000}).catch(e=>{console.error(errors);throw e;});await page.evaluate(()=>MapApp.openPanel(MapApp.effectiveSpots()[0]));}
  await open();assert.equal(await page.locator('#personFilter option').first().textContent(),'點位清單');assert.equal(await page.locator('#spotFilterCount').textContent(),'1');
  const cardHeight = await page.locator('#controls').evaluate(el=>el.getBoundingClientRect().height);
  await page.locator('#spotFilterTrigger').click();
  assert.ok(await page.locator('#spotFilterOptions').evaluate(el=>el.matches(':popover-open')));
  assert.equal(await page.locator('#controls').evaluate(el=>el.getBoundingClientRect().height), cardHeight);
  assert.equal(await page.locator('#spotFilterOptions .sl-list-marker .dot-pin').count(),1);
  await page.keyboard.press('Escape');
  assert.equal(await page.locator('#spotFilterTrigger').getAttribute('aria-expanded'),'false');
  assert.equal(await page.locator('.story-head').count(),0);assert.equal(await page.locator('#histBtn').count(),1);assert.equal(await page.locator('.hist-btn').count(),1);
  assert.ok((await page.locator('.story-by').textContent()).startsWith('— 管理者・'));assert.equal(await page.locator('.entry .who').textContent(),'投稿者 已編輯');
  assert.equal(await page.locator('.entry-footer .entry-license a').first().getAttribute('href'),'https://creativecommons.org/licenses/by-nc/4.0/deed.zh-hant');
  assert.equal(await page.locator('.entry-footer .entry-license a').last().getAttribute('href'),'https://example.com/author');
  assert.equal(await page.locator('.entry-footer .entry-actions .hist-btn').count(),1);
  await page.evaluate(()=>Object.defineProperty(navigator,'clipboard',{configurable:true,value:{writeText:async text=>{window.copiedCitation=text;}}}));
  await page.locator('.entry-footer button[aria-label="複製引用資訊"]').evaluate(el=>el.click());
  const citation=await page.evaluate(()=>window.copiedCitation);
  assert.ok(citation.includes('投稿者') && citation.includes('CC BY-NC') && citation.includes('?entry=entry1'));
  for(const width of [1280,240]){await page.setViewportSize({width,height:800});assert.ok(await page.locator('#controls').evaluate(el=>el.getBoundingClientRect().right<=innerWidth),'面板不溢出');}
  const auth=JSON.parse(execFileSync('php',['-r','require '+JSON.stringify(tmp+'/api/security.php')+'; $cfg=require '+JSON.stringify(tmp+'/api/config.php')+'; $token=primary_session_issue($cfg,"cfg"); echo json_encode(["name"=>PRIMARY_COOKIE,"token"=>$token]);'],{encoding:'utf8'}));
  await context.addCookies([{name:auth.name,value:auth.token,url:base}]);
  await page.goto(base+'/manager/test');
  const form=page.locator('form').filter({has:page.locator('textarea[name="bylineFormats[spot]"]')});
  assert.equal(await form.count(),1);
  const pane = await form.evaluate(el=>el.closest('.pane').id.replace('pane-',''));
  await page.locator('[data-pane="'+pane+'"]').first().click();
  await form.evaluate(el=>{for(let node=el;node;node=node.parentElement){if(node.tagName==='DIALOG')node.showModal();if(node.tagName==='DETAILS')node.open=true;}const section=el.querySelector('textarea[name="bylineFormats[spot]"]').closest('details');section.open=true;});
  await page.setViewportSize({width:1280,height:900});

  await form.locator('input[name="features[spotHistory]"]').uncheck({timeout:5000});await form.locator('input[name="features[entryByline]"]').uncheck();
  await form.locator('select[name="pinMark"]').evaluate(el=>el.closest('details').open=true);
  await form.locator('select[name="pinMark"]').selectOption('icon');
  await form.locator('select[name="categoryIcons[test]"]').selectOption('mug-hot');
  assert.ok(await form.locator('[data-pin-icon-select]').last().evaluate(el=>el.parentElement.querySelector('.pin-icon-preview svg path').getAttribute('d').length>10));
  await form.locator('select[name="numbering"]').selectOption('disable');
  await form.locator('textarea[name="bylineFormats[spot]"]').fill('{name}｜{date} {time} <script>');
  await form.evaluate(async el=>{const response=await fetch(el.action||location.href,{method:'POST',body:(()=>{const data=new FormData(el);data.set('categoryIcons[new]','fa-solid fa-user onclick=alert(1)');return data;})()});if(!response.ok)throw Error('儲存失敗 '+response.status);});
  await open();assert.equal(await page.locator('#histBtn').count(),0);assert.equal(await page.locator('.hist-btn').count(),1);assert.equal(await page.locator('.entry .who').count(),0);assert.equal(await page.locator('.entry .time').count(),0);
  const saved=JSON.parse(fs.readFileSync(tmp+'/projects/test/meta.json','utf8'));assert.equal(saved.pinMark,'icon');assert.equal(saved.categoryIcons.test,'mug-hot');assert.equal(saved.categoryIcons.new,'location-dot');
  const specs=await page.evaluate(()=>MapApp.spotMarkerSpecs());assert.ok(specs[0].html.includes('sl-pin-icon'));assert.ok(!specs[0].html.includes('<span>1</span>'));assert.ok(specs[0].html.includes('#aa3311'));assert.ok(specs[0].html.includes('class="badge"'));assert.equal(await page.locator('#pTitle').textContent(),'點位一');
  assert.ok(specs[0].html.includes('has-audio'));
  await page.locator('.sc-block-audio audio').evaluate(el=>el.dispatchEvent(new Event('play')));
  assert.ok(await page.evaluate(()=>MapApp.spotMarkerSpecs()[0].html.includes('is-playing')));
  await page.locator('.sc-block-audio audio').evaluate(el=>el.dispatchEvent(new Event('pause')));
  assert.deepEqual(await page.locator('#spotNavBtn').evaluate(el=>[el.offsetWidth,el.offsetHeight,getComputedStyle(el.querySelector('i')).fontSize]),[32,32,'20px']);
  assert.deepEqual(await page.locator('.p-close').evaluate(el=>[el.offsetWidth,el.offsetHeight]),[32,32]);
  assert.equal(await page.locator('.story-by').textContent(),'管理者｜2026/10/07 19:00 <script>');assert.equal(await page.locator('.story-by script').count(),0);
  meta.features={...meta.features,spotList:true,spotByline:false,spotHistory:true,entryHistory:false};meta.bylineFormats={entry:'由 {name} 提供（{date}）'};
  fs.writeFileSync(tmp+'/projects/test/meta.json',JSON.stringify(meta));await open();
  assert.equal(await page.locator('.sl-spot-list-heading .sl-spot-count').textContent(),'1');assert.equal(await page.locator('.story-by').count(),0);assert.equal(await page.locator('#histBtn').count(),1);assert.equal(await page.locator('.hist-btn').count(),0);
  assert.equal(await page.locator('.entry .who').textContent(),'由 投稿者 提供（2026/10/07） 已編輯');assert.deepEqual(errors,[]);
  for(const [mode,size] of [['number','md'],['blank','sm'],['shape','lg'],['image','md'],['icon','sm'],['icon','lg']]) {
    meta.pinMark=mode;meta.pinSize=size;meta.pinBorder=false;meta.categoryIcons={test:'<img onerror=alert(1)>'};
    fs.writeFileSync(tmp+'/projects/test/meta.json',JSON.stringify(meta));await open();
    const result=await page.evaluate(()=>MapApp.spotMarkerSpecs()[0]);assert.equal(result.size[0],size==='sm'?18:size==='lg'?32:24);assert.ok(result.html.includes('sl-noborder'));assert.ok(result.html.includes('class="badge"'));
    if(mode==='icon'){const catalog=JSON.parse(fs.readFileSync(root+'/assets/icons/fontawesome-solid.json','utf8'));assert.ok(result.html.includes(catalog['location-dot'].paths[0]));assert.ok(!result.html.includes('onerror'));}
    if(mode==='number')assert.ok(result.html.includes('<span>1</span>'));if(mode==='blank')assert.ok(!result.html.includes('<span>'));if(mode==='shape')assert.ok(result.html.includes('<svg'));if(mode==='image')assert.ok(result.html.includes('center/cover'));
  }
  assert.deepEqual(errors,[]);
  console.log('PASS: 點位清單膠囊、面板寬度、後台儲存、獨立紀錄／署名開關、格式與 HTML 跳脫、icon 儲存與預設、五種模式及導航按鈕尺寸');
 }finally{if(browser)await browser.close();if(server)server.kill();fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exitCode=1;});
