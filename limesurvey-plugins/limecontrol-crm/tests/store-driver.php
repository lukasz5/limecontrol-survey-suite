<?php
// SPDX-License-Identifier: GPL-2.0-or-later
// Pass a local disposable PostgreSQL/MySQL DSN and username. All test objects get a fresh random prefix.
require __DIR__.'/../LimeControlCrm/CrmStore.php';
require __DIR__.'/../LimeControlCrm/CrmCsv.php';
[$script,$dsn,$username]=$argv;
if (!preg_match('/^(?:pgsql:host=(?:\/private\/tmp|\/tmp|127\.0\.0\.1|localhost)|mysql:unix_socket=(?:\/private\/tmp|\/tmp))/', $dsn)) {throw new RuntimeException('Disposable local DSN required.');}
$db=new PDO($dsn,$username,'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>true]);
$prefix='lc_test_'.bin2hex(random_bytes(6)).'_'; $store=new LcCrmStore($db,$prefix);
try {
    $store->install(); $store->enable(1); $row=$store->save(1,'company',['title'=>'Pracownia Żuraw','tags'=>'Łódź'],0);
    // Simulate the previous candidate schema and prove that reactivation backfills its records.
    $db->exec('ALTER TABLE '.$store->table('lc_crm_record').' DROP COLUMN search_text');$store->install();$store->install();
    foreach(['PRACOWNIA ŻURAW','praCowNia żuraw','ŁÓDŹ'] as $query){$list=$store->listing(1,'company',null,$query);if(count($list)!==1 || $list[0]['id']!==$row['id']){throw new RuntimeException('Unicode search mismatch');}}
    $csv=implode(',',LcCrmCsv::COLUMNS)."\n".$row['id'].",Fikcyjny klient,buyer@example.invalid,,Łódź\n";
    if(LcCrmCsv::apply($store,1,0,LcCrmCsv::preview($store,1,$csv),[])!==1 || LcCrmCsv::apply($store,1,0,LcCrmCsv::preview($store,1,$csv),[])!==0){throw new RuntimeException('CSV replay failed');}
    echo $db->getAttribute(PDO::ATTR_DRIVER_NAME)." schema migration, Unicode search and duplicate CSV passed.\n";
} finally {foreach(['lc_crm_record','lc_crm_survey','lc_crm_history','lc_crm_team'] as $table){$db->exec('DROP TABLE IF EXISTS '.$store->table($table));}}
