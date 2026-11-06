// Run after vps_compatibility.py has seeded activities in the disposable fixture server.
const {chromium}=require('playwright');
const assert=require('node:assert/strict');
(async()=>{
  const url=process.env.MAY_TEST_URL||'http://127.0.0.1:8082';
  assert.match(url,/^http:\/\/(127\.0\.0\.1|localhost):/);
  const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH||'/usr/bin/chromium',headless:true,args:['--no-sandbox']});
  try{
    const context=await browser.newContext({viewport:{width:1024,height:768},serviceWorkers:'block'});
    await context.addInitScript(()=>{localStorage.setItem('may_pin',JSON.stringify('test-child'));localStorage.setItem('may_autoRead','false');});
    const page=await context.newPage(),errors=[];
    page.on('pageerror',e=>errors.push(e.message));
    await page.goto(url,{waitUntil:'domcontentloaded'});
    await page.locator('#ccResume').click();
    await page.locator('#ccActivity #sciText').waitFor();
    await page.locator('#ccReflect').click();
    await page.locator('#ccActions').click();
    await page.locator('[data-cc-turn="direct"]').click();
    await page.locator('[data-cc-pick="tv"]').click();
    await page.locator('[data-cc-start="tvw"]').click();
    await page.locator('#ccActivity [data-tvk]').first().waitFor();
    await page.reload();
    await page.locator('#ccResume').click();
    await page.locator('#ccActivity [data-tvk]').first().waitFor();
    assert.equal(await page.locator('.cc-with-workspace').count(),1);
    assert.deepEqual(errors,[]);
    console.log('PASS VPS browser compatibility: Science and TV activities render and resume inside the chat shell');
  }finally{await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
