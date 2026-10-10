<?php
// JSON request/response helpers.

declare(strict_types=1);

final class HttpError extends RuntimeException
{
    public int $status;
    public function __construct(int $status, string $message)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function fail(int $status, string $message): never
{
    throw new HttpError($status, $message);
}

function body(): array
{
    static $data = null;
    if ($data !== null) return $data;
    $raw = file_get_contents('php://input') ?: '';
    $data = $raw === '' ? [] : (json_decode($raw, true) ?? []);
    if (!is_array($data)) fail(400, 'Invalid JSON body.');
    return $data;
}

function send_json($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function now_iso(): string
{
    return gmdate('Y-m-d\TH:i:s.v\Z');
}

function site_url(): string
{
    $c = config();
    if (!empty($c['site_url'])) return rtrim($c['site_url'], '/');
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
}
