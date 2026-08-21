<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'path' => '/',
    ]);
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

function member_json_input(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '', true);
    return is_array($data) ? $data : $_POST;
}

function member_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function normalize_member_phone(string $value): string
{
    $digits = preg_replace('/\D+/', '', $value) ?? '';
    if (!preg_match('/^(010|011|016|017|018|019)(\d{7,8})$/', $digits)) {
        return '';
    }
    $prefix = substr($digits, 0, 3);
    $rest = substr($digits, 3);
    if (strlen($rest) === 7) {
        return $prefix . '-' . substr($rest, 0, 3) . '-' . substr($rest, 3);
    }
    return $prefix . '-' . substr($rest, 0, 4) . '-' . substr($rest, 4);
}

function member_public(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'name' => (string)$row['name'],
        'phone' => (string)$row['phone'],
        'email' => (string)$row['email'],
        'created_at' => isset($row['created_at']) ? (string)$row['created_at'] : '',
    ];
}
