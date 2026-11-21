<?php $pl = str_starts_with(App()->language,'pl'); $e=static function($value) { return CHtml::encode((string)$value); }; ?>
<main><h1><?= $pl ? 'Sprawy klientów' : 'Customer cases' ?></h1>
<?php if (!$cases): ?><p><?= $pl ? 'Brak spraw.' : 'There are no cases.' ?></p><?php else: ?>
<div class="overflow-x-auto"><table class="table"><thead><tr>
<?php foreach (($pl ? ['Zamówienie','Kontakt','Powód','SKU','Ocena','Stan'] : ['Order','Contact','Reason','SKU','Score','Stage']) as $label): ?><th scope="col"><?= $e($label) ?></th><?php endforeach ?>
</tr></thead><tbody>
<?php foreach (array_slice($cases,0,50) as $case): $data=json_decode($case['payload'],true); ?><tr>
<?php foreach (['orderId','email','kind','sku','score'] as $key): ?><td><?= $e($data[$key]) ?></td><?php endforeach ?>
<td><?= CHtml::beginForm('','post') ?><?= CHtml::hiddenField('case',$case['id'],['id'=>false]) ?>
<?= CHtml::dropDownList('stage',$case['stage'],($pl ? ['open'=>'Otwarta','working'=>'W obsłudze','closed'=>'Zamknięta'] : ['open'=>'Open','working'=>'Working','closed'=>'Closed']),['id'=>false,'aria-label'=>$pl ? 'Stan sprawy' : 'Case stage']) ?>
<button class="btn" type="submit"><?= $pl ? 'Zapisz' : 'Save' ?></button><?= CHtml::endForm() ?></td>
</tr><?php endforeach ?></tbody></table></div>
<?php endif ?>
<?php if ($page>0): ?><?= CHtml::link($pl ? 'Poprzednia strona' : 'Previous page', $url.(str_contains($url,'?') ? '&' : '?').'page='.($page-1)) ?><?php endif ?>
<?php if (count($cases)>50): ?><?= CHtml::link($pl ? 'Następna strona' : 'Next page', $url.(str_contains($url,'?') ? '&' : '?').'page='.($page+1)) ?><?php endif ?>
</main>
