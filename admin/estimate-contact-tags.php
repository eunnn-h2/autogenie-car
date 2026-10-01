<?php
declare(strict_types=1);

function estimateContactTagOptions(): array
{
    return [
        'CONSULTING' => '상담중',
        'NO_ANSWER' => '부재중',
        'MANAGED' => '관리고객',
        'SPECIAL' => '특별관리',
        'SIMPLE' => '단순문의',
    ];
}

function ensureEstimateContactTags(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_estimate_contact_tags (
        estimate_source VARCHAR(10) NOT NULL,
        estimate_id INT NOT NULL,
        contact_tag VARCHAR(24) NOT NULL,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (estimate_source, estimate_id),
        KEY idx_contact_tag (contact_tag),
        KEY idx_updated_by (updated_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function getEstimateContactTag(PDO $pdo, string $source, int $estimateId): string
{
    $stmt = $pdo->prepare('SELECT contact_tag FROM admin_estimate_contact_tags WHERE estimate_source = ? AND estimate_id = ? LIMIT 1');
    $stmt->execute([strtoupper($source), $estimateId]);
    return (string)($stmt->fetchColumn() ?: '');
}

function saveEstimateContactTag(PDO $pdo, string $source, int $estimateId, string $tag, ?int $adminId = null): void
{
    $source = strtoupper(trim($source));
    $tag = strtoupper(trim($tag));
    $allowed = estimateContactTagOptions();

    if ($tag === '') {
        $stmt = $pdo->prepare('DELETE FROM admin_estimate_contact_tags WHERE estimate_source = ? AND estimate_id = ?');
        $stmt->execute([$source, $estimateId]);
        return;
    }

    if (!isset($allowed[$tag])) {
        throw new InvalidArgumentException('올바른 상담 구분을 선택해 주세요.');
    }

    $stmt = $pdo->prepare("INSERT INTO admin_estimate_contact_tags (estimate_source, estimate_id, contact_tag, updated_by)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE contact_tag = VALUES(contact_tag), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP");
    $stmt->execute([$source, $estimateId, $tag, $adminId]);
}
