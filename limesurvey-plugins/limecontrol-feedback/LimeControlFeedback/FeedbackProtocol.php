<?php
// SPDX-License-Identifier: GPL-2.0-or-later

final class FeedbackProtocol
{
    public const KINDS = ['experience', 'delivery', 'product', 'returns'];
    public const MAX_BYTES = 131072;

    public static function authenticated($body, $timestamp, $signature, $secret, $now)
    {
        return is_string($secret) && strlen($secret) >= 32 && strlen($body) <= self::MAX_BYTES
            && is_string($timestamp) && preg_match('/^[0-9]{10}$/D', $timestamp)
            && abs($now - (int)$timestamp) <= 300 && is_string($signature)
            && hash_equals(hash_hmac('sha256', $timestamp . '.' . $body, $secret), $signature);
    }

    private static function stringField($value, $max, $empty = false)
    {
        if (!is_string($value) || (!$empty && trim($value) === '') || strlen($value) > $max || preg_match('/[\x00-\x1F\x7F]/', $value)) {
            throw new InvalidArgumentException('Invalid text field');
        }
        return $value;
    }

    private static function items($items)
    {
        if (!is_array($items) || array_values($items) !== $items || count($items) > 250) {
            throw new InvalidArgumentException('Invalid items');
        }
        $out = [];
        $ids = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_int($item['quantity'] ?? null) || $item['quantity'] < 1) {
                throw new InvalidArgumentException('Invalid line quantity');
            }
            $line = self::stringField($item['line_id'] ?? null, 100);
            if (isset($ids[$line])) {
                throw new InvalidArgumentException('Duplicate order line');
            }
            $ids[$line] = true;
            $row = ['line_id' => $line, 'quantity' => $item['quantity'], 'label' => self::stringField($item['label'] ?? null, 500)];
            foreach (['sku', 'product_id', 'variant_id', 'category'] as $field) {
                $row[$field] = self::stringField($item[$field] ?? '', 255, true);
            }
            if ($row['sku'] === '' && $row['product_id'] === '') {
                throw new InvalidArgumentException('Missing product identifier');
            }
            $out[] = $row;
        }
        return $out;
    }

    public static function snapshot($body)
    {
        if (strlen($body) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Oversized snapshot');
        }
        $input = json_decode($body, true, 12);
        if (!is_array($input) || ($input['version'] ?? null) !== 1 || !is_int($input['revision'] ?? null)
            || $input['revision'] < 1 || !is_int($input['occurred_at'] ?? null) || $input['occurred_at'] < 1
            || !in_array($input['status'] ?? '', ['eligible','pending','cancelled','refunded'], true)) {
            throw new InvalidArgumentException('Invalid snapshot');
        }
        $email = trim(self::stringField($input['email'] ?? '', 254, true));
        if (($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) || ($input['status'] === 'eligible' && $email === '')) {
            throw new InvalidArgumentException('Invalid recipient');
        }
        $out = ['event_id' => self::stringField($input['event_id'] ?? null, 180), 'order_id' => self::stringField($input['order_id'] ?? null, 120),
            'revision' => $input['revision'], 'occurred_at' => $input['occurred_at'], 'status' => $input['status'], 'email' => $email,
            'items' => self::items($input['items'] ?? null), 'returned_items' => self::items($input['returned_items'] ?? []),
            'customer_ref' => self::stringField($input['customer_ref'] ?? '', 120, true)];
        foreach (['delivered_at','return_closed_at'] as $key) {
            $time = $input[$key] ?? 0;
            if (!is_int($time) || $time < 0) {
                throw new InvalidArgumentException('Invalid event time');
            }
            $out[$key] = $time;
        }
        return $out;
    }

    public static function anchor($kind, array $data, $firstEligible)
    {
        if (!$data['email']) {
            return 0;
        }
        if ($kind === 'returns') {
            return $data['returned_items'] && in_array($data['status'], ['eligible','refunded'], true)
                ? ($data['return_closed_at'] ?: $data['occurred_at']) : 0;
        }
        if ($data['status'] !== 'eligible' || !$data['items']) {
            return 0;
        }
        if ($kind === 'delivery') {
            return $data['delivered_at'];
        }
        if ($kind === 'product') {
            return $data['delivered_at'] ?: $firstEligible;
        }
        return $firstEligible;
    }

    public static function productKey(array $row)
    {
        return $row['sku'] ?: ($row['product_id'] . ($row['variant_id'] === '' ? '' : '/' . $row['variant_id']));
    }

    public static function score($value)
    {
        return (is_string($value) || is_int($value)) && preg_match('/^[1-5]$/D', (string)$value) ? (int)$value : null;
    }

    public static function preflight($url, $store, $secret, $orderId)
    {
        if (!is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || !parse_url($url, PHP_URL_HOST)
            || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_FRAGMENT) !== null) {
            throw new RuntimeException('Invalid store preflight URL');
        }
        $body = json_encode(['version'=>1, 'order_id'=>$orderId], JSON_THROW_ON_ERROR);
        $timestamp = (string)time();
        $headers = [];
        $response = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_POST=>true, CURLOPT_POSTFIELDS=>$body, CURLOPT_FOLLOWLOCATION=>false,
            CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_CONNECTTIMEOUT=>4, CURLOPT_TIMEOUT=>10,
            CURLOPT_HTTPHEADER=>['Content-Type: application/json','Accept: application/json','X-LC-Store: '.$store,
                'X-LC-Timestamp: '.$timestamp, 'X-LC-Signature: '.hash_hmac('sha256',$timestamp.'.'.$body,$secret)],
            CURLOPT_WRITEFUNCTION=>static function ($curl,$part) use (&$response) {
                if (strlen($response) + strlen($part) > self::MAX_BYTES) { return 0; }
                $response .= $part;
                return strlen($part);
            }, CURLOPT_HEADERFUNCTION=>static function ($curl,$line) use (&$headers) {
                $parts = explode(':',$line,2);
                if (count($parts) === 2) { $headers[strtolower(trim($parts[0]))] = trim($parts[1]); }
                return strlen($line);
            }]);
        $ok = curl_exec($curl);
        $status = curl_getinfo($curl,CURLINFO_RESPONSE_CODE);
        if (!$ok || $status !== 200 || !self::authenticated($response,$headers['x-lc-timestamp'] ?? '',$headers['x-lc-signature'] ?? '',$secret,time())) {
            throw new RuntimeException('Store recheck unavailable (HTTP '.$status.', transport '.curl_errno($curl).')');
        }
        $snapshot = self::snapshot($response);
        if ($snapshot['order_id'] !== $orderId) { throw new RuntimeException('Store returned a different order'); }
        return $snapshot;
    }
}
