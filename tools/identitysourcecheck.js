// Isolated HTTP checks for inherited source visibility and migration authorization.
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn,execFileSync}=require('node:child_process');
(async()=>{
 const root=path.resolve(__dirname,'..'),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'souliong-identity-')),port=26300+Math.floor(Math.random()*500),base='http://127.0.0.1:'+port;let server;
 try{
  for(const dir of ['api','pages','assets','lang','layers'])fs.cpSync(path.join(root,dir),path.join(tmp,dir),{recursive:true});
  for(const file of ['index.php','.htaccess'])if(fs.existsSync(path.join(root,file)))fs.copyFileSync(path.join(root,file),path.join(tmp,file));
  fs.mkdirSync(tmp+'/state');fs.mkdirSync(tmp+'/projects/test',{recursive:true});
  fs.writeFileSync(tmp+'/api/config.php','<?php $c=require __DIR__."/config.example.php"; $c["projects_dir"]='+JSON.stringify(tmp+'/projects')+'; $c["state_dir"]='+JSON.stringify(tmp+'/state')+'; $c["primary_pin"]=""; $c["ip_salt"]="identity-test"; return $c;');
  fs.writeFileSync(tmp+'/projects/test/meta.json',JSON.stringify({title:'隔離測試',modules:{delegation:true}}));
  const grants=['edit_spots','grant_access'],perms=keys=>Object.fromEntries(['edit_spots','edit_meta','edit_layers','manage_contrib','export_backup','bypass_code','grant_access','delete_others','edit_3d_regions'].map(k=>[k,keys.includes(k)]));
  fs.writeFileSync(tmp+'/state/pins.json',JSON.stringify({primary:[],projects:{test:[{id:'actor',kind:'pin',label:'ACTOR',perms:perms(grants)},{id:'low',kind:'pin',label:'VISIBLE_LOWER',perms:perms(['edit_spots'])},{id:'high',kind:'pin',label:'HIDDEN_HIGHER',perms:perms([...grants,'edit_meta'])},{id:'other',kind:'pin',label:'HIDDEN_INCOMPARABLE',perms:perms(['grant_access','edit_meta'])},{id:'unknown',kind:'pin',label:'HIDDEN_UNKNOWN',perms:{...perms([]),unexpected:true}}]}}));
  fs.writeFileSync(tmp+'/state/accounts.json',JSON.stringify({accounts:[{id:'admin',userid:'admin',role:'primary',label:'PRIVATE_GLOBAL_NAME'}],pending:[]}));
  fs.writeFileSync(tmp+'/router.php','<?php $p=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); if(is_file(__DIR__.$p))return false; require __DIR__."/index.php";');
  const auth=JSON.parse(execFileSync('php',['-r','require '+JSON.stringify(tmp+'/api/security.php')+'; $cfg=require '+JSON.stringify(tmp+'/api/config.php')+'; echo json_encode(["primary"=>PRIMARY_COOKIE."=".primary_session_issue($cfg,"cfg"),"pin"=>pin_cookie_name("test")."=actor.".pin_derived($cfg,"test","actor"),"csrf"=>pin_derived($cfg,"test","actor")]);'],{encoding:'utf8'}));
  server=spawn('php',['-S','127.0.0.1:'+port,'-t',tmp,tmp+'/router.php'],{stdio:'ignore'});
  for(let i=0;i<50;i++){try{await (await fetch(base)).text();break;}catch{await new Promise(r=>setTimeout(r,100));}}
  const get=async cookie=>(await fetch(base+'/manager/test/access',{headers:{cookie}})).text();
  const primary=await get(auth.primary),lower=await get(auth.pin);
  assert.ok(primary.includes('PRIVATE_GLOBAL_NAME'));assert.ok(primary.includes('HIDDEN_HIGHER'));
  assert.ok(lower.includes('全站管理者（繼承）'));assert.ok(lower.includes('VISIBLE_LOWER'));
  for(const hidden of ['PRIVATE_GLOBAL_NAME','HIDDEN_HIGHER','HIDDEN_INCOMPARABLE','HIDDEN_UNKNOWN'])assert.ok(!lower.includes(hidden),hidden+' leaked');
  for(const target of ['high','other','unknown']){
   const response=await fetch(base+'/manager/test/access',{method:'POST',headers:{cookie:auth.pin,'content-type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'migrate_create',project:'test',source:'project',legacy_id:target,label:'test',csrf:auth.csrf}),redirect:'manual'});await response.text();assert.equal(response.status,403,target);
  }
  const response=await fetch(base+'/manager/test/access',{method:'POST',headers:{cookie:auth.pin,'content-type':'application/x-www-form-urlencoded'},body:new URLSearchParams({action:'migrate_create',project:'test',source:'project',legacy_id:'low',label:'test',csrf:auth.csrf}),redirect:'manual'});await response.text();assert.ok(response.status<400);
  const pending=JSON.parse(fs.readFileSync(tmp+'/state/accounts.json')).pending;assert.equal(pending.length,1);
  const {chromium}=require(process.env.PLAYWRIGHT_PATH||'playwright');
  const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
  try {
   const context=await browser.newContext();await context.addCookies([{name:auth.pin.split('=')[0],value:auth.pin.split('=')[1],url:base}]);
   const page=await context.newPage();await context.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
   for(const width of [390,1280]) {await page.setViewportSize({width,height:844});await page.goto(base+'/manager/test/access');const cards=page.locator('.identity-source');assert.equal(await cards.count(),2);assert.ok(await cards.evaluateAll(els=>els.every(e=>e.getBoundingClientRect().width>0&&e.getBoundingClientRect().right<=innerWidth)));}
  } finally {await browser.close();}
  console.log('Identity sources: inherited labels, private global names, subset/incomparable/unknown PIN filtering and direct migration guards passed.');
 }finally{if(server)server.kill();fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exitCode=1;});
