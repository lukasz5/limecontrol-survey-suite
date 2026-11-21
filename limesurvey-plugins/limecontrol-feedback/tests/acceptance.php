<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once __DIR__.'/../LimeControlFeedback/FeedbackProtocol.php';
require_once __DIR__.'/../LimeControlFeedback/FeedbackStorage.php';
require_once __DIR__.'/../LimeControlFeedback/FeedbackResults.php';

function check($ok, $label) {
    if (!$ok) { throw new RuntimeException($label); }
}
function snap(array $edits = []) {
    $base = ['version'=>1,'order_id'=>'42','event_id'=>'evt-1','revision'=>1,'occurred_at'=>1000,'status'=>'eligible',
        'email'=>'guest@example.invalid','items'=>[['line_id'=>'5','sku'=>'A','label'=>'Widget','quantity'=>1],
        ['line_id'=>'6','sku'=>'','product_id'=>'7','variant_id'=>'9','label'=>'Other widget','quantity'=>1,'category'=>'Goods']]];
    return FeedbackProtocol::snapshot(json_encode(array_replace($base,$edits),JSON_THROW_ON_ERROR));
}
$secret=str_repeat('k',40);
$now=1700000000;
$raw='{"version":1}';
$sig=hash_hmac('sha256',$now.'.'.$raw,$secret);
check(FeedbackProtocol::authenticated($raw,(string)$now,$sig,$secret,$now),'valid signature');
check(!FeedbackProtocol::authenticated($raw.' ',(string)$now,$sig,$secret,$now),'modified body');
check(!FeedbackProtocol::authenticated($raw,(string)$now,$sig,$secret,$now+301),'stale signature');
$dsn=getenv('LCF_TEST_DSN') ?: 'sqlite::memory:';
$db=new PDO($dsn,getenv('LCF_TEST_USER') ?: null,getenv('LCF_TEST_PASSWORD') ?: null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$prefix='fbt'.bin2hex(random_bytes(4)).'_';
$store=new FeedbackStorage($db,$prefix,$secret);
$store->install();
$store->install();
$campaigns=['experience'=>['survey'=>1,'delayHours'=>0,'frequencyHours'=>1],
    'delivery'=>['survey'=>2,'delayHours'=>24,'frequencyHours'=>1],
    'product'=>['survey'=>3,'delayHours'=>24,'frequencyHours'=>1],
    'returns'=>['survey'=>4,'delayHours'=>0,'frequencyHours'=>0]];
$a=snap();
check($store->accept('s',$a,$campaigns,1000)==='accepted','event accepted');
check($store->accept('s',$a,$campaigns,1000)==='duplicate','event replay');
$due=$store->due(1001);
check(count($due)===1 && $due[0]['kind']==='experience','initial experience only');
$partial=snap(['event_id'=>'e2','revision'=>2,'occurred_at'=>1010,'delivered_at'=>1010,
    'items'=>[$a['items'][1]],'returned_items'=>[$a['items'][0]],'return_closed_at'=>1010]);
check($store->accept('s',$partial,$campaigns,1010)==='accepted','partial refund');
check($store->accept('s',$a,$campaigns,1010)==='stale','out of order');
check($store->claim($due[0]['id'],$a,$campaigns['experience'],1011,static function () { return 1; })===null,'stale preflight');
$target=function ($sid,$email) { check($sid===1 && $email==='guest@example.invalid','one targeted participant'); return 12; };
$invite=$store->claim($due[0]['id'],$partial,$campaigns['experience'],1011,$target);
check($invite['tid']===12,'claimed');
check($store->claim($due[0]['id'],$partial,$campaigns['experience'],1011,$target)===null,'retry after SMTP uncertainty');
check($store->operationalCounts(1,'s')['sending']==1,'ambiguous status preserved');
$store->markSent($due[0]['id']);
$context=$store->context(1,12);
check($context['selected'][0]['line_id']==='6' && count($context['selected'])===1,'refunded product excluded');
$returns=$store->due(1011);
check(count($returns)===1 && $returns[0]['kind']==='returns','a partial returned line triggers return feedback');
$store->claim($returns[0]['id'],$partial,$campaigns['returns'],1011,static function () { return 91; });
check($store->context(4,91)['selected'][0]['sku']==='A','return survey uses returned items');
$second=snap(['order_id'=>'43','event_id'=>'another']);
$store->accept('s',$second,$campaigns,1011);
$due=$store->due(1012);
check(count($due)===1 && !$store->claim($due[0]['id'],$second,$campaigns['experience'],1012,$target),'cross-order frequency cap');
$store->unsubscribe($invite['recipient']);
$third=snap(['order_id'=>'44','event_id'=>'next','occurred_at'=>8000]);
$store->accept('s',$third,$campaigns,8000);
$due=$store->due(8001);
check(count($due)===1 && !$store->claim($due[0]['id'],$third,$campaigns['experience'],8001,$target),'opt-out survives later orders');
check($store->recipient('s','Guest@example.invalid')!==$store->recipient('s2','guest@example.invalid'),'store scoped suppression');
$cancel=snap(['event_id'=>'cancel','revision'=>3,'status'=>'cancelled','email'=>'','items'=>[]]);
$store->accept('s',$cancel,$campaigns,8002);
check($store->operationalCounts(3,'s')['hold']>=1,'cancelled delayed product');
$answers=['CSAT'=>'2','NPS'=>'9','PRODUCT1'=>'1','DEFECT1'=>'DAM','PRODUCT2'=>'NA','DEFECT2'=>'FAIL','CONTACT'=>'Y','RETURN'=>'Y','CATEGORY'=>'GOODS'];
$report=FeedbackResults::observe($answers,$a['items'],true);
check($report['products'][1]['score']===null && $report['products'][1]['defect']==='','unused product excluded');
$cases=FeedbackResults::cases($answers,$report,['store'=>'s','surveyId'=>1,'responseId'=>17,'orderId'=>'42','email'=>'guest@example.invalid'],2);
check(count($cases)===1 && $cases[0]['sku']==='A' && $cases[0]['contactAllowed']===true,'consented return and low score cases');
$positive=FeedbackResults::observe(['CSAT'=>'5'],[],true);
check(FeedbackResults::cases(['CONTACT'=>'Y'],$positive,[],2)[0]['kind']==='contact_request','positive contact request');
$multiple=FeedbackResults::observe(['PRODUCT1'=>'2','PRODUCT2'=>'1','DEFECT2'=>'FAIL'],$a['items'],true);
$grouped=FeedbackResults::cases(['CONTACT'=>'Y'],$multiple,[],2);
check(count($grouped)===1 && count($grouped[0]['items'])===2,'one case for several defects');
check(FeedbackResults::cases(['CONTACT'=>'N'],$report,[],2)===[],'contact permission');
$store->saveObservation(1,17,'s',$report,$cases,8002);
$store->saveObservation(1,17,'s',$report,$cases,8002);
check(count($store->pendingCases())===1 && count(iterator_to_array($store->facts(1,'s')))===1,'response and case redelivery');
foreach ($store->pendingCases() as $case) { $store->markHandled($case['id']); }
check($store->pendingCases()===[],'CRM acknowledgement');
$anonymous=FeedbackResults::observe($answers,[],false);
check(strpos(json_encode($anonymous),'guest@')===false && $anonymous['products'][0]['key']==='','anonymous observations');
check(FeedbackResults::aggregate(array_fill(0,4,$anonymous),5)===[],'minimum group');
$rollup=FeedbackResults::aggregate(array_fill(0,5,$anonymous),5);
check(count($rollup)>0 && !in_array('sku.quality',array_column($rollup,'metric'),true),'anonymous aggregate only');
$reportRows=FeedbackResults::aggregate([$report,$report],1);
$defects=array_values(array_filter($reportRows,static function ($r) { return $r['metric']==='sku.defect'; }));
check(count($defects)===1 && $defects[0]['n']===2,'repeated SKU defect');
$store->prune(86400*10,1);
check(($store->context(1,12)['body'] ?? [])===[],'order context expires');
echo 'Feedback acceptance passed ('.$db->getAttribute(PDO::ATTR_DRIVER_NAME).")\n";
