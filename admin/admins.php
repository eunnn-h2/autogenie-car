<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=utf-8');

require_once __DIR__ . '/auth.php';
requireSuperAdmin();
require_once __DIR__ . '/../config/database.php';

$message = null;
$error = null;
if (empty($_SESSION['admin_accounts_csrf'])) {
    $_SESSION['admin_accounts_csrf'] = bin2hex(random_bytes(32));
}

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

function salesTeamExists(PDO $pdo, string $teamName): bool {
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM sales_teams WHERE team_name = :team_name AND is_active = 1");
    $stmt->execute([':team_name' => $teamName]);
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
    if (!adminColumnExists($pdo, 'team_name')) {
        $pdo->exec("ALTER TABLE admin_accounts ADD COLUMN team_name VARCHAR(50) NULL AFTER parent_admin_id");
        $pdo->exec("ALTER TABLE admin_accounts ADD INDEX idx_admin_accounts_team_name (team_name)");
    }

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sales_teams (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            team_name VARCHAR(50) NOT NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_sales_teams_name (team_name),
            KEY idx_sales_teams_active_sort (is_active, sort_order, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // 기존 계정에 직접 입력되어 있던 팀명은 최초 1회 팀 목록으로 자동 이관합니다.
    $pdo->exec("
        INSERT IGNORE INTO sales_teams (team_name, is_active, sort_order)
        SELECT DISTINCT TRIM(team_name), 1, 0
        FROM admin_accounts
        WHERE role = 'SALES' AND team_name IS NOT NULL AND TRIM(team_name) <> ''
    ");

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
        if ($action === 'create_team') {
            $token = $_POST['csrf_token'] ?? '';
            if (!is_string($token) || !hash_equals($_SESSION['admin_accounts_csrf'], $token)) {
                throw new RuntimeException('요청을 확인할 수 없습니다. 새로고침 후 다시 시도해주세요.');
            }
            $teamName = trim((string)($_POST['team_name'] ?? ''));
            if ($teamName === '') throw new RuntimeException('추가할 팀명을 입력해주세요.');
            if (mb_strlen($teamName, 'UTF-8') > 50) throw new RuntimeException('팀명은 50자 이하로 입력해주세요.');
            $stmt = $pdo->prepare("INSERT INTO sales_teams (team_name, is_active) VALUES (:team_name, 1)");
            $stmt->execute([':team_name' => $teamName]);
            $message = $teamName . '을(를) 추가했습니다.';
        }

        if ($action === 'delete_team') {
            $token = $_POST['csrf_token'] ?? '';
            if (!is_string($token) || !hash_equals($_SESSION['admin_accounts_csrf'], $token)) {
                throw new RuntimeException('요청을 확인할 수 없습니다. 새로고침 후 다시 시도해주세요.');
            }
            $teamId = (int)($_POST['team_id'] ?? 0);
            $stmt = $pdo->prepare("SELECT id, team_name FROM sales_teams WHERE id = ? LIMIT 1");
            $stmt->execute([$teamId]);
            $team = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$team) throw new RuntimeException('팀을 찾을 수 없습니다.');
            $useStmt = $pdo->prepare("SELECT COUNT(*) FROM admin_accounts WHERE role = 'SALES' AND team_name = ?");
            $useStmt->execute([(string)$team['team_name']]);
            if ((int)$useStmt->fetchColumn() > 0) {
                throw new RuntimeException('소속 영업사원이 있는 팀은 삭제할 수 없습니다. 먼저 영업사원의 팀을 변경해주세요.');
            }
            $pdo->prepare("DELETE FROM sales_teams WHERE id = ?")->execute([$teamId]);
            $message = '팀을 삭제했습니다.';
        }

        if ($action === 'save_permission_table') {
            $token = $_POST['csrf_token'] ?? '';
            if (!is_string($token) || !hash_equals($_SESSION['admin_accounts_csrf'], $token)) {
                throw new RuntimeException('요청을 확인할 수 없습니다. 새로고침 후 다시 시도해주세요.');
            }
            require_once __DIR__ . '/account-permission-settings.php';
            $payload = $_POST['permission_changes'] ?? '';
            if (!is_string($payload)) throw new RuntimeException('잘못된 권한 설정입니다.');
            $changed = saveAccountPermissionChanges($pdo, parseAccountPermissionChanges($payload), $currentAdminId);
            $message = number_format($changed) . '개 계정의 권한 설정을 저장했습니다.';
        }
        if ($action === 'create_sales') {
            $username = trim((string)($_POST['new_username'] ?? ''));
            $name = trim((string)($_POST['new_name'] ?? ''));
            $teamName = trim((string)($_POST['new_team_name'] ?? ''));
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
            if ($teamName === '') {
                throw new RuntimeException('소속 팀을 선택해주세요.');
            }
            if (!salesTeamExists($pdo, $teamName)) {
                throw new RuntimeException('등록된 팀 목록에서 소속 팀을 선택해주세요.');
            }
            if (strlen($password) < 8) {
                throw new RuntimeException('비밀번호는 8자 이상으로 입력해주세요.');
            }
            if ($password !== $password2) {
                throw new RuntimeException('비밀번호 확인이 일치하지 않습니다.');
            }

            $stmt = $pdo->prepare("
                INSERT INTO admin_accounts (
                    username, password_hash, name, role, parent_admin_id, team_name,
                    can_create, can_update, can_delete, category_permissions, is_active
                ) VALUES (
                    :username, :password_hash, :name, 'SALES', :parent_admin_id, :team_name,
                    :can_create, :can_update, :can_delete, :category_permissions, 1
                )
            ");
            $stmt->execute([
                ':username' => $username,
                ':password_hash' => password_hash($password, PASSWORD_DEFAULT),
                ':name' => $name,
                ':parent_admin_id' => $currentAdminId,
                ':team_name' => $teamName,
                ':category_permissions' => $categoryPermissions,
                ':can_create' => $canCreate,
                ':can_update' => $canUpdate,
                ':can_delete' => $canDelete,
            ]);

            $message = '영업사원 부계정을 추가했습니다.';
        }

        if ($action === 'bulk_settings') {
            $token = $_POST['csrf_token'] ?? '';
            if (!is_string($token) || !hash_equals($_SESSION['admin_accounts_csrf'], $token)) {
                throw new RuntimeException('요청을 확인할 수 없습니다. 새로고침 후 다시 시도해주세요.');
            }
            $selectedIds = $_POST['selected_admin_ids'] ?? [];
            if (!is_array($selectedIds)) $selectedIds = [];
            $selectedIds = array_values(array_unique(array_filter($selectedIds,
                static fn($id): bool => is_scalar($id) && filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false
            )));
            if (!$selectedIds) throw new RuntimeException('설정을 적용할 영업사원 계정을 선택해주세요.');
            $statusChoice = $_POST['bulk_status'] ?? '';
            if (!in_array($statusChoice, ['', 'bulk_activate', 'bulk_deactivate'], true)) {
                throw new RuntimeException('잘못된 계정 상태입니다.');
            }
            $changes = [];
            foreach (['bulk_work_choice' => ['can_create', 'can_update', 'can_delete'], 'bulk_category_choice' => array_keys(adminCategoryLabels())] as $field => $allowed) {
                $choice = $_POST[$field] ?? '';
                if ($choice === '') continue;
                $parts = is_string($choice) ? explode(':', $choice) : [];
                if (count($parts) !== 2 || !in_array($parts[0], $allowed, true) || !in_array($parts[1], ['0', '1'], true)) {
                    throw new RuntimeException('잘못된 권한 설정입니다.');
                }
                $changes[$field] = $parts;
            }
            if ($statusChoice === '' && !$changes) throw new RuntimeException('변경할 설정을 하나 이상 선택해주세요.');
            $placeholders = implode(',', array_fill(0, count($selectedIds), '?'));
            $changed = 0;
            $pdo->beginTransaction();
            try {
                $stmt = $pdo->prepare("SELECT * FROM admin_accounts WHERE id IN ($placeholders) AND role = 'SALES' AND id <> ? FOR UPDATE");
                $stmt->execute(array_merge($selectedIds, [$currentAdminId]));
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $account) {
                    $assignments = [];
                    $values = [];
                    if ($statusChoice !== '') {
                        $assignments[] = 'is_active = ?';
                        $values[] = $statusChoice === 'bulk_activate' ? 1 : 0;
                    }
                    if (isset($changes['bulk_work_choice'])) {
                        [$permission, $enabled] = $changes['bulk_work_choice'];
                        $assignments[] = "$permission = ?";
                        $values[] = (int)$enabled;
                    }
                    if ($changes) {
                        // Resolve legacy defaults before changing work permissions.
                        $categories = adminCategoriesForAccount($account);
                        if (isset($changes['bulk_category_choice'])) {
                            [$category, $enabled] = $changes['bulk_category_choice'];
                            $categories = array_values(array_diff($categories, [$category]));
                            if ($enabled === '1') $categories[] = $category;
                        }
                        $assignments[] = 'category_permissions = ?';
                        $values[] = json_encode(normalizeAdminCategories($categories), JSON_THROW_ON_ERROR);
                    }
                    $values[] = $account['id'];
                    $update = $pdo->prepare('UPDATE admin_accounts SET ' . implode(', ', $assignments) . " WHERE id = ? AND role = 'SALES'");
                    $update->execute($values);
                    $changed += $update->rowCount();
                }
                $pdo->commit();
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $message = '선택한 설정을 적용했습니다. 변경된 계정: ' . number_format($changed) . '개';
        }

        if ($action === 'update') {
            $name = trim((string)($_POST['name'] ?? ''));
            $teamName = trim((string)($_POST['team_name'] ?? ''));
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
                if ($teamName === '') {
                    throw new RuntimeException('영업사원의 소속 팀을 선택해주세요.');
                }
                if (!salesTeamExists($pdo, $teamName)) {
                    throw new RuntimeException('등록된 팀 목록에서 소속 팀을 선택해주세요.');
                }
                $stmt = $pdo->prepare("
                    UPDATE admin_accounts
                    SET name = :name,
                        team_name = :team_name,
                        is_active = :is_active,
                        can_create = :can_create,
                        can_update = :can_update,
                        can_delete = :can_delete,
                        category_permissions = :category_permissions
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':name' => $name,
                    ':team_name' => $teamName,
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
            $token = $_POST['csrf_token'] ?? '';
            if (!is_string($token) || !hash_equals($_SESSION['admin_accounts_csrf'], $token)) {
                throw new RuntimeException('요청을 확인할 수 없습니다. 새로고침 후 다시 시도해주세요.');
            }
            $password = (string)($_POST['new_password'] ?? '');
            $password2 = (string)($_POST['new_password2'] ?? '');
            $currentPassword = (string)($_POST['current_password'] ?? '');

            if ($targetId <= 0) {
                throw new RuntimeException('계정을 찾을 수 없습니다.');
            }
            if (strlen($password) < 8) {
                throw new RuntimeException('새 비밀번호는 8자 이상이어야 합니다.');
            }
            if (strlen($password) > 72) {
                throw new RuntimeException('새 비밀번호는 72바이트 이하여야 합니다.');
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

            if ($targetId === $currentAdminId) {
                if ($currentPassword === '') {
                    throw new RuntimeException('현재 비밀번호를 입력해주세요.');
                }
                if (!password_verify($currentPassword, (string)$target['password_hash'])) {
                    throw new RuntimeException('현재 비밀번호가 일치하지 않습니다.');
                }
            } elseif ((string)$target['role'] !== 'SALES') {
                throw new RuntimeException('영업사원 계정만 비밀번호를 초기화할 수 있습니다.');
            }
            $successMessage = $targetId === $currentAdminId
                ? '비밀번호를 변경했습니다.'
                : '영업사원 비밀번호를 초기화했습니다. 새 비밀번호를 전달하고 로그인 후 내 계정에서 변경하도록 안내해주세요.';

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
            $error = $action === 'create_team' ? '이미 등록된 팀명입니다.' : '이미 사용 중인 아이디입니다.';
        } else {
            $error = 'DB 처리 중 오류가 발생했습니다: ' . $e->getMessage();
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$admins = [];
if (adminColumnExists($pdo, 'category_permissions') && adminColumnExists($pdo, 'can_delete') && adminColumnExists($pdo, 'team_name')) {
    $admins = $pdo->query("
        SELECT
            a.id,
            a.username,
            a.name,
            a.role,
            a.parent_admin_id,
            a.team_name,
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
$teams = [];
if ($error === null) {
    $teams = $pdo->query("
        SELECT t.id, t.team_name, t.is_active, t.sort_order, t.created_at,
               (SELECT COUNT(*) FROM admin_accounts a WHERE a.role = 'SALES' AND a.team_name = t.team_name) AS member_count
        FROM sales_teams t
        WHERE t.is_active = 1
        ORDER BY t.sort_order ASC, t.id ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}
$teamNames = array_map(static fn(array $team): string => (string)$team['team_name'], $teams);
?>
<!DOCTYPE html>
<html lang="ko">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>관리자 계정 관리</title>
<link rel="stylesheet" href="./sidebar.css?v=<?= filemtime(__DIR__ . '/sidebar.css') ?>">
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
            <div class="top-actions">
                <button type="button" class="btn team-open-btn" id="openTeamManager" aria-haspopup="dialog" aria-controls="teamManager" <?= $error !== null ? 'disabled' : '' ?>>+ 팀 추가</button>
                <button type="button" class="primary add-account-link" id="openCreateAccount" aria-haspopup="dialog" aria-controls="createAccount" <?= ($error !== null || !$teams) ? 'disabled' : '' ?> title="<?= !$teams ? '영업팀을 먼저 추가해주세요.' : '영업사원 계정 추가' ?>">+ 영업사원 추가</button>
            </div>
        </div>
    </section>

    <?php if ($message): ?><div class="alert ok"><?= h2($message) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert err"><?= h2($error) ?></div><?php endif; ?>

    <?php if ($error === null): ?>
    <dialog class="team-manager-dialog" id="teamManager" aria-labelledby="teamManagerTitle" <?= in_array((string)($_POST['action'] ?? ''), ['create_team', 'delete_team'], true) ? 'data-reopen="1"' : '' ?>>
        <div class="section-head team-dialog-head">
            <div>
                <h2 id="teamManagerTitle">영업팀 관리</h2>
                <span>영업팀을 추가한 뒤 영업사원 계정에서 소속 팀을 선택할 수 있습니다.</span>
            </div>
            <button type="button" class="btn" id="closeTeamManager" aria-label="영업팀 관리 팝업 닫기">닫기</button>
        </div>
        <form method="post" class="team-add-form" autocomplete="off">
            <input type="hidden" name="action" value="create_team">
            <input type="hidden" name="csrf_token" value="<?= h2($_SESSION['admin_accounts_csrf']) ?>">
            <label class="team-name-field">
                <span>팀명</span>
                <input type="text" name="team_name" placeholder="예: 1팀" maxlength="50" required aria-label="추가할 팀명">
            </label>
            <button type="submit" class="primary">+ 팀 추가</button>
        </form>
        <div class="team-dialog-divider"></div>
        <div class="team-list-title">등록된 팀 <strong><?= number_format(count($teams)) ?></strong></div>
        <div class="team-list">
            <?php if (!$teams): ?>
                <div class="team-empty">등록된 팀이 없습니다. 위에서 팀을 먼저 추가해주세요.</div>
            <?php else: foreach ($teams as $team): ?>
                <div class="team-item">
                    <div class="team-item-info"><strong><?= h2((string)$team['team_name']) ?></strong><span>영업사원 <?= number_format((int)$team['member_count']) ?>명</span></div>
                    <?php if ((int)$team['member_count'] === 0): ?>
                    <form method="post" onsubmit="return confirm('<?= h2((string)$team['team_name']) ?>을(를) 삭제할까요?');">
                        <input type="hidden" name="action" value="delete_team">
                        <input type="hidden" name="csrf_token" value="<?= h2($_SESSION['admin_accounts_csrf']) ?>">
                        <input type="hidden" name="team_id" value="<?= (int)$team['id'] ?>">
                        <button type="submit" class="btn team-delete">삭제</button>
                    </form>
                    <?php else: ?><span class="team-in-use">사용 중</span><?php endif; ?>
                </div>
            <?php endforeach; endif; ?>
        </div>
    </dialog>

    <dialog class="create-account" id="createAccount" aria-labelledby="createAccountTitle">
        <div class="section-head"><h2 id="createAccountTitle">새 계정 추가</h2><button type="button" class="btn" id="closeCreateAccount" aria-label="새 계정 추가 팝업 닫기">닫기</button></div>
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
                <label>소속 팀</label>
                <select name="new_team_name" required <?= !$teams ? 'disabled' : '' ?>>
                    <option value="">팀 선택</option>
                    <?php foreach ($teams as $team): ?><option value="<?= h2((string)$team['team_name']) ?>"><?= h2((string)$team['team_name']) ?></option><?php endforeach; ?>
                </select>
                <?php if (!$teams): ?><small class="field-help">먼저 영업팀을 추가해주세요.</small><?php endif; ?>
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
                <label>차량 관리 권한</label>
                <div class="permission-checks" data-permission-group>
                    <label class="permission-check all"><input type="checkbox" data-permission-all> 전체 선택</label>
                    <label class="permission-check create"><input type="checkbox" name="can_create" value="1" data-permission-item> 차량 등록</label>
                    <label class="permission-check update"><input type="checkbox" name="can_update" value="1" data-permission-item> 차량 수정</label>
                    <label class="permission-check delete"><input type="checkbox" name="can_delete" value="1" data-permission-item> 차량 삭제</label>
                </div>
            </div>
            <div class="permission-create"><?php renderCategoryPermissions(['dashboard', 'estimates', 'inquiries']); ?></div>
            <div class="submit-cell"><button class="primary" type="submit">+ 부계정 추가</button></div>
        </form>
    </dialog>
    <div class="note">
        <strong>권한 설정 안내</strong><span>카테고리는 접근 가능한 화면입니다. 차량 등록·수정·삭제 권한은 차량 데이터 메뉴에서만 적용되며, 견적문의·고객문의 처리는 각 메뉴 접근 권한으로 이용합니다.</span>
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
            <input type="hidden" name="csrf_token" value="<?= h2($_SESSION['admin_accounts_csrf']) ?>">

            <div class="bulk-permission-left">
                <label class="bulk-select-all">
                    <input type="checkbox" id="selectAllSales">
                    표시된 영업사원 선택
                </label>
                <span class="bulk-selected-count">선택 <strong id="bulkSelectedCount">0</strong>명</span>
            </div>

            <div class="bulk-settings">
            <div class="bulk-setting">
                <h3>계정 상태</h3>
                <div class="bulk-action-controls">
                <select name="bulk_status" id="bulkAction" aria-label="변경할 계정 상태">
                    <option value="">상태 선택</option>
                    <option value="bulk_activate">활성화</option>
                    <option value="bulk_deactivate">비활성화</option>
                </select>
                </div>
            </div>
            <div class="bulk-setting">
                <h3>차량 관리 권한</h3>
                <div class="bulk-action-controls">
                <select name="bulk_work_choice" aria-label="변경할 작업 권한" data-bulk-choice>
                    <option value="">차량 권한 선택</option>
                    <?php foreach (['can_create' => '등록', 'can_update' => '수정', 'can_delete' => '삭제'] as $key => $label): ?>
                    <option value="<?= h2($key) ?>:1">차량 <?= h2($label) ?> 허용</option>
                    <option value="<?= h2($key) ?>:0">차량 <?= h2($label) ?> 차단</option>
                    <?php endforeach; ?>
                </select>
                </div>
            </div>
            <div class="bulk-setting">
                <h3>카테고리 접근 권한</h3>
                <div class="bulk-action-controls">
                <select name="bulk_category_choice" aria-label="변경할 카테고리 접근 권한" data-bulk-choice>
                    <option value="">카테고리 선택</option>
                    <?php foreach (adminCategoryLabels() as $key => $label): ?>
                    <option value="<?= h2($key) ?>:1">차량 <?= h2($label) ?> 허용</option>
                    <option value="<?= h2($key) ?>:0">차량 <?= h2($label) ?> 차단</option>
                    <?php endforeach; ?>
                </select>
                </div>
            </div>
            <button type="submit" name="action" value="bulk_settings" class="bulk-apply-btn" id="bulkPermissionApply" disabled>적용</button>
            </div>
        </form>
        <?php endif; ?>

        <?php require __DIR__ . '/account-permissions-table.php'; ?>
        <div class="account-dialogs">
            <?php foreach ($admins as $admin):
                $role = (string)$admin['role'];
                $isSales = $role === 'SALES';
                $isMain = $role === 'SUPER_ADMIN';
                $displayName = trim((string)($admin['name'] ?? '')) ?: (string)$admin['username'];
                $initial = function_exists('mb_substr') ? mb_substr($displayName, 0, 1, 'UTF-8') : substr($displayName, 0, 1);
            ?>
            <dialog class="account-card account-detail-dialog" id="accountDialog<?= (int)$admin['id'] ?>" aria-label="<?= h2($displayName) ?> 계정 설정">
                <div class="account-dialog-close"><button type="button" class="btn" data-close-account>닫기</button></div>
                <div class="account-summary <?= $isSales ? 'with-select' : '' ?>">
                    <div class="account-identity">
                        <div class="avatar <?= $isSales ? 'sales' : '' ?>"><?= h2($initial) ?></div>
                        <div class="account-name">
                            <div class="account-name-line">
                                <strong><?= h2($displayName) ?></strong>
                                <?php if ($isMain): ?><span class="badge owner">본 관리자</span><?php elseif ($isSales): ?><span class="badge sales">영업사원</span><span class="badge team"><?= h2(trim((string)($admin['team_name'] ?? '')) ?: '팀 미지정') ?></span><?php else: ?><span class="badge owner"><?= h2($role) ?></span><?php endif; ?>
                                <?= (int)$admin['is_active'] === 1 ? '<span class="status on">활성</span>' : '<span class="status off">비활성</span>' ?>
                            </div>
                            <div class="account-username"><?= h2((string)$admin['username']) ?></div>
                        </div>
                    </div>
                    <div class="account-summary-meta">
                        <span>마지막 로그인 <b><?= h2((string)($admin['last_login_at'] ?? '-')) ?></b></span>
                    </div>
                </div>

                <div class="account-info" hidden>
                    <div class="info-item"><span class="info-label">아이디</span><span class="info-value"><?= h2((string)$admin['username']) ?></span></div>
                    <div class="info-item"><span class="info-label">권한</span><span class="info-value"><?= $isSales ? 'SALES · 영업사원' : h2($role) ?></span></div>
                    <?php if ($isSales): ?><div class="info-item"><span class="info-label">소속 팀</span><span class="info-value"><?= h2(trim((string)($admin['team_name'] ?? '')) ?: '미지정') ?></span></div><?php endif; ?>
                    <div class="info-item"><span class="info-label">소속 관리자</span><span class="info-value"><?= $isSales ? h2((string)($admin['parent_name'] ?: $admin['parent_username'] ?: '-')) : '본 관리자' ?></span></div>
                    <div class="info-item"><span class="info-label">생성일</span><span class="info-value"><?= h2((string)$admin['created_at']) ?></span></div>
                </div>

                <div class="account-manage" id="accountEditor<?= (int)$admin['id'] ?>">
                    <div class="manage-title"><span class="dirty-indicator" hidden>저장하지 않은 변경</span></div>
                    <div class="manage-grid">
                        <div class="manage-box">
                            <label>이름 / 소속 팀 / 상태</label>
                            <form method="post" class="account-update-form">
                                <input type="hidden" name="action" value="update">
                                <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                <div class="manage-row <?= $isSales ? 'with-team' : '' ?>">
                                    <input type="text" name="name" aria-label="이름" value="<?= h2($displayName) ?>" required autocomplete="off">
                                    <?php if ($isSales): ?><select name="team_name" aria-label="소속 팀" required>
                                        <option value="">팀 선택</option>
                                        <?php foreach ($teams as $team): $teamName = (string)$team['team_name']; ?><option value="<?= h2($teamName) ?>" <?= $teamName === (string)($admin['team_name'] ?? '') ? 'selected' : '' ?>><?= h2($teamName) ?></option><?php endforeach; ?>
                                    </select><?php endif; ?>
                                    <select name="is_active" aria-label="계정 상태">
                                        <option value="1" <?= (int)$admin['is_active']===1?'selected':'' ?>>활성</option>
                                        <option value="0" <?= (int)$admin['is_active']===0?'selected':'' ?>>비활성</option>
                                    </select>
                                </div>
                                <?php if ($isSales): ?>
                                <div class="account-permissions" data-permission-group>
                                    <span class="account-permissions-title">차량 관리 권한</span>
                                    <label class="permission-check all"><input type="checkbox" data-permission-all> 전체</label>
                                    <label class="permission-check create"><input type="checkbox" name="can_create" value="1" data-permission-item <?= (int)$admin['can_create']===1?'checked':'' ?>> 차량 등록</label>
                                    <label class="permission-check update"><input type="checkbox" name="can_update" value="1" data-permission-item <?= (int)$admin['can_update']===1?'checked':'' ?>> 차량 수정</label>
                                    <label class="permission-check delete"><input type="checkbox" name="can_delete" value="1" data-permission-item <?= (int)$admin['can_delete']===1?'checked':'' ?>> 차량 삭제</label>
                                </div>
                                <?php renderCategoryPermissions(adminCategoriesForAccount($admin)); ?>
                                <?php endif; ?>
                                <div class="editor-save"><span>체크한 권한을 확인한 후 저장하세요.</span><button class="btn save" type="submit">변경사항 저장</button></div>
                            </form>
                        </div>
                        <div class="manage-box account-password-section">
                            <?php if ((int)$admin['id'] === (int)$_SESSION['admin_id']): ?>
                                <div class="password-intro"><span class="password-eyebrow">SECURITY</span><h2 id="passwordTitle<?= (int)$admin['id'] ?>">비밀번호 변경</h2><p>계정을 보호하기 위해 다른 서비스에서 사용하지 않는 비밀번호를 설정하세요.</p></div>
                                <form method="post" class="account-password-form self-password" autocomplete="off" aria-labelledby="passwordTitle<?= (int)$admin['id'] ?>">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="csrf_token" value="<?= h2($_SESSION['admin_accounts_csrf']) ?>">
                                    <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                    <div class="password-field"><label for="currentPassword<?= (int)$admin['id'] ?>">현재 비밀번호</label><input id="currentPassword<?= (int)$admin['id'] ?>" type="password" name="current_password" placeholder="현재 비밀번호를 입력하세요" required autocomplete="current-password"></div>
                                    <div class="password-field"><label for="newPassword<?= (int)$admin['id'] ?>">새 비밀번호</label><input id="newPassword<?= (int)$admin['id'] ?>" type="password" name="new_password" placeholder="새 비밀번호를 입력하세요" minlength="8" maxlength="72" required autocomplete="new-password" aria-describedby="passwordHelp<?= (int)$admin['id'] ?>"><p id="passwordHelp<?= (int)$admin['id'] ?>" class="password-field-help">8자 이상으로, 현재 비밀번호와 다르게 입력해주세요.</p></div>
                                    <div class="password-field"><label for="confirmPassword<?= (int)$admin['id'] ?>">새 비밀번호 확인</label><input id="confirmPassword<?= (int)$admin['id'] ?>" type="password" name="new_password2" placeholder="새 비밀번호를 한 번 더 입력하세요" minlength="8" maxlength="72" required autocomplete="new-password"></div>
                                    <div class="password-submit"><p>현재 비밀번호가 일치할 때만 변경됩니다.</p><button class="btn pw" type="submit">비밀번호 변경</button></div>
                                </form>
                            <?php elseif ($isSales): ?>
                                <div class="password-intro"><span class="password-eyebrow">SECURITY</span><h2 id="passwordTitle<?= (int)$admin['id'] ?>">비밀번호 초기화</h2><p>현재 비밀번호 없이<br><span class="password-reset-description">새 비밀번호로 초기화합니다.</span></p><div class="password-security-note"><strong>초기화 후 안내</strong><p>영업사원에게 전달한 뒤 내 계정에서 변경하도록 안내해주세요.</p></div></div>
                                <form method="post" class="account-password-form" autocomplete="off" aria-labelledby="passwordTitle<?= (int)$admin['id'] ?>" onsubmit="return confirm('이 영업사원의 비밀번호를 초기화할까요? 기존 비밀번호로는 로그인할 수 없게 됩니다.');">
                                    <input type="hidden" name="action" value="reset_password">
                                    <input type="hidden" name="csrf_token" value="<?= h2($_SESSION['admin_accounts_csrf']) ?>">
                                    <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                    <div class="password-field"><label for="newPassword<?= (int)$admin['id'] ?>">새 비밀번호</label><input id="newPassword<?= (int)$admin['id'] ?>" type="password" name="new_password" placeholder="새 비밀번호를 입력하세요" minlength="8" maxlength="72" required autocomplete="new-password" aria-describedby="passwordHelp<?= (int)$admin['id'] ?>"><p id="passwordHelp<?= (int)$admin['id'] ?>" class="password-field-help">8자 이상으로 입력해주세요.</p></div>
                                    <div class="password-field"><label for="confirmPassword<?= (int)$admin['id'] ?>">새 비밀번호 확인</label><input id="confirmPassword<?= (int)$admin['id'] ?>" type="password" name="new_password2" placeholder="새 비밀번호를 한 번 더 입력하세요" minlength="8" maxlength="72" required autocomplete="new-password"></div>
                                    <div class="password-submit"><button class="btn pw" type="submit">비밀번호 초기화</button></div>
                                </form>
                            <?php else: ?>
                                <label>비밀번호</label>
                                <div class="self">본인이 내 계정에서 변경</div>
                            <?php endif; ?>
                        </div>
                        <?php if ((int)$admin['id'] === (int)$_SESSION['admin_id']): ?>
                            <div class="self">현재 로그인 계정</div>
                        <?php elseif ($isSales): ?>
                            <details class="account-delete-options">
                                <summary>계정 삭제 옵션</summary>
                            <form method="post" class="delete-form" onsubmit="return confirm('이 영업사원 부계정을 삭제할까요?');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="admin_id" value="<?= (int)$admin['id'] ?>">
                                <button class="btn delete" type="submit">부계정 삭제</button>
                            </form>
                            </details>
                        <?php endif; ?>
                    </div>
                </div>
            </dialog>
            <?php endforeach; ?>
        </div>
        <p id="accountNoResults" class="empty" hidden>검색 조건에 맞는 계정이 없습니다.</p>
        <?php endif; ?>
    </section>
    <?php endif; ?>
</div></main></div>






<script src="./admin-accounts.js?v=<?= filemtime(__DIR__ . '/admin-accounts.js') ?>"></script>
</body>
</html>
