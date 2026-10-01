<?php
declare(strict_types=1);

// Check ownership in the UPDATE itself so reassignment during a request cannot bypass it.
function updateEstimateStatus(PDO $pdo, string $table, int $estimateId, string $status, ?int $salesAdminId): bool {
    if (!in_array($table, ['estimate_direct', 'estimate_quick'], true) || $estimateId < 1
        || ($salesAdminId !== null && $salesAdminId < 1)
        || !in_array($status, ['NEW', 'CONTACTED', 'REVIEWING', 'APPROVED', 'CONTRACTED', 'CANCELED'], true)) {
        throw new InvalidArgumentException('잘못된 견적 정보입니다.');
    }
    $sql = "UPDATE $table SET status=? WHERE id=?";
    $params = [$status, $estimateId];
    if ($salesAdminId !== null) {
        $sql .= ' AND assigned_admin_id = ?';
        $params[] = $salesAdminId;
    }
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount() === 1;
}

function claimEstimate(PDO $pdo, string $table, int $estimateId, int $adminId): bool {
    if (!in_array($table, ['estimate_direct', 'estimate_quick'], true) || $estimateId < 1 || $adminId < 1) {
        throw new InvalidArgumentException('잘못된 견적 정보입니다.');
    }
    // The conditional update prevents two people from claiming the same estimate.
    $stmt = $pdo->prepare("UPDATE $table SET assigned_admin_id = ? WHERE id = ? AND (assigned_admin_id IS NULL OR NOT EXISTS (SELECT 1 FROM admin_accounts previous_owner WHERE previous_owner.id = $table.assigned_admin_id AND previous_owner.is_active = 1)) AND EXISTS (SELECT 1 FROM admin_accounts WHERE id = ? AND role = 'SALES' AND is_active = 1)");
    $stmt->execute([$adminId, $estimateId, $adminId]);
    return $stmt->rowCount() === 1;
}


/**
 * 관리자 화면에서 특정 영업사원을 견적 담당자로 배정/변경합니다.
 * $adminId가 null이면 담당자 배정을 해제합니다.
 */
function assignEstimate(PDO $pdo, string $table, int $estimateId, ?int $adminId): bool {
    if (!in_array($table, ['estimate_direct', 'estimate_quick'], true) || $estimateId < 1) {
        throw new InvalidArgumentException('잘못된 견적 정보입니다.');
    }

    if ($adminId === null) {
        $stmt = $pdo->prepare("UPDATE $table SET assigned_admin_id = NULL WHERE id = ?");
        $stmt->execute([$estimateId]);
        return $stmt->rowCount() === 1;
    }

    if ($adminId < 1) {
        throw new InvalidArgumentException('잘못된 담당자 정보입니다.');
    }

    $stmt = $pdo->prepare("UPDATE $table SET assigned_admin_id = ? WHERE id = ? AND EXISTS (SELECT 1 FROM admin_accounts WHERE id = ? AND role = 'SALES' AND is_active = 1)");
    $stmt->execute([$adminId, $estimateId, $adminId]);
    return $stmt->rowCount() === 1;
}
