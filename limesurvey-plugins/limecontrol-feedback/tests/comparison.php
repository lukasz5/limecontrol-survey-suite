<?php
// SPDX-License-Identifier: GPL-2.0-or-later
require_once __DIR__.'/../LimeControlFeedback/FeedbackComparison.php';
require_once __DIR__.'/../LimeControlFeedback/FeedbackResults.php';
require_once __DIR__.'/../LimeControlFeedback/FeedbackProtocol.php';

function verifyComparison($ok, $label) {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    echo "ok - $label\n";
}
function itemFact($id, $variant, $sku, $score) {
    return ['productId'=>$id,'variantId'=>$variant,'key'=>$sku,'score'=>$score];
}
$stores = [
    'shop-A'=>['campaigns'=>['product'=>['survey'=>11], 'returns'=>['survey'=>12], 'experience'=>['survey'=>13]]],
    'shop-B'=>['campaigns'=>['product'=>['survey'=>21], 'returns'=>['survey'=>22]]]
];
$allow = static function ($id) { return ['active'=>'Y','read'=>true,'anonymized'=>'N']; };
$pair = ['store'=>'shop-A','product'=>11,'returns'=>12];
verifyComparison(FeedbackComparison::scope($stores,11,$allow)===$pair && FeedbackComparison::scope($stores,12,$allow)===$pair,
    'entry from either survey resolves one configured store');
verifyComparison(FeedbackComparison::scope($stores,13,$allow)===null && FeedbackComparison::scope($stores,99,$allow)===null,
    'unrelated surveys cannot open the report');
foreach (['read'=>false,'anonymized'=>'Y','active'=>'N'] as $field=>$value) {
    foreach ([11,12] as $sid) {
        $access = static function ($id) use ($allow,$field,$value,$sid) {
            return $id === $sid ? array_replace($allow($id),[$field=>$value]) : $allow($id);
        };
        verifyComparison(FeedbackComparison::scope($stores,11,$access)===null, "deny $field on survey $sid");
    }
}
$split = $stores;
unset($split['shop-A']['campaigns']['returns']);
verifyComparison(FeedbackComparison::scope($split,11,$allow)===null, 'a returns survey from another store cannot complete a pair');
$old = itemFact('p','v1','OLD',1);
$new = itemFact('p','v1','NEW',5);
$otherVariant = itemFact('p','v2','<sku>',3);
$otherProduct = itemFact('q','v1','NEW',2);
$noSku = itemFact('14','8','14/8',null);
$skuOnly = itemFact('','','14/8',4);
$kept = [
    ['products'=>[$old,$otherVariant,$skuOnly],'returnReason'=>'BAD'],
    ['products'=>[$new,$otherProduct,$noSku]]
];
$return = [
    ['products'=>[$new],'totalItems'=>1,'returnReason'=>'FIT'],
    ['products'=>[$new,$otherVariant],'totalItems'=>2,'returnReason'=>'DAM'],
    ['products'=>[$new],'totalItems'=>6,'returnReason'=>'FAIL'],
    ['products'=>[$otherProduct],'returnReason'=>'MIND'],
    ['products'=>[$noSku],'totalItems'=>1,'returnReason'=>'OTHER'],
    ['products'=>[],'returnReason'=>'DESC'],
    ['products'=>[$new],'returnReason'=>''],
];
$result = FeedbackComparison::compare((function() use ($kept){yield from $kept;})(), $return);
$groups = [];
foreach ($result['groups'] as $row) {
    $groups[$row['level'].'/'.$row['product'].'/'.$row['variant']] = $row;
}
verifyComparison($result['summary']['scores'] === 5 && $result['summary']['mean'] === 3.0
    && $result['summary']['returns'] === 6, 'store counts scores from kept purchases and one reason per return response');
verifyComparison($groups['product/p/']['scores'] === 3 && $groups['product/p/']['returns'] === 1, 'product subtotal includes its variants');
verifyComparison($groups['variant/p/v1']['skus'] === ['NEW','OLD'] && $groups['variant/p/v1']['mean'] === 3.0
    && $groups['variant/p/v1']['reasons'] === ['FIT'=>1], 'stable variant identity survives SKU changes');
verifyComparison($groups['variant/q/v1']['reasons'] === ['MIND'=>1] && $groups['variant/q/v1']['scores'] === 1,
    'equal SKU or variant IDs on different products remain distinct');
verifyComparison($groups['sku//']['skus'] === ['14/8'] && $groups['variant/14/8']['skus'] === []
    && $groups['variant/14/8']['mean'] === null, 'real SKU and synthetic fallback never collide');
verifyComparison($result['unassigned']['returns']===3 && $result['unassigned']['reasons']===['DAM'=>1,'DESC'=>1,'FAIL'=>1],
    'missing or ambiguous attribution stays outside product and variant counts');
verifyComparison(FeedbackComparison::compare([],[])['summary']['mean']===null, 'empty sample has no mean');
$native = FeedbackResults::observe(['PRODUCT1'=>'4'],[['line_id'=>'22','sku'=>'N','product_id'=>'P','variant_id'=>'V','category'=>'C']],true);
verifyComparison(FeedbackComparison::compare([$native],[])['summary']['mean']===4.0, 'native persisted fact shape supported');

class CHtml { public static function encode($text) {return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');} }
function App() { return (object)['language'=>'pl']; }
$comparison = $result;
$store = '<sklep>';
ob_start();
require __DIR__.'/../LimeControlFeedback/views/comparison.php';
$html = ob_get_clean();
verifyComparison(str_contains($html,'&lt;sklep&gt;') && str_contains($html,'&lt;sku&gt;') && !str_contains($html,'<sku>'), 'HTML output escapes merchant-controlled values');
verifyComparison(str_contains($html,'Zwrot bez wskazanej pozycji') && str_contains($html,'Rozmiar lub dopasowanie'), 'ambiguous group and reason display in Polish');
