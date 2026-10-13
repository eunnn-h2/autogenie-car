<?php
/** Naver Cloud SENS SMS v2 config. Keys must remain server-only. */
declare(strict_types=1);
$path = __DIR__ . '/sms.local.php';
$local = is_file($path) ? require $path : [];
if (!is_array($local)) $local = [];
return [
    'service_id' => (string)($local['service_id'] ?? getenv('NAVER_SENS_SERVICE_ID') ?: ''),
    'access_key' => (string)($local['access_key'] ?? getenv('NAVER_SENS_ACCESS_KEY') ?: ''),
    'secret_key' => (string)($local['secret_key'] ?? getenv('NAVER_SENS_SECRET_KEY') ?: ''),
    'sender' => (string)($local['sender'] ?? getenv('NAVER_SENS_SENDER') ?: ''),
    'local_debug' => (bool)($local['local_debug'] ?? false),
];
