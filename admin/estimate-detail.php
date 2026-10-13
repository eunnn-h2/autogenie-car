<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
requireAdminCategory('estimates');
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete') {
    requireDataPermission('delete');
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/estimate-assignment.php';
require_once __DIR__ . '/estimate-notes.php';
require_once __DIR__ . '/estimate-contact-tags.php';
require_once __DIR__ . '/estimate-save.php';
require_once __DIR__ . '/../config/estimate-region.php';

$id = (int)($_GET['id'] ?? 0);
$type = strtolower(trim((string)($_GET['type'] ?? 'direct')));
$isQuick = $type === 'quick';
if ($id < 1) { http_response_code(400); exit('잘못된 견적 ID입니다.'); }

$table = $isQuick ? 'estimate_quick' : 'estimate_direct';
if (empty($_SESSION['estimate_detail_csrf'])) $_SESSION['estimate_detail_csrf'] = bin2hex(random_bytes(32));
$notice = '';
$error = '';
$noteDraft = null;
$editDraft = null;
$canEditDetails = canEditEstimateDetails(adminRole());
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

    $action = (string)($_POST['action'] ?? 'save');

    if ($action === 'save') {
        $editDraft = (($_POST['edit_details'] ?? '') === '1' && $canEditDetails) ? array_filter($_POST, 'is_string') : null;
        $noteDraft = is_string($_POST['note_body'] ?? null) ? $_POST['note_body'] : null;
        try {
            if ($editDraft !== null && !$isQuick && !array_key_exists('registration_region', $e)) ensureEstimateRegionColumn($pdo);
            saveEstimateForm($pdo, $table, $id, adminRole(), (int)$_SESSION['admin_id'], (string)($_SESSION['admin_name'] ?? $_SESSION['admin_username']), $_POST, $notesReady, $contactTagsReady);
            $editDraft = null;
            $noteDraft = null;
            $notice = '변경사항을 저장했습니다.';
        } catch (PDOException $saveError) {
            error_log('Estimate save failed: ' . $saveError->getMessage());
            $error = '변경사항을 저장하지 못했습니다. 잠시 후 다시 시도해 주세요.';
        } catch (InvalidArgumentException | RuntimeException $saveError) {
            $error = $saveError->getMessage();
        } catch (Throwable $saveError) {
            error_log('Estimate save failed: ' . $saveError->getMessage());
            $error = '변경사항을 저장하지 못했습니다. 잠시 후 다시 시도해 주세요.';
        }
    } elseif ($action === 'assign') {
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
    } elseif ($action === 'delete') {
        $pdo->prepare("DELETE FROM {$table} WHERE id=?")->execute([$id]);
        header('Location: ./estimates.php');
        exit;
    } else {
        $error = '잘못된 요청입니다.';
    }

    $e = loadEstimate($pdo, $table, $id);
    if (!$e) { http_response_code(404); exit('견적을 찾을 수 없습니다.'); }
}

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
$canUpdateStatus = $canEditDetails || $isAssignedToMe;
$displayStatus = $error !== '' && is_string($_POST['status'] ?? null) ? $_POST['status'] : $e['status'];
$selectedAssigneeId = $error !== '' && is_string($_POST['assigned_admin_id'] ?? null) ? (int)$_POST['assigned_admin_id'] : $currentAssigneeId;
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
if ($error !== '' && is_string($_POST['contact_tag'] ?? null)) $currentContactTag = $_POST['contact_tag'];
if ($notesReady) {
    try {
        $notesQuery = $pdo->prepare('SELECT body FROM admin_estimate_notes WHERE estimate_source = ? AND estimate_id = ? ORDER BY id DESC LIMIT 1');
        $notesQuery->execute([$isQuick ? 'QUICK' : 'DIRECT', $id]);
        $consultationNotes = $notesQuery->fetchAll(PDO::FETCH_ASSOC);
        if ($noteDraft === null) $noteDraft = (string)($consultationNotes[0]['body'] ?? '');
    } catch (Throwable $notesError) { $notesReady = false; }
}
?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($e['estimate_no'])?> - 견적 상세</title><link rel="stylesheet" href="./estimate-detail-page.css?v=<?= filemtime(__DIR__ . '/estimate-detail-page.css') ?>"><link rel="stylesheet" href="./admin-ui.css"><link rel="stylesheet" href="./admin-mobile-refresh.css?v=<?= filemtime(__DIR__ . '/admin-mobile-refresh.css') ?>"><style><?=estimateContactTagColorCss(getEstimateContactTagColors($pdo))?></style></head><body class="estimate-detail"><div class="wrap"><a class="back" href="./estimates.php">← 견적목록</a><div class="card">
<?php if ($notice !== ''): ?><div class="detail-notice" role="status"><?=h($notice)?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="detail-error" role="alert"><?=h($error)?></div><?php endif; ?>
<div class="head"><div><h1><?=h($e['estimate_no'])?></h1><p>신청일 <?=h($e['created_at'])?></p><span class="kind <?= $isQuick ? 'kind--quick' : 'kind--direct' ?>"><?=$isQuick?'간편견적':'직접견적'?></span></div><form class="status-form" id="estimateStatusForm" method="post"><input type="hidden" name="csrf_token" value="<?=h($_SESSION['estimate_detail_csrf'])?>"><select name="status" <?=$canUpdateStatus ? '' : 'disabled'?>><?php foreach(['NEW'=>'신규','CONTACTED'=>'상담중','REVIEWING'=>'심사중','APPROVED'=>'승인','CONTRACTED'=>'계약완료','CANCELED'=>'취소'] as $k=>$v): ?><option value="<?=$k?>" <?=$displayStatus===$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select><?php if ($canUpdateStatus): ?><button name="action" value="save">저장</button><?php endif; ?><?php if (canDeleteData()): ?><button class="delete-btn" name="action" value="delete" formnovalidate onclick="return confirm('이 견적을 삭제할까요? 삭제 후 복구할 수 없습니다.')">삭제</button><?php endif; ?></form></div>

<?php if (isSalesAdmin() && !$isAssignedToMe): ?><p class="assignment-help">본인 담당 견적만 상태를 변경할 수 있습니다. 미배정 견적은 담당 배정 후 변경해 주세요.</p><?php endif; ?>
<div class="section assignment-section"><div class="section-title-row"><h2>담당자 배정</h2><span class="assignment-current">현재 담당자 <strong><?=h($currentAssignee !== '' ? $currentAssignee : '미배정')?><?php if ($currentAssigneeId > 0 && !$currentAssigneeIsActive): ?> <em>(퇴사/비활성)</em><?php endif; ?></strong></span></div>
<?php if ($canChooseAnyAssignee): ?>
<div class="assignment-form">
<div class="assignment-control">
<select name="assigned_admin_id" aria-label="담당자 선택" form="estimateStatusForm">
<option value="0" <?=$selectedAssigneeId === 0 ? 'selected' : ''?>>미배정</option>
<?php if ($selectedAssigneeId > 0 && !in_array($selectedAssigneeId, array_map('intval', array_column($salesAdmins, 'id')), true)): ?>
<option value="<?=$selectedAssigneeId?>" selected><?=h($selectedAssigneeId === $currentAssigneeId ? $currentAssignee : '선택한 담당자')?> (현재 선택)</option>
<?php endif; ?>
<?php foreach($salesAdmins as $admin): $adminLabel = trim((string)($admin['name'] ?? '')) ?: (string)$admin['username']; ?>
<option value="<?=(int)$admin['id']?>" <?=$selectedAssigneeId===(int)$admin['id']?'selected':''?>><?=h($adminLabel)?> (<?=h($admin['username'])?>)</option>
<?php endforeach; ?>
</select>

</div>
<?php if ($currentAssigneeId > 0 && !$currentAssigneeIsActive): ?><p class="assignment-note">퇴사·비활성 처리된 담당자의 견적입니다. 위 목록에서 새 영업사원을 선택해 바로 넘길 수 있습니다.</p><?php endif; ?>
</div>
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

<?php require __DIR__ . '/estimate-detail-fields.php'; ?>
<section class="section" id="consultation-notes"><h2>상담 메모</h2>
<?php if (!$notesReady): ?><p role="alert">상담 메모를 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.</p><?php endif; ?>
<div class="consultation-note-form">
<textarea id="noteBody" name="note_body" form="estimateStatusForm" aria-label="상담 메모" rows="5" maxlength="5000" <?=!$canUpdateStatus || !$notesReady ? 'disabled' : ''?> style="display:block;box-sizing:border-box;width:100%;margin:8px 0 12px;padding:12px;border:1px solid #d8e1eb;border-radius:8px;font:inherit;resize:vertical"><?=h($noteDraft ?? '')?></textarea>
</div>
</section>
</div></div><script src="./admin-delete-guard.js" defer></script></body></html>
