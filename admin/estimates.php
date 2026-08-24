<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$type = trim((string)($_GET['type'] ?? ''));
$source = trim((string)($_GET['source'] ?? ''));

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

$activeAdmins = $pdo->query("
    SELECT id, username, name
    FROM admins
    WHERE is_active = 1
    ORDER BY COALESCE(NULLIF(name, ''), username) ASC
")->fetchAll(PDO::FETCH_ASSOC);

function adminDisplayName(array $admin): string {
    $name = trim((string)($admin['name'] ?? ''));
    return $name !== '' ? $name : (string)($admin['username'] ?? '');
}

// 일반 직접견적
if ($type === '' || $type === 'DIRECT') {
    $params = [];
    $where = [];
    if ($status !== '') { $where[] = 'e.status = ?'; $params[] = $status; }
    if ($source !== '') { $where[] = "COALESCE(NULLIF(e.utm_source,''), '직접/기타') = ?"; $params[] = $source; }
    if ($q !== '') {
        $where[] = '(e.estimate_no LIKE ? OR e.customer_name LIKE ? OR e.customer_phone LIKE ? OR e.vehicle_name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = 'SELECT e.*,
                   a.name AS _assigned_admin_name,
                   a.username AS _assigned_admin_username
            FROM estimates e
            LEFT JOIN admins a ON a.id = e.assigned_admin_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY e.id DESC LIMIT 500';
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $r) {
            $r['_source'] = 'DIRECT';
            $r['_source_label'] = '직접견적';
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
    if ($status !== '') { $where[] = 'q.status = ?'; $params[] = $status; }
    if ($source !== '') { $where[] = "COALESCE(NULLIF(q.utm_source,''), '직접/기타') = ?"; $params[] = $source; }
    if ($q !== '') {
        $where[] = '(q.estimate_no LIKE ? OR q.customer_name LIKE ? OR q.customer_phone LIKE ? OR q.car_type LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = 'SELECT q.*,
                   a.name AS _assigned_admin_name,
                   a.username AS _assigned_admin_username
            FROM quick_estimates q
            LEFT JOIN admins a ON a.id = q.assigned_admin_id'
         . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ' ORDER BY q.id DESC LIMIT 500';
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
        foreach ($pdo->query("SELECT id, created_at FROM estimates") as $g) {
            $globalRows[] = ['key' => 'DIRECT:' . (int)$g['id'], 'created_at' => (string)$g['created_at'], 'id' => (int)$g['id'], 'source' => 'DIRECT'];
        }
    } catch (PDOException $e) {
        // 목록 조회에서 이미 테이블 상태를 처리했으므로 여기서는 화면을 계속 표시한다.
    }
}
if (!$quickTableMissing) {
    try {
        foreach ($pdo->query("SELECT id, created_at FROM quick_estimates") as $g) {
            $globalRows[] = ['key' => 'QUICK:' . (int)$g['id'], 'created_at' => (string)$g['created_at'], 'id' => (int)$g['id'], 'source' => 'QUICK'];
        }
    } catch (PDOException $e) {
        // quick_estimates가 아직 없더라도 직접견적 목록은 정상 표시한다.
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
<style>
*{box-sizing:border-box}body{margin:0;font-family:Pretendard,"Noto Sans KR",Arial,sans-serif;background:#eef5f8;color:#25384a;font-size:13px}a{text-decoration:none;color:inherit}.layout{display:grid;grid-template-columns:264px 1fr;min-height:100vh}.side{background:#fff;border-right:1px solid #dbe4e9;padding:18px 14px;display:flex;flex-direction:column;gap:14px}.admin-userbox{display:flex;gap:10px;align-items:center;padding-bottom:18px;border-bottom:1px solid #edf1f3}.admin-userbox .mark{width:38px;height:38px;border-radius:10px;background:#29bed1;color:#fff;display:grid;place-items:center;font-weight:800}.admin-userbox strong,.admin-userbox span,.admin-userbox small,.admin-userbox a{display:block}.admin-userbox strong{font-size:15px}.admin-userbox span{font-size:11px;color:#93a3ad;margin-top:2px}.admin-userbox .role{display:inline-block;margin-top:4px;padding:2px 6px;border-radius:999px;background:#eef2ff;color:#4338ca;font-size:10px;font-weight:800}.admin-userbox .logout{margin-top:5px;font-size:11px;color:#25bcd0}.admin-menu{display:grid;gap:14px}.admin-menu-group{padding:12px;border:1px solid #e5ebef;border-radius:14px;background:#fbfcfd}.admin-menu-group.current{border-color:#cfc9f4;background:#f7f5ff;box-shadow:0 0 0 1px rgba(57,36,185,.04) inset}.admin-menu-group p{font-size:11px;color:#778893;font-weight:800;margin:0 0 10px;letter-spacing:.02em}.admin-menu-link{display:flex;align-items:flex-start;gap:10px;padding:10px 11px;border:1px solid transparent;border-radius:10px;color:#5c7080;transition:.15s}.admin-menu-link + .admin-menu-link{margin-top:6px}.admin-menu-link:hover{background:#f2f6f9;border-color:#e0e7ec}.admin-menu-link.active{background:#3924b9;color:#fff;border-color:#3924b9;box-shadow:0 8px 18px rgba(57,36,185,.18)}.admin-menu-link.active .admin-menu-icon{background:rgba(255,255,255,.16);color:#fff}.admin-menu-link.active small{color:rgba(255,255,255,.82)}.admin-menu-icon{flex:0 0 34px;width:34px;height:34px;border-radius:10px;background:#eef2f7;color:#3924b9;font-size:11px;font-weight:800;display:grid;place-items:center}.admin-menu-text{display:block;min-width:0}.admin-menu-text strong{display:block;font-size:13px;line-height:1.3}.admin-menu-text small{display:block;margin-top:3px;font-size:11px;line-height:1.45;color:#81919b}.main{padding:28px}.card{background:#fff;border:1px solid #d8e2e7;padding:18px}.top{display:flex;justify-content:space-between;gap:16px;align-items:center;margin-bottom:16px}.top h1{font-size:20px;margin:0}.top a{color:#3657d6;font-weight:700}.filter{display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap}.filter select,.filter input{height:38px;border:1px solid #c7d2d9;padding:0 10px}.filter input{min-width:300px}.filter button{border:0;background:#24bfd1;color:#fff;font-weight:700;padding:0 18px}.table-wrap{overflow:auto}.table{width:100%;border-collapse:collapse;min-width:1160px}.table th,.table td{padding:11px 9px;border-bottom:1px solid #e2e8ec;text-align:center}.table th{color:#536b77;font-size:12px}.table tbody tr:nth-child(odd){background:#f7f9fb}.no{color:#315cff}.name{text-align:left!important}.detail-link{color:#315cff;font-weight:700}.detail-link:hover{text-decoration:underline}.bulkbar{display:flex;align-items:center;gap:8px;margin:0 0 10px;padding:10px 12px;background:#f7f9fb;border:1px solid #e0e6ea}.bulkbar .selected-count{margin-right:auto;color:#71818b;font-size:12px}.bulkbar select,.row-status,.row-assignee{height:32px;border:1px solid #c7d2d9;background:#fff;padding:0 8px}.action-btn{height:32px;border:0;border-radius:4px;padding:0 11px;font-weight:700;cursor:pointer}.action-btn.primary{background:#3924b9;color:#fff}.action-btn.danger{background:#fff0f0;color:#c23838;border:1px solid #efc7c7}.action-btn.small{height:29px;padding:0 8px;font-size:11px}.check{width:16px;height:16px;cursor:pointer}.row-actions{display:flex;justify-content:center;align-items:center;gap:6px;white-space:nowrap}.badge{display:inline-flex;align-items:center;justify-content:center;padding:4px 7px;border-radius:4px;background:#e9eef4;font-weight:700}.new{background:#eaf8ef;color:#16713a}.kind-direct{background:#edf1ff;color:#405bd7}.kind-quick{background:#fff0e8;color:#ef6a2c}.alert{margin-bottom:12px;padding:14px;background:#fff3f3;border:1px solid #f3bbbb;color:#a5232e}.notice{margin-bottom:12px;padding:12px 14px;background:#fff8e8;border:1px solid #f2dca4;color:#805d13}@media(max-width:920px){.layout{grid-template-columns:1fr}.side{display:none}.main{padding:12px}.filter input{min-width:0;flex:1}}

.row-status,
.row-assignee{
    box-sizing:border-box;
    vertical-align:middle;
    line-height:1;
}

.row-status{
    min-width:88px;
    padding-left:10px;
    padding-right:28px;
}

.row-assignee{
    min-width:105px;
}

@media(max-width:700px){
    .row-assignee{
    min-width:105px;
}
    .action-btn.small{height:32px}
}
@media(max-width:380px){
    .filter{grid-template-columns:1fr}
    .filter input,.filter button{grid-column:auto}
    .bulkbar{grid-template-columns:1fr}
    .bulkbar .selected-count{grid-column:auto}
}

.layout{grid-template-columns:228px minmax(0,1fr)!important}@media(max-width:900px){.layout{grid-template-columns:1fr!important}}
</style>
</head><body><div class="layout">
<?php $currentAdminPage = 'estimates'; require __DIR__ . '/sidebar.php'; ?>
<main class="main"><div class="card"><div class="top"><div><h1>견적 신청 관리</h1><div style="margin-top:5px;color:#84949e">직접견적과 간편견적 신청을 최신순으로 확인합니다.</div></div><a href="../db-test.html" target="_blank">+ 실제 화면에서 견적 신청</a></div>
<?php if ($tableMissing): ?><div class="alert"><strong>estimates 테이블이 없습니다.</strong><br>기존 견적 테이블을 먼저 생성해 주세요.</div><?php endif; ?>
<?php if ($quickTableMissing): ?><div class="notice"><strong>간편견적 테이블이 아직 없습니다.</strong><br>프로젝트 루트의 <code>quick_estimates_table.sql</code>을 phpMyAdmin에서 한 번 실행하면 간편견적도 여기에 표시됩니다.</div><?php endif; ?>
<div style="display:flex;justify-content:flex-end;margin:-4px 0 10px"><a class="action-btn primary" style="display:inline-flex;align-items:center" href="./export-estimates.php?<?=h(http_build_query(['type'=>$type,'status'=>$status,'q'=>$q,'source'=>$source]))?>">엑셀 다운로드</a></div><form class="filter" method="get">
<select name="type"><option value="">전체 유형</option><option value="DIRECT" <?=$type==='DIRECT'?'selected':''?>>직접견적</option><option value="QUICK" <?=$type==='QUICK'?'selected':''?>>간편견적</option></select>
<input name="source" value="<?=h($source)?>" placeholder="유입경로 (예: naver, daangn)" style="min-width:190px"><select name="status"><option value="">전체 상태</option><?php foreach(['NEW'=>'신규','CONTACTED'=>'상담중','REVIEWING'=>'심사중','APPROVED'=>'승인','CONTRACTED'=>'계약완료','CANCELED'=>'취소'] as $k=>$v): ?><option value="<?=h($k)?>" <?=$status===$k?'selected':''?>><?=h($v)?></option><?php endforeach; ?></select>
<input name="q" value="<?=h($q)?>" placeholder="견적번호 / 고객명 / 연락처 / 차량·관심차종"><button>검색</button></form>
<form id="bulkForm" method="post" action="./estimate-actions.php">
<input type="hidden" name="return_query" value="<?=h(http_build_query(['type'=>$type,'status'=>$status,'q'=>$q,'source'=>$source]))?>">
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
<div class="table-wrap"><table class="table"><thead><tr><th><input class="check" type="checkbox" id="checkAll" aria-label="전체 선택"></th><th>번호</th><th>구분</th><th>견적번호</th><th>상태</th><th>담당자</th><th>고객</th><th>연락처</th><th>차량/관심차종</th><th>트림</th><th>이용조건</th><th>월 납입금</th><th>신청일</th><th>관리</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="14" style="padding:50px;color:#9aabb4">저장된 견적이 없습니다.</td></tr><?php endif; ?>
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
<td>
    <select
        class="row-assignee"
        aria-label="<?=h($r['estimate_no'])?> 담당자"
        onchange="saveRowAssignee(this, '<?=h($rowKey)?>')"
    >
        <option value="0">미지정</option>
        <?php foreach($activeAdmins as $admin): ?>
            <option
                value="<?=(int)$admin['id']?>"
                <?=((int)($r['assigned_admin_id'] ?? 0) === (int)$admin['id']) ? 'selected' : ''?>
            ><?=h(adminDisplayName($admin))?></option>
        <?php endforeach; ?>
    </select>
</td>
<td><a class="detail-link" href="./estimate-detail.php?type=<?=strtolower(h($r['_source']))?>&id=<?=(int)$r['id']?>"><?=h($r['customer_name'])?></a></td><td><?=h($r['customer_phone'])?></td>
<td class="name"><?=h($r['_vehicle_display'])?></td><td><?=h($r['trim_name'] ?? '-')?></td>
<td><?=h($r['_condition_display'])?></td><td><?=h($r['_monthly_display'])?></td><td><?=h($r['created_at'])?></td>
<td><div class="row-actions"><button class="action-btn primary small" type="button" onclick="saveRowStatus(this, '<?=h($rowKey)?>')">상태저장</button><button class="action-btn danger small" type="button" onclick="deleteRow('<?=h($rowKey)?>', '<?=h($r['estimate_no'])?>')">삭제</button></div></td>
</tr><?php endforeach; ?>
</tbody></table></div></form></div></main></div><form id="singleActionForm" method="post" action="./estimate-actions.php" hidden>
    <input type="hidden" name="action" id="singleAction">
    <input type="hidden" name="row_key" id="singleRowKey">
    <input type="hidden" name="status" id="singleStatus">
    <input type="hidden" name="assigned_admin_id" id="singleAssignedAdminId">
    <input type="hidden" name="return_query" value="<?=h(http_build_query(['type'=>$type,'status'=>$status,'q'=>$q,'source'=>$source]))?>">
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

function submitSingle(action, rowKey, status='', assignedAdminId=''){
    document.getElementById('singleAction').value = action;
    document.getElementById('singleRowKey').value = rowKey;
    document.getElementById('singleStatus').value = status;
    document.getElementById('singleAssignedAdminId').value = assignedAdminId;
    document.getElementById('singleActionForm').submit();
}

function saveRowAssignee(select, rowKey){
    submitSingle('single_assign', rowKey, '', select.value);
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
