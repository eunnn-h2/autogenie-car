<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/auth.php';
requireSuperAdmin();
require_once __DIR__ . '/../config/database.php';

$message = null;
$error = null;

function h2(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function superAdminCount(PDO $pdo): int {
    return (int)$pdo->query("
        SELECT COUNT(*)
        FROM admin_accounts
        WHERE role = 'SUPER_ADMIN'
          AND is_active = 1
    ")->fetchColumn();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $targetId = (int)($_POST['admin_id'] ?? 0);
    $currentAdminId = (int)$_SESSION['admin_id'];

    try {
        if ($action === 'create') {
            $username = trim((string)($_POST['username'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $role = (string)($_POST['role'] ?? 'ADMIN');
            $password = (string)($_POST['password'] ?? '');
            $password2 = (string)($_POST['password2'] ?? '');

            if (!preg_match('/^[A-Za-z0-9_.-]{4,50}$/', $username)) {
                throw new RuntimeException('아이디는 영문/숫자/._- 조합 4~50자로 입력해주세요.');
            }
            if (!in_array($role, ['SUPER_ADMIN', 'ADMIN', 'VIEWER'], true)) {
                throw new RuntimeException('잘못된 권한입니다.');
            }
            if (strlen($password) < 8) {
                throw new RuntimeException('비밀번호는 8자 이상으로 입력해주세요.');
            }
            if ($password !== $password2) {
                throw new RuntimeException('비밀번호 확인이 일치하지 않습니다.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO admin_accounts (
                    username, password_hash, name, role, is_active
                ) VALUES (
                    :username, :password_hash, :name, :role, 1
                )
            ");
            $stmt->execute([
                ':username' => $username,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':name' => $name !== '' ? $name : null,
                ':role' => $role,
            ]);

            $message = '새 관리자 계정을 추가했습니다.';
        }

        if ($action === 'update') {
            $role = (string)($_POST['role'] ?? 'ADMIN');
            $name = trim((string)($_POST['name'] ?? ''));
            $isActive = (int)($_POST['is_active'] ?? 1);

            if (!in_array($role, ['SUPER_ADMIN', 'ADMIN', 'VIEWER'], true)) {
                throw new RuntimeException('잘못된 권한입니다.');
            }

            $stmt = $pdo->prepare("SELECT id, role, is_active FROM admin_accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new RuntimeException('관리자 계정을 찾을 수 없습니다.');
            }

            if (
                (string)$target['role'] === 'SUPER_ADMIN' &&
                (int)$target['is_active'] === 1 &&
                ($role !== 'SUPER_ADMIN' || $isActive !== 1) &&
                superAdminCount($pdo) <= 1
            ) {
                throw new RuntimeException('마지막 활성 SUPER_ADMIN 계정은 권한을 낮추거나 비활성화할 수 없습니다.');
            }

            if ($targetId === $currentAdminId && $isActive !== 1) {
                throw new RuntimeException('현재 로그인한 본인 계정을 비활성화할 수 없습니다.');
            }

            $stmt = $pdo->prepare("
                UPDATE admin_accounts
                SET name = :name,
                    role = :role,
                    is_active = :is_active
                WHERE id = :id
            ");
            $stmt->execute([
                ':name' => $name !== '' ? $name : null,
                ':role' => $role,
                ':is_active' => $isActive,
                ':id' => $targetId,
            ]);

            if ($targetId === $currentAdminId) {
                $_SESSION['admin_name'] = $name !== '' ? $name : $_SESSION['admin_username'];
                $_SESSION['admin_role'] = $role;
            }

            $message = '관리자 정보를 수정했습니다.';
        }

        if ($action === 'reset_password') {
            $password = (string)($_POST['new_password'] ?? '');
            $password2 = (string)($_POST['new_password2'] ?? '');

            if (strlen($password) < 8) {
                throw new RuntimeException('새 비밀번호는 8자 이상이어야 합니다.');
            }
            if ($password !== $password2) {
                throw new RuntimeException('새 비밀번호 확인이 일치하지 않습니다.');
            }

            $stmt = $pdo->prepare("
                UPDATE admin_accounts
                SET password_hash = :password_hash
                WHERE id = :id
            ");
            $stmt->execute([
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => $targetId,
            ]);

            $message = '비밀번호를 변경했습니다.';
        }

        if ($action === 'delete') {
            if ($targetId === $currentAdminId) {
                throw new RuntimeException('현재 로그인한 본인 계정은 삭제할 수 없습니다.');
            }

            $stmt = $pdo->prepare("SELECT role, is_active FROM admin_accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new RuntimeException('관리자 계정을 찾을 수 없습니다.');
            }

            if (
                (string)$target['role'] === 'SUPER_ADMIN' &&
                (int)$target['is_active'] === 1 &&
                superAdminCount($pdo) <= 1
            ) {
                throw new RuntimeException('마지막 활성 SUPER_ADMIN 계정은 삭제할 수 없습니다.');
            }

            $pdo->prepare("DELETE FROM admin_accounts WHERE id = ?")->execute([$targetId]);
            $message = '관리자 계정을 삭제했습니다.';
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$admins = $pdo->query("
    SELECT
        id,
        username,
        name,
        role,
        is_active,
        last_login_at,
        created_at
    FROM admin_accounts
    ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>관리자 계정 관리</title>
<style>
*{box-sizing:border-box}
body{margin:0;font-family:Pretendard,"Noto Sans KR",Arial,sans-serif;background:#eef5f8;color:#263b48;font-size:13px}
a{text-decoration:none;color:inherit}
.wrap{max-width:1200px;margin:0 auto;padding:28px 18px}
.top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:18px}
h1{margin:0;font-size:23px}.sub{margin-top:6px;color:#8496a0}.back{padding:9px 12px;background:#fff;border:1px solid #ccd7dd}
.card{background:#fff;border:1px solid #d8e2e7;padding:18px;margin-bottom:16px}
h2{margin:0 0 14px;font-size:16px}
.alert{padding:11px 12px;margin-bottom:15px}.ok{background:#edf9f2;border:1px solid #bce5c9;color:#16733a}.err{background:#fff1f1;border:1px solid #ffc4c4;color:#b4232f}
.create-grid{display:grid;grid-template-columns:1fr 1fr 1fr 1fr;gap:10px}
label{display:block;font-size:11px;color:#71838e;margin-bottom:5px}
input,select{width:100%;height:38px;border:1px solid #c7d3d9;padding:0 9px;background:#fff}
.actions{grid-column:1/-1;text-align:right}.primary{border:0;background:#3924b9;color:#fff;padding:10px 15px;font-weight:800;cursor:pointer}
.table-wrap{overflow:auto}
table{border-collapse:collapse;width:100%;min-width:1450px}
th,td{padding:10px 8px;border-bottom:1px solid #e3e9ec;text-align:center;white-space:nowrap;vertical-align:middle}
th{background:#f6f8fa;color:#617582;font-size:11px}
.role{font-weight:800}.SUPER_ADMIN{color:#3924b9}.ADMIN{color:#16733a}.VIEWER{color:#7c8790}
.status{display:inline-block;padding:4px 7px;border-radius:3px}.on{background:#1f2937;color:#fff}.off{background:#f1f2f3;color:#8998a1}
.row-form{display:flex;gap:6px;align-items:center;justify-content:center;flex-wrap:nowrap;white-space:nowrap}.row-form input,.row-form select{height:32px}
.btn{border:0;padding:7px 9px;cursor:pointer;font-weight:700}.save{background:#25bcd0;color:#fff}.pw{background:#eef2ff;color:#4338ca}.delete{background:#fff0f1;color:#c92333}
.pw-form{display:flex;gap:5px;align-items:center;flex-wrap:nowrap;white-space:nowrap}.pw-form input{width:125px;min-width:125px;height:32px}
.note{padding:11px;background:#fff8dc;border:1px solid #f1df9d;color:#775f13;font-size:12px}
@media(max-width:800px){.create-grid{grid-template-columns:1fr 1fr}}@media(max-width:520px){.create-grid{grid-template-columns:1fr}}

.btn{white-space:nowrap}
.role,.status{white-space:nowrap}
.table-wrap{overflow-x:auto;overflow-y:hidden}
table th,table td{word-break:keep-all}

</style>
</head>
<body>
<div class="wrap">
    <div class="top">
        <div>
            <h1>관리자 계정 관리</h1>
            <div class="sub">SUPER_ADMIN만 관리자 계정과 권한을 관리할 수 있습니다.</div>
        </div>
        <a class="back" href="./index.php">관리자로 돌아가기</a>
    </div>

    <?php if ($message): ?><div class="alert ok"><?= h2($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert err"><?= h2($error) ?></div><?php endif; ?>

    <div class="card">
        <h2>새 관리자 추가</h2>
        <form method="post" class="create-grid" autocomplete="off">
            <input type="hidden" name="action" value="create">
            <div>
                <label>아이디</label>
                <input type="text" name="username" required placeholder="manager01">
            </div>
            <div>
                <label>이름</label>
                <input type="text" name="name" placeholder="홍길동">
            </div>
            <div>
                <label>권한</label>
                <select name="role">
                    <option value="ADMIN">ADMIN - 차량 수정 가능</option>
                    <option value="VIEWER">VIEWER - 조회만 가능</option>
                    <option value="SUPER_ADMIN">SUPER_ADMIN - 전체 권한</option>
                </select>
            </div>
            <div>
                <label>비밀번호</label>
                <input type="password" name="password" required placeholder="8자 이상">
            </div>
            <div>
                <label>비밀번호 확인</label>
                <input type="password" name="password2" required>
            </div>
            <div class="actions"><button class="primary" type="submit">관리자 추가</button></div>
        </form>
    </div>

    <div class="note">
        권한: <strong>SUPER_ADMIN</strong> = 관리자 계정까지 전부 관리 /
        <strong>ADMIN</strong> = 차량·색상·트림·가격·엑셀 등록 가능 /
        <strong>VIEWER</strong> = 조회만 가능
    </div>

    <div class="card">
        <h2>관리자 목록</h2>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th><th>아이디</th><th>이름</th><th>권한</th><th>상태</th>
                        <th>마지막 로그인</th><th>생성일</th><th>정보 수정</th><th>비밀번호 변경</th><th>삭제</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($admins as $admin): ?>
                    <tr>
                        <td><?= (int)$admin['id'] ?></td>
                        <td><?= h2($admin['username']) ?></td>
                        <td><?= h2((string)($admin['name'] ?? '')) ?></td>
                        <td><span class="role <?= h2($admin['role']) ?>"><?= h2($admin['role']) ?></span></td>
                        <td><?= (int)$admin['is_active'] === 1 ? '<span class="status on">활성</span>' : '<span class="status off">비활성</span>' ?></td>
                        <td><?= h2((string)($admin['last_login_at'] ?? '-')) ?></td>
                        <td><?= h2((string)$admin['created_at']) ?></td>
                        <td>
                            <form method="post" class="row-form">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                <input type="text" name="name" value="<?= h2((string)($admin['name'] ?? '')) ?>" placeholder="이름" style="width:120px;min-width:120px">
                                <select name="role" style="width:150px;min-width:150px">
                                    <?php foreach (['SUPER_ADMIN','ADMIN','VIEWER'] as $role): ?>
                                        <option value="<?= $role ?>" <?= $admin['role'] === $role ? 'selected' : '' ?>><?= $role ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <select name="is_active" style="width:90px;min-width:90px">
                                    <option value="1" <?= (int)$admin['is_active']===1?'selected':'' ?>>활성</option>
                                    <option value="0" <?= (int)$admin['is_active']===0?'selected':'' ?>>비활성</option>
                                </select>
                                <button class="btn save" type="submit">저장</button>
                            </form>
                        </td>
                        <td>
                            <form method="post" class="pw-form">
                                <input type="hidden" name="action" value="reset_password">
                                <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                <input type="password" name="new_password" placeholder="새 비밀번호" required>
                                <input type="password" name="new_password2" placeholder="확인" required>
                                <button class="btn pw" type="submit">변경</button>
                            </form>
                        </td>
                        <td>
                            <?php if ((int)$admin['id'] === (int)$_SESSION['admin_id']): ?>
                                본인
                            <?php else: ?>
                                <form method="post" onsubmit="return confirm('이 관리자 계정을 삭제할까요?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                    <button class="btn delete" type="submit">삭제</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
</body>
</html>
