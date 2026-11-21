// SPDX-License-Identifier: GPL-2.0-or-later
const {chromium}=require(process.env.PLAYWRIGHT_MODULE || 'playwright');
const assert=require('node:assert/strict');
const fs=require('node:fs/promises');

(async()=>{
    const [base,team,deal,caseId,task,pdfPath]=process.argv.slice(2);
    assert.equal(new URL(base).hostname,'127.0.0.1');
    const browser=await chromium.launch({executablePath:process.env.CHROMIUM_PATH});
    const page=await browser.newPage();
    const panel=(type,id)=>base+'/index.php/plugins/direct?'+new URLSearchParams({plugin:'LimeControlCrmDesk',function:'panel',type,id,team});
    const record=(type,id)=>base+'/index.php/plugins/direct?'+new URLSearchParams({plugin:'LimeControlCrm',function:'panel',type,id,team,tab:'record'});
    try {
        await page.goto(base+'/index.php/admin');
        await page.locator('#user').fill(process.env.LC_TEST_LOGIN);
        await page.locator('#password').fill(process.env.LC_TEST_PASSWORD);
        await page.locator('[name=login_submit]').click();
        await page.locator('#user').waitFor({state:'detached'});
        await page.goto(record('deal',deal));
        await page.locator('a[href*=LimeControlCrmDesk]').first().click();
        await page.locator('[name="name[]"]').first().fill('Żuraw – wdrożenie');
        await page.locator('[name="qty[]"]').first().fill('2');
        await page.locator('[name="price[]"]').first().fill('124.51');
        await page.locator('[name="customer"]').fill('Pracownia Żuraw');
        await page.locator('#lcdesk-add').click();
        assert.ok(await page.locator('[name="name[]"]').count()>=4);
        await page.locator('form').filter({has:page.locator('[name="previous"]')}).locator('button[type=submit],button:not([type])').last().click();
        const offer=page.locator('section').filter({has:page.locator('a[href*=pdf]')}).first();
        const pdf=await page.request.get(await offer.locator('a[href*=pdf]').getAttribute('href').then(h=>new URL(h,base).href));
        assert.equal(pdf.headers()['content-type'],'application/pdf');
        const bytes=await pdf.body(); assert.equal(bytes.subarray(0,4).toString(),'%PDF');
        if (pdfPath) await fs.writeFile(pdfPath,bytes);
        await offer.locator('button[value=accepted]').click();
        await page.getByText(/Accepted|Przyjęta/).first().waitFor();
        await page.goto(panel('case',caseId));
        if (await page.locator('input[name=mail]').count()) await page.locator('form').filter({has:page.locator('input[name=mail]')}).first().locator('button').click();
        assert.match(await page.locator('body').innerText(),/Żuraw: odbieramy/);
        await page.goto(panel('task',task));
        await page.locator('button[value=issue]').click();
        const key=await page.locator('input[readonly]').inputValue();
        const outsider=await browser.newContext();
        const feed=await outsider.request.get(key);
        assert.match(await feed.text(),/BEGIN:VEVENT[\s\S]*Oddzwonić do klienta/);
        await page.locator('button[value=revoke]').click();
        assert.equal((await outsider.request.get(key)).status(),404);
        assert.equal((await outsider.request.get(panel('deal',deal))).status(),403);
        console.log('OK', new URL(base).port,'record link, editable revision, PDF, decision, message assignment and revocable calendar');
    } catch(e) {
        console.error(page.url(),(await page.locator('body').innerText()).slice(0,1300));
        throw e;
    } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
