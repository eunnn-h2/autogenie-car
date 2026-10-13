<?php
/** 카카오 REST API 서버 설정. 실제 키는 kakao.local.php 또는 환경변수에만 저장합니다. */
declare(strict_types=1);
$localFile = __DIR__ . '/kakao.local.php';
$local = is_file($localFile) ? require $localFile : [];
if (!is_array($local)) $local = [];
return [
    'rest_api_key' => (string)($local['rest_api_key'] ?? getenv('KAKAO_REST_API_KEY') ?: ''),
    'client_secret' => (string)($local['client_secret'] ?? getenv('KAKAO_CLIENT_SECRET') ?: ''),
    'redirect_uri' => (string)($local['redirect_uri'] ?? getenv('KAKAO_REDIRECT_URI') ?: 'http://localhost/autogenie-car/api/kakao-callback.php'),
];
