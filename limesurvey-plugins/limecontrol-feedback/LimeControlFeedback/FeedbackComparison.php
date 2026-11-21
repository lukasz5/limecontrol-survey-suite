<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class FeedbackComparison
{
    /** Derive both survey IDs from one store's configuration before loading either data source. */
    public static function scope(array $stores, int $sid, callable $access): ?array
    {
        if ($sid < 1) {
            return null;
        }
        $pair = null;
        foreach ($stores as $store => $config) {
            $product = $config['campaigns']['product']['survey'] ?? null;
            $returns = $config['campaigns']['returns']['survey'] ?? null;
            if ($product !== $sid && $returns !== $sid) {
                continue;
            }
            if ($pair !== null || !is_int($product) || !is_int($returns) || min($product, $returns) < 1 || $product === $returns) {
                return null;
            }
            $pair = ['store' => (string) $store, 'product' => $product, 'returns' => $returns];
        }
        if (!$pair) {
            return null;
        }
        foreach ([$pair['product'], $pair['returns']] as $survey) {
            $rights = $access($survey);
            if (($rights['read'] ?? false) !== true || ($rights['active'] ?? '') !== 'Y' || ($rights['anonymized'] ?? '') !== 'N') {
                return null;
            }
        }
        return $pair;
    }

    /** Independently summarize response counts. No matching by person, order or response ID. */
    public static function compare(iterable $productFacts, iterable $returnFacts): array
    {
        $summary = self::bucket();
        $groups = [];
        $unassigned = self::bucket();
        foreach ($productFacts as $fact) {
            foreach ($fact['products'] ?? [] as $item) {
                $score = $item['score'] ?? null;
                if (!is_int($score) || $score < 1 || $score > 5) {
                    continue;
                }
                self::count($summary, $score, null);
                self::group($groups, $item, $score, null);
            }
        }
        foreach ($returnFacts as $fact) {
            $reason = $fact['returnReason'] ?? '';
            if (!is_string($reason) || !preg_match('/^[A-Za-z0-9_-]{1,40}$/D', $reason)) {
                continue;
            }
            self::count($summary, null, $reason);
            $items = $fact['products'] ?? [];
            // One native RETURNREASON applies to the whole return. A sample of a multi-line return is ambiguous.
            if (count($items) !== 1 || ($fact['totalItems'] ?? count($items)) !== 1
                || !self::group($groups, $items[0], null, $reason)) {
                self::count($unassigned, null, $reason);
            }
        }
        ksort($groups, SORT_STRING);
        return ['summary' => self::finish($summary), 'groups' => array_values(array_map([self::class,'finish'], $groups)),
            'unassigned' => self::finish($unassigned)];
    }

    private static function group(array &$groups, array $item, ?int $score, ?string $reason): bool
    {
        $product = (string) ($item['productId'] ?? '');
        $variant = (string) ($item['variantId'] ?? '');
        $key = (string) ($item['key'] ?? '');
        if ($product === '' && $key === '') {
            return false;
        }
        // Legacy facts retain the computed product key even if no SKU was supplied.
        $fallback = $product . ($variant === '' ? '' : '/' . $variant);
        $sku = $key !== $fallback ? $key : '';
        $levels = $product === '' ? [['sku','', '', $key]] : [['product', $product, '', '']];
        if ($product !== '' && $variant !== '') {
            $levels[] = ['variant', $product, $variant, ''];
        }
        foreach ($levels as [$level, $id, $vid, $skuKey]) {
            $index = json_encode([$id, $level, $vid, $skuKey], JSON_THROW_ON_ERROR);
            if (!isset($groups[$index])) {
                $groups[$index] = self::bucket() + ['level'=>$level, 'product'=>$id, 'variant'=>$vid, 'skus'=>[]];
            }
            if ($sku !== '' && ($level !== 'product' || $variant === '')) {
                $groups[$index]['skus'][$sku] = true;
            }
            self::count($groups[$index], $score, $reason);
        }
        return true;
    }

    private static function bucket(): array
    {
        return ['scores' => 0, 'sum' => 0, 'returns' => 0, 'reasons' => []];
    }

    private static function count(array &$bucket, ?int $score, ?string $reason): void
    {
        if ($score !== null) {
            ++$bucket['scores'];
            $bucket['sum'] += $score;
        }
        if ($reason !== null) {
            ++$bucket['returns'];
            $bucket['reasons'][$reason] = ($bucket['reasons'][$reason] ?? 0) + 1;
        }
    }

    private static function finish(array $bucket): array
    {
        $bucket['mean'] = $bucket['scores'] ? round($bucket['sum'] / $bucket['scores'], 2) : null;
        unset($bucket['sum']);
        ksort($bucket['reasons'], SORT_STRING);
        if (isset($bucket['skus'])) {
            $bucket['skus'] = array_map('strval', array_keys($bucket['skus']));
            sort($bucket['skus'], SORT_STRING);
        }
        return $bucket;
    }
}
