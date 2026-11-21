<?php
// SPDX-License-Identifier: GPL-2.0-or-later
/** Run only inside a disposable LimeSurvey installation seeded with the synthetic admin. */
final class DeskfixtureCommand extends CConsoleCommand
{
    public function run($args)
    {
        if (getenv('LC_DESK_SYNTHETIC')!=='1') throw new RuntimeException('Disposable database required');
        $user=User::model()->findByAttributes(['users_name'=>'suiteadmin','email'=>'admin@example.invalid']);
        if (!$user) throw new RuntimeException('Missing synthetic operator');
        $manager=App()->getPluginManager();
        foreach (['LimeControlCrm','LimeControlCrmDesk'] as $name) {
            if (!$manager->isPluginActive($name)) throw new RuntimeException('Plugin inactive: '.$name);
            $manager->loadPlugin($name);
        }
        $group=UserGroup::model()->findByAttributes(['name'=>'Desk QA']);
        if (!$group) {
            $group=new UserGroup();
            $group->name='Desk QA'; $group->owner_id=$user->uid; $group->description='Synthetic team';
            if (!$group->save(false)) throw new RuntimeException('Group creation failed');
        }
        $s=LimeControlCrm::store(); $team=(int)$group->ugid; $uid=(int)$user->uid;
        $s->enable($team);
        $ids=['team'=>$team];
        foreach (['company'=>'Pracownia Żuraw','deal'=>'Wdrożenie obsługi','case'=>'Zgłoszenie pilotażowe','task'=>'Oddzwonić do klienta'] as $kind=>$title) {
            $rows=$s->listing($team,$kind,$uid,$title);
            $extra=[];
            if (in_array($kind,['deal','case','task'],true)) $extra['parent_id']=$ids['company'];
            if ($kind==='task') $extra['due']='2026-12-03';
            $r=$rows[0]??$s->save($team,$kind,['title'=>$title,'owner_id'=>$uid]+$extra,$uid);
            $ids[$kind]=(int)$r['id'];
        }
        $box=new DeskStore(App()->db);
        $dir=getenv('LC_DESK_QA_MAILDIR');
        DeskMailbox::sync($box,$team,$dir);
        if (DeskMailbox::sync($box,$team,$dir)!==0) throw new RuntimeException('Maildir not idempotent');
        $mail=$box->messages($team,$ids['case'],true);
        $mail=array_values(array_filter($mail,fn($m)=>$m['subject']==='Oferta testowa'));
        if (count($mail)!==1 || !str_contains($mail[0]['body'],'Żuraw')) throw new RuntimeException('MIME import mismatch');
        echo 'DESK '.json_encode($ids).PHP_EOL;
    }
}
