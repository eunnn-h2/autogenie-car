<?php
declare(strict_types=1);

// Run: php tests/admin-category-permissions.php (no database required).
if (($argv[1] ?? '') === '--case') {
    $case = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    class PermissionStatement extends PDOStatement {
        public function __construct(private array|false $account) {}
        public function execute(?array $params = null): bool { return true; }
        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
            return $this->account;
        }
    }
    class PermissionDatabase extends PDO {
        public function __construct(private array|false $account) {}
        public function prepare(string $query, array $options = []): PDOStatement|false {
            return new PermissionStatement($this->account);
        }
    }
    session_start(['use_cookies' => false, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
    $_SESSION = ['admin_id' => 1, 'admin_username' => 'test', 'admin_role' => $case['role']];
    $pdo = new PermissionDatabase($case['account'] ?? false);
    require __DIR__ . '/../admin/auth.php';
    session_destroy();
    if (isset($case['page'])) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        register_shutdown_function(static function (): void {
            echo '\nSTATUS:' . http_response_code();
        });
        require __DIR__ . '/../admin/' . $case['page'];
        exit;
    }
    $access = [];
    foreach (array_merge(array_keys(adminCategoryLabels()), ['admins', 'unknown']) as $category) {
        $access[$category] = canAccessAdminCategory($category);
    }
    ob_start();
    require __DIR__ . '/../admin/sidebar.php';
    $sidebar = ob_get_clean();
    echo json_encode(['access' => $access, 'landing' => adminLandingPage(), 'sidebar' => $sidebar]);
    exit;
}

require __DIR__ . '/../admin/category-permissions.php';
$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    $checks++;
    if (!$condition) throw new RuntimeException($message);
}
function runCase(array $case): string {
    $process = proc_open([PHP_BINARY, __FILE__, '--case', base64_encode(json_encode($case))],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);
    check($status === 0 && $err === '', 'PHP execution failed: ' . $err);
    return $out;
}

check(normalizeAdminCategories(['admins', 'vehicles', 'vehicles', [], 'bogus']) === ['vehicles'], 'Invalid categories must not grant access');
check(normalizeAdminCategories(['customers']) === ['customers'], 'Customers can be explicitly granted');
check(normalizeAdminCategories('vehicles') === [], 'Malformed input must be denied');
$legacy = ['role' => 'SALES', 'can_create' => 0, 'can_update' => 0, 'can_delete' => 0];
check(adminCategoriesForAccount($legacy) === ['dashboard', 'estimates', 'inquiries'], 'Preserve legacy consultation access');
check(in_array('vehicles', adminCategoriesForAccount(array_replace($legacy, ['can_update' => 1])), true), 'Preserve legacy vehicle access');
check(adminCategoriesForAccount(array_replace($legacy, ['category_permissions' => 'invalid'])) === [], 'Corrupt permissions must deny access');

foreach (['SUPER_ADMIN', 'ADMIN', 'VIEWER'] as $role) {
    $result = json_decode(runCase(['role' => $role]), true);
    foreach (array_keys(adminCategoryLabels()) as $key) {
        if ($key !== 'customers') check($result['access'][$key], "$role retains $key");
    }
    check($result['access']['customers'] === ($role === 'SUPER_ADMIN'), 'Customers remain super admin only');
    check($result['access']['admins'] === ($role === 'SUPER_ADMIN'), 'Accounts remain super admin only');
    check(!$result['access']['unknown'], 'Unknown category denied');
}

// Each category works independently, even with no CRUD rights; no selection grants none.
foreach (array_merge([[]], array_map(static fn($key) => [$key], array_keys(adminCategoryLabels()))) as $selected) {
    $account = array_replace($legacy, ['category_permissions' => json_encode($selected)]);
    $result = json_decode(runCase(['role' => 'SALES', 'account' => $account]), true);
    foreach (array_keys(adminCategoryLabels()) as $key) {
        $allowed = in_array($key, $selected, true);
        check($result['access'][$key] === $allowed, 'Independent access: ' . $key);
        check(str_contains($result['sidebar'], './' . $key . '.php') === $allowed, 'Sidebar access: ' . $key);
    }
    check(!$result['access']['admins'], 'Sales cannot access admin accounts');
    check($result['landing'] === ($selected ? './' . $selected[0] . '.php' : './account-settings.php'), 'Landing must be accessible');
}

$allCrudNoCategories = array_replace($legacy, ['can_create' => 1, 'can_update' => 1, 'can_delete' => 1, 'category_permissions' => '[]']);
$result = json_decode(runCase(['role' => 'SALES', 'account' => $allCrudNoCategories]), true);
check(!in_array(true, $result['access'], true), 'CRUD must not override category denial');
$result = json_decode(runCase(['role' => 'SALES', 'account' => false]), true);
check(!in_array(true, $result['access'], true), 'Missing or inactive account denied');

// Execute real entry points: denials must happen before DB queries, rendering, or actions.
foreach (['dashboard.php', 'vehicles.php', 'index.php', 'download-vehicle-template.php',
    'estimates.php', 'estimate-detail.php', 'estimate-actions.php', 'export-estimates.php',
    'inquiries.php', 'inquiry-actions.php', 'traffic.php', 'customers.php', 'admins.php'] as $page) {
    $output = runCase(['role' => 'SALES', 'account' => $allCrudNoCategories, 'page' => $page]);
    check(str_contains($output, 'STATUS:403'), 'Direct endpoint must deny: ' . $page);
}
echo "Passed $checks checks.\n";
