<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once __DIR__ . '/FeedbackProtocol.php';
require_once __DIR__ . '/FeedbackStorage.php';
require_once __DIR__ . '/FeedbackResults.php';
require_once __DIR__ . '/FeedbackComparison.php';

class LimeControlFeedback extends PluginBase
{
    protected static $name = 'LimeControlFeedback';
    protected static $description = 'Customer experience, delivery, product quality and returns campaigns';
    protected $storage = 'DbStorage';
    protected $settings = [
        'stores'=>['type'=>'text','label'=>'Stores and campaigns (JSON, see README)','default'=>'{}'],
        'publicBase'=>['type'=>'string','label'=>'Public HTTPS URL of the LimeSurvey index.php (used in invitation links)'],
        'retentionDays'=>['type'=>'int','label'=>'Days to retain order snapshots and identified cases','default'=>90],
    ];
    private $db;
    private $storeConfig;

    public function init()
    {
        foreach (['beforeActivate','newUnsecureRequest','newDirectRequest','cron','direct','beforeQuestionRender','afterSurveyComplete','beforeToolsMenuRender'] as $event) {
            $this->subscribe($event);
        }
    }

    public function beforeActivate()
    {
        if (!$this->get('contactKey')) { $this->set('contactKey',bin2hex(random_bytes(32))); }
        $this->storage()->install();
    }

    private function storage()
    {
        if (!$this->db) {
            $connection = App()->db;
            $connection->setActive(true);
            $this->db = new FeedbackStorage($connection->getPdoInstance(),$connection->tablePrefix,(string)$this->get('contactKey'));
        }
        return $this->db;
    }

    private function stores()
    {
        if ($this->storeConfig !== null) { return $this->storeConfig; }
        return $this->storeConfig = $this->parseStores((string)$this->get('stores',null,null,'{}'));
    }

    public function saveSettings($settings)
    {
        if (array_key_exists('stores',$settings)) {
            try { $this->parseStores((string)$settings['stores']); }
            catch (RuntimeException $error) { throw new CHttpException(422,$error->getMessage()); }
        }
        parent::saveSettings($settings);
        $this->storeConfig=null;
    }

    private function parseStores($json)
    {
        $stores = json_decode($json,true);
        if (!is_array($stores)) { throw new RuntimeException('Invalid stores configuration'); }
        $sids = [];
        $envs = [];
        foreach ($stores as $name=>&$store) {
            if (!is_array($store) || !preg_match('/^[a-zA-Z0-9_-]{1,80}$/D',(string)$name)
                || !preg_match('/^LCF_[A-Z0-9_]+$/D',$store['secretEnv'] ?? '') || isset($envs[$store['secretEnv']])
                || !is_string($store['preflight'] ?? null) || !is_array($store['campaigns'] ?? null)) {
                throw new RuntimeException('Invalid store configuration');
            }
            $envs[$store['secretEnv']] = true;
            foreach ($store['campaigns'] as $kind=>&$campaign) {
                if (!in_array($kind,FeedbackProtocol::KINDS,true) || !is_array($campaign) || !is_int($campaign['survey'] ?? null)
                    || $campaign['survey'] < 1 || isset($sids[$campaign['survey']])) {
                    throw new RuntimeException('Use a dedicated survey for every store and campaign');
                }
                $sids[$campaign['survey']] = true;
                $campaign += ['delayHours'=>['experience'=>24,'delivery'=>24,'product'=>168,'returns'=>24][$kind],
                    'frequencyHours'=>24,'lowScore'=>2];
                foreach (['delayHours','frequencyHours','lowScore'] as $field) {
                    if (!is_int($campaign[$field]) || $campaign[$field]<0 || $campaign[$field]>8760) { throw new RuntimeException('Invalid campaign setting'); }
                }
                if ($campaign['lowScore'] > 5) { throw new RuntimeException('Invalid score threshold'); }
            }
            unset($campaign);
        }
        unset($store);
        return $stores;
    }

    private function campaign($sid)
    {
        try { $stores=$this->stores(); }
        catch (RuntimeException $error) { return null; }
        foreach ($stores as $store=>$config) {
            foreach ($config['campaigns'] as $kind=>$campaign) {
                if ($campaign['survey'] === $sid) { return $campaign + ['store'=>(string)$store,'kind'=>$kind]; }
            }
        }
        return null;
    }

    private function secret(array $store)
    {
        $secret = getenv($store['secretEnv']);
        if (!$secret || strlen($secret) < 32) { throw new RuntimeException('Missing store secret'); }
        return $secret;
    }

    private function jsonResponse($status, array $data)
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        App()->end();
    }

    public function newUnsecureRequest()
    {
        $event = $this->getEvent();
        if ($event->get('target') !== self::$name || $event->get('function') !== 'ingest') { return; }
        $status = 200;
        $result = 'accepted';
        try {
            if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') { $this->jsonResponse(405,['status'=>'method']); return; }
            $body = file_get_contents('php://input',false,null,0,FeedbackProtocol::MAX_BYTES+1);
            $name = $_SERVER['HTTP_X_LC_STORE'] ?? '';
            $config = $this->stores()[$name] ?? null;
            if (!$config || !FeedbackProtocol::authenticated($body,$_SERVER['HTTP_X_LC_TIMESTAMP'] ?? '',
                $_SERVER['HTTP_X_LC_SIGNATURE'] ?? '',$this->secret($config),time())) {
                $this->jsonResponse(401,['status'=>'unauthorized']); return;
            }
            $result = $this->storage()->accept($name,FeedbackProtocol::snapshot($body),$config['campaigns'],time());
        } catch (InvalidArgumentException $e) { $status=400; $result='invalid'; }
        catch (Throwable $e) { $status=503; $result='unavailable'; }
        $this->jsonResponse($status,['status'=>$result]);
    }

    private function url($route, array $parameters)
    {
        $base = (string)$this->get('publicBase');
        if (parse_url($base,PHP_URL_SCHEME) !== 'https' || !parse_url($base,PHP_URL_HOST) || parse_url($base,PHP_URL_QUERY) !== null
            || parse_url($base,PHP_URL_USER) !== null || parse_url($base,PHP_URL_FRAGMENT) !== null) { throw new RuntimeException('Invalid public URL'); }
        return rtrim($base,'/').'/'.$route.'?'.http_build_query(['r'=>$route]+$parameters,'','&',PHP_QUERY_RFC3986);
    }

    private function sendOne($survey, array $invite)
    {
        $participant = Token::model((int)$survey->sid)->findByPk($invite['tid']);
        if (!$participant || $participant->emailstatus !== 'OK') { throw new RuntimeException('Participant opted out'); }
        $link=$this->url('survey/index',['sid'=>$invite['sid'],'token'=>$participant->token,'lang'=>$survey->language]);
        $recipient=$invite['recipient'];
        $proof=hash_hmac('sha256','unsubscribe.'.$recipient,(string)$this->get('contactKey'));
        $quit=$this->url('plugins/direct',['plugin'=>self::$name,'function'=>'unsubscribe','recipient'=>$recipient,'proof'=>$proof]);
        $pl=$survey->language === 'pl';
        $mail=new LimeMailer();
        $mail->emailType='invite';
        $mail->setFrom($survey->adminemail,$survey->admin);
        $mail->addAddress($invite['email']);
        $mail->isHTML(false);
        $mail->Subject=$pl ? 'Zaproszenie do oceny zakupu' : 'Tell us about your purchase';
        $mail->Body=($pl ? 'Wypełnij krótką ankietę' : 'Take the short survey')."\n".$link."\n\n".
            ($pl ? 'Zrezygnuj z kolejnych ankiet tego sklepu' : 'Stop receiving surveys from this store')."\n".$quit;
        if (!$mail->send()) { throw new RuntimeException('Unconfirmed mail transport'); }
        $this->storage()->markSent($invite['id']);
        $participant->sent=date('Y-m-d H:i');
        $participant->save(false,['sent']);
    }

    private function processJob(array $job)
    {
        $store=$this->stores()[$job['store']] ?? null;
        $campaign=$store['campaigns'][$job['kind']] ?? null;
        if (!$campaign || $campaign['survey'] !== (int)$job['sid']) { throw new RuntimeException('Campaign remapped'); }
        $survey=Survey::model()->findByPk($campaign['survey']);
        if (!$survey || $survey->active !== 'Y' || !$survey->getHasTokensTable() || !filter_var($survey->adminemail,FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Survey unavailable');
        }
        // Validate generated links before reserving any delivery.
        $this->url('survey/index',['sid'=>$campaign['survey']]);
        $old=json_decode($job['body'],true,16,JSON_THROW_ON_ERROR);
        $snapshot=FeedbackProtocol::preflight($store['preflight'],$job['store'],$this->secret($store),$old['order_id']);
        $this->storage()->accept($job['store'],$snapshot,$store['campaigns'],time());
        $invite=$this->storage()->claim($job['id'],$snapshot,$campaign,time(),function ($sid,$email) use ($survey) {
            $token=Token::create($sid);
            $token->email=$email;
            $token->language=$survey->language;
            $token->usesleft=1;
            $token->emailstatus='OK';
            $token->generateToken();
            if (!$token->save()) { throw new RuntimeException('Cannot create participant'); }
            return $token->emailstatus === 'OK' ? $token->tid : null;
        });
        if ($invite) { $this->sendOne($survey,$invite+['id'=>$job['id']]); }
    }

    public function direct()
    {
        if ($this->getEvent()->get('target') === self::$name && $this->getEvent()->get('function') === 'run') { $this->cron(); }
    }

    public function cron()
    {
        if (PHP_SAPI !== 'cli') { return; }
        foreach ($this->storage()->due(time()) as $job) {
            try { $this->processJob($job); }
            catch (Throwable $e) {
                // Never include names, tokens, order identifiers, or payloads in logs.
                Yii::log('A customer feedback invitation needs retry or transport reconciliation','warning','plugin.LimeControlFeedback');
                $this->storage()->defer($job['id'],time());
            }
        }
        foreach ($this->storage()->pendingCases() as $row) {
            $this->storage()->markAttempt($row['id'],time());
            $data=json_decode($row['payload'],true,16,JSON_THROW_ON_ERROR);
            $survey=Survey::model()->findByPk((int)$data['surveyId']);
            $campaign=$this->campaign((int)$data['surveyId']);
            if (!$survey || $survey->anonymized === 'Y' || !$campaign || $campaign['store'] !== $data['store']) { continue; }
            try {
                $event=new PluginEvent('limeControlFeedbackCase');
                foreach ($data as $field=>$value) { $event->set($field,$value); }
                App()->getPluginManager()->dispatchEvent($event);
                if ($event->get('handled') === true) { $this->storage()->markHandled($row['id']); }
            } catch (Throwable $e) { Yii::log('Customer feedback case delivery pending','warning','plugin.LimeControlFeedback'); }
        }
        $this->storage()->prune(time(),max(1,(int)$this->get('retentionDays',null,null,90)));
    }

    private function eligibleContext($survey, $token)
    {
        if ($survey->anonymized === 'Y' || !$token) { return null; }
        $participant=Token::model((int)$survey->sid)->findByToken($token);
        if (!$participant) { return null; }
        $context=$this->storage()->context((int)$survey->sid,(int)$participant->tid);
        $campaign=$this->campaign((int)$survey->sid);
        if (!$context || !$campaign || $context['store'] !== $campaign['store'] || empty($context['body']['email'])
            || $this->storage()->recipient($context['store'],$context['body']['email']) !== $context['recipient']) { return null; }
        return $context;
    }

    public function beforeQuestionRender()
    {
        $event=$this->getEvent();
        $sid=(int)$event->get('surveyId');
        $code=(string)$event->get('code');
        if (!preg_match('/^(PRODUCT|DEFECT)([1-5])$/D',$code,$slot) || !$this->campaign($sid)) { return; }
        $survey=Survey::model()->findByPk($sid);
        if (!$survey) { return; }
        $word=$slot[1];
        $index=(int)$slot[2]-1;
        $item=null;
        if ($survey->anonymized === 'Y') {
            if ($word === 'PRODUCT' && $index === 0) { return; }
        } else {
            $session=$_SESSION['responses_'.$sid] ?? ($_SESSION['survey_'.$sid] ?? []);
            $token=$session['token'] ?? ($session['tokenused'] ?? '');
            $ctx=$this->eligibleContext($survey,$token);
            $item=$ctx['selected'][$index] ?? null;
        }
        if ($item) {
            $event->set('text',$event->get('text').' — '.CHtml::encode($item['label']));
        } else {
            $event->set('class',trim($event->get('class').' d-none'));
            $event->set('aHtmlOptions',array_merge((array)$event->get('aHtmlOptions'),['hidden'=>'hidden','aria-hidden'=>'true']));
            $event->set('answers','');
            $event->set('mandatory',false);
        }
    }

    public function afterSurveyComplete()
    {
        $event=$this->getEvent();
        $sid=(int)$event->get('surveyId');
        $rid=(int)$event->get('responseId');
        if (!$rid || !($campaign=$this->campaign($sid))) { return; }
        $survey=Survey::model()->findByPk($sid);
        if (!$survey || $survey->anonymized === 'Y') { return; }
        $answers=$this->api->getResponse($sid,$rid);
        if (!is_array($answers) || empty($answers['submitdate'])) { return; }
        $ctx=$this->eligibleContext($survey,$answers['token'] ?? '');
        if (!$ctx || $ctx['store'] !== $campaign['store']) { return; }
        $report=FeedbackResults::observe($answers,$ctx['selected'],true);
        $report['totalItems']=$ctx['totalItems'];
        $base=['store'=>$ctx['store'],'surveyId'=>$sid,'responseId'=>$rid,'orderId'=>$ctx['body']['order_id'],'email'=>$ctx['body']['email'],'customerRef'=>$ctx['body']['customer_ref'] ?? ''];
        $cases=FeedbackResults::cases($answers,$report,$base,$campaign['lowScore']);
        $this->storage()->saveObservation($sid,$rid,$ctx['store'],$report,$cases,time());
    }

    public function beforeToolsMenuRender()
    {
        $event=$this->getEvent();
        $sid=(int)$event->get('surveyId');
        if (!$sid || App()->user->isGuest || !$this->campaign($sid) || !Permission::model()->hasSurveyPermission($sid,'responses','read')) { return; }
        $items=$event->get('menuItems') ?: [];
        $items[]=new \LimeSurvey\Menu\MenuItem(['label'=>'Customer feedback','href'=>CHtml::encode($this->api->createUrl('plugins/direct',
            ['plugin'=>self::$name,'function'=>'report','sid'=>$sid]))]);
        if ($this->comparisonScope($sid)) {
            $items[]=new \LimeSurvey\Menu\MenuItem(['label'=>'Product ratings and returns','href'=>CHtml::encode($this->api->createUrl('plugins/direct',
                ['plugin'=>self::$name,'function'=>'comparison','sid'=>$sid]))]);
        }
        if ($this->canHandleCases($sid) && Survey::model()->findByPk($sid)->anonymized !== 'Y') {
            $items[]=new \LimeSurvey\Menu\MenuItem(['label'=>'Feedback cases','href'=>CHtml::encode($this->api->createUrl('plugins/direct',
                ['plugin'=>self::$name,'function'=>'cases','sid'=>$sid]))]);
        }
        $event->set('menuItems',$items);
    }

    private function anonymousFacts($survey)
    {
        // Read only the mapped numeric / enumerated columns. No timestamps, tokens, free text or order IDs.
        $codes=['CSAT','DELIVERY','NPS','PRODUCT1','CATEGORY','RETURNREASON'];
        $map=[];
        foreach (createFieldMap($survey,'full',null,false,$survey->language) as $f) {
            if (in_array($f['title'] ?? '',$codes,true) && !empty($f['qid']) && empty($f['aid'])) {
                // Free-text answers may contain contact details. Category and reason must be closed single choice.
                if (in_array($f['title'],['CATEGORY','RETURNREASON'],true) && !in_array($f['type'],['L','!'],true)) { continue; }
                $map[$f['fieldname']]=$f['title'];
            }
        }
        if (!$map) { return; }
        $connection=App()->db;
        $model=Response::model((int)$survey->sid);
        $reader=$connection->createCommand()->select(array_keys($map))->from($model->tableName())->where('submitdate IS NOT NULL')->query();
        foreach ($reader as $row) {
            $answers=[];
            foreach ($map as $field=>$code) { $answers[$code]=$row[$field]; }
            yield FeedbackResults::observe($answers,[],false);
        }
    }

    public function newDirectRequest()
    {
        $event=$this->getEvent();
        if ($event->get('target') !== self::$name) { return; }
        $action=$event->get('function');
        if ($action==='unsubscribe') { $this->handleUnsubscribe(); return; }
        if (!in_array($action,['report','cases','comparison'],true)) { throw new CHttpException(404); }
        $sid=(int)App()->request->getQuery('sid');
        if (App()->user->isGuest || !Permission::model()->hasSurveyPermission($sid,'responses','read')) { throw new CHttpException(403); }
        $campaign=$this->campaign($sid);
        $survey=Survey::model()->findByPk($sid);
        if (!$campaign || !$survey || $survey->active !== 'Y') { throw new CHttpException(404); }
        if ($action==='comparison') {
            $scope=$this->comparisonScope($sid);
            if (!$scope) { throw new CHttpException(403); }
            $comparison=FeedbackComparison::compare($this->storage()->facts($scope['product'],$scope['store']),
                $this->storage()->facts($scope['returns'],$scope['store']));
            $this->renderView('comparison',['comparison'=>$comparison,'store'=>$scope['store']]);
            return;
        }
        if ($action==='cases') {
            if ($survey->anonymized === 'Y' || !$this->canHandleCases($sid)) { throw new CHttpException(403); }
            $page=max(0,min(100000,(int)App()->request->getQuery('page',0)));
            if (App()->request->isPostRequest) {
                $this->storage()->setStage($sid,$campaign['store'],(string)App()->request->getPost('case'),(string)App()->request->getPost('stage'));
            }
            $cases=$this->storage()->cases($sid,$campaign['store'],$page);
            $url=$this->api->createUrl('plugins/direct',['plugin'=>self::$name,'function'=>'cases','sid'=>$sid]);
            $this->renderView('cases',['cases'=>$cases,'page'=>$page,'url'=>$url]);
            return;
        }
        $anon=$survey->anonymized === 'Y';
        $rows=$anon ? $this->anonymousFacts($survey) : $this->storage()->facts($sid,$campaign['store']);
        $result=FeedbackResults::aggregate($rows,$anon ? 5 : 1);
        $this->renderView('report',['metrics'=>$result,'anonymous'=>$anon,'queue'=>$this->storage()->operationalCounts($sid,$campaign['store'])]);
    }

    private function comparisonScope($sid)
    {
        return FeedbackComparison::scope($this->stores(),$sid,static function ($surveyId) {
            $survey=Survey::model()->findByPk($surveyId);
            if (!$survey) { return null; }
            return ['read'=>Permission::model()->hasSurveyPermission($surveyId,'responses','read'),
                'active'=>$survey->active,'anonymized'=>$survey->anonymized];
        });
    }

    private function canHandleCases($sid)
    {
        return Permission::model()->hasSurveyPermission($sid,'tokens','read')
            && Permission::model()->hasSurveyPermission($sid,'responses','update');
    }

    private function renderView($view,array $data)
    {
        header('Cache-Control: no-store');
        header('Referrer-Policy: no-referrer');
        header('Content-Type: text/html; charset=utf-8');
        $assets=App()->assetManager->publish(__DIR__.'/assets');
        $language=App()->language;
        $pl=str_starts_with($language,'pl');
        $titles=$pl ? ['report'=>'Opinie klientów','cases'=>'Sprawy klientów','comparison'=>'Oceny produktów i przyczyny zwrotów','unsubscribe'=>'Zaproszenia do ankiet']
            : ['report'=>'Customer feedback','cases'=>'Customer cases','comparison'=>'Product ratings and reasons for returns','unsubscribe'=>'Survey invitations'];
        $title=$titles[$view];
        $back=$view === 'unsubscribe' ? '' : App()->createUrl('surveyAdministration/view',['iSurveyID'=>(int)App()->request->getQuery('sid')]);
        extract($data,EXTR_SKIP);
        require __DIR__.'/views/layout.php';
        App()->end();
    }

    private function handleUnsubscribe()
    {
        $recipient=(string)App()->request->getQuery('recipient','');
        $proof=(string)App()->request->getQuery('proof','');
        if (!preg_match('/^[a-f0-9]{64}$/D',$recipient) || !hash_equals(hash_hmac('sha256','unsubscribe.'.$recipient,(string)$this->get('contactKey')),$proof)) {
            throw new CHttpException(403);
        }
        $done=App()->request->isPostRequest;
        if ($done) { $this->storage()->unsubscribe($recipient); }
        $this->renderView('unsubscribe',['done'=>$done]);
    }
}
