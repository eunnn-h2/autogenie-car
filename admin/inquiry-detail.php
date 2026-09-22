<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
requireAdminCategory('inquiries');
require_once __DIR__ . '/../config/database.php';

function detailH(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
if (!$id) {
    header('Location: ./inquiries.php');
    exit;
}

// Query parameters are only used to restore the inquiry list filters.
$backParams = [];
parse_str((string)($_GET['return_query'] ?? ''), $backParams);
$backParams = array_intersect_key($backParams, array_flip(['status','q','from','to','member_type','per_page','page']));
foreach ($backParams as $key => $value) {
    if (!is_scalar($value)) unset($backParams[$key]);
}
$returnQuery = http_build_query($backParams);
$backUrl = './inquiries.php' . ($returnQuery !== '' ? '?' . $returnQuery : '');

try {
    $stmt = $pdo->prepare('SELECT i.*, a.name AS answered_admin_name, a.username AS answered_admin_username
        FROM customer_inquiries i
        LEFT JOIN admin_accounts a ON a.id = i.answered_by
        WHERE i.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stmt = $pdo->prepare('SELECT i.*, NULL AS answered_admin_name, NULL AS answered_admin_username
        FROM customer_inquiries i WHERE i.id = ? LIMIT 1');
    $stmt->execute([$id]);
    $inquiry = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$inquiry) {
    http_response_code(404);
    exit('해당 문의를 찾을 수 없습니다. <a href="./inquiries.php">목록으로</a>');
}
$isAnswered = (string)$inquiry['status'] === 'ANSWERED';
$answeredBy = trim((string)($inquiry['answered_admin_name'] ?: $inquiry['answered_admin_username'] ?: ''));
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>고객문의 상세 - 오토지니</title>
<link rel="stylesheet" href="./sidebar.css">
<style>
*{box-sizing:border-box}body{margin:0;font:14px Pretendard,"Noto Sans KR",Arial,sans-serif;background:#f4f7fb;color:#25384a}a{text-decoration:none;color:inherit}.layout{display:grid;grid-template-columns:228px minmax(0,1fr);min-height:100vh}.main{padding:28px;min-width:0}.page-header{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:16px}.page-header h1{font-size:23px;line-height:1.35;margin:0}.page-header p{color:#83919d;margin:6px 0 0}.btn{display:inline-flex;align-items:center;justify-content:center;min-height:37px;padding:0 14px;border:1px solid #d3dce6;border-radius:7px;background:#fff;color:#3a4d5c;font:inherit;font-weight:750;cursor:pointer;white-space:nowrap}.btn.primary{background:#3924b9;color:#fff;border-color:#3924b9}.btn.danger{color:#ca273c;border-color:#f0c7cd}.buttons{display:flex;flex-wrap:wrap;gap:7px}.panel{background:#fff;border:1px solid #dde5ed;border-radius:10px;padding:20px;margin-bottom:14px}.panel h2{margin:0 0 16px;font-size:16px}.info-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:0;border:1px solid #e2e8ef;border-radius:7px;overflow:hidden}.info{display:grid;grid-template-columns:126px minmax(0,1fr);border-bottom:1px solid #e2e8ef;min-width:0}.info:nth-last-child(-n+2){border-bottom:0}.info:nth-child(odd){border-right:1px solid #e2e8ef}.info .key{padding:13px;background:#f8fafd;color:#6d8091;font-weight:700}.info .value{padding:13px;min-width:0;overflow-wrap:anywhere;font-weight:650}.state{display:inline-flex;align-items:center;min-height:25px;border-radius:5px;padding:3px 9px;font-size:12px;font-weight:800}.state.new{background:#fff0ea;color:#dc572e}.state.done{background:#eaf7ee;color:#258148}.message{padding:17px;border:1px solid #e2e8ef;border-radius:7px;line-height:1.75;white-space:pre-wrap;overflow-wrap:anywhere;background:#fcfdff}.field-label{display:block;margin-bottom:8px;font-size:13px;font-weight:800}.answer{display:block;width:100%;min-height:185px;padding:13px;border:1px solid #cbd7e1;border-radius:7px;resize:vertical;font:inherit;line-height:1.7;color:#25384a}.answer:focus{outline:0;border-color:#3924b9;box-shadow:0 0 0 2px #eeeaff}.form-foot{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:13px}.hint{color:#8493a2;font-size:12px;line-height:1.5}.notice{padding:12px 15px;border:1px solid #d4e8dc;background:#edf9f1;color:#267644;border-radius:7px;margin-bottom:14px}.status-tools{display:flex;gap:8px;flex-wrap:wrap;border-top:1px solid #ebeff4;padding-top:15px;margin-top:16px}.meta{font-size:12px;color:#7e8e9c;margin-top:9px}form.inline{display:inline-flex;margin:0}.delete-note{margin-top:8px;font-size:12px;color:#8796a4}@media(max-width:850px){.layout{display:block}.admin-sidebar{display:none}.main{padding:14px}.info-grid{grid-template-columns:1fr}.info{grid-template-columns:105px minmax(0,1fr)!important;border-right:0!important;border-bottom:1px solid #e2e8ef!important}.info:last-child{border-bottom:0!important}.page-header{flex-direction:column}.form-foot{align-items:flex-start;flex-direction:column}.buttons{width:100%}}
</style>
<link rel="stylesheet" href="./admin-ui.css">
</head>
<body><div class="layout">
<?php $currentAdminPage='inquiries'; require __DIR__ . '/sidebar.php'; ?>
<main class="main">
<header class="page-header"><div><h1>고객문의 상세</h1><p>문의 내용을 확인하고 답변을 등록하거나 수정합니다.</p></div><a class="btn" href="<?=detailH($backUrl)?>">목록으로</a></header>
<?php if(isset($_GET['saved'])):?><div class="notice">답변이 저장되었습니다. 고객 문의내역에도 반영됩니다.</div><?php endif;?>
<?php if(isset($_GET['updated'])):?><div class="notice">문의 처리상태가 변경되었습니다.</div><?php endif;?>
<section class="panel"><h2>문의 정보</h2><div class="info-grid">
<div class="info"><span class="key">문의번호</span><span class="value"><?=detailH($inquiry['inquiry_no'])?></span></div>
<div class="info"><span class="key">상태</span><span class="value"><span class="state <?=$isAnswered?'done':'new'?>"><?=$isAnswered?'답변완료':'미처리'?></span></span></div>
<div class="info"><span class="key">작성자</span><span class="value"><?=detailH($inquiry['member_name']?:'비회원')?></span></div>
<div class="info"><span class="key">작성구분</span><span class="value"><?=$inquiry['member_id']===null?'비회원':'회원'?></span></div>
<div class="info"><span class="key">연락처</span><span class="value"><?=detailH($inquiry['member_phone']?:'미등록')?></span></div>
<div class="info"><span class="key">이메일</span><span class="value"><?=detailH($inquiry['member_email']?:'미등록')?></span></div>
<div class="info"><span class="key">접수일</span><span class="value"><?=detailH($inquiry['created_at'])?></span></div>
<div class="info"><span class="key">답변일</span><span class="value"><?=detailH($inquiry['answered_at']?:'-')?></span></div>
</div></section>
<section class="panel"><h2>고객 문의 내용</h2><div class="message"><?=detailH($inquiry['message'])?></div></section>
<section class="panel"><h2>관리자 답변</h2>
<form method="post" action="./inquiry-actions.php" id="replyForm">
<input type="hidden" name="action" value="save_answer"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="detail_id" value="<?=$id?>"><input type="hidden" name="return_query" value="<?=detailH($returnQuery)?>">
<label class="field-label" for="answer">답변 내용</label><textarea class="answer" id="answer" name="answer" maxlength="3000" required placeholder="고객에게 전달할 답변을 작성해 주세요."><?=detailH($inquiry['answer']??'')?></textarea>
<div class="form-foot"><span class="hint">답변을 저장하면 답변완료 상태로 변경되며 고객의 문의내역에 반영됩니다.</span><div class="buttons"><a href="<?=detailH($backUrl)?>" class="btn">취소</a><button type="submit" class="btn primary"><?=$inquiry['answer']?'답변 수정·저장':'답변 등록'?></button></div></div>
<?php if($inquiry['answered_at']):?><div class="meta">마지막 답변: <?=detailH($inquiry['answered_at'])?><?=$answeredBy!==''?' · '.detailH($answeredBy):''?></div><?php endif;?>
</form>
<div class="status-tools">
<?php if($isAnswered):?>
<form class="inline" method="post" action="./inquiry-actions.php" onsubmit="return confirm('이 문의를 미처리 상태로 변경할까요?');"><input type="hidden" name="action" value="bulk"><input type="hidden" name="bulk_action" value="mark_new"><input type="hidden" name="ids[]" value="<?=$id?>"><input type="hidden" name="detail_id" value="<?=$id?>"><input type="hidden" name="return_query" value="<?=detailH($returnQuery)?>"><button type="submit" class="btn">미처리로 변경</button></form>
<?php endif;?>
<form class="inline" method="post" action="./inquiry-actions.php" onsubmit="return confirm('이 문의를 삭제할까요? 삭제 후 복구할 수 없습니다.');"><input type="hidden" name="action" value="bulk"><input type="hidden" name="bulk_action" value="delete"><input type="hidden" name="ids[]" value="<?=$id?>"><input type="hidden" name="return_query" value="<?=detailH($returnQuery)?>"><button type="submit" class="btn danger">문의 삭제</button></form>
</div><p class="delete-note">원본 문의 내용과 작성자 정보는 이 화면에서 변경하지 않습니다.</p></section>
</main></div></body></html>
