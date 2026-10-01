<?php
declare(strict_types=1);
require __DIR__ . '/../admin/account-permission-settings.php';
$checks = 0;
function verifyPermissionChange(bool $condition): void {
    global $checks;
    if (!$condition) throw new RuntimeException('Permission change check failed');
    $checks++;
}
$row = ['id' => 2, 'can_create' => 1, 'can_update' => 0, 'can_delete' => 0, 'categories' => ['estimates']];
$parsed = parseAccountPermissionChanges(json_encode([$row]));
verifyPermissionChange($parsed[2] === $row);
$none = array_replace($row, ['categories' => [], 'can_create' => 0]);
verifyPermissionChange(parseAccountPermissionChanges(json_encode([$none]))[2] === $none);
foreach ([[], [$row, $row], [array_replace($row, ['id' => -1])], [array_replace($row, ['can_delete' => 2])], [array_replace($row, ['categories' => ['admins']])], [array_replace($row, ['categories' => 'all'])], [array_replace($row, ['can_create' => '1'])]] as $invalid) {
    $rejected = false;
    try { parseAccountPermissionChanges(json_encode($invalid)); } catch (Throwable $e) { $rejected = true; }
    verifyPermissionChange($rejected);
}
class AccountPermissionTestStatement extends PDOStatement {
    public array $params = [];
    public function __construct(private AccountPermissionTestDatabase $db, private bool $isUpdate) {}
    public function execute(?array $params = null): bool {
        $this->params = $params ?? [];
        if ($this->isUpdate) $this->db->updates[] = $this->params;
        return true;
    }
    public function fetchColumn(int $column = 0): mixed { return $this->db->roles[$this->params[0]] ?? false; }
    public function rowCount(): int { return 1; }
}
class AccountPermissionTestDatabase extends PDO {
    public array $updates = [];
    public bool $transaction = false;
    public bool $committed = false;
    public bool $rolledBack = false;
    public function __construct(public array $roles) {}
    public function beginTransaction(): bool { $this->transaction = true; return true; }
    public function inTransaction(): bool { return $this->transaction; }
    public function commit(): bool { $this->committed = true; $this->transaction = false; return true; }
    public function rollBack(): bool { $this->rolledBack = true; $this->updates = []; $this->transaction = false; return true; }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new AccountPermissionTestStatement($this, str_starts_with($query, 'UPDATE'));
    }
}
$db = new AccountPermissionTestDatabase([2 => 'SALES']);
verifyPermissionChange(saveAccountPermissionChanges($db, $parsed, 1) === 1);
verifyPermissionChange($db->committed && $db->updates[0] === [1, 0, 0, '["estimates"]', 2]);
foreach ([['SUPER_ADMIN', 1], ['SALES', 3], [null, 1]] as [$role, $current]) {
    $db = new AccountPermissionTestDatabase([2 => 'SALES', 3 => $role]);
    $rejected = false;
    try {
        saveAccountPermissionChanges($db, $parsed + [3 => array_replace($row, ['id' => 3])], $current);
    } catch (RuntimeException $e) { $rejected = true; }
    verifyPermissionChange($rejected && $db->rolledBack && !$db->updates);
}
echo "Passed $checks checks.\n";
