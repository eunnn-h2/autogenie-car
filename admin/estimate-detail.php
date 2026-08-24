<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

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

    if ($action === 'assign') {
        $assignedAdminId = (int)($_POST['assigned_admin_id'] ?? 0);
        $assignedValue = null;

        if ($assignedAdminId > 0) {
            $check = $pdo->prepare("
                SELECT id
                FROM admin_accounts
                WHERE id = ? AND is_active = 1
                LIMIT 1
            ");
            $check->execute([$assignedAdminId]);

            if (!$check->fetchColumn()) {
                http_response_code(400);
                exit('선택한 담당자를 찾을 수 없습니다.');
            }

            $assignedValue = $assignedAdminId;
        }

        $pdo->prepare("UPDATE {$table} SET assigned_admin_id=? WHERE id=?")
            ->execute([$assignedValue, $id]);

        header('Location: ./estimate-detail.php?type=' . ($isQuick ? 'quick' : 'direct') . '&id=' . $id);
        exit;
    }

    if ($action === 'comment') {
        $comment = trim((string)($_POST['admin_comment'] ?? ''));

        if (mb_strlen($comment, 'UTF-8') > 5000) {
            $comment = mb_substr($comment, 0, 5000, 'UTF-8');
        }

        $adminName = (string)($_SESSION['admin_username'] ?? '관리자');

        $stmt = $pdo->prepare("
            UPDATE {$table}
            SET admin_comment = ?,
                admin_comment_by = ?,
                admin_comment_updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$comment, $adminName, $id]);

        header('Location: ./estimate-detail.php?type=' . ($isQuick ? 'quick' : 'direct') . '&id=' . $id . '#consult-comment');
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
        exit('quick_estimate_direct 테이블이 없습니다. quick_estimates_table.sql을 먼저 실행해 주세요.');
    }
    throw $ex;
}
if (!$e) { http_response_code(404); exit('견적을 찾을 수 없습니다.'); }

$activeAdmins = $pdo->query("
    SELECT id, username, name
    FROM admin_accounts
    WHERE is_active = 1
    ORDER BY COALESCE(NULLIF(name, ''), username) ASC
")->fetchAll(PDO::FETCH_ASSOC);


function h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function val(mixed $v): string { return ($v === null || $v === '') ? '-' : h($v); }
function adminDisplayName(array $admin): string {
    $name = trim((string)($admin['name'] ?? ''));
    return $name !== '' ? $name : (string)($admin['username'] ?? '');
}
function productLabel(mixed $v): string {
    return match (strtoupper((string)$v)) {
        'RENT' => '장기렌트',
        'LEASE' => '리스',
        default => ($v === null || $v === '') ? '-' : h($v),
    };
}
?>
<!DOCTYPE html><html lang="ko"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=h($e['estimate_no'])?> - 견적 상세</title><style>
*{box-sizing:border-box}body{margin:0;background:#eef5f8;color:#25384a;font-family:Pretendard,"Noto Sans KR",Arial,sans-serif}.wrap{width:min(1000px,calc(100% - 28px));margin:30px auto}.back{display:inline-block;margin-bottom:12px;color:#405bd7;font-weight:700}.card{background:#fff;border:1px solid #d8e2e7;border-radius:8px;padding:22px}.head{display:flex;justify-content:space-between;gap:20px;align-items:start;padding-bottom:18px;border-bottom:1px solid #e5eaee}.head h1{margin:0;font-size:22px}.head p{margin:6px 0 0;color:#85949e}.kind{display:inline-flex;margin-top:8px;padding:5px 8px;border-radius:4px;font-size:12px;font-weight:800;background:<?=$isQuick?'#fff0e8':'#edf1ff'?>;color:<?=$isQuick?'#ef6a2c':'#405bd7'?>}.status-form{display:flex;gap:7px}.status-form select{height:38px;border:1px solid #cbd5dc;padding:0 10px}.status-form button{border:0;background:#3526ba;color:#fff;padding:0 14px;font-weight:700;cursor:pointer}.status-form .delete-btn{background:#fff0f0;color:#c23838;border:1px solid #efc7c7}.section{margin-top:22px}.section h2{font-size:15px;margin:0 0 10px}.info{display:grid;grid-template-columns:1fr 1fr;border-top:1px solid #dfe6ea;border-left:1px solid #dfe6ea}.item{display:grid;grid-template-columns:130px 1fr;border-right:1px solid #dfe6ea;border-bottom:1px solid #dfe6ea}.item span,.item b{padding:12px}.item span{background:#f6f8fa;color:#6d7d87}.item b{font-weight:600}.payment{color:#3526ba;font-size:20px}.memo{padding:15px;border:1px solid #dfe6ea;background:#fafcfd;white-space:pre-wrap;min-height:90px}
.comment-box{padding:16px;border:1px solid #dfe6ea;background:#fafcfd;border-radius:6px}
.comment-box textarea{display:block;width:100%;min-height:130px;resize:vertical;border:1px solid #ccd7de;border-radius:6px;background:#fff;padding:13px 14px;color:#25384a;font:inherit;font-size:13px;line-height:1.65;outline:none}
.comment-box textarea:focus{border-color:#7467d9;box-shadow:0 0 0 2px rgba(53,38,186,.08)}
.comment-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:10px}
.comment-meta{color:#85949e;font-size:11px;line-height:1.4}
.comment-save{height:38px;border:0;border-radius:4px;background:#3526ba;color:#fff;padding:0 18px;font-weight:700;cursor:pointer}
.comment-save:hover{background:#291b9b}
.assignee-box{display:flex;align-items:center;gap:8px}
.assignee-box select{height:38px;min-width:145px;border:1px solid #cbd5dc;background:#fff;padding:0 10px;font:inherit}
.assignee-save{height:38px;border:0;background:#3526ba;color:#fff;padding:0 14px;font-weight:700;cursor:pointer}@media(max-width:700px){.head{display:block}.status-form{margin-top:14px}.info{grid-template-columns:1fr}.item{grid-template-columns:105px 1fr}}
</style><style>

@media(max-width:700px){
    html,body{overflow-x:hidden}
    .wrap{width:100%;margin:0;padding:10px}
    .back{margin:4px 0 10px;font-size:12px}
    .card{padding:14px;border-radius:7px}
    .head{display:block;padding-bottom:14px}
    .head h1{font-size:19px;line-height:1.3}
    .head p{font-size:11px;line-height:1.5;overflow-wrap:anywhere}
    .status-form{display:grid;grid-template-columns:1fr 1fr;margin-top:12px;gap:6px}
    .status-form select{grid-column:1/-1;width:100%;height:42px;font-size:16px}
    .status-form button{min-height:40px;padding:0 8px}
    .section{margin-top:17px}
    .info{grid-template-columns:1fr}
    .item{grid-template-columns:92px minmax(0,1fr)}
    .item span,.item b{padding:10px;font-size:11px;overflow-wrap:anywhere}
    .payment{font-size:18px}
    .memo{padding:12px;font-size:12px;line-height:1.55}
    .comment-box{padding:12px}
    .comment-box textarea{min-height:140px;padding:12px;font-size:16px}
    .comment-actions{align-items:flex-start;flex-direction:column}
    .comment-save{width:100%;height:42px}
    .assignee-box{display:grid;grid-template-columns:1fr auto;width:100%;margin-top:8px}
    .assignee-box select{width:100%;min-width:0;height:42px;font-size:16px}
    .assignee-save{height:42px}
}

</style>
</head><body><div class="wrap"><a class="back" href="./estimates.php">← 견적목록</a><div class="card"><div class="head"><div><h1><?=h($e['estimate_no'])?></h1><p>신청일 <?=h($e['created_at'])?></p><span class="kind"><?=$isQuick?'간편견적':'직접견적'?></span></div><form class="status-form" method="post"><select name="status"><?php foreach(['NEW'=>'신규','CONTACTED'=>'상담중','REVIEWING'=>'심사중','APPROVED'=>'승인','CONTRACTED'=>'계약완료','CANCELED'=>'취소'] as $k=>$v): ?><option value="<?=$k?>" <?=$e['status']===$k?'selected':''?>><?=$v?></option><?php endforeach; ?></select><button name="action" value="status">상태 저장</button><button class="delete-btn" name="action" value="delete" onclick="return confirm('이 견적을 삭제할까요? 삭제 후 복구할 수 없습니다.')">삭제</button></form>
<form class="assignee-box" method="post">
    <select name="assigned_admin_id" aria-label="담당자 선택">
        <option value="0">담당자 미지정</option>
        <?php foreach($activeAdmins as $admin): ?>
            <option value="<?=(int)$admin['id']?>" <?=((int)($e['assigned_admin_id'] ?? 0) === (int)$admin['id']) ? 'selected' : ''?>><?=h(adminDisplayName($admin))?></option>
        <?php endforeach; ?>
    </select>
    <button class="assignee-save" type="submit" name="action" value="assign">담당 저장</button>
</form>
</div>
<div class="section"><h2>고객 정보</h2><div class="info"><div class="item"><span>성함</span><b><?=h($e['customer_name'])?></b></div><div class="item"><span>연락처</span><b><?=h($e['customer_phone'])?></b></div></div></div>
<div class="section"><h2>유입 정보</h2><div class="info"><div class="item"><span>유입경로</span><b><?=val($e['utm_source'] ?? null)?></b></div><div class="item"><span>매체</span><b><?=val($e['utm_medium'] ?? null)?></b></div><div class="item"><span>캠페인</span><b><?=val($e['utm_campaign'] ?? null)?></b></div><div class="item"><span>랜딩페이지</span><b><?=val($e['landing_page'] ?? null)?></b></div></div></div>
<?php if ($isQuick): ?>
<div class="section"><h2>간편견적 요청 조건</h2><div class="info">
<div class="item"><span>관심 차종</span><b><?=val($e['car_type'])?></b></div>
<div class="item"><span>희망 월 예산</span><b><?=val($e['monthly_budget'])?></b></div>
<div class="item"><span>이용 방식</span><b><?=productLabel($e['product_type'])?></b></div>
<div class="item"><span>신청 유형</span><b>상담 후 차량·조건 결정</b></div>
</div></div>
<?php else: ?>
<div class="section"><h2>차량 정보</h2><div class="info"><div class="item"><span>브랜드</span><b><?=h($e['brand_name'])?></b></div><div class="item"><span>차량</span><b><?=h($e['vehicle_name'])?></b></div><div class="item"><span>트림</span><b><?=val($e['trim_name'])?></b></div><div class="item"><span>외장색상</span><b><?=val($e['color_name'])?></b></div></div></div>
<div class="section"><h2>이용 조건</h2><div class="info"><div class="item"><span>상품</span><b><?=productLabel($e['product_type'])?></b></div><div class="item"><span>계약기간</span><b><?=val($e['contract_months'])?>개월</b></div><div class="item"><span>선납률</span><b><?=val($e['prepayment_rate'])?>%</b></div><div class="item"><span>연 주행거리</span><b><?=isset($e['annual_mileage'])?number_format((int)$e['annual_mileage']).'km':'-'?></b></div><div class="item"><span>월 납입금</span><b class="payment"><?=isset($e['monthly_payment'])?number_format((int)$e['monthly_payment']).'원':'-'?></b></div></div></div>
<div class="section"><h2>고객 메모</h2><div class="memo"><?=val($e['customer_memo'])?></div></div>
<?php endif; ?>

<div class="section" id="consult-comment">
    <h2>상담 코멘트</h2>
    <form class="comment-box" method="post">
        <textarea
            name="admin_comment"
            maxlength="5000"
            placeholder="고객과 상담한 내용, 요청사항, 다음 연락 일정 등을 기록해 주세요."
        ><?=h($e['admin_comment'] ?? '')?></textarea>

        <div class="comment-actions">
            <div class="comment-meta">
                <?php if (!empty($e['admin_comment_updated_at'])): ?>
                    마지막 저장:
                    <?=h($e['admin_comment_by'] ?? '관리자')?>
                    · <?=h($e['admin_comment_updated_at'])?>
                <?php else: ?>
                    아직 저장된 상담 코멘트가 없습니다.
                <?php endif; ?>
            </div>
            <button class="comment-save" type="submit" name="action" value="comment">코멘트 저장</button>
        </div>
    </form>
</div>

</div></div></body></html>
