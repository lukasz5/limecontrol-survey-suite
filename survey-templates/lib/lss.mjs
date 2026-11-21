// Generator plików struktury ankiety LimeSurvey (.lss) z definicji JSON.
//
// Format wyjściowy to „LimeSurveyDocType Survey” z tabelami *_l10ns (LimeSurvey 4+),
// taki sam jak eksport „Struktura ankiety (.lss)” z panelu. Sprawdzony importem
// na LimeSurvey 6.15.12 i 7.10.0 (patrz ../verify.mjs).
//
// Bez zależności — działa w Node 20+ i w przeglądarce.

/** Wersja schematu bazy zapisywana w pliku (importer LS 6/7 przyjmuje starsze i nowsze). */
export const DB_VERSION = 623;

/** Typy pytań z definicji → kod typu LimeSurvey. */
export const QUESTION_TYPES = {
  radio: 'L', // lista (radio), opcjonalnie „Inne”
  dropdown: '!', // lista rozwijana
  checkbox: 'M', // wielokrotny wybór (opcje = podpytania)
  scale5: '5', // wybór 1–5
  yesno: 'Y', // tak / nie
  text: 'S', // krótki tekst
  longtext: 'T', // długi tekst
  number: 'N', // liczba
  date: 'D', // data
  array: 'F', // tablica: wiersze (podpytania) × kolumny (opcje)
  ranking: 'R', // ranking opcji
  multitext: 'Q', // kilka krótkich pól tekstowych
  info: 'X', // tekst bez odpowiedzi (np. skrypt dla ankietera)
  equation: '*', // równanie ExpressionScript (pole wyliczane)
};

const TYPES_WITH_OPTIONS = new Set(['radio', 'dropdown', 'array', 'ranking']);
const TYPES_WITH_SUBQUESTIONS = new Set(['checkbox', 'array', 'multitext']);
const TYPES_WITH_OTHER = new Set(['radio', 'dropdown', 'checkbox']);

const CODE_RE = /^[A-Za-z][A-Za-z0-9]{0,19}$/;
const SUBCODE_RE = /^[A-Za-z0-9]{1,20}$/;
const ANSWER_CODE_RE = /^[A-Za-z0-9]{1,5}$/;

/** Ustawienia ankiety obecne zarówno w LimeSurvey 6, jak i 7 (kolumny tabeli surveys). */
export const DEFAULT_SETTINGS = {
  admin: '',
  adminemail: '',
  anonymized: 'Y',
  format: 'G', // G = grupa na stronę, S = pytanie na stronę, A = wszystko na jednej
  savetimings: 'N',
  template: 'fruity_twentythree',
  datestamp: 'N',
  usecookie: 'N',
  allowregister: 'N',
  allowsave: 'N',
  autoredirect: 'N',
  allowprev: 'Y',
  printanswers: 'N',
  ipaddr: 'N',
  ipanonymize: 'N',
  refurl: 'N',
  showsurveypolicynotice: '0',
  publicstatistics: 'N',
  publicgraphs: 'N',
  listpublic: 'N',
  htmlemail: 'Y',
  sendconfirmation: 'N',
  tokenanswerspersistence: 'N',
  assessments: 'N',
  usecaptcha: 'N',
  usetokens: 'N',
  tokenlength: '15',
  showxquestions: 'N',
  showgroupinfo: 'B',
  shownoanswer: 'N',
  showqnumcode: 'X',
  showwelcome: 'Y',
  showprogress: 'Y',
  questionindex: '0',
  navigationdelay: '0',
  alloweditaftercompletion: 'N',
};

/** Domyślne treści e-maili (zaproszenie, przypomnienie, potwierdzenie, rejestracja). */
export const DEFAULT_EMAILS = {
  pl: {
    invite_subj: 'Zaproszenie do ankiety: {SURVEYNAME}',
    invite:
      'Dzień dobry {FIRSTNAME},<br /><br />zapraszamy do wypełnienia ankiety „{SURVEYNAME}”.<br /><br />{SURVEYDESCRIPTION}<br /><br />Aby wziąć udział, kliknij w link:<br />{SURVEYURL}<br /><br />Z poważaniem<br />{ADMINNAME} ({ADMINEMAIL})<br /><br />----------------------------------------------<br />Jeśli nie chcesz brać udziału w tej ankiecie i otrzymywać kolejnych zaproszeń, kliknij:<br />{OPTOUTURL}',
    remind_subj: 'Przypomnienie o ankiecie: {SURVEYNAME}',
    remind:
      'Dzień dobry {FIRSTNAME},<br /><br />niedawno zaprosiliśmy Cię do ankiety „{SURVEYNAME}”. Nie mamy jeszcze Twoich odpowiedzi — ankieta jest nadal dostępna:<br />{SURVEYURL}<br /><br />Z poważaniem<br />{ADMINNAME} ({ADMINEMAIL})<br /><br />----------------------------------------------<br />Jeśli nie chcesz otrzymywać kolejnych przypomnień, kliknij:<br />{OPTOUTURL}',
    confirm_subj: 'Potwierdzenie udziału w ankiecie: {SURVEYNAME}',
    confirm:
      'Dzień dobry {FIRSTNAME},<br /><br />dziękujemy za wypełnienie ankiety „{SURVEYNAME}”. Twoje odpowiedzi zostały zapisane.<br /><br />Z poważaniem<br />{ADMINNAME}',
    register_subj: 'Potwierdzenie rejestracji w ankiecie: {SURVEYNAME}',
    register:
      'Dzień dobry {FIRSTNAME},<br /><br />dziękujemy za rejestrację w ankiecie „{SURVEYNAME}”. Aby ją wypełnić, kliknij w link:<br />{SURVEYURL}<br /><br />Z poważaniem<br />{ADMINNAME} ({ADMINEMAIL})',
  },
  en: {
    invite_subj: 'Invitation to participate in a survey: {SURVEYNAME}',
    invite:
      'Dear {FIRSTNAME},<br /><br />you have been invited to take part in the survey "{SURVEYNAME}".<br /><br />{SURVEYDESCRIPTION}<br /><br />To participate, please click the link below:<br />{SURVEYURL}<br /><br />Sincerely,<br />{ADMINNAME} ({ADMINEMAIL})<br /><br />----------------------------------------------<br />If you do not want to participate in this survey and do not want to receive any more invitations, please click the following link:<br />{OPTOUTURL}',
    remind_subj: 'Reminder to participate in a survey: {SURVEYNAME}',
    remind:
      'Dear {FIRSTNAME},<br /><br />recently we invited you to take part in the survey "{SURVEYNAME}". We have not received your answers yet — the survey is still available:<br />{SURVEYURL}<br /><br />Sincerely,<br />{ADMINNAME} ({ADMINEMAIL})<br /><br />----------------------------------------------<br />If you do not want to receive any more reminders, please click the following link:<br />{OPTOUTURL}',
    confirm_subj: 'Confirmation of your participation in our survey: {SURVEYNAME}',
    confirm:
      'Dear {FIRSTNAME},<br /><br />thank you for completing the survey "{SURVEYNAME}". Your answers have been saved.<br /><br />Sincerely,<br />{ADMINNAME}',
    register_subj: 'Survey registration confirmation: {SURVEYNAME}',
    register:
      'Dear {FIRSTNAME},<br /><br />thank you for registering for the survey "{SURVEYNAME}". To take part, please click the link below:<br />{SURVEYURL}<br /><br />Sincerely,<br />{ADMINNAME} ({ADMINEMAIL})',
  },
};

// ---------------------------------------------------------------------------
// Normalizacja skróconego zapisu
// ---------------------------------------------------------------------------

const L10N_KEYS = new Set(['title', 'summary', 'description', 'welcome', 'end', 'text', 'help', 'name']);

/**
 * Rozwija skrócony zapis definicji do pełnej postaci:
 *  - teksty jako tablica w kolejności `languages`, np. ["Tak", "Yes"] → {pl: "Tak", en: "Yes"},
 *  - `"options": "@nazwa"` → kopia zestawu odpowiedzi z `scales` (np. skala zgody 1–5).
 * Zwraca nowy obiekt; wejście pozostaje bez zmian.
 * @param {object} def
 * @param {Record<string, Array<{code: string, text: unknown}>>} [scales]
 */
export function normalizeTemplate(def, scales = {}) {
  const langs = Array.isArray(def?.languages) ? def.languages : [];
  const pair = (value) => {
    if (!Array.isArray(value)) return value;
    if (value.length !== langs.length || value.some((v) => typeof v !== 'string')) return value;
    return Object.fromEntries(langs.map((l, i) => [l, value[i]]));
  };
  const walk = (node, key) => {
    if (Array.isArray(node)) {
      if (key && L10N_KEYS.has(key) && node.every((v) => typeof v === 'string')) return pair(node);
      return node.map((item) => walk(item));
    }
    if (!node || typeof node !== 'object') return node;
    const out = {};
    for (const [k, v] of Object.entries(node)) {
      if (k === 'options' && typeof v === 'string' && v.startsWith('@')) {
        const set = scales[v.slice(1)];
        if (!set) throw new Error(`${def.id}: nieznany zestaw odpowiedzi „${v}”.`);
        out[k] = walk(set);
      } else if (k === 'attributesL10n' && v && typeof v === 'object') {
        out[k] = Object.fromEntries(Object.entries(v).map(([name, val]) => [name, pair(val)]));
      } else if (k === 'emails' || k === 'settings' || k === 'attributes') {
        out[k] = v;
      } else {
        out[k] = walk(v, k);
      }
    }
    return out;
  };
  return walk(def);
}

// ---------------------------------------------------------------------------
// Walidacja definicji
// ---------------------------------------------------------------------------

/**
 * Sprawdza definicję szablonu. Zwraca listę błędów (pusta = poprawna).
 * @param {object} def
 * @returns {string[]}
 */
export function validateTemplate(def) {
  const errors = [];
  const err = (msg) => errors.push(`${def?.id || '(brak id)'}: ${msg}`);
  if (!def || typeof def !== 'object') return ['Definicja nie jest obiektem.'];
  if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(def.id || '')) err('id musi być w formacie kebab-case (a-z, 0-9, -).');
  const langs = def.languages;
  if (!Array.isArray(langs) || langs.length === 0) return [...errors, `${def.id}: languages musi być niepustą tablicą.`];
  for (const l of langs) if (!/^[a-z]{2}(-[A-Za-z]{2,})?$/.test(l)) err(`nieprawidłowy kod języka: ${l}`);

  const l10n = (obj, where, { required = true } = {}) => {
    if (obj === undefined || obj === null) {
      if (required) err(`${where}: brak tekstu.`);
      return;
    }
    if (typeof obj !== 'object') return err(`${where}: oczekiwano obiektu {język: tekst}.`);
    for (const l of langs) {
      if (typeof obj[l] !== 'string' || (required && !obj[l].trim())) err(`${where}: brak tłumaczenia „${l}”.`);
    }
    for (const k of Object.keys(obj)) if (!langs.includes(k)) err(`${where}: nadmiarowy język „${k}”.`);
  };

  l10n(def.title, 'title');
  l10n(def.description, 'description', { required: false });
  l10n(def.welcome, 'welcome', { required: false });
  l10n(def.end, 'end', { required: false });

  for (const key of Object.keys(def.settings || {})) {
    if (!(key in DEFAULT_SETTINGS)) err(`settings.${key}: nieznane ustawienie ankiety.`);
  }
  (def.tokenAttributes || []).forEach((a, i) => l10n(a.name, `tokenAttributes[${i}].name`));

  if (!Array.isArray(def.groups) || def.groups.length === 0) err('groups musi zawierać co najmniej jedną grupę.');
  const codes = new Set();
  (def.groups || []).forEach((g, gi) => {
    l10n(g.title, `groups[${gi}].title`);
    l10n(g.description, `groups[${gi}].description`, { required: false });
    if (!Array.isArray(g.questions) || g.questions.length === 0) err(`groups[${gi}]: brak pytań.`);
    (g.questions || []).forEach((q, qi) => {
      const where = `pytanie ${q.code || `#${gi}.${qi}`}`;
      if (!CODE_RE.test(q.code || '')) err(`${where}: kod musi zaczynać się literą i mieć do 20 znaków alfanumerycznych.`);
      const lower = String(q.code).toLowerCase();
      if (codes.has(lower)) err(`${where}: zduplikowany kod pytania.`);
      codes.add(lower);
      if (!QUESTION_TYPES[q.type]) return err(`${where}: nieznany typ „${q.type}”.`);
      l10n(q.text, `${where}.text`);
      l10n(q.help, `${where}.help`, { required: false });

      const needsOptions = TYPES_WITH_OPTIONS.has(q.type);
      const needsSubs = TYPES_WITH_SUBQUESTIONS.has(q.type);
      if (needsOptions) {
        if (!Array.isArray(q.options) || q.options.length < 2) err(`${where}: typ „${q.type}” wymaga co najmniej 2 opcji.`);
        const seen = new Set();
        (q.options || []).forEach((o, oi) => {
          if (!ANSWER_CODE_RE.test(String(o.code ?? ''))) err(`${where}: opcja #${oi} ma nieprawidłowy kod (1–5 znaków alfanumerycznych).`);
          if (seen.has(String(o.code))) err(`${where}: zduplikowany kod opcji „${o.code}”.`);
          seen.add(String(o.code));
          l10n(o.text, `${where}.options[${o.code}]`);
        });
      } else if (q.options) err(`${where}: typ „${q.type}” nie przyjmuje opcji (użyj subquestions?).`);
      if (needsSubs) {
        if (!Array.isArray(q.subquestions) || q.subquestions.length < 1) err(`${where}: typ „${q.type}” wymaga podpytań.`);
        const seen = new Set();
        (q.subquestions || []).forEach((s, si) => {
          if (!SUBCODE_RE.test(String(s.code ?? ''))) err(`${where}: podpytanie #${si} ma nieprawidłowy kod.`);
          if (seen.has(String(s.code))) err(`${where}: zduplikowany kod podpytania „${s.code}”.`);
          seen.add(String(s.code));
          l10n(s.text, `${where}.subquestions[${s.code}]`);
        });
      } else if (q.subquestions) err(`${where}: typ „${q.type}” nie przyjmuje podpytań.`);
      if (q.other && !TYPES_WITH_OTHER.has(q.type)) err(`${where}: opcja „Inne” jest dostępna tylko dla radio, dropdown i checkbox.`);
      for (const [name, val] of Object.entries(q.attributesL10n || {})) l10n(val, `${where}.attributesL10n.${name}`);
    });
  });
  return errors;
}

// ---------------------------------------------------------------------------
// Budowa XML
// ---------------------------------------------------------------------------

const cdata = (value) => `<![CDATA[${String(value ?? '').replace(/]]>/g, ']]]]><![CDATA[>')}]]>`;

function table(name, fields, rows) {
  const out = [` <${name}>`, '  <fields>'];
  for (const f of fields) out.push(`   <fieldname>${f}</fieldname>`);
  out.push('  </fields>', '  <rows>');
  for (const row of rows) {
    out.push('   <row>');
    for (const f of fields) out.push(`    <${f}>${cdata(row[f])}</${f}>`);
    out.push('   </row>');
  }
  out.push('  </rows>', ` </${name}>`);
  return out.join('\n');
}

/**
 * Liczy pytania widoczne dla respondenta (bez podpytań, równań i tekstów informacyjnych).
 * @param {object} def
 */
export function countQuestions(def) {
  let n = 0;
  for (const g of def.groups) for (const q of g.questions) if (q.type !== 'equation' && q.type !== 'info') n += 1;
  return n;
}

/**
 * Buduje plik .lss z definicji szablonu.
 * @param {object} def definicja (patrz ../README.md)
 * @param {{ sid?: number }} [opts] identyfikator ankiety zapisany w pliku (LimeSurvey nada własny przy imporcie)
 * @returns {string} XML
 */
export function buildLss(def, opts = {}) {
  const errors = validateTemplate(def);
  if (errors.length) throw new Error(`Nieprawidłowa definicja szablonu:\n- ${errors.join('\n- ')}`);

  const sid = opts.sid || 100001;
  const langs = def.languages;
  const base = langs[0];
  const settings = { ...DEFAULT_SETTINGS, ...(def.settings || {}) };

  // --- surveys ---
  const tokenAttributes = def.tokenAttributes || [];
  const attributeDescriptions = {};
  tokenAttributes.forEach((a, i) => {
    attributeDescriptions[`attribute_${i + 1}`] = {
      description: a.name[base],
      mandatory: a.mandatory ? 'Y' : 'N',
      show_register: a.showRegister ? 'Y' : 'N',
      cpdbmap: '',
    };
  });
  const surveyRow = {
    sid,
    gsid: 1,
    ...settings,
    language: base,
    additional_languages: langs.slice(1).join(' '),
    attributedescriptions: tokenAttributes.length ? JSON.stringify(attributeDescriptions) : '',
  };
  const surveyFields = ['sid', 'gsid', ...Object.keys(DEFAULT_SETTINGS), 'language', 'additional_languages', 'attributedescriptions'];

  // --- surveys_languagesettings ---
  const lsFields = [
    'surveyls_survey_id', 'surveyls_language', 'surveyls_title', 'surveyls_description', 'surveyls_welcometext', 'surveyls_endtext',
    'surveyls_url', 'surveyls_urldescription',
    'surveyls_email_invite_subj', 'surveyls_email_invite', 'surveyls_email_remind_subj', 'surveyls_email_remind',
    'surveyls_email_register_subj', 'surveyls_email_register', 'surveyls_email_confirm_subj', 'surveyls_email_confirm',
    'surveyls_dateformat', 'surveyls_attributecaptions', 'surveyls_numberformat',
  ];
  // Domyślne formaty LimeSurvey dla języka: 1 = dd.mm.yyyy (pl), 9 = mm-dd-yyyy (en).
  const dateFormat = { pl: 1, en: 9 };
  const lsRows = langs.map((l) => {
    const mail = { ...(DEFAULT_EMAILS[l] || DEFAULT_EMAILS.en), ...((def.emails || {})[l] || {}) };
    const captions = {};
    tokenAttributes.forEach((a, i) => { captions[`attribute_${i + 1}`] = a.name[l]; });
    return {
      surveyls_survey_id: sid,
      surveyls_language: l,
      surveyls_title: def.title[l],
      surveyls_description: def.description?.[l] || '',
      surveyls_welcometext: def.welcome?.[l] || '',
      surveyls_endtext: def.end?.[l] || '',
      surveyls_url: '',
      surveyls_urldescription: '',
      surveyls_email_invite_subj: mail.invite_subj,
      surveyls_email_invite: mail.invite,
      surveyls_email_remind_subj: mail.remind_subj,
      surveyls_email_remind: mail.remind,
      surveyls_email_register_subj: mail.register_subj,
      surveyls_email_register: mail.register,
      surveyls_email_confirm_subj: mail.confirm_subj,
      surveyls_email_confirm: mail.confirm,
      surveyls_dateformat: dateFormat[l] || 6, // 6 = yyyy-mm-dd
      surveyls_attributecaptions: tokenAttributes.length ? JSON.stringify(captions) : '',
      surveyls_numberformat: l === 'pl' ? 1 : 0, // 1 = przecinek dziesiętny
    };
  });

  // --- grupy, pytania, podpytania, odpowiedzi, atrybuty ---
  const groups = [];
  const groupL10ns = [];
  const questions = [];
  const subquestions = [];
  const questionL10ns = [];
  const answers = [];
  const answerL10ns = [];
  const attributes = [];
  let gid = 0;
  let qid = 0;
  let aid = 0;

  def.groups.forEach((g, gi) => {
    gid += 1;
    groups.push({ gid, sid, group_order: gi, randomization_group: '', grelevance: g.relevance || '1' });
    for (const l of langs) groupL10ns.push({ gid, language: l, group_name: g.title[l], description: g.description?.[l] || '' });

    g.questions.forEach((q, qi) => {
      qid += 1;
      const parentQid = qid;
      const type = QUESTION_TYPES[q.type];
      questions.push({
        qid: parentQid,
        parent_qid: 0,
        sid,
        gid,
        type,
        title: q.code,
        preg: q.preg || '',
        other: q.other ? 'Y' : 'N',
        mandatory: q.mandatory ? 'Y' : 'N',
        encrypted: 'N',
        question_order: qi,
        scale_id: 0,
        same_default: 0,
        relevance: q.relevance || '1',
        question_theme_name: '',
        modulename: '',
        same_script: 0,
      });
      for (const l of langs) questionL10ns.push({ qid: parentQid, language: l, question: q.text[l], help: q.help?.[l] || '', script: '' });

      (q.subquestions || []).forEach((s, si) => {
        qid += 1;
        subquestions.push({
          qid,
          parent_qid: parentQid,
          sid,
          gid,
          type,
          title: String(s.code),
          preg: '',
          other: 'N',
          mandatory: '',
          encrypted: 'N',
          question_order: si,
          scale_id: 0,
          same_default: 0,
          relevance: s.relevance || '1',
          question_theme_name: '',
          modulename: '',
          same_script: 0,
        });
        for (const l of langs) questionL10ns.push({ qid, language: l, question: s.text[l], help: '', script: '' });
      });

      (q.options || []).forEach((o, oi) => {
        aid += 1;
        answers.push({ aid, qid: parentQid, code: String(o.code), sortorder: oi, assessment_value: o.value ?? 0, scale_id: 0 });
        for (const l of langs) answerL10ns.push({ aid, language: l, answer: o.text[l] });
      });

      const attrs = { ...(q.attributes || {}) };
      if (q.cssClass) attrs.cssclass = q.cssClass;
      for (const [attribute, value] of Object.entries(attrs)) attributes.push({ qid: parentQid, attribute, value: String(value), language: '' });
      for (const [attribute, values] of Object.entries(q.attributesL10n || {})) {
        for (const l of langs) attributes.push({ qid: parentQid, attribute, value: values[l], language: l });
      }
    });
  });

  const questionFields = ['qid', 'parent_qid', 'sid', 'gid', 'type', 'title', 'preg', 'other', 'mandatory', 'encrypted', 'question_order', 'scale_id', 'same_default', 'relevance', 'question_theme_name', 'modulename', 'same_script'];

  const parts = [
    '<?xml version="1.0" encoding="UTF-8"?>',
    '<document>',
    ' <LimeSurveyDocType>Survey</LimeSurveyDocType>',
    ` <DBVersion>${DB_VERSION}</DBVersion>`,
    ' <languages>',
    ...langs.map((l) => `  <language>${l}</language>`),
    ' </languages>',
  ];
  if (answers.length) {
    parts.push(table('answers', ['aid', 'qid', 'code', 'sortorder', 'assessment_value', 'scale_id'], answers));
    parts.push(table('answer_l10ns', ['aid', 'language', 'answer'], answerL10ns));
  }
  parts.push(table('groups', ['gid', 'sid', 'group_order', 'randomization_group', 'grelevance'], groups));
  parts.push(table('group_l10ns', ['gid', 'language', 'group_name', 'description'], groupL10ns));
  parts.push(table('questions', questionFields, questions));
  if (subquestions.length) parts.push(table('subquestions', questionFields, subquestions));
  parts.push(table('question_l10ns', ['qid', 'language', 'question', 'help', 'script'], questionL10ns));
  if (attributes.length) parts.push(table('question_attributes', ['qid', 'attribute', 'value', 'language'], attributes));
  parts.push(table('surveys', surveyFields, [surveyRow]));
  parts.push(table('surveys_languagesettings', lsFields, lsRows));
  parts.push('</document>', '');
  return parts.join('\n');
}
