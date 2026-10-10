<?php
// One-time setup: creates the tables, loads the starting catalogue and creates the owner account.
// Refuses to run again once an owner exists.

declare(strict_types=1);

require __DIR__ . '/lib/http.php';
require __DIR__ . '/lib/db.php';

function schema(bool $sqlite): array
{
    if ($sqlite) {
        return [
            'CREATE TABLE IF NOT EXISTS kv_store (k TEXT PRIMARY KEY, v TEXT NOT NULL, updated_at TEXT, updated_by TEXT)',
            'CREATE TABLE IF NOT EXISTS orders (id TEXT PRIMARY KEY, data TEXT NOT NULL, status TEXT, payment_status TEXT, assigned_staff_id TEXT, customer_phone TEXT, created_at TEXT, updated_at TEXT)',
            'CREATE INDEX IF NOT EXISTS orders_created ON orders (created_at)',
            'CREATE INDEX IF NOT EXISTS orders_courier ON orders (assigned_staff_id)',
            'CREATE TABLE IF NOT EXISTS staff (id TEXT PRIMARY KEY, name TEXT, email TEXT UNIQUE, phone TEXT, role TEXT, active INTEGER, password_hash TEXT, created_at TEXT)',
            'CREATE TABLE IF NOT EXISTS counters (name TEXT PRIMARY KEY, value INTEGER)',
        ];
    }
    $opts = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    return [
        "CREATE TABLE IF NOT EXISTS kv_store (k VARCHAR(64) PRIMARY KEY, v LONGTEXT NOT NULL, updated_at VARCHAR(40), updated_by VARCHAR(120)) $opts",
        "CREATE TABLE IF NOT EXISTS orders (id VARCHAR(40) PRIMARY KEY, data LONGTEXT NOT NULL, status VARCHAR(40), payment_status VARCHAR(20), assigned_staff_id VARCHAR(64) NULL, customer_phone VARCHAR(40), created_at VARCHAR(40), updated_at VARCHAR(40), INDEX orders_created (created_at), INDEX orders_courier (assigned_staff_id)) $opts",
        "CREATE TABLE IF NOT EXISTS staff (id VARCHAR(64) PRIMARY KEY, name VARCHAR(120), email VARCHAR(160) UNIQUE, phone VARCHAR(40), role VARCHAR(20), active TINYINT(1), password_hash VARCHAR(255), created_at VARCHAR(40)) $opts",
        "CREATE TABLE IF NOT EXISTS counters (name VARCHAR(40) PRIMARY KEY, value INT) $opts",
    ];
}

$message = '';
$done = false;
try {
    foreach (schema(is_sqlite()) as $sql) db()->exec($sql);
    $hasOwner = (int) db()->query("SELECT COUNT(*) FROM staff WHERE role = 'owner'")->fetchColumn() > 0;

    if ($hasOwner) {
        $done = true;
        $message = 'Already installed. You can delete install.php or leave it; it will not run again.';
    } elseif (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $pass = (string) ($_POST['password'] ?? '');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $message = 'Enter your name and a valid email.';
        elseif (strlen($pass) < 10) $message = 'Use a password of at least 10 characters.';
        elseif ($pass !== ($_POST['confirm'] ?? '')) $message = 'Passwords do not match.';
        else {
            in_transaction(function () use ($name, $email, $pass) {
                $seed = json_decode((string) file_get_contents(__DIR__ . '/seed.json'), true) ?? [];
                foreach (['products', 'settings', 'delivery_zones', 'coupons'] as $k) {
                    if (isset($seed[$k]) && kv_get($k) === null) kv_set($k, $seed[$k], 'install');
                }
                foreach (['suppliers', 'purchases', 'movements', 'expenses', 'audit_logs'] as $k) if (kv_get($k) === null) kv_set($k, [], 'install');
                db()->prepare('INSERT INTO staff (id, name, email, phone, role, active, password_hash, created_at) VALUES (?, ?, ?, ?, ?, 1, ?, ?)')
                    ->execute(['usr-' . bin2hex(random_bytes(6)), $name, $email, '', 'owner', password_hash($pass, PASSWORD_DEFAULT), now_iso()]);
            });
            $done = true;
            $message = 'Installed. Sign in at /#/staff-login with the email and password you just set.';
        }
    }
} catch (Throwable $e) {
    $message = 'Error: ' . $e->getMessage();
}
?><!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="robots" content="noindex">
  <title>DK Perfume · Setup</title>
  <style>
    body { font-family: system-ui, sans-serif; background: #faf8f5; color: #161513; display: grid; place-items: center; min-height: 100vh; margin: 0; }
    form, .box { background: #fff; border: 1px solid #eae5dd; padding: 32px; width: min(380px, 90vw); }
    h1 { font-family: Georgia, serif; font-weight: 400; margin: 0 0 20px; }
    label { display: block; font-size: 12px; letter-spacing: .1em; text-transform: uppercase; color: #7d776a; margin: 14px 0 4px; }
    input { width: 100%; box-sizing: border-box; padding: 10px; border: 1px solid #ded8cc; font-size: 15px; }
    button { margin-top: 22px; width: 100%; padding: 12px; background: #161513; color: #fff; border: 0; letter-spacing: .14em; text-transform: uppercase; cursor: pointer; }
    p { font-size: 14px; line-height: 1.5; }
    .msg { color: #b45309; }
  </style>
</head>
<body>
<?php if ($done): ?>
  <div class="box"><h1>DK Perfume</h1><p><?= htmlspecialchars($message) ?></p></div>
<?php else: ?>
  <form method="post">
    <h1>Set up DK Perfume</h1>
    <p>Create the owner account. This page works only once.</p>
    <?php if ($message): ?><p class="msg"><?= htmlspecialchars($message) ?></p><?php endif; ?>
    <label>Your name</label><input name="name" required>
    <label>Email</label><input name="email" type="email" required>
    <label>Password (10+ characters)</label><input name="password" type="password" required minlength="10">
    <label>Confirm password</label><input name="confirm" type="password" required minlength="10">
    <button type="submit">Install</button>
  </form>
<?php endif; ?>
</body>
</html>
