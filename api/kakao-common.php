<?php
/** 카카오 계정 인증용 공통 처리 (브라우저에 키/토큰을 전달하지 않음). */
declare(strict_types=1);
require_once __DIR__ . '/member-auth-common.php';
$config = require __DIR__ . '/../config/kakao.php';

function kakao_config_ready(array $config): bool
{
    return $config['rest_api_key'] !== '' && $config['client_secret'] !== '' &&
        filter_var($config['redirect_uri'], FILTER_VALIDATE_URL) !== false;
}

function kakao_error(string $message, int $status = 500): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="ko"><meta charset="utf-8"><title>카카오 로그인 설정</title>';
    echo '<body style="font:16px/1.7 sans-serif;max-width:540px;margin:60px auto;padding:20px">';
    echo '<h2>카카오 로그인 안내</h2><p>' . htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    echo '<a href="../db-test.html">사이트로 돌아가기</a></body></html>';
    exit;
}

function kakao_http_json(string $url, ?array $post = null, ?string $accessToken = null): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL 확장이 필요합니다.');
    }
    $ch = curl_init($url);
    if ($ch === false) throw new RuntimeException('카카오 서버 연결을 준비하지 못했습니다.');
    $headers = ['Accept: application/json'];
    if ($post !== null) {
        $headers[] = 'Content-Type: application/x-www-form-urlencoded;charset=utf-8';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post, '', '&', PHP_QUERY_RFC3986));
    }
    if ($accessToken !== null) $headers[] = 'Authorization: Bearer ' . $accessToken;
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = is_string($response) ? json_decode($response, true) : null;
    if ($status < 200 || $status >= 300 || !is_array($data)) {
        throw new RuntimeException('카카오 인증 서버의 응답을 확인하지 못했습니다.');
    }
    return $data;
}

function kakao_member_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS member_kakao_accounts (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        kakao_app_hash CHAR(64) NOT NULL,
        kakao_user_id VARCHAR(32) NOT NULL,
        member_id BIGINT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_kakao_identity (kakao_app_hash, kakao_user_id),
        UNIQUE KEY uq_kakao_member (member_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
