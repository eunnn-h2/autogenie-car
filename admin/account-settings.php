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
<style>
*{box-sizing:border-box}body{margin:0;font-family:Pretendard,"Noto Sans KR",Arial,sans-serif;background:#f3f7fa;color:#223644;font-size:14px}.wrap{max-width:1180px;margin:0 auto;padding:24px 18px 40px}.card{background:#fff;border:1px solid #dce4e9;border-radius:10px;padding:20px;margin-bottom:16px}.head h1{margin:0;font-size:22px}.head p{margin:6px 0 0;color:#83939d}.alert{padding:11px 12px;margin-bottom:15px;border-radius:7px}.ok{background:#edf9f2;border:1px solid #bce5c9;color:#16733a}.err{background:#fff1f1;border:1px solid #ffc4c4;color:#b4232f}.profile{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));border:1px solid #e3e9ed;border-radius:9px;overflow:hidden;margin-bottom:16px}.profile div{padding:13px 15px;border-right:1px solid #edf1f3}.profile div:last-child{border-right:0}.profile span{display:block;color:#8a99a2;font-size:14px;margin-bottom:4px}.profile strong{font-size:14px}.form-title{margin:0 0 12px;font-size:16px}.pw-grid{display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:9px;align-items:end}.field label{display:block;color:#71838e;font-size:14px;margin-bottom:5px}.field input{width:100%;height:40px;border:1px solid #ccd7dd;border-radius:7px;padding:0 10px;background:#fff}.field input:focus{outline:0;border-color:#3924b9;box-shadow:0 0 0 3px rgba(57,36,185,.08)}.btn{height:40px;border:0;border-radius:7px;background:#3924b9;color:#fff;padding:0 17px;font-weight:800;cursor:pointer}.help{margin:11px 0 0;color:#8998a1;font-size:14px;line-height:1.6}@media(max-width:900px){.profile{grid-template-columns:1fr 1fr}.profile div:nth-child(2){border-right:0}.pw-grid{grid-template-columns:1fr 1fr}.pw-grid .submit{grid-column:1/-1}.btn{width:100%}}@media(max-width:600px){.wrap{padding:16px 10px}.profile,.pw-grid{grid-template-columns:1fr}.profile div{border-right:0;border-bottom:1px solid #edf1f3}.profile div:last-child{border-bottom:0}}
</style>
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
