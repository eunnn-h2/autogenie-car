<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

require_once __DIR__ . '/../config/database.php';

if (isset($_SESSION['admin_id'])) {
    header('Location: ./dashboard.php');
    exit;
}

$error = null;
$expired = isset($_GET['expired']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = '아이디와 비밀번호를 모두 입력해주세요.';
    } else {
        try {
            $stmt = $pdo->prepare("
                SELECT id, username, password_hash, name, role, is_active
                FROM admins
                WHERE username = :username
                LIMIT 1
            ");
            $stmt->execute([':username' => $username]);
            $admin = $stmt->fetch(PDO::FETCH_ASSOC);

            if (
                !$admin ||
                (int)$admin['is_active'] !== 1 ||
                !password_verify($password, (string)$admin['password_hash'])
            ) {
                $error = '아이디 또는 비밀번호가 올바르지 않습니다.';
            } else {
                session_regenerate_id(true);

                $_SESSION['admin_id'] = (int)$admin['id'];
                $_SESSION['admin_username'] = (string)$admin['username'];
                $_SESSION['admin_name'] = (string)($admin['name'] ?: $admin['username']);
                $_SESSION['admin_role'] = (string)($admin['role'] ?? 'VIEWER');
                $_SESSION['admin_last_activity'] = time();

                $pdo->prepare("
                    UPDATE admins
                    SET last_login_at = NOW()
                    WHERE id = ?
                ")->execute([(int)$admin['id']]);

                header('Location: ./dashboard.php');
                exit;
            }
        } catch (Throwable $e) {
            $error = '로그인 처리 중 오류가 발생했습니다. admins 테이블이 생성되어 있는지 확인해주세요.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>오토지니 관리자 로그인</title>
<style>
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;font-family:Pretendard,"Noto Sans KR",Arial,sans-serif;background:#eef5f8;color:#25384a}
body{min-height:100vh;display:grid;place-items:center;padding:20px}
.login-card{width:min(420px,100%);background:#fff;border:1px solid #d8e2e7;border-radius:10px;padding:34px;box-shadow:0 12px 36px rgba(27,55,70,.08)}
.logo{width:48px;height:48px;border-radius:8px;background:#3924b9;color:#fff;display:grid;place-items:center;font-weight:900;margin-bottom:20px}
h1{margin:0;font-size:24px}
.desc{margin:8px 0 26px;color:#8a9aa4;font-size:13px}
.field{margin-bottom:15px}
label{display:block;margin-bottom:6px;font-size:12px;font-weight:700;color:#60737f}
input{width:100%;height:46px;border:1px solid #c7d3d9;border-radius:6px;padding:0 13px;font-size:14px;outline:none}
input:focus{border-color:#3924b9;box-shadow:0 0 0 3px rgba(57,36,185,.08)}
button{width:100%;height:48px;border:0;border-radius:6px;background:#3924b9;color:#fff;font-weight:800;font-size:14px;cursor:pointer;margin-top:6px}
.alert{padding:11px 12px;border-radius:5px;margin-bottom:15px;font-size:12px}
.alert.error{background:#fff1f1;color:#b4232f;border:1px solid #ffc5c5}
.alert.info{background:#eef7ff;color:#2563a6;border:1px solid #cce6fa}
.setup{margin-top:18px;padding-top:16px;border-top:1px solid #edf1f3;text-align:center;font-size:11px;color:#9aabb4}
.setup a{color:#3924b9;text-decoration:none}
</style>
<style>

@media(max-width:520px){
    body{display:flex;align-items:center;justify-content:center;padding:12px;min-height:100dvh}
    .login-card{width:100%;padding:24px 18px;border-radius:9px}
    .logo{width:43px;height:43px;margin-bottom:16px}
    h1{font-size:21px}
    .desc{margin-bottom:20px;font-size:12px;line-height:1.5}
    input{height:48px;font-size:16px}
    button{height:50px}
}

</style>
</head>
<body>
<div class="login-card">
    <div class="logo">AG</div>
    <h1>관리자 로그인</h1>
    <p class="desc">관리자 계정으로 로그인해야 차량 DB를 관리할 수 있습니다.</p>

    <?php if ($expired): ?>
        <div class="alert info">로그인 시간이 만료되었습니다. 다시 로그인해주세요.</div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="post" autocomplete="off">
        <div class="field">
            <label for="username">관리자 아이디</label>
            <input id="username" type="text" name="username" required autofocus autocomplete="username">
        </div>
        <div class="field">
            <label for="password">비밀번호</label>
            <input id="password" type="password" name="password" required autocomplete="current-password">
        </div>
        <button type="submit">로그인</button>
    </form>

    <div class="setup">
        처음 설치하는 경우 <a href="./setup_admin.php">최초 관리자 계정 만들기</a>
    </div>
</div>
</body>
</html>
