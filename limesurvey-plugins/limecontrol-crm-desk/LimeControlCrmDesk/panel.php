<?php
// SPDX-License-Identifier: GPL-2.0-or-later
$h=static fn($v)=>htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
$t=static fn($pl,$en)=>$h(LimeControlCrmDesk::tr($pl,$en));
$csrf=CHtml::hiddenField($req->csrfTokenName,$req->getCsrfToken(),['id'=>false]);
$back=LimeControlCrm::url(['team'=>$team,'tab'=>'record','type'=>$type,'id'=>$id]);
?>
<!doctype html><html lang="<?=$h(App()->language)?>" data-theme="light"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?=$h($record['title'])?> · CRM</title>
<link rel="stylesheet" href="<?=$h($assets)?>/components.css"><link rel="stylesheet" href="<?=$h($assets)?>/panel.css">
<script src="<?=$h($script)?>" defer></script></head><body><main>
<a href="<?=$h($back)?>">← <?=$h($record['title'])?></a>
<?php if ($type==='deal'):
$latest=$offers[0]??[];
$old=$latest ? json_decode($latest['items'],true,32,JSON_THROW_ON_ERROR) : [];
$customer=$latest['customer']??($record['company_name']??'');
if (!$customer && !empty($record['parent_id'])) {
    try {$customer=LimeControlCrm::recordContext($team,'company',(int)$record['parent_id'])['title'];} catch (CHttpException $ignored) {}
}
?>
<h1><?=$t('Oferty handlowe','Sales offers')?></h1>
<?php foreach($offers as $v): ?>
<section class="sheet"><h2><?=$h($v['title'])?> · v<?=$h($v['revision'])?></h2>
<p><?=$h($v['customer'])?> · <?=$h($v['created_at'])?> · <?=$h(['pending'=>$t('Oczekuje','Pending'),'accepted'=>$t('Przyjęta','Accepted'),'declined'=>$t('Odrzucona','Declined')][$v['decision']])?></p>
<a href="<?=$h(self::url('pdf',$params+['revision'=>$v['revision']]))?>"><?=$t('Pobierz PDF','Download PDF')?></a>
<?php if ($v['decision']==='pending'): ?><form method="post"><?=$csrf?>
<input type="hidden" name="op" value="decide"><input type="hidden" name="revision" value="<?=$h($v['revision'])?>">
<button class="btn" name="decision" value="accepted"><?=$t('Zapisz przyjęcie','Record acceptance')?></button>
<button class="btn" name="decision" value="declined"><?=$t('Zapisz odrzucenie','Record rejection')?></button></form><?php endif;?>
</section><?php endforeach;?>
<h2><?=$t('Nowa wersja oferty','New offer revision')?></h2>
<form method="post" class="sheet"><?=$csrf?><input type="hidden" name="op" value="save"><input type="hidden" name="previous" value="<?=$h($latest['revision']??0)?>">
<p><label><?=$t('Tytuł','Title')?> <input class="input" name="title" required maxlength="200" value="<?=$h($latest['title']??$record['title'])?>"></label></p>
<p><label><?=$t('Odbiorca','Customer')?> <input class="input" name="customer" required maxlength="200" value="<?=$h($customer)?>"></label>
<label><?=$t('Waluta','Currency')?> <select class="select" name="currency"><?php foreach(['PLN','EUR','USD','GBP'] as $currency):?><option <?=$currency===($latest['currency']??'PLN')?'selected':''?>><?=$currency?></option><?php endforeach;?></select></label></p>
<div class="overflow-x-auto"><table class="table"><thead><tr><th><?=$t('Pozycja','Item')?></th><th><?=$t('Ilość','Quantity')?></th><th><?=$t('Cena jednostkowa','Unit price')?></th></tr></thead><tbody id="lcdesk-lines">
<?php foreach(array_merge($old,array_fill(0,min(3,100-count($old)),[])) as $item):?>
<tr><td><input aria-label="<?=$t('Pozycja','Item')?>" class="input" name="name[]" maxlength="180" value="<?=$h($item['name']??'')?>"></td>
<td><input aria-label="<?=$t('Ilość','Quantity')?>" class="input" name="qty[]" type="number" min="1" max="99999" value="<?=$h($item['quantity']??'')?>"></td>
<td><input aria-label="<?=$t('Cena','Price')?>" class="input" name="price[]" inputmode="decimal" value="<?=isset($item['price'])?$h(DeskDocuments::money($item['price'])):''?>"></td></tr>
<?php endforeach;?></tbody></table></div>
<button type="button" class="btn" id="lcdesk-add"><?=$t('Dodaj pozycję','Add item')?></button>
<button class="btn btn-primary"><?=$t('Zapisz wersję','Save revision')?></button></form>
<?php elseif ($type==='case'):?>
<h1><?=$t('Korespondencja sprawy','Case correspondence')?></h1>
<?php if (!$messages):?><p><?=$t('Brak wiadomości.','No messages.')?></p><?php endif;?>
<?php foreach ($messages as $m):?><section class="sheet"><h2><?=$h($m['subject'])?></h2><p><?=$h($m['sender'])?> · <?=$h($m['received_at'])?> UTC</p><pre><?=$h($m['body'])?></pre>
<?php if ($m['case_id']===null):?><form method="post"><?=$csrf?><input type="hidden" name="op" value="assign"><input type="hidden" name="mail" value="<?=$h($m['mail_key'])?>"><button class="btn"><?=$t('Przypisz do tej sprawy','Assign to this case')?></button></form><?php endif;?></section><?php endforeach;?>
<nav><?php if ($page>1):?><a href="<?=$h(self::url('panel',$params+['page'=>$page-1]))?>"><?=$t('Poprzednie','Previous')?></a><?php endif;?>
<?php if ($more):?><a href="<?=$h(self::url('panel',$params+['page'=>$page+1]))?>"><?=$t('Następne','Next')?></a><?php endif;?></nav>
<?php else:?>
<h1><?=$t('Mój kalendarz zadań','My task calendar')?></h1>
<p><?=$t('Dodaj adres subskrypcji do kalendarza. Każdy, kto zna adres, może odczytać przypisane Ci terminy w tym zespole. Wygenerowanie nowego adresu unieważnia poprzedni.','Subscribe using the address below. Anyone with the address can read your assigned due dates for this team. Issuing a new address revokes the previous one.')?></p>
<?php if ($feedUrl):?><p><label><?=$t('Adres subskrypcji','Subscription address')?> <input class="input" readonly value="<?=$h($req->hostInfo.$feedUrl)?>"></label></p><?php endif;?>
<form method="post"><?=$csrf?><button class="btn" name="op" value="issue"><?=$t('Utwórz adres','Issue address')?></button>
<button class="btn" name="op" value="revoke"><?=$t('Unieważnij adres','Revoke address')?></button></form>
<?php endif;?></main></body></html>
