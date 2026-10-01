<?php
declare(strict_types=1);
require __DIR__ . '/../admin/estimate-assignment.php';
class AssignmentTestDatabase extends PDO {
    public ?int $owner = null;
    public array $activeSales = [2, 3];
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        foreach (['assigned_admin_id IS NULL', 'OR NOT EXISTS', 'previous_owner.is_active = 1', "role = 'SALES'", 'is_active = 1', 'WHERE id = ?'] as $guard) {
            if (!str_contains($query, $guard)) throw new RuntimeException('Missing assignment guard');
        }
        return new AssignmentTestStatement($this);
    }
}
class AssignmentTestStatement extends PDOStatement {
    private int $changed = 0;
    public function __construct(private AssignmentTestDatabase $db) {}
    public function execute(?array $params = null): bool {
        [$admin, $estimate, $validatedAdmin] = $params;
        if ($admin !== $validatedAdmin) throw new RuntimeException('Mismatched actor');
        if ($estimate === 1 && !in_array($this->db->owner, $this->db->activeSales, true) && in_array($admin, $this->db->activeSales, true)) {
            $this->db->owner = $admin;
            $this->changed = 1;
        }
        return true;
    }
    public function rowCount(): int { return $this->changed; }
}
$checks = 0;
function verifyAssignment(bool $condition): void {
    global $checks;
    if (!$condition) throw new RuntimeException('Assignment test failed');
    $checks++;
}
foreach (['estimate_direct', 'estimate_quick'] as $table) {
    $db = new AssignmentTestDatabase();
    verifyAssignment(!claimEstimate($db, $table, 1, 99));
    verifyAssignment(!claimEstimate($db, $table, 999, 2));
    verifyAssignment(claimEstimate($db, $table, 1, 2));
    verifyAssignment(!claimEstimate($db, $table, 1, 3));
    verifyAssignment(!claimEstimate($db, $table, 1, 2));
    verifyAssignment($db->owner === 2);
    $db->activeSales = [3]; // Previous owner has left / been disabled.
    verifyAssignment(!claimEstimate($db, $table, 1, 2));
    verifyAssignment(claimEstimate($db, $table, 1, 3));
    verifyAssignment($db->owner === 3);
    $db->activeSales = [2, 3];
    verifyAssignment(!claimEstimate($db, $table, 1, 2));
}
foreach ([['admin_accounts', 1, 2], ['estimate_direct', 0, 2], ['estimate_direct', 1, 0]] as $args) {
    $rejected = false;
    try { claimEstimate(new AssignmentTestDatabase(), ...$args); } catch (InvalidArgumentException $e) { $rejected = true; }
    verifyAssignment($rejected);
}
class StatusTestDatabase extends PDO {
    public string $status = 'NEW';
    public function __construct(public ?int $owner) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new StatusTestStatement($this, str_contains($query, 'AND assigned_admin_id = ?'));
    }
}
class StatusTestStatement extends PDOStatement {
    private int $changed = 0;
    public function __construct(private StatusTestDatabase $db, private bool $scoped) {}
    public function execute(?array $params = null): bool {
        if ($params[1] === 1 && (!$this->scoped || $this->db->owner === $params[2]) && $this->db->status !== $params[0]) {
            $this->db->status = $params[0];
            $this->changed = 1;
        }
        return true;
    }
    public function rowCount(): int { return $this->changed; }
}
foreach (['estimate_direct', 'estimate_quick'] as $table) {
    foreach ([null, 2, 3] as $owner) {
        $db = new StatusTestDatabase($owner);
        verifyAssignment(updateEstimateStatus($db, $table, 1, 'CONTACTED', 2) === ($owner === 2));
        verifyAssignment($db->status === ($owner === 2 ? 'CONTACTED' : 'NEW'));
        // Admins can update any assignee, including unassigned estimates.
        verifyAssignment(updateEstimateStatus($db, $table, 1, 'CONTRACTED', null));
    }
    $db = new StatusTestDatabase(2);
    $db->owner = 3; // Reassigned after the detail page was loaded.
    verifyAssignment(!updateEstimateStatus($db, $table, 1, 'CONTACTED', 2));
    verifyAssignment($db->status === 'NEW');
}
echo "Passed $checks checks.\n";
