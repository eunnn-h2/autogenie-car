<?php

declare(strict_types=1);
require_once __DIR__ . '/member-auth-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    member_response(['ok' => false, 'message' => '잘못된 요청입니다.'], 405);
}

$data = member_json_input();
$email = strtolower(trim((string)($data['email'] ?? '')));
$code = preg_replace('/\D+/', '', (string)($data['code'] ?? '')) ?? '';

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    member_response(['ok' => false, 'message' => '올바른 이메일 주소를 입력해 주세요.'], 422);
}
if (!preg_match('/^\d{6}$/', $code)) {
    member_response(['ok' => false, 'message' => '6자리 인증번호를 입력해 주세요.'], 422);
}

$sessionEmail = strtolower((string)($_SESSION['signup_email'] ?? ''));
$hash = (string)($_SESSION['signup_email_code_hash'] ?? '');
$expiresAt = (int)($_SESSION['signup_email_code_expires_at'] ?? 0);

if ($sessionEmail === '' || $sessionEmail !== $email || $hash === '') {
    member_response(['ok' => false, 'message' => '인증번호를 먼저 발급받아 주세요.'], 400);
}

if ($expiresAt < time()) {
    unset(
        $_SESSION['signup_email_code_hash'],
        $_SESSION['signup_email_code_expires_at']
    );
    member_response(['ok' => false, 'message' => '인증번호가 만료되었습니다. 새 인증번호를 받아 주세요.'], 410);
}

if (!password_verify($code, $hash)) {
    member_response(['ok' => false, 'message' => '인증번호가 올바르지 않습니다.'], 422);
}

$_SESSION['signup_email_verified'] = $email;
$_SESSION['signup_email_verified_at'] = time();

unset(
    $_SESSION['signup_email_code_hash'],
    $_SESSION['signup_email_code_expires_at']
);

member_response([
    'ok' => true,
    'message' => '이메일 인증이 완료되었습니다.'
]);
