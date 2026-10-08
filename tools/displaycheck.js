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
   if((url.searchParams.get('f')||url.pathname).endsWith('/maplibre-engine.js'))return route.fulfill({contentType:'application/javascript',body:'window.MapLibreEngine=class {constructor(){this.supportsSnapshot=false;} getZoom(){return window.testZoom||14;} mountControls(){} onZoomThresholdCross(z,fn){window.testZoomCross=fn;} fitBounds(){} setMarkerLayer(key,specs){(window.testLayers||=( {} ))[key]=specs;} clearMarkerLayer(key){(window.testLayers||=( {} ))[key]=[];} onBackgroundClick(){} panTo(){} applyTheme(){} setView(){} createMiniPicker(){return {onChange(){},setPosition(){},destroy(){}}}};'});
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
  assert.equal(citation,'投稿者 · CC BY-NC\n'+base+'/test?entry=entry1');
  for(const width of [1280,240]){await page.setViewportSize({width,height:800});assert.ok(await page.locator('#controls').evaluate(el=>el.getBoundingClientRect().right<=innerWidth),'面板不溢出');}
  const auth=JSON.parse(execFileSync('php',['-r','require '+JSON.stringify(tmp+'/api/security.php')+'; $cfg=require '+JSON.stringify(tmp+'/api/config.php')+'; $token=primary_session_issue($cfg,"cfg"); echo json_encode(["name"=>PRIMARY_COOKIE,"token"=>$token]);'],{encoding:'utf8'}));
  await context.addCookies([{name:auth.name,value:auth.token,url:base}]);
  await page.setViewportSize({width:390,height:844});
  await open();await page.locator('#spotEditBtn').click();
  for(const [icon,url] of [['link','https://example.com/a'],['instagram','https://instagram.com/one'],['instagram','https://instagram.com/two']]){
   await page.locator('.add-spot-link').click();const row=page.locator('.spot-link-row').last();await row.locator('select').selectOption(icon);await row.locator('input').fill(url);
  }
  await page.locator('.pt-save').click();await page.waitForFunction(()=>document.querySelectorAll('.spot-links .spot-link').length===3);
  await open();assert.equal(await page.locator('.spot-links .spot-link').count(),3);assert.equal(await page.locator('.spot-links .fa-instagram').count(),2);
  for(const width of [390,1280]){await page.setViewportSize({width,height:844});assert.ok(await page.locator('.spot-links').evaluate(el=>el.getBoundingClientRect().right<=innerWidth));assert.equal(await page.locator('.spot-link').first().evaluate(el=>el.getBoundingClientRect().height),32);}
  await page.locator('#spotEditBtn').click();assert.equal(await page.locator('.spot-link-row').count(),3);
  while(await page.locator('.spot-link-row').count())await page.locator('.spot-link-row button').first().click();
  await page.locator('.pt-save').click();await page.waitForFunction(()=>document.querySelectorAll('.spot-links .spot-link').length===0);await open();assert.equal(await page.locator('.spot-link').count(),0);
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
  await form.locator('input[name="featuredColor"]').fill('#a87820');
  await form.locator('select[name="numbering"]').selectOption('disable');
  await form.locator('textarea[name="bylineFormats[spot]"]').fill('{name}｜{date} {time} <script>');
  await form.evaluate(async el=>{const response=await fetch(el.action||location.href,{method:'POST',body:(()=>{const data=new FormData(el);data.set('categoryIcons[new]','fa-solid fa-user onclick=alert(1)');return data;})()});if(!response.ok)throw Error('儲存失敗 '+response.status);});
  await open();
  const star=page.locator('.entry-featured-star');
  assert.equal(await star.count(),1);assert.equal(await star.getAttribute('aria-pressed'),'false');
  assert.deepEqual(await star.locator('svg').evaluate(el=>[el.getAttribute('width'),el.getAttribute('height')]),['20','20']);
  await star.click();await page.waitForFunction(()=>document.querySelector('.entry-featured-star')?.getAttribute('aria-pressed')==='true');
  assert.equal(await page.evaluate(()=>window.testLayers.contrib.length),1);
  assert.ok(!(await page.evaluate(()=>MapApp.spotMarkerSpecs()[0].html)).includes('class="badge"'));
  await open();assert.equal(await page.locator('.entry-featured-star').getAttribute('aria-pressed'),'true');
  await context.clearCookies();await open();assert.equal(await page.locator('.entry-featured-star').count(),1);assert.equal(await page.locator('button.entry-featured-star').count(),0);
  await page.locator('#skeleton').waitFor({state:'detached',timeout:11000});
  await page.locator('.entry-featured-star').scrollIntoViewIfNeeded();
  for (const width of [390,1280]) {
    await page.setViewportSize({width,height:900});
    const geometry=await page.locator('.entry-featured-star').evaluate(el=>{const a=el.getBoundingClientRect(),b=el.closest('.entry').getBoundingClientRect(),css=getComputedStyle(el);return {width:a.width,height:a.height,right:b.right-a.right,top:a.top-b.top,color:css.color,bg:css.backgroundColor};});
    assert.equal(geometry.width,26);assert.equal(geometry.height,26);assert.ok(geometry.right>=8&&geometry.right<=12);assert.ok(geometry.top>=8&&geometry.top<=12);
    assert.equal(geometry.color,'rgb(255, 255, 255)');assert.equal(geometry.bg,'rgb(168, 120, 32)');
    await page.screenshot({path:'/tmp/souliong-featured-'+width+'.png'});
  }

  await context.addCookies([{name:auth.name,value:auth.token,url:base}]);await open();
  await page.locator('.entry-featured-star').click();await page.waitForFunction(()=>document.querySelector('.entry-featured-star')?.getAttribute('aria-pressed')==='false');
  await context.clearCookies();await open();assert.equal(await page.locator('.entry-featured-star').count(),0);
  await context.addCookies([{name:auth.name,value:auth.token,url:base}]);
  await open();assert.equal(await page.locator('#histBtn').count(),0);assert.equal(await page.locator('.hist-btn').count(),1);assert.equal(await page.locator('.entry .who').count(),0);assert.equal(await page.locator('.entry .time').count(),0);
  const saved=JSON.parse(fs.readFileSync(tmp+'/projects/test/meta.json','utf8'));assert.equal(saved.featuredColor,'#a87820');assert.equal(saved.pinMark,'icon');assert.equal(saved.categoryIcons.test,'mug-hot');assert.equal(saved.categoryIcons.new,'location-dot');
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
  // 沒有 GPS 的精選圖片、GPS 精選與一般投稿混合，確認數量上限及座標不被改寫。
  meta.contrib.kinds=['text','photo'];meta.features.contribBrowse=true;
  fs.writeFileSync(tmp+'/projects/test/meta.json',JSON.stringify(meta));
  const photos=Array.from({length:5},(_,i)=>({id:'photo'+i,kind:'photo',project:'test',item_num:1,name:'攝影者',photo:'photo'+i+'.jpg',featured:true,created_at:'2026-10-08T00:00:0'+i+'Z',...(i===0?{lat:25,lon:121}:{})}));
  const ordinary={...photos[0],id:'ordinary',photo:'ordinary.jpg',featured:false,lat:26,lon:122};
  fs.writeFileSync(tmp+'/projects/test/spots.jsonl',[spot,edit,entry,entryEdit,...photos,ordinary].map(JSON.stringify).join('\n')+'\n');
  await open();
  let previews=await page.evaluate(()=>window.testLayers.contrib.map(({id,lat,lon,anchor,size,html})=>({id,lat,lon,anchor,size,html})));
  assert.deepEqual(previews.map(p=>p.id),['photo0','photo1','photo2','featured-more-1']);
  assert.ok(previews.every(p=>p.lat===24&&p.lon===120));
  function checkSurrounding(markers) {
    const centers=markers.map(p=>({x:p.size[0]/2-p.anchor[0],y:p.size[1]/2-p.anchor[1],half:p.size[0]/2}));
    assert.deepEqual(centers.map(p=>[Math.sign(p.x),Math.sign(p.y)]),[[1,-1],[-1,1],[-1,-1],[1,1]]);
    for(const p of centers) assert.ok(Math.abs(p.x)>12+p.half || Math.abs(p.y)>12+p.half,'中央地點不被遮住');
    for(let i=0;i<centers.length;i++) for(let j=i+1;j<centers.length;j++) {
      const a=centers[i],b=centers[j],distance=a.half+b.half+5;
      assert.ok(Math.abs(a.x-b.x)>=distance || Math.abs(a.y-b.y)>=distance,'預覽彼此保留間距');
    }
  }
  checkSurrounding(previews);
  assert.ok(previews[3].html.includes('+2'));
  assert.equal(await page.evaluate(()=>MapApp.effectiveEntries().find(e=>e.id==='photo0').lat),25);
  assert.equal(await page.evaluate(()=>MapApp.effectiveEntries().find(e=>e.id==='photo1').lat),null);
  await page.evaluate(()=>{window.testZoom=16;window.testZoomCross();});
  assert.ok(await page.evaluate(()=>window.testLayers.contrib.every(p=>p.size[0]===30)));
  checkSurrounding(await page.evaluate(()=>window.testLayers.contrib.map(({size,anchor})=>({size,anchor}))));
  await page.evaluate(()=>window.testLayers.contrib[3].onClick());
  assert.ok((await page.locator('#pTitle').textContent()).startsWith('點位一'));
  await page.evaluate(()=>window.testLayers.contrib[0].onClick());
  assert.equal(await page.locator('#lb').evaluate(el=>el.style.display),'flex');
  assert.ok((await page.locator('#lbImg').getAttribute('src')).includes('photo0.jpg'));
  await page.evaluate(()=>document.getElementById('lb').style.display='none');
  await page.locator('.chip').first().evaluate(el=>el.click());
  assert.equal(await page.evaluate(()=>window.testLayers.contrib.length),0);
  await page.locator('.chip').first().evaluate(el=>el.click());
  assert.equal(await page.evaluate(()=>window.testLayers.contrib.length),4);
  await page.evaluate(()=>MapApp.setDisplay({contributions:false}));
  assert.equal(await page.evaluate(()=>window.testLayers.contrib.length),0);
  await page.evaluate(()=>MapApp.setDisplay({contributions:true}));
  assert.equal(await page.evaluate(()=>window.testLayers.contrib.length),4);
  await page.locator('#photoLayerBtn').evaluate(el=>el.click());
  assert.equal(await page.evaluate(()=>window.testLayers.contrib.filter(p=>p.id==='ordinary').length),1);
  assert.equal(await page.evaluate(()=>window.testLayers.contrib.filter(p=>p.id==='photo0').length),1);
  assert.deepEqual(errors,[]);
  console.log('PASS: 精選預覽無 GPS、數量收合、像素錯開、縮放重繪、即時更新、原座標保留與一般投稿相容；精選星章管理／訪客、重載保留、底色儲存與手機／桌面位置；點位清單膠囊、面板寬度、後台儲存、獨立紀錄／署名開關、格式與 HTML 跳脫、icon 儲存與預設、五種模式及導航按鈕尺寸');
 }finally{if(browser)await browser.close();if(server)server.kill();fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exitCode=1;});
