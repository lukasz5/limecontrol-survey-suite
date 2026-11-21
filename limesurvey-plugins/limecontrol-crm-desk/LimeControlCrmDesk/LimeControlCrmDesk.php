<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once __DIR__.'/DeskDocuments.php';
require_once __DIR__.'/DeskStore.php';
require_once __DIR__.'/DeskMailbox.php';

class LimeControlCrmDesk extends PluginBase
{
    protected static $name='LimeControlCrmDesk';
    protected static $description='Sales offers, case correspondence and task calendars.';
    protected $storage='DbStorage';

    public function init()
    {
        foreach (['beforeActivate','limeControlCrmRecordLinks','newDirectRequest','direct'] as $event) $this->subscribe($event);
    }

    public function beforeActivate()
    {
        if (!$this->activeCrm()) {
            $this->getEvent()->set('success',false);
            $this->getEvent()->set('message','Activate LimeControlCrm first.');
            return;
        }
        (new DeskStore(App()->db))->install();
    }

    private function activeCrm(): bool
    {
        return App()->getPluginManager()->isPluginActive('LimeControlCrm');
    }

    public static function tr(string $pl,string $en): string
    {
        return str_starts_with((string)App()->language,'pl') ? $pl : $en;
    }

    public static function url(string $action,array $params=[]): string
    {
        return App()->createUrl('plugins/direct',['plugin'=>'LimeControlCrmDesk','function'=>$action]+$params);
    }

    public function limeControlCrmRecordLinks()
    {
        $e=$this->getEvent();
        $labels=['deal'=>self::tr('Oferty handlowe','Sales offers'),'case'=>self::tr('Korespondencja','Correspondence'),'task'=>self::tr('Mój kalendarz','My calendar')];
        $type=$e->get('recordType');
        if (isset($labels[$type])) {
            $links=(array)$e->get('links');
            $links[]=['label'=>$labels[$type],'url'=>self::url('panel',['team'=>$e->get('teamId'),'type'=>$type,'id'=>$e->get('recordId')])];
            $e->set('links',$links);
        }
    }

    /** Maildir paths come from a server environment setting available only to CLI. */
    public function direct()
    {
        $e=$this->getEvent();
        if ($e->get('target')!==self::$name || $e->get('function')!=='mail' || PHP_SAPI!=='cli') return;
        if (!$this->activeCrm()) throw new RuntimeException('Activate LimeControlCrm first');
        $dirs=json_decode(getenv('LIMECONTROL_CRM_MAILDIRS') ?: '{}',true,16,JSON_THROW_ON_ERROR);
        $accounts=json_decode(getenv('LIMECONTROL_CRM_IMAP') ?: '{}',true,16,JSON_THROW_ON_ERROR);
        $store=new DeskStore(App()->db);
        foreach ($accounts as $team=>$account) {
            if (!ctype_digit((string)$team) || !LimeControlCrm::store()->enabled((int)$team)) throw new InvalidArgumentException('CRM team is unavailable');
            $n=DeskMailbox::syncImap($store,(int)$team,$account,
                fn($source)=>$this->get('imap_'.substr($source,0,40),null,null,0),
                fn($source,$uid)=>$this->set('imap_'.substr($source,0,40),$uid));
            echo (int)$team.': '.$n.PHP_EOL;
        }
        foreach ($dirs as $team=>$dir) {
            if (!ctype_digit((string)$team) || !LimeControlCrm::store()->enabled((int)$team)) throw new InvalidArgumentException('CRM team is unavailable');
            $n=DeskMailbox::sync($store,(int)$team,$dir);
            echo (int)$team.': '.$n.PHP_EOL;
        }
    }

    public function newDirectRequest()
    {
        $e=$this->getEvent();
        if ($e->get('target')!==self::$name) return;
        if (!$this->activeCrm()) throw new CHttpException(503,'Activate LimeControlCrm first');
        $db=new DeskStore(App()->db);
        $req=App()->request;
        $action=$e->get('function');
        header('Cache-Control: no-store, private');
        header('Referrer-Policy: no-referrer');
        if ($action==='feed') {
            $account=$db->feed((string)$req->getQuery('key',''));
            $team=(int)$account['team_id']; $uid=(int)$account['uid'];
            $tasks=LimeControlCrm::calendarTasks($team,$uid);
            header('Content-Type: text/calendar; charset=UTF-8');
            echo DeskDocuments::calendar($tasks,'team-'.$team.'-user-'.$uid,'LimeControl CRM');
            App()->end();
        }
        if ($action!=='panel' && $action!=='pdf') throw new CHttpException(404);
        $team=(int)$req->getQuery('team',0);
        $id=(int)$req->getQuery('id',0);
        $type=(string)$req->getQuery('type','');
        if (!in_array($type,['deal','case','task'],true)) throw new CHttpException(404);
        $record=LimeControlCrm::recordContext($team,$type,$id,$req->isPostRequest);
        $role=LimeControlCrm::teamRole($team);
        $uid=(int)App()->user->id;
        $params=['team'=>$team,'type'=>$type,'id'=>$id];
        if ($action==='pdf') {
            if ($type!=='deal') throw new CHttpException(404);
            $offer=$db->offer($team,$id,(int)$req->getQuery('revision',0));
            $bytes=DeskDocuments::pdf($offer,str_starts_with(App()->language,'pl'));
            header('Content-Type: application/pdf');
            header('Content-Disposition: attachment; filename="offer-'.$id.'-'.$offer['revision'].'.pdf"');
            echo $bytes;
            App()->end();
        }
        $feedUrl=null;
        if ($req->isPostRequest) {
            // Also protect the form on installations with globally disabled validation.
            if (!is_string($req->getPost($req->csrfTokenName)) || !hash_equals($req->getCsrfToken(),$req->getPost($req->csrfTokenName))) throw new CHttpException(403);
            $op=$req->getPost('op');
            try {
                if ($type==='deal' && $op==='save') {
                    $items=DeskDocuments::lineItems((array)$req->getPost('name'),(array)$req->getPost('qty'),(array)$req->getPost('price'));
                    $db->saveOffer($team,$id,(int)$req->getPost('previous'),trim((string)$req->getPost('title')),trim((string)$req->getPost('customer')),(string)$req->getPost('currency'),$items,$uid);
                } elseif ($type==='deal' && $op==='decide') {
                    $db->decide($team,$id,(int)$req->getPost('revision'),(string)$req->getPost('decision'),$uid);
                } elseif ($type==='case' && $op==='assign' && in_array($role,['admin','manager'],true)) {
                    $db->assignMail($team,(string)$req->getPost('mail'),$id);
                } elseif ($type==='task' && in_array($op,['issue','revoke'],true)) {
                    LimeControlCrm::calendarTasks($team,$uid);
                    $key=$db->feedKey($team,$uid,$op==='revoke');
                    if ($key!==null) $feedUrl=self::url('feed',['key'=>$key]);
                } else {
                    throw new CHttpException(403);
                }
            } catch (InvalidArgumentException $ex) {
                throw new CHttpException(422,$ex->getMessage());
            }
            if ($feedUrl===null) {
                $req->redirect(self::url('panel',$params),true,303);
            }
        }
        $offers=$type==='deal' ? $db->offers($team,$id) : [];
        $page=max(1, min(1000000,(int)$req->getQuery('page',1)));
        $messages=$type==='case' ? $db->messages($team,$id,$role!=='worker',($page-1)*25,26) : [];
        $more=count($messages)>25;
        if ($more) array_pop($messages);
        $assets=App()->assetManager->publish(dirname((new ReflectionClass(LimeControlCrm::class))->getFileName()).'/assets');
        $script=App()->assetManager->publish(__DIR__.'/desk.js');
        header('Content-Type: text/html; charset=UTF-8');
        include __DIR__.'/panel.php';
        App()->end();
    }
}
