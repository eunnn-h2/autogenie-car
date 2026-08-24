<?php

declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

$message = '';
$error = '';

function h(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireVehicleEditor();

    $requestId = (int)($_POST['request_id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');

    try {
        $stmt = $pdo->prepare("
            SELECT r.*, m.id AS actual_member_id
            FROM admin_password_reset_requests r
            LEFT JOIN member_accounts m ON m.id = r.member_id
            WHERE r.id = ?
            LIMIT 1
        ");
        $stmt->execute([$requestId]);
        $request = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new RuntimeException('요청을 찾을 수 없습니다.');
        }

        if ($action === 'reset_password') {
            $password = (string)($_POST['temporary_password'] ?? '');
            $passwordConfirm = (string)($_POST['temporary_password_confirm'] ?? '');

            if (strlen($password) < 8 || strlen($password) > 72) {
                throw new RuntimeException('임시 비밀번호는 8자 이상 72자 이하로 입력해 주세요.');
            }
            if ($password !== $passwordConfirm) {
                throw new RuntimeException('임시 비밀번호 확인이 일치하지 않습니다.');
            }
            if (empty($request['actual_member_id'])) {
                throw new RuntimeException('연결된 회원 계정을 찾을 수 없습니다.');
            }

            $pdo->beginTransaction();

            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare("
                UPDATE member_accounts
                SET password_hash = ?,
                    password_reset_code_hash = NULL,
                    password_reset_expires_at = NULL,
                    password_reset_requested_at = NULL,
                    updated_at = NOW()
                WHERE id = ?
            ")->execute([$hash, (int)$request['actual_member_id']]);

            $pdo->prepare("
                UPDATE admin_password_reset_requests
                SET status = 'RESOLVED',
                    resolved_at = NOW(),
                    resolved_by = ?
                WHERE id = ?
            ")->execute([
                (string)($_SESSION['admin_username'] ?? '관리자'),
                $requestId
            ]);

            $pdo->commit();
            $message = '임시 비밀번호로 재설정하고 요청을 처리 완료했습니다.';
        }

        if ($action === 'dismiss') {
            $pdo->prepare("
                UPDATE admin_password_reset_requests
                SET status = 'DISMISSED',
                    resolved_at = NOW(),
                    resolved_by = ?
                WHERE id = ?
            ")->execute([
                (string)($_SESSION['admin_username'] ?? '관리자'),
                $requestId
            ]);
            $message = '요청을 종료했습니다.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$rows = $pdo->query("
    SELECT r.*
    FROM admin_password_reset_requests r
    ORDER BY
        CASE r.status WHEN 'PENDING' THEN 0 ELSE 1 END,
        r.id DESC
    LIMIT 500
")->fetchAll(PDO::FETCH_ASSOC);

$pendingCount = (int)$pdo->query("
    SELECT COUNT(*)
    FROM admin_password_reset_requests
    WHERE status = 'PENDING'
")->fetchColumn();
?>
<!doctype html>
<html lang="ko">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>비밀번호 재설정 요청 | 오토지니 관리자</title>
<style>
*{box-sizing:border-box}
body{margin:0;background:#f5f7f8;color:#263746;font-family:Arial,"Noto Sans KR",sans-serif}
.wrap{max-width:1180px;margin:0 auto;padding:28px 18px 60px}
.head{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;margin-bottom:20px}
h1{margin:0;font-size:22px}.sub{margin-top:6px;color:#81909a;font-size:12px}
.back{color:#3544c4;text-decoration:none;font-size:12px;font-weight:700}
.notice{padding:12px 14px;margin-bottom:14px;border-radius:7px;font-size:12px}
.notice.ok{background:#ebf8ef;color:#287044}.notice.error{background:#fff0ef;color:#b23e37}
.card{background:#fff;border:1px solid #e1e7eb}
table{width:100%;border-collapse:collapse;font-size:12px}
th,td{padding:12px 10px;border-bottom:1px solid #edf0f2;text-align:left;vertical-align:middle}
th{background:#f8fafb;color:#667782;font-weight:800}
.status{display:inline-flex;padding:5px 9px;border-radius:20px;font-size:10px;font-weight:800}
.status.PENDING{background:#fff3dc;color:#a76900}
.status.RESOLVED{background:#eaf8ee;color:#2c7a47}
.status.DISMISSED{background:#edf0f2;color:#7d8991}
.reset-form{display:grid;grid-template-columns:130px 130px auto;gap:5px;align-items:center}
.reset-form input{height:32px;border:1px solid #ccd6dc;padding:0 8px;font:inherit}
.btn{height:32px;border:0;padding:0 10px;font:inherit;font-weight:700;cursor:pointer}
.btn.primary{background:#3526ba;color:#fff}.btn.gray{background:#eef1f3;color:#4a5963}
.actions{display:flex;gap:6px}
.empty{padding:45px;text-align:center;color:#919ca4}
@media(max-width:850px){
    .card{overflow:auto} table{min-width:1000px}
}
</style>
</head>
<body>
<div class="wrap">
    <div class="head">
        <div>
            <h1>비밀번호 재설정 요청 (<?= $pendingCount ?>)</h1>
            <div class="sub">가입 이메일을 사용할 수 없는 회원이 이름과 휴대폰 번호로 요청한 목록입니다.</div>
        </div>
        <a class="back" href="./estimates.php">견적 신청 관리로 돌아가기</a>
    </div>

    <?php if ($message !== ''): ?><div class="notice ok"><?=h($message)?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="notice error"><?=h($error)?></div><?php endif; ?>

    <div class="card">
        <?php if (!$rows): ?>
            <div class="empty">비밀번호 재설정 요청이 없습니다.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>요청번호</th>
                    <th>상태</th>
                    <th>회원명</th>
                    <th>휴대폰</th>
                    <th>가입 이메일</th>
                    <th>요청일</th>
                    <th>처리자</th>
                    <th>비밀번호 재설정</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>#<?=(int)$row['id']?></td>
                    <td><span class="status <?=h((string)$row['status'])?>"><?=h((string)$row['status'])?></span></td>
                    <td><?=h((string)$row['member_name'])?></td>
                    <td><?=h((string)$row['member_phone'])?></td>
                    <td><?=h((string)$row['member_email'])?></td>
                    <td><?=h((string)$row['requested_at'])?></td>
                    <td><?=h((string)($row['resolved_by'] ?? '-'))?></td>
                    <td>
                    <?php if (($row['status'] ?? '') === 'PENDING'): ?>
                        <div class="actions">
                            <form class="reset-form" method="post" autocomplete="off">
                                <input type="hidden" name="request_id" value="<?=(int)$row['id']?>">
                                <input type="password" name="temporary_password" minlength="8" maxlength="72" required placeholder="임시 비밀번호">
                                <input type="password" name="temporary_password_confirm" minlength="8" maxlength="72" required placeholder="비밀번호 확인">
                                <button class="btn primary" type="submit" name="action" value="reset_password"
                                    onclick="return confirm('이 회원의 비밀번호를 입력한 임시 비밀번호로 변경할까요?')">재설정</button>
                            </form>
                            <form method="post">
                                <input type="hidden" name="request_id" value="<?=(int)$row['id']?>">
                                <button class="btn gray" type="submit" name="action" value="dismiss">종료</button>
                            </form>
                        </div>
                    <?php else: ?>
                        처리 완료: <?=h((string)($row['resolved_at'] ?? '-'))?>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
