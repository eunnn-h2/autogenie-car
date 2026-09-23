<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
requireAdminCategory('estimates');
require_once __DIR__ . '/estimate-date-filter.php';
try { $dates = estimateDateRange($_GET); }
catch (InvalidArgumentException $e) { http_response_code(400); exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/member-provider.php';

$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$type = trim((string)($_GET['type'] ?? '')); // DIRECT=차량견적, QUICK=간편견적, 빈값=전체 견적

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function statusLabel(string $s): string { return ['NEW'=>'신규','CONTACTED'=>'상담중','REVIEWING'=>'심사중','APPROVED'=>'승인','CONTRACTED'=>'계약완료','CANCELED'=>'취소'][$s] ?? $s; }
function productLabel(?string $v): string {
    return match (strtoupper((string)$v)) {
        'RENT' => '장기렌트',
        'LEASE' => '리스',
        default => $v ?: '-',
    };
}

$rows = [];
$tableMissing = false;
$quickTableMissing = false;

// 일반 직접견적
if ($type === '' || $type === 'DIRECT') {
    $params = [];
    $where = [];
    applyEstimateDateRange($where, $params, $dates, 'e.created_at');
    if ($status !== '') { $where[] = 'e.status = ?'; $params[] = $status; }
    if ($q !== '') {
        $where[] = '(e.estimate_no LIKE ? OR e.customer_name LIKE ? OR e.customer_phone LIKE ? OR e.vehicle_name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = 'SELECT e.* FROM estimate_direct e' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY e.id DESC LIMIT 500';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $r['_source'] = 'DIRECT';
            $r['_source_label'] = '차량견적';
            $r['_vehicle_display'] = trim((string)($r['brand_name'] ?? '') . ' ' . (string)($r['vehicle_name'] ?? '')) ?: '-';
            $r['_condition_display'] = productLabel($r['product_type'] ?? null) . ' / ' . (($r['contract_months'] ?? null) ? $r['contract_months'] . '개월' : '-');
            $r['_monthly_display'] = isset($r['monthly_payment']) && $r['monthly_payment'] !== null ? number_format((int)$r['monthly_payment']) . '원' : '-';
            $rows[] = $r;
        }
    } catch (PDOException $e) {
        $tableMissing = str_contains($e->getMessage(), "doesn't exist");
        if (!$tableMissing) throw $e;
    }
}

// 간편견적
if ($type === '' || $type === 'QUICK') {
    $params = [];
    $where = [];
    applyEstimateDateRange($where, $params, $dates, 'q.created_at');
    if ($status !== '') { $where[] = 'q.status = ?'; $params[] = $status; }
    if ($q !== '') {
        $where[] = '(q.estimate_no LIKE ? OR q.customer_name LIKE ? OR q.customer_phone LIKE ? OR q.car_type LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = 'SELECT q.* FROM estimate_quick q' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY q.id DESC LIMIT 500';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $r['_source'] = 'QUICK';
            $r['_source_label'] = '간편견적';
            $r['_vehicle_display'] = $r['car_type'] ?: '상담 후 결정';
            $parts = [];
            if (!empty($r['product_type'])) $parts[] = productLabel($r['product_type']);
            else $parts[] = '이용방식 상담';
            if (!empty($r['monthly_budget'])) $parts[] = $r['monthly_budget'];
            $r['_condition_display'] = implode(' / ', $parts);
            $r['_monthly_display'] = '-';
            $r['trim_name'] = null;
            $rows[] = $r;
        }
    } catch (PDOException $e) {
        $quickTableMissing = str_contains($e->getMessage(), "doesn't exist");
        if (!$quickTableMissing) throw $e;
    }
}

$memberProviders = adminMemberProviders($pdo, $rows);
usort($rows, static function(array $a, array $b): int {
    $ta = strtotime((string)($a['created_at'] ?? '')) ?: 0;
    $tb = strtotime((string)($b['created_at'] ?? '')) ?: 0;
    if ($ta === $tb) {
        $sourceCompare = strcmp((string)($b['_source'] ?? ''), (string)($a['_source'] ?? ''));
        if ($sourceCompare !== 0) return $sourceCompare;
        return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
    }
    return $tb <=> $ta;
});

// 직접견적/간편견적의 id는 서로 다른 테이블에서 증가하므로 관리자 화면의 "번호"는
// 두 테이블을 신청일 순으로 합친 통합 순번을 별도로 계산한다.
$globalNumberMap = [];
$globalRows = [];
if (!$tableMissing) {
    try {
        foreach ($pdo->query("SELECT id, created_at FROM estimate_direct") as $g) {
            $globalRows[] = ['key' => 'DIRECT:' . (int)$g['id'], 'created_at' => (string)$g['created_at'], 'id' => (int)$g['id'], 'source' => 'DIRECT'];
        }
    } catch (PDOException $e) {
        // 목록 조회에서 이미 테이블 상태를 처리했으므로 여기서는 화면을 계속 표시한다.
    }
}
if (!$quickTableMissing) {
    try {
        foreach ($pdo->query("SELECT id, created_at FROM estimate_quick") as $g) {
            $globalRows[] = ['key' => 'QUICK:' . (int)$g['id'], 'created_at' => (string)$g['created_at'], 'id' => (int)$g['id'], 'source' => 'QUICK'];
        }
    } catch (PDOException $e) {
        // 간편견적 목록 조회 오류가 발생하더라도 직접견적 목록은 표시한다.
    }
}
usort($globalRows, static function(array $a, array $b): int {
    $ta = strtotime($a['created_at']) ?: 0;
    $tb = strtotime($b['created_at']) ?: 0;
    if ($ta !== $tb) return $ta <=> $tb;
    $sourceCompare = strcmp($a['source'], $b['source']);
    if ($sourceCompare !== 0) return $sourceCompare;
    return $a['id'] <=> $b['id'];
});
foreach ($globalRows as $index => $g) {
    $globalNumberMap[$g['key']] = $index + 1;
}
?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>견적 관리 - 오토지니</title><link rel="stylesheet" href="./sidebar.css">
<link rel="stylesheet" href="./estimates-page.css"><link rel="stylesheet" href="./admin-ui.css"><link rel="stylesheet" href="./date-range-picker.css"><script src="./date-range-picker.js" defer></script></head><body><div class="layout">
<?php $currentAdminPage = 'estimates'; require __DIR__ . '/sidebar.php'; ?>
<main class="main"><div class="card"><div class="top"><div><h1>견적문의 관리</h1><div style="margin-top:5px;color:#84949e">차량 선택 견적과 간편견적을 한 곳에서 최신순으로 확인합니다.</div></div><a href="../db-test.html" target="_blank">+ 실제 화면에서 견적 신청</a></div>
<?php if ($tableMissing): ?><div class="alert"><strong>estimates 테이블이 없습니다.</strong><br>기존 견적 테이블을 먼저 생성해 주세요.</div><?php endif; ?>
<?php if ($quickTableMissing): ?><div class="notice"><strong>간편견적 테이블이 아직 없습니다.</strong><br>연결된 DB에 <code>estimate_quick</code> 테이블이 존재하는지 확인해 주세요.</div><?php endif; ?>
<form class="filter" method="get">
<div class="estimate-date-range" data-date-range data-label="신청일"><span data-range-separator>신청일</span><input type="date" name="from" aria-label="조회 시작일" value="<?=h($dates['from'])?>"><span data-range-separator>~</span><input type="date" name="to" aria-label="조회 종료일" value="<?=h($dates['to'])?>"></div>
<select name="type"><option value="">전체 견적</option><option value="DIRECT" <?=$type==='DIRECT'?'selected':''?>>차량견적</option><option value="QUICK" <?=$type==='QUICK'?'selected':''?>>간편견적</option></select>
<select name="status"><option value="">전체 상태</option><?php foreach(['NEW'=>'신규','CONTACTED'=>'상담중','REVIEWING'=>'심사중','APPROVED'=>'승인','CONTRACTED'=>'계약완료','CANCELED'=>'취소'] as $k=>$v): ?><option value="<?=h($k)?>" <?=$status===$k?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select>
<input name="q" value="<?=h($q)?>" placeholder="견적번호 / 고객명 / 연락처 / 차량·관심차종"><button type="submit">검색</button><a class="estimate-reset reset" href="./estimates.php">초기화</a></form>
<form id="bulkForm" method="post" action="./estimate-actions.php">
<input type="hidden" name="return_query" value="<?=h(http_build_query(['type'=>$type,'status'=>$status,'q'=>$q,'from'=>$dates['from'],'to'=>$dates['to']]))?>">
<div class="bulkbar">
    <strong>선택 항목</strong>
    <span class="selected-count"><span id="selectedCount">0</span>건 선택</span>
    <select name="bulk_status" id="bulkStatus">
        <option value="">변경할 상태</option>
        <?php foreach(['NEW'=>'신규','CONTACTED'=>'상담중','REVIEWING'=>'심사중','APPROVED'=>'승인','CONTRACTED'=>'계약완료','CANCELED'=>'취소'] as $k=>$v): ?><option value="<?=h($k)?>"><?=h($v)?></option><?php endforeach; ?>
    </select>
    <button class="action-btn primary" type="submit" name="action" value="bulk_status" onclick="return confirmBulkStatus()">선택 상태변경</button>
    <button class="action-btn danger" type="submit" name="action" value="bulk_delete" onclick="return confirmBulkDelete()">선택 삭제</button>
</div>
<div class="table-wrap"><table class="table"><thead><tr><th><input class="check" type="checkbox" id="checkAll" aria-label="전체 선택"></th><th>번호</th><th>구분</th><th>견적번호</th><th>상태</th><th>고객</th><th>연락처</th><th>차량/관심차종</th><th>신청일</th><th>관리</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="10" style="padding:50px;color:#9aabb4">저장된 견적이 없습니다.</td></tr><?php endif; ?>
<?php foreach($rows as $r): $rowKey = $r['_source'] . ':' . (int)$r['id']; ?><tr>
<td><input class="check row-check" type="checkbox" name="selected[]" value="<?=h($rowKey)?>" aria-label="<?=h($r['estimate_no'])?> 선택"></td>
<td class="no"><?=number_format((int)($globalNumberMap[$rowKey] ?? 0))?></td>
<td><span class="badge <?=$r['_source']==='QUICK'?'kind-quick':'kind-direct'?>"><?=h($r['_source_label'])?></span></td>
<td><a class="detail-link" href="./estimate-detail.php?type=<?=strtolower(h($r['_source']))?>&id=<?=(int)$r['id']?>"><?=h($r['estimate_no'])?></a></td>
<td>
    <select class="row-status" data-row-key="<?=h($rowKey)?>" aria-label="<?=h($r['estimate_no'])?> 상태">
        <?php foreach(['NEW'=>'신규','CONTACTED'=>'상담중','REVIEWING'=>'심사중','APPROVED'=>'승인','CONTRACTED'=>'계약완료','CANCELED'=>'취소'] as $k=>$v): ?><option value="<?=h($k)?>" <?=$r['status']===$k?'selected':''?>><?=h($v)?></option><?php endforeach; ?>
    </select>
</td>
<td><a class="detail-link" href="./estimate-detail.php?type=<?=strtolower(h($r['_source']))?>&id=<?=(int)$r['id']?>"><?=h($r['customer_name'])?></a><div class="member-provider-line"><?=adminMemberProviderBadge($r, $memberProviders)?></div></td><td><?=h($r['customer_phone'])?></td>
<td class="name"><?=h($r['_vehicle_display'])?></td><td><?=h($r['created_at'])?></td>
<td><div class="row-actions"><button class="action-btn primary small" type="button" onclick="saveRowStatus(this, '<?=h($rowKey)?>')">상태저장</button><button class="action-btn danger small" type="button" onclick="deleteRow('<?=h($rowKey)?>', '<?=h($r['estimate_no'])?>')">삭제</button></div></td>
</tr><?php endforeach; ?>
</tbody></table></div></form></div></main></div><form id="singleActionForm" method="post" action="./estimate-actions.php" hidden>
    <input type="hidden" name="action" id="singleAction">
    <input type="hidden" name="row_key" id="singleRowKey">
    <input type="hidden" name="status" id="singleStatus">
    <input type="hidden" name="return_query" value="<?=h(http_build_query(['type'=>$type,'status'=>$status,'q'=>$q,'from'=>$dates['from'],'to'=>$dates['to']]))?>">
</form>
<script>
const checkAll = document.getElementById('checkAll');
const rowChecks = [...document.querySelectorAll('.row-check')];
const selectedCount = document.getElementById('selectedCount');
function updateSelection(){
    const count = rowChecks.filter(el => el.checked).length;
    selectedCount.textContent = String(count);
    if (checkAll) {
        checkAll.checked = rowChecks.length > 0 && count === rowChecks.length;
        checkAll.indeterminate = count > 0 && count < rowChecks.length;
    }
}
checkAll?.addEventListener('change', () => { rowChecks.forEach(el => el.checked = checkAll.checked); updateSelection(); });
rowChecks.forEach(el => el.addEventListener('change', updateSelection));

function submitSingle(action, rowKey, status=''){
    document.getElementById('singleAction').value = action;
    document.getElementById('singleRowKey').value = rowKey;
    document.getElementById('singleStatus').value = status;
    document.getElementById('singleActionForm').submit();
}
function saveRowStatus(button, rowKey){
    const row = button.closest('tr');
    const select = row?.querySelector('.row-status');
    if (!select) return;
    submitSingle('single_status', rowKey, select.value);
}
function deleteRow(rowKey, estimateNo){
    if (!confirm(estimateNo + ' 견적을 삭제할까요?\n삭제한 데이터는 복구할 수 없습니다.')) return;
    submitSingle('single_delete', rowKey);
}
function checkedCount(){ return rowChecks.filter(el => el.checked).length; }
function confirmBulkStatus(){
    const count = checkedCount();
    if (!count) { alert('상태를 변경할 견적을 선택해 주세요.'); return false; }
    const status = document.getElementById('bulkStatus').value;
    if (!status) { alert('변경할 상태를 선택해 주세요.'); return false; }
    return confirm(count + '건의 상태를 한 번에 변경할까요?');
}
function confirmBulkDelete(){
    const count = checkedCount();
    if (!count) { alert('삭제할 견적을 선택해 주세요.'); return false; }
    return confirm(count + '건의 견적을 삭제할까요?\n삭제한 데이터는 복구할 수 없습니다.');
}
updateSelection();
</script></body></html>
