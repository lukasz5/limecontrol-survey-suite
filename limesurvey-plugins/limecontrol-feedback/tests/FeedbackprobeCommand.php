<?php
// Synthetic acceptance helper. Only added to a disposable CE console via YII_CONSOLE_COMMANDS.
class FeedbackprobeCommand extends CConsoleCommand
{
    public function run($args)
    {
        if (getenv('LCF_NATIVE_QA') !== 'disposable') { throw new RuntimeException('Disposable QA only'); }
        Yii::import('application.helpers.common_helper',true);
        App()->setComponent('session',['class'=>'ConsoleHttpSession','autoStart'=>false],false);
        $pm=App()->getPluginManager();
        $getPlugin=static function ($name) use ($pm) {
            $row=Plugin::model()->findByAttributes(['name'=>$name]);
            if (!$row || !$row->active) { throw new RuntimeException('Missing active '.$name); }
            return $pm->loadPlugin($name,$row->id);
        };
        $plugin=$getPlugin('LimeControlFeedback');
        $shop=getenv('LCF_NATIVE_STORE');
        if (!preg_match('/^qa[a-f0-9]{8}$/D',$shop)) { throw new RuntimeException('Expected a unique QA store'); }
        $settings=$plugin->getPluginSettings();
        $stores=json_decode($settings['stores']['current'],true) ?: [];
        $out=[];
        if ($args[0]==='setup') {
            [$product,$anon,$returns,$port]=array_map('intval',array_slice($args,1));
            $stores[$shop]=['secretEnv'=>'LCF_SUITE_SECRET','preflight'=>'https://127.0.0.1:'.$port.'/snapshot','campaigns'=>[
                'product'=>['survey'=>$product,'delayHours'=>0,'frequencyHours'=>0],
                'experience'=>['survey'=>$anon,'delayHours'=>0,'frequencyHours'=>0],
                'returns'=>['survey'=>$returns,'delayHours'=>0,'frequencyHours'=>0]]];
            $plugin->saveSettings(['stores'=>json_encode($stores,JSON_THROW_ON_ERROR)]);
            if (empty($settings['publicBase']['current'])) { $plugin->saveSettings(['publicBase'=>'https://feedback.example.invalid/index.php']); }
            $group=new UserGroup();
            $group->name=$shop; $group->owner_id=1; $group->description='Synthetic feedback test';
            if (!$group->save()) { throw new RuntimeException('Could not create test group'); }
            $out=['team'=>(int)$group->ugid];
        } elseif ($args[0]==='worker') {
            // Synthetic transport override exists only in this console process.
            foreach (['demoMode'=>false,'emailmethod'=>'smtp','emailsmtphost'=>'127.0.0.1:'.(int)$args[1],
                'emailsmtpuser'=>'','emailsmtppassword'=>'','emailsmtpssl'=>'','emailsmtpdebug'=>0] as $k=>$v) { App()->setConfig($k,$v); }
            $storage=(new ReflectionMethod($plugin,'storage'))->invoke($plugin);
            $processed=0;
            foreach ($storage->due(time(),10000) as $job) {
                if ($job['store']!==$shop) { continue; }
                (new ReflectionMethod($plugin,'processJob'))->invoke($plugin,$job);
                ++$processed;
            }
            $out=['processed'=>$processed];
        } elseif ($args[0]==='questions') {
            foreach (Question::model()->findAllByAttributes(['sid'=>(int)$args[1],'parent_qid'=>0]) as $question) { $out[$question->title]=(int)$question->qid; }
        } elseif ($args[0]==='verify') {
            [$product,$anon,$team]=array_map('intval',array_slice($args,1));
            $storage=(new ReflectionMethod($plugin,'storage'))->invoke($plugin);
            $facts=iterator_to_array($storage->facts($product,$shop));
            $cases=$storage->cases($product,$shop,0);
            $anonFacts=iterator_to_array($storage->facts($anon,$shop));
            $anonCases=$storage->cases($anon,$shop,0);
            if (count($facts)!==1 || count($cases)!==1 || $anonFacts || $anonCases || count($facts[0]['products'])!==1
                || $facts[0]['products'][0]['score']!==1 || $facts[0]['products'][0]['defect']!=='DAM') { throw new RuntimeException('Incorrect identified or anonymous data'); }
            $crm=$getPlugin('LimeControlCrm')::store();
            if ($cases[0]['stage']!=='closed') { throw new RuntimeException('Operator did not close case'); }
            $recipient=$storage->recipient($shop,'synthetic-buyer@example.invalid');
            if (!(int)App()->db->createCommand('SELECT blocked FROM {{lcf_contacts}} WHERE id=:id')->queryScalar([':id'=>$recipient])) {
                throw new RuntimeException('Unsubscribe was not persisted');
            }
            $data=json_decode($cases[0]['payload'],true);
            if ($crm->enabled($team) || $crm->mapping($product)) { throw new RuntimeException('Expected standalone shop before CRM enable'); }
            $event=new PluginEvent('limeControlFeedbackCase');
            foreach ($data as $k=>$v) { $event->set($k,$v); }
            $pm->dispatchEvent($event);
            if ($event->get('handled')===true || $crm->listing($team,'case')) { throw new RuntimeException('CRM accepted standalone case before enablement'); }
            $crm->enable($team);
            $crm->setMapping($team,$product,$shop,'','','');
            foreach ([1,2] as $attempt) {
                $event=new PluginEvent('limeControlFeedbackCase');
                foreach ($data as $k=>$v) { $event->set($k,$v); }
                $pm->dispatchEvent($event);
                if ($event->get('handled')!==true) { throw new RuntimeException('CRM did not accept consented case'); }
            }
            $caseCount=count($crm->listing($team,'case'));
            if ($caseCount!==1) { throw new RuntimeException('CRM case duplicated'); }
            $native=Response::model($anon)->find('submitdate IS NOT NULL');
            if (!$native) { throw new RuntimeException('Missing anonymous browser submission'); }
            $row=$native->attributes; unset($row['id']);
            for ($i=0;$i<4;$i++) {
                $extra=Response::create($anon);
                $extra->setAttributes($row,false);
                if (!$extra->save(false)) { throw new RuntimeException('Synthetic anonymous cohort not saved'); }
            }
            $survey=Survey::model()->findByPk($anon);
            $observations=(new ReflectionMethod($plugin,'anonymousFacts'))->invoke($plugin,$survey);
            $agg=FeedbackResults::aggregate($observations,5);
            $byName=array_column($agg,null,'metric');
            if (($byName['csat']['n']??0)!==5 || $byName['csat']['index']!=0
                || $byName['delivery']['index']!=100 || $byName['category.csat']['group']!=='GOODS') { throw new RuntimeException('Incorrect anonymous cohort'); }
            $out=['anonymousCohort'=>5,'standaloneBeforeTeamEnabled'=>true,'identifiedFacts'=>1,'returnedSkuAbsent'=>true,'anonymousFacts'=>0,'anonymousCases'=>0,'crmCasesAfterReplay'=>$caseCount];
        } elseif ($args[0]==='unmap') {
            unset($stores[$shop]); $plugin->saveSettings(['stores'=>json_encode($stores,JSON_THROW_ON_ERROR)]);
        }
        echo 'FEEDBACK_QA '.json_encode($out,JSON_THROW_ON_ERROR).PHP_EOL;
    }
}
