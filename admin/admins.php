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

function renderCategoryPermissions(array $selected): void {
    echo '<div class="account-permissions" data-permission-group><span class="account-permissions-title">카테고리 접근</span>';
    echo '<label class="permission-check all"><input type="checkbox" data-permission-all> 전체</label>';
    foreach (adminCategoryLabels() as $key => $label) {
        $checked = in_array($key, $selected, true) ? ' checked' : '';
        echo '<label class="permission-check"><input type="checkbox" name="categories[]" value="' . h2($key) . '" data-permission-item' . $checked . '> ' . h2($label) . '</label>';
    }
    echo '</div>';
}

function superAdminCount(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM admin_accounts WHERE role = 'SUPER_ADMIN' AND is_active = 1")->fetchColumn();
}

function adminColumnExists(PDO $pdo, string $column): bool {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'admin_accounts'
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([':column_name' => $column]);
    return (int)$stmt->fetchColumn() > 0;
}

/*
 * 기존 DB를 그대로 사용해도 페이지에 들어오면 필요한 스키마를 자동 보완합니다.
 * DB 계정에 ALTER 권한이 없는 환경에서는 admin_subaccounts_migration.sql을 1회 실행하면 됩니다.
 */
try {
    $roleInfo = $pdo->query("SHOW COLUMNS FROM admin_accounts LIKE 'role'")->fetch(PDO::FETCH_ASSOC);
    $roleType = strtolower((string)($roleInfo['Type'] ?? ''));
    if ($roleType !== '' && strpos($roleType, "'sales'") === false) {
        $pdo->exec("ALTER TABLE admin_accounts MODIFY COLUMN role ENUM('SUPER_ADMIN','ADMIN','VIEWER','SALES') NOT NULL DEFAULT 'ADMIN'");
    }

    if (!adminColumnExists($pdo, 'parent_admin_id')) {
        $pdo->exec("ALTER TABLE admin_accounts ADD COLUMN parent_admin_id INT UNSIGNED NULL AFTER role");
        $pdo->exec("ALTER TABLE admin_accounts ADD INDEX idx_admins_parent (parent_admin_id)");
    }

    if (!adminColumnExists($pdo, 'can_create')) {
        $pdo->exec("ALTER TABLE admin_accounts ADD COLUMN can_create TINYINT(1) NOT NULL DEFAULT 0 AFTER parent_admin_id");
    }
    if (!adminColumnExists($pdo, 'can_update')) {
        $pdo->exec("ALTER TABLE admin_accounts ADD COLUMN can_update TINYINT(1) NOT NULL DEFAULT 0 AFTER can_create");
    }
    if (!adminColumnExists($pdo, 'can_delete')) {
        $pdo->exec("ALTER TABLE admin_accounts ADD COLUMN can_delete TINYINT(1) NOT NULL DEFAULT 0 AFTER can_update");
    }
    if (!adminColumnExists($pdo, 'category_permissions')) {
        $pdo->exec("ALTER TABLE admin_accounts ADD COLUMN category_permissions TEXT NULL AFTER can_delete");
    }
} catch (Throwable $schemaError) {
    $error = '부계정 DB 구조를 자동 설정하지 못했습니다. admin/admin_subaccounts_migration.sql 및 admin/admin_category_permissions_migration.sql을 실행해주세요.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $error === null) {
    $action = (string)($_POST['action'] ?? '');
    $targetId = (int)($_POST['admin_id'] ?? 0);
    $currentAdminId = (int)$_SESSION['admin_id'];
    $categoryPermissions = json_encode(normalizeAdminCategories($_POST['categories'] ?? []), JSON_THROW_ON_ERROR);

    try {
        if ($action === 'create_sales') {
            $username = trim((string)($_POST['new_username'] ?? ''));
            $name = trim((string)($_POST['new_name'] ?? ''));
            $password = (string)($_POST['new_account_password'] ?? '');
            $password2 = (string)($_POST['new_account_password2'] ?? '');
            $canCreate = isset($_POST['can_create']) ? 1 : 0;
            $canUpdate = isset($_POST['can_update']) ? 1 : 0;
            $canDelete = isset($_POST['can_delete']) ? 1 : 0;

            if (!preg_match('/^[A-Za-z0-9_.-]{4,50}$/', $username)) {
                throw new RuntimeException('아이디는 영문/숫자/._- 조합 4~50자로 입력해주세요.');
            }
            if ($name === '') {
                throw new RuntimeException('영업사원 이름을 입력해주세요.');
            }
            if (strlen($password) < 8) {
                throw new RuntimeException('비밀번호는 8자 이상으로 입력해주세요.');
            }
            if ($password !== $password2) {
                throw new RuntimeException('비밀번호 확인이 일치하지 않습니다.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO admin_accounts (
                    username, password_hash, name, role, parent_admin_id,
                    can_create, can_update, can_delete, category_permissions, is_active
                ) VALUES (
                    :username, :password_hash, :name, 'SALES', :parent_admin_id,
                    :can_create, :can_update, :can_delete, :category_permissions, 1
                )
            ");
            $stmt->execute([
                ':username' => $username,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':name' => $name,
                ':parent_admin_id' => $currentAdminId,
                ':category_permissions' => $categoryPermissions,
                ':can_create' => $canCreate,
                ':can_update' => $canUpdate,
                ':can_delete' => $canDelete,
            ]);

            $message = '영업사원 부계정을 추가했습니다.';
        }

        if ($action === 'bulk_permissions') {
            $selectedIds = $_POST['selected_admin_ids'] ?? [];
            if (!is_array($selectedIds)) {
                $selectedIds = [];
            }

            $selectedIds = array_values(array_unique(array_filter(
                array_map('intval', $selectedIds),
                static fn(int $id): bool => $id > 0
            )));

            if (!$selectedIds) {
                throw new RuntimeException('권한을 적용할 영업사원 계정을 선택해주세요.');
            }

            $bulkCreate = isset($_POST['bulk_can_create']) ? 1 : 0;
            $bulkUpdate = isset($_POST['bulk_can_update']) ? 1 : 0;
            $bulkDelete = isset($_POST['bulk_can_delete']) ? 1 : 0;

            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $check = $pdo->prepare("
                SELECT id
                FROM admin_accounts
                WHERE id IN ($placeholders)
                  AND role = 'SALES'
            ");
            $check->execute($selectedIds);
            $validIds = array_map('intval', $check->fetchAll(PDO::FETCH_COLUMN));

            if (!$validIds) {
                throw new RuntimeException('선택한 영업사원 계정을 찾을 수 없습니다.');
            }

            $updatePlaceholders = implode(',', array_fill(0, count($validIds), '?'));
            $stmt = $pdo->prepare("
                UPDATE admin_accounts
                SET can_create = ?,
                    can_update = ?,
                    can_delete = ?,
                    category_permissions = ?
                WHERE id IN ($updatePlaceholders)
                  AND role = 'SALES'
            ");
            $stmt->execute(array_merge(
                [$bulkCreate, $bulkUpdate, $bulkDelete, $categoryPermissions],
                $validIds
            ));

            $message = number_format(count($validIds)) . '개 영업사원 계정의 작업 및 카테고리 권한을 일괄 변경했습니다.';
        }

        if ($action === 'update') {
            $name = trim((string)($_POST['name'] ?? ''));
            $isActive = (int)($_POST['is_active'] ?? 1);
            $canCreate = isset($_POST['can_create']) ? 1 : 0;
            $canUpdate = isset($_POST['can_update']) ? 1 : 0;
            $canDelete = isset($_POST['can_delete']) ? 1 : 0;

            $stmt = $pdo->prepare("SELECT id, username, role, is_active, parent_admin_id FROM admin_accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new RuntimeException('계정을 찾을 수 없습니다.');
            }
            if (!in_array($isActive, [0,1], true)) {
                throw new RuntimeException('잘못된 상태 값입니다.');
            }
            if ($name === '') {
                throw new RuntimeException('이름을 입력해주세요.');
            }
            if ($targetId === $currentAdminId && $isActive !== 1) {
                throw new RuntimeException('현재 로그인한 본인 계정을 비활성화할 수 없습니다.');
            }
            if (
                (string)$target['role'] === 'SUPER_ADMIN' &&
                (int)$target['is_active'] === 1 &&
                $isActive !== 1 &&
                superAdminCount($pdo) <= 1
            ) {
                throw new RuntimeException('마지막 활성 SUPER_ADMIN 계정은 비활성화할 수 없습니다.');
            }

            if ((string)$target['role'] === 'SALES') {
                $stmt = $pdo->prepare("
                    UPDATE admin_accounts
                    SET name = :name,
                        is_active = :is_active,
                        can_create = :can_create,
                        can_update = :can_update,
                        can_delete = :can_delete,
                        category_permissions = :category_permissions
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':is_active' => $isActive,
                    ':can_create' => $canCreate,
                    ':can_update' => $canUpdate,
                    ':can_delete' => $canDelete,
                    ':id' => $targetId,
                    ':category_permissions' => $categoryPermissions,
                ]);
            } else {
                $stmt = $pdo->prepare("UPDATE admin_accounts SET name = :name, is_active = :is_active WHERE id = :id");
                $stmt->execute([
                    ':name' => $name,
                    ':is_active' => $isActive,
                    ':id' => $targetId,
                ]);
            }

            if ($targetId === $currentAdminId) {
                $_SESSION['admin_name'] = $name;
            }

            $message = '계정 정보를 수정했습니다.';
        }

        if ($action === 'reset_password') {
            $password = (string)($_POST['new_password'] ?? '');
            $password2 = (string)($_POST['new_password2'] ?? '');
            $currentPassword = (string)($_POST['current_password'] ?? '');

            if ($targetId <= 0) {
                throw new RuntimeException('계정을 찾을 수 없습니다.');
            }
            if (strlen($password) < 8) {
                throw new RuntimeException('새 비밀번호는 8자 이상이어야 합니다.');
            }
            if ($password !== $password2) {
                throw new RuntimeException('새 비밀번호 확인이 일치하지 않습니다.');
            }

            $stmt = $pdo->prepare("SELECT id, role, parent_admin_id, password_hash FROM admin_accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$target) {
                throw new RuntimeException('계정을 찾을 수 없습니다.');
            }

            if ($targetId !== $currentAdminId) {
                throw new RuntimeException('비밀번호는 각 계정에서 직접 변경해야 합니다.');
            }
            if ($currentPassword === '') {
                throw new RuntimeException('현재 비밀번호를 입력해주세요.');
            }
            if (!password_verify($currentPassword, (string)$target['password_hash'])) {
                throw new RuntimeException('현재 비밀번호가 일치하지 않습니다.');
            }
            $successMessage = '비밀번호를 변경했습니다.';

            $stmt = $pdo->prepare("UPDATE admin_accounts SET password_hash = :password_hash WHERE id = :id");
            $stmt->execute([
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':id' => $targetId,
            ]);

            if ($targetId === $currentAdminId) {
                session_regenerate_id(true);
            }
            $message = $successMessage;
        }

        if ($action === 'delete') {
            if ($targetId === $currentAdminId) {
                throw new RuntimeException('현재 로그인한 본인 계정은 삭제할 수 없습니다.');
            }

            $stmt = $pdo->prepare("SELECT role, is_active, parent_admin_id FROM admin_accounts WHERE id = ? LIMIT 1");
            $stmt->execute([$targetId]);
            $target = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$target) {
                throw new RuntimeException('계정을 찾을 수 없습니다.');
            }
            if ((string)$target['role'] !== 'SALES') {
                throw new RuntimeException('본 관리자 계정은 이 화면에서 삭제할 수 없습니다.');
            }

            $pdo->prepare("DELETE FROM admin_accounts WHERE id = ?")->execute([$targetId]);
            $message = '영업사원 부계정을 삭제했습니다.';
        }
    } catch (PDOException $e) {
        if ((string)$e->getCode() === '23000') {
            $error = '이미 사용 중인 아이디입니다.';
        } else {
            $error = 'DB 처리 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$admins = [];
if (adminColumnExists($pdo, 'category_permissions') && adminColumnExists($pdo, 'can_delete')) {
    $admins = $pdo->query("
        SELECT
            a.id,
            a.username,
            a.name,
            a.role,
            a.parent_admin_id,
            a.can_create,
            a.can_update,
            a.can_delete,
            a.category_permissions,
            a.is_active,
            a.last_login_at,
            a.created_at,
            p.name AS parent_name,
            p.username AS parent_username
        FROM admin_accounts a
        LEFT JOIN admin_accounts p ON p.id = a.parent_admin_id
        ORDER BY
            CASE WHEN a.role = 'SUPER_ADMIN' THEN 0 WHEN a.role = 'SALES' THEN 1 ELSE 2 END,
            a.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

$salesCount = 0;
foreach ($admins as $row) {
    if ((string)$row['role'] === 'SALES') $salesCount++;
}
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>관리자 계정 관리</title>
<link rel="stylesheet" href="./sidebar.css">
<link rel="stylesheet" href="./admin-ui.css">
<link rel="stylesheet" href="./admin-accounts.css">
</head>
<body><div class="admin-shell">
<?php $currentAdminPage='admins'; require __DIR__.'/sidebar.php'; ?>
<main class="main"><div class="wrap">
    <section class="card page-card ag-page-card">
        <div class="top">
            <div>
                <h1>관리자 계정 관리</h1>
                <div class="sub">본 관리자 계정에서 영업사원용 부계정을 생성하고 관리합니다.</div>
            </div>
            <a class="primary add-account-link" href="#createAccount" id="openCreateAccount">+ 영업사원 추가</a>
        </div>
    </section>

    <?php if ($message): ?><div class="alert ok"><?= h2($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert err"><?= h2($error) ?></div><?php endif; ?>

    <?php if ($error === null): ?>
    <details class="card create-account" id="createAccount">
        <summary class="section-head"><strong>새 영업사원 계정</strong><span>계정 정보와 초기 권한 설정</span></summary>
        <form method="post" class="create-grid" autocomplete="off">
            <input type="hidden" name="action" value="create_sales">
            <div class="field">
                <label>로그인 아이디</label>
                <input type="text" name="new_username" value="" required autocomplete="off" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore>
            </div>
            <div class="field">
                <label>영업사원 이름</label>
                <input type="text" name="new_name" value="" required autocomplete="off" data-lpignore="true" data-1p-ignore>
            </div>
            <div class="field">
                <label>비밀번호</label>
                <input type="password" name="new_account_password" value="" required autocomplete="new-password" data-lpignore="true" data-1p-ignore>
            </div>
            <div class="field">
                <label>비밀번호 확인</label>
                <input type="password" name="new_account_password2" value="" required autocomplete="new-password" data-lpignore="true" data-1p-ignore>
            </div>
            <div class="permission-create">
                <label>데이터 작업 권한</label>
                <div class="permission-checks" data-permission-group>
                    <label class="permission-check all"><input type="checkbox" data-permission-all> 전체 권한</label>
                    <label class="permission-check create"><input type="checkbox" name="can_create" value="1" data-permission-item> 등록 허용</label>
                    <label class="permission-check update"><input type="checkbox" name="can_update" value="1" data-permission-item> 수정 허용</label>
                    <label class="permission-check delete"><input type="checkbox" name="can_delete" value="1" data-permission-item> 삭제 허용</label>
                </div>
            </div>
            <div class="permission-create"><?php renderCategoryPermissions(['dashboard', 'estimates', 'inquiries']); ?></div>
            <div class="submit-cell"><button class="primary" type="submit">+ 부계정 추가</button></div>
        </form>
    </details>

    <div class="note">
        <strong>권한 설정 안내</strong><span>카테고리는 들어갈 수 있는 화면, 작업 권한은 허용할 작업입니다. 고객관리는 기본 미선택입니다.</span>
    </div>

    <section class="card">
        <div class="section-head">
            <h2>계정 목록</h2>
            <span>전체 <b><?= number_format(count($admins)) ?></b>명 · 영업사원 <b><?= number_format($salesCount) ?></b>명</span>
        </div>
        <div class="account-toolbar">
            <label class="account-search">계정 검색<input type="search" id="accountSearch" placeholder="이름 또는 아이디로 검색"></label>
            <label>계정 구분<select id="accountRole"><option value="">전체 계정</option><option value="SALES">영업사원</option><option value="OTHER">관리자</option></select></label>
            <label>상태<select id="accountStatus"><option value="">전체 상태</option><option value="1">활성</option><option value="0">비활성</option></select></label>
            <span id="accountResultCount" aria-live="polite"></span>
        </div>

        <?php if (!$admins): ?>
            <div class="empty">등록된 계정이 없습니다.</div>
        <?php else: ?>
        <?php if ($salesCount > 0): ?>
        <form method="post" id="bulkPermissionForm" class="bulk-permission-bar">
            <input type="hidden" name="action" value="bulk_permissions">

            <div class="bulk-permission-left">
                <label class="bulk-select-all">
                    <input type="checkbox" id="selectAllSales">
                    표시된 영업사원 선택
                </label>
                <span class="bulk-selected-count">선택 <strong id="bulkSelectedCount">0</strong>명</span>
            </div>

            <details class="bulk-editor">
            <summary>선택 계정 권한 일괄 설정</summary>
            <div class="bulk-permission-form">
                <div class="bulk-work"><span class="account-permissions-title">작업 권한</span>
                <label class="permission-check create">
                    <input type="checkbox" name="bulk_can_create" value="1">
                    등록
                </label>
                <label class="permission-check update">
                    <input type="checkbox" name="bulk_can_update" value="1">
                    수정
                </label>
                <label class="permission-check delete">
                    <input type="checkbox" name="bulk_can_delete" value="1">
                    삭제
                </label>
                </div>
                <?php renderCategoryPermissions([]); ?>
                <span>체크한 작업·카테고리 권한으로 모두 변경됩니다.</span>
                <button type="submit" class="bulk-apply-btn" id="bulkPermissionApply" disabled>선택 계정에 적용</button>
            </div>
            </details>
        </form>
        <?php endif; ?>

        <div class="account-list">
            <?php foreach ($admins as $admin):
                $role = (string)$admin['role'];
                $isSales = $role === 'SALES';
                $isMain = $role === 'SUPER_ADMIN';
                $displayName = trim((string)($admin['name'] ?? '')) ?: (string)$admin['username'];
                $initial = function_exists('mb_substr') ? mb_substr($displayName, 0, 1, 'UTF-8') : substr($displayName, 0, 1);
            ?>
            <article class="account-card" data-account-name="<?= h2($displayName . ' ' . (string)$admin['username']) ?>" data-role="<?= h2($role) ?>" data-active="<?= (int)$admin['is_active'] ?>">
                <div class="account-summary <?= $isSales ? 'with-select' : '' ?>">
                    <?php if ($isSales): ?>
                    <label class="account-select" aria-label="<?= h2($displayName) ?> 선택">
                        <input type="checkbox" class="sales-account-check" value="<?= (int)$admin['id'] ?>">
                    </label>
                    <?php endif; ?>
                    <div class="account-identity">
                        <div class="avatar <?= $isSales ? 'sales' : '' ?>"><?= h2($initial) ?></div>
                        <div class="account-name">
                            <div class="account-name-line">
                                <strong><?= h2($displayName) ?></strong>
                                <?php if ($isMain): ?><span class="badge owner">본 관리자</span><?php elseif ($isSales): ?><span class="badge sales">영업사원</span><?php else: ?><span class="badge owner"><?= h2($role) ?></span><?php endif; ?>
                                <?= (int)$admin['is_active'] === 1 ? '<span class="status on">활성</span>' : '<span class="status off">비활성</span>' ?>
                            </div>
                            <div class="account-username"><?= h2((string)$admin['username']) ?></div>
                        </div>
                    </div>
                    <div class="account-summary-meta">
                        <span>마지막 로그인 <b><?= h2((string)($admin['last_login_at'] ?? '-')) ?></b></span>
                        <button type="button" class="btn account-toggle" aria-expanded="false" aria-controls="accountEditor<?= (int)$admin['id'] ?>">권한 / 계정 설정</button>
                    </div>
                </div>

                <div class="account-info" hidden>
                    <div class="info-item"><span class="info-label">아이디</span><span class="info-value"><?= h2((string)$admin['username']) ?></span></div>
                    <div class="info-item"><span class="info-label">권한</span><span class="info-value"><?= $isSales ? 'SALES · 영업사원' : h2($role) ?></span></div>
                    <div class="info-item"><span class="info-label">소속 관리자</span><span class="info-value"><?= $isSales ? h2((string)($admin['parent_name'] ?: $admin['parent_username'] ?: '-')) : '본 관리자' ?></span></div>
                    <div class="info-item"><span class="info-label">생성일</span><span class="info-value"><?= h2((string)$admin['created_at']) ?></span></div>
                </div>
                <?php if ($isSales): ?>
                <div class="permission-summary" style="padding:10px 15px 0">
                    <span class="summary-label">접근 화면</span>
                    <?php $selectedCategories = adminCategoriesForAccount($admin); foreach ($selectedCategories as $category): ?>
                    <span class="category-chip"><?= h2(adminCategoryLabels()[$category]) ?></span>
                    <?php endforeach; ?>
                    <?php if (!$selectedCategories): ?><span class="muted">허용된 카테고리 없음</span><?php endif; ?>
                    <span class="summary-label work-label">작업</span>
                    <span class="permission-chip <?= (int)$admin['can_create']===1?'on':'' ?>">등록 <?= (int)$admin['can_create']===1?'허용':'차단' ?></span>
                    <span class="permission-chip <?= (int)$admin['can_update']===1?'on':'' ?>">수정 <?= (int)$admin['can_update']===1?'허용':'차단' ?></span>
                    <span class="permission-chip <?= (int)$admin['can_delete']===1?'on':'' ?>">삭제 <?= (int)$admin['can_delete']===1?'허용':'차단' ?></span>
                </div>
                <?php endif; ?>

                <div class="account-manage" id="accountEditor<?= (int)$admin['id'] ?>" hidden>
                    <div class="manage-title">계정 정보와 권한 수정 <span class="dirty-indicator" hidden>저장하지 않은 변경</span></div>
                    <div class="manage-grid">
                        <div class="manage-box">
                            <label>이름 / 상태</label>
                            <form method="post" class="account-update-form">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                <div class="manage-row">
                                    <input type="text" name="name" aria-label="이름" value="<?= h2($displayName) ?>" required autocomplete="off">
                                    <select name="is_active" aria-label="계정 상태">
                                        <option value="1" <?= (int)$admin['is_active']===1?'selected':'' ?>>활성</option>
                                        <option value="0" <?= (int)$admin['is_active']===0?'selected':'' ?>>비활성</option>
                                    </select>
                                </div>
                                <?php if ($isSales): ?>
                                <div class="account-permissions" data-permission-group>
                                    <span class="account-permissions-title">작업 권한</span>
                                    <label class="permission-check all"><input type="checkbox" data-permission-all> 전체</label>
                                    <label class="permission-check create"><input type="checkbox" name="can_create" value="1" data-permission-item <?= (int)$admin['can_create']===1?'checked':'' ?>> 등록</label>
                                    <label class="permission-check update"><input type="checkbox" name="can_update" value="1" data-permission-item <?= (int)$admin['can_update']===1?'checked':'' ?>> 수정</label>
                                    <label class="permission-check delete"><input type="checkbox" name="can_delete" value="1" data-permission-item <?= (int)$admin['can_delete']===1?'checked':'' ?>> 삭제</label>
                                </div>
                                <?php renderCategoryPermissions(adminCategoriesForAccount($admin)); ?>
                                <?php endif; ?>
                                <div class="editor-save"><span>체크한 권한을 확인한 후 저장하세요.</span><button class="btn save" type="submit">변경사항 저장</button></div>
                            </form>
                        </div>
                        <div class="manage-box">
                            <?php if ((int)$admin['id'] === (int)$_SESSION['admin_id']): ?>
                                <label>비밀번호 변경</label>
                                <form method="post" class="password-row self-password" autocomplete="off">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                    <input type="password" name="current_password" placeholder="현재 비밀번호" required autocomplete="current-password">
                                    <input type="password" name="new_password" placeholder="새 비밀번호 (8자 이상)" required autocomplete="new-password">
                                    <input type="password" name="new_password2" placeholder="새 비밀번호 확인" required autocomplete="new-password">
                                    <button class="btn pw" type="submit">변경</button>
                                </form>
                            <?php else: ?>
                                <label>비밀번호</label>
                                <div class="self">영업사원 본인이 내 계정에서 변경</div>
                            <?php endif; ?>
                        </div>
                        <?php if ((int)$admin['id'] === (int)$_SESSION['admin_id']): ?>
                            <div class="self">현재 로그인 계정</div>
                        <?php elseif ($isSales): ?>
                            <form method="post" class="delete-form" onsubmit="return confirm('이 영업사원 부계정을 삭제할까요?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                <button class="btn delete" type="submit">부계정 삭제</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </article>
            <?php endforeach; ?>
        </div>
        <p id="accountNoResults" class="empty" hidden>검색 조건에 맞는 계정이 없습니다.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div></main></div>






<script src="./admin-accounts.js"></script>
</body>
</html>
