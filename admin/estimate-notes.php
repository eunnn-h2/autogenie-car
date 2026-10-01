<?php
declare(strict_types=1);

function ensureEstimateNotes(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_estimate_notes (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        estimate_source VARCHAR(6) NOT NULL,
        estimate_id BIGINT UNSIGNED NOT NULL,
        author_id BIGINT UNSIGNED NOT NULL,
        author_name VARCHAR(255) NOT NULL,
        body TEXT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX estimate_notes_lookup (estimate_source, estimate_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function addEstimateNote(PDO $pdo, string $table, int $id, int $authorId, string $authorName, string $body, bool $sales): bool {
    if (!in_array($table, ['estimate_direct', 'estimate_quick'], true) || $id < 1 || $authorId < 1) throw new InvalidArgumentException('잘못된 견적 정보입니다.');
    $body = trim($body);
    if ($body === '' || mb_strlen($body, 'UTF-8') > 5000) throw new InvalidArgumentException('상담 메모는 1~5,000자로 입력해 주세요.');
    // Ownership is checked at insertion time, including reassignment during a request.
    $sql = "INSERT INTO admin_estimate_notes (estimate_source, estimate_id, author_id, author_name, body)
        SELECT ?, e.id, ?, ?, ? FROM $table e WHERE e.id = ?";
    $params = [$table === 'estimate_quick' ? 'QUICK' : 'DIRECT', $authorId, $authorName, $body, $id];
    if ($sales) { $sql .= ' AND e.assigned_admin_id = ?'; $params[] = $authorId; }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount() === 1;
}
