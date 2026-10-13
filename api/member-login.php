<?php

declare(strict_types=1);
require_once __DIR__ . '/member-auth-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    member_response(['ok' => false, 'message' => '잘못된 요청입니다.'], 405);
}
$data = member_json_input();
$login = trim((string)($data['login'] ?? $data['email'] ?? ''));
$password = (string)($data['password'] ?? '');
$phone = normalize_member_phone($login);
if ($phone !== '') {
    $stmt = $pdo->prepare('SELECT id, name, phone, email, password_hash, status, created_at FROM member_accounts WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
} else {
    // 변경 전에 가입한 기존 이메일 회원은 계속 이메일/비밀번호로 로그인할 수 있습니다.
    $email = strtolower($login);
    $stmt = $pdo->prepare('SELECT id, name, phone, email, password_hash, status, created_at FROM member_accounts WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
}
$member = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$member || $member['status'] !== 'ACTIVE' || !password_verify($password, (string)$member['password_hash'])) {
    member_response(['ok' => false, 'message' => '휴대폰 번호(또는 이메일)와 비밀번호를 확인해 주세요.'], 401);
}
// 카카오 계정의 임시 전화번호나 랜덤 비밀번호를 통한 사이트 로그인은 허용하지 않습니다.
if (str_starts_with((string)$member['phone'], 'KAKAO-')) {
    member_response(['ok' => false, 'message' => '카카오 계정은 카카오로 로그인해 주세요.'], 401);
}
session_regenerate_id(true);
$_SESSION['member_id'] = (int)$member['id'];
$pdo->prepare('UPDATE member_accounts SET last_login_at = NOW() WHERE id = ?')->execute([(int)$member['id']]);
member_response(['ok' => true, 'member' => member_public($member)]);
