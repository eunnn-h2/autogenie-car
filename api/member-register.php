<?php

declare(strict_types=1);
require_once __DIR__ . '/member-auth-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    member_response(['ok' => false, 'message' => '잘못된 요청입니다.'], 405);
}

$data = member_json_input();
$name = trim((string)($data['name'] ?? ''));
$phone = normalize_member_phone((string)($data['phone'] ?? ''));
$email = strtolower(trim((string)($data['email'] ?? '')));
$password = (string)($data['password'] ?? '');
$passwordConfirm = (string)($data['password_confirm'] ?? '');

if (!preg_match('/^[가-힣ㄱ-ㅎㅏ-ㅣa-zA-Z]+(?:\s[가-힣ㄱ-ㅎㅏ-ㅣa-zA-Z]+)*$/u', $name) || mb_strlen(preg_replace('/\s/u', '', $name) ?? '', 'UTF-8') < 2) {
    member_response(['ok' => false, 'message' => '이름은 한글 또는 영문 2자 이상 입력해 주세요.'], 422);
}
if ($phone === '') {
    member_response(['ok' => false, 'message' => '사용 가능한 휴대폰 번호를 입력해 주세요.'], 422);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
    member_response(['ok' => false, 'message' => '올바른 이메일 주소를 입력해 주세요.'], 422);
}
if (strlen($password) < 8 || strlen($password) > 72) {
    member_response(['ok' => false, 'message' => '비밀번호는 8자 이상 72자 이하로 입력해 주세요.'], 422);
}
if ($password !== $passwordConfirm) {
    member_response(['ok' => false, 'message' => '비밀번호 확인이 일치하지 않습니다.'], 422);
}

try {
    $stmt = $pdo->prepare('SELECT id, email, phone FROM member_accounts WHERE email = ? OR phone = ? LIMIT 1');
    $stmt->execute([$email, $phone]);
    if ($stmt->fetch()) {
        member_response(['ok' => false, 'message' => '이미 가입된 이메일 또는 휴대폰 번호입니다.'], 409);
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO member_accounts (name, phone, email, password_hash) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $phone, $email, $hash]);
    $id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT id, name, phone, email, created_at FROM member_accounts WHERE id = ?');
    $stmt->execute([$id]);
    $member = $stmt->fetch();

    session_regenerate_id(true);
    $_SESSION['member_id'] = $id;
    member_response(['ok' => true, 'member' => member_public($member)]);
} catch (PDOException $e) {
    member_response(['ok' => false, 'message' => '회원 정보를 저장하지 못했습니다.'], 500);
}
