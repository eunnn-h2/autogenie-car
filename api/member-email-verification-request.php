<?php

declare(strict_types=1);
require_once __DIR__ . '/member-auth-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    member_response(['ok' => false, 'message' => '잘못된 요청입니다.'], 405);
}

$data = member_json_input();
$email = strtolower(trim((string)($data['email'] ?? '')));

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    member_response(['ok' => false, 'message' => '올바른 이메일 주소를 입력해 주세요.'], 422);
}

try {
    $stmt = $pdo->prepare('SELECT id FROM members WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);

    if ($stmt->fetchColumn()) {
        member_response(['ok' => false, 'message' => '이미 가입된 이메일입니다. 로그인 또는 비밀번호 찾기를 이용해 주세요.'], 409);
    }

    $lastRequestedAt = (int)($_SESSION['signup_email_code_requested_at'] ?? 0);
    if ($lastRequestedAt > 0 && (time() - $lastRequestedAt) < 60) {
        member_response(['ok' => false, 'message' => '인증번호는 1분 후 다시 요청할 수 있습니다.'], 429);
    }

    $code = (string)random_int(100000, 999999);
    $_SESSION['signup_email'] = $email;
    $_SESSION['signup_email_code_hash'] = password_hash($code, PASSWORD_DEFAULT);
    $_SESSION['signup_email_code_expires_at'] = time() + 600;
    $_SESSION['signup_email_code_requested_at'] = time();
    unset($_SESSION['signup_email_verified'], $_SESSION['signup_email_verified_at']);

    $subject = '[오토지니] 회원가입 이메일 인증번호';
    $body = "오토지니 회원가입 이메일 인증번호는 {$code} 입니다.\n\n"
          . "인증번호는 10분 동안 유효합니다.\n"
          . "본인이 요청하지 않았다면 이 메일을 무시해 주세요.";

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: Autogenie <no-reply@localhost>',
    ];

    $mailSent = @mail(
        $email,
        '=?UTF-8?B?' . base64_encode($subject) . '?=',
        $body,
        implode("\r\n", $headers)
    );

    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    $isLocal = $host === ''
        || str_contains($host, 'localhost')
        || str_contains($host, '127.0.0.1');

    if (!$mailSent && !$isLocal) {
        unset(
            $_SESSION['signup_email'],
            $_SESSION['signup_email_code_hash'],
            $_SESSION['signup_email_code_expires_at'],
            $_SESSION['signup_email_code_requested_at']
        );
        member_response([
            'ok' => false,
            'message' => '인증메일을 발송하지 못했습니다. 서버 SMTP 설정을 확인해 주세요.'
        ], 500);
    }

    $response = [
        'ok' => true,
        'message' => $mailSent
            ? '인증번호를 이메일로 발송했습니다.'
            : '로컬 테스트 모드입니다.'
    ];

    if ($isLocal && !$mailSent) {
        $response['debug_code'] = $code;
    }

    member_response($response);
} catch (PDOException $e) {
    member_response(['ok' => false, 'message' => '이메일 인증을 처리하지 못했습니다.'], 500);
}
