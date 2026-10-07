const fs=require('fs'),path=require('path'),assert=require('assert');
const {execFileSync}=require('child_process');
const {chromium}=require(process.env.PLAYWRIGHT_PATH||'/workspace/.cloud-setup/browser/node_modules/playwright-core');
(async()=>{
 const root=path.resolve(__dirname,'..');
 const source='**經典日常**\n李奕智\n作品簡述\n\n第二段說明\n\n```\n  第一行\n    第二行\n```';
 const html=execFileSync('php',['-r','require $argv[1]; echo Markdown::toHtml($argv[2]);',root+'/api/markdown.php',source],{encoding:'utf8'});
 const browser=await chromium.launch({executablePath:'/usr/bin/chromium',args:['--no-sandbox']});
 try {
  const page=await browser.newPage();
  for(const width of [390,1280]){
   await page.setViewportSize({width,height:900});
   await page.setContent('<style>'+fs.readFileSync(root+'/assets/css/spot-panel.css','utf8')+fs.readFileSync(root+'/assets/css/lightbox.css','utf8')+'\n.fixture{width:calc(100% - 40px);max-width:600px;margin:20px} #lb{display:block;position:static} #lb .cap{position:static}</style><div class="entry fixture"><div class="txt sc-md">'+html+'</div><div class="txt plain">第一行\n第二行\n第三行</div></div><div id="lb" class="fixture"><div class="cap"><div class="lb-txt sc-md">'+html+'</div><div class="lb-txt plain">第一行\n第二行\n第三行</div></div></div>');
   for(const selector of ['.entry .txt.sc-md','#lb .cap .lb-txt.sc-md']){
    const result=await page.locator(selector).evaluate(el=>{
     const top=text=>{const walker=document.createTreeWalker(el,NodeFilter.SHOW_TEXT);let node;while(node=walker.nextNode()){const i=node.textContent.indexOf(text);if(i>=0){const range=document.createRange();range.setStart(node,i);range.setEnd(node,i+text.length);return range.getBoundingClientRect().top;}}};
     return {space:getComputedStyle(el).whiteSpace,line:parseFloat(getComputedStyle(el).lineHeight),tops:['經典日常','李奕智','作品簡述'].map(top),paragraphs:el.querySelectorAll('p').length,pre:getComputedStyle(el.querySelector('pre')).whiteSpace,code:el.querySelector('pre').textContent,strong:el.querySelector('strong').textContent};
    });
    assert.equal(result.space,'normal');assert.equal(result.strong,'經典日常');
    for(let i=1;i<3;i++)assert(Math.abs(result.tops[i]-result.tops[i-1]-result.line)<2,JSON.stringify(result));
    assert.equal(result.paragraphs,2);assert.equal(result.pre,'pre');assert(result.code.includes('  第一行\n    第二行'));
   }
   for(const selector of ['.entry .plain','#lb .cap .plain'])assert.equal(await page.locator(selector).evaluate(el=>getComputedStyle(el).whiteSpace),'pre-wrap');
  }
  console.log('PASS：手機／桌面投稿卡片與燈箱單次換行、粗體、段落、程式碼及純文字空白');
 }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
