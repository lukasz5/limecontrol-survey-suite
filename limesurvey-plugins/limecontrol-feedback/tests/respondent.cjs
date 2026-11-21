const assert = require('node:assert/strict');
const { chromium } = require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const fs = require('node:fs');
const spec = JSON.parse(fs.readFileSync(0,'utf8'));
if (!['localhost','127.0.0.1'].includes(new URL(spec.base).hostname)) throw Error('Loopback QA only');

(async () => {
  const browser = await chromium.launch({headless:true, ...(process.env.CHROME_PATH ? {executablePath:process.env.CHROME_PATH} : {})});
  try {
    for (const form of spec.forms) {
      const page = await browser.newPage();
      // These URLs originate from captured local SMTP messages and are rebased onto the disposable fixture.
      await page.goto(form.url);
      const next = page.locator('[name=move][value=movenext]:visible').first();
      if (await next.count()) await next.click();
      const item = code => page.locator('#question'+form.questions[code]);
      const choose = async (code,value) => {
        const radio = item(code).locator('input[type=radio][value="'+value+'"]');
        assert.equal(await radio.count(),1,code);
        await radio.evaluate(el=>el.click());
      };
      if (form.kind==='product') {
        await item('PRODUCT1').waitFor();
        assert((await item('PRODUCT1').innerText()).includes('<em>Kept item</em>'));
        assert.equal(await item('PRODUCT1').locator('em').filter({hasText:'Kept item'}).count(),0);
        assert.equal(await item('PRODUCT2').isVisible(),false);
        await choose('PRODUCT1','1');
        await choose('DEFECT1','DAM');
        await choose('CONTACT','Y');
      } else if (form.kind==='returns') {
        await item('RETURNREASON').waitFor();
        await choose('RETURNREASON','DAM');
        await choose('CSAT','5');
        await choose('CONTACT','N');
      } else {
        await item('CSAT').waitFor();
        assert(!(await page.locator('body').innerText()).includes('Kept item'));
        await choose('CSAT','2');
        await choose('DELIVERY','5');
        await choose('CATEGORY','GOODS');
      }
      await page.locator('[name=move][value=movesubmit]:visible').first().click();
      await page.waitForFunction(()=>!document.querySelector('#limesurvey [name=move][value=movesubmit]'));
      await page.close();
    }
    const product = spec.forms.find(f=>f.kind==='product').sid;
    const anon = spec.forms.find(f=>f.kind==='anonymous').sid;
    const page = await browser.newPage();
    await page.goto(spec.optout);
    const confirmation = page.locator('main button[type=submit]');
    await confirmation.click();
    assert(await page.locator('main [role=status]').isVisible());
    const url = (action,sid) => spec.base+'/index.php/plugins/direct?plugin=LimeControlFeedback&function='+action+'&sid='+sid;
    assert.equal((await page.goto(url('report',product))).status(),403);
    await page.goto(spec.base+'/index.php/admin');
    await page.locator('#user').fill(process.env.LCF_QA_USER);
    await page.locator('#password').fill(process.env.LCF_QA_PASSWORD);
    await page.locator('[name=login_submit]').click();
    await page.locator('#user').waitFor({state:'detached'});
    assert.equal((await page.goto(url('report',product))).status(),200);
    assert((await page.locator('main').innerText()).includes('sku.defect'));
    assert.equal((await page.goto(url('cases',product))).status(),200);
    const stage = page.locator('main select[name=stage]');
    assert.equal(await stage.count(),1);
    await stage.selectOption('closed');
    await page.locator('main button[type=submit]').click();
    assert.equal(await stage.inputValue(),'closed');
    assert.equal((await page.goto(url('comparison',product))).status(),200);
    const retained = page.locator('main tr').filter({hasText:'SKU KEEP'});
    const returned = page.locator('main tr').filter({hasText:'SKU RETURNED'});
    assert.equal((await retained.locator('td').allTextContents())[0],'1');
    assert.equal((await retained.locator('td').allTextContents())[2],'0');
    assert.equal((await returned.locator('td').allTextContents())[0],'0');
    assert.equal((await returned.locator('td').allTextContents())[2],'1');
    assert.equal((await page.goto(url('comparison',anon))).status(),403);
    assert.equal((await page.goto(url('report',anon))).status(),200);
    const anonText = await page.locator('main').innerText();
    assert(!anonText.includes('synthetic-buyer') && !anonText.includes('KEEP') && !anonText.includes('DAM'));
    assert.equal((await page.goto(url('cases',anon))).status(),403);
    await page.close();
  } finally {await browser.close();}
})().catch(err=>{console.error(err);process.exitCode=1;});
