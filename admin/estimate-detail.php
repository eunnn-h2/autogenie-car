<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
requireAdminCategory('estimates');
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/member-provider.php';

$id = (int)($_GET['id'] ?? 0);
$type = strtolower(trim((string)($_GET['type'] ?? 'direct')));
$isQuick = $type === 'quick';
if ($id < 1) { http_response_code(400); exit('잘못된 견적 ID입니다.'); }

$table = $isQuick ? 'estimate_quick' : 'estimate_direct';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireVehicleEditor();
    $action = (string)($_POST['action'] ?? 'status');
    if ($action === 'delete') {
        $pdo->prepare("DELETE FROM {$table} WHERE id=?")->execute([$id]);
        header('Location: ./estimates.php');
        exit;
    }

    $allowed = ['NEW','CONTACTED','REVIEWING','APPROVED','CONTRACTED','CANCELED'];
    $newStatus = (string)($_POST['status'] ?? '');
    if (in_array($newStatus, $allowed, true)) {
        $pdo->prepare("UPDATE {$table} SET status=? WHERE id=?")->execute([$newStatus, $id]);
        header('Location: ./estimate-detail.php?type=' . ($isQuick ? 'quick' : 'direct') . '&id=' . $id);
        exit;
    }
}

try {
    $stmt = $pdo->prepare("SELECT * FROM {$table} WHERE id=? LIMIT 1");
    $stmt->execute([$id]);
    $e = $stmt->fetch();
} catch (PDOException $ex) {
    if ($isQuick && str_contains($ex->getMessage(), "doesn't exist")) {
        http_response_code(500);
        exit('간편견적 테이블 estimate_quick을 찾을 수 없습니다. DB 연결을 확인해 주세요.');
    }
    throw $ex;
}
if (!$e) { http_response_code(404); exit('견적을 찾을 수 없습니다.'); }
$memberProviders = adminMemberProviders($pdo, [$e]);

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function val(mixed $v): string { return ($v === null || $v === '') ? '-' : h($v); }
function productLabel(mixed $v): string {
    return match (strtoupper((string)$v)) {
        'RENT' => '장기렌트',
        'LEASE' => '리스',
        default => ($v === null || $v === '') ? '-' : h($v),
    };
}
?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($e['estimate_no'])?> - 견적 상세</title><link rel="stylesheet" href="./estimate-detail-page.css"><link rel="stylesheet" href="./admin-ui.css"></head><body class="estimate-detail"><div class="wrap"><a class="back" href="./estimates.php">← 견적목록</a><div class="card"><div class="head"><div><h1><?=h($e['estimate_no'])?></h1><p>신청일 <?=h($e['created_at'])?></p><span class="kind <?= $isQuick ? 'kind--quick' : 'kind--direct' ?>"><?=$isQuick?'간편견적':'직접견적'?></span></div><form class="status-form" method="post"><select name="status"><?php foreach(['NEW'=>'신규','CONTACTED'=>'상담중','REVIEWING'=>'심사중','APPROVED'=>'승인','CONTRACTED'=>'계약완료','CANCELED'=>'취소'] as $k=>$v): ?><option value="<?=$k?>" <?=$e['status']===$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select><button name="action" value="status">상태 저장</button><button class="delete-btn" name="action" value="delete" onclick="return confirm('이 견적을 삭제할까요? 삭제 후 복구할 수 없습니다.')">삭제</button></form></div>
<div class="section"><h2>고객 정보</h2><div class="info"><div class="item"><span>성함</span><b><?=h($e['customer_name'])?> <span class="member-provider-line"><?=adminMemberProviderBadge($e, $memberProviders)?></span></b></div><div class="item"><span>연락처</span><b><?=h($e['customer_phone'])?></b></div></div></div>
<?php if ($isQuick): ?>
<div class="section"><h2>간편견적 요청 조건</h2><div class="info">
<div class="item"><span>관심 차종</span><b><?=val($e['car_type'])?></b></div>
<div class="item"><span>희망 월 예산</span><b><?=val($e['monthly_budget'])?></b></div>
<div class="item"><span>이용 방식</span><b><?=productLabel($e['product_type'])?></b></div>
<div class="item"><span>신청 유형</span><b>상담 후 차량·조건 결정</b></div>
</div></div>
<?php else: ?>
<div class="section"><h2>차량 정보</h2><div class="info"><div class="item"><span>브랜드</span><b><?=h($e['brand_name'])?></b></div><div class="item"><span>차량</span><b><?=h($e['vehicle_name'])?></b></div><div class="item"><span>트림</span><b><?=val($e['trim_name'])?></b></div><div class="item"><span>외장색상</span><b><?=val($e['color_name'])?></b></div></div></div>
<div class="section"><h2>이용 조건</h2><div class="info"><div class="item"><span>상품</span><b><?=productLabel($e['product_type'])?></b></div><div class="item"><span>계약기간</span><b><?=val($e['contract_months'])?>개월</b></div><div class="item"><span>선납률</span><b><?=val($e['prepayment_rate'])?>%</b></div><div class="item"><span>연 주행거리</span><b><?=isset($e['annual_mileage'])?number_format((int)$e['annual_mileage']).'km':'-'?></b></div><div class="item"><span>월 납입금</span><b><?=isset($e['monthly_payment'])?number_format((int)$e['monthly_payment']).'원':'-'?></b></div></div></div>
<div class="section"><h2>고객 메모</h2><div class="memo"><?=val($e['customer_memo'])?></div></div>
<?php endif; ?>
</div></div><script src="./admin-delete-guard.js" defer></script></body></html>
