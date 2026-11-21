// SPDX-License-Identifier: GPL-2.0-or-later
import {readFileSync, mkdirSync, writeFileSync} from 'node:fs';
import {join} from 'node:path';
import {buildLss, normalizeTemplate, validateTemplate} from '../../survey-templates/lib/lss.mjs';

const scales = JSON.parse(readFileSync(new URL('../../survey-templates/scales.json', import.meta.url)));
const output = process.argv[2];
if (!output) throw new Error('Usage: node templates.mjs OUTPUT_DIRECTORY');
mkdirSync(output, {recursive: true});

const q = (code, type, pl, en, extra = {}) => ({code, type, text: [pl, en], ...extra});
const option = (code, pl, en) => ({code, text: [pl,en]});
const contact = q('CONTACT', 'yesno', 'Czy chcesz, aby opiekun skontaktował się z Tobą w tej sprawie?', 'Would you like our team to contact you about this matter?');
const service = q('CSAT', 'radio', 'Jak oceniasz obsługę sklepu?', 'How satisfied are you with the service from the shop?', {options: '@satisfaction5'});
const nps = q('NPS','radio','Na ile prawdopodobne jest, że polecisz sklep znajomym?', 'How likely are you to recommend the shop?', {options: Array.from({length:11}, (_,i) => option(String(i),String(i),String(i))) });
const delivery = q('DELIVERY','radio','Jak oceniasz dostawę?', 'How satisfied are you with delivery?', {options:'@satisfaction5'});
const issues = [
  option('DAM', 'Uszkodzenie', 'Damage'), option('FIT', 'Rozmiar lub dopasowanie', 'Size or fit'),
  option('DESC', 'Niezgodność z opisem', 'Different from the description'), option('FAIL', 'Usterka podczas używania', 'Failure during use'),
  option('OTHER', 'Inny powód', 'Another reason')
];
const survey = (id, sid, title, questions, {anonymous = false, publicForm = false} = {}) => {
  const settings = {anonymized: anonymous ? 'Y' : 'N', usetokens: publicForm ? 'N' : 'Y'};
  const description = anonymous ? ['Odpowiedzi zbieramy anonimowo.', 'Your answers are collected anonymously.'] :
    ['Przesłane informacje będą powiązane z Twoim zapytaniem lub zamówieniem.', 'Your answers will be associated with your enquiry or order.'];
  const def = normalizeTemplate({id, languages: ['pl','en'], title, description, settings, groups: [{title, questions}]}, scales);
  const errors = validateTemplate(def);
  if (errors.length) throw new Error(errors.join('\n'));
  writeFileSync(join(output, id + '.lss'), buildLss(def, {sid}));
};
survey('crm-inquiry', 781601, ['Zapytanie do zespołu sprzedaży','Contact our sales team'], [
  q('COMPANY','text','Nazwa firmy','Company name', {mandatory:true}),
  q('PERSON','text','Imię i nazwisko','Contact name', {mandatory:true}),
  q('EMAIL','text','Adres e-mail','Email address', {mandatory:true}),
  q('SUBJECT','text','Czego dotyczy zapytanie?', 'What would you like to discuss?', {mandatory:true}), contact
], {publicForm:true});
survey('purchase-experience',781602,['Ocena zakupu','Purchase experience'],[service, nps, contact]);
survey('delivery',781606,['Ocena dostawy','Delivery experience'],[delivery, contact]);
const productQuestions = [];
for (let i = 1; i <= 5; ++i) {
  productQuestions.push(q('PRODUCT'+i,'radio','Jak oceniasz jakość produktu?', 'How would you rate the quality of this product?', {
    options: [...scales.quality5, option('NA','Jeszcze nie używam produktu','I have not used it yet')]
  }));
  productQuestions.push(q('DEFECT'+i,'radio','Co wpłynęło na niską ocenę?', 'What contributed to your low rating?', {
    options: issues, relevance: '(PRODUCT'+i+'.NAOK == "1" or PRODUCT'+i+'.NAOK == "2")'
  }));
}
survey('product-quality',781603,['Jakość zakupionych produktów','Quality of purchased products'],[...productQuestions, contact]);
survey('returns',781604,['Doświadczenie ze zwrotem','Return experience'],[
  q('RETURNREASON','radio','Dlaczego zwracasz produkt?', 'Why are you returning the product?', {options:[...issues, option('MIND','Zmiana decyzji','Changed my mind')]}),
  q('CSAT','radio','Jak oceniasz obsługę zwrotu?', 'How satisfied are you with the returns service?', {options:'@satisfaction5'}),
  q('RETURN','yesno','Czy potrzebujesz pomocy przy tym zwrocie?', 'Do you need assistance with this return?'), contact]);
survey('anonymous-service',781605,['Anonimowa ocena sklepu','Anonymous shop survey'],[service, delivery, q('CATEGORY','radio','Jakiej kategorii dotyczy ocena?', 'Which category are you rating?', {options:[option('GOODS','Towary','Goods'),option('SERV','Usługi','Services')]})], {anonymous:true});
survey('service-contact',781607,['Kontakt z obsługą','Contact support'],[
  q('PERSON','text','Jak się do Ciebie zwracać?', 'What is your name?', {mandatory:true}),
  q('EMAIL','text','Na jaki adres możemy odpisać?', 'Where may we email you?', {mandatory:true}),
  q('SUBJECT','text','W czym możemy pomóc?', 'How can we help?', {mandatory:true}),contact
], {publicForm:true});
