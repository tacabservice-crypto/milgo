<?php
// Orders are priced, stock-checked and totalled on the server. The browser only says what was chosen;
// it cannot set prices, discounts, delivery fees or payment status.

declare(strict_types=1);

const GATEWAY_FOR = [
    'Credit / Debit Card' => 'Stripe',
    'Apple Pay' => 'Stripe',
    'Google Pay' => 'Stripe',
    'PayPal' => 'PayPal',
];
const ORDER_STATUSES = ['Pending', 'Confirmed', 'Processing', 'Ready for Dispatch', 'Shipped', 'Delivered', 'Cancelled', 'Return Requested', 'Returned', 'Refunded'];

function audit(string $action, string $entityType, string $entityId, string $details, ?array $staff = null): void
{
    $logs = kv_get('audit_logs', []);
    array_unshift($logs, [
        'id' => 'log-' . bin2hex(random_bytes(6)),
        'timestamp' => now_iso(),
        'userId' => $staff['id'] ?? 'storefront',
        'userName' => $staff['name'] ?? 'Online Store',
        'action' => $action,
        'entityType' => $entityType,
        'entityId' => $entityId,
        'details' => $details,
    ]);
    kv_set('audit_logs', array_slice($logs, 0, 500), $staff['name'] ?? 'storefront');
}

function add_movement(array &$movements, array $product, array $variant, int $change, string $type, string $ref, string $reason, string $by): void
{
    array_unshift($movements, [
        'id' => 'sm-' . bin2hex(random_bytes(6)),
        'productId' => $product['id'],
        'variantId' => $variant['id'],
        'productName' => $product['name'],
        'size' => $variant['size'],
        'quantityChange' => $change,
        'newBalance' => $variant['stockQuantity'],
        'type' => $type,
        'referenceId' => $ref,
        'reason' => $reason,
        'performedBy' => $by,
        'timestamp' => now_iso(),
    ]);
}

// Delivery zones that serve a country: exact country first, else the catch-all '*' zone
function zones_for_country(array $zones, string $country): array
{
    $active = array_values(array_filter($zones, fn($z) => !empty($z['active'])));
    $direct = array_values(array_filter($active, fn($z) => in_array($country, $z['countries'] ?? [], true)));
    return $direct ?: array_values(array_filter($active, fn($z) => in_array('*', $z['countries'] ?? [], true)));
}

function method_allowed(array $payments, string $method, string $country): bool
{
    switch ($method) {
        case 'Credit / Debit Card': return !empty($payments['cards']['enabled']);
        case 'PayPal': return !empty($payments['paypal']['enabled']);
        case 'Apple Pay': return !empty($payments['applePay']);
        case 'Google Pay': return !empty($payments['googlePay']);
        case 'Bank Transfer': return !empty($payments['bankTransfer']['enabled']);
        case 'Cash on Delivery':
            return !empty($payments['cashOnDelivery']['enabled']) && in_array($country, $payments['cashOnDelivery']['countries'] ?? [], true);
        default: // mobile wallets
            if (empty($payments['mobileMoney']['enabled']) || !in_array($country, $payments['mobileMoney']['countries'] ?? [], true)) return false;
            foreach ($payments['mobileMoney']['wallets'] ?? [] as $w) if ($w['name'] === $method && !empty($w['enabled'])) return true;
            return false;
    }
}

function coupon_discount(array $coupons, string $code, float $subtotal): array
{
    $code = strtoupper(trim($code));
    foreach ($coupons as $c) {
        if (strtoupper($c['code']) !== $code || empty($c['active'])) continue;
        if (!empty($c['expiryDate']) && strtotime($c['expiryDate'] . ' 23:59:59') < time()) break;
        if (!empty($c['maxUsage']) && ($c['timesUsed'] ?? 0) >= $c['maxUsage']) break;
        if ($subtotal < (float) $c['minOrderAmount']) {
            return ['valid' => false, 'discount' => 0, 'message' => sprintf('Order must be at least £%s to use %s.', $c['minOrderAmount'], $c['code'])];
        }
        $d = $c['discountType'] === 'percentage' ? $subtotal * $c['discountValue'] / 100 : (float) $c['discountValue'];
        return ['valid' => true, 'discount' => round(min($d, $subtotal), 2), 'message' => 'Coupon applied.', 'code' => $c['code']];
    }
    return ['valid' => false, 'discount' => 0, 'message' => 'Invalid or expired coupon code.'];
}

function create_order(array $in): array
{
    $text = fn($k, $max = 200) => mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
    $name = $text('customerName', 120);
    $phone = $text('customerPhone', 40);
    $email = $text('customerEmail', 160);
    $country = strtoupper($text('country', 2));
    $method = $text('paymentMethod', 40);
    $address = $text('deliveryAddress', 300);
    if ($name === '' || strlen(preg_replace('/\D/', '', $phone)) < 7) fail(422, 'Please enter your name and a valid phone number.');
    if ($address === '') fail(422, 'Please enter your delivery address.');
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) fail(422, 'Please enter a valid email address.');
    $items = $in['items'] ?? [];
    if (!is_array($items) || !$items || count($items) > 50) fail(422, 'Your bag is empty.');

    return in_transaction(function () use ($in, $text, $name, $phone, $email, $country, $method, $address, $items) {
        $settings = kv_get('settings', []);
        $payments = $settings['payments'] ?? [];
        $products = kv_get('products', []);
        $zones = kv_get('delivery_zones', []);
        $coupons = kv_get('coupons', []);
        $movements = kv_get('movements', []);

        $markets = $settings['markets'] ?? ['sellWorldwide' => true];
        if (empty($markets['sellWorldwide']) && !in_array($country, $markets['allowedCountries'] ?? [], true)) fail(422, 'We do not ship to this country yet.');
        $zone = null;
        foreach (zones_for_country($zones, $country) as $z) if ($z['id'] === ($in['zoneId'] ?? '')) $zone = $z;
        if (!$zone) fail(422, 'Please choose a delivery option for your country.');
        if (!method_allowed($payments, $method, $country)) fail(422, 'This payment method is not available for your country.');
        if (isset(GATEWAY_FOR[$method]) && GATEWAY_FOR[$method] === 'Stripe' || $method === 'PayPal') {
            if (!gateway_ready(GATEWAY_FOR[$method]) && ($payments['mode'] ?? 'test') !== 'test') fail(503, 'Online payment is not set up yet. Please choose another method.');
        }

        // Price every line from the catalogue and take the stock
        $orderItems = [];
        $subtotal = 0.0;
        $pIndex = [];
        foreach ($products as $i => $p) $pIndex[$p['id']] = $i;
        foreach ($items as $it) {
            $pi = $pIndex[$it['productId'] ?? ''] ?? null;
            $qty = (int) ($it['quantity'] ?? 0);
            if ($pi === null || $qty < 1 || empty($products[$pi]['active'])) fail(422, 'A product in your bag is no longer available.');
            $p = &$products[$pi];
            $vi = null;
            foreach ($p['variants'] as $k => $v) if ($v['id'] === ($it['variantId'] ?? '')) $vi = $k;
            if ($vi === null) fail(422, 'A size in your bag is no longer available.');
            $v = &$p['variants'][$vi];
            if ($v['stockQuantity'] < $qty) fail(409, sprintf('Only %d left of %s (%s).', $v['stockQuantity'], $p['name'], $v['size']));
            $unit = (float) (!empty($v['discountPrice']) ? $v['discountPrice'] : $v['sellingPrice']);
            $line = round($unit * $qty, 2);
            $subtotal += $line;
            $orderItems[] = [
                'productId' => $p['id'], 'variantId' => $v['id'], 'productName' => $p['name'], 'brand' => $p['brand'],
                'size' => $v['size'], 'image' => $p['images'][0] ?? '', 'unitPrice' => $unit, 'costPrice' => (float) $v['costPrice'],
                'quantity' => $qty, 'subtotal' => $line,
            ];
            $v['stockQuantity'] -= $qty;
            unset($v, $p);
        }
        $subtotal = round($subtotal, 2);

        $discount = 0.0;
        $couponCode = null;
        if (!empty($in['couponCode'])) {
            $c = coupon_discount($coupons, (string) $in['couponCode'], $subtotal);
            if ($c['valid']) {
                $discount = $c['discount'];
                $couponCode = $c['code'];
                foreach ($coupons as &$cp) if ($cp['code'] === $couponCode) $cp['timesUsed'] = ($cp['timesUsed'] ?? 0) + 1;
                unset($cp);
            }
        }
        $freeFrom = $zone['freeShippingFrom'] ?? null;
        $deliveryFee = ($freeFrom !== null && $subtotal >= (float) $freeFrom) ? 0.0 : (float) $zone['deliveryFee'];
        $grand = round(max(0, $subtotal - $discount + $deliveryFee), 2);

        $id = sprintf('DK-%s-%d', gmdate('Y'), next_counter('order', 1050));
        $gateway = GATEWAY_FOR[$method] ?? null;
        $simulated = $gateway && !gateway_ready($gateway); // test mode without keys
        $isCityZone = ($zone['regionType'] ?? '') === 'domestic';
        $order = [
            'id' => $id,
            'customerName' => $name,
            'customerPhone' => $phone,
            'customerEmail' => $email ?: null,
            'country' => $text('countryName', 80) ?: $country,
            'countryCode' => $country,
            'city' => $isCityZone ? $zone['city'] : $text('city', 80),
            'postalCode' => $text('postalCode', 20) ?: null,
            'deliveryArea' => $zone['zoneName'],
            'deliveryAddress' => $address,
            'deliveryInstructions' => $text('deliveryInstructions', 300) ?: null,
            'shippingMethod' => $zone['zoneName'] . ' (' . $zone['estimatedHours'] . ')',
            'items' => $orderItems,
            'subtotal' => $subtotal,
            'discountAmount' => $discount,
            'couponCode' => $couponCode,
            'deliveryFee' => $deliveryFee,
            'tax' => 0,
            'grandTotal' => $grand,
            'currency' => 'GBP',
            'paymentMethod' => $method,
            // Money is only "Paid" once the gateway confirms it (webhook / capture) or staff verify it
            'paymentStatus' => $simulated ? 'Paid' : 'Unpaid',
            'paymentReference' => $simulated ? 'test_' . strtolower($gateway) . '_' . bin2hex(random_bytes(4)) : ($text('paymentReference', 60) ?: null),
            'orderStatus' => 'Pending',
            'statusHistory' => [['status' => 'Pending', 'timestamp' => now_iso(), 'updatedBy' => 'Customer (Checkout)', 'note' => 'Order placed']],
            'createdAt' => now_iso(),
        ];
        if ($simulated) $order['paymentGatewayDetails'] = ['gateway' => $gateway, 'transactionId' => $order['paymentReference'], 'captureDate' => now_iso()];

        $pIndex2 = [];
        foreach ($products as $p) $pIndex2[$p['id']] = $p;
        foreach ($orderItems as $oi) {
            $p = $pIndex2[$oi['productId']];
            foreach ($p['variants'] as $v) if ($v['id'] === $oi['variantId']) add_movement($movements, $p, $v, -$oi['quantity'], 'order_sold', $id, 'Customer order placed by ' . $name, 'Online Store Checkout');
        }

        kv_set('products', $products, 'storefront');
        kv_set('movements', array_slice($movements, 0, 2000), 'storefront');
        if ($couponCode) kv_set('coupons', $coupons, 'storefront');
        order_put($order);
        audit('Order Placed', 'Order', $id, sprintf('New order by %s for £%.2f', $name, $grand));
        return $order;
    });
}

// Restock when an order is cancelled or returned
function restock(array $order, string $by, string $why): void
{
    $products = kv_get('products', []);
    $movements = kv_get('movements', []);
    foreach ($order['items'] as $it) {
        foreach ($products as &$p) {
            if ($p['id'] !== $it['productId']) continue;
            foreach ($p['variants'] as &$v) {
                if ($v['id'] !== $it['variantId']) continue;
                $v['stockQuantity'] += $it['quantity'];
                add_movement($movements, $p, $v, $it['quantity'], 'order_cancelled', $order['id'], $why, $by);
            }
            unset($v);
        }
        unset($p);
    }
    kv_set('products', $products, $by);
    kv_set('movements', array_slice($movements, 0, 2000), $by);
}

function update_order(array $staff, string $id, array $in): array
{
    return in_transaction(function () use ($staff, $id, $in) {
        $order = order_get($id);
        if (!$order) fail(404, 'Order not found.');
        $courier = $staff['role'] === 'delivery';
        if ($courier && ($order['assignedStaffId'] ?? null) !== $staff['id']) fail(403, 'This order is not assigned to you.');

        if (isset($in['status'])) {
            $new = (string) $in['status'];
            if (!in_array($new, ORDER_STATUSES, true)) fail(422, 'Unknown status.');
            if ($courier && !in_array($new, ['Shipped', 'Delivered'], true)) fail(403, 'Couriers can only mark orders shipped or delivered.');
            $prev = $order['orderStatus'];
            if ($new !== $prev) {
                $returning = in_array($new, ['Cancelled', 'Returned'], true) && !in_array($prev, ['Cancelled', 'Returned'], true);
                if ($returning) restock($order, $staff['name'], 'Stock restored: order ' . strtolower($new));
                $order['orderStatus'] = $new;
                $order['statusHistory'][] = ['status' => $new, 'timestamp' => now_iso(), 'updatedBy' => $staff['name'], 'note' => $in['note'] ?? null];
                audit('Order Status Changed', 'Order', $id, "Status $prev -> $new", $staff);
            }
        }
        if (isset($in['paymentStatus'])) {
            $ps = (string) $in['paymentStatus'];
            if (!in_array($ps, ['Unpaid', 'Paid', 'Partial', 'Refunded'], true)) fail(422, 'Unknown payment status.');
            if ($courier && $order['paymentMethod'] !== 'Cash on Delivery') fail(403, 'Couriers can only record cash collected.');
            $order['paymentStatus'] = $ps;
            if (!empty($in['paymentReference'])) $order['paymentReference'] = mb_substr((string) $in['paymentReference'], 0, 80);
            $order['paymentVerifiedBy'] = $staff['name'];
            audit('Order Payment Updated', 'Order', $id, "Payment marked $ps", $staff);
        }
        if (array_key_exists('courierName', $in) && !$courier) {
            $order['assignedDeliveryPerson'] = mb_substr((string) $in['courierName'], 0, 80);
            $order['assignedStaffId'] = $in['courierStaffId'] ?? null;
            if (in_array($order['orderStatus'], ['Confirmed', 'Processing'], true)) {
                $order['orderStatus'] = 'Ready for Dispatch';
                $order['statusHistory'][] = ['status' => 'Ready for Dispatch', 'timestamp' => now_iso(), 'updatedBy' => $staff['name'], 'note' => 'Assigned courier: ' . $order['assignedDeliveryPerson']];
            }
            audit('Courier Assigned', 'Order', $id, 'Assigned to ' . $order['assignedDeliveryPerson'], $staff);
        }
        if (isset($in['internalNotes']) && !$courier) $order['internalNotes'] = mb_substr((string) $in['internalNotes'], 0, 1000);
        order_put($order);
        return $order;
    });
}

// Public tracking: no address, phone or email in the response
function track_order(string $id, string $phone): array
{
    $o = order_get(strtoupper(trim($id)));
    if (!$o) fail(404, 'No order found.');
    if ($phone !== '') {
        $a = preg_replace('/\D/', '', $phone);
        $b = preg_replace('/\D/', '', $o['customerPhone']);
        if ($a === '' || (!str_contains($b, $a) && !str_contains($a, $b))) fail(404, 'No order found.');
    }
    return [
        'id' => $o['id'],
        'city' => $o['city'],
        'orderStatus' => $o['orderStatus'],
        'assignedDeliveryPerson' => $o['assignedDeliveryPerson'] ?? null,
        'items' => array_map(fn($i) => ['productName' => $i['productName'], 'size' => $i['size'], 'quantity' => $i['quantity'], 'subtotal' => $i['subtotal']], $o['items']),
        'createdAt' => $o['createdAt'],
    ];
}
