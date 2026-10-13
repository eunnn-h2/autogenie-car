<?php

declare(strict_types=1);
require_once __DIR__ . '/member-sms-common.php';
member_phone_api_guard();
$data = member_json_input();
$phone = normalize_member_phone((string)($data['phone'] ?? ''));
$code = preg_replace('/\D+/', '', (string)($data['code'] ?? '')) ?? '';
if ($phone === '' || !preg_match('/^\d{6}$/', $code)) {
    member_response(['ok' => false, 'message' => '휴대폰 번호와 6자리 인증번호를 확인해 주세요.'], 422);
}
$expectedPhone = (string)($_SESSION['signup_phone'] ?? '');
$expectedHash = (string)($_SESSION['signup_phone_code_hash'] ?? '');
$expires = (int)($_SESSION['signup_phone_code_expires_at'] ?? 0);
if ($expectedPhone === '' || !hash_equals($expectedPhone, $phone) || $expectedHash === '') {
    member_response(['ok' => false, 'message' => '인증번호를 먼저 발급받아 주세요.'], 400);
}
if ($expires < time()) {
    unset($_SESSION['signup_phone_code_hash'], $_SESSION['signup_phone_code_expires_at']);
    member_response(['ok' => false, 'message' => '인증번호가 만료되었습니다. 다시 발급받아 주세요.'], 410);
}
$attempts = (int)($_SESSION['signup_phone_code_attempts'] ?? 0);
if ($attempts >= 5) {
    unset($_SESSION['signup_phone_code_hash'], $_SESSION['signup_phone_code_expires_at']);
    member_response(['ok' => false, 'message' => '인증번호를 너무 많이 틀렸습니다. 다시 발급받아 주세요.'], 429);
}
if (!password_verify($code, $expectedHash)) {
    $_SESSION['signup_phone_code_attempts'] = $attempts + 1;
    member_response(['ok' => false, 'message' => '인증번호가 일치하지 않습니다.'], 422);
}
$_SESSION['signup_phone_verified'] = $phone;
$_SESSION['signup_phone_verified_at'] = time();
unset($_SESSION['signup_phone_code_hash'], $_SESSION['signup_phone_code_expires_at'], $_SESSION['signup_phone_code_attempts']);
member_response(['ok' => true, 'message' => '휴대폰 인증이 완료되었습니다.']);
