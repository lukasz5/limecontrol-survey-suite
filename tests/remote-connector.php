<?php
declare(strict_types=1);

use LimeControl\Commerce\OrderState;
use LimeControl\Commerce\Protocol;
use LimeControl\Commerce\RemoteConnector;

require_once __DIR__ . '/../commerce-connectors/hosted-runtime/RemoteConnector.php';
require_once __DIR__ . '/../limesurvey-plugins/limecontrol-feedback/LimeControlFeedback/FeedbackProtocol.php';
require_once __DIR__ . '/../limesurvey-plugins/limecontrol-feedback/LimeControlFeedback/FeedbackStorage.php';

function verify(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
}

$dir = sys_get_temp_dir() . '/lc-hosted-' . bin2hex(random_bytes(8));
mkdir($dir, 0700);
try {
    $config = ['url' => 'https://receiver.invalid/ingest', 'store' => 'sample', 'source' => 'shop.invalid',
        'secret' => bin2hex(random_bytes(32))];
    $path = "$dir/shop.sqlite";
    $connector = new RemoteConnector($path, $config);
    $second = new RemoteConnector($path, $config);
    $connector->setCursor('last-id', '37');
    verify($second->cursor('last-id') === '37', 'durable checkpoint');
    verify($connector->runExclusive(fn() => $second->runExclusive(fn() => 19)) === 0, 'single worker');
    verify($second->runExclusive(fn() => 19) === 19, 'lock released');
    foreach (['store', 'source'] as $field) {
        try {
            new RemoteConnector($path, array_replace($config, [$field => 'another']));
            throw new LogicException('Cross-store database accepted');
        } catch (RuntimeException $e) {
            verify(str_contains($e->getMessage(), 'another store'), 'store identity');
        }
    }

    try {
        $connector->setCursorAndMark('review', 'decision', 0);
        throw new LogicException('Invalid order accepted');
    } catch (RuntimeException $e) {
        verify($second->cursor('review') === null, 'failed marker rolls back decision');
    }

    $db = new PDO('sqlite::memory:');
    $feedback = new FeedbackStorage($db, 'fb_', str_repeat('x', 32));
    $feedback->install();
    $campaigns = ['experience' => ['survey' => 1, 'delayHours' => 0, 'frequencyHours' => 0]];
    $events = [];
    $deliver = static function ($url, $headers, $body) use ($config, $feedback, $campaigns, &$events): array {
        verify($url === $config['url'] && $headers['X-LC-Store'] === $config['store'], 'recipient');
        verify(FeedbackProtocol::authenticated($body, $headers['X-LC-Timestamp'], $headers['X-LC-Signature'], $config['secret'], time()), 'receiver authenticates payload');
        $events[] = $event = FeedbackProtocol::snapshot($body);
        $ack = $feedback->accept($config['store'], $event, $campaigns, time());
        return [200, json_encode(['status' => $ack])];
    };
    $eligible = OrderState::result('client@example.invalid', 0, [OrderState::item(3, 5, 7, 'W-23', 'Test variant', 2)], [], 0, time());
    $cancelled = OrderState::closed('cancelled');
    $state = $eligible;
    $load = static function (int $id) use (&$state): array { return $state; };
    $failedRead = static function (): array { throw new RuntimeException('Unavailable upstream'); };
    $request = static function (string $id, callable $reader) use ($connector, $config) {
        $body = Protocol::encode(['version' => 1, 'order_id' => $id]);
        return $connector->preflight(Protocol::headers($config, $body, time()), $body, $reader);
    };
    verify($request('11', $failedRead)[0] === 404, 'unknown order has no upstream lookup');
    $connector->mark(11);
    verify($connector->dispatch($load, $deliver) === 0 && count($events) === 1, 'accepted by native storage');
    verify($connector->dispatch($load, $deliver) === 0 && count($events) === 1, 'ack clears queue');
    $connector->mark(11);
    verify($connector->dispatch($load, $deliver) === 0 && $events[0] === $events[1], 'unchanged order has identical event');
    $jobs = $feedback->due(time()+1);
    verify(count($jobs) === 1, 'one invitation queued');

    $body = Protocol::encode(['version' => 1, 'order_id' => '11']);
    $auth = Protocol::headers($config, $body, time());
    foreach ([array_replace($auth, ['X-LC-Store' => 'other']), Protocol::headers($config, $body, time()-301),
        array_replace($auth, ['X-LC-Signature' => 'invalid'])] as $invalid) {
        verify($connector->preflight($invalid, $body, $failedRead)[0] === 401, 'signed request validation before source read');
    }
    verify($request('011', $load)[0] === 400, 'canonical order ID');
    verify($request('11', $failedRead)[0] === 503, 'preflight source failure holds invitation');
    $state = $cancelled;
    [$code, $headers, $raw] = $request('11', $load);
    verify($code === 200 && FeedbackProtocol::authenticated($raw, $headers['X-LC-Timestamp'], $headers['X-LC-Signature'], $config['secret'], time()), 'signed readback');
    $current = FeedbackProtocol::snapshot($raw);
    verify($current['status'] === 'cancelled' && $current['revision'] === 2, 'missed cancellation discovered by preflight');
    $feedback->accept('sample', $current, $campaigns, time());
    verify($feedback->claim($jobs[0]['id'], $current, $campaigns['experience'], time()+1, $failedRead) === null, 'cancelled invitation is not sent');
    verify($connector->dispatch($load, $deliver) === 0 && end($events) === $current, 'preflight change is queued for receiver');

    $connector->mark(11);
    $connector->dispatch($load, static function (...$args) use ($deliver, $second) {
        $second->mark(11);
        return $deliver(...$args);
    });
    $before = count($events);
    verify($connector->dispatch($load, $deliver) === 0 && count($events) === $before+1, 'in-flight mark survives ACK');

    $calls = 0;
    [$code, , $raw] = $request('11', static function () use ($second, &$calls, $eligible, $cancelled) {
        if (++$calls === 1) {
            $second->mark(11);
            return $eligible;
        }
        return $cancelled;
    });
    verify($code === 200 && $calls === 2 && FeedbackProtocol::snapshot($raw) === $current, 'conflicting read never restores stale state');

    $attempts = 0;
    $noAck = static function () use (&$attempts) { ++$attempts; return [200, '{}']; };
    verify($connector->dispatch($load, $noAck) === 1 && $attempts === 1, 'missing confirmation retried later');
    verify($connector->dispatch($load, $noAck) === 0 && $attempts === 1, 'retry backoff');
    $connector->mark(11);
    verify($connector->dispatch($failedRead, $deliver) === 1, 'source failure reported');
    $second->mark(11);
    verify($second->dispatch($load, $deliver) === 0, 'store resumes after restart and source recovery');
    $connector->mark(12);
    verify($request('12', fn() => $eligible)[0] === 200, 'baseline for concurrent preflight');
    $reads = 0;
    [$code, , $raw] = $request('12', static function () use ($second, $config, $eligible, $cancelled, &$reads) {
        if (++$reads === 1) {
            $body = Protocol::encode(['version' => 1, 'order_id' => '12']);
            $other = $second->preflight(Protocol::headers($config, $body, time()), $body, fn() => $cancelled);
            verify($other[0] === 200, 'newer reader recorded cancellation');
            return $eligible;
        }
        return $cancelled;
    });
    $current = FeedbackProtocol::snapshot($raw);
    verify($code === 200 && $reads === 2 && $current['status'] === 'cancelled' && $current['revision'] === 2,
        'a slower read cannot reinstate an invitation');
    foreach (glob($path . '*') as $file) {
        $contents = file_get_contents($file);
        foreach (['client@example.invalid', 'Test variant', $config['secret']] as $private) {
            verify(strpos($contents, $private) === false, 'order contents and signing secret not in queue');
        }
    }
    echo "Hosted queue, signatures, concurrent changes and LimeSurvey cancellation passed.\n";
} finally {
    unset($connector, $second);
    foreach (glob($dir . '/*') as $file) {
        unlink($file);
    }
    rmdir($dir);
}
