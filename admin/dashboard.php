<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
requireAdminCategory('dashboard');
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/admin_helpers.php';

function dashCount(PDO $pdo, string $table, string $where='1=1', array $params=[]): int {
    if (!ag_table_exists($pdo, $table)) return 0;
    return ag_scalar($pdo, "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params);
}

$hasDirect = ag_table_exists($pdo, 'estimate_direct');
$hasQuick = ag_table_exists($pdo, 'estimate_quick');
$hasInquiry = ag_table_exists($pdo, 'customer_inquiries');

$estimateTotal = dashCount($pdo, 'estimate_direct') + dashCount($pdo, 'estimate_quick');
$directTotal = dashCount($pdo, 'estimate_direct');
$quickTotal = dashCount($pdo, 'estimate_quick');
$statusOrder = ['NEW', 'CONTACTED', 'REVIEWING', 'APPROVED', 'CONTRACTED', 'CANCELED'];
$statusLabels = ['NEW'=>'신규 접수', 'CONTACTED'=>'상담중', 'REVIEWING'=>'심사중', 'APPROVED'=>'승인', 'CONTRACTED'=>'계약완료', 'CANCELED'=>'취소'];
$estimateStatusCounts = [];
foreach ($statusOrder as $statusCode) {
    $estimateStatusCounts[$statusCode] = dashCount($pdo, 'estimate_direct', 'status=?', [$statusCode])
        + dashCount($pdo, 'estimate_quick', 'status=?', [$statusCode]);
}

$inquiryTotal = dashCount($pdo, 'customer_inquiries');
$inquiryPending = dashCount($pdo, 'customer_inquiries', 'status=?', ['NEW']);
$inquiryAnswered = dashCount($pdo, 'customer_inquiries', 'status=?', ['ANSWERED']);

$recentEstimates = [];
try {
    if ($hasDirect) {
        $sql = "SELECT id, customer_name, CONCAT(COALESCE(brand_name,''),' ',COALESCE(vehicle_name,'')) AS item, status, created_at, 'DIRECT' AS src FROM estimate_direct ORDER BY created_at DESC, id DESC LIMIT 7";
        foreach ($pdo->query($sql) as $record) $recentEstimates[] = $record;
    }
    if ($hasQuick) {
        $sql = "SELECT id, customer_name, COALESCE(NULLIF(car_type,''),'상담 후 결정') AS item, status, created_at, 'QUICK' AS src FROM estimate_quick ORDER BY created_at DESC, id DESC LIMIT 7";
        foreach ($pdo->query($sql) as $record) $recentEstimates[] = $record;
    }
} catch (Throwable $e) {
    $recentEstimates = [];
}
usort($recentEstimates, static fn($a, $b) => (strtotime((string)$b['created_at']) ?: 0) <=> (strtotime((string)$a['created_at']) ?: 0));
$recentEstimates = array_slice($recentEstimates, 0, 5);

$recentInquiries = [];
try {
    if ($hasInquiry) {
        $recentInquiries = $pdo->query('SELECT id, member_name, message, status, created_at FROM customer_inquiries ORDER BY created_at DESC, id DESC LIMIT 5')->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $recentInquiries = [];
}
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>운영 대시보드 - 오토지니</title>
<link rel="stylesheet" href="./sidebar.css">
<link rel="stylesheet" href="./dashboard-page.css">
<link rel="stylesheet" href="./admin-ui.css">
</head>
<body>
<div class="layout">
<?php $currentAdminPage='dashboard'; require __DIR__.'/sidebar.php'; ?>
<main class="main">
<header class="page-head"><div><h1>운영 대시보드</h1><p>견적 진행상황과 고객문의 처리상태를 확인합니다.</p></div><span class="today">기준일 <?=date('Y.m.d')?></span></header>

<?php if (canAccessAdminCategory('estimates')): ?>
<section class="panel" aria-labelledby="estimate-heading">
  <div class="panel-head"><div><h2 id="estimate-heading">견적 진행 현황</h2><p class="panel-description">차량견적과 간편견적을 합산한 현재 상태입니다.</p></div><a href="./estimates.php">견적관리 바로가기 ›</a></div>
  <p class="section-label">단계별 접수 건수</p>
  <div class="stage-grid">
    <?php foreach ($statusOrder as $statusCode): ?>
      <a class="stage <?= $statusCode==='NEW'?'is-new':($statusCode==='CONTRACTED'?'is-done':'') ?>" href="./estimates.php?status=<?=rawurlencode($statusCode)?>"><span><?=ag_h($statusLabels[$statusCode])?></span><strong><?=number_format($estimateStatusCounts[$statusCode])?></strong></a>
    <?php endforeach; ?>
  </div>
  <div class="summary-line"><span>전체 견적 <b><?=number_format($estimateTotal)?>건</b></span><span>차량견적 <b><?=number_format($directTotal)?>건</b></span><span>간편견적 <b><?=number_format($quickTotal)?>건</b></span></div>
</section>
<?php endif; ?>

<?php if (canAccessAdminCategory('inquiries')): ?>
<section class="panel" aria-labelledby="inquiry-heading">
  <div class="panel-head"><div><h2 id="inquiry-heading">고객문의 처리 현황</h2><p class="panel-description">일반 고객문의만 집계하며 견적문의는 포함하지 않습니다.</p></div><a href="./inquiries.php">고객문의 바로가기 ›</a></div>
  <div class="status-cards">
    <a class="status-card pending" href="./inquiries.php?status=NEW"><span class="label">미처리 문의</span><strong><?=number_format($inquiryPending)?></strong><small>답변이 필요한 문의</small></a>
    <a class="status-card complete" href="./inquiries.php?status=ANSWERED"><span class="label">답변 완료</span><strong><?=number_format($inquiryAnswered)?></strong><small>답변 등록된 문의</small></a>
    <a class="status-card" href="./inquiries.php"><span class="label">전체 고객문의</span><strong><?=number_format($inquiryTotal)?></strong><small>누적 접수 건수</small></a>
  </div>
</section>
<?php endif; ?>

<div class="list-grid">
<?php if (canAccessAdminCategory('estimates')): ?>
<section class="panel"><div class="panel-head"><h2>최근 견적 접수</h2><a href="./estimates.php">전체보기 ›</a></div><div class="table-wrap"><table class="data-table"><thead><tr><th>구분</th><th>고객</th><th>차량/관심차종</th><th>상태</th><th>신청일</th></tr></thead><tbody>
<?php foreach ($recentEstimates as $record): ?>
<tr><td><span class="tag"><?=$record['src']==='QUICK'?'간편':'차량'?></span></td><td><a href="./estimate-detail.php?type=<?=strtolower($record['src'])?>&amp;id=<?=(int)$record['id']?>"><?=ag_h($record['customer_name'])?></a></td><td class="truncate" title="<?=ag_h(trim((string)$record['item'])?:'-')?>"><?=ag_h(trim((string)$record['item'])?:'-')?></td><td><span class="tag <?= $record['status']==='NEW'?'pending':($record['status']==='CONTRACTED'?'complete':'current') ?>"><?=ag_h($statusLabels[$record['status']]??(string)$record['status'])?></span></td><td><?=date('m/d H:i',strtotime((string)$record['created_at']))?></td></tr>
<?php endforeach; ?>
<?php if (!$recentEstimates): ?><tr><td colspan="5" class="empty">접수된 견적이 없습니다.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php if (canAccessAdminCategory('inquiries')): ?>
<section class="panel"><div class="panel-head"><h2>최근 고객문의</h2><a href="./inquiries.php">전체보기 ›</a></div><div class="table-wrap"><table class="data-table"><thead><tr><th>고객</th><th>문의내용</th><th>상태</th><th>접수일</th></tr></thead><tbody>
<?php foreach ($recentInquiries as $record): ?>
<tr><td><?=ag_h($record['member_name']?:'비회원')?></td><td class="truncate"><a title="<?=ag_h((string)$record['message'])?>" href="./inquiries.php?open=<?=(int)$record['id']?>"><?=ag_h(mb_strimwidth(preg_replace('/\s+/u',' ',(string)$record['message'])??'',0,55,'…','UTF-8'))?></a></td><td><span class="tag <?=$record['status']==='ANSWERED'?'complete':'pending'?>"><?=$record['status']==='ANSWERED'?'답변완료':'미처리'?></span></td><td><?=date('m/d H:i',strtotime((string)$record['created_at']))?></td></tr>
<?php endforeach; ?>
<?php if (!$recentInquiries): ?><tr><td colspan="4" class="empty">접수된 고객문의가 없습니다.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php endif; ?>
</div>
</main></div></body></html>
