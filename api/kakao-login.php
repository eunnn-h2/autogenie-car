<?php
/** 인가 코드 시작: CSRF 방어 state를 서버 세션에 저장합니다. */
declare(strict_types=1);
require_once __DIR__ . '/kakao-common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'GET') kakao_error('잘못된 접근입니다.', 405);
if (!kakao_config_ready($config)) {
    kakao_error('카카오 테스트 키를 아직 설정하지 않았습니다. config/kakao.local.php 파일에 REST API 키와 클라이언트 시크릿을 입력해 주세요.');
}
$state = bin2hex(random_bytes(32));
$_SESSION['kakao_oauth_state'] = $state;
$_SESSION['kakao_oauth_started_at'] = time();
$url = 'https://kauth.kakao.com/oauth/authorize?' . http_build_query([
    'client_id' => $config['rest_api_key'],
    'redirect_uri' => $config['redirect_uri'],
    'response_type' => 'code',
    'state' => $state,
], '', '&', PHP_QUERY_RFC3986);
header('Cache-Control: no-store');
header('Location: ' . $url, true, 302);
exit;
