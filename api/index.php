<?php
// DK Perfume API: /api/?a=<action>. Every read and write is checked against the caller's role here.

declare(strict_types=1);

// Errors go to the server log, never into responses
ini_set('display_errors', '0');

require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/db.php';
require __DIR__ . '/lib/auth.php';
require __DIR__ . '/lib/payments.php';
require __DIR__ . '/lib/orders.php';

// Which roles may read / write each shared collection
const READERS = [
    'orders' => ['orders', 'dashboard', 'finances', 'reports'],
    'movements' => ['inventory', 'purchases', 'reports'],
    'suppliers' => ['purchases', 'finances'],
    'purchases' => ['purchases', 'finances', 'inventory'],
    'expenses' => ['expenses', 'finances', 'profit'],
    'coupons' => ['promotions'],
    'audit_logs' => ['users'],
];
const WRITERS = [
    'products' => ['products', 'inventory', 'purchases'],
    'movements' => ['inventory', 'purchases', 'products'],
    'suppliers' => ['purchases'],
    'purchases' => ['purchases'],
    'expenses' => ['expenses'],
    'delivery_zones' => ['delivery'],
    'coupons' => ['promotions'],
];
// Settings fields each permission may change (owners hold 'settings' and may change everything)
const SETTINGS_FIELDS = [
    'hero' => ['heroSlides', 'collections'],
    'payments' => ['payments', 'markets'],
    'users' => ['rolePermissions'],
];

try {
    $a = (string) ($_GET['a'] ?? '');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    // Browser calls that change data must carry this header; plain cross-site forms cannot set it
    $writes = ['login', 'logout', 'save', 'staff_save', 'staff_delete', 'order_create', 'order_update', 'audit', 'coupon_check'];
    if (in_array($a, $writes, true) && (($_SERVER['HTTP_X_DK_CLIENT'] ?? '') !== '1' || $method !== 'POST')) fail(400, 'Bad request.');

    switch ($a) {
        case 'bootstrap':
            $me = current_staff();
            $data = [
                'products' => kv_get('products', []),
                'settings' => kv_get('settings', new stdClass()),
                'delivery_zones' => kv_get('delivery_zones', []),
                'gateways' => ['stripe' => gateway_ready('Stripe'), 'paypal' => gateway_ready('PayPal')],
                'me' => $me ? public_staff($me) : null,
            ];
            if ($me) {
                foreach (READERS as $key => $perms) {
                    foreach ($perms as $p) {
                        if (!can($p, $me)) continue;
                        $data[$key] = $key === 'orders'
                            ? orders_all($me['role'] === 'delivery' ? $me['id'] : null)
                            : kv_get($key, []);
                        break;
                    }
                }
                if (can('users', $me)) $data['staff'] = staff_all();
                elseif (can('orders', $me)) {
                    // Enough to pick a courier, nothing more
                    $data['staff'] = array_values(array_filter(staff_all(), fn($s) => $s['role'] === 'delivery' && $s['active']));
                }
            }
            send_json($data);

        case 'login':
            $b = body();
            $s = login((string) ($b['identifier'] ?? ''), (string) ($b['password'] ?? ''));
            audit('Staff Authenticated', 'Staff', $s['id'], $s['name'] . ' signed in', $s);
            send_json(['me' => public_staff($s)]);

        case 'logout':
            logout();
            send_json(['ok' => true]);

        case 'save':
            $b = body();
            $key = (string) ($b['key'] ?? '');
            $value = $b['value'] ?? null;
            if ($key === 'settings') {
                $me = current_staff() ?? fail(401, 'Please sign in.');
                if (!is_array($value)) fail(422, 'Invalid settings.');
                $current = kv_get('settings', []);
                if (can('settings', $me)) {
                    $next = $value;
                } else {
                    $next = $current;
                    $allowed = [];
                    foreach (SETTINGS_FIELDS as $perm => $fields) if (can($perm, $me)) $allowed = array_merge($allowed, $fields);
                    if (!$allowed) fail(403, 'Your role is not allowed to do this.');
                    foreach ($allowed as $f) if (array_key_exists($f, $value)) $next[$f] = $value[$f];
                }
                kv_set('settings', $next, $me['name']);
                send_json(['ok' => true]);
            }
            if (!isset(WRITERS[$key])) fail(400, 'Unknown collection.');
            $me = require_any(WRITERS[$key]);
            if (!is_array($value)) fail(422, 'Invalid data.');
            kv_set($key, $value, $me['name']);
            send_json(['ok' => true]);

        case 'audit':
            $me = current_staff() ?? fail(401, 'Please sign in.');
            $b = body();
            audit(mb_substr((string) ($b['action'] ?? ''), 0, 80), mb_substr((string) ($b['entityType'] ?? ''), 0, 40), mb_substr((string) ($b['entityId'] ?? ''), 0, 80), mb_substr((string) ($b['details'] ?? ''), 0, 500), $me);
            send_json(['ok' => true]);

        case 'staff_save':
            $me = require_any(['users']);
            $u = body()['user'] ?? [];
            $id = (string) ($u['id'] ?? '');
            $role = (string) ($u['role'] ?? '');
            $email = strtolower(trim((string) ($u['email'] ?? '')));
            if ($id === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || !in_array($role, ['owner', 'manager', 'sales', 'inventory', 'accountant', 'delivery'], true)) fail(422, 'Name, valid email and role are required.');
            $existing = staff_by_id($id);
            $dupe = db()->prepare('SELECT id FROM staff WHERE LOWER(email) = ? AND id <> ?');
            $dupe->execute([$email, $id]);
            if ($dupe->fetchColumn()) fail(409, 'This email is already used.');
            $active = !empty($u['active']) ? 1 : 0;
            if ($existing && $existing['role'] === 'owner' && ($role !== 'owner' || !$active)) {
                $owners = (int) db()->query("SELECT COUNT(*) FROM staff WHERE role = 'owner' AND active = 1")->fetchColumn();
                if ($owners <= 1) fail(409, 'The store needs at least one active owner.');
            }
            $password = (string) ($u['password'] ?? '');
            if (!$existing && strlen($password) < 8) fail(422, 'Password must be at least 8 characters.');
            if ($password !== '' && strlen($password) < 8) fail(422, 'Password must be at least 8 characters.');
            $hash = $password !== '' ? password_hash($password, PASSWORD_DEFAULT) : $existing['password_hash'];
            if ($existing) {
                db()->prepare('UPDATE staff SET name = ?, email = ?, phone = ?, role = ?, active = ?, password_hash = ? WHERE id = ?')
                    ->execute([mb_substr((string) $u['name'], 0, 120), $email, (string) ($u['phone'] ?? ''), $role, $active, $hash, $id]);
            } else {
                db()->prepare('INSERT INTO staff (id, name, email, phone, role, active, password_hash, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
                    ->execute([$id, mb_substr((string) $u['name'], 0, 120), $email, (string) ($u['phone'] ?? ''), $role, $active, $hash, now_iso()]);
            }
            audit($existing ? 'Staff Updated' : 'Staff Created', 'Staff', $id, ($u['name'] ?? '') . " ($role)", $me);
            send_json(['staff' => staff_all()]);

        case 'staff_delete':
            $me = require_any(['users']);
            $id = (string) (body()['id'] ?? '');
            $s = staff_by_id($id);
            if (!$s) fail(404, 'Staff member not found.');
            if ($s['role'] === 'owner' || $s['id'] === $me['id']) fail(409, 'Owners and your own account cannot be removed.');
            db()->prepare('DELETE FROM staff WHERE id = ?')->execute([$id]);
            audit('Staff Deleted', 'Staff', $id, 'Removed ' . $s['name'], $me);
            send_json(['staff' => staff_all()]);

        case 'coupon_check':
            $b = body();
            send_json(coupon_discount(kv_get('coupons', []), (string) ($b['code'] ?? ''), (float) ($b['subtotal'] ?? 0)));

        case 'order_create':
            $order = create_order(body());
            $redirect = null;
            $gateway = GATEWAY_FOR[$order['paymentMethod']] ?? null;
            if ($gateway && gateway_ready($gateway)) {
                $redirect = $gateway === 'Stripe' ? stripe_checkout($order) : paypal_checkout($order);
            }
            send_json(['order' => $order, 'redirectUrl' => $redirect]);

        case 'order_update':
            $me = require_any(['orders']);
            $b = body();
            send_json(['order' => update_order($me, (string) ($b['id'] ?? ''), $b)]);

        case 'track':
            send_json(['order' => track_order((string) ($_GET['id'] ?? ''), (string) ($_GET['phone'] ?? ''))]);

        case 'stripe_webhook':
            stripe_webhook();

        case 'paypal_return':
            paypal_return();

        default:
            fail(404, 'Unknown action.');
    }
} catch (HttpError $e) {
    send_json(['error' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    error_log('[dk-api] ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    send_json(['error' => str_contains($e->getMessage(), 'not configured') ? $e->getMessage() : 'Server error. Please try again.'], 500);
}
