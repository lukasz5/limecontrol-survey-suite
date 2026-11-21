<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once __DIR__.'/CrmStore.php';
require_once __DIR__.'/CrmCsv.php';

class LimeControlCrm extends PluginBase
{
    protected static $name='LimeControlCrm';
    protected static $description='Kontakty, sprzedaż i obsługa spraw we własnej instalacji.';
    protected $storage='DbStorage';
    private const SCHEMA_VERSION=3;
    private static $instance;
    private static bool $schemaChecked=false;
    public function init()
    {
        self::$instance=$this; self::$schemaChecked=false;
        foreach (['beforeActivate','beforeAdminMenuRender','beforeToolsMenuRender','newDirectRequest','afterSurveyComplete','limeControlFeedbackCase'] as $event) { $this->subscribe($event); }
    }
    private static function connection(): LcCrmStore
    {
        $db=App()->db; $db->setActive(true);
        return new LcCrmStore($db->getPdoInstance(),(string)$db->tablePrefix);
    }
    public static function store(): LcCrmStore
    {
        $store=self::connection();
        if (!self::$schemaChecked && self::$instance) {
            self::$instance->ensureSchema($store);
        }
        return $store;
    }
    private function ensureSchema(LcCrmStore $store): void
    {
        if ((int)$this->get('crm_schema_version',null,null,0)<self::SCHEMA_VERSION) {
            $store->upgrade(function () use ($store) {
                if ((int)$this->get('crm_schema_version',null,null,0)<self::SCHEMA_VERSION) {
                    $store->install();
                    $this->set('crm_schema_version',self::SCHEMA_VERSION);
                }
            });
        }
        self::$schemaChecked=true;
    }
    public function beforeActivate() {$this->ensureSchema(self::connection());}
    public static function uid(): int {return App()->user->isGuest ? 0 : (int)App()->user->id;}
    public static function admin(): bool {return self::uid()>0 && Permission::model()->hasGlobalPermission('superadmin','read');}
    public static function teamRole(int $teamId): string
    {
        $s=self::store();
        if ($teamId>0 && self::uid() && $s->enabled($teamId)) {
            if (self::admin() && UserGroup::model()->findByPk($teamId)) {return 'admin';}
            $role=$s->memberRole($teamId,self::uid()); if ($role) {return $role;}
        }
        throw new CHttpException(403,'Brak dostępu do zespołu.');
    }
    public static function recordContext(int $teamId,string $type,int $id,bool $write=false): array
    {
        $role=self::teamRole($teamId); $row=self::store()->get($teamId,$id);
        if (!$row || $row['type']!==$type || ($role==='worker' && $row['owner_id']!==self::uid())) {throw new CHttpException(404,'Brak rekordu.');}
        return $row;
    }
    public static function calendarTasks(int $teamId,int $uid): array
    {
        $s=self::store();
        if (!$s->memberRole($teamId,$uid)) {throw new CHttpException(403,'Brak dostępu do zespołu.');}
        $rows=$s->listing($teamId,'task',$uid,'','',0,0,null);
        return array_values(array_map(static fn($r)=>array_intersect_key($r,array_flip(['id','title','due','status'])),array_filter($rows,fn($r)=>$r['due']!==null)));
    }
    public static function url(array $args=[]): string {return App()->createUrl('plugins/direct',['plugin'=>'LimeControlCrm','function'=>'panel']+$args);}
    private function menuVisible(): bool
    {
        if (!self::uid()) {return false;}
        if (self::admin()) {return true;}
        $s=self::store();
        foreach ($s->all('SELECT team_id FROM '.$s->table('lc_crm_team')) as $team) {
            if ($s->memberRole((int)$team['team_id'],self::uid())) {return true;}
        }
        return false;
    }
    public function beforeAdminMenuRender()
    {
        if (!$this->menuVisible()) {return;}
        $this->getEvent()->append('extraMenus',[new \LimeSurvey\Menu\Menu(['id'=>'limecontrol-crm-menu','label'=>'CRM',
            'href'=>CHtml::encode(self::url()), 'isInMiddleSection'=>true, 'iconClass'=>'ri-contacts-book-line'])]);
    }
    public function beforeToolsMenuRender()
    {
        if (!$this->menuVisible()) {return;}
        $event=$this->getEvent(); $items=$event->get('menuItems')?:[];
        $items[]=new \LimeSurvey\Menu\MenuItem(['label'=>'CRM · Klienci i sprzedaż','href'=>CHtml::encode(self::url())]);
        $event->set('menuItems',$items);
    }
    public function newDirectRequest()
    {
        $event=$this->getEvent(); if ($event->get('target')!==self::$name) {return;}
        if ($event->get('function')!=='panel') {throw new CHttpException(404);}
        if (!self::uid()) {throw new CHttpException(403,'Zaloguj się do panelu administracyjnego LimeSurvey.');}
        require_once __DIR__.'/CrmPanel.php'; (new LcCrmPanel(self::store()))->run();
    }
    private static function identifiedSurvey(int $sid)
    {
        $survey=$sid ? Survey::model()->findByPk($sid) : null;
        return $survey && $survey->active==='Y' && $survey->anonymized==='N' ? $survey : null;
    }
    public static function leadFields($survey,array $codes): array
    {
        $allowed=[['Y'],['S','T'],['S','T'],['S','T'],['S','T']]; $byId=[]; $fields=[];
        foreach (array_values($codes) as $i=>$code) {
            if ($i>=3 && $code==='') {continue;}
            $q=Question::model()->findAllByAttributes(['sid'=>(int)$survey->sid,'parent_qid'=>0,'title'=>$code]);
            if (count($q)!==1 || !in_array($q[0]->type,$allowed[$i],true) || $q[0]->encrypted==='Y') {return [];}
            $byId[(int)$q[0]->qid]=$i;
        }
        if (count($byId)!==count(array_filter($codes))) {return [];}
        foreach(createFieldMap($survey,'full',false,false,$survey->language) as $f) {
            $id=(int)($f['qid']??0); if(isset($byId[$id]) && empty($f['aid'])) {$fields[$byId[$id]]=$f['fieldname'];}
        }
        ksort($fields); return count($fields)===count($byId) ? $fields : [];
    }
    public function afterSurveyComplete()
    {
        $event=$this->getEvent(); $sid=(int)$event->get('surveyId'); $rid=(int)$event->get('responseId');
        if (!$rid || !($survey=self::identifiedSurvey($sid))) {return;}
        $s=self::store(); $map=$s->mapping($sid);
        if (!$map || !$map['optin_code'] || !$s->enabled((int)$map['team_id'])) {return;}
        $fields=self::leadFields($survey,[$map['optin_code'],$map['name_code'],$map['email_code'],$map['company_code'],$map['subject_code']]);
        if (!$fields) {return;}
        $r=Response::model($sid)->findByPk($rid);
        if (!$r || !$r->submitdate || $r->{$fields[0]}!=='Y') {return;}
        $name=trim((string)$r->{$fields[1]}); $email=trim((string)$r->{$fields[2]});
        if (!$name || mb_strlen($name)>180 || mb_strlen($email)>240 || !filter_var($email,FILTER_VALIDATE_EMAIL)) {return;}
        $key=hash('sha256','lead|'.$sid.'|'.$rid); $team=(int)$map['team_id'];
        $subject=isset($fields[4]) ? trim((string)$r->{$fields[4]}) : '';
        $record=['title'=>mb_substr($subject!==''?$subject:$name,0,180),'person'=>$name,'email'=>$email,
            'company_name'=>isset($fields[3])?mb_substr((string)$r->{$fields[3]},0,240):''];
        $target=$map['form_target'];
        if ($target==='deal') {$record['next_action']='Skontaktuj się w sprawie zapytania.';}
        self::deliver($s,$team,$target,$record,$key);
    }
    public function limeControlFeedbackCase()
    {
        $ev=$this->getEvent(); $sid=(int)$ev->get('surveyId'); $rid=(int)$ev->get('responseId'); $email=(string)$ev->get('email');
        if ($ev->get('contactAllowed')!==true || !$rid || !self::identifiedSurvey($sid) || !filter_var($email,FILTER_VALIDATE_EMAIL)) {return;}
        $s=self::store(); $map=$s->mapping($sid); $shop=(string)$ev->get('store'); $source=(string)$ev->get('caseKey'); $kind=(string)$ev->get('kind');
        if (!$map || !$s->enabled((int)$map['team_id']) || $shop==='' || $map['store_key']!==$shop || !preg_match('/^[a-f0-9]{64}$/D',$source)
            || !in_array($kind,['low_score','return_request','contact_request'],true)) {return;}
        $r=Response::model($sid)->findByPk($rid); if (!$r || !$r->submitdate || !$r->token) {return;}
        $summary=[];
        foreach ((array)$ev->get('items') as $item) {
            if (!is_array($item) || !is_scalar($item['sku']??null) || !is_scalar($item['score']??null) || !is_scalar($item['defect']??'')) {return;}
            $summary[]=trim((string)$item['sku']).' · '.(string)$item['score'].'/5'.((string)($item['defect']??'')!==''?' · '.(string)$item['defect']:'');
        }
        $caption=['low_score'=>'Niska ocena','return_request'=>'Prośba o zwrot','contact_request'=>'Prośba o kontakt'];
        $case=self::deliver($s,(int)$map['team_id'],'case',['title'=>mb_substr($caption[$kind].' · '.(string)$ev->get('orderId'),0,180),
            'priority'=>$kind==='contact_request'?'normal':'high','order'=>(string)$ev->get('orderId'),'email'=>$email,'sku'=>(string)$ev->get('sku'),'score'=>(string)$ev->get('score'),
            'customer_ref'=>(string)$ev->get('customerRef'),'item_feedback'=>implode("\n",$summary)],hash('sha256','feedback|'.$sid.'|'.$shop.'|'.$source));
        $ev->set('caseId',$case['id']); $ev->set('handled',true);
    }
    private static function deliver(LcCrmStore $s,int $team,string $type,array $record,string $source): array
    {
        if ($existing=$s->source($team,$source)) {return $existing;}
        if ($type==='case') {$record+=$s->caseAssignment($team,$record['email']);}
        try {return $s->save($team,$type,$record,0,null,$source);}
        catch (PDOException $e) {if($existing=$s->source($team,$source)){return $existing;} throw $e;}
    }
}
