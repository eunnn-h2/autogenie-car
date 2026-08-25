<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

$pdo->exec("CREATE TABLE IF NOT EXISTS customer_inquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    inquiry_no VARCHAR(32) NOT NULL,
    member_id BIGINT UNSIGNED NULL,
    guest_key VARCHAR(64) NULL,
    member_name VARCHAR(100) NULL,
    member_email VARCHAR(190) NULL,
    member_phone VARCHAR(30) NULL,
    message TEXT NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'NEW',
    answer TEXT NULL,
    answered_by BIGINT UNSIGNED NULL,
    answered_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id), UNIQUE KEY uq_customer_inquiries_no (inquiry_no), UNIQUE KEY uq_customer_inquiries_legacy (legacy_id),
    KEY idx_customer_inquiries_member (member_id, created_at),
    KEY idx_customer_inquiries_guest (guest_key, created_at),
    KEY idx_customer_inquiries_status (status, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$legacyColumn = $pdo->query("SHOW COLUMNS FROM customer_inquiries LIKE 'legacy_id'")->fetch();
if (!$legacyColumn) $pdo->exec("ALTER TABLE customer_inquiries ADD COLUMN legacy_id VARCHAR(80) NULL AFTER inquiry_no");
$legacyIndex = $pdo->query("SHOW INDEX FROM customer_inquiries WHERE Key_name = 'uq_customer_inquiries_legacy'")->fetch();
if (!$legacyIndex) $pdo->exec("ALTER TABLE customer_inquiries ADD UNIQUE KEY uq_customer_inquiries_legacy (legacy_id)");

$status = strtoupper(trim((string)($_GET['status'] ?? '')));
$q = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = [];
if (in_array($status, ['NEW','ANSWERED'], true)) { $where[] = 'i.status = ?'; $params[] = $status; }
if ($q !== '') {
    $where[] = '(i.inquiry_no LIKE ? OR i.member_name LIKE ? OR i.member_email LIKE ? OR i.member_phone LIKE ? OR i.message LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}
$sql = "SELECT i.*, a.name AS answered_admin_name, a.username AS answered_admin_username
        FROM customer_inquiries i
        LEFT JOIN admin_accounts a ON a.id = i.answered_by" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY i.id DESC LIMIT 500";
try {
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    $sql = "SELECT i.*, NULL AS answered_admin_name, NULL AS answered_admin_username FROM customer_inquiries i" . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY i.id DESC LIMIT 500";
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
}
$newCount = (int)$pdo->query("SELECT COUNT(*) FROM customer_inquiries WHERE status='NEW'")->fetchColumn();
$answeredCount = (int)$pdo->query("SELECT COUNT(*) FROM customer_inquiries WHERE status='ANSWERED'")->fetchColumn();
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>문의 관리 - 오토지니</title><link rel="stylesheet" href="./sidebar.css"><style>
*{box-sizing:border-box}body{margin:0;font-family:Pretendard,"Noto Sans KR",Arial,sans-serif;background:#eef5f8;color:#25384a;font-size:13px}.layout{display:grid;grid-template-columns:228px minmax(0,1fr);min-height:100vh}.main{padding:28px}.head{display:flex;justify-content:space-between;align-items:end;gap:20px;margin-bottom:18px}.head h1{margin:0;font-size:24px}.head p{margin:6px 0 0;color:#80919c}.summary{display:flex;gap:8px}.badge-count{padding:9px 12px;border:1px solid #d8e2e7;border-radius:8px;background:#fff;color:#667984}.badge-count b{color:#3924b9;margin-left:5px}.filters{display:flex;gap:8px;align-items:center;margin-bottom:14px;padding:14px;background:#fff;border:1px solid #d8e2e7;border-radius:8px}.filters input{min-width:260px;height:38px;padding:0 12px;border:1px solid #d8e2e7;border-radius:6px}.filters select,.filters button{height:38px;padding:0 12px;border:1px solid #d8e2e7;border-radius:6px;background:#fff}.filters button{background:#3924b9;color:#fff;border-color:#3924b9;font-weight:700}.list{display:grid;gap:12px}.item{background:#fff;border:1px solid #d8e2e7;border-radius:10px;overflow:hidden}.item-head{display:flex;justify-content:space-between;gap:16px;padding:15px 17px;border-bottom:1px solid #edf1f3}.meta{display:flex;flex-wrap:wrap;gap:7px 14px;color:#788b98;font-size:12px}.meta strong{color:#263c4d}.status{display:inline-flex;align-items:center;height:24px;padding:0 8px;border-radius:999px;font-size:11px;font-weight:800}.status.new{background:#fff1e8;color:#ef641d}.status.answered{background:#ebf8ef;color:#39714b}.item-body{display:grid;grid-template-columns:minmax(0,1fr) minmax(320px,.85fr);gap:0}.question{padding:18px;border-right:1px solid #edf1f3}.question h3,.reply h3{margin:0 0 10px;font-size:13px}.message{margin:0;white-space:pre-wrap;word-break:break-word;font-size:14px;line-height:1.7;color:#273c4c}.reply{padding:18px;background:#fbfcfd}.reply textarea{width:100%;min-height:112px;padding:12px;border:1px solid #d8e2e7;border-radius:7px;resize:vertical;font:inherit;line-height:1.6;outline:0}.reply textarea:focus{border-color:#3924b9;box-shadow:0 0 0 3px rgba(57,36,185,.08)}.reply-foot{display:flex;justify-content:space-between;align-items:center;gap:10px;margin-top:10px}.reply-note{font-size:11px;color:#8a9aa4}.save{height:38px;padding:0 14px;border:0;border-radius:6px;background:#3924b9;color:#fff;font-weight:800;cursor:pointer}.empty{padding:50px;text-align:center;background:#fff;border:1px solid #d8e2e7;border-radius:8px;color:#84949e}.alert{margin-bottom:12px;padding:11px 13px;border-radius:7px;background:#ebf8ef;color:#39714b}@media(max-width:900px){.layout{grid-template-columns:1fr}.admin-sidebar{display:none}.main{padding:14px}.item-body{grid-template-columns:1fr}.question{border-right:0;border-bottom:1px solid #edf1f3}.head{align-items:flex-start;flex-direction:column}.filters{flex-wrap:wrap}.filters input{min-width:0;flex:1}}</style></head><body><div class="layout">
<?php $currentAdminPage = 'inquiries'; require __DIR__ . '/sidebar.php'; ?>
<main class="main"><div class="head"><div><h1>문의 관리</h1><p>사용자가 Q&amp;A에서 남긴 문의를 확인하고 답변을 등록합니다.</p></div><div class="summary"><span class="badge-count">미답변 <b><?=number_format($newCount)?></b></span><span class="badge-count">답변완료 <b><?=number_format($answeredCount)?></b></span></div></div>
<?php if(isset($_GET['saved'])):?><div class="alert">답변이 저장되었습니다. 사용자 마이페이지 문의 내역에 바로 표시됩니다.</div><?php endif;?>
<form class="filters" method="get"><input type="search" name="q" value="<?=h($q)?>" placeholder="문의번호, 이름, 이메일, 연락처, 문의내용 검색"><select name="status"><option value="">전체 상태</option><option value="NEW" <?=$status==='NEW'?'selected':''?>>미답변</option><option value="ANSWERED" <?=$status==='ANSWERED'?'selected':''?>>답변완료</option></select><button type="submit">검색</button><a href="./inquiries.php" style="color:#72838e">초기화</a></form>
<div class="list"><?php foreach($rows as $r): ?><article class="item" id="inquiry-<?= (int)$r['id'] ?>"><div class="item-head"><div class="meta"><strong><?=h($r['inquiry_no'])?></strong><span><?=h($r['member_name'] ?: '비회원')?></span><?php if($r['member_email']):?><span><?=h($r['member_email'])?></span><?php endif;?><?php if($r['member_phone']):?><span><?=h($r['member_phone'])?></span><?php endif;?><span><?=h($r['created_at'])?></span></div><span class="status <?=$r['status']==='ANSWERED'?'answered':'new'?>"><?=$r['status']==='ANSWERED'?'답변완료':'미답변'?></span></div><div class="item-body"><div class="question"><h3>문의 내용</h3><p class="message"><?=h($r['message'])?></p></div><form class="reply" action="./inquiry-actions.php" method="post"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><h3>관리자 답변</h3><textarea name="answer" maxlength="3000" required placeholder="사용자에게 전달할 답변을 입력해 주세요."><?=h($r['answer'] ?? '')?></textarea><div class="reply-foot"><span class="reply-note"><?php if($r['answered_at']):?>마지막 답변 <?=h($r['answered_at'])?><?php if($r['answered_admin_name'] || $r['answered_admin_username']):?> · <?=h($r['answered_admin_name'] ?: $r['answered_admin_username'])?><?php endif;?><?php else:?>답변 등록 시 사용자 마이페이지에 표시됩니다.<?php endif;?></span><button class="save" type="submit"><?=$r['answer']?'답변 수정':'답변 등록'?></button></div></form></div></article><?php endforeach;?><?php if(!$rows):?><div class="empty">조건에 맞는 문의 내역이 없습니다.</div><?php endif;?></div>
</main></div></body></html>
