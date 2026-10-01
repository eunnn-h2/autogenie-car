<?php
declare(strict_types=1);
require __DIR__ . '/../admin/estimate-notes.php';
class NotesDb extends PDO {
    public array $notes = [];
    public function __construct(public ?int $owner) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return new NotesStmt($this, str_contains($query, 'AND e.assigned_admin_id = ?'));
    }
}
class NotesStmt extends PDOStatement {
    private int $count = 0;
    public function __construct(private NotesDb $db, private bool $scoped) {}
    public function execute(?array $params = null): bool {
        if ($params[4] === 1 && (!$this->scoped || $this->db->owner === $params[5])) {
            $this->db->notes[] = $params;
            $this->count = 1;
        }
        return true;
    }
    public function rowCount(): int { return $this->count; }
}
$checks = 0;
function checkNote(bool $condition): void { global $checks; if (!$condition) throw new RuntimeException('Note check failed'); $checks++; }
foreach (['estimate_direct', 'estimate_quick'] as $table) {
    foreach ([null, 2, 3] as $owner) {
        $db = new NotesDb($owner);
        checkNote(addEstimateNote($db, $table, 1, 2, 'Sales', 'First contact', true) === ($owner === 2));
        checkNote(count($db->notes) === ($owner === 2 ? 1 : 0));
        checkNote(addEstimateNote($db, $table, 1, 1, 'Admin', 'Follow up', false));
        if ($owner === 2) checkNote(count($db->notes) === 2);
    }
    $db = new NotesDb(3);
    checkNote(!addEstimateNote($db, $table, 1, 2, 'Sales', 'Reassigned', true));
    foreach (['', '  ', str_repeat('가', 5001)] as $body) {
        $rejected = false;
        try { addEstimateNote($db, $table, 1, 1, 'Admin', $body, false); } catch (InvalidArgumentException $e) { $rejected = true; }
        checkNote($rejected);
    }
}
echo "Passed $checks checks.\n";
