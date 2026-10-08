// Isolated browser test: real settings POSTs, persisted periods, desktop/mobile collapse.
const fs=require('node:fs'),os=require('node:os'),path=require('node:path'),assert=require('node:assert/strict');
const {spawn,execFileSync}=require('node:child_process');
const {chromium}=require(process.env.PLAYWRIGHT_PATH||'playwright');
(async()=>{
 const root=path.resolve(__dirname,'..'),tmp=fs.mkdtempSync(path.join(os.tmpdir(),'souliong-contribadmin-')),port=25100+Math.floor(Math.random()*500),base='http://127.0.0.1:'+port;
 let server,browser;
 try {
  for(const dir of ['api','pages','assets','lang','layers'])fs.cpSync(path.join(root,dir),path.join(tmp,dir),{recursive:true});
  for(const file of ['index.php','config.php'])fs.copyFileSync(path.join(root,file),path.join(tmp,file));
  fs.mkdirSync(tmp+'/projects/test',{recursive:true});fs.mkdirSync(tmp+'/state');
  fs.writeFileSync(tmp+'/api/config.php','<?php $c=require '+JSON.stringify(root+'/api/config.example.php')+'; $c["projects_dir"]='+JSON.stringify(tmp+'/projects')+'; $c["state_dir"]='+JSON.stringify(tmp+'/state')+'; $c["primary_pin"]=""; $c["ip_salt"]="contrib-admin-test"; return $c;');
  fs.writeFileSync(tmp+'/projects/test/meta.json',JSON.stringify({title:'隔離測試',contrib:{kinds:['text','photo'],newSpot:'off'}}));
  fs.writeFileSync(tmp+'/projects/test/codes.json',JSON.stringify([{code:'123456',created:'2026-01-01T00:00:00+00:00',enabled:true}]));
  fs.writeFileSync(tmp+'/router.php','<?php $p=parse_url($_SERVER["REQUEST_URI"],PHP_URL_PATH); if(is_file(__DIR__.$p))return false; require __DIR__."/index.php";');
  server=spawn('php',['-S','127.0.0.1:'+port,'-t',tmp,tmp+'/router.php'],{stdio:'ignore'});
  for(let i=0;i<50;i++){try{await fetch(base);break;}catch{await new Promise(r=>setTimeout(r,100));}}
  const auth=JSON.parse(execFileSync('php',['-r','require '+JSON.stringify(tmp+'/api/security.php')+'; $cfg=require '+JSON.stringify(tmp+'/api/config.php')+'; $token=primary_session_issue($cfg,"cfg"); echo json_encode(["name"=>PRIMARY_COOKIE,"token"=>$token]);'],{encoding:'utf8'}));
  browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
  const context=await browser.newContext({timezoneId:'Asia/Taipei',locale:'zh-TW'}),page=await context.newPage(),errors=[];
  page.on('pageerror',e=>errors.push(e.message));
  await context.route('**/*',r=>new URL(r.request().url()).origin===base?r.continue():r.abort());
  await context.addCookies([{name:auth.name,value:auth.token,url:base}]);
  await page.goto(base+'/manager/test/access');
  const form=page.locator('[data-contribution-access]'),codes=page.locator('.contribution-codes');
  await page.locator('[data-pane="access"]').first().click();
  assert.equal(await form.locator('.contrib-access-body').isVisible(),false);
  assert.equal(await codes.evaluate(el=>el.open),true);
  await form.locator('[name="contrib_free_enabled"]').check();
  assert.equal(await form.locator('.contrib-access-body').isVisible(),true);
  assert.equal(await codes.evaluate(el=>el.open),false);
  await form.locator('[name="contrib_allow_newspot"]').check();
  await Promise.all([page.waitForNavigation(),form.locator('button[type="submit"]').click()]);
  let saved=JSON.parse(fs.readFileSync(tmp+'/projects/test/meta.json'));
  assert.deepEqual(saved.contributionAccess,{enabled:true,starts_at:null,expires_at:null});assert.equal(saved.contrib.newSpot,'contributor');
  for(const width of [1280,390]){
   await page.setViewportSize({width,height:844});
   assert.equal(await codes.evaluate(el=>el.open),false);
   await codes.locator('summary').first().click();assert.equal(await codes.evaluate(el=>el.open),true);
   assert.ok(await form.evaluate(el=>el.getBoundingClientRect().right<=innerWidth));
   await codes.locator('summary').first().click();
  }
  await form.locator('[name="contrib_free_mode"]').selectOption('scheduled');
  await form.locator('[name="contrib_free_start"]').fill('2030-01-01T10:00');
  await form.locator('[name="contrib_free_end"]').fill('2030-01-01T12:00');
  await Promise.all([page.waitForNavigation(),form.locator('button[type="submit"]').click()]);
  saved=JSON.parse(fs.readFileSync(tmp+'/projects/test/meta.json'));
  assert.equal(saved.contributionAccess.starts_at,'2030-01-01T02:00:00+00:00');assert.equal(saved.contributionAccessHistory.length,1);
  await form.locator('[name="contrib_free_enabled"]').uncheck();
  assert.equal(await form.locator('.contrib-access-body').isVisible(),false);
  await Promise.all([page.waitForNavigation(),form.locator('button[type="submit"]').click()]);
  await page.locator('.contribution-history summary').click();
  assert.ok((await page.locator('.contribution-history').textContent()).includes('此時段在開始前取消，未曾開放。'));
  assert.equal((await context.request.get(base+'/api/manager-contribhistory.php')).status(),404);
  assert.deepEqual(errors,[]);
  console.log('contribadmincheck: isolated saves, long-term/scheduled/cancelled history, new-place setting, desktop/mobile collapse and direct-access guard passed');
 } finally {if(browser)await browser.close();if(server)server.kill();fs.rmSync(tmp,{recursive:true,force:true});}
})().catch(e=>{console.error(e);process.exit(1);});
