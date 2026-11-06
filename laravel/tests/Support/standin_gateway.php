<?php

/**
 * The stand-in online gateway's server, for the e2e copy only (StandInGateway is its driver):
 * `php -S 127.0.0.1:8814 tests/Support/standin_gateway.php`, with
 * - STANDIN_KEYS: the accepted keys, `id:secret,id:secret` (an id starting `sk_live_` is live, else test);
 * - STANDIN_WEBHOOK_SECRET: what webhooks are signed with;
 * - STANDIN_STATE: the JSON file it keeps its orders in.
 *
 * API (Basic auth with a key): GET /v1/account; POST /v1/orders; GET /v1/orders/{id}; POST /v1/orders/{id}/refunds
 * (an Idempotency-Key header refunds once); POST /v1/orders/{id}/dispute {status: open|won|lost} (a test helper that
 * sends the dispute's webhook). The payer's page: GET /pay/{id} shows the order with Pay and Decline; POST /pay/{id}
 * settles it, then 303s to its return_url. Like a real gateway, it sends each signed webhook to the order's notify_url
 * a moment later from a process of its own (`php standin_gateway.php deliver <job>`), never while a caller waits on
 * it. It holds no real money.
 */

// A webhook delivery, run in the background by sendWebhook(): wait a moment, post, forget.
if (PHP_SAPI === 'cli' && ($argv[1] ?? null) === 'deliver') {
    $job = json_decode((string) file_get_contents($argv[2]), true);
    unlink($argv[2]);
    sleep(1);
    @file_get_contents($job['url'], false, stream_context_create(['http' => [
        'method' => 'POST', 'header' => "Content-Type: application/json\r\nX-Standin-Signature: {$job['signature']}\r\n", 'content' => $job['body'],
        'ignore_errors' => true, 'timeout' => 10,
    ]]));

    return;
}

$keys = [];
foreach (array_filter(explode(',', (string) getenv('STANDIN_KEYS'))) as $pair) {
    [$id, $secret] = explode(':', $pair, 2);
    $keys[$id] = $secret;
}
$webhookSecret = (string) getenv('STANDIN_WEBHOOK_SECRET');
$stateFile = getenv('STANDIN_STATE') ?: sys_get_temp_dir().'/standin_gateway.json';

$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

function answer(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($body);
}

/** Run `$change` on the saved orders with the file locked; answers what it answers. */
function withState(string $file, Closure $change): mixed
{
    $h = fopen($file, 'c+');
    flock($h, LOCK_EX);
    $state = json_decode(stream_get_contents($h) ?: '{"orders":{}}', true);
    $result = $change($state);
    ftruncate($h, 0);
    rewind($h);
    fwrite($h, json_encode($state));
    flock($h, LOCK_UN);
    fclose($h);

    return $result;
}

function sendWebhook(array $order, string $type, array $extra, string $secret): void
{
    $body = json_encode(['id' => 'evt_'.bin2hex(random_bytes(8)), 'type' => $type,
        'data' => ['order' => array_intersect_key($order, array_flip(['id', 'reference', 'amount', 'currency', 'status']))] + $extra]);
    $at = time();
    $job = tempnam(sys_get_temp_dir(), 'standin-webhook-');
    file_put_contents($job, json_encode(['url' => $order['notify_url'], 'body' => $body,
        'signature' => "t={$at},v1=".hash_hmac('sha256', "{$at}.{$body}", $secret)]));
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__FILE__).' deliver '.escapeshellarg($job).' > /dev/null 2>&1 &');
}

function authed(array $keys): ?string
{
    $id = $_SERVER['PHP_AUTH_USER'] ?? null;

    return $id !== null && isset($keys[$id]) && hash_equals($keys[$id], (string) ($_SERVER['PHP_AUTH_PW'] ?? '')) ? $id : null;
}

// The payer's page.
if (preg_match('#^/pay/(ord_[0-9a-f]+)$#', $path, $m)) {
    $order = withState($stateFile, fn (array &$s) => $s['orders'][$m[1]] ?? null);
    if ($order === null) {
        http_response_code(404);
        echo 'No such order';

        return true;
    }
    if ($method === 'POST') {
        $decision = ($_POST['decision'] ?? '') === 'pay' ? 'paid' : 'failed';
        $order = withState($stateFile, function (array &$s) use ($m, $decision) {
            if ($s['orders'][$m[1]]['status'] === 'created') {
                $s['orders'][$m[1]]['status'] = $decision;
            }

            return $s['orders'][$m[1]];
        });
        sendWebhook($order, $order['status'] === 'paid' ? 'payment.paid' : 'payment.failed', [], $webhookSecret);
        header('Location: '.$order['return_url'], true, 303);

        return true;
    }
    $amount = number_format($order['amount'] / 100, 2, '.', '');
    $mode = str_starts_with($order['key'], 'sk_live_') ? 'live' : 'test';
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><title>Stand-in gateway</title>'
        .'<body style="font-family:sans-serif;max-width:28rem;margin:2rem auto;padding:0 1rem">'
        .'<h1>Stand-in gateway ('.htmlspecialchars($mode).')</h1>'
        .'<p>Pay <strong>'.htmlspecialchars($amount.' '.$order['currency']).'</strong>. Status: '.htmlspecialchars($order['status']).'</p>'
        .'<form method="post"><button name="decision" value="pay">Pay</button> <button name="decision" value="decline">Decline</button></form>';

    return true;
}

$key = authed($keys);
if (str_starts_with($path, '/v1/') && $key === null) {
    answer(401, ['error' => 'Authentication failed']);

    return true;
}
$input = json_decode((string) file_get_contents('php://input'), true) ?? [];

if ($method === 'GET' && $path === '/v1/account') {
    answer(200, ['id' => 'acct_standin', 'mode' => str_starts_with($key, 'sk_live_') ? 'live' : 'test']);

    return true;
}
if ($method === 'POST' && $path === '/v1/orders') {
    $id = 'ord_'.bin2hex(random_bytes(8));
    $host = $_SERVER['HTTP_HOST'];
    $order = ['id' => $id, 'key' => $key, 'amount' => (int) $input['amount'], 'currency' => $input['currency'], 'reference' => $input['reference'],
        'status' => 'created', 'return_url' => $input['return_url'], 'notify_url' => $input['notify_url'], 'refunds' => []];
    withState($stateFile, function (array &$s) use ($order) {
        $s['orders'][$order['id']] = $order;
    });
    answer(201, ['id' => $id, 'status' => 'created', 'checkout_url' => "http://{$host}/pay/{$id}"]);

    return true;
}
if (preg_match('#^/v1/orders/(ord_[0-9a-f]+)(/refunds|/dispute)?$#', $path, $m)) {
    $order = withState($stateFile, fn (array &$s) => $s['orders'][$m[1]] ?? null);
    if ($order === null || $order['key'] !== $key) {
        answer(404, ['error' => 'No such order']);

        return true;
    }
    if ($method === 'GET' && ($m[2] ?? '') === '') {
        answer(200, array_intersect_key($order, array_flip(['id', 'reference', 'amount', 'currency', 'status'])));

        return true;
    }
    if ($method === 'POST' && $m[2] === '/refunds') {
        $idem = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? bin2hex(random_bytes(8));
        $refund = withState($stateFile, function (array &$s) use ($m, $idem, $input) {
            $o = &$s['orders'][$m[1]];
            if ($o['status'] !== 'paid') {
                return ['error' => 'Order is not paid'];
            }
            if (! isset($o['refunds'][$idem])) {
                $left = $o['amount'] - array_sum(array_column($o['refunds'], 'amount'));
                if ((int) $input['amount'] > $left) {
                    return ['error' => "Refund exceeds the {$left} left"];
                }
                $o['refunds'][$idem] = ['id' => 'rfnd_'.bin2hex(random_bytes(8)), 'amount' => (int) $input['amount'], 'status' => 'processed'];
            }

            return $o['refunds'][$idem];
        });
        if (isset($refund['error'])) {
            answer(400, $refund);

            return true;
        }
        sendWebhook($order, 'refund.processed', ['refund' => $refund], $webhookSecret);
        answer(201, $refund);

        return true;
    }
    if ($method === 'POST' && $m[2] === '/dispute') {
        $dispute = ['id' => $input['id'] ?? 'dp_'.substr($order['id'], 4), 'amount' => (int) ($input['amount'] ?? $order['amount']), 'status' => $input['status'] ?? 'open'];
        sendWebhook($order, $dispute['status'] === 'open' ? 'dispute.opened' : 'dispute.closed', ['dispute' => $dispute], $webhookSecret);
        answer(201, $dispute);

        return true;
    }
}
answer(404, ['error' => 'Not found']);

return true;
