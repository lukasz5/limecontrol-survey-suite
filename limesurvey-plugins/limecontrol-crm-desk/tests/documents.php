<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require __DIR__.'/../LimeControlCrmDesk/DeskDocuments.php';
function check($ok,$message) { if (!$ok) throw new RuntimeException($message); }
$items=DeskDocuments::lineItems(['Konsultacja','Warsztat',''],['3','2',''],['12,34','150.01','']);
check(count($items)===2 && $items[0]['price']===1234 && DeskDocuments::money(3*1234+2*15001)==='337.04','Decimal arithmetic');
foreach (['-1','1.001','1e7','12345678.99'] as $price) {
    try { DeskDocuments::lineItems(['Treść'],['1'],[$price]); throw new RuntimeException('Invalid input accepted'); }
    catch (InvalidArgumentException $expected) {}
}
$label=str_repeat('Żółć ',32).", ;\nodbiór";
$ics=DeskDocuments::calendar([['id'=>4,'title'=>$label,'due'=>'2026-12-31']], 't1-u1','CRM');
check(str_contains($ics,'DTEND;VALUE=DATE:20270101'),'End date');
foreach (explode("\r\n",$ics) as $line) check(strlen($line)<=75 && mb_check_encoding($line,'UTF-8'),'Folding');
check(str_contains(str_replace("\r\n ",'',$ics),'\, \;\nodbiór'),'Text escaping');
if (isset($argv[1],$argv[2])) {
    require $argv[1];
    $offer=['deal_id'=>41,'revision'=>2,'created_at'=>'2026-11-20','customer'=>'Pracownia Żuraw','title'=>'Wdrożenie obsługi klienta','items'=>json_encode($items),'currency'=>'PLN'];
    file_put_contents($argv[2],DeskDocuments::pdf($offer,true));
}
echo "OK: revision items, decimal amounts, calendar escaping and UTF-8 folding\n";
