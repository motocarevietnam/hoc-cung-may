// Real page/CSS with synthetic API responses: reproduce a long mobile recap without touching child data.
const {chromium}=require('playwright');
const http=require('node:http');
const fs=require('node:fs');
const path=require('node:path');
const assert=require('node:assert/strict');
(async()=>{
  const root=path.resolve(__dirname,'../public');
  const server=http.createServer((req,res)=>{
    const file=path.join(root,req.url==='/'?'index.html':req.url.split('?')[0]);
    if(!file.startsWith(root+path.sep)||!fs.existsSync(file)){res.writeHead(404);return res.end();}
    res.setHeader('Content-Type',file.endsWith('.css')?'text/css':file.endsWith('.js')?'text/javascript':file.endsWith('.html')?'text/html':'application/octet-stream');
    res.end(fs.readFileSync(file));
  });
  await new Promise(r=>server.listen(0,'127.0.0.1',r));
  const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
  try{
    const context=await browser.newContext({viewport:{width:393,height:852},serviceWorkers:'block',reducedMotion:'reduce'});
    await context.addInitScript(()=>{localStorage.setItem('may_pin',JSON.stringify('fixture'));localStorage.setItem('may_autoRead','false');});
    const page=await context.newPage(),errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    const subjects=[['toan','Toán'],['tv','Tiếng Việt'],['cmath','Maths'],['ceng','English'],['csci','Science']].map(([id,name])=>({id,name,modes:{homework:'Bài cô giao',quiz:'Đố vui',chat:'Hỏi bài Mây'}}));
    const expected=[['global','Công dân toàn cầu',null],['vietnamese','Tiếng Việt','tv'],['math','Toán','toan'],['english','Tiếng Anh ET','ceng'],['science','Science Cambridge','csci']].map(([key,label,subject])=>({key,label,subject}));
    const messages=Array.from({length:13},(_,i)=>({id:i+1,role:i%2?'user':'assistant',content:i%2?'Mình muốn kể môn Science. Hôm nay mình có chuyện muốn kể với Mây.':'Mây đang nghe Kem kể. Kem có thể nói bằng lời của mình, đổi môn hoặc nghỉ bất cứ lúc nào nhé.'}));
    const session={id:1,revision:0,state:{phase:'SCHOOL_RECAP',selected:expected[4],recaps:[],active_card:null},messages};
    await page.route('**/api/**',async route=>{
      let data={};
      const endpoint=new URL(route.request().url()).pathname;
      if(endpoint==='/api/state')data={nick:'Kem',stars:53,chat_first:true};
      else if(endpoint==='/api/plan')data={day:{items:[],notes:[]}};
      else {
        if(endpoint==='/api/companion/turn'){
          const b=route.request().postDataJSON();session.revision++;
          session.messages.push({id:session.messages.length+1,role:'user',content:b.text||'Mình muốn đổi môn.'},{id:session.messages.length+2,role:'assistant',content:'Mây nghe rồi. Kem kể tiếp bằng cách của mình nhé.'});
          if(b.type==='subject')session.state.selected=expected.find(x=>x.key===b.subject_key);
        }
        data={session,context:{expected,subjects,retention_days:30},stars:53};
      }
      await route.fulfill({json:data});
    });
    await page.goto('http://127.0.0.1:'+server.address().port,{waitUntil:'domcontentloaded'});
    await page.waitForFunction(()=>document.querySelectorAll('.cc-history .msg').length===13);
    await page.waitForFunction(()=>{const f=document.querySelector('.cc-feed');return f.scrollTop>0;});
    const geometry=await page.evaluate(()=>{
      const feed=document.querySelector('.cc-feed'), composer=document.querySelector('.cc-compose');
      const last=document.querySelector('.cc-history .msg:last-child');
      return {feedHeight:feed.clientHeight,hasScroll:feed.scrollHeight>feed.clientHeight,
        lastHeight:last.clientHeight,lastTextHeight:last.querySelector('.bubble').scrollHeight,
        lastBottom:last.getBoundingClientRect().bottom,composerTop:composer.getBoundingClientRect().top,
        composerBottom:composer.getBoundingClientRect().bottom,viewport:innerHeight,
        overflow:document.documentElement.scrollWidth>innerWidth,background:getComputedStyle(document.body).backgroundImage};
    });
    assert(geometry.feedHeight>500,JSON.stringify(geometry));
    assert(geometry.hasScroll);
    assert(geometry.lastHeight>=geometry.lastTextHeight,'Messages must not shrink and clip their text');
    assert(geometry.lastBottom<=geometry.composerTop,'Composer must not overlap the latest message');
    assert(geometry.composerBottom<=geometry.viewport+1);
    assert.equal(geometry.overflow,false);
    assert.equal(geometry.background,'none');
    assert.equal(await page.locator('.cc-quick').count(),0,'Repeated actions stay collapsed after chatting');
    await page.locator('#ccBox').fill('Nội dung Kem đang gõ');
    await page.locator('#ccSchedule').click();
    const dialog=page.locator('#ccScheduleDialog');await dialog.waitFor({state:'visible'});
    const bounds=await dialog.boundingBox();assert(bounds.y>=0&&bounds.y+bounds.height<=852);
    assert.equal(await dialog.getByRole('button',{name:'Maths',exact:true}).isVisible(),false,'Other subjects are not presented as scheduled lessons');
    await dialog.getByRole('button',{name:'Toán',exact:true}).click();
    await page.waitForFunction(()=>!document.querySelector('#ccScheduleDialog'));
    assert.equal(await page.locator('#ccBox').inputValue(),'Nội dung Kem đang gõ');
    await page.screenshot({path:'/tmp/may-chat-mobile-clean.png'});
    await page.locator('#ccActions').click();
    assert.equal(await page.locator('.cc-quick button').count(),4);
    await page.locator('#ccActions').click();
    await page.setViewportSize({width:393,height:430});
    await page.locator('#ccBox').focus();
    await page.waitForFunction(()=>document.querySelector('.cc-compose').getBoundingClientRect().bottom<=innerHeight+1);
    assert(await page.locator('.cc-feed').evaluate(e=>e.clientHeight>90));
    await page.setViewportSize({width:1024,height:768});
    await page.screenshot({path:'/tmp/may-chat-ipad-clean.png'});
    assert.deepEqual(errors,[]);
    console.log('PASS mobile layout: full-height feed, no clipped messages, compact actions, schedule dialog, preserved draft and resized keyboard viewport');
  }finally{await browser.close();await new Promise(r=>server.close(r));}
})().catch(e=>{console.error(e);process.exitCode=1;});
