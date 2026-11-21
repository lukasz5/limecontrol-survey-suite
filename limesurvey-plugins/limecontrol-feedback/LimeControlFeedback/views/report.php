<?php $pl = str_starts_with(App()->language,'pl'); $e = static function ($text) { return CHtml::encode((string)$text); }; ?>
<main>
<h1><?= $pl ? 'Opinie klientów' : 'Customer feedback' ?></h1>
<?php if ($anonymous): ?><p><?= $pl ? 'Raport anonimowy pokazuje grupy od pięciu ocen.' : 'Anonymous reporting shows groups with at least five ratings.' ?></p><?php endif ?>
<?php if (!$metrics): ?><p><?= $pl ? 'Brak ocen do wyświetlenia.' : 'There are no reportable ratings yet.' ?></p>
<?php else: ?>
<div class="overflow-x-auto"><table class="table"><thead><tr>
<?php foreach (($pl ? ['Wskaźnik','Grupa','Liczba','Średnia','CSAT (%) / NPS'] : ['Metric','Group','Count','Mean','CSAT (%) / NPS']) as $label): ?><th scope="col"><?= $e($label) ?></th><?php endforeach ?>
</tr></thead><tbody>
<?php foreach ($metrics as $metric): ?><tr><?php foreach ($metric as $value): ?><td><?= $e($value ?? '—') ?></td><?php endforeach ?></tr><?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<h2><?= $pl ? 'Zaproszenia' : 'Invitations' ?></h2>
<?php if (!$queue): ?><p><?= $pl ? 'Brak zaproszeń.' : 'There are no invitations yet.' ?></p><?php else: ?>
<dl><?php foreach ($queue as $state=>$count): ?><dt><?= $e($state) ?></dt><dd><?= (int)$count ?></dd><?php endforeach ?></dl>
<?php endif ?>
</main>
