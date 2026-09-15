<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

$timeoutSeconds = 8 * 60 * 60;

if (!isset($_SESSION['admin_id'], $_SESSION['admin_username'], $_SESSION['admin_role'])) {
    header('Location: ./login.php');
    exit;
}

$lastActivity = (int)($_SESSION['admin_last_activity'] ?? 0);

if ($lastActivity > 0 && (time() - $lastActivity) > $timeoutSeconds) {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path'],
            $params['domain'],
            (bool)$params['secure'],
            (bool)$params['httponly']
        );
    }

    session_destroy();
    header('Location: ./login.php?expired=1');
    exit;
}

$_SESSION['admin_last_activity'] = time();

function adminRole(): string {
    return (string)($_SESSION['admin_role'] ?? 'VIEWER');
}

function isSuperAdmin(): bool {
    return adminRole() === 'SUPER_ADMIN';
}

function isSalesAdmin(): bool {
    return adminRole() === 'SALES';
}

/**
 * SALES 계정의 데이터 작업 권한.
 * SUPER_ADMIN / ADMIN은 기존처럼 전체 CRUD 허용.
 * VIEWER는 모두 차단.
 */
function adminCrudPermissions(): array {
    static $permissions = null;

    if (is_array($permissions)) {
        return $permissions;
    }

    $role = adminRole();

    if (in_array($role, ['SUPER_ADMIN', 'ADMIN'], true)) {
        return $permissions = ['create' => true, 'update' => true, 'delete' => true];
    }

    if ($role !== 'SALES') {
        return $permissions = ['create' => false, 'update' => false, 'delete' => false];
    }

    $permissions = ['create' => false, 'update' => false, 'delete' => false];

    try {
        require_once __DIR__ . '/../config/database.php';
        global $pdo;

        if (!isset($pdo) || !($pdo instanceof PDO)) {
            return $permissions;
        }

        $required = ['can_create', 'can_update', 'can_delete'];
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'admin_accounts'
              AND COLUMN_NAME IN ('can_create','can_update','can_delete')
        ");
        $stmt->execute();

        if ((int)$stmt->fetchColumn() < 3) {
            return $permissions;
        }

        $stmt = $pdo->prepare("
            SELECT can_create, can_update, can_delete
            FROM admin_accounts
            WHERE id = ?
            LIMIT 1
        ");
        $stmt->execute([(int)($_SESSION['admin_id'] ?? 0)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $permissions = [
                'create' => (int)$row['can_create'] === 1,
                'update' => (int)$row['can_update'] === 1,
                'delete' => (int)$row['can_delete'] === 1,
            ];
        }
    } catch (Throwable $e) {
        // 권한 컬럼이 아직 없거나 DB 조회 실패 시 SALES는 안전하게 모두 차단.
    }

    return $permissions;
}

function canCreateData(): bool {
    return adminCrudPermissions()['create'];
}

function canUpdateData(): bool {
    return adminCrudPermissions()['update'];
}

function canDeleteData(): bool {
    return adminCrudPermissions()['delete'];
}

function canViewVehicleData(): bool {
    return canAccessAdminCategory('vehicles');
}

function canCreateVehicleData(): bool { return canCreateData(); }
function canUpdateVehicleData(): bool { return canUpdateData(); }
function canDeleteVehicleData(): bool { return canDeleteData(); }

function canEditVehicleData(): bool {
    return canCreateVehicleData() || canUpdateVehicleData() || canDeleteVehicleData();
}

function canManageAdminAccounts(): bool {
    return isSuperAdmin();
}

function canViewAnalytics(): bool {
    return canAccessAdminCategory('traffic');
}

require_once __DIR__ . '/category-permissions.php';

function canAccessAdminCategory(string $category): bool {
    if ($category === 'admins') return isSuperAdmin();
    if ($category === 'customers' && !isSalesAdmin()) return isSuperAdmin();
    if (!array_key_exists($category, adminCategoryLabels())) return false;
    if (!isSalesAdmin()) return true;

    static $categories = null;
    if ($categories === null) {
        $categories = [];
        try {
            global $pdo;
            if (!isset($pdo) || !($pdo instanceof PDO)) {
                require_once __DIR__ . '/../config/database.php';
            }
            $stmt = $pdo->prepare('SELECT * FROM admin_accounts WHERE id = ? AND role = ? AND is_active = 1 LIMIT 1');
            $stmt->execute([(int)$_SESSION['admin_id'], 'SALES']);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($account) $categories = adminCategoriesForAccount($account);
        } catch (Throwable $e) {
            // Deny access when the account permissions cannot be loaded.
        }
    }
    return in_array($category, $categories, true);
}

function requireAdminCategory(string $category): void {
    if (!canAccessAdminCategory($category)) {
        http_response_code(403);
        exit('이 카테고리에 접근할 권한이 없습니다.');
    }
}

function adminLandingPage(): string {
    foreach (array_keys(adminCategoryLabels()) as $category) {
        if (canAccessAdminCategory($category)) return './' . $category . '.php';
    }
    return './account-settings.php';
}

function requireSuperAdmin(): void {
    if (!isSuperAdmin()) {
        http_response_code(403);
        exit('SUPER_ADMIN 권한이 필요합니다.');
    }
}

function requireVehicleEditor(): void {
    if (!canEditVehicleData()) {
        http_response_code(403);
        exit('등록·수정·삭제 권한이 없습니다.');
    }
}

function requireVehicleCreate(): void {
    if (!canCreateVehicleData()) {
        http_response_code(403);
        exit('등록 권한이 없습니다.');
    }
}

function requireVehicleUpdate(): void {
    if (!canUpdateVehicleData()) {
        http_response_code(403);
        exit('수정 권한이 없습니다.');
    }
}

function requireVehicleDelete(): void {
    if (!canDeleteVehicleData()) {
        http_response_code(403);
        exit('삭제 권한이 없습니다.');
    }
}

function requireVehicleImport(): void {
    if (!canCreateVehicleData() || !canUpdateVehicleData()) {
        http_response_code(403);
        exit('엑셀 일괄등록은 등록 권한과 수정 권한이 모두 필요합니다.');
    }
}

function requireVehicleCrudAction(string $action): void {
    $createActions = ['add_vehicle_manual','add_color','add_trim','add_price'];
    $updateActions = ['bulk_update_vehicles','update_vehicle','update_color','update_trim','update_price','bulk_update_colors','bulk_update_trims','bulk_update_prices'];
    $deleteActions = ['bulk_delete_vehicles','delete_vehicle','delete_color','delete_trim','delete_price'];

    if (in_array($action, $createActions, true)) {
        requireVehicleCreate();
        return;
    }
    if (in_array($action, $updateActions, true)) {
        requireVehicleUpdate();
        return;
    }
    if (in_array($action, $deleteActions, true)) {
        requireVehicleDelete();
        return;
    }

    if ($action !== '') {
        requireVehicleEditor();
    }
}
