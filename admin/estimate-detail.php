<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
requireAdminCategory('estimates');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete') {
    requireDataPermission('delete');
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/member-provider.php';
require_once __DIR__ . '/estimate-assignment.php';
require_once __DIR__ . '/estimate-notes.php';
require_once __DIR__ . '/estimate-contact-tags.php';

$id = (int)($_GET['id'] ?? 0);
$type = strtolower(trim((string)($_GET['type'] ?? 'direct')));
$isQuick = $type === 'quick';
if ($id < 1) { http_response_code(400); exit('잘못된 견적 ID입니다.'); }

$table = $isQuick ? 'estimate_quick' : 'estimate_direct';
if (empty($_SESSION['estimate_detail_csrf'])) $_SESSION['estimate_detail_csrf'] = bin2hex(random_bytes(32));
$notice = '';
$error = '';
$noteDraft = null;
$notesReady = false;
$contactTagsReady = false;
try {
    ensureEstimateNotes($pdo);
    $notesReady = true;
} catch (Throwable $notesError) {
    error_log('Estimate notes unavailable: ' . $notesError->getMessage());
}
try {
    ensureEstimateContactTags($pdo);
    $contactTagsReady = true;
} catch (Throwable $tagSetupError) {
    error_log('Estimate contact tags unavailable: ' . $tagSetupError->getMessage());
}

function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function val(mixed $v): string { return ($v === null || $v === '') ? '-' : h($v); }
function productLabel(mixed $v): string {
    return match (strtoupper((string)$v)) {
        'RENT' => '장기렌트',
        'LEASE' => '리스',
        default => ($v === null || $v === '') ? '-' : h($v),
    };
}

function loadEstimate(PDO $pdo, string $table, int $id): array|false {
    $stmt = $pdo->prepare("SELECT e.*, a.name AS _assigned_name, a.username AS _assigned_username, a.is_active AS _assigned_is_active, a.role AS _assigned_role FROM {$table} e LEFT JOIN admin_accounts a ON a.id = e.assigned_admin_id WHERE e.id=? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

try {
    $e = loadEstimate($pdo, $table, $id);
} catch (PDOException $ex) {
    if ($isQuick && str_contains($ex->getMessage(), "doesn't exist")) {
        http_response_code(500);
        exit('간편견적 테이블 estimate_quick을 찾을 수 없습니다. DB 연결을 확인해 주세요.');
    }
    throw $ex;
}
if (!$e) { http_response_code(404); exit('견적을 찾을 수 없습니다.'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)$_SESSION['estimate_detail_csrf'], $token)) {
        http_response_code(403);
        exit('요청을 확인할 수 없습니다. 새로고침 후 다시 시도해 주세요.');
    }

    $action = (string)($_POST['action'] ?? 'status');

    if ($action === 'assign') {
        $requestedId = (int)($_POST['assigned_admin_id'] ?? 0);
        try {
            if (in_array(adminRole(), ['SUPER_ADMIN', 'ADMIN'], true)) {
                assignEstimate($pdo, $table, $id, $requestedId > 0 ? $requestedId : null);
                $notice = $requestedId > 0 ? '담당자를 배정했습니다.' : '담당자 배정을 해제했습니다.';
            } elseif (isSalesAdmin()) {
                $myId = (int)$_SESSION['admin_id'];
                if ($requestedId !== $myId) {
                    throw new RuntimeException('영업사원 계정은 본인에게만 담당 배정할 수 있습니다.');
                }
                if ((int)($e['assigned_admin_id'] ?? 0) !== $myId && !claimEstimate($pdo, $table, $id, $myId)) {
                    throw new RuntimeException('이미 활동 중인 담당자가 배정된 견적입니다. 담당자 정보를 다시 확인해 주세요.');
                }
                $notice = '내 담당 견적으로 배정했습니다.';
            } else {
                throw new RuntimeException('담당자를 배정할 권한이 없습니다.');
            }
        } catch (Throwable $assignError) {
            $error = $assignError->getMessage();
        }
    } elseif ($action === 'save_note') {
        if (isSalesAdmin() && (int)($e['assigned_admin_id'] ?? 0) !== (int)$_SESSION['admin_id']) {
            http_response_code(403);
            exit('본인 담당 견적에만 상담 메모를 작성할 수 있습니다.');
        }
        $noteDraft = is_string($_POST['note_body'] ?? null) ? $_POST['note_body'] : '';
        try {
            if (!$notesReady) throw new RuntimeException('상담 메모 저장 공간을 준비하지 못했습니다. 잠시 후 다시 시도해 주세요.');
            $saved = addEstimateNote($pdo, $table, $id, (int)$_SESSION['admin_id'], (string)($_SESSION['admin_name'] ?? $_SESSION['admin_username']), $noteDraft, isSalesAdmin());
            if (!$saved) {
                http_response_code(403);
                $error = '담당자가 변경되었거나 견적을 찾을 수 없어 메모를 저장하지 못했습니다.';
            } else {
                header('Location: ./estimate-detail.php?' . http_build_query(['type' => $isQuick ? 'quick' : 'direct', 'id' => $id]) . '#consultation-notes', true, 303);
                exit;
            }
        } catch (InvalidArgumentException $noteError) {
            $error = $noteError->getMessage();
        } catch (Throwable $noteError) {
            error_log('Estimate note save failed: ' . $noteError->getMessage());
            $error = '메모를 저장하지 못했습니다. 작성 내용을 확인하고 다시 시도해 주세요.';
        }
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM {$table} WHERE id=?")->execute([$id]);
        header('Location: ./estimates.php');
        exit;
    } else {
        if (isSalesAdmin() && (int)($e['assigned_admin_id'] ?? 0) !== (int)$_SESSION['admin_id']) {
            http_response_code(403);
            exit('본인 담당 견적만 상태를 변경할 수 있습니다.');
        }
        $allowed = ['NEW','CONTACTED','REVIEWING','APPROVED','CONTRACTED','CANCELED'];
        $newStatus = (string)($_POST['status'] ?? '');
        if (in_array($newStatus, $allowed, true)) {
            $statusChanged = $newStatus !== (string)($e['status'] ?? '');
            $statusSaved = true;

            if ($statusChanged) {
                $statusSaved = updateEstimateStatus($pdo, $table, $id, $newStatus, isSalesAdmin() ? (int)$_SESSION['admin_id'] : null);
            }

            $tagSaved = false;
            try {
                if (!$contactTagsReady) {
                    throw new RuntimeException('상담 구분 저장 공간을 준비하지 못했습니다. 잠시 후 다시 시도해 주세요.');
                }
                saveEstimateContactTag(
                    $pdo,
                    $isQuick ? 'QUICK' : 'DIRECT',
                    $id,
                    (string)($_POST['contact_tag'] ?? ''),
                    (int)($_SESSION['admin_id'] ?? 0) ?: null
                );
                $tagSaved = true;
            } catch (Throwable $tagSaveError) {
                $error = $tagSaveError->getMessage();
            }

            if ($statusSaved && $tagSaved) {
                $notice = '상태와 상담 구분을 저장했습니다.';
            } elseif (!$statusSaved && $error === '') {
                $error = '상태가 변경되지 않았습니다. 현재 상태와 담당자를 확인해 주세요.';
            }
        }
    }

    $e = loadEstimate($pdo, $table, $id);
    if (!$e) { http_response_code(404); exit('견적을 찾을 수 없습니다.'); }
}

$memberProviders = adminMemberProviders($pdo, [$e]);
$salesAdmins = [];
try {
    $salesAdmins = $pdo->query("SELECT id, name, username FROM admin_accounts WHERE role='SALES' AND is_active=1 ORDER BY COALESCE(NULLIF(name,''), username), id")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $ignored) {
    $salesAdmins = [];
}
$canChooseAnyAssignee = in_array(adminRole(), ['SUPER_ADMIN', 'ADMIN'], true);
$canAssignSelf = isSalesAdmin();
$currentAssignee = trim((string)($e['_assigned_name'] ?? '')) ?: trim((string)($e['_assigned_username'] ?? ''));
$currentAssigneeId = (int)($e['assigned_admin_id'] ?? 0);
$currentAssigneeIsActive = $currentAssigneeId > 0 && (int)($e['_assigned_is_active'] ?? 0) === 1;
$isAssignedToMe = isSalesAdmin() && $currentAssigneeId > 0 && $currentAssigneeId === (int)($_SESSION['admin_id'] ?? 0);
$canUpdateStatus = !isSalesAdmin() || $isAssignedToMe;
$contactTagOptions = estimateContactTagOptions();
$currentContactTag = '';
if ($contactTagsReady) {
    try {
        $currentContactTag = getEstimateContactTag($pdo, $isQuick ? 'QUICK' : 'DIRECT', $id);
    } catch (Throwable $tagLoadError) {
        $contactTagsReady = false;
        error_log('Estimate contact tag load failed: ' . $tagLoadError->getMessage());
    }
}

$consultationNotes = [];
if ($notesReady) {
    try {
        $notesQuery = $pdo->prepare('SELECT body FROM admin_estimate_notes WHERE estimate_source = ? AND estimate_id = ? ORDER BY id DESC LIMIT 1');
        $notesQuery->execute([$isQuick ? 'QUICK' : 'DIRECT', $id]);
        $consultationNotes = $notesQuery->fetchAll(PDO::FETCH_ASSOC);
        if ($noteDraft === null) $noteDraft = (string)($consultationNotes[0]['body'] ?? '');
    } catch (Throwable $notesError) { $notesReady = false; }
}
?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($e['estimate_no'])?> - 견적 상세</title><link rel="stylesheet" href="./estimate-detail-page.css?v=<?= filemtime(__DIR__ . '/estimate-detail-page.css') ?>"><link rel="stylesheet" href="./admin-ui.css"><style><?=estimateContactTagColorCss(getEstimateContactTagColors($pdo))?></style></head><body class="estimate-detail"><div class="wrap"><a class="back" href="./estimates.php">← 견적목록</a><div class="card">
<?php if ($notice !== ''): ?><div class="detail-notice" role="status"><?=h($notice)?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="detail-error" role="alert"><?=h($error)?></div><?php endif; ?>
<div class="head"><div><h1><?=h($e['estimate_no'])?></h1><p>신청일 <?=h($e['created_at'])?></p><span class="kind <?= $isQuick ? 'kind--quick' : 'kind--direct' ?>"><?=$isQuick?'간편견적':'직접견적'?></span></div><form class="status-form" id="estimateStatusForm" method="post"><input type="hidden" name="csrf_token" value="<?=h($_SESSION['estimate_detail_csrf'])?>"><select name="status" <?=$canUpdateStatus ? '' : 'disabled'?>><?php foreach(['NEW'=>'신규','CONTACTED'=>'상담중','REVIEWING'=>'심사중','APPROVED'=>'승인','CONTRACTED'=>'계약완료','CANCELED'=>'취소'] as $k=>$v): ?><option value="<?=$k?>" <?=$e['status']===$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select><?php if ($canUpdateStatus): ?><button name="action" value="status">상태 저장</button><?php endif; ?><?php if (canDeleteData()): ?><button class="delete-btn" name="action" value="delete" onclick="return confirm('이 견적을 삭제할까요? 삭제 후 복구할 수 없습니다.')">삭제</button><?php endif; ?></form></div>

<?php if (isSalesAdmin() && !$isAssignedToMe): ?><p class="assignment-help">본인 담당 견적만 상태를 변경할 수 있습니다. 미배정 견적은 담당 배정 후 변경해 주세요.</p><?php endif; ?>
<div class="section assignment-section"><div class="section-title-row"><h2>담당자 배정</h2><span class="assignment-current">현재 담당자 <strong><?=h($currentAssignee !== '' ? $currentAssignee : '미배정')?><?php if ($currentAssigneeId > 0 && !$currentAssigneeIsActive): ?> <em>(퇴사/비활성)</em><?php endif; ?></strong></span></div>
<?php if ($canChooseAnyAssignee): ?>
<form class="assignment-form" method="post">
<input type="hidden" name="csrf_token" value="<?=h($_SESSION['estimate_detail_csrf'])?>">
<div class="assignment-control">
<select name="assigned_admin_id" aria-label="담당자 선택">
<option value="0">미배정</option>
<?php foreach($salesAdmins as $admin): $adminLabel = trim((string)($admin['name'] ?? '')) ?: (string)$admin['username']; ?>
<option value="<?=(int)$admin['id']?>" <?=$currentAssigneeId===(int)$admin['id']?'selected':''?>><?=h($adminLabel)?> (<?=h($admin['username'])?>)</option>
<?php endforeach; ?>
</select>
<button type="submit" name="action" value="assign"><?=$currentAssigneeId > 0 ? '담당자 변경' : '담당자 배정'?></button>
</div>
<?php if ($currentAssigneeId > 0 && !$currentAssigneeIsActive): ?><p class="assignment-note">퇴사·비활성 처리된 담당자의 견적입니다. 위 목록에서 새 영업사원을 선택해 바로 넘길 수 있습니다.</p><?php endif; ?>
</form>
<?php elseif ($canAssignSelf): ?>
<?php if ($isAssignedToMe): ?>
<div class="assignment-state assignment-state--mine">내 담당 견적으로 배정되어 있습니다.</div>
<?php elseif ($currentAssigneeId > 0 && $currentAssigneeIsActive): ?>
<div class="assignment-state">이미 다른 담당자가 배정된 견적입니다.</div>
<?php else: ?>
<form class="assignment-form assignment-form--self" method="post">
<input type="hidden" name="csrf_token" value="<?=h($_SESSION['estimate_detail_csrf'])?>">
<input type="hidden" name="assigned_admin_id" value="<?=(int)$_SESSION['admin_id']?>">
<button type="submit" name="action" value="assign">내 담당으로 배정</button>
</form>
<?php endif; ?>
<?php else: ?><p class="assignment-help">담당자 배정 권한이 없습니다.</p><?php endif; ?>
</div>

<div class="section contact-tag-section">
<div class="section-title-row"><h2>상담 구분</h2><span class="contact-tag-help">목록 화면에 표시됩니다.</span></div>
<?php if (!$contactTagsReady): ?>
<p class="assignment-help" role="alert">상담 구분을 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.</p>
<?php else: ?>
<div class="contact-tag-form">
<div class="contact-tag-options">
<label class="contact-tag-option contact-tag-option--none"><input type="radio" name="contact_tag" value="" form="estimateStatusForm" <?=$currentContactTag===''?'checked':''?> <?=!$canUpdateStatus?'disabled':''?>><span>미지정</span></label>
<?php foreach($contactTagOptions as $tagKey=>$tagLabel): ?>
<label class="contact-tag-option contact-tag-option--<?=h(strtolower($tagKey))?>"><input type="radio" name="contact_tag" value="<?=h($tagKey)?>" form="estimateStatusForm" <?=$currentContactTag===$tagKey?'checked':''?> <?=!$canUpdateStatus?'disabled':''?>><span><?=h($tagLabel)?></span></label>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>
</div>

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
<div class="section"><h2>이용 조건</h2><div class="info"><div class="item"><span>상품</span><b><?=productLabel($e['product_type'])?></b></div><div class="item"><span>계약기간</span><b><?=val($e['contract_months'])?>개월</b></div><div class="item"><span>선납률</span><b><?=val($e['prepayment_rate'])?>%</b></div><div class="item"><span>연 주행거리</span><b><?=isset($e['annual_mileage'])?number_format((int)$e['annual_mileage']).'km':'-'?></b></div><div class="item"><span>월 납입금</span><b><?=isset($e['monthly_payment'])?number_format((int)$e['monthly_payment']).'원':'-'?></b></div><?php if (!empty($e['registration_region'])): ?><div class="item"><span>차량 등록 지역</span><b><?=h($e['registration_region'])?></b></div><?php endif; ?></div></div>
<div class="section"><h2>고객 메모</h2><div class="memo"><?=val($e['customer_memo'])?></div></div>

<?php endif; ?>
<section class="section" id="consultation-notes"><h2>상담 메모</h2>
<?php if (!$notesReady): ?><p role="alert">상담 메모를 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.</p><?php endif; ?>
<form method="post" class="consultation-note-form">
<input type="hidden" name="csrf_token" value="<?=h($_SESSION['estimate_detail_csrf'])?>">
<input type="hidden" name="action" value="save_note">
<textarea id="noteBody" name="note_body" aria-label="상담 메모" rows="5" maxlength="5000" required <?=!$canUpdateStatus || !$notesReady ? 'readonly' : ''?> style="display:block;box-sizing:border-box;width:100%;margin:8px 0 12px;padding:12px;border:1px solid #d8e1eb;border-radius:8px;font:inherit;resize:vertical"><?=h($noteDraft ?? '')?></textarea>
<?php if ($canUpdateStatus): ?><button type="submit" <?=!$notesReady ? 'disabled' : ''?>>메모 저장</button><?php endif; ?>
</form>
</section>
</div></div><script src="./admin-delete-guard.js" defer></script></body></html>
