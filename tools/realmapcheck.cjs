// 真實 MapLibre 引擎與隔離資料；底圖為本機空白樣式，測量不含 VPS／圖磚網路。
// MAPLIBRE_DIST 必須指向 6.6.0 的 mjs、shared、worker 與 CSS 檔案目錄。
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_PATH||'playwright');
(async()=>{
 const root=path.resolve(__dirname,'..'),dist=process.env.MAPLIBRE_DIST;
 assert.ok(dist,'請設定 MAPLIBRE_DIST（MapLibre 6.6.0 dist 目錄）');
 for(const f of ['maplibre-gl.mjs','maplibre-gl-shared.mjs','maplibre-gl-worker.mjs','maplibre-gl.css'])assert.ok(fs.existsSync(path.join(dist,f)),f);
 const tmp=fs.mkdtempSync(path.join(os.tmpdir(),'souliong-realmap-')),port=25100+Math.floor(Math.random()*500),base='http://127.0.0.1:'+port;
 let server,browser;
 try{
  for(const dir of ['api','pages','assets','lang','layers'])fs.cpSync(path.join(root,dir),path.join(tmp,dir),{recursive:true});
  for(const file of ['index.php','config.php'])fs.copyFileSync(path.join(root,file),path.join(tmp,file));
  fs.mkdirSync(tmp+'/projects/test',{recursive:true});fs.mkdirSync(tmp+'/state');
  fs.writeFileSync(tmp+'/api/config.php','<?php $c=require '+JSON.stringify(root+'/api/config.example.php')+'; $c["projects_dir"]='+JSON.stringify(tmp+'/projects')+'; $c["state_dir"]='+JSON.stringify(tmp+'/state')+'; $c["primary_pin"]=""; return $c;');
  fs.writeFileSync(tmp+'/projects/test/meta.json',JSON.stringify({title:'地圖驗證',center:[24,120],zoom:16,features:{upload:false,contentEdit:false,map3d:false},contrib:{kinds:['photo']}}));
  const rows=[];
  for(let i=0;i<50;i++){
   rows.push({id:'spot'+i,kind:'spot',num:i+1,item_num:i+1,project:'test',title:'地點 '+(i+1),cat:'test',lat:24+Math.floor(i/5)*.0005,lon:120+(i%5)*.0006,created_at:'2026-10-08T00:00:00Z'});
   for(let j=0;j<6;j++)rows.push({id:'photo'+i+'_'+j,kind:'photo',project:'test',item_num:i+1,name:'測試者',photo:'photo'+i+'_'+j+'.jpg',featured:true,created_at:'2026-10-08T00:00:0'+j+'Z'});
  }
  fs.writeFileSync(tmp+'/projects/test/spots.jsonl',rows.map(JSON.stringify).join('\n')+'\n');
  fs.writeFileSync(tmp+'/router.php','<?php $p=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); if(is_file(__DIR__.$p))return false; require __DIR__."/index.php";');
  server=spawn('php',['-S','127.0.0.1:'+port,'-t',tmp,tmp+'/router.php'],{stdio:'ignore'});
  for(let i=0;i<50;i++){try{await fetch(base);break;}catch{await new Promise(r=>setTimeout(r,100));}}
  browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox','--enable-unsafe-swiftshader']});
  const page=await browser.newPage(),errors=[];page.on('pageerror',e=>errors.push(e.message));
  await page.route('**/*',async route=>{
   const url=new URL(route.request().url());
   if(url.hostname==='unpkg.com'&&url.pathname.startsWith('/maplibre-gl@6.6.0/dist/'))return route.fulfill({path:path.join(dist,path.basename(url.pathname)),contentType:url.pathname.endsWith('.css')?'text/css':'application/javascript'});
   if(url.origin!==base)return route.abort();
   if(url.searchParams.get('api')==='photo')return route.fulfill({contentType:'image/svg+xml',body:'<svg xmlns="http://www.w3.org/2000/svg" width="80" height="80"><rect width="80" height="80" fill="#76aa95"/><circle cx="40" cy="35" r="20" fill="#e3cf82"/></svg>'});
   if((url.searchParams.get('f')||url.pathname).endsWith('/maplibre-engine.js'))return route.fulfill({contentType:'application/javascript',body:fs.readFileSync(root+'/assets/js/engine/maplibre-engine.js','utf8')+'\nwindow.MapLibreEngine=class extends window.MapLibreEngine {_styleFor(){return {version:8,sources:{},layers:[{id:"background",type:"background",paint:{"background-color":"#e9ece8"}}]};} _mountOverlays(){} get supportsSnapshot(){return false;}};'});
   return route.continue();
  });
  const report=[];
  for(const width of [390,1280]){
   await page.setViewportSize({width,height:900});await page.goto(base+'/test');
   await page.waitForFunction(()=>window.MapApp?.getEngine()?.getRawMap()?.loaded()&&MapApp.effectiveEntries().length===300,null,{timeout:20000});
   for(const [zoom,size] of [[13,14],[14,22],[16,32],[13,14],[16,32]]){
    await page.evaluate(zoom=>MapApp.getEngine().getRawMap().jumpTo({center:[120.0012,24.002],zoom}),zoom);
    await page.waitForFunction(size=>Array.isArray(MapApp.getEngine()._markerSpecs.contrib)&&MapApp.getEngine()._markerSpecs.contrib.every(s=>s.size[0]===size),size);
    const transitions=await page.locator('.sl-move').evaluateAll(els=>els.reduce((n,el)=>n+el.getAnimations().length,0));
    await page.waitForTimeout(700);
    const geometry=await page.evaluate(()=>{
     const els=[...MapApp.getEngine().markerElements('contrib').values()];
     const rects=els.map(el=>el.querySelector('.sl-move').getBoundingClientRect());
     let overlappingPairs=0;
     for(let i=0;i<rects.length;i++)for(let j=i+1;j<rects.length;j++){const a=rects[i],b=rects[j];if(Math.min(a.right,b.right)-Math.max(a.left,b.left)>1&&Math.min(a.bottom,b.bottom)-Math.max(a.top,b.top)>1)overlappingPairs++;}
     return {markers:els.length,widths:[...new Set(rects.map(r=>r.width))],overlappingPairs};
    });
    assert.ok(geometry.markers<=200);assert.equal(geometry.overlappingPairs,0);assert.ok(geometry.widths.every(w=>Math.abs(w-size)<.1));
    report.push({width,zoom,size,transitions,markers:geometry.markers,overlappingPairs:geometry.overlappingPairs});
   }
   await page.locator('.dot-pin:has(.badge)').first().evaluate(el=>el.click());
   assert.equal(await page.locator('#entries .entry').count(),6,'收合入口仍可查看所有投稿');
   await page.locator('.p-close').evaluate(el=>el.click());
   await page.evaluate(()=>MapApp.getEngine().getRawMap().jumpTo({zoom:18}));
   await page.waitForTimeout(700);
   await page.locator('.sl-featured-body .photo-sq').first().evaluate(el=>el.click());
   assert.equal(await page.locator('#lb').evaluate(el=>el.style.display),'flex');
   await page.evaluate(()=>document.getElementById('lb').style.display='none');
   // 畫面外不保留預覽，移回時恢復；拖曳途中不更換 DOM 標記。
   await page.evaluate(()=>MapApp.getEngine().getRawMap().jumpTo({center:[122,26],zoom:16}));
   assert.equal(await page.evaluate(()=>MapApp.getEngine()._markerSpecs.contrib.length),0);
   await page.evaluate(()=>MapApp.getEngine().getRawMap().jumpTo({center:[120.0012,24.002],zoom:16}));
   await page.waitForTimeout(700);
   const before=await page.evaluate(()=>[...MapApp.getEngine().markerElements('contrib').values()].map(el=>{el.dataset.stable='yes';return el.dataset.mid;}));
   await page.evaluate(()=>MapApp.getEngine().getRawMap().easeTo({center:[120.0013,24.002],duration:400}));
   await page.waitForTimeout(100);
   assert.deepEqual(await page.evaluate(()=>[...MapApp.getEngine().markerElements('contrib').values()].map(el=>el.dataset.mid)),before);
   assert.ok(await page.evaluate(()=>[...MapApp.getEngine().markerElements('contrib').values()].every(el=>el.dataset.stable==='yes')));
   await page.waitForTimeout(1000);
   const frames=await page.evaluate(async()=>{
    const map=MapApp.getEngine().getRawMap(),deltas=[];let last=performance.now();
    for(let i=0;i<30;i++){await new Promise(requestAnimationFrame);const now=performance.now();deltas.push(now-last);last=now;map.panBy([2,1],{duration:0});}
    return {meanMs:deltas.reduce((a,b)=>a+b,0)/deltas.length,maxMs:Math.max(...deltas)};
   });
   report.push({width,panFrames:frames});
   await page.waitForTimeout(700);
   await page.screenshot({path:'/tmp/souliong-realmap-'+width+'.png'});
  }
  await page.emulateMedia({reducedMotion:'reduce'});
  await page.evaluate(()=>MapApp.getEngine().getRawMap().jumpTo({zoom:14}));
  assert.equal(await page.locator('.sl-move').first().evaluate(el=>getComputedStyle(el).transitionDuration),'0s');
  assert.deepEqual(errors,[]);
  console.log(JSON.stringify({functionalChecksPassed:true,crowdedOverlapsObserved:report.some(r=>r.overlappingPairs>0),spots:50,photos:300,maxPreviewMarkers:200,report},null,2));
 }finally{if(browser)await browser.close();if(server)server.kill();fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exitCode=1;});
