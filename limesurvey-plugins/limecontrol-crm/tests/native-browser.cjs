// SPDX-License-Identifier: GPL-2.0-or-later
// Run only on a disposable local CE installation with active CRM, JSON-RPC, and an enabled team.
const assert = require('node:assert/strict');
const fs = require('node:fs/promises');
const {chromium, request} = require(process.env.PLAYWRIGHT_MODULE || 'playwright');

async function run() {
    const [base, fixture, serviceFixture, anonymousFixture] = process.argv.slice(2);
    assert(['localhost','127.0.0.1'].includes(new URL(base).hostname));
    const user = process.env.LC_CRM_TEST_USER, password = process.env.LC_CRM_TEST_PASSWORD;
    assert(user && password && fixture, 'Set synthetic credentials and pass a local LSS fixture');
    const stamp = Date.now().toString(36);
    const company = 'Synthetic company ' + stamp, person = 'Synthetic Buyer', email = 'buyer@example.invalid';
    const subject = 'Flow ' + stamp, rejected = 'Refused ' + stamp;
    const browser = await chromium.launch(process.env.CHROMIUM_PATH ? {executablePath:process.env.CHROMIUM_PATH} : {});
    const rpcSession = await request.newContext();
    let key;
    async function rpc(method, params) {
        const r = await rpcSession.post(base+'/index.php/admin/remotecontrol',{data:{id:1,method,params}, headers:{Accept:'application/json'}});
        assert.equal(r.status(),200,method); const payload=await r.json(); assert(!payload.error,method); return payload.result;
    }
    async function submit(sid, label, consent) {
        const context=await browser.newContext(); const p=await context.newPage();
        try {
            await p.goto(base+'/index.php/'+sid+'?lang=pl&newtest=Y');
            const group=p.locator('.question-container').filter({hasText:'Nazwa firmy'});
            if (!await group.isVisible()) {await p.locator('#ls-button-submit,[name=move][value=movenext]').filter({visible:true}).first().click();}
            for(const [question,value] of [['Nazwa firmy',company],['Imię i nazwisko',person],['Adres e-mail',email],['Czego dotyczy zapytanie?',label]]) {
                await p.locator('.question-container').filter({hasText:question}).locator('input[type=text], input[type=email], textarea').first().fill(value);
            }
            const q=p.locator('.question-container').filter({hasText:'Czy chcesz, aby'});
            const choice=q.locator('input[type=radio][value="'+(consent?'Y':'N')+'"]');
            const id=await choice.getAttribute('id'); await q.locator('label[for="'+id+'"]').click();
            await p.locator('#ls-button-submit,[name=move][value=movesubmit]').filter({visible:true}).first().click();
            await p.locator('.question-container').first().waitFor({state:'detached'});
            assert(!await p.locator('.errorSummary,.alert-danger').filter({visible:true}).count(),'Survey completed without validation errors');
        } finally {await context.close();}
    }
    try {
        key=await rpc('get_session_key',[user,password]);assert.equal(typeof key,'string');
        const lss=await fs.readFile(fixture);
        const sid=Number(await rpc('import_survey',[key,lss.toString('base64'),'lss','CRM test '+stamp]));assert(sid>0,'Imported LSS');
        assert.equal((await rpc('activate_survey',[key,sid,{anonymized:'N'}])).status,'OK','Activated public LSS');
        const p=await browser.newPage(); p.setDefaultTimeout(12000);
        await p.goto(base+'/index.php/admin'); await p.locator('#user').fill(user); await p.locator('#password').fill(password);
        await p.locator('[name=login_submit]').click();
        const menu=p.locator('a.nav-link[href*="LimeControlCrm"]').filter({hasText:'CRM'}).first();
        try { await menu.waitFor(); } catch (e) {throw Error('Main menu unavailable: '+new URL(p.url()).pathname+' | '+(await p.locator('body').innerText()).slice(0,1200));}
        for (let i=0;i<10;i++) {
            const dismissal=p.locator('#admin-notification-modal.show button[data-bs-dismiss=modal]');
            if (await dismissal.isVisible()) {await dismissal.click(); await dismissal.waitFor({state:'hidden'});}
            try {await menu.click({timeout:1000});break;} catch (e) {if(i===9) throw e;}
        }
        if (await p.getByRole('heading',{name:'Zespoły CRM'}).isVisible()) {
            const panel=p.url(); const name='CRM test '+stamp;
            await p.goto(base+'/index.php/userGroup/addGroup'); await p.locator('#group_name').fill(name);
            await p.locator('#group_name').press('Enter'); await p.locator('#group_name').waitFor({state:'detached'});
            await p.goto(panel); await p.getByLabel('Grupa użytkowników LimeSurvey').selectOption({label:name});
            await p.getByRole('button',{name:'Włącz CRM dla grupy'}).click();
        }
        await p.getByRole('heading',{name:'Lejek sprzedaży'}).waitFor();
        await p.getByRole('link',{name:'Ustawienia',exact:true}).click();
        for(const [k,v] of Object.entries({survey:String(sid),consent:'CONTACT',person:'PERSON',email:'EMAIL',company:'COMPANY',subject:'SUBJECT'})) {await p.locator('input[name='+k+']').fill(v);}
        await p.getByRole('button',{name:'Zapisz mapowanie'}).click(); await p.getByRole('status').filter({hasText:'Zapisano mapowanie'}).waitFor();
        await submit(sid,subject,true);await submit(sid,rejected,false);
        await p.getByRole('link',{name:'Szanse sprzedaży',exact:true}).click();
        await p.getByLabel('Wyszukaj').fill(subject);await p.getByRole('button',{name:'Filtruj'}).click();
        const found=p.getByRole('link',{name:subject,exact:true}); assert.equal(await found.count(),1);await found.click();
        const card=p.locator('dl'); assert((await card.innerText()).includes(email)); assert((await card.innerText()).includes(company)); assert((await card.innerText()).includes(person));
        await p.getByRole('link',{name:'Edytuj',exact:true}).click();await p.locator('select[name="record[status]"]').selectOption('won');
        await p.locator('input[name="record[amount]"]').fill('123.45');await p.getByRole('button',{name:'Zapisz',exact:true}).click();
        await p.getByRole('heading',{name:subject,exact:true}).waitFor(); assert((await p.locator('dl').innerText()).includes('Wygrana'));
        await p.getByRole('link',{name:'Dodaj notatkę lub rozmowę'}).click(); await p.locator('input[name="record[title]"]').fill('Call '+stamp);
        await p.locator('textarea[name="record[body]"]').fill('Synthetic call summary');await p.locator('select[name="record[status]"]').selectOption('call');await p.getByRole('button',{name:'Zapisz',exact:true}).click();
        await p.getByRole('heading',{name:'Call '+stamp,exact:true}).waitFor();await p.getByRole('link',{name:subject,exact:true}).click();
        await p.getByRole('link',{name:'Call '+stamp,exact:true}).waitFor();
        await p.getByRole('link',{name:'Szanse sprzedaży',exact:true}).click(); await p.getByLabel('Wyszukaj').fill(rejected); await p.getByRole('button',{name:'Filtruj'}).click();
        await p.getByText('Brak pasujących rekordów.').waitFor();await p.getByRole('link',{name:'Raporty',exact:true}).click();await p.getByRole('heading',{name:'Raport kierownika'}).waitFor();
        const cpdbId=require('node:crypto').randomUUID(), contactName='Contact '+stamp, contactEmail='buyer-'+stamp+'@example.invalid';
        const imported=await rpc('cpd_importParticipants',[key,[{participant_id:cpdbId,firstname:'Synthetic',lastname:'Buyer',email:contactEmail,language:'pl',blacklisted:'N'}]]);
        assert.equal(Number(imported.ImportCount),1,'Imported explicitly selected central participant');
        await rpc('activate_tokens',[key,sid]);
        const participants=await rpc('add_participants',[key,sid,[{participant_id:cpdbId,firstname:'Synthetic',lastname:'Buyer',email:contactEmail,language:'pl'}],true]);
        assert(Number(participants[0]?.tid)>0,'Created native survey participant');
        await p.getByRole('link',{name:'CSV',exact:true}).click();
        const makeCsv=(name)=>({name:'synthetic.csv',mimeType:'text/csv',buffer:Buffer.from('company_id,name,email,phone,tags\n,'+name+','+contactEmail+',,test\n')});
        await p.locator('input[type=file]').setInputFiles(makeCsv(contactName));await p.getByRole('button',{name:'Pokaż podgląd'}).click();
        await p.getByRole('heading',{name:'Podgląd importu'}).waitFor();await p.getByRole('button',{name:'Zastosuj import'}).click();
        await p.getByRole('link',{name:contactName,exact:true}).click(); assert(!(await p.locator('dl').innerText()).includes(cpdbId));
        await p.getByText('Powiąż z uczestnikiem CPDB').click(); await p.getByLabel('Numer zmapowanej ankiety').fill(String(sid));
        await p.getByLabel('ID uczestnika ankiety (TID)').fill(String(participants[0].tid));await p.getByRole('button',{name:'Powiąż wskazanego uczestnika'}).click();
        await p.locator('dl').getByText(cpdbId,{exact:true}).waitFor();
        await p.getByRole('link',{name:'CSV',exact:true}).click();await p.locator('input[type=file]').setInputFiles(makeCsv(contactName+' updated'));
        await p.getByRole('button',{name:'Pokaż podgląd'}).click();await p.getByRole('checkbox',{name:/Aktualizuj kontakt/}).check();await p.getByRole('button',{name:'Zastosuj import'}).click();
        await p.getByRole('link',{name:contactName+' updated',exact:true}).click(); await p.locator('dl').getByText(cpdbId,{exact:true}).waitFor();
        let anonymousSupport=false;
        if(serviceFixture && anonymousFixture) {
            async function importSurvey(file,title,anonymous) {
                const input=await fs.readFile(file); const id=Number(await rpc('import_survey',[key,input.toString('base64'),'lss',title+' '+stamp]));
                assert(id>0); assert.equal((await rpc('activate_survey',[key,id,{anonymized:anonymous?'Y':'N'}])).status,'OK'); return id;
            }
            const serviceId=await importSurvey(serviceFixture,'Service request',false);
            const anonId=await importSurvey(anonymousFixture,'Anonymous service',true);
            const publicUrl=base+'/index.php/'+serviceId;
            await rpc('set_language_properties',[key,anonId,{surveyls_endtext:'<a href="'+publicUrl+'" rel="noreferrer">Otwórz formularz obsługi</a>'},'pl']);
            await p.getByRole('link',{name:'Ustawienia',exact:true}).click();
            for(const [k,v] of Object.entries({survey:String(serviceId),consent:'CONTACT',person:'PERSON',email:'EMAIL',subject:'SUBJECT'})){await p.locator('input[name='+k+']').fill(v);}
            await p.getByLabel('Przeznaczenie formularza').selectOption('case');await p.getByRole('button',{name:'Zapisz mapowanie'}).click();
            await p.getByRole('status').filter({hasText:'Zapisano mapowanie'}).waitFor();
            const ctx=await browser.newContext();const guest=await ctx.newPage();
            try {
                await guest.goto(base+'/index.php/'+anonId+'?lang=pl&newtest=Y');
                if (!await guest.locator('.question-container').first().isVisible()) {await guest.locator('#ls-button-submit,[name=move][value=movenext]').filter({visible:true}).first().click();}
                await guest.locator('#ls-button-submit,[name=move][value=movesubmit]').filter({visible:true}).first().click();
                const independentLink=guest.getByRole('link',{name:'Otwórz formularz obsługi'}); assert.equal(await independentLink.getAttribute('href'),publicUrl);
                await independentLink.click();
                if (!await guest.locator('.question-container').first().isVisible()) {await guest.locator('#ls-button-submit,[name=move][value=movenext]').filter({visible:true}).first().click();}
                const helpCase='Support '+stamp;
                for(const [q,value] of [['Jak się do Ciebie zwracać?',person],['Na jaki adres możemy odpisać?',email],['W czym możemy pomóc?',helpCase]]){
                    await guest.locator('.question-container').filter({hasText:q}).locator('input[type=text],input[type=email],textarea').first().fill(value);
                }
                const consent=guest.locator('.question-container').filter({hasText:'Czy chcesz, aby'});
                const yes=await consent.locator('input[type=radio][value=Y]').getAttribute('id');await consent.locator('label[for="'+yes+'"]').click();
                await guest.locator('#ls-button-submit,[name=move][value=movesubmit]').filter({visible:true}).first().click();await guest.locator('.question-container').first().waitFor({state:'detached'});
                await p.getByRole('link',{name:'Sprawy',exact:true}).click();await p.getByLabel('Wyszukaj').fill(helpCase);await p.getByRole('button',{name:'Filtruj'}).click();
                await p.getByRole('link',{name:helpCase,exact:true}).click();const serviceCard=await p.locator('dl').innerText();
                assert(serviceCard.includes(person) && serviceCard.includes(email) && !serviceCard.includes('Zamówienie') && !serviceCard.includes('SKU'));
                const caseOwner=await p.locator('dt').filter({hasText:'Opiekun'}).locator('xpath=following-sibling::dd[1]').innerText();assert(caseOwner && caseOwner!=='Brak','Service case assigned to eligible team operator');
                anonymousSupport=true;
            } finally {await ctx.close();}
        }
        console.log(JSON.stringify({survey:sid,menu:true,identifiedPublicLead:true,refusedContactSkipped:true,search:true,won:true,callHistory:true,managerReport:true,nativeCpdb:true,csvMerge:true,anonymousSupport}));
    } finally {if(key) await rpc('release_session_key',[key]);await rpcSession.dispose();await browser.close();}
}
run().catch(e=>{console.error(e);process.exitCode=1;});
