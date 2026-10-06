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

function estimateContactTagDefaultColors(): array
{
    return ['CONSULTING'=>'#e9eef2', 'NO_ANSWER'=>'#e7ebff', 'MANAGED'=>'#f4e3f8', 'SPECIAL'=>'#e3e5e8', 'SIMPLE'=>'#ffe7e9'];
}

function ensureEstimateContactTagColors(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_estimate_contact_tag_colors (
        contact_tag VARCHAR(24) NOT NULL PRIMARY KEY,
        background_color CHAR(7) NOT NULL,
        updated_by INT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function getEstimateContactTagColors(PDO $pdo): array
{
    $colors = estimateContactTagDefaultColors();
    try {
        foreach ($pdo->query('SELECT contact_tag, background_color FROM admin_estimate_contact_tag_colors')->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($colors[$row['contact_tag']]) && preg_match('/^#[0-9a-fA-F]{6}$/D', $row['background_color'])) {
                $colors[$row['contact_tag']] = strtolower($row['background_color']);
            }
        }
    } catch (Throwable $e) {
        error_log('Estimate contact tag colors unavailable: ' . $e->getMessage());
    }
    return $colors;
}

function saveEstimateContactTagColors(PDO $pdo, array $input, int $adminId): void
{
    $colors = [];
    foreach (estimateContactTagDefaultColors() as $tag => $default) {
        $color = $input[$tag] ?? null;
        if (!is_string($color) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $color)) {
            throw new InvalidArgumentException('모든 상담 구분에 올바른 색상을 선택해 주세요.');
        }
        $colors[$tag] = strtolower($color);
    }
    ensureEstimateContactTagColors($pdo);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('INSERT INTO admin_estimate_contact_tag_colors (contact_tag, background_color, updated_by) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE background_color = VALUES(background_color), updated_by = VALUES(updated_by), updated_at = CURRENT_TIMESTAMP');
        foreach ($colors as $tag => $color) $stmt->execute([$tag, $color, $adminId]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function estimateContactTagColorCss(array $colors): string
{
    $css = '';
    foreach (estimateContactTagDefaultColors() as $tag => $default) {
        $bg = $colors[$tag] ?? $default;
        if (!is_string($bg) || !preg_match('/^#[0-9a-fA-F]{6}$/D', $bg)) $bg = $default;
        $rgb = sscanf($bg, '#%02x%02x%02x');
        $text = (0.299 * $rgb[0] + 0.587 * $rgb[1] + 0.114 * $rgb[2]) > 150 ? '#243746' : '#ffffff';
        $key = strtolower($tag);
        $css .= ".table.estimates-table tbody tr.estimate-row--{$key}{background:{$bg};color:{$text}}";
        $css .= ".table.estimates-table tbody tr.estimate-row--{$key}>td{background:{$bg}!important;color:{$text}}";
        $css .= ".table.estimates-table tbody tr.estimate-row--{$key} .estimate-contact-tag{color:{$text}}";
        $css .= ".contact-tag-option--{$key} input:checked+span{background:{$bg};color:{$text};border-color:{$text}}";
    }
    return $css;
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
