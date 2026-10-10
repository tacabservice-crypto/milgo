<?php
// Real payments. Card, Apple Pay and Google Pay go through Stripe Checkout (Stripe's hosted, PCI-compliant
// page); PayPal through PayPal Checkout. Secret keys come from the server config only.
// An order becomes "Paid" only when Stripe's signed webhook or PayPal's capture confirms the money.

declare(strict_types=1);

function gateway_ready(string $gateway): bool
{
    $c = config();
    if ($gateway === 'Stripe') return !empty($c['stripe_secret_key']);
    if ($gateway === 'PayPal') return !empty($c['paypal_client_id']) && !empty($c['paypal_secret']);
    return false;
}

function http_call(string $method, string $url, array $headers, $body = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) fail(502, 'Payment provider unreachable: ' . $err);
    return [$status, json_decode($raw, true) ?? []];
}

// ---------------- Stripe ----------------

function stripe_checkout(array $order): string
{
    $site = site_url();
    $params = [
        'mode' => 'payment',
        'client_reference_id' => $order['id'],
        'metadata[order_id]' => $order['id'],
        'success_url' => "$site/#/track/{$order['id']}",
        'cancel_url' => "$site/#/",
        'line_items[0][quantity]' => 1,
        'line_items[0][price_data][currency]' => 'gbp',
        'line_items[0][price_data][unit_amount]' => (int) round($order['grandTotal'] * 100),
        'line_items[0][price_data][product_data][name]' => 'DK Perfume order ' . $order['id'],
    ];
    if (!empty($order['customerEmail'])) $params['customer_email'] = $order['customerEmail'];
    [$status, $res] = http_call('POST', 'https://api.stripe.com/v1/checkout/sessions', [
        'Authorization: Bearer ' . config()['stripe_secret_key'],
        'Content-Type: application/x-www-form-urlencoded',
    ], http_build_query($params));
    if ($status >= 300 || empty($res['url'])) fail(502, 'Could not start card payment: ' . ($res['error']['message'] ?? 'unknown error'));
    return $res['url'];
}

function stripe_webhook(): void
{
    $payload = file_get_contents('php://input') ?: '';
    $secret = config()['stripe_webhook_secret'] ?? '';
    $sig = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
    if ($secret === '' || $sig === '') fail(400, 'Missing signature.');
    $parts = [];
    foreach (explode(',', $sig) as $kv) {
        [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
        $parts[$k][] = $v;
    }
    $t = (int) ($parts['t'][0] ?? 0);
    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    $ok = false;
    foreach ($parts['v1'] ?? [] as $v1) if (hash_equals($expected, $v1)) $ok = true;
    if (!$ok || abs(time() - $t) > 300) fail(400, 'Invalid signature.');

    $event = json_decode($payload, true);
    if (($event['type'] ?? '') === 'checkout.session.completed') {
        $s = $event['data']['object'];
        $id = $s['metadata']['order_id'] ?? $s['client_reference_id'] ?? '';
        if (($s['payment_status'] ?? '') === 'paid' && $id) {
            in_transaction(function () use ($id, $s) {
                $o = order_get($id);
                if (!$o || $o['paymentStatus'] === 'Paid') return;
                if ((int) round($o['grandTotal'] * 100) !== (int) $s['amount_total']) return; // amount mismatch: leave for staff
                $o['paymentStatus'] = 'Paid';
                $o['paymentReference'] = $s['payment_intent'] ?? $s['id'];
                $o['paymentGatewayDetails'] = ['gateway' => 'Stripe', 'transactionId' => $o['paymentReference'], 'payerEmail' => $s['customer_details']['email'] ?? null, 'captureDate' => now_iso()];
                order_put($o);
                audit('Payment Received', 'Order', $id, sprintf('Stripe payment £%.2f', $o['grandTotal']));
            });
        }
    }
    send_json(['received' => true]);
}

// ---------------- PayPal ----------------

function paypal_base(): string
{
    return (config()['paypal_mode'] ?? 'live') === 'sandbox' ? 'https://api-m.sandbox.paypal.com' : 'https://api-m.paypal.com';
}

function paypal_token(): string
{
    $c = config();
    [$status, $res] = http_call('POST', paypal_base() . '/v1/oauth2/token', [
        'Authorization: Basic ' . base64_encode($c['paypal_client_id'] . ':' . $c['paypal_secret']),
        'Content-Type: application/x-www-form-urlencoded',
    ], 'grant_type=client_credentials');
    if ($status >= 300 || empty($res['access_token'])) fail(502, 'PayPal sign-in failed.');
    return $res['access_token'];
}

function paypal_checkout(array $order): string
{
    $site = site_url();
    $body = [
        'intent' => 'CAPTURE',
        'purchase_units' => [[
            'reference_id' => $order['id'],
            'custom_id' => $order['id'],
            'description' => 'DK Perfume order ' . $order['id'],
            'amount' => ['currency_code' => 'GBP', 'value' => number_format($order['grandTotal'], 2, '.', '')],
        ]],
        'application_context' => [
            'brand_name' => 'DK Perfume',
            'user_action' => 'PAY_NOW',
            'shipping_preference' => 'NO_SHIPPING',
            'return_url' => "$site/api/?a=paypal_return&order=" . rawurlencode($order['id']),
            'cancel_url' => "$site/#/",
        ],
    ];
    [$status, $res] = http_call('POST', paypal_base() . '/v2/checkout/orders', [
        'Authorization: Bearer ' . paypal_token(),
        'Content-Type: application/json',
    ], json_encode($body));
    if ($status >= 300) fail(502, 'Could not start PayPal payment.');
    foreach ($res['links'] ?? [] as $l) if (in_array($l['rel'], ['approve', 'payer-action'], true)) return $l['href'];
    fail(502, 'PayPal did not return an approval link.');
}

// PayPal sends the shopper back here after approval: capture the money, then show their order
function paypal_return(): never
{
    $id = (string) ($_GET['order'] ?? '');
    $token = (string) ($_GET['token'] ?? '');
    $site = site_url();
    if ($id === '' || $token === '') { header("Location: $site/#/"); exit; }
    [$status, $res] = http_call('POST', paypal_base() . '/v2/checkout/orders/' . rawurlencode($token) . '/capture', [
        'Authorization: Bearer ' . paypal_token(),
        'Content-Type: application/json',
    ], '{}');
    $unit = $res['purchase_units'][0] ?? [];
    $capture = $unit['payments']['captures'][0] ?? null;
    if ($status < 300 && ($res['status'] ?? '') === 'COMPLETED' && $capture && ($capture['custom_id'] ?? $unit['custom_id'] ?? $id) === $id) {
        in_transaction(function () use ($id, $capture, $res) {
            $o = order_get($id);
            if (!$o || $o['paymentStatus'] === 'Paid') return;
            if (number_format($o['grandTotal'], 2, '.', '') !== ($capture['amount']['value'] ?? '')) return;
            $o['paymentStatus'] = 'Paid';
            $o['paymentReference'] = $capture['id'];
            $o['paymentGatewayDetails'] = ['gateway' => 'PayPal', 'transactionId' => $capture['id'], 'payerEmail' => $res['payer']['email_address'] ?? null, 'captureDate' => now_iso()];
            order_put($o);
            audit('Payment Received', 'Order', $id, sprintf('PayPal payment £%.2f', $o['grandTotal']));
        });
    }
    header("Location: $site/#/track/" . rawurlencode($id));
    exit;
}
