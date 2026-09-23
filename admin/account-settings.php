<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../config/database.php';

$message = null;
$error = null;
$currentAdminId = (int)($_SESSION['admin_id'] ?? 0);

function esc(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$stmt = $pdo->prepare("SELECT id, username, name, role, is_active, password_hash, last_login_at, created_at FROM admin_accounts WHERE id = ? LIMIT 1");
$stmt->execute([$currentAdminId]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$account) {
    http_response_code(404);
    exit('관리자 계정을 찾을 수 없습니다.');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');

    try {
        if ($action === 'change_password') {
            $currentPassword = (string)($_POST['current_password'] ?? '');
            $newPassword = (string)($_POST['new_password'] ?? '');
            $newPassword2 = (string)($_POST['new_password2'] ?? '');

            if ($currentPassword === '') {
                throw new RuntimeException('현재 비밀번호를 입력해주세요.');
            }
            if (!password_verify($currentPassword, (string)$account['password_hash'])) {
                throw new RuntimeException('현재 비밀번호가 일치하지 않습니다.');
            }
            if (strlen($newPassword) < 8 || strlen($newPassword) > 72) {
                throw new RuntimeException('새 비밀번호는 8자 이상 72자 이하로 입력해주세요.');
            }
            if ($newPassword !== $newPassword2) {
                throw new RuntimeException('새 비밀번호 확인이 일치하지 않습니다.');
            }
            if (password_verify($newPassword, (string)$account['password_hash'])) {
                throw new RuntimeException('현재 비밀번호와 다른 새 비밀번호를 입력해주세요.');
            }

            $stmt = $pdo->prepare("UPDATE admin_accounts SET password_hash = ? WHERE id = ?");
            $stmt->execute([password_hash($newPassword, PASSWORD_DEFAULT), $currentAdminId]);
            session_regenerate_id(true);
            $message = '비밀번호가 변경되었습니다.';

            $stmt = $pdo->prepare("SELECT id, username, name, role, is_active, password_hash, last_login_at, created_at FROM admin_accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$currentAdminId]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>내 계정</title>
<link rel="stylesheet" href="./sidebar.css">
<link rel="stylesheet" href="./account-settings-page.css">
<link rel="stylesheet" href="./admin-ui.css">
</head>
<body><div class="admin-shell">
<?php $currentAdminPage='account-settings'; require __DIR__.'/sidebar.php'; ?>
<main class="main"><div class="wrap">
    <section class="card head ag-page-card">
        <h1>내 계정</h1>
        <p>현재 비밀번호를 확인한 뒤 새 비밀번호로 변경할 수 있습니다.</p>
    </section>

    <?php if ($message): ?><div class="alert ok"><?= esc($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert err"><?= esc($error) ?></div><?php endif; ?>

    <section class="card">
        <div class="profile">
            <div><span>아이디</span><strong><?= esc((string)$account['username']) ?></strong></div>
            <div><span>이름</span><strong><?= esc((string)$account['name']) ?></strong></div>
            <div><span>권한</span><strong><?= esc((string)$account['role']) ?></strong></div>
            <div><span>마지막 로그인</span><strong><?= esc((string)($account['last_login_at'] ?? '-')) ?></strong></div>
        </div>

        <h2 class="form-title">비밀번호 변경</h2>
        <form method="post" class="pw-grid" autocomplete="off">
            <input type="hidden" name="action" value="change_password">
            <div class="field">
                <label>현재 비밀번호</label>
                <input type="password" name="current_password" required autocomplete="current-password">
            </div>
            <div class="field">
                <label>새 비밀번호</label>
                <input type="password" name="new_password" minlength="8" maxlength="72" required autocomplete="new-password" placeholder="8자 이상">
            </div>
            <div class="field">
                <label>새 비밀번호 확인</label>
                <input type="password" name="new_password2" minlength="8" maxlength="72" required autocomplete="new-password">
            </div>
            <div class="submit"><button class="btn" type="submit">비밀번호 변경</button></div>
        </form>
        <p class="help">본인 계정의 비밀번호는 현재 비밀번호가 일치할 때만 변경됩니다. 비밀번호를 잊은 영업사원은 본 관리자에게 초기화를 요청해야 합니다.</p>
    </section>
</div></main></div></body></html>
