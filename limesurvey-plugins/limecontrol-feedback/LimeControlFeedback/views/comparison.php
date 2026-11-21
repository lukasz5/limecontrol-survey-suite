<?php
$pl = str_starts_with(App()->language, 'pl');
$e = static function ($text) { return CHtml::encode((string) $text); };
$headings = $pl
    ? ['Produkt / wariant', 'Liczba ocen', 'Średnia ocena (1–5)', 'Odpowiedzi o przyczynie zwrotu', 'Przyczyny zwrotu']
    : ['Product / variant', 'Ratings', 'Mean score (1–5)', 'Responses with a return reason', 'Reasons for return'];
$labels = $pl ? ['product'=>'Produkt','variant'=>'Wariant','sku'=>'SKU'] : ['product'=>'Product','variant'=>'Variant','sku'=>'SKU'];
$reasonLabels = $pl
    ? ['DAM'=>'Uszkodzenie','FIT'=>'Rozmiar lub dopasowanie','DESC'=>'Niezgodność z opisem','FAIL'=>'Usterka podczas używania','OTHER'=>'Inny powód','MIND'=>'Zmiana decyzji']
    : ['DAM'=>'Damage','FIT'=>'Size or fit','DESC'=>'Different from the description','FAIL'=>'Failure during use','OTHER'=>'Another reason','MIND'=>'Changed my mind'];
$rows = [[$pl ? 'Cały sklep' : 'Entire store', $comparison['summary']]];
foreach ($comparison['groups'] as $group) {
    if ($group['level'] === 'sku') {
        $name = 'SKU ' . implode(', ', $group['skus']);
    } else {
        $name = $labels['product'] . ' ' . $group['product'];
        if ($group['level'] === 'variant') {
            $name .= ' · ' . $labels['variant'] . ' ' . $group['variant'];
        }
        if ($group['skus']) {
            $name .= ' · SKU ' . implode(', ', $group['skus']);
        }
    }
    $rows[] = [$name, $group];
}
if ($comparison['unassigned']['returns']) {
    $rows[] = [$pl ? 'Zwrot bez wskazanej pozycji' : 'Return with no item specified', $comparison['unassigned']];
}
?>
<main>
<h1><?= $pl ? 'Przyczyny zwrotów i oceny zatrzymanych produktów' : 'Reasons for returns and ratings of retained products' ?></h1>
<p><?= $pl ? 'Sklep' : 'Store' ?> <strong><?= $e($store) ?></strong></p>
<?php if (!$comparison['summary']['scores'] && !$comparison['summary']['returns']): ?>
<p><?= $pl ? 'Brak ocen produktów i odpowiedzi o przyczynach zwrotu.' : 'No product ratings or responses with a return reason yet.' ?></p>
<?php else: ?>
<div class="overflow-x-auto"><table class="table table-zebra">
<caption><?= $pl ? 'Oceny dotyczą pozycji pozostawionych po zwrotach znanych w chwili wysyłki ankiety produktowej. Przyczynę liczymy raz na odpowiedź w ankiecie zwrotowej.' : 'Ratings cover items retained after refunds known when the product survey invitation was sent. Each return survey response contributes at most one reason.' ?></caption>
<thead><tr><?php foreach ($headings as $heading): ?><th scope="col"><?= $e($heading) ?></th><?php endforeach ?></tr></thead>
<tbody>
<?php foreach ($rows as [$name,$row]): ?>
<tr>
<th scope="row"><?= $e($name) ?></th>
<td><?= (int) $row['scores'] ?></td><td><?= $row['mean'] === null ? '—' : $e(number_format($row['mean'], 2, $pl ? ',' : '.', '')) ?></td>
<td><?= (int) $row['returns'] ?></td>
<td><?php if ($row['reasons']): ?><ul><?php foreach ($row['reasons'] as $code => $count): ?><li><?= $e($reasonLabels[$code] ?? $code) ?> (<?= (int) $count ?>)</li><?php endforeach ?></ul><?php else: ?>—<?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</main>
