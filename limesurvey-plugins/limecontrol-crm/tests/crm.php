<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Fast integration checks using the same SQL and plugin events as the CE adapters.
class PluginBase {private array $settings=[]; protected function subscribe($e){} protected function get($k,$m=null,$id=null,$default=null){return $this->settings[$k]??$default;} protected function set($k,$v){$this->settings[$k]=$v;} public $event; public function getEvent(){return $this->event;}}
class CHttpException extends RuntimeException {public function __construct(public int $statusCode=500,string $message=''){parent::__construct($message,$statusCode);}}
class CrmFixtureEvent {public function __construct(public array $values){} public function get($k){return $this->values[$k]??null;} public function set($k,$v){$this->values[$k]=$v;}}
class CrmFixtureDb {public $tablePrefix='t_'; public function __construct(private PDO $pdo){} public function setActive($flag){} public function getPdoInstance(){return $this->pdo;}}
function App(){return $GLOBALS['app'];}
class Permission {public static function model(){return new self;} public function hasGlobalPermission($p,$a){return App()->user->id===1;}}
class UserGroup {public static function model(){return new self;} public function findByPk($id){return in_array($id,[10,11],true);}}
class Survey {public static array $rows=[]; public static function model(){return new self;} public function findByPk($k){return self::$rows[$k]??null;}}
class Response {public static array $rows=[]; public function __construct(private int $sid){} public static function model($sid){return new self($sid);} public function findByPk($k){return (object)(self::$rows[$this->sid][$k]??[]);}}
class Question {public static function model(){return new self;} public function findAllByAttributes($a){$map=['CONTACT'=>[1,'Y'],'PERSON'=>[2,'S'],'EMAIL'=>[3,'S'],'COMPANY'=>[4,'S'],'SUBJECT'=>[5,'S']];return isset($map[$a['title']])?[(object)['qid'=>$map[$a['title']][0],'type'=>$map[$a['title']][1],'encrypted'=>'N']]:[];}}
function createFieldMap($survey,...$ignore){$a=[];foreach(range(1,5) as $id){$a[]=['qid'=>$id,'fieldname'=>'field_'.$id,'aid'=>''];}return $a;}
require_once __DIR__.'/../LimeControlCrm/LimeControlCrm.php';
require_once __DIR__.'/../LimeControlCrm/CrmPanel.php';
function check($ok,$what){if(!$ok){throw new Exception($what);}}
function rejected(callable $fn,$msg){try{$fn();}catch(DomainException|CHttpException $e){return;}throw new Exception($msg);}
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$GLOBALS['app']=(object)['db'=>new CrmFixtureDb($pdo),'user'=>(object)['id'=>1,'isGuest'=>false],'request'=>null];
$pdo->exec('CREATE TABLE t_user_groups (ugid INTEGER PRIMARY KEY,name TEXT,owner_id INTEGER);CREATE TABLE t_user_in_groups (ugid INTEGER,uid INTEGER);CREATE TABLE t_users (uid INTEGER PRIMARY KEY,users_name TEXT,user_status INTEGER,expires TEXT)');
$pdo->exec("INSERT INTO t_user_groups VALUES(10,'Sales',2),(11,'Other',4);INSERT INTO t_users VALUES(1,'Admin',1,NULL),(2,'Lead',1,NULL),(3,'Agent',1,NULL),(4,'Other',1,NULL),(5,'Disabled',0,NULL);INSERT INTO t_user_in_groups VALUES(10,3),(10,5)");
$plugin=new LimeControlCrm; $plugin->init(); $plugin->beforeActivate(); $plugin->beforeActivate(); $s=LimeControlCrm::store(); $s->enable(10);$s->enable(11);
$a=$s->save(10,'company',['title'=>'A','owner_id'=>3],2);$b=$s->save(10,'company',['title'=>'B','owner_id'=>2],2);
$polish=$s->save(10,'company',['title'=>'Pracownia Żuraw','tags'=>'Łódź'],2);
check($s->listing(10,'company',null,'pRaCoWnIa żURAW')[0]['id']===$polish['id'] && count($s->listing(10,'company',null,'ŁÓDŹ'))===1,'Unicode case folding across database collations');
$pdo->exec('UPDATE t_lc_crm_record SET search_text=NULL');$s->install();check(count($s->listing(10,'company',null,'ŻURAW'))===1,'Search backfill');
$email='buyer@example.invalid';$v=['title'=>'Buyer','email'=>$email,'owner_id'=>3];$x=$s->save(10,'contact',['parent_id'=>$a['id']]+$v,2);$y=$s->save(10,'contact',['parent_id'=>$b['id']]+$v,2);
check($x['id']!==$y['id'] && $s->lookupContact(10,$a['id'],strtoupper($email))['id']===$x['id'],'Separate companies may share email');
App()->user->id=3;check(LimeControlCrm::teamRole(10)==='worker' && LimeControlCrm::recordContext(10,'contact',$x['id'])['id']===$x['id'],'Worker owns record');rejected(fn()=>LimeControlCrm::recordContext(10,'company',$b['id'],true),'Worker read/write boundary');rejected(fn()=>LimeControlCrm::teamRole(11),'Other team is inaccessible');
App()->user->id=2;check(LimeControlCrm::teamRole(10)==='manager','Manager role');
$foreign=$s->save(11,'company',['title'=>'Other'],4);rejected(fn()=>$s->save(10,'contact',['parent_id'=>$foreign['id']]+$v,3),'Foreign reference');
$d=$s->save(10,'deal',['title'=>'Deal','owner_id'=>3,'status'=>'proposal','amount'=>'100.50','currency'=>'PLN','next_action'=>'Call','due'=>'2026-12-01'],2);
rejected(fn()=>$s->save(10,'deal',['status'=>'lost']+$d,2,$d),'Missing loss reason'); $won=$s->save(10,'deal',['status'=>'won','owner_id'=>2]+$d,2,$d); check(count($s->history(10,$d['id']))===2 && $won['closed_at']===date('Y-m-d'),'History and closing date');
rejected(fn()=>$s->save(10,'deal',['title'=>'Stale']+$d,2,$d),'Concurrent stale update');
$task=$s->save(10,'task',['title'=>'Call','due'=>'2026-01-10','owner_id'=>3,'parent_id'=>$d['id']],2);$s->save(10,'note',['title'=>'Call','status'=>'call','body'=>'Spoke to purchasing','parent_id'=>$d['id']],2);
check(count(LimeControlCrm::calendarTasks(10,3))===1 && LimeControlCrm::calendarTasks(10,3)[0]['due']==='2026-01-10','ICS owner scope');rejected(fn()=>LimeControlCrm::calendarTasks(10,5),'Disabled user blocked');
$csv=implode(',',LcCrmCsv::COLUMNS)."\n{$a['id']},Buyer changed,$email,222,VIP\n{$b['id']},Other person,new@example.invalid,444,\n";
$preview=LcCrmCsv::preview($s,10,$csv);check($preview[0]['existing_id']===$x['id'] && $preview[1]['existing_id']===0,'Preview per-company duplicate');
check(LcCrmCsv::apply($s,10,2,$preview,[])===1 && $s->get(10,$x['id'])['title']==='Buyer','Skip duplicates by default');
$preview=LcCrmCsv::preview($s,10,$csv);check(LcCrmCsv::apply($s,10,2,$preview,[0=>1])===1 && $s->get(10,$x['id'])['title']==='Buyer changed','Explicit merge');
rejected(fn()=>LcCrmCsv::apply($s,10,2,$preview,[0=>1]),'Stale preview');$out=fopen('php://temp','w+b'); LcCrmCsv::export($s,10,$out);rewind($out);check(str_contains(stream_get_contents($out),'Buyer changed') && LcCrmCsv::cell('=2+2')==="'=2+2",'Safe CSV export');
Survey::$rows[100]=(object)['sid'=>100,'language'=>'pl','anonymized'=>'N','active'=>'Y'];$s->setMapping(10,100,'shop','CONTACT','PERSON','EMAIL','COMPANY','SUBJECT');
Response::$rows[100][7]=['submitdate'=>'2026-11-20','token'=>'','field_1'=>'Y','field_2'=>'Buyer','field_3'=>$email,'field_4'=>'A','field_5'=>'New inquiry'];
$plugin->event=new CrmFixtureEvent(['surveyId'=>100,'responseId'=>7]);$before=count($s->listing(10,'deal'));$plugin->afterSurveyComplete();$plugin->afterSurveyComplete();$deals=$s->listing(10,'deal');check(count($deals)===$before+1 && $deals[0]['title']==='New inquiry' && $deals[0]['person']==='Buyer','Public identified opt-in and replay');
Response::$rows[100][8]=array_merge(Response::$rows[100][7],['field_1'=>'N']);$plugin->event->set('responseId',8);$plugin->afterSurveyComplete();check(count($s->listing(10,'deal'))===$before+1,'Refusal');
Survey::$rows[100]->anonymized='Y';$plugin->event->set('responseId',9);Response::$rows[100][9]=Response::$rows[100][7];$plugin->afterSurveyComplete();check(count($s->listing(10,'deal'))===$before+1,'Anonymous survey');Survey::$rows[100]->anonymized='N';
Response::$rows[100][7]['token']='synthetic-token';$ev=['surveyId'=>100,'responseId'=>7,'store'=>'shop','email'=>$email,'orderId'=>'order-1','sku'=>'sku-1','contactAllowed'=>true,'kind'=>'low_score','score'=>2,'items'=>[['sku'=>'variant-2','score'=>1,'defect'=>'pęknięcie']],'customerRef'=>'c-2','caseKey'=>hash('sha256','fixture')];
$plugin->event=new CrmFixtureEvent($ev);$plugin->limeControlFeedbackCase();$plugin->limeControlFeedbackCase();check($plugin->event->get('handled')===true && count($s->listing(10,'case'))===1,'Feedback replay');
foreach([['store'=>'other'],['contactAllowed'=>false]] as $override){$plugin->event=new CrmFixtureEvent($override+$ev);$plugin->limeControlFeedbackCase();check(!$plugin->event->get('handled'),'Unmapped shop or refusal');}
$case=$s->listing(10,'case')[0];check($case['owner_id']===2 && $case['parent_id']===null,'Shared email across two companies stays unlinked and routes to manager');check(str_contains($case['item_feedback'],'variant-2 · 1/5 · pęknięcie') && $case['customer_ref']==='c-2','Defect context');
$plugin->event=new CrmFixtureEvent(['kind'=>'contact_request']+$ev);$plugin->limeControlFeedbackCase();check($plugin->event->get('caseId')===$case['id'],'Same response closes once across reasons');rejected(fn()=>$s->save(10,'case',['status'=>'resolved']+$case,2,$case),'Empty resolution');
$s->save(10,'case',['title'=>'Manual unassigned case'],2);
$p=new LcCrmPanel($s);$p->team=10;[$wins,$pipeline,$unassigned,$overdue]=$p->report('2000-01-01','2099-01-01');check($wins['won']===1 && $unassigned===1 && $overdue===1,'Manager totals');
Survey::$rows[101]=(object)['sid'=>101,'language'=>'pl','anonymized'=>'N','active'=>'Y'];$s->setMapping(10,101,'','CONTACT','PERSON','EMAIL','COMPANY','SUBJECT','case');
Response::$rows[101][55]=Response::$rows[100][7];Response::$rows[101][55]['token']='';$plugin->event=new CrmFixtureEvent(['surveyId'=>101,'responseId'=>55]);$plugin->afterSurveyComplete();$plugin->afterSurveyComplete();$service=$s->listing(10,'case')[0];check($service['title']==='New inquiry' && $service['person']==='Buyer' && $service['order']==='' && $service['sku']==='' && $service['owner_id']===2 && count($s->listing(10,'case'))===3,'Independent public service request, replay safe and has no previous order identity');
$unique=$s->save(10,'contact',['title'=>'Unique contact','email'=>'unique@example.invalid','parent_id'=>$a['id'],'owner_id'=>3],2);
Response::$rows[100][71]=Response::$rows[100][7];
$plugin->event=new CrmFixtureEvent(['kind'=>'contact_request','score'=>5,'responseId'=>71,'email'=>'UNIQUE@example.invalid','caseKey'=>hash('sha256','positive-consent')]+$ev);
$plugin->limeControlFeedbackCase();$positive=$s->get(10,$plugin->event->get('caseId'));
check($positive['parent_id']==$unique['id'] && $positive['owner_id']===3 && $positive['priority']==='normal','Positive contact request assigned to the matched contact owner');
$pdo->exec('UPDATE t_users SET user_status=0 WHERE uid=3');check($s->caseAssignment(10,'unique@example.invalid')['owner_id']===2,'Inactive owner falls back to active manager');
$pdo->exec('UPDATE t_users SET user_status=0 WHERE uid=2');check($s->caseAssignment(10,$email)===['parent_id'=>0,'owner_id'=>0],'No eligible manager leaves ambiguous case unassigned');
fwrite(STDOUT,"CRM storage, roles, pipeline, history, calendar, CSV, consent, isolation, feedback and reports passed.\n");
