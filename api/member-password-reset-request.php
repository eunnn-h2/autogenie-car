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

$stmt = $pdo->prepare('SELECT id, name, email, status, password_reset_requested_at FROM member_accounts WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member || ($member['status'] ?? '') !== 'ACTIVE') {
    member_response(['ok' => true, 'message' => '가입된 이메일이라면 인증번호를 발송했습니다.']);
}

if (!empty($member['password_reset_requested_at'])) {
    $last = strtotime((string)$member['password_reset_requested_at']);
    if ($last && (time() - $last) < 60) {
        member_response(['ok' => false, 'message' => '인증번호는 1분 후 다시 요청할 수 있습니다.'], 429);
    }
}

$code = (string)random_int(100000, 999999);
$codeHash = password_hash($code, PASSWORD_DEFAULT);
$expiresAt = date('Y-m-d H:i:s', time() + 600);

$pdo->prepare('UPDATE member_accounts SET password_reset_code_hash = ?, password_reset_expires_at = ?, password_reset_requested_at = NOW() WHERE id = ?')
    ->execute([$codeHash, $expiresAt, (int)$member['id']]);

$subject = '[오토지니] 비밀번호 재설정 인증번호';
$body = "안녕하세요, {$member['name']}님.\n\n비밀번호 재설정 인증번호는 {$code} 입니다.\n인증번호는 10분 동안 유효합니다.\n\n본인이 요청하지 않았다면 이 메일을 무시해 주세요.";
$headers = [
    'MIME-Version: 1.0',
    'Content-Type: text/plain; charset=UTF-8',
    'From: Autogenie <no-reply@localhost>',
];

$mailSent = @mail($email, '=?UTF-8?B?' . base64_encode($subject) . '?=', $body, implode("\r\n", $headers));
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
$isLocal = $host === '' || str_contains($host, 'localhost') || str_contains($host, '127.0.0.1');

if (!$mailSent && !$isLocal) {
    $pdo->prepare('UPDATE member_accounts SET password_reset_code_hash = NULL, password_reset_expires_at = NULL WHERE id = ?')
        ->execute([(int)$member['id']]);
    member_response(['ok' => false, 'message' => '인증메일을 발송하지 못했습니다. 서버 메일(SMTP) 설정을 확인해 주세요.'], 500);
}

$response = ['ok' => true, 'message' => $mailSent ? '인증번호를 이메일로 발송했습니다.' : '로컬 테스트 모드입니다.'];
if ($isLocal && !$mailSent) {
    $response['debug_code'] = $code;
}
member_response($response);
