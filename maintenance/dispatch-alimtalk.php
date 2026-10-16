<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/../config/consultation.php';
// Never resend an interrupted or ambiguous send. Check the provider console instead.
$pdo->exec("UPDATE ag_estimate_messages SET status='UNKNOWN',detail='처리 중단 · SENS 발송 이력 확인 필요',updated_at=NOW() WHERE status='SENDING' AND updated_at < NOW()-INTERVAL 5 MINUTE");
$rows=$pdo->query("SELECT id,status FROM ag_estimate_messages WHERE status IN ('QUEUED','NOT_CONFIGURED','REQUESTED') ORDER BY updated_at LIMIT 50")->fetchAll();
foreach($rows as $row){try{if($row['status']==='REQUESTED')agAlimtalkRefresh($pdo,(int)$row['id']);else agAlimtalkProcess($pdo,(int)$row['id']);}catch(Throwable $e){error_log('AutoGenie notification worker: processing failed');}}
echo "Processed ".count($rows)." notification records.\n";
