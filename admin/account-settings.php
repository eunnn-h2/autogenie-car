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

$teamColumnStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_accounts' AND COLUMN_NAME = 'team_name'");
$teamColumnStmt->execute();
$hasTeamColumn = (int)$teamColumnStmt->fetchColumn() > 0;
$teamSelect = $hasTeamColumn ? ', team_name' : ", NULL AS team_name";
$stmt = $pdo->prepare("SELECT id, username, name, role, is_active, password_hash, last_login_at, created_at{$teamSelect} FROM admin_accounts WHERE id = ? LIMIT 1");
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

            $stmt = $pdo->prepare("SELECT id, username, name, role, is_active, password_hash, last_login_at, created_at{$teamSelect} FROM admin_accounts WHERE id = ? LIMIT 1");
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
<link rel="stylesheet" href="./sidebar.css?v=<?= filemtime(__DIR__ . '/sidebar.css') ?>">
<link rel="stylesheet" href="./admin-ui.css">
<link rel="stylesheet" href="./account-settings-page.css?v=<?= filemtime(__DIR__ . '/account-settings-page.css') ?>">
</head>
<body><div class="admin-shell">
<?php $currentAdminPage='account-settings'; require __DIR__.'/sidebar.php'; ?>
<main class="main"><div class="wrap account-settings">
    <section class="card head ag-page-card">
        <div><h1>내 계정</h1><p>계정 정보를 확인하고 비밀번호를 안전하게 관리하세요.</p></div>
    </section>

    <?php if ($message): ?><div class="alert ok"><?= esc($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert err"><?= esc($error) ?></div><?php endif; ?>

    <section class="card settings-section" aria-labelledby="profile-title">
        <div class="section-intro">
            <span class="section-eyebrow">PROFILE</span>
            <h2 id="profile-title">계정 정보</h2>
            <p>현재 로그인한 계정의 기본 정보입니다.</p>
        </div>
        <div class="profile">
            <div><span>아이디</span><strong><?= esc((string)$account['username']) ?></strong></div>
            <div><span>이름</span><strong><?= esc((string)$account['name']) ?></strong></div>
            <div><span>권한</span><strong><?= esc((string)$account['role']) ?></strong></div>
            <?php if ((string)$account['role'] === 'SALES'): ?><div><span>소속 팀</span><strong><?= esc(trim((string)($account['team_name'] ?? '')) ?: '미지정') ?></strong></div><?php endif; ?>
            <div><span>마지막 로그인</span><strong><?= esc((string)($account['last_login_at'] ?? '-')) ?></strong></div>
        </div>
    </section>

    <section class="card settings-section" aria-labelledby="password-title">
        <div class="section-intro">
            <span class="section-eyebrow">SECURITY</span>
            <h2 id="password-title">비밀번호 변경</h2>
            <p>계정을 보호하기 위해 다른 서비스에서 사용하지 않는 비밀번호를 설정하세요.</p>
            <div class="security-note"><strong>비밀번호를 잊으셨나요?</strong><p>영업사원은 본 관리자에게 비밀번호 초기화를 요청해주세요.</p></div>
        </div>
        <form method="post" class="pw-grid" autocomplete="off" aria-labelledby="password-title">
            <input type="hidden" name="action" value="change_password">
            <div class="field">
                <label for="current-password">현재 비밀번호</label>
                <input id="current-password" type="password" name="current_password" required autocomplete="current-password" placeholder="현재 비밀번호를 입력하세요">
            </div>
            <div class="field">
                <label for="new-password">새 비밀번호</label>
                <input id="new-password" type="password" name="new_password" minlength="8" maxlength="72" required autocomplete="new-password" placeholder="새 비밀번호를 입력하세요" aria-describedby="password-help">
                <p id="password-help" class="field-help">8자 이상으로, 현재 비밀번호와 다르게 입력해주세요.</p>
            </div>
            <div class="field">
                <label for="confirm-password">새 비밀번호 확인</label>
                <input id="confirm-password" type="password" name="new_password2" minlength="8" maxlength="72" required autocomplete="new-password" placeholder="새 비밀번호를 한 번 더 입력하세요" aria-describedby="password-match-status">
                <p id="password-match-status" class="field-help password-match-status" role="status" aria-live="polite" aria-atomic="true" hidden></p>
            </div>
            <div class="submit"><button class="btn" type="submit">비밀번호 변경</button></div>
        </form>
    </section>

</div></main></div>
<script src="./account-settings.js?v=<?= filemtime(__DIR__ . '/account-settings.js') ?>" defer></script>
</body></html>
