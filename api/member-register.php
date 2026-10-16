<?php

declare(strict_types=1);
require_once __DIR__ . '/member-auth-common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    member_response(['ok' => false, 'message' => '잘못된 요청입니다.'], 405);
}
if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'XMLHttpRequest') {
    member_response(['ok' => false, 'message' => '허용되지 않은 요청입니다.'], 403);
}

$data = member_json_input();
$name = trim((string)($data['name'] ?? ''));
$phone = normalize_member_phone((string)($data['phone'] ?? ''));
$password = (string)($data['password'] ?? '');
$passwordConfirm = (string)($data['password_confirm'] ?? '');
if (!in_array($data['privacy_consent'] ?? null, [true, 1, '1'], true)) {
    member_response(['ok' => false, 'message' => '개인정보 수집·이용 동의 후 가입해 주세요.'], 422);
}

if (!preg_match('/^[가-힣ㄱ-ㅎㅏ-ㅣa-zA-Z]+(?:\s[가-힣ㄱ-ㅎㅏ-ㅣa-zA-Z]+)*$/u', $name)
    || mb_strlen(preg_replace('/\s/u', '', $name) ?? '', 'UTF-8') < 2) {
    member_response(['ok' => false, 'message' => '이름은 한글 또는 영문 2자 이상 입력해 주세요.'], 422);
}
if ($phone === '') {
    member_response(['ok' => false, 'message' => '사용 가능한 휴대폰 번호를 입력해 주세요.'], 422);
}
if (strlen($password) < 8 || strlen($password) > 72) {
    member_response(['ok' => false, 'message' => '비밀번호는 8자 이상 72자 이하로 입력해 주세요.'], 422);
}
if ($password !== $passwordConfirm) {
    member_response(['ok' => false, 'message' => '비밀번호 확인이 일치하지 않습니다.'], 422);
}
// 반드시 서버 세션에서 인증 완료 여부를 확인합니다. 브라우저의 인증 상태는 신뢰하지 않습니다.
$verifiedPhone = (string)($_SESSION['signup_phone_verified'] ?? '');
$verifiedAt = (int)($_SESSION['signup_phone_verified_at'] ?? 0);
if ($verifiedPhone === '' || !hash_equals($verifiedPhone, $phone)
    || $verifiedAt < time() - 900 || $verifiedAt > time() + 30) {
    member_response(['ok' => false, 'message' => '휴대폰 인증을 완료한 후 가입해 주세요.'], 403);
}

try {
    $stmt = $pdo->prepare('SELECT id FROM member_accounts WHERE phone = ? LIMIT 1');
    $stmt->execute([$phone]);
    if ($stmt->fetchColumn()) {
        member_response(['ok' => false, 'message' => '이미 가입된 휴대폰 번호입니다. 기존 계정으로 로그인해 주세요.'], 409);
    }

    // 기존 DB에서 email은 NOT NULL/UNIQUE이므로 사이트 내부용 불가역 임시 식별자를 사용합니다.
    // 실제 이메일 인증/발송 대상으로 사용하면 안 됩니다.
    $placeholderEmail = 'mobile-' . bin2hex(random_bytes(16)) . '@accounts.invalid';
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO member_accounts (name, phone, email, password_hash) VALUES (?, ?, ?, ?)');
    $stmt->execute([$name, $phone, $placeholderEmail, $hash]);
    $id = (int)$pdo->lastInsertId();

    $stmt = $pdo->prepare('SELECT id, name, phone, email, created_at FROM member_accounts WHERE id = ?');
    $stmt->execute([$id]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    unset($_SESSION['signup_phone_verified'], $_SESSION['signup_phone_verified_at'],
        $_SESSION['signup_phone_code_hash'], $_SESSION['signup_phone_code_expires_at']);
    session_regenerate_id(true);
    $_SESSION['member_auth_stamp']=hash('sha256',$hash);
    $_SESSION['member_id'] = $id;
    member_response(['ok' => true, 'member' => member_public($member)]);
} catch (PDOException $e) {
    if ((string)$e->getCode() === '23000') {
        member_response(['ok' => false, 'message' => '이미 가입된 휴대폰 번호입니다. 기존 계정으로 로그인해 주세요.'], 409);
    }
    error_log('[AutoGenie member registration] ' . $e->getMessage());
    member_response(['ok' => false, 'message' => '회원 정보를 저장하지 못했습니다.'], 500);
}
