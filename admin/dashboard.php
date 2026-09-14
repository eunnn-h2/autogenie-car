<?php
declare(strict_types=1);
require_once __DIR__.'/auth.php';
require_once __DIR__.'/../config/database.php';
require_once __DIR__.'/admin_helpers.php';

function dashCount(PDO $pdo, string $table, string $where='1=1', array $params=[]): int {
    if (!ag_table_exists($pdo, $table)) return 0;
    return ag_scalar($pdo, "SELECT COUNT(*) FROM {$table} WHERE {$where}", $params);
}

$hasDirect = ag_table_exists($pdo, 'estimate_direct');
$hasQuick = ag_table_exists($pdo, 'estimate_quick');
$hasInquiry = ag_table_exists($pdo, 'customer_inquiries');

$directToday = dashCount($pdo, 'estimate_direct', 'DATE(created_at)=CURDATE()');
$quickToday = dashCount($pdo, 'estimate_quick', 'DATE(created_at)=CURDATE()');
$directMonth = dashCount($pdo, 'estimate_direct', 'YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())');
$quickMonth = dashCount($pdo, 'estimate_quick', 'YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())');
$directTotal = dashCount($pdo, 'estimate_direct');
$quickTotal = dashCount($pdo, 'estimate_quick');
$estimateToday = $directToday + $quickToday;
$estimateMonth = $directMonth + $quickMonth;
$estimateTotal = $directTotal + $quickTotal;

$inquiryToday = dashCount($pdo, 'customer_inquiries', 'DATE(created_at)=CURDATE()');
$inquiryMonth = dashCount($pdo, 'customer_inquiries', 'YEAR(created_at)=YEAR(CURDATE()) AND MONTH(created_at)=MONTH(CURDATE())');
$inquiryTotal = dashCount($pdo, 'customer_inquiries');
$inquiryPending = dashCount($pdo, 'customer_inquiries', 'status=?', ['NEW']);

$statusOrder = ['NEW','CONTACTED','REVIEWING','APPROVED','CONTRACTED'];
$statusLabels = ['NEW'=>'신규','CONTACTED'=>'상담중','REVIEWING'=>'심사중','APPROVED'=>'승인','CONTRACTED'=>'계약완료','CANCELED'=>'취소'];
$estimateStatusCounts = [];
foreach ($statusOrder as $st) {
    $estimateStatusCounts[$st] = dashCount($pdo, 'estimate_direct', 'status=?', [$st]) + dashCount($pdo, 'estimate_quick', 'status=?', [$st]);
}
$estimateCanceled = dashCount($pdo, 'estimate_direct', 'status=?', ['CANCELED']) + dashCount($pdo, 'estimate_quick', 'status=?', ['CANCELED']);

$recentEstimates = [];
try {
    if ($hasDirect) {
        foreach ($pdo->query("SELECT id,estimate_no,customer_name,customer_phone,CONCAT(COALESCE(brand_name,''),' ',COALESCE(vehicle_name,'')) AS item,status,created_at,'DIRECT' AS src FROM estimate_direct ORDER BY id DESC LIMIT 10") as $r) $recentEstimates[] = $r;
    }
} catch (Throwable $e) {}
try {
    if ($hasQuick) {
        foreach ($pdo->query("SELECT id,estimate_no,customer_name,customer_phone,COALESCE(NULLIF(car_type,''),'상담 후 결정') AS item,status,created_at,'QUICK' AS src FROM estimate_quick ORDER BY id DESC LIMIT 10") as $r) $recentEstimates[] = $r;
    }
} catch (Throwable $e) {}
usort($recentEstimates, static fn($a,$b)=>(strtotime((string)$b['created_at'])?:0) <=> (strtotime((string)$a['created_at'])?:0));
$recentEstimates = array_slice($recentEstimates,0,7);

$recentInquiries = [];
try {
    if ($hasInquiry) $recentInquiries = $pdo->query("SELECT id,inquiry_no,member_name,subject,status,created_at FROM customer_inquiries ORDER BY id DESC LIMIT 7")->fetchAll();
} catch (Throwable $e) {}

$trend = [];
for ($i=6; $i>=0; $i--) {
    $date = date('Y-m-d', strtotime("-{$i} day"));
    $trend[] = [
        'date'=>$date,
        'estimate'=>dashCount($pdo,'estimate_direct','DATE(created_at)=?',[$date]) + dashCount($pdo,'estimate_quick','DATE(created_at)=?',[$date]),
        'inquiry'=>dashCount($pdo,'customer_inquiries','DATE(created_at)=?',[$date]),
    ];
}

$popularVehicles = [];
try {
    if ($hasDirect) $popularVehicles = $pdo->query("SELECT TRIM(CONCAT(COALESCE(brand_name,''),' ',COALESCE(vehicle_name,''))) vehicle, COUNT(*) cnt FROM estimate_direct WHERE COALESCE(vehicle_name,'')<>'' GROUP BY brand_name,vehicle_name ORDER BY cnt DESC LIMIT 6")->fetchAll();
} catch (Throwable $e) {}

$activeEstimate = $estimateStatusCounts['NEW'] + $estimateStatusCounts['CONTACTED'] + $estimateStatusCounts['REVIEWING'] + $estimateStatusCounts['APPROVED'];
$contractRate = $estimateTotal > 0 ? round(($estimateStatusCounts['CONTRACTED'] / $estimateTotal) * 100, 1) : 0;
?>
<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>관리자 대시보드 - 오토지니</title><link rel="stylesheet" href="./sidebar.css"><style>
*{box-sizing:border-box}body{margin:0;font:13px Pretendard,"Noto Sans KR",Arial,sans-serif;background:#f3f7f9;color:#25384a}.layout{display:grid;grid-template-columns:228px minmax(0,1fr);min-height:100vh}.main{padding:28px;min-width:0}.head{display:flex;justify-content:space-between;align-items:flex-end;gap:20px;margin-bottom:20px}.head h1{margin:0;font-size:25px}.head p{margin:7px 0 0;color:#7f909c}.date{color:#7f909c;font-size:12px}.cards{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;margin-bottom:14px}.metric{display:block;background:#fff;border:1px solid #dce5ea;border-radius:12px;padding:17px;text-decoration:none;color:inherit}.metric .label{color:#758793;font-size:12px}.metric strong{display:block;margin-top:8px;font-size:25px}.metric small{display:block;margin-top:7px;color:#98a6af}.metric.primary strong{color:#4c35c4}.metric.warn strong{color:#e76d22}.metric.green strong{color:#278657}.panel{background:#fff;border:1px solid #dce5ea;border-radius:12px;padding:18px;margin-bottom:14px;min-width:0}.panel-head{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:15px}.panel h2{margin:0;font-size:16px}.panel-head a{font-size:12px;color:#4c35c4;text-decoration:none;font-weight:700}.section-grid{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,.65fr);gap:14px}.funnel{display:grid;grid-template-columns:repeat(5,1fr);gap:10px}.step{position:relative;padding:18px 12px;border:1px solid #e3e9ed;border-radius:10px;background:#fafcfd;text-align:center}.step:not(:last-child):after{content:'›';position:absolute;right:-9px;top:50%;transform:translateY(-50%);z-index:2;color:#9aa8b1;font-size:24px;background:#fff;width:16px}.step span{display:block;color:#7a8b96;font-size:12px}.step b{display:block;font-size:24px;margin-top:7px}.step small{display:block;margin-top:5px;color:#9aa8b1}.trend{display:grid;grid-template-columns:repeat(7,1fr);gap:10px;height:205px;align-items:end}.trend-day{height:100%;display:flex;flex-direction:column;justify-content:flex-end;align-items:center;gap:7px}.trend-bars{height:145px;display:flex;align-items:flex-end;justify-content:center;gap:5px}.tb{width:15px;min-height:3px;border-radius:4px 4px 1px 1px}.tb.direct{background:#4b35c5}.tb.quick{background:#47a0d8}.trend-day small{font-size:10px;color:#8797a0}.legend{display:flex;gap:14px;justify-content:flex-end;margin-top:8px;color:#7d8e98;font-size:11px}.legend i{display:inline-block;width:8px;height:8px;border-radius:2px;margin-right:5px}.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:650px}.table th,.table td{padding:10px 8px;border-bottom:1px solid #edf1f3;text-align:left}.table th{font-size:11px;color:#80919b}.table a{color:#273b4c;text-decoration:none}.pill{display:inline-flex;padding:4px 7px;border-radius:999px;background:#edf1ff;color:#405bd7;font-size:11px;font-weight:800}.ellipsis{max-width:250px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.two-list{display:grid;grid-template-columns:1fr 1fr;gap:14px}.status-row{display:grid;grid-template-columns:180px 1fr 40px;gap:10px;align-items:center;margin:10px 0}.bar{height:8px;background:#eef2f5;border-radius:99px;overflow:hidden}.bar i{display:block;height:100%;background:#4b35c5;border-radius:99px}.quick-links{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.quick-links a{padding:14px;border:1px solid #dce5ea;border-radius:9px;text-decoration:none;color:#344a5b;background:#fff;font-weight:700;text-align:center}.empty{padding:22px;text-align:center;color:#94a2aa}@media(max-width:1300px){.cards{grid-template-columns:repeat(3,1fr)}.section-grid,.two-list{grid-template-columns:1fr}}@media(max-width:900px){.layout{grid-template-columns:1fr}.admin-sidebar{display:none}.main{padding:14px}.cards{grid-template-columns:1fr 1fr}.funnel{grid-template-columns:1fr}.step:after{display:none}}@media(max-width:540px){.cards{grid-template-columns:1fr}.quick-links{grid-template-columns:1fr}}
</style><link rel="stylesheet" href="./admin-ui.css"></head><body><div class="layout"><?php $currentAdminPage='dashboard';require __DIR__.'/sidebar.php';?><main class="main">
<section class="card ag-page-card"><div class="top"><div><h1>운영 대시보드</h1><p>견적 진행상황과 상담 유입을 한 화면에서 확인합니다.</p></div><div class="top-stats"><span class="stat">오늘 <b><?=date('Y.m.d')?></b></span></div></div></section>

<div class="cards">
<a class="metric primary" href="./estimates.php"><div class="label">누적 견적</div><strong><?=number_format($estimateTotal)?></strong><small>차량견적 <?=number_format($directTotal)?> · 간편견적 <?=number_format($quickTotal)?></small></a>
<a class="metric" href="./estimates.php"><div class="label">이번 달 견적</div><strong><?=number_format($estimateMonth)?></strong><small>오늘 <?=number_format($estimateToday)?>건</small></a>
<a class="metric" href="./inquiries.php"><div class="label">누적 고객문의</div><strong><?=number_format($inquiryTotal)?></strong><small>이번 달 <?=number_format($inquiryMonth)?>건</small></a>
<a class="metric warn" href="./estimates.php?status=NEW"><div class="label">상담 대기</div><strong><?=number_format($estimateStatusCounts['NEW'])?></strong><small>신규 견적</small></a>
<a class="metric green" href="./estimates.php?status=CONTACTED"><div class="label">진행중 견적</div><strong><?=number_format($activeEstimate)?></strong><small>신규~승인 단계</small></a>
<a class="metric" href="./estimates.php?status=CONTRACTED"><div class="label">계약 완료</div><strong><?=number_format($estimateStatusCounts['CONTRACTED'])?></strong><small>누적 계약률 <?=$contractRate?>%</small></a>
</div>

<section class="panel"><div class="panel-head"><h2>견적 진행 현황</h2><a href="./estimates.php">견적문의 전체보기</a></div><div class="funnel"><?php foreach($statusOrder as $k): ?><a class="step" href="./estimates.php?status=<?=$k?>" style="text-decoration:none;color:inherit"><span><?=$statusLabels[$k]?></span><b><?=number_format($estimateStatusCounts[$k])?></b><small>건</small></a><?php endforeach;?></div><?php if($estimateCanceled>0): ?><div style="margin-top:10px;color:#8b99a2;font-size:12px">취소 <?=number_format($estimateCanceled)?>건</div><?php endif;?></section>

<div class="section-grid">
<section class="panel"><div class="panel-head"><h2>최근 7일 유입 추이</h2><span style="font-size:11px;color:#8999a2">견적문의 / 고객문의</span></div><?php $maxTrend=max(array_map(fn($r)=>max($r['estimate'],$r['inquiry']),$trend)?:[1]);$maxTrend=max(1,$maxTrend);?><div class="trend"><?php foreach($trend as $r):?><div class="trend-day"><div class="trend-bars"><i class="tb direct" style="height:<?=max(3,round($r['estimate']/$maxTrend*135))?>px"></i><i class="tb quick" style="height:<?=max(3,round($r['inquiry']/$maxTrend*135))?>px"></i></div><b style="font-size:11px"><?=number_format($r['estimate']+$r['inquiry'])?></b><small><?=date('m/d',strtotime($r['date']))?></small></div><?php endforeach;?></div><div class="legend"><span><i style="background:#4b35c5"></i>견적문의</span><span><i style="background:#47a0d8"></i>고객문의</span></div></section>
<section class="panel"><div class="panel-head"><h2>이번 달 운영 요약</h2></div><div class="status-row"><span>견적문의</span><div class="bar"><i style="width:<?=($estimateMonth+$inquiryMonth)>0?($estimateMonth/($estimateMonth+$inquiryMonth)*100):0?>%"></i></div><b><?=number_format($estimateMonth)?></b></div><div class="status-row"><span>고객문의</span><div class="bar"><i style="width:<?=($estimateMonth+$inquiryMonth)>0?($inquiryMonth/($estimateMonth+$inquiryMonth)*100):0?>%;background:#47a0d8"></i></div><b><?=number_format($inquiryMonth)?></b></div><div style="margin-top:18px;padding-top:15px;border-top:1px solid #edf1f3"><b style="font-size:22px"><?=number_format($estimateMonth+$inquiryMonth)?></b><span style="margin-left:7px;color:#83939d">이번 달 전체 상담 유입</span></div><div style="margin-top:10px;color:#8b99a2">미처리 고객문의 <?=number_format($inquiryPending)?>건</div></section>
</div>

<div class="two-list">
<section class="panel"><div class="panel-head"><h2>최근 견적문의</h2><a href="./estimates.php">전체보기</a></div><div class="table-wrap"><table class="table"><thead><tr><th>구분</th><th>고객</th><th>차량/관심차종</th><th>상태</th><th>신청일</th></tr></thead><tbody><?php foreach($recentEstimates as $r):?><tr><td><span class="pill"><?=($r['src']==='QUICK'?'간편견적':'차량견적')?></span></td><td><a href="./estimate-detail.php?type=<?=strtolower($r['src'])?>&id=<?=(int)$r['id']?>"><b><?=ag_h($r['customer_name'])?></b></a></td><td class="ellipsis"><?=ag_h(trim((string)$r['item'])?:'-')?></td><td><span class="pill"><?=ag_h($statusLabels[$r['status']]??$r['status'])?></span></td><td><?=date('m/d H:i',strtotime($r['created_at']))?></td></tr><?php endforeach;?><?php if(!$recentEstimates):?><tr><td colspan="5" class="empty">견적문의가 없습니다.</td></tr><?php endif;?></tbody></table></div></section>
<section class="panel"><div class="panel-head"><h2>최근 고객문의</h2><a href="./inquiries.php">전체보기</a></div><div class="table-wrap"><table class="table"><thead><tr><th>고객</th><th>문의내용</th><th>상태</th><th>등록일</th></tr></thead><tbody><?php foreach($recentInquiries as $r):?><tr><td><b><?=ag_h($r['member_name']?:'비회원')?></b></td><td class="ellipsis"><a href="./inquiries.php?open=<?=(int)$r['id']?>"><?=ag_h($r['subject']?:'-')?></a></td><td><span class="pill"><?=($r['status']==='ANSWERED'?'답변완료':'미처리')?></span></td><td><?=date('m/d H:i',strtotime($r['created_at']))?></td></tr><?php endforeach;?><?php if(!$recentInquiries):?><tr><td colspan="4" class="empty">고객문의가 없습니다.</td></tr><?php endif;?></tbody></table></div></section>
</div>

<?php if($popularVehicles):?><section class="panel"><div class="panel-head"><h2>차량견적 인기 차량 TOP 6</h2><a href="./estimates.php?type=DIRECT">차량견적 보기</a></div><?php $pvmax=max(1,max(array_map(fn($r)=>(int)$r['cnt'],$popularVehicles))); foreach($popularVehicles as $r):?><div class="status-row"><span><?=ag_h($r['vehicle'])?></span><div class="bar"><i style="width:<?=((int)$r['cnt']/$pvmax*100)?>%"></i></div><b><?=number_format((int)$r['cnt'])?></b></div><?php endforeach;?></section><?php endif;?>

<section class="panel"><div class="panel-head"><h2>빠른 관리</h2></div><div class="quick-links"><a href="./estimates.php">견적문의 관리</a><a href="./inquiries.php">고객문의 관리</a><a href="./customers.php">고객 관리</a></div></section>
</main></div></body></html>
