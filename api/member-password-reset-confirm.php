<?php

declare(strict_types=1);
require_once __DIR__ . '/member-auth-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    member_response(['ok' => false, 'message' => '잘못된 요청입니다.'], 405);
}

$data = member_json_input();
$email = strtolower(trim((string)($data['email'] ?? '')));
$code = preg_replace('/\D+/', '', (string)($data['code'] ?? '')) ?? '';
$password = (string)($data['password'] ?? '');
$passwordConfirm = (string)($data['password_confirm'] ?? '');

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    member_response(['ok' => false, 'message' => '올바른 이메일 주소를 입력해 주세요.'], 422);
}
if (!preg_match('/^\d{6}$/', $code)) {
    member_response(['ok' => false, 'message' => '6자리 인증번호를 입력해 주세요.'], 422);
}
if (strlen($password) < 8 || strlen($password) > 72) {
    member_response(['ok' => false, 'message' => '비밀번호는 8자 이상 72자 이하로 입력해 주세요.'], 422);
}
if ($password !== $passwordConfirm) {
    member_response(['ok' => false, 'message' => '비밀번호 확인이 일치하지 않습니다.'], 422);
}

$stmt = $pdo->prepare('SELECT id, status, password_reset_code_hash, password_reset_expires_at FROM members WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member || ($member['status'] ?? '') !== 'ACTIVE') {
    member_response(['ok' => false, 'message' => '인증정보를 확인해 주세요.'], 400);
}

$hash = (string)($member['password_reset_code_hash'] ?? '');
$expires = strtotime((string)($member['password_reset_expires_at'] ?? ''));
if ($hash === '' || !$expires || $expires < time()) {
    member_response(['ok' => false, 'message' => '인증번호가 만료되었습니다. 새 인증번호를 받아 주세요.'], 410);
}
if (!password_verify($code, $hash)) {
    member_response(['ok' => false, 'message' => '인증번호가 올바르지 않습니다.'], 422);
}

$newHash = password_hash($password, PASSWORD_DEFAULT);
$pdo->prepare('UPDATE members SET password_hash = ?, password_reset_code_hash = NULL, password_reset_expires_at = NULL, password_reset_requested_at = NULL, updated_at = NOW() WHERE id = ?')
    ->execute([$newHash, (int)$member['id']]);

member_response(['ok' => true]);
