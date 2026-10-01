<?php
declare(strict_types=1);
require __DIR__ . '/../admin/contract-performance.php';
class ContractTestDatabase extends PDO {
    public array $queries = [];
    public array $accounts = [
        ['id' => 1, 'name' => 'Main', 'username' => 'main', 'role' => 'SUPER_ADMIN', 'is_active' => 1],
        ['id' => 2, 'name' => 'Sales A', 'username' => 'sales_a', 'role' => 'SALES', 'is_active' => 1],
        ['id' => 3, 'name' => 'Sales B', 'username' => 'sales_b', 'role' => 'SALES', 'is_active' => 0],
        ['id' => 4, 'name' => 'Sales C', 'username' => 'sales_c', 'role' => 'SALES', 'is_active' => 1],
    ];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new ContractTestStatement($this, $query);
    }
}
class ContractTestStatement extends PDOStatement {
    private array $params = [];
    public function __construct(private ContractTestDatabase $db, private string $sql) {}
    public function execute(?array $params = null): bool {
        $this->params = $params ?? [];
        $this->db->queries[] = [$this->sql, $this->params];
        return true;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        if (str_contains($this->sql, 'FROM admin_accounts')) {
            return array_values(array_filter($this->db->accounts, fn($a) => $a['role'] === 'SALES' && (!$this->params || $a['id'] === $this->params[0])));
        }
        if (!str_contains($this->sql, "status = 'CONTRACTED'")) throw new RuntimeException('Missing status restriction');
        $counts = str_contains($this->sql, 'estimate_direct') ? [0 => 8, 1 => 9, 2 => 3, 3 => 1] : [2 => 2, 3 => 4];
        $rows = [];
        foreach ($counts as $id => $count) {
            if ($this->params && $id !== $this->params[0]) continue;
            $rows[] = ['assigned_admin_id' => $id, 'contract_count' => $count];
        }
        return $rows;
    }
}
$checks = 0;
function verifyContract(bool $condition): void {
    global $checks;
    if (!$condition) throw new RuntimeException('Contract performance test failed');
    $checks++;
}
$db = new ContractTestDatabase();
$all = contractPerformance($db, true, 1);
verifyContract(count($all) === 3);
verifyContract(array_column($all, 'total') === [5, 5, 0]);
verifyContract($all[0]['direct'] === 3 && $all[0]['quick'] === 2);
verifyContract(array_sum(array_column($all, 'total')) === 10);
$db = new ContractTestDatabase();
$own = contractPerformance($db, false, 2);
verifyContract(count($own) === 1 && $own[0]['id'] === 2 && $own[0]['total'] === 5);
foreach ($db->queries as [$query, $params]) {
    verifyContract($params === [2]);
    verifyContract(str_contains($query, str_contains($query, 'FROM admin_accounts') ? 'AND id = ?' : 'AND assigned_admin_id = ?'));
}
verifyContract(contractPerformance(new ContractTestDatabase(), false, 999) === []);
$denied = false;
try { contractPerformance(new ContractTestDatabase(), false, 0); } catch (RuntimeException $e) { $denied = true; }
verifyContract($denied);
echo "Passed $checks checks.\n";
