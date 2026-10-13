<?php

declare(strict_types=1);
require_once __DIR__ . '/member-sms-common.php';
member_phone_api_guard();
$data = member_json_input();
$phone = normalize_member_phone((string)($data['phone'] ?? ''));
if ($phone === '') member_response(['ok' => false, 'message' => '올바른 휴대폰 번호를 입력해 주세요.'], 422);

$now = time();
$lastRequest = (int)($_SESSION['signup_phone_code_requested_at'] ?? 0);
if ($lastRequest > 0 && $now - $lastRequest < 60) {
    member_response(['ok' => false, 'message' => '인증번호는 1분 후 다시 요청할 수 있습니다.'], 429);
}
try {
    $stmt = $pdo->prepare('SELECT id FROM member_accounts WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
    if ($stmt->fetchColumn()) {
        member_response(['ok' => false, 'message' => '이미 가입된 휴대폰 번호입니다. 기존 계정으로 로그인해 주세요.'], 409);
    }
    // Missing settings should not consume a rate-limit slot.
    $settings = require __DIR__ . '/../config/sms.php';
    $local = in_array(strtolower((string)($_SERVER['HTTP_HOST'] ?? '')), ['localhost', 'localhost:80', '127.0.0.1', '127.0.0.1:80'], true)
        && in_array((string)($_SERVER['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
    if (!($local && $settings['local_debug'])
        && ($settings['service_id'] === '' || $settings['access_key'] === ''
            || $settings['secret_key'] === '' || $settings['sender'] === '')) {
        member_response(['ok' => false, 'message' => '문자 인증 서비스가 아직 설정되지 않았습니다. 관리자에게 문의해 주세요.'], 503);
    }

    member_sms_consume_rate($pdo, $phone);
    $code = (string)random_int(100000, 999999);
    $debug = member_sms_send($phone, $code);
    $_SESSION['signup_phone'] = $phone;
    $_SESSION['signup_phone_code_hash'] = password_hash($code, PASSWORD_DEFAULT);
    $_SESSION['signup_phone_code_expires_at'] = time() + 600;
    $_SESSION['signup_phone_code_requested_at'] = time();
    $_SESSION['signup_phone_code_attempts'] = 0;
    unset($_SESSION['signup_phone_verified'], $_SESSION['signup_phone_verified_at']);
    $result = ['ok' => true, 'message' => '인증번호 발송을 요청했습니다. 문자를 확인해 주세요. (10분 유효)'];
    if ($debug) $result['debug_code'] = $code;
    member_response($result);
} catch (DomainException $e) {
    member_response(['ok' => false, 'message' => $e->getMessage()], 429);
} catch (Throwable $e) {
    error_log('[AutoGenie signup SMS] ' . $e->getMessage());
    member_response(['ok' => false, 'message' => '문자 인증 요청을 처리하지 못했습니다. 문자 발송 및 DB 설정을 확인해 주세요.'], 503);
}
