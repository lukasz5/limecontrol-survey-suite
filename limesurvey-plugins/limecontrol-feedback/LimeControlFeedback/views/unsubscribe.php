<?php $pl = str_starts_with(App()->language, 'pl'); ?>
<main>
<h1><?= $pl ? 'Zaproszenia do ankiet' : 'Survey invitations' ?></h1>
<?php if ($done): ?>
<p role="status"><?= $pl ? 'Rezygnacja została zapisana.' : 'Your unsubscribe preference has been saved.' ?></p>
<?php else: ?>
<?= CHtml::beginForm('', 'post') ?>
<button class="btn" type="submit"><?= $pl ? 'Zrezygnuj z zaproszeń tego sklepu' : 'Unsubscribe from this store’s invitations' ?></button>
<?= CHtml::endForm() ?>
<?php endif ?>
</main>
