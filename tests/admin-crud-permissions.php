<?php
declare(strict_types=1);

// Run: php tests/admin-crud-permissions.php (no database required).
if (($argv[1] ?? '') === '--case') {
    $case = json_decode(base64_decode($argv[2]), true, 512, JSON_THROW_ON_ERROR);
    class CrudStatement extends PDOStatement {
        public function __construct(private array $account) {}
        public function execute(?array $params = null): bool { return true; }
        public function fetchColumn(int $column = 0): mixed { return 3; }
        public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed { return $this->account; }
    }
    class CrudDatabase extends PDO {
        public function __construct(private array $account) {}
        public function prepare(string $query, array $options = []): PDOStatement|false {
            if (!str_contains($query, 'admin_accounts') && !str_contains($query, 'INFORMATION_SCHEMA')) {
                throw new RuntimeException('Unexpected data access before permission denial');
            }
            return new CrudStatement($this->account);
        }
    }
    session_start(['use_cookies' => false, 'cache_limiter' => '', 'save_path' => sys_get_temp_dir()]);
    $_SESSION = ['admin_id' => 1, 'admin_username' => 'test', 'admin_role' => $case['role'] ?? 'SALES'];
    $pdo = new CrudDatabase(array_merge($case['permissions'], ['role' => 'SALES', 'category_permissions' => '["vehicles","estimates","inquiries"]']));
    require __DIR__ . '/../admin/auth.php';
    session_destroy();
    register_shutdown_function(static function (): void { echo '\nSTATUS:' . http_response_code(); });
    if (isset($case['page'])) {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $case['post'];
        require __DIR__ . '/../admin/' . $case['page'];
    } else {
        requireDataPermission($case['operation']);
        echo 'ALLOWED';
    }
    exit;
}

$checks = 0;
function verifyCase(array $case, bool $allowed): void {
    global $checks;
    $process = proc_open([PHP_BINARY, __FILE__, '--case', base64_encode(json_encode($case))], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $exit = proc_close($process);
    if ($exit !== 0 || $err !== '' || !str_contains($out, $allowed ? 'ALLOWED' : 'STATUS:403')) {
        throw new RuntimeException(json_encode($case) . ': ' . $out . $err);
    }
    $checks++;
}

foreach (range(0, 7) as $bits) {
    $permissions = ['can_create' => $bits & 1 ? 1 : 0, 'can_update' => $bits & 2 ? 1 : 0, 'can_delete' => $bits & 4 ? 1 : 0];
    foreach (['create', 'update', 'delete'] as $operation) {
        verifyCase(compact('permissions', 'operation'), (bool)$permissions['can_' . $operation]);
    }
    $routes = [
        ['vehicle-create.php', [], 'create'],
        ['estimate-actions.php', ['action' => 'single_delete'], 'delete'],
        ['estimate-actions.php', ['action' => 'bulk_delete'], 'delete'],
        ['estimate-actions.php', ['action' => 'claim'], 'update'],
        ['estimate-detail.php', ['action' => 'delete'], 'delete'],
        ['estimate-detail.php', ['action' => 'status'], 'update'],
        ['estimate-detail.php', ['action' => 'save_note'], 'update'],
        ['estimate-detail.php', ['action' => 'assign'], 'update'],
        ['inquiry-actions.php', ['action' => 'bulk', 'bulk_action' => 'delete'], 'delete'],
        ['inquiry-actions.php', ['action' => 'bulk', 'bulk_action' => 'mark_new'], 'update'],
        ['inquiry-actions.php', ['action' => 'bulk', 'bulk_action' => 'mark_answered'], 'update'],
        ['inquiry-actions.php', ['action' => 'save_answer'], 'update'],
        ['inquiry-actions.php', [], 'update'],
    ];
    foreach ($routes as [$page, $post, $operation]) {
        if (!$permissions['can_' . $operation]) verifyCase(compact('permissions', 'page', 'post'), false);
    }
}
foreach (['ADMIN', 'SUPER_ADMIN', 'VIEWER'] as $role) {
    foreach (['create', 'update', 'delete'] as $operation) {
        verifyCase(['role' => $role, 'permissions' => [], 'operation' => $operation], $role !== 'VIEWER');
    }
}
echo "Passed $checks checks.\n";
