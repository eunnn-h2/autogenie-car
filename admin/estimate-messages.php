<?php
declare(strict_types=1);
// Included only after estimate-detail.php authenticates the administrator.
$source=$isQuick?'QUICK':'DIRECT';
$q=$pdo->prepare('SELECT * FROM ag_estimate_messages WHERE estimate_source=? AND estimate_id=? ORDER BY id DESC LIMIT 10');
$q->execute([$source,$id]);$messageRows=$q->fetchAll();
$contact=agConsultationEstimate($pdo,$source,$id);
$alimtalkConfig=require __DIR__.'/../config/alimtalk.php';
$anyNotificationConfigured=agAlimtalkConfigReady($alimtalkConfig,'receipt')||agAlimtalkConfigReady($alimtalkConfig,'assigned');
$labels=['QUEUED'=>'발송 대기','NOT_CONFIGURED'=>'설정 필요','SENDING'=>'발송 처리 중','REQUESTED'=>'발송 접수','DELIVERED'=>'수신 성공','FAILED'=>'발송 실패','UNKNOWN'=>'발송 이력 확인 필요','CANCELED'=>'발송 취소'];
?>
<section class="section ag-messages"><div class="section-title-row"><h2>고객 카카오톡 안내</h2></div>
<?php if((int)($e['member_id']??0)<1): ?><p>회원 로그인 후 신청한 견적에 알림톡과 마이페이지 상담 연결을 제공합니다.</p>
<?php else: ?>
<?php if($anyNotificationConfigured): ?><p>접수·담당자 배정 시 안내를 발송합니다. ‘발송 접수’는 고객 수신 완료 전 상태입니다.</p>
<?php else: ?><p>알림톡 미설정 상태입니다. 회사 채널과 승인 템플릿을 등록한 뒤 발송할 수 있습니다. 고객 마이페이지의 담당자 확인·전화·카카오 상담 연결은 연락처 등록 후 사용할 수 있습니다.</p><?php endif; ?>
<?php if($contact && $contact['consultant_id'] && (!$contact['consultation_phone']||!$contact['consultation_chat_url'])): ?><p class="ag-message-warning">담당자의 영업용 번호와 카카오 1:1 상담 링크를 계정 관리 또는 내 계정에서 등록해 주세요.</p><?php endif; ?>
<?php if(!$messageRows): ?><p>새로 접수하거나 담당자를 변경한 견적부터 발송 이력이 기록됩니다.</p><?php endif; ?>
<div class="ag-message-list">
<?php foreach($messageRows as $mr): ?><div class="ag-message-row"><div><strong><?=$mr['event_kind']==='receipt'?'견적 접수 안내':'담당자 배정 안내'?></strong><span class="ag-message-state"><?=h($labels[$mr['status']]??$mr['status'])?></span><p><?=h($mr['detail'])?></p><small><?=h($mr['updated_at'])?> · 발송 시도 <?=(int)$mr['attempts']?>회</small></div>
<?php if($canUpdateStatus && (in_array($mr['status'],['QUEUED','NOT_CONFIGURED','FAILED'],true) || ($mr['message_id'] && in_array($mr['status'],['REQUESTED','UNKNOWN'],true)))): ?>
<?php if(!agAlimtalkConfigReady($alimtalkConfig,$mr['event_kind'])): ?><button type="button" disabled title="회사 채널과 승인 템플릿을 등록한 뒤 이용할 수 있습니다.">설정 후 사용</button>
<?php else: ?>
<form method="post"><input type="hidden" name="csrf_token" value="<?=h($_SESSION['estimate_detail_csrf'])?>"><input type="hidden" name="action" value="notification"><input type="hidden" name="message_id" value="<?=(int)$mr['id']?>"><button type="submit"><?=in_array($mr['status'],['REQUESTED','UNKNOWN'],true)?'수신 결과 확인':($mr['status']==='FAILED'?'다시 발송':'발송하기')?></button></form>
<?php endif; ?>
<?php endif; ?></div><?php endforeach; ?></div>
<?php endif; ?></section>
