<?php
// Database access. Production uses MySQL on Hostinger; local tests can point the config at SQLite.

declare(strict_types=1);

function config(): array
{
    static $cfg = null;
    if ($cfg !== null) return $cfg;
    // The config lives OUTSIDE the web root (next to public_html) so it is never downloadable or in git.
    $path = getenv('DK_CONFIG') ?: dirname(__DIR__, 3) . '/dkperfume-config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Server is not configured yet (missing dkperfume-config.php).');
    }
    $cfg = require $path;
    return $cfg;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;
    $c = config();
    if (($c['db_driver'] ?? 'mysql') === 'sqlite') {
        $pdo = new PDO('sqlite:' . $c['db_path']);
        $pdo->exec('PRAGMA journal_mode = WAL');
    } else {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $c['db_host'] ?? 'localhost', $c['db_name']);
        $pdo = new PDO($dsn, $c['db_user'], $c['db_pass']);
    }
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function is_sqlite(): bool
{
    return db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
}

// Run $fn inside a write transaction; SQLite takes the write lock up front so concurrent orders serialise.
function in_transaction(callable $fn)
{
    $pdo = db();
    $sqlite = is_sqlite();
    if ($sqlite) $pdo->exec('BEGIN IMMEDIATE');
    else $pdo->beginTransaction();
    try {
        $result = $fn($pdo);
        $sqlite ? $pdo->exec('COMMIT') : $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        $sqlite ? $pdo->exec('ROLLBACK') : $pdo->rollBack();
        throw $e;
    }
}

// ---- Key/value documents (catalogue, settings, zones, coupons, ledgers) ----

function kv_get(string $key, $default = null)
{
    $sql = 'SELECT v FROM kv_store WHERE k = ?' . (is_sqlite() ? '' : ' FOR UPDATE');
    $st = db()->prepare(db()->inTransaction() ? $sql : 'SELECT v FROM kv_store WHERE k = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : json_decode($v, true);
}

function kv_set(string $key, $value, string $by = 'system'): void
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $now = gmdate('c');
    if (is_sqlite()) {
        $sql = 'INSERT INTO kv_store (k, v, updated_at, updated_by) VALUES (?, ?, ?, ?)
                ON CONFLICT(k) DO UPDATE SET v = excluded.v, updated_at = excluded.updated_at, updated_by = excluded.updated_by';
    } else {
        $sql = 'INSERT INTO kv_store (k, v, updated_at, updated_by) VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE v = VALUES(v), updated_at = VALUES(updated_at), updated_by = VALUES(updated_by)';
    }
    db()->prepare($sql)->execute([$key, $json, $now, $by]);
}

function next_counter(string $name, int $start): int
{
    $pdo = db();
    $pdo->prepare('UPDATE counters SET value = value + 1 WHERE name = ?')->execute([$name]);
    $st = $pdo->prepare('SELECT value FROM counters WHERE name = ?');
    $st->execute([$name]);
    $v = $st->fetchColumn();
    if ($v === false) {
        $pdo->prepare('INSERT INTO counters (name, value) VALUES (?, ?)')->execute([$name, $start]);
        return $start;
    }
    return (int) $v;
}

// ---- Orders: one row each, so concurrent customers never overwrite each other ----

function order_get(string $id): ?array
{
    $st = db()->prepare('SELECT data FROM orders WHERE id = ?');
    $st->execute([$id]);
    $v = $st->fetchColumn();
    return $v === false ? null : json_decode($v, true);
}

function order_put(array $order): void
{
    $json = json_encode($order, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $exists = db()->prepare('SELECT 1 FROM orders WHERE id = ?');
    $exists->execute([$order['id']]);
    if ($exists->fetchColumn()) {
        $st = db()->prepare('UPDATE orders SET data = ?, status = ?, payment_status = ?, assigned_staff_id = ?, updated_at = ? WHERE id = ?');
        $st->execute([$json, $order['orderStatus'], $order['paymentStatus'], $order['assignedStaffId'] ?? null, gmdate('c'), $order['id']]);
    } else {
        $st = db()->prepare('INSERT INTO orders (id, data, status, payment_status, assigned_staff_id, customer_phone, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $st->execute([$order['id'], $json, $order['orderStatus'], $order['paymentStatus'], $order['assignedStaffId'] ?? null, $order['customerPhone'], $order['createdAt'], gmdate('c')]);
    }
}

function orders_all(?string $assignedTo = null): array
{
    if ($assignedTo !== null) {
        $st = db()->prepare('SELECT data FROM orders WHERE assigned_staff_id = ? ORDER BY created_at DESC');
        $st->execute([$assignedTo]);
    } else {
        $st = db()->query('SELECT data FROM orders ORDER BY created_at DESC');
    }
    return array_map(fn($r) => json_decode($r['data'], true), $st->fetchAll());
}
