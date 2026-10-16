<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? '1' : '0');
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

require_once __DIR__ . '/../config/database.php';
$accountQuery = $pdo->prepare('SELECT id, username, name, role, is_active, password_hash FROM admin_accounts WHERE id=? LIMIT 1');
$accountQuery->execute([(int)$_SESSION['admin_id']]);
$activeAccount = $accountQuery->fetch(PDO::FETCH_ASSOC);
$stamp = $activeAccount ? hash('sha256',(string)$activeAccount['password_hash']) : '';
if (!$activeAccount || (int)$activeAccount['is_active'] !== 1 ||
    (isset($_SESSION['admin_auth_stamp']) && !hash_equals($_SESSION['admin_auth_stamp'],$stamp))) {
    unset($_SESSION['admin_id'],$_SESSION['admin_username'],$_SESSION['admin_role'],$_SESSION['admin_name'],$_SESSION['admin_auth_stamp']);
    session_regenerate_id(true);
    header('Location: ./login.php?expired=1'); exit;
}
$_SESSION['admin_role']=(string)$activeAccount['role'];
$_SESSION['admin_name']=(string)($activeAccount['name'] ?: $activeAccount['username']);
$_SESSION['admin_username']=(string)$activeAccount['username'];
$_SESSION['admin_auth_stamp']=$stamp;
$_SESSION['admin_last_activity'] = time();
if (empty($_SESSION['admin_csrf_token'])) $_SESSION['admin_csrf_token']=bin2hex(random_bytes(32));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token=$_POST['admin_csrf_token'] ?? $_SERVER['HTTP_X_ADMIN_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['admin_csrf_token'],$token)) {
        http_response_code(403); exit('요청이 만료되었거나 확인되지 않습니다. 새로고침 후 다시 시도해 주세요.');
    }
}
// Insert the shared token into all server-rendered forms, including forms submitted via form.submit().
if (!defined('AG_ADMIN_SECURITY_BUFFER')) {
    define('AG_ADMIN_SECURITY_BUFFER',true);
    ob_start(static function(string $html): string {
        if (stripos($html,'<html')===false) return $html;
        $token=htmlspecialchars($_SESSION['admin_csrf_token'],ENT_QUOTES,'UTF-8');
        $html=preg_replace_callback('/<form\b[^>]*>/i', static fn(array $m): string => preg_match('/method\s*=\s*[\"\']?post\b/i',$m[0]) ? $m[0].'<input type="hidden" name="admin_csrf_token" value="'.$token.'">' : $m[0],$html);
        return preg_replace('/<head\b[^>]*>/i','$0<meta name="admin-csrf-token" content="'.$token.'"><script src="./admin-security.js?v='.filemtime(__DIR__ . '/admin-security.js').'" defer></script>',$html,1);
    });
}

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
 * SALES 계정의 차량 데이터 작업 권한.
 * can_create / can_update / can_delete 컬럼은 차량 데이터 메뉴의 등록·수정·삭제에만 사용한다.
 * SUPER_ADMIN / ADMIN은 차량 데이터 전체 CRUD 허용. VIEWER는 모두 차단.
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
        global $pdo;
        if (!isset($pdo) || !($pdo instanceof PDO)) {
            require_once __DIR__ . '/../config/database.php';
        }

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

function requireDataPermission(string $operation): void {
    $labels = ['create' => '등록', 'update' => '수정', 'delete' => '삭제'];
    if (!(adminCrudPermissions()[$operation] ?? false)) {
        http_response_code(403);
        exit('차량 ' . ($labels[$operation] ?? '작업') . ' 권한이 없습니다.');
    }
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
    if ($category === 'contracts' && !isSalesAdmin()) return isSuperAdmin();
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
        exit('차량 등록·수정·삭제 권한이 없습니다.');
    }
}

function requireVehicleCreate(): void {
    if (!canCreateVehicleData()) {
        http_response_code(403);
        exit('차량 등록 권한이 없습니다.');
    }
}

function requireVehicleUpdate(): void {
    if (!canUpdateVehicleData()) {
        http_response_code(403);
        exit('차량 수정 권한이 없습니다.');
    }
}

function requireVehicleDelete(): void {
    if (!canDeleteVehicleData()) {
        http_response_code(403);
        exit('차량 삭제 권한이 없습니다.');
    }
}

function requireVehicleImport(): void {
    if (!canCreateVehicleData() || !canUpdateVehicleData()) {
        http_response_code(403);
        exit('엑셀 일괄등록은 등록 권한과 수정 권한이 모두 필요합니다.');
    }
}

function requireVehicleCrudAction(string $action): void {
    $createActions = ['add_vehicle_manual','copy_vehicle','add_color','add_trim','add_price'];
    $updateActions = ['set_price_validity','bulk_update_vehicles','update_vehicle','update_color','update_trim','update_price','bulk_update_colors','bulk_update_trims','bulk_update_prices'];
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

function canDeleteEstimates():bool{return in_array(adminRole(),['SUPER_ADMIN','ADMIN'],true)&&canAccessAdminCategory('estimates');}
function canUpdateInquiries():bool{return in_array(adminRole(),['SUPER_ADMIN','ADMIN','SALES'],true)&&canAccessAdminCategory('inquiries');}
function canDeleteInquiries():bool{return in_array(adminRole(),['SUPER_ADMIN','ADMIN'],true)&&canAccessAdminCategory('inquiries');}
