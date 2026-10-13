<?php
/** 인가 코드 -> 토큰 -> 사용자 고유 ID -> 자체 로그인 세션. */
declare(strict_types=1);
require_once __DIR__ . '/kakao-common.php';
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'GET') kakao_error('잘못된 접근입니다.', 405);
$storedState = (string)($_SESSION['kakao_oauth_state'] ?? '');
$startedAt = (int)($_SESSION['kakao_oauth_started_at'] ?? 0);
unset($_SESSION['kakao_oauth_state'], $_SESSION['kakao_oauth_started_at']);
$state = (string)($_GET['state'] ?? '');
if ($storedState === '' || $state === '' || !hash_equals($storedState, $state) ||
    $startedAt < time() - 600 || $startedAt > time() + 30) {
    kakao_error('로그인 요청이 만료되었거나 인증 상태가 일치하지 않습니다. 다시 시도해 주세요.', 400);
}
if (isset($_GET['error'])) {
    header('Location: ../db-test.html?kakao_login=cancel', true, 302);
    exit;
}
$code = (string)($_GET['code'] ?? '');
if ($code === '' || strlen($code) > 2048 || !kakao_config_ready($config)) {
    kakao_error('로그인 설정 또는 인가 코드를 확인해 주세요.', 400);
}
try {
    $token = kakao_http_json('https://kauth.kakao.com/oauth/token', [
        'grant_type' => 'authorization_code',
        'client_id' => $config['rest_api_key'],
        'redirect_uri' => $config['redirect_uri'],
        'code' => $code,
        'client_secret' => $config['client_secret'],
    ]);
    $accessToken = (string)($token['access_token'] ?? '');
    if ($accessToken === '') throw new RuntimeException('카카오 인증 토큰이 없습니다.');
    $profile = kakao_http_json('https://kapi.kakao.com/v2/user/me', null, $accessToken);
    // 숫자형 카카오 ID는 문자열로 저장: 서로 다른 앱에 동일 ID가 있더라도 앱별로 분리합니다.
    $userId = (string)($profile['id'] ?? '');
    if (!preg_match('/^[0-9]{1,32}$/', $userId)) throw new RuntimeException('카카오 사용자 식별값이 올바르지 않습니다.');
    $nickname = trim((string)($profile['kakao_account']['profile']['nickname'] ?? $profile['properties']['nickname'] ?? ''));
    if ($nickname === '') $nickname = '카카오 회원';
    $nickname = mb_substr($nickname, 0, 30, 'UTF-8');

    kakao_member_tables($pdo);
    $appHash = hash('sha256', $config['rest_api_key']);
    $pdo->beginTransaction();
    $find = $pdo->prepare('SELECT member_id FROM member_kakao_accounts WHERE kakao_app_hash = ? AND kakao_user_id = ? LIMIT 1 FOR UPDATE');
    $find->execute([$appHash, $userId]);
    $id = (int)($find->fetchColumn() ?: 0);
    if ($id === 0) {
        // 기존 회원과 이메일/전화번호로 자동 병합하지 않습니다. 임시 연락처는 상담용으로 사용하지 않습니다.
        $identity = hash('sha256', $appHash . ':' . $userId);
        $email = 'kakao-' . substr($identity, 0, 32) . '@accounts.invalid';
        $phone = 'KAKAO-' . substr($identity, 0, 24);
        $passwordHash = password_hash(bin2hex(random_bytes(32)), PASSWORD_DEFAULT);
        $create = $pdo->prepare('INSERT INTO member_accounts (name, phone, email, password_hash) VALUES (?, ?, ?, ?)');
        $create->execute([$nickname, $phone, $email, $passwordHash]);
        $id = (int)$pdo->lastInsertId();
        $link = $pdo->prepare('INSERT INTO member_kakao_accounts (kakao_app_hash, kakao_user_id, member_id) VALUES (?, ?, ?)');
        $link->execute([$appHash, $userId, $id]);
    }
    $memberQuery = $pdo->prepare('SELECT id, name, phone, email, status, created_at FROM member_accounts WHERE id = ? LIMIT 1');
    $memberQuery->execute([$id]);
    $member = $memberQuery->fetch();
    if (!$member || $member['status'] !== 'ACTIVE') {
        throw new RuntimeException('이 계정은 현재 로그인할 수 없습니다.');
    }
    $pdo->prepare('UPDATE member_accounts SET last_login_at = NOW() WHERE id = ?')->execute([$id]);
    $pdo->commit();
    session_regenerate_id(true);
    $_SESSION['member_id'] = $id;
    header('Location: ../db-test.html', true, 302);
    exit;
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[AutoGenie Kakao OAuth] ' . $error->getMessage());
    header('Location: ../db-test.html?kakao_login=error', true, 302);
    exit;
}
