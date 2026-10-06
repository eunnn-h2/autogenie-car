<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
requireAdminCategory('estimates');
require_once __DIR__ . '/estimate-date-filter.php';
try { $dates = estimateDateRange($_GET); }
catch (InvalidArgumentException $e) { http_response_code(400); exit(htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8')); }
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/member-provider.php';
require_once __DIR__ . '/estimate-notes.php';
require_once __DIR__ . '/estimate-contact-tags.php';
if (empty($_SESSION['estimate_action_csrf'])) $_SESSION['estimate_action_csrf'] = bin2hex(random_bytes(32));
$assignmentNotice = $_SESSION['estimate_assignment_notice'] ?? null;
unset($_SESSION['estimate_assignment_notice']);

$contactTagsReady = false;
try {
    ensureEstimateContactTags($pdo);
    $contactTagsReady = true;
} catch (Throwable $tagSetupError) {
    error_log('Estimate contact tags unavailable: ' . $tagSetupError->getMessage());
}

$status = trim((string)($_GET['status'] ?? ''));
$q = trim((string)($_GET['q'] ?? ''));
$type = trim((string)($_GET['type'] ?? '')); // DIRECT=차량견적, QUICK=간편견적, 빈값=전체 견적
$owner = trim((string)($_GET['owner'] ?? '')); // mine=내 담당, unassigned=미배정, handoff=인계필요
$contactTag = strtoupper(trim((string)($_GET['contact_tag'] ?? ''))); // NONE=미지정 또는 상담구분 코드

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
    if ($owner === 'mine') { $where[] = 'e.assigned_admin_id = ?'; $params[] = (int)$_SESSION['admin_id']; }
    elseif ($owner === 'unassigned') { $where[] = 'e.assigned_admin_id IS NULL'; }
    elseif ($owner === 'handoff') { $where[] = "e.assigned_admin_id IS NOT NULL AND (a.id IS NULL OR a.is_active <> 1 OR UPPER(COALESCE(a.role, '')) <> 'SALES')"; }
    if ($contactTag === 'NONE') {
        $where[] = "NOT EXISTS (SELECT 1 FROM admin_estimate_contact_tags ct WHERE ct.estimate_source = 'DIRECT' AND ct.estimate_id = e.id)";
    } elseif ($contactTag !== '') {
        $where[] = "EXISTS (SELECT 1 FROM admin_estimate_contact_tags ct WHERE ct.estimate_source = 'DIRECT' AND ct.estimate_id = e.id AND ct.contact_tag = ?)";
        $params[] = $contactTag;
    }
    if ($q !== '') {
        $where[] = '(e.estimate_no LIKE ? OR e.customer_name LIKE ? OR e.customer_phone LIKE ? OR e.vehicle_name LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = 'SELECT e.*, v.image_path AS _vehicle_image, a.name AS _assigned_name, a.username AS _assigned_username, a.is_active AS _assigned_is_active, a.role AS _assigned_role FROM estimate_direct e LEFT JOIN car_vehicles v ON v.id = e.vehicle_id LEFT JOIN admin_accounts a ON a.id = e.assigned_admin_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY e.id DESC LIMIT 500';
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
    if ($owner === 'mine') { $where[] = 'q.assigned_admin_id = ?'; $params[] = (int)$_SESSION['admin_id']; }
    elseif ($owner === 'unassigned') { $where[] = 'q.assigned_admin_id IS NULL'; }
    elseif ($owner === 'handoff') { $where[] = "q.assigned_admin_id IS NOT NULL AND (a.id IS NULL OR a.is_active <> 1 OR UPPER(COALESCE(a.role, '')) <> 'SALES')"; }
    if ($contactTag === 'NONE') {
        $where[] = "NOT EXISTS (SELECT 1 FROM admin_estimate_contact_tags ct WHERE ct.estimate_source = 'QUICK' AND ct.estimate_id = q.id)";
    } elseif ($contactTag !== '') {
        $where[] = "EXISTS (SELECT 1 FROM admin_estimate_contact_tags ct WHERE ct.estimate_source = 'QUICK' AND ct.estimate_id = q.id AND ct.contact_tag = ?)";
        $params[] = $contactTag;
    }
    if ($q !== '') {
        $where[] = '(q.estimate_no LIKE ? OR q.customer_name LIKE ? OR q.customer_phone LIKE ? OR q.car_type LIKE ?)';
        $like = '%' . $q . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $sql = 'SELECT q.*, a.name AS _assigned_name, a.username AS _assigned_username, a.is_active AS _assigned_is_active, a.role AS _assigned_role FROM estimate_quick q LEFT JOIN admin_accounts a ON a.id = q.assigned_admin_id' . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY q.id DESC LIMIT 500';
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

// 목록에서 바로 확인할 수 있도록 각 견적의 가장 최근 상담 메모를 붙인다.
$latestNotes = [];
try {
    ensureEstimateNotes($pdo);
    $directIds = [];
    $quickIds = [];
    foreach ($rows as $r) {
        if (($r['_source'] ?? '') === 'QUICK') $quickIds[] = (int)$r['id'];
        else $directIds[] = (int)$r['id'];
    }

    $noteWhere = [];
    $noteParams = [];
    if ($directIds) {
        $noteWhere[] = "(estimate_source = 'DIRECT' AND estimate_id IN (" . implode(',', array_fill(0, count($directIds), '?')) . '))';
        array_push($noteParams, ...$directIds);
    }
    if ($quickIds) {
        $noteWhere[] = "(estimate_source = 'QUICK' AND estimate_id IN (" . implode(',', array_fill(0, count($quickIds), '?')) . '))';
        array_push($noteParams, ...$quickIds);
    }

    if ($noteWhere) {
        $noteStmt = $pdo->prepare('SELECT estimate_source, estimate_id, body FROM admin_estimate_notes WHERE ' . implode(' OR ', $noteWhere) . ' ORDER BY id DESC');
        $noteStmt->execute($noteParams);
        foreach ($noteStmt->fetchAll(PDO::FETCH_ASSOC) as $note) {
            $key = (string)$note['estimate_source'] . ':' . (int)$note['estimate_id'];
            if (!array_key_exists($key, $latestNotes)) $latestNotes[$key] = trim((string)$note['body']);
        }
    }
} catch (Throwable $noteListError) {
    error_log('Estimate list notes unavailable: ' . $noteListError->getMessage());
}

$contactTagOptions = estimateContactTagOptions();
$contactTags = [];
if ($contactTagsReady && $rows) {
    try {
        $directIds = [];
        $quickIds = [];
        foreach ($rows as $r) {
            if (($r['_source'] ?? '') === 'QUICK') $quickIds[] = (int)$r['id'];
            else $directIds[] = (int)$r['id'];
        }
        $tagWhere = [];
        $tagParams = [];
        if ($directIds) {
            $tagWhere[] = "(estimate_source = 'DIRECT' AND estimate_id IN (" . implode(',', array_fill(0, count($directIds), '?')) . '))';
            array_push($tagParams, ...$directIds);
        }
        if ($quickIds) {
            $tagWhere[] = "(estimate_source = 'QUICK' AND estimate_id IN (" . implode(',', array_fill(0, count($quickIds), '?')) . '))';
            array_push($tagParams, ...$quickIds);
        }
        if ($tagWhere) {
            $tagStmt = $pdo->prepare('SELECT estimate_source, estimate_id, contact_tag FROM admin_estimate_contact_tags WHERE ' . implode(' OR ', $tagWhere));
            $tagStmt->execute($tagParams);
            foreach ($tagStmt->fetchAll(PDO::FETCH_ASSOC) as $tagRow) {
                $contactTags[(string)$tagRow['estimate_source'] . ':' . (int)$tagRow['estimate_id']] = (string)$tagRow['contact_tag'];
            }
        }
    } catch (Throwable $tagListError) {
        error_log('Estimate list contact tags unavailable: ' . $tagListError->getMessage());
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


// 상단 빠른 필터용 전체 건수
$quickCounts = [
    'all' => 0, 'NEW' => 0, 'CONTACTED' => 0, 'REVIEWING' => 0,
    'APPROVED' => 0, 'CONTRACTED' => 0, 'CANCELED' => 0,
    'DIRECT' => 0, 'QUICK' => 0, 'mine' => 0, 'unassigned' => 0, 'handoff' => 0,
    'TAG_NONE' => 0, 'TAG_CONSULTING' => 0, 'TAG_NO_ANSWER' => 0, 'TAG_MANAGED' => 0, 'TAG_SPECIAL' => 0, 'TAG_SIMPLE' => 0,
];
foreach ([['table' => 'estimate_direct', 'source' => 'DIRECT'], ['table' => 'estimate_quick', 'source' => 'QUICK']] as $meta) {
    try {
        $sourceForCount = $meta['source'];
        $sql = "SELECT e.status, e.assigned_admin_id, a.id AS admin_exists, a.is_active, a.role, ct.contact_tag
                FROM {$meta['table']} e
                LEFT JOIN admin_accounts a ON a.id = e.assigned_admin_id
                LEFT JOIN admin_estimate_contact_tags ct ON ct.estimate_source = " . $pdo->quote($sourceForCount) . " AND ct.estimate_id = e.id";
        foreach ($pdo->query($sql) as $c) {
            $quickCounts['all']++;
            $quickCounts[$meta['source']]++;
            $statusKey = strtoupper((string)($c['status'] ?? ''));
            if (isset($quickCounts[$statusKey])) $quickCounts[$statusKey]++;
            if (empty($c['assigned_admin_id'])) {
                $quickCounts['unassigned']++;
            } else {
                if ((int)$c['assigned_admin_id'] === (int)$_SESSION['admin_id']) $quickCounts['mine']++;
                $activeSales = !empty($c['admin_exists']) && (int)($c['is_active'] ?? 0) === 1 && strtoupper((string)($c['role'] ?? '')) === 'SALES';
                if (!$activeSales) $quickCounts['handoff']++;
            }
            $tagKey = strtoupper((string)($c['contact_tag'] ?? ''));
            if ($tagKey === '') $quickCounts['TAG_NONE']++;
            elseif (isset($quickCounts['TAG_' . $tagKey])) $quickCounts['TAG_' . $tagKey]++;
        }
    } catch (PDOException $e) {
        // 테이블이 아직 없는 환경에서는 해당 건수만 0으로 둔다.
    }
}
function quickFilterUrl(array $set = []): string {
    $params = $set;
    return './estimates.php' . ($params ? '?' . http_build_query($params) : '');
}
?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>견적 관리 - 오토지니</title><link rel="stylesheet" href="./sidebar.css?v=<?= filemtime(__DIR__ . '/sidebar.css') ?>">
<link rel="stylesheet" href="./estimates-page.css?v=<?= filemtime(__DIR__ . '/estimates-page.css') ?>"><link rel="stylesheet" href="./admin-ui.css?v=<?= filemtime(__DIR__ . '/admin-ui.css') ?>"><link rel="stylesheet" href="./date-range-picker.css?v=<?= filemtime(__DIR__ . '/date-range-picker.css') ?>"><script src="./date-range-picker.js?v=<?= filemtime(__DIR__ . '/date-range-picker.js') ?>" defer></script><style><?=estimateContactTagColorCss(getEstimateContactTagColors($pdo))?></style></head><body><div class="layout">
<?php $currentAdminPage = 'estimates'; require __DIR__ . '/sidebar.php'; ?>
<main class="main"><div class="card"><div class="top"><h1>견적문의 관리</h1></div>
<?php if (is_string($assignmentNotice)): ?><div class="notice" role="status"><?=h($assignmentNotice)?></div><?php endif; ?>
<?php if ($tableMissing): ?><div class="alert"><strong>estimates 테이블이 없습니다.</strong><br>기존 견적 테이블을 먼저 생성해 주세요.</div><?php endif; ?>
<?php if ($quickTableMissing): ?><div class="notice"><strong>간편견적 테이블이 아직 없습니다.</strong><br>연결된 DB에 <code>estimate_quick</code> 테이블이 존재하는지 확인해 주세요.</div><?php endif; ?>
<nav class="estimate-filter-tabs" aria-label="견적문의 필터"><div class="estimate-menu-group"><div class="estimate-menu-heading">견적 상태</div>
<a href="<?=h(quickFilterUrl())?>" class="estimate-menu-item <?=($status==='' && $type==='' && $owner==='' && $contactTag==='')?'active':''?>">전체 <span class="estimate-filter-count"><?=number_format($quickCounts['all'])?></span></a>
<a href="<?=h(quickFilterUrl(['status'=>'NEW']))?>" class="estimate-menu-item <?=$status==='NEW'?'active':''?>">신규 <span class="estimate-filter-count"><?=number_format($quickCounts['NEW'])?></span></a>
<a href="<?=h(quickFilterUrl(['status'=>'CONTACTED']))?>" class="estimate-menu-item <?=$status==='CONTACTED'?'active':''?>">상담중 <span class="estimate-filter-count"><?=number_format($quickCounts['CONTACTED'])?></span></a>
<a href="<?=h(quickFilterUrl(['status'=>'REVIEWING']))?>" class="estimate-menu-item <?=$status==='REVIEWING'?'active':''?>">심사중 <span class="estimate-filter-count"><?=number_format($quickCounts['REVIEWING'])?></span></a>
<a href="<?=h(quickFilterUrl(['status'=>'APPROVED']))?>" class="estimate-menu-item <?=$status==='APPROVED'?'active':''?>">승인 <span class="estimate-filter-count"><?=number_format($quickCounts['APPROVED'])?></span></a>
<a href="<?=h(quickFilterUrl(['status'=>'CONTRACTED']))?>" class="estimate-menu-item <?=$status==='CONTRACTED'?'active':''?>">계약완료 <span class="estimate-filter-count"><?=number_format($quickCounts['CONTRACTED'])?></span></a>
<a href="<?=h(quickFilterUrl(['status'=>'CANCELED']))?>" class="estimate-menu-item <?=$status==='CANCELED'?'active':''?>">취소 <span class="estimate-filter-count"><?=number_format($quickCounts['CANCELED'])?></span></a>
</div><div class="estimate-menu-group"><div class="estimate-menu-heading">담당자</div>
<a href="<?=h(quickFilterUrl(['owner'=>'mine']))?>" class="estimate-menu-item <?=$owner==='mine'?'active':''?>">내 담당 <span class="estimate-filter-count"><?=number_format($quickCounts['mine'])?></span></a>
<a href="<?=h(quickFilterUrl(['owner'=>'unassigned']))?>" class="estimate-menu-item <?=$owner==='unassigned'?'active':''?>">미배정 <span class="estimate-filter-count"><?=number_format($quickCounts['unassigned'])?></span></a>
<a href="<?=h(quickFilterUrl(['owner'=>'handoff']))?>" class="estimate-menu-item <?=$owner==='handoff'?'active':''?>">인계필요 <span class="estimate-filter-count"><?=number_format($quickCounts['handoff'])?></span></a>
</div><div class="estimate-menu-group"><div class="estimate-menu-heading">상담구분</div>
<a href="<?=h(quickFilterUrl(['contact_tag'=>'NONE']))?>" class="estimate-menu-item <?=$contactTag==='NONE'?'active':''?>">미지정 <span class="estimate-filter-count"><?=number_format($quickCounts['TAG_NONE'])?></span></a>
<a href="<?=h(quickFilterUrl(['contact_tag'=>'CONSULTING']))?>" class="estimate-menu-item <?=$contactTag==='CONSULTING'?'active':''?>">상담중 <span class="estimate-filter-count"><?=number_format($quickCounts['TAG_CONSULTING'])?></span></a>
<a href="<?=h(quickFilterUrl(['contact_tag'=>'NO_ANSWER']))?>" class="estimate-menu-item <?=$contactTag==='NO_ANSWER'?'active':''?>">부재중 <span class="estimate-filter-count"><?=number_format($quickCounts['TAG_NO_ANSWER'])?></span></a>
<a href="<?=h(quickFilterUrl(['contact_tag'=>'MANAGED']))?>" class="estimate-menu-item <?=$contactTag==='MANAGED'?'active':''?>">관리고객 <span class="estimate-filter-count"><?=number_format($quickCounts['TAG_MANAGED'])?></span></a>
<a href="<?=h(quickFilterUrl(['contact_tag'=>'SPECIAL']))?>" class="estimate-menu-item <?=$contactTag==='SPECIAL'?'active':''?>">특별관리 <span class="estimate-filter-count"><?=number_format($quickCounts['TAG_SPECIAL'])?></span></a>
<a href="<?=h(quickFilterUrl(['contact_tag'=>'SIMPLE']))?>" class="estimate-menu-item <?=$contactTag==='SIMPLE'?'active':''?>">단순문의 <span class="estimate-filter-count"><?=number_format($quickCounts['TAG_SIMPLE'])?></span></a>
</div></nav>
<form class="filter" method="get">
<div class="estimate-date-range" data-date-range data-label="신청일"><span data-range-separator hidden>신청일</span><input type="date" name="from" aria-label="조회 시작일" value="<?=h($dates['from'])?>" hidden><span data-range-separator hidden>~</span><input type="date" name="to" aria-label="조회 종료일" value="<?=h($dates['to'])?>" hidden></div>
<?php if ($type !== ''): ?><input type="hidden" name="type" value="<?=h($type)?>"><?php endif; ?>
<?php if ($status !== ''): ?><input type="hidden" name="status" value="<?=h($status)?>"><?php endif; ?>
<?php if ($owner !== ''): ?><input type="hidden" name="owner" value="<?=h($owner)?>"><?php endif; ?>
<?php if ($contactTag !== ''): ?><input type="hidden" name="contact_tag" value="<?=h($contactTag)?>"><?php endif; ?>
<input name="q" value="<?=h($q)?>" placeholder="견적번호 / 고객명 / 연락처 / 차량·관심차종"><button type="submit">검색</button><a class="estimate-reset reset" href="./estimates.php">초기화</a></form>
<form id="bulkForm" method="post" action="./estimate-actions.php">
<input type="hidden" name="return_query" value="<?=h(http_build_query(['type'=>$type,'status'=>$status,'owner'=>$owner,'contact_tag'=>$contactTag,'q'=>$q,'from'=>$dates['from'],'to'=>$dates['to']]))?>">
<div class="bulkbar">
    <strong>선택 항목</strong>
    <span class="selected-count"><span id="selectedCount">0</span>건 선택</span>
    <?php if (canDeleteData()): ?><button class="action-btn danger" type="submit" name="action" value="bulk_delete" onclick="return confirmBulkDelete()">선택 삭제</button><?php endif; ?>
</div>
<div class="table-wrap"><table class="table estimates-table"><thead><tr><th><input class="check" type="checkbox" id="checkAll" aria-label="전체 선택"></th><th>신청일</th><th>차량 이미지</th><th>차종</th><th>구분</th><th>상태</th><th>상담구분</th><th>고객</th><th>연락처</th><th>담당자</th><th>상담메모</th><th>관리</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="12" style="padding:50px;color:#9aabb4">저장된 견적이 없습니다.</td></tr><?php endif; ?>
<?php foreach($rows as $r): $rowKey = $r['_source'] . ':' . (int)$r['id']; $contactTag = $contactTags[$rowKey] ?? ''; ?><tr class="estimate-row<?= $contactTag !== '' ? ' estimate-row--' . h(strtolower($contactTag)) : '' ?>">
<td><input class="check row-check" type="checkbox" name="selected[]" value="<?=h($rowKey)?>" aria-label="<?=h($r['estimate_no'])?> 선택"></td>
<td class="estimate-created-at"><div class="estimate-date-content"><?php $createdTs = strtotime((string)$r['created_at']); ?><?=h($createdTs ? date('Y-m-d', $createdTs) : (string)$r['created_at'])?><?php if ($createdTs): ?><span><?=h(date('H:i', $createdTs))?></span><?php endif; ?></div></td>
<td class="estimate-image-cell">
    <?php if (!empty($r['_vehicle_image'])): ?>
    <a class="estimate-thumb-link" href="./estimate-detail.php?type=<?=strtolower(h($r['_source']))?>&id=<?=(int)$r['id']?>" aria-label="<?=h($r['_vehicle_display'])?> 견적 상세 보기">
        <img class="estimate-thumb" src="../<?=h(ltrim((string)$r['_vehicle_image'], '/'))?>" alt="<?=h($r['_vehicle_display'])?>" width="88" height="54" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false">
        <span class="estimate-no-image" hidden>이미지 없음</span>
    </a>
    <?php else: ?>
    <a class="estimate-thumb-link" href="./estimate-detail.php?type=<?=strtolower(h($r['_source']))?>&id=<?=(int)$r['id']?>" aria-label="<?=h($r['_vehicle_display'])?> 견적 상세 보기">
        <span class="estimate-no-image"><?=$r['_source']==='QUICK'?'차량 미지정':'이미지 없음'?></span>
    </a>
    <?php endif; ?>
</td>
<td class="name"><a class="detail-link" href="./estimate-detail.php?type=<?=strtolower(h($r['_source']))?>&id=<?=(int)$r['id']?>"><?=h($r['_vehicle_display'])?></a></td>
<td><span class="estimate-kind"><?=h($r['_source_label'])?></span><span class="estimate-product-type"><?=h(!empty($r['product_type']) ? productLabel($r['product_type']) : '상담 후 결정')?></span></td>
<td><span class="estimate-status estimate-status--<?=h(strtolower((string)$r['status']))?>"><?=h(statusLabel((string)$r['status']))?></span></td>
<td class="estimate-contact-tag-cell"><?php if ($contactTag !== '' && isset($contactTagOptions[$contactTag])): ?><span class="estimate-contact-tag estimate-contact-tag--<?=h(strtolower($contactTag))?>"><?=h($contactTagOptions[$contactTag])?></span><?php else: ?><span class="estimate-contact-tag-empty">-</span><?php endif; ?></td>
<td><a class="detail-link" href="./estimate-detail.php?type=<?=strtolower(h($r['_source']))?>&id=<?=(int)$r['id']?>"><?=h($r['customer_name'])?></a></td><td><?=h($r['customer_phone'])?></td>
<td>
    <?php $hasActiveSalesOwner = !empty($r['assigned_admin_id']) && (int)($r['_assigned_is_active'] ?? 0) === 1 && strtoupper((string)($r['_assigned_role'] ?? '')) === 'SALES'; ?>
    <div class="estimate-owner-cell">
        <?php if (!empty($r['assigned_admin_id'])): ?>
            <div class="estimate-owner-info">
                <span class="estimate-assignee"><?=h($r['_assigned_name'] ?: ($r['_assigned_username'] ?: '담당자'))?></span>
            </div>
        <?php endif; ?>
        <?php if (isSalesAdmin() && !$hasActiveSalesOwner): ?>
            <button class="action-btn primary small estimate-claim-btn" type="button" data-claim-estimate="<?=h($rowKey)?>" data-estimate-no="<?=h($r['estimate_no'])?>"><?=empty($r['assigned_admin_id']) ? '담당하기' : '인계받기'?></button>
        <?php elseif (empty($r['assigned_admin_id'])): ?><span class="estimate-unassigned">미배정</span><?php endif; ?>
    </div>
</td>
<td class="estimate-note-cell"><?php $latestNote = $latestNotes[$rowKey] ?? ''; ?><?php if ($latestNote !== ''): ?><span class="estimate-note-preview" title="<?=h($latestNote)?>"><?=h($latestNote)?></span><?php else: ?><span class="estimate-note-empty">-</span><?php endif; ?></td>
<td><div class="row-actions"><a class="action-btn edit small" href="./estimate-detail.php?type=<?=strtolower(h($r['_source']))?>&id=<?=(int)$r['id']?>"><?php if (canUpdateData()): ?><svg class="estimate-edit-icon" width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 5 4 4M4 20l4-1L20 7a2.8 2.8 0 0 0-4-4L4 15z"/></svg><?php endif; ?><?=canUpdateData() ? '수정' : '상세'?></a></div></td>
</tr><?php endforeach; ?>
</tbody></table></div></form></div></main></div><form id="singleActionForm" method="post" action="./estimate-actions.php" hidden>
    <input type="hidden" name="action" id="singleAction">
    <input type="hidden" name="row_key" id="singleRowKey">
    <input type="hidden" name="csrf_token" value="<?=h($_SESSION['estimate_action_csrf'])?>">
    <input type="hidden" name="return_query" value="<?=h(http_build_query(['type'=>$type,'status'=>$status,'owner'=>$owner,'q'=>$q,'from'=>$dates['from'],'to'=>$dates['to']]))?>">
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

function submitSingle(action, rowKey){
    document.getElementById('singleAction').value = action;
    document.getElementById('singleRowKey').value = rowKey;
    document.getElementById('singleActionForm').submit();
}
document.querySelectorAll('[data-claim-estimate]').forEach(button => button.addEventListener('click', () => {
    if (!confirm(button.dataset.estimateNo + ' 고객의 담당자로 등록할까요?')) return;
    button.disabled = true;
    submitSingle('claim', button.dataset.claimEstimate);
}));
function deleteRow(rowKey, estimateNo){
    if (!confirm(estimateNo + ' 견적을 삭제할까요?\n삭제한 데이터는 복구할 수 없습니다.')) return;
    submitSingle('single_delete', rowKey);
}
function checkedCount(){ return rowChecks.filter(el => el.checked).length; }
function confirmBulkDelete(){
    const count = checkedCount();
    if (!count) { alert('삭제할 견적을 선택해 주세요.'); return false; }
    return confirm(count + '건의 견적을 삭제할까요?\n삭제한 데이터는 복구할 수 없습니다.');
}
updateSelection();
</script></body></html>
