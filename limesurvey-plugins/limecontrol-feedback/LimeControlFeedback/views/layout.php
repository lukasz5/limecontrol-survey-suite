<!doctype html><html lang="<?= CHtml::encode($language) ?>" data-theme="light"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title><?= CHtml::encode($title) ?></title>
<link rel="stylesheet" href="<?= CHtml::encode($assets.'/components.css') ?>">
<link rel="stylesheet" href="<?= CHtml::encode($assets.'/feedback.css') ?>"></head><body>
<?php if ($back): ?><nav><?= CHtml::link($pl ? 'Wróć do ankiety' : 'Back to survey',$back,['class'=>'link']) ?></nav><?php endif ?>
<?php require __DIR__.'/'.$view.'.php'; ?>
</body></html>
