<?php
// Staff sign-in (PHP session cookie) and role permissions. Mirrors src/lib/permissions.ts,
// but here it is enforced: the browser can hide buttons, only the server can refuse a request.

declare(strict_types=1);

const OWNER_ONLY = ['users', 'payments', 'settings'];
const DEFAULT_ROLE_PERMISSIONS = [
    'manager' => ['dashboard', 'orders', 'products', 'inventory', 'purchases', 'delivery', 'promotions', 'hero', 'reports'],
    'sales' => ['dashboard', 'orders', 'promotions'],
    'inventory' => ['dashboard', 'products', 'inventory', 'purchases'],
    'accountant' => ['dashboard', 'orders', 'purchases', 'expenses', 'finances', 'reports', 'profit'],
    'delivery' => ['orders'],
];

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $secure = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    session_name('dk_staff');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $secure, 'httponly' => true, 'samesite' => 'Lax']);
    session_start();
}

function public_staff(array $s): array
{
    return [
        'id' => $s['id'],
        'name' => $s['name'],
        'email' => $s['email'],
        'phone' => $s['phone'],
        'role' => $s['role'],
        'active' => (bool) $s['active'],
    ];
}

function staff_by_id(string $id): ?array
{
    $st = db()->prepare('SELECT * FROM staff WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function staff_all(): array
{
    return array_map('public_staff', db()->query('SELECT * FROM staff ORDER BY created_at')->fetchAll());
}

function current_staff(): ?array
{
    start_session();
    $id = $_SESSION['staff_id'] ?? null;
    if (!$id) return null;
    $s = staff_by_id($id);
    return $s && $s['active'] ? $s : null;
}

function permissions_for(?array $staff): array
{
    if (!$staff || !$staff['active']) return [];
    $all = array_unique(array_merge(array_merge(...array_values(DEFAULT_ROLE_PERMISSIONS)), OWNER_ONLY, ['profit']));
    if ($staff['role'] === 'owner') return array_values($all);
    $custom = (kv_get('settings', [])['rolePermissions'] ?? []);
    $perms = $custom[$staff['role']] ?? DEFAULT_ROLE_PERMISSIONS[$staff['role']] ?? [];
    return array_values(array_diff($perms, OWNER_ONLY));
}

function can(string $perm, ?array $staff = null): bool
{
    return in_array($perm, permissions_for($staff ?? current_staff()), true);
}

// Stop the request unless the signed-in staff member holds one of the permissions
function require_any(array $perms): array
{
    $staff = current_staff();
    if (!$staff) fail(401, 'Please sign in.');
    foreach ($perms as $p) if (can($p, $staff)) return $staff;
    fail(403, 'Your role is not allowed to do this.');
}

function login(string $identifier, string $password): array
{
    $id = strtolower(trim($identifier));
    $rows = db()->query('SELECT * FROM staff WHERE active = 1')->fetchAll();
    $found = null;
    foreach ($rows as $s) {
        $phone = preg_replace('/\s+/', '', $s['phone']);
        if (strtolower($s['email']) === $id || ($phone !== '' && $phone === preg_replace('/\s+/', '', $id))) {
            $found = $s;
            break;
        }
    }
    // Same message for unknown user and wrong password, and a short delay to slow guessing
    if (!$found || !password_verify($password, $found['password_hash'])) {
        usleep(400000);
        fail(401, 'Incorrect email or password.');
    }
    start_session();
    session_regenerate_id(true);
    $_SESSION['staff_id'] = $found['id'];
    return $found;
}

function logout(): void
{
    start_session();
    $_SESSION = [];
    session_destroy();
}
