<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class FeedbackResults
{
    private static function category($value)
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_-]{1,40}$/D',$value) ? $value : '';
    }

    public static function observe(array $answers, array $selected, $identified)
    {
        $nps = (string)($answers['NPS'] ?? '');
        $out = ['csat'=>FeedbackProtocol::score($answers['CSAT'] ?? null),'delivery'=>FeedbackProtocol::score($answers['DELIVERY'] ?? null),
            'nps'=>preg_match('/^(?:[0-9]|10)$/D',$nps) ? (int)$nps : null,
            'category'=>self::category($answers['CATEGORY'] ?? null), 'returnReason'=>self::category($answers['RETURNREASON'] ?? null), 'products'=>[]];
        if (!$identified) {
            $rating = FeedbackProtocol::score($answers['PRODUCT1'] ?? null);
            $out['products'][] = ['key'=>'','category'=>self::category($answers['CATEGORY'] ?? null),'score'=>$rating,'defect'=>''];
            return $out;
        }
        foreach ($selected as $i=>$item) {
            $index = $i+1;
            $answer = $answers['PRODUCT'.$index] ?? null;
            $defect = self::category($answers['DEFECT'.$index] ?? null);
            $out['products'][] = ['key'=>FeedbackProtocol::productKey($item), 'category'=>$item['category'],
                'productId'=>$item['product_id'], 'variantId'=>$item['variant_id'], 'score'=>FeedbackProtocol::score($answer),
                'defect'=>$answer === 'NA' || in_array($defect,['NONE','NO','OK'],true) ? '' : $defect];
        }
        return $out;
    }

    public static function cases(array $answers, array $report, array $base, $low)
    {
        if (($answers['CONTACT'] ?? '') !== 'Y') { return []; }
        $items = [];
        $scores = array_values(array_filter([$report['csat'],$report['delivery']],static function ($v) { return $v !== null; }));
        foreach ($report['products'] as $product) {
            $score = $product['score'];
            if ($score !== null) { $scores[] = $score; }
            if (($score !== null && $score <= $low) || $product['defect'] !== '') {
                $items[] = ['sku'=>$product['key'],'score'=>$score,'defect'=>$product['defect']];
            }
        }
        $score = $scores ? min($scores) : 0;
        $kind = 'contact_request';
        if (($answers['RETURN'] ?? '') === 'Y') { $kind = 'return_request'; }
        elseif ($items || ($score && $score <= $low)) { $kind = 'low_score'; }
        return [$base + ['kind'=>$kind,'score'=>$score,'sku'=>$items[0]['sku'] ?? '', 'items'=>$items,'contactAllowed'=>true]];
    }

    /** One row per metric and grouping. Only response-derived, enumerated categories enter anonymous aggregates. */
    public static function aggregate(iterable $observations, $minimum)
    {
        $buckets = [];
        $add = static function ($metric,$key,$value,$positive) use (&$buckets) {
            if ($value === null) { return; }
            $index = json_encode([$metric,$key],JSON_THROW_ON_ERROR);
            if (!isset($buckets[$index])) { $buckets[$index]=['metric'=>$metric,'group'=>$key,'n'=>0,'sum'=>0,'positive'=>0]; }
            ++$buckets[$index]['n'];
            $buckets[$index]['sum'] += $value;
            $buckets[$index]['positive'] += $positive;
        };
        foreach ($observations as $row) {
            foreach (['csat','delivery'] as $name) { $add($name,'',$row[$name],$row[$name] >= 4 ? 1 : 0); }
            if (!empty($row['category'])) { $add('category.csat',$row['category'],$row['csat'],$row['csat'] >= 4 ? 1 : 0); }
            if ($row['nps'] !== null) { $add('nps','',$row['nps'],$row['nps'] >= 9 ? 1 : ($row['nps'] <= 6 ? -1 : 0)); }
            if ($row['returnReason'] !== '') { $add('return.reason',$row['returnReason'],1,0); }
            foreach ($row['products'] as $p) {
                $add('product.quality','',$p['score'],$p['score'] >= 4 ? 1 : 0);
                if ($p['key'] !== '') {
                    $add('sku.quality',$p['key'],$p['score'],$p['score'] >= 4 ? 1 : 0);
                    if ($p['defect'] !== '') { $add('sku.defect',json_encode([$p['key'],$p['defect']],JSON_UNESCAPED_UNICODE),1,0); }
                }
                if ($p['category'] !== '') { $add('category.quality',$p['category'],$p['score'],$p['score'] >= 4 ? 1 : 0); }
            }
        }
        $output = [];
        foreach ($buckets as $entry) {
            if ($entry['n'] < $minimum) { continue; }
            $countOnly = in_array($entry['metric'],['sku.defect','return.reason'],true);
            $output[]=['metric'=>$entry['metric'],'group'=>$entry['group'],'n'=>$entry['n'],
                'mean'=>$countOnly ? null : round($entry['sum']/$entry['n'],2),
                'index'=>$countOnly ? null : round(100*$entry['positive']/$entry['n'],1)];
        }
        return $output;
    }
}
