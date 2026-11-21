// SPDX-License-Identifier: GPL-2.0-or-later
// Exercise the actual administrator upload, confirmation, activation and update in a disposable CE installation.
const assert = require('node:assert/strict');
const {readFile} = require('node:fs/promises');
const {join} = require('node:path');
const {chromium} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

(async () => {
    const [base, dist, mode] = process.argv.slice(2);
    assert.equal(new URL(base).hostname, '127.0.0.1');
    assert.ok(['install','update','refresh'].includes(mode));
    const browser = await chromium.launch({executablePath: process.env.CHROMIUM_PATH});
    const page = await browser.newPage();
    try {
        await page.goto(base+'/index.php/admin');
        await page.locator('#user').fill(process.env.LC_TEST_LOGIN);
        await page.locator('#password').fill(process.env.LC_TEST_PASSWORD);
        await page.locator('[name=login_submit]').click();
        await page.locator('#user').waitFor({state:'detached'});
        const manager = base+'/index.php/admin/pluginmanager/sa';
        async function csrf() {
            const field = page.locator('input[type=hidden][name*=CSRF i]').first();
            return {[await field.getAttribute('name')]: await field.inputValue()};
        }
        for (const name of ['LimeControlCrm','LimeControlFeedback','LimeControlCrmDesk']) {
            await page.goto(manager+'/index?pageSize=100');
            const filename = name+'-0.1.0.zip';
            const response = await page.request.post(manager+'/upload', {multipart:{...await csrf(),
                the_file:{name:filename,mimeType:'application/zip',buffer:await readFile(join(dist,filename))}}});
            assert.match(response.url(),/uploadConfirm/);
            await page.goto(response.url());
            const update = await page.locator('input[name=isUpdate]').inputValue()==='true';
            if (mode!=='refresh') assert.equal(update,mode==='update',name+' operation');
            const install = page.locator('form[action*=installUploadedPlugin]');
            await install.locator('button[type=submit],input[type=submit]').last().click();
            await page.goto(manager+'/index?pageSize=100');
            const row=page.locator('tr').filter({has:page.getByText(name,{exact:true})}).first();
            await row.waitFor();
            if (!update || !(await row.locator('td .ri-checkbox-blank-circle-fill, td .ri-play-fill').count())) {
                const toggle=row.locator('[data-plugin-id],[data-pluginid],[data-id]').first();
                const id=await row.getAttribute('data-id') || await toggle.evaluate(n=>n.dataset.pluginId || n.dataset.pluginid || n.dataset.id);
                const activation=await page.request.post(manager+'/activate',{form:{...await csrf(),pluginId:id}});
                assert.equal(activation.status(),200,name+' activation');
                if (/fatal plugin error|fatal error/i.test(await activation.text())) throw Error(name+' failed to load');
                await page.goto(manager+'/index?pageSize=100');
            }
            assert.ok(await row.locator('td .ri-checkbox-blank-circle-fill, td .ri-play-fill').count(),name+' must be active');
            console.log(new URL(base).port, name,update?'updated':'installed');
        }
        await page.goto(base+'/index.php/admin');
        const crm=page.locator('a[href*=LimeControlCrm]').filter({hasText:/^\s*CRM\s*$/}).first();
        await crm.waitFor({state:'attached'});
        await page.goto(await crm.evaluate(el=>el.href));
        await page.locator('main').waitFor();
        assert.match(await page.locator('body').innerText(),/Zesp|CRM/);
    } catch (error) {
        console.error('Last page',page.url(),(await page.locator('body').innerText()).slice(-650));
        throw error;
    } finally {await browser.close();}
})().catch(e=>{console.error(e);process.exitCode=1;});
