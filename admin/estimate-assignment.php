<?php
declare(strict_types=1);

require_once __DIR__.'/../config/consultation.php';

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
    if (!in_array($table,['estimate_direct','estimate_quick'],true) || $estimateId<1 || $adminId<1) throw new InvalidArgumentException('잘못된 견적 정보입니다.');
    $ownTransaction=!$pdo->inTransaction(); if($ownTransaction)$pdo->beginTransaction();
    try {
        $q=$pdo->prepare("SELECT * FROM $table WHERE id=? FOR UPDATE");$q->execute([$estimateId]);$before=$q->fetch(PDO::FETCH_ASSOC);
        $q=$pdo->prepare("UPDATE $table SET assigned_admin_id=? WHERE id=? AND (assigned_admin_id IS NULL OR NOT EXISTS (SELECT 1 FROM admin_accounts p WHERE p.id=$table.assigned_admin_id AND p.is_active=1 AND p.role='SALES')) AND EXISTS (SELECT 1 FROM admin_accounts WHERE id=? AND role='SALES' AND is_active=1)");
        $q->execute([$adminId,$estimateId,$adminId]);$changed=$q->rowCount()===1;
        if($changed && $before)agEstimateHistory($pdo,$table,$estimateId,$before,['assigned_admin_id'=>$adminId]);
        if($changed){$pdo->prepare("UPDATE $table SET consultation_revision=consultation_revision+1 WHERE id=?")->execute([$estimateId]);agAlimtalkQueue($pdo,$table,$estimateId,'assigned');}
        if($ownTransaction){$pdo->commit();if($changed)agAlimtalkDispatchEstimate($pdo,$table,$estimateId);}return $changed;
    } catch(Throwable $e){if($ownTransaction && $pdo->inTransaction())$pdo->rollBack();throw $e;}
}
function assignEstimate(PDO $pdo, string $table, int $estimateId, ?int $adminId, bool $log=true): bool {
    if(!in_array($table,['estimate_direct','estimate_quick'],true)||$estimateId<1||($adminId!==null&&$adminId<1))throw new InvalidArgumentException('잘못된 견적 정보입니다.');
    $ownTransaction=!$pdo->inTransaction();if($ownTransaction)$pdo->beginTransaction();
    try{
        $q=$pdo->prepare("SELECT * FROM $table WHERE id=? FOR UPDATE");$q->execute([$estimateId]);$before=$q->fetch(PDO::FETCH_ASSOC);
        if($adminId===null){$q=$pdo->prepare("UPDATE $table SET assigned_admin_id=NULL WHERE id=?");$q->execute([$estimateId]);}
        else{$q=$pdo->prepare("UPDATE $table SET assigned_admin_id=? WHERE id=? AND EXISTS (SELECT 1 FROM admin_accounts WHERE id=? AND role='SALES' AND is_active=1)");$q->execute([$adminId,$estimateId,$adminId]);}
        $changed=$q->rowCount()===1;if($changed&&$before&&$log)agEstimateHistory($pdo,$table,$estimateId,$before,['assigned_admin_id'=>$adminId]);
        if($changed){$pdo->prepare("UPDATE $table SET consultation_revision=consultation_revision+1 WHERE id=?")->execute([$estimateId]);if($adminId!==null)agAlimtalkQueue($pdo,$table,$estimateId,'assigned');}
        if($ownTransaction){$pdo->commit();if($changed)agAlimtalkDispatchEstimate($pdo,$table,$estimateId);}return $changed;
    }catch(Throwable $e){if($ownTransaction&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
}
