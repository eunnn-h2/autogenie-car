<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
requireAdminCategory('inquiries');
require_once __DIR__ . '/../config/database.php';

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function qstr(array $changes = []): string {
    $q = $_GET;
    foreach ($changes as $k => $v) {
        if ($v === null || $v === '') unset($q[$k]); else $q[$k] = $v;
    }
    return http_build_query($q);
}

$pdo->exec("CREATE TABLE IF NOT EXISTS customer_inquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    inquiry_no VARCHAR(32) NOT NULL,
    legacy_id VARCHAR(80) NULL,
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
    PRIMARY KEY (id),
    UNIQUE KEY uq_customer_inquiries_no (inquiry_no),
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
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$memberType = trim((string)($_GET['member_type'] ?? ''));
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, [20,50,100], true)) $perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$openId = max(0, (int)($_GET['open'] ?? 0));
if ($openId > 0) {
    $returnQuery = qstr(['open'=>null, 'saved'=>null, 'bulk'=>null, 'error'=>null]);
    header('Location: ./inquiry-detail.php?' . http_build_query(['id'=>$openId, 'return_query'=>$returnQuery]));
    exit;
}

$params = [];
$where = [];
if (in_array($status, ['NEW','ANSWERED'], true)) { $where[] = 'i.status = ?'; $params[] = $status; }
if ($memberType === 'member') $where[] = 'i.member_id IS NOT NULL';
if ($memberType === 'guest') $where[] = 'i.member_id IS NULL';
if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) { $where[] = 'i.created_at >= ?'; $params[] = $from . ' 00:00:00'; }
if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) { $where[] = 'i.created_at <= ?'; $params[] = $to . ' 23:59:59'; }
if ($q !== '') {
    $where[] = '(i.inquiry_no LIKE ? OR i.member_name LIKE ? OR i.member_email LIKE ? OR i.member_phone LIKE ? OR i.message LIKE ? OR i.answer LIKE ?)';
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like, $like);
}
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM customer_inquiries i' . $whereSql);
$countStmt->execute($params);
$searchCount = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($searchCount / $perPage));
if ($page > $totalPages) $page = $totalPages;
$offset = ($page - 1) * $perPage;

$sql = "SELECT i.*, a.name AS answered_admin_name, a.username AS answered_admin_username
        FROM customer_inquiries i
        LEFT JOIN admin_accounts a ON a.id = i.answered_by" . $whereSql . " ORDER BY i.id DESC LIMIT {$perPage} OFFSET {$offset}";
try {
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
} catch (PDOException $e) {
    $sql = "SELECT i.*, NULL AS answered_admin_name, NULL AS answered_admin_username FROM customer_inquiries i" . $whereSql . " ORDER BY i.id DESC LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql); $stmt->execute($params); $rows = $stmt->fetchAll();
}

$newCount = (int)$pdo->query("SELECT COUNT(*) FROM customer_inquiries WHERE status='NEW'")->fetchColumn();
$answeredCount = (int)$pdo->query("SELECT COUNT(*) FROM customer_inquiries WHERE status='ANSWERED'")->fetchColumn();
$totalCount = (int)$pdo->query("SELECT COUNT(*) FROM customer_inquiries")->fetchColumn();
$todayCount = (int)$pdo->query("SELECT COUNT(*) FROM customer_inquiries WHERE created_at >= CURDATE() AND created_at < DATE_ADD(CURDATE(), INTERVAL 1 DAY)")->fetchColumn();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>고객 문의 - 오토지니</title>
<link rel="stylesheet" href="./sidebar.css">
<style>
*{box-sizing:border-box}body{margin:0;font-family:Pretendard,"Noto Sans KR",Arial,sans-serif;background:#eef5f8;color:#25384a;font-size:14px}a{text-decoration:none;color:inherit}.layout{display:grid;grid-template-columns:228px minmax(0,1fr);min-height:100vh}.main{padding:28px}.card{background:#fff;border:1px solid #d8e2e7;padding:18px}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:16px}.top h1{font-size:20px;margin:0}.top p{margin:5px 0 0;color:#84949e}.top-stats{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.stat{display:inline-flex;align-items:center;gap:5px;height:32px;padding:0 10px;background:#f7f9fb;border:1px solid #e0e6ea;border-radius:5px;color:#667a88;font-size:14px}.stat b{color:#3924b9;font-size:14px}.stat.alert{background:#fff7f4;border-color:#f2d4ca}.stat.alert b{color:#e3472f}
.filter-box{margin-bottom:14px;padding:14px;background:#f7f9fb;border:1px solid #e0e6ea}.filter-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.filter-item{min-width:0}.filter-item.search{grid-column:span 2}.filter-label{display:block;margin:0 0 6px;color:#657986;font-size:14px;font-weight:700}.filter-control{display:flex;gap:7px;align-items:center;min-width:0}.filter-control input[type=date],.filter-control input[type=search],.filter-control select{width:100%;height:36px;border:1px solid #c7d2d9;border-radius:4px;padding:0 9px;background:#fff;color:#334957}.filter-control input[type=search]{min-width:0}.radio-group{display:flex;gap:10px;align-items:center;height:36px;flex-wrap:wrap}.radio{display:inline-flex;align-items:center;gap:4px;white-space:nowrap}.search-actions{display:flex;align-items:center;gap:7px;margin:0;flex-shrink:0}.search-actions .btn{height:38px;white-space:nowrap;font-family:inherit;font-size:14px}.btn{display:inline-flex;align-items:center;justify-content:center;height:36px;padding:0 14px;border:1px solid #cfd9df;border-radius:4px;background:#fff;color:#425969;font-weight:700;cursor:pointer}.btn.primary{background:#3924b9;border-color:#3924b9;color:#fff}.btn.danger{background:#fff;color:#d74735;border-color:#e5aaa3}.btn.small{height:29px;padding:0 9px;font-size:14px}
.result-bar{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;padding:10px 12px;background:#f7f9fb;border:1px solid #e0e6ea}.result-count{font-size:14px;font-weight:700}.result-count .pending{color:#e4432f}.result-count .searched{color:#3924b9}.result-tools{display:flex;gap:6px;align-items:center}.result-tools select{height:32px;border:1px solid #c7d2d9;border-radius:4px;padding:0 8px;background:#fff}
.table-wrap{overflow-x:auto;border:1px solid #e0e6ea}.inquiry-table{width:100%;border-collapse:collapse;table-layout:fixed;min-width:1120px}.inquiry-table th,.inquiry-table td{padding:11px 9px;border-bottom:1px solid #e2e8ec;text-align:center;vertical-align:middle}.inquiry-table th{background:#f7f9fb;color:#536b77;font-size:14px}.inquiry-table tbody tr.data-row:nth-of-type(odd){background:#fbfcfd}.inquiry-table tbody tr.data-row:hover{background:#f7f9ff}.col-check{width:42px}.col-status{width:92px}.col-no{width:205px}.col-writer{width:120px}.col-date{width:150px}.col-answer{width:135px}.inquiry-no{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#536977;font-size:14px;font-variant-numeric:tabular-nums}.subject{text-align:left!important}.subject a{display:block;color:#2157c7;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.subject small{display:block;color:#8a9aa5;margin-top:4px;font-weight:400;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.state{display:inline-flex;align-items:center;justify-content:center;min-width:62px;height:24px;padding:0 8px;border-radius:999px;font-size:14px;font-weight:800}.state.new{background:#fff0ea;color:#e6572e}.state.answered{background:#eaf7ee;color:#357249}.answer-link{display:inline-flex;align-items:center;justify-content:center;min-width:82px;height:28px;padding:0 10px;border-radius:5px;font-weight:700;white-space:nowrap}.answer-link.pending{color:#3924b9;background:#f0edff;border:1px solid #d8d0ff}.answer-link.done{color:#357249;background:#edf8f0;border:1px solid #d4ecd9}.answer-link.opened{color:#5e6f79;background:#f4f6f8;border:1px solid #dbe2e6}
.bulk-bar{display:flex;justify-content:space-between;align-items:center;padding:11px 12px;border:1px solid #e0e6ea;border-top:0;background:#f7f9fb}.bulk-left{display:flex;align-items:center;gap:7px}.bulk-left select{height:32px;border:1px solid #cfd9df;border-radius:4px;padding:0 8px}.pagination{display:flex;justify-content:center;align-items:center;gap:4px;padding:17px}.page-link{min-width:30px;height:30px;display:inline-flex;align-items:center;justify-content:center;border:1px solid #d6e0e5;border-radius:4px;color:#617582;background:#fff}.page-link.active{background:#3924b9;color:#fff;border-color:#3924b9}.empty{padding:45px!important;color:#8797a1}.notice{margin-bottom:12px;padding:11px 13px;border-radius:5px;background:#eaf7ee;color:#36744b;border:1px solid #cfe9d7}.notice.error{background:#fff1ed;color:#bd4933;border-color:#f1cdc5}
@media(max-width:1100px){.filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.filter-item.search{grid-column:span 1}}@media(max-width:850px){.layout{grid-template-columns:1fr}.admin-sidebar{display:none}.main{padding:12px}.top{align-items:flex-start;flex-direction:column}.top-stats{justify-content:flex-start}.filter-grid{grid-template-columns:1fr}.search-actions{justify-content:flex-start}}
.filter-item.date-range{grid-column:span 2}.date-range .filter-control{flex-wrap:wrap}.date-range .filter-control input[type=date]{flex:1 1 160px;min-width:0;max-width:100%;width:auto}.date-range .filter-control>span{flex:0 0 auto}
@media(max-width:850px){.filter-item.date-range{grid-column:span 1}}.filter-item.search{grid-column:1 / -1}.filter-item.search .filter-control input[type=search]{flex:1;width:auto;max-width:600px}</style>
<link rel="stylesheet" href="./admin-ui.css"><link rel="stylesheet" href="./date-range-picker.css"><script src="./date-range-picker.js" defer></script></head>
<body><div class="layout">
<?php $currentAdminPage = 'inquiries'; require __DIR__ . '/sidebar.php'; ?>
<main class="main"><section class="card">
<div class="top"><div><h1>고객문의 관리</h1><p>문의 목록에서 항목을 누르면 상세 페이지에서 답변을 등록하거나 수정할 수 있습니다.</p></div><div class="top-stats"><span class="stat">전체 <b><?=number_format($totalCount)?></b></span><span class="stat">오늘 <b><?=number_format($todayCount)?></b></span><span class="stat alert">미처리 <b><?=number_format($newCount)?></b></span><span class="stat">답변완료 <b><?=number_format($answeredCount)?></b></span></div></div>
<?php if(isset($_GET['saved'])):?><div class="notice">답변이 저장되었습니다.</div><?php endif;?>
<?php if(isset($_GET['bulk'])):?><div class="notice">선택한 문의의 처리가 완료되었습니다.</div><?php endif;?>
<?php if(isset($_GET['error'])):?><div class="notice error">요청을 처리하지 못했습니다. 다시 확인해 주세요.</div><?php endif;?>
<form class="filter-box" method="get"><div class="filter-grid">
    <div class="filter-item date-range"><span class="filter-label">조회기간</span><div class="filter-control" data-date-range data-label="조회기간"><input type="date" name="from" value="<?=h($from)?>"><span data-range-separator>~</span><input type="date" name="to" value="<?=h($to)?>"></div></div>
    <div class="filter-item"><span class="filter-label">처리상태</span><div class="radio-group"><label class="radio"><input type="radio" name="status" value="" <?=$status===''?'checked':''?>> 전체</label><label class="radio"><input type="radio" name="status" value="NEW" <?=$status==='NEW'?'checked':''?>> 미처리</label><label class="radio"><input type="radio" name="status" value="ANSWERED" <?=$status==='ANSWERED'?'checked':''?>> 답변완료</label></div></div>
    <div class="filter-item"><span class="filter-label">작성구분</span><div class="radio-group"><label class="radio"><input type="radio" name="member_type" value="" <?=$memberType===''?'checked':''?>> 전체</label><label class="radio"><input type="radio" name="member_type" value="member" <?=$memberType==='member'?'checked':''?>> 회원</label><label class="radio"><input type="radio" name="member_type" value="guest" <?=$memberType==='guest'?'checked':''?>> 비회원</label></div></div>
    <div class="filter-item search"><span class="filter-label">검색어</span><div class="filter-control"><input type="search" name="q" value="<?=h($q)?>" placeholder="문의번호 / 고객명 / 연락처 / 이메일 / 문의내용"><div class="search-actions"><button class="btn primary" type="submit">검색</button><a class="btn" href="./inquiries.php">초기화</a></div></div></div>
    <input type="hidden" name="per_page" value="<?=$perPage?>">
</div></form>
<div class="result-bar"><div class="result-count">미처리 건수 : <span class="pending"><?=number_format($newCount)?>개</span> &nbsp; 검색 건수 : <span class="searched"><?=number_format($searchCount)?>개</span></div><div class="result-tools"><span>목록 수</span><select onchange="location.href='?<?=h(qstr(['per_page'=>null,'page'=>null]))?>'+(this.value?'&per_page='+this.value:'')"><option value="20" <?=$perPage===20?'selected':''?>>20개</option><option value="50" <?=$perPage===50?'selected':''?>>50개</option><option value="100" <?=$perPage===100?'selected':''?>>100개</option></select></div></div>
<form id="bulkForm" action="./inquiry-actions.php" method="post"><input type="hidden" name="return_query" value="<?=h(qstr(['open'=>null]))?>"><input type="hidden" name="action" value="bulk"></form>
<div class="table-wrap"><table class="inquiry-table"><thead><tr><th class="col-check"><input type="checkbox" id="checkAll"></th><th class="col-status">처리상태</th><th class="col-no">문의번호</th><th>문의내용</th><th class="col-writer">작성자</th><th class="col-date">등록일</th><th class="col-answer">상세 관리</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="7" class="empty">조건에 맞는 문의가 없습니다.</td></tr><?php endif;?>
<?php foreach($rows as $r): $detailHref = "./inquiry-detail.php?" . http_build_query(["id"=>(int)$r["id"], "return_query"=>qstr(["open"=>null, "saved"=>null, "bulk"=>null, "error"=>null])]); ?>
<tr class="data-row">
<td><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" class="row-check" form="bulkForm"></td>
<td><span class="state <?=$r['status']==='ANSWERED'?'answered':'new'?>"><?=$r['status']==='ANSWERED'?'답변완료':'미처리'?></span></td>
<td><a class="inquiry-no" href="<?=h($detailHref)?>" title="<?=h($r['inquiry_no'])?>"><?=h($r['inquiry_no'])?></a></td>
<td class="subject"><a href="<?=h($detailHref)?>"><?=h(mb_strimwidth(preg_replace('/\s+/', ' ', (string)$r['message']),0,90,'…','UTF-8'))?></a><small><?=h($r['member_phone'] ?: ($r['member_email'] ?: '연락처 없음'))?></small></td>
<td><?=h($r['member_name'] ?: '비회원')?></td>
<td><?=h(date('Y-m-d H:i', strtotime((string)$r['created_at'])))?></td>
<td><a class="answer-link <?=$r['status']==='ANSWERED'?'done':'pending'?>" href="<?=h($detailHref)?>"><?=$r['status']==='ANSWERED'?'답변 확인·수정':'상세 보기·답변'?></a></td>
</tr>
<?php endforeach;?></tbody></table></div>
<div class="bulk-bar"><div class="bulk-left"><select name="bulk_action" form="bulkForm"><option value="">선택 문의 처리</option><option value="mark_answered">답변완료 처리</option><option value="mark_new">미처리로 변경</option><option value="delete">삭제</option></select><button class="btn small" type="submit" form="bulkForm" onclick="return confirmBulk()">적용</button></div><span style="color:#8696a0">체크한 문의를 일괄 처리할 수 있습니다.</span></div>
<?php if($totalPages>1):?><nav class="pagination"><?php if($page>1):?><a class="page-link" href="?<?=h(qstr(['page'=>$page-1,'open'=>null]))?>">‹</a><?php endif;?><?php $s=max(1,$page-3);$e=min($totalPages,$page+3);for($p=$s;$p<=$e;$p++):?><a class="page-link <?=$p===$page?'active':''?>" href="?<?=h(qstr(['page'=>$p,'open'=>null]))?>"><?=$p?></a><?php endfor;?><?php if($page<$totalPages):?><a class="page-link" href="?<?=h(qstr(['page'=>$page+1,'open'=>null]))?>">›</a><?php endif;?></nav><?php endif;?>
</section>
</main></div>
<script>
const checkAll=document.getElementById('checkAll');
if(checkAll){checkAll.addEventListener('change',()=>document.querySelectorAll('.row-check').forEach(c=>c.checked=checkAll.checked));}
function confirmBulk(){const checked=[...document.querySelectorAll('.row-check:checked')];if(!checked.length){alert('처리할 문의를 선택해 주세요.');return false;}const action=document.querySelector('[name="bulk_action"]').value;if(!action){alert('처리 방법을 선택해 주세요.');return false;}if(action==='delete') return confirm('선택한 문의를 삭제하시겠습니까? 삭제 후 복구할 수 없습니다.');return true;}
</script>
</body></html>
