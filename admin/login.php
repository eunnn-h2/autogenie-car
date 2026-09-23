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
    header('Location: ./index.php');
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
                FROM admin_accounts
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
                    UPDATE admin_accounts
                    SET last_login_at = NOW()
                    WHERE id = ?
                ")->execute([(int)$admin['id']]);

                header('Location: ./index.php');
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
<link rel="stylesheet" href="./login-page.css">
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
