<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('서버 내부 실행만 허용됩니다.');}
require_once __DIR__.'/../config/database.php';
echo "DB 항목 확장을 완료했습니다. 기존 데이터는 유지됩니다.\n";
