<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/../config/database.php';

$error = null;
$success = false;
$locked = false;

try {
    $count = (int)$pdo->query("SELECT COUNT(*) FROM admins")->fetchColumn();
    if ($count > 0) {
        $locked = true;
    }
} catch (Throwable $e) {
    $error = '먼저 admins_table.sql을 phpMyAdmin에서 실행해주세요.';
}

if (!$locked && $error === null && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $password2 = (string)($_POST['password2'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.-]{4,50}$/', $username)) {
        $error = '아이디는 영문/숫자/._- 조합 4~50자로 입력해주세요.';
    } elseif (strlen($password) < 8) {
        $error = '비밀번호는 8자 이상으로 입력해주세요.';
    } elseif ($password !== $password2) {
        $error = '비밀번호 확인이 일치하지 않습니다.';
    } else {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO admins (
                    username, password_hash, name, role, is_active
                ) VALUES (
                    :username, :password_hash, :name, 'SUPER_ADMIN', 1
                )
            ");
            $stmt->execute([
                ':username' => $username,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':name' => $name !== '' ? $name : null,
            ]);
            $success = true;
        } catch (Throwable $e) {
            $error = '관리자 계정을 생성하지 못했습니다: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>최초 관리자 생성</title>
<style>
*{box-sizing:border-box}html,body{margin:0;font-family:Pretendard,"Noto Sans KR",Arial,sans-serif;background:#eef5f8;color:#25384a}
body{min-height:100vh;display:grid;place-items:center;padding:20px}.box{width:min(480px,100%);background:#fff;border:1px solid #d8e2e7;border-radius:9px;padding:30px}
h1{margin:0 0 6px}.desc{margin:0 0 22px;color:#8a9aa4;font-size:12px}.field{margin:12px 0}.field label{display:block;font-size:12px;font-weight:700;margin-bottom:5px}
input{width:100%;height:43px;border:1px solid #c8d4da;border-radius:5px;padding:0 11px}.btn{display:block;width:100%;height:45px;border:0;border-radius:5px;background:#3924b9;color:#fff;font-weight:800;margin-top:16px;cursor:pointer}
.alert{padding:11px;border-radius:5px;margin:14px 0;font-size:12px}.error{background:#fff1f1;border:1px solid #ffc7c7;color:#b4232f}.success{background:#edf9f2;border:1px solid #bae5c9;color:#15703a}
a{color:#3924b9}.back{margin-top:18px;text-align:center;font-size:12px}
</style>
</head>
<body>
<div class="box">
    <h1>최초 관리자 계정 만들기</h1>
    <p class="desc">이 페이지는 admins 테이블에 계정이 하나도 없을 때만 사용할 수 있습니다.</p>

    <?php if ($error): ?><div class="alert error"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if ($locked): ?>
        <div class="alert error">이미 관리자 계정이 존재합니다. 보안을 위해 이 페이지에서는 추가 계정을 만들 수 없습니다.</div>
        <div class="back"><a href="./login.php">로그인 화면으로</a></div>
    <?php elseif ($success): ?>
        <div class="alert success">관리자 계정이 생성되었습니다.</div>
        <div class="back"><a href="./login.php">관리자 로그인하기</a></div>
    <?php elseif ($error !== '먼저 admins_table.sql을 phpMyAdmin에서 실행해주세요.'): ?>
        <form method="post" autocomplete="off">
            <div class="field">
                <label>관리자 아이디</label>
                <input type="text" name="username" required placeholder="예: autogenie_admin">
            </div>
            <div class="field">
                <label>관리자 이름</label>
                <input type="text" name="name" placeholder="예: 관리자">
            </div>
            <div class="field">
                <label>비밀번호 (8자 이상)</label>
                <input type="password" name="password" required>
            </div>
            <div class="field">
                <label>비밀번호 확인</label>
                <input type="password" name="password2" required>
            </div>
            <button class="btn" type="submit">관리자 계정 생성</button>
        </form>
    <?php endif; ?>

    <div class="back"><a href="./login.php">로그인 화면으로 돌아가기</a></div>
</div>
</body>
</html>
