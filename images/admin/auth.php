<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

$timeoutSeconds = 8 * 60 * 60; // 8시간

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

function canEditVehicleData(): bool {
    return in_array(adminRole(), ['SUPER_ADMIN', 'ADMIN'], true);
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
        exit('수정 권한이 없습니다.');
    }
}
