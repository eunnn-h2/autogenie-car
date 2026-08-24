<?php

declare(strict_types=1);
require_once __DIR__ . '/member-auth-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    member_response(['ok' => false, 'message' => '잘못된 요청입니다.'], 405);
}

$data = member_json_input();
$email = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');

$stmt = $pdo->prepare('SELECT id, name, phone, email, password_hash, status, created_at FROM member_accounts WHERE email = ? LIMIT 1');
$stmt->execute([$email]);
$member = $stmt->fetch();

if (!$member || $member['status'] !== 'ACTIVE' || !password_verify($password, $member['password_hash'])) {
    member_response(['ok' => false, 'message' => '이메일 또는 비밀번호를 확인해 주세요.'], 401);
}

session_regenerate_id(true);
$_SESSION['member_id'] = (int)$member['id'];
$pdo->prepare('UPDATE member_accounts SET last_login_at = NOW() WHERE id = ?')->execute([(int)$member['id']]);
member_response(['ok' => true, 'member' => member_public($member)]);
