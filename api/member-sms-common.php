<?php
/** SMS send / rate limit helper. No SMS credentials or verification codes in browser code. */
declare(strict_types=1);
require_once __DIR__ . '/member-auth-common.php';

function member_phone_api_guard(): void
{
    header('Cache-Control: no-store');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        member_response(['ok' => false, 'message' => '잘못된 요청입니다.'], 405);
    }
    // Same-origin fetch sets this header; a cross-site HTML form cannot set it.
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
        member_response(['ok' => false, 'message' => '허용되지 않은 요청입니다.'], 403);
    }
}

function member_sms_consume_rate(PDO $pdo, string $phone): void
{
    // DB-level limits cannot be avoided by clearing session cookies.
    $remoteAddress = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    $keys = [
        [hash('sha256', 'phone:' . $phone), 5],
        [hash('sha256', 'ip:' . $remoteAddress), 15],
    ];
    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare('INSERT IGNORE INTO member_sms_send_limits (limit_key, window_started_at, request_count) VALUES (?, NOW(), 0)');
        $check = $pdo->prepare('SELECT request_count, TIMESTAMPDIFF(SECOND, window_started_at, NOW()) AS age_seconds FROM member_sms_send_limits WHERE limit_key = ? FOR UPDATE');
        $inc = $pdo->prepare('UPDATE member_sms_send_limits SET request_count = ?, window_started_at = NOW() WHERE limit_key = ?');
        foreach ($keys as [$key, $max]) {
            $insert->execute([$key]);
            $check->execute([$key]);
            $row = $check->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new RuntimeException('인증 요청 제한을 확인하지 못했습니다.');
            $expired = (int)$row['age_seconds'] >= 3600;
            $count = $expired ? 0 : (int)$row['request_count'];
            if ($count >= $max) {
                throw new DomainException('인증 요청 횟수가 너무 많습니다. 잠시 후 다시 시도해 주세요.');
            }
            if ($expired) {
                $inc->execute([$count + 1, $key]);
            } else {
                $pdo->prepare('UPDATE member_sms_send_limits SET request_count = request_count + 1 WHERE limit_key = ?')->execute([$key]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function member_sms_send(string $phone, string $code): bool
{
    $config = require __DIR__ . '/../config/sms.php';
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $localOnly = (in_array($host, ['localhost', 'localhost:80', '127.0.0.1', '127.0.0.1:80'], true))
        && in_array($remote, ['127.0.0.1', '::1'], true);
    if ($localOnly && $config['local_debug'] === true) return true;

    if ($config['service_id'] === '' || $config['access_key'] === ''
        || $config['secret_key'] === '' || !preg_match('/^\d{9,12}$/', $config['sender'])) {
        throw new RuntimeException('문자 인증 서비스가 아직 설정되지 않았습니다.');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL 확장 기능이 필요합니다.');
    }

    $method = 'POST';
    if (!preg_match('/^[A-Za-z0-9:_-]+$/', $config['service_id'])) {
        throw new RuntimeException('문자 서비스 아이디 설정이 올바르지 않습니다.');
    }
    $path = '/sms/v2/services/' . $config['service_id'] . '/messages';
    $timestamp = (string)(int)round(microtime(true) * 1000);
    $stringToSign = $method . ' ' . $path . "\n" . $timestamp . "\n" . $config['access_key'];
    $signature = base64_encode(hash_hmac('sha256', $stringToSign, $config['secret_key'], true));
    $body = json_encode([
        'type' => 'SMS', 'contentType' => 'COMM', 'countryCode' => '82',
        'from' => $config['sender'],
        'content' => '[오토지니] 회원가입 인증번호는 ' . $code . ' 입니다. (10분 유효)',
        'messages' => [['to' => preg_replace('/\D/', '', $phone)]],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($body === false) throw new RuntimeException('문자 요청을 준비하지 못했습니다.');
    $ch = curl_init('https://sens.apigw.ntruss.com' . $path);
    if ($ch === false) throw new RuntimeException('문자 발송 연결에 실패했습니다.');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json; charset=utf-8',
            'x-ncp-apigw-timestamp: ' . $timestamp,
            'x-ncp-iam-access-key: ' . $config['access_key'],
            'x-ncp-apigw-signature-v2: ' . $signature,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($response) ? json_decode($response, true) : null;
    // SENS returns a 202 on an accepted request; this is NOT delivery confirmation.
    if ($status !== 202 || !is_array($data) || (string)($data['statusCode'] ?? '') !== '202') {
        throw new RuntimeException('문자 발송 요청을 처리하지 못했습니다. 발신번호/서비스 설정을 확인해 주세요.');
    }
    return false;
}
