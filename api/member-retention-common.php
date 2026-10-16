<?php
declare(strict_types=1);

function member_retention_tables(): array
{
    return [
        'estimate_direct' => ['kind' => 'estimate', 'source' => 'direct'],
        'estimate_quick' => ['kind' => 'estimate', 'source' => 'quick'],
        'customer_inquiries' => ['kind' => 'inquiry', 'source' => ''],
    ];
}

function member_retention_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

function member_retention_init(PDO $pdo): void
{
    if (member_retention_table_exists($pdo, 'member_accounts')) {
        $column = $pdo->prepare("SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'member_accounts' AND column_name = 'withdrawn_at' LIMIT 1");
        $column->execute();
        if (!$column->fetchColumn()) $pdo->exec('ALTER TABLE member_accounts ADD COLUMN withdrawn_at DATETIME NULL AFTER status');
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_member_data_retention (
        record_type VARCHAR(32) NOT NULL,
        record_id BIGINT UNSIGNED NOT NULL,
        member_id BIGINT UNSIGNED NOT NULL,
        basis ENUM('CONTRACT','PAYMENT','DISPUTE') NOT NULL,
        retention_started_at DATE NOT NULL,
        retention_until DATE NOT NULL,
        marked_by BIGINT UNSIGNED NOT NULL,
        marked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (record_type, record_id),
        KEY idx_member_retention_member (member_id, retention_until)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function member_retention_load(PDO $pdo): array
{
    $records = [];
    foreach ($pdo->query('SELECT record_type, record_id, member_id, basis, retention_started_at, retention_until FROM admin_member_data_retention') as $row) {
        $records[$row['record_type'] . ':' . (int)$row['record_id']] = $row;
    }
    return $records;
}

function member_retention_apply(PDO $pdo, bool $isMainAdmin, string $sessionToken, array $post, array $customers, int $adminId): void
{
    if (!$isMainAdmin) throw new RuntimeException('메인관리자만 법정 보존 정보를 관리할 수 있습니다.', 403);
    if (!is_string($post['csrf_token'] ?? null) || !hash_equals($sessionToken, $post['csrf_token'])) {
        throw new RuntimeException('요청이 만료되었습니다. 새로고침 후 다시 시도해 주세요.', 403);
    }

    $customerHash = $post['customer_hash'] ?? '';
    $recordType = $post['record_type'] ?? '';
    $recordId = filter_var($post['record_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $tables = member_retention_tables();
    if (!is_string($customerHash) || !preg_match('/^[a-f0-9]{64}$/D', $customerHash)
        || !isset($customers[$customerHash]) || !is_string($recordType) || !isset($tables[$recordType])
        || !$recordId) {
        throw new RuntimeException('보존할 기록을 확인할 수 없습니다.', 400);
    }

    $activity = null;
    foreach ($customers[$customerHash]['activities'] ?? [] as $candidate) {
        $expectedType = ($candidate['kind'] ?? '') === 'inquiry'
            ? 'customer_inquiries'
            : (($candidate['source'] ?? '') === 'quick' ? 'estimate_quick' : 'estimate_direct');
        if ((int)($candidate['id'] ?? 0) === $recordId && $expectedType === $recordType) {
            $activity = $candidate;
            break;
        }
    }
    $memberId = (int)($activity['member_id'] ?? $activity['retention_member_id'] ?? 0);
    if ($memberId <= 0) throw new RuntimeException('회원 계정과 연결된 기록만 보존 지정할 수 있습니다.', 400);

    $action = $post['retention_action'] ?? '';
    if ($action === 'clear') {
        $stmt = $pdo->prepare('DELETE FROM admin_member_data_retention WHERE record_type = ? AND record_id = ? AND member_id = ?');
        $stmt->execute([$recordType, $recordId, $memberId]);
        return;
    }
    if ($action !== 'save') throw new RuntimeException('올바르지 않은 요청입니다.', 400);

    $basis = $post['basis'] ?? '';
    $years = ['CONTRACT' => 5, 'PAYMENT' => 5, 'DISPUTE' => 3];
    if (!is_string($basis) || !isset($years[$basis])) {
        throw new RuntimeException('보존 사유를 선택해 주세요.', 400);
    }
    $startValue = $post['retention_started_at'] ?? '';
    if (!is_string($startValue) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $startValue)) {
        throw new RuntimeException('보존 기준일을 확인해 주세요.', 400);
    }
    $startDate = DateTimeImmutable::createFromFormat('!Y-m-d', $startValue);
    $dateErrors = DateTimeImmutable::getLastErrors();
    if (!$startDate || ($dateErrors && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
        || $startDate->format('Y-m-d') !== $startValue || $startDate > new DateTimeImmutable('today')) {
        throw new RuntimeException('보존 기준일이 올바르지 않습니다.', 400);
    }
    $retentionUntil = $startDate->modify('+' . $years[$basis] . ' years')->format('Y-m-d');
    if ($retentionUntil <= date('Y-m-d')) {
        throw new RuntimeException('보존기간이 이미 끝난 기록입니다.', 400);
    }

    $member = $pdo->prepare('SELECT phone, email, status, withdrawn_at FROM member_accounts WHERE id = ? LIMIT 1');
    $member->execute([$memberId]);
    $memberRow = $member->fetch(PDO::FETCH_ASSOC);
    if (!$memberRow || $memberRow['status'] !== 'ACTIVE' || $memberRow['withdrawn_at'] !== null) {
        throw new RuntimeException('탈퇴한 회원의 기록에는 보존을 새로 지정할 수 없습니다.', 409);
    }

    $table = $recordType;
    $recordFields = $recordType === 'customer_inquiries'
        ? 'member_id, member_phone, member_email'
        : 'member_id, customer_phone AS member_phone, NULL AS member_email';
    $check = $pdo->prepare("SELECT {$recordFields} FROM {$table} WHERE id = ? LIMIT 1");
    $check->execute([$recordId]);
    $record = $check->fetch(PDO::FETCH_ASSOC);
    if (!$record || ((int)($record['member_id'] ?? 0) > 0 && (int)$record['member_id'] !== $memberId)) {
        throw new RuntimeException('회원 기록이 변경되었습니다. 새로고침 후 다시 시도해 주세요.', 409);
    }
    if ((int)($record['member_id'] ?? 0) === 0) {
        $recordPhone = preg_replace('/\D+/', '', (string)($record['member_phone'] ?? '')) ?? '';
        $memberPhone = preg_replace('/\D+/', '', (string)$memberRow['phone']) ?? '';
        $recordEmail = strtolower(trim((string)($record['member_email'] ?? '')));
        $memberEmail = strtolower(trim((string)$memberRow['email']));
        $phoneMatches = $memberPhone !== '' && hash_equals($memberPhone, $recordPhone);
        $emailMatches = $memberEmail !== '' && !str_ends_with($memberEmail, '@accounts.invalid')
            && hash_equals($memberEmail, $recordEmail);
        if (!$phoneMatches && !$emailMatches) {
            throw new RuntimeException('회원 연락처와 일치하지 않는 기록입니다.', 409);
        }
        $link = $pdo->prepare("UPDATE {$table} SET member_id = ? WHERE id = ? AND (member_id IS NULL OR member_id = 0)");
        $link->execute([$memberId, $recordId]);
        if ($link->rowCount() !== 1) throw new RuntimeException('회원 기록 연결에 실패했습니다. 새로고침 후 다시 시도해 주세요.', 409);
    }

    $stmt = $pdo->prepare("INSERT INTO admin_member_data_retention
        (record_type, record_id, member_id, basis, retention_started_at, retention_until, marked_by)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE member_id = VALUES(member_id), basis = VALUES(basis),
            retention_started_at = VALUES(retention_started_at), retention_until = VALUES(retention_until),
            marked_by = VALUES(marked_by), marked_at = CURRENT_TIMESTAMP");
    $stmt->execute([$recordType, $recordId, $memberId, $basis, $startValue, $retentionUntil, $adminId]);
}

function member_retention_delete_estimate_metadata(PDO $pdo, string $table, string $source, int $recordId): void
{
    foreach (['admin_estimate_notes', 'admin_estimate_contact_tags', 'admin_estimate_history'] as $metadataTable) {
        if (!member_retention_table_exists($pdo, $metadataTable)) continue;
        $pdo->prepare("DELETE FROM {$metadataTable} WHERE estimate_source = ? AND estimate_id = ?")
            ->execute([$source, $recordId]);
    }
    if (member_retention_table_exists($pdo, 'estimate_admin_memos')) {
        $pdo->prepare('DELETE FROM estimate_admin_memos WHERE LOWER(estimate_type) IN (?, ?) AND estimate_id = ?')
            ->execute([strtolower($source), strtolower('estimate_' . $source), $recordId]);
    }
}

function member_retention_cleanup_identity_shadows(PDO $pdo, int $memberId, string $oldPhone): void
{
    $phones = [$oldPhone];
    $names = [];
    $keys = ['member:' . $memberId];
    foreach (['estimate_direct', 'estimate_quick'] as $table) {
        if (!member_retention_table_exists($pdo, $table)) continue;
        $stmt = $pdo->prepare("SELECT customer_phone AS phone, customer_name AS name FROM {$table} WHERE member_id = ?");
        $stmt->execute([$memberId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $phones[] = (string)$record['phone'];
            $names[] = (string)$record['name'];
        }
    }
    if (member_retention_table_exists($pdo, 'customer_inquiries')) {
        $stmt = $pdo->prepare('SELECT member_phone AS phone, member_name AS name FROM customer_inquiries WHERE member_id = ?');
        $stmt->execute([$memberId]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $phones[] = (string)$record['phone'];
            $names[] = (string)$record['name'];
        }
    }
    foreach (array_unique($phones) as $phone) {
        $digits = preg_replace('/\D+/', '', $phone) ?? '';
        if (preg_match('/^01[016789]\d{7,8}$/D', $digits)) $keys[] = 'phone:' . $digits;
    }
    foreach (array_unique($names) as $name) {
        if ($name !== '') $keys[] = 'activity:' . $name;
    }

    if (member_retention_table_exists($pdo, 'admin_customer_archive')) {
        $delete = $pdo->prepare('DELETE FROM admin_customer_archive WHERE customer_hash = ?');
        foreach (array_unique(array_map(static fn(string $key): string => hash('sha256', $key), $keys)) as $hash) {
            $delete->execute([$hash]);
        }
    }
    if (member_retention_table_exists($pdo, 'customer_admin_memos')) {
        $delete = $pdo->prepare('DELETE FROM customer_admin_memos WHERE customer_phone = ?');
        foreach (array_unique(array_filter($phones, static fn(string $phone): bool => $phone !== '')) as $phone) {
            $delete->execute([$phone]);
        }
    }
}

function member_retention_withdraw_records(PDO $pdo, int $memberId): void
{
    foreach (member_retention_tables() as $table => $metadata) {
        if (!member_retention_table_exists($pdo, $table)) continue;
        if ($metadata['kind'] === 'estimate') {
            foreach (['admin_estimate_notes', 'admin_estimate_contact_tags', 'admin_estimate_history'] as $metadataTable) {
                if (!member_retention_table_exists($pdo, $metadataTable)) continue;
                $source = strtoupper($metadata['source']);
                $sql = "DELETE FROM {$metadataTable} WHERE estimate_source = ? AND estimate_id IN (
                    SELECT e.id FROM {$table} e
                    WHERE e.member_id = ? AND NOT EXISTS (
                        SELECT 1 FROM admin_member_data_retention r
                        WHERE r.record_type = ? AND r.record_id = e.id AND r.member_id = e.member_id
                            AND r.retention_until >= CURDATE()
                    )
                )";
                $pdo->prepare($sql)->execute([$source, $memberId, $table]);
            }
            if (member_retention_table_exists($pdo, 'estimate_admin_memos')) {
                $sourceNames = [strtolower($metadata['source']), strtolower($table)];
                $sql = "DELETE FROM estimate_admin_memos WHERE LOWER(estimate_type) IN (?, ?) AND estimate_id IN (
                    SELECT e.id FROM {$table} e
                    WHERE e.member_id = ? AND NOT EXISTS (
                        SELECT 1 FROM admin_member_data_retention r
                        WHERE r.record_type = ? AND r.record_id = e.id AND r.member_id = e.member_id
                            AND r.retention_until >= CURDATE()
                    )
                )";
                $pdo->prepare($sql)->execute([$sourceNames[0], $sourceNames[1], $memberId, $table]);
            }
        }
        $sql = "DELETE FROM {$table} WHERE member_id = ? AND NOT EXISTS (
            SELECT 1 FROM admin_member_data_retention r
            WHERE r.record_type = ? AND r.record_id = {$table}.id AND r.member_id = {$table}.member_id
                AND r.retention_until >= CURDATE()
        )";
        $pdo->prepare($sql)->execute([$memberId, $table]);
    }

    $pdo->prepare('DELETE FROM admin_member_data_retention WHERE member_id = ? AND retention_until < CURDATE()')
        ->execute([$memberId]);
}

function member_retention_purge_expired(PDO $pdo): int
{
    member_retention_init($pdo);
    $stmt = $pdo->query("SELECT r.record_type, r.record_id, r.member_id, m.status AS member_status, m.withdrawn_at
        FROM admin_member_data_retention r
        LEFT JOIN member_accounts m ON m.id = r.member_id
        WHERE r.retention_until < CURDATE()");
    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $tables = member_retention_tables();
    $removed = 0;

    foreach ($expired as $record) {
        $type = (string)$record['record_type'];
        $recordId = (int)$record['record_id'];
        $memberId = (int)$record['member_id'];
        if (!isset($tables[$type])) {
            $pdo->prepare('DELETE FROM admin_member_data_retention WHERE record_type = ? AND record_id = ?')
                ->execute([$type, $recordId]);
            continue;
        }
        if (($record['withdrawn_at'] !== null || $record['member_status'] === null)
            && member_retention_table_exists($pdo, $type)) {
            $pdo->prepare("DELETE FROM {$type} WHERE id = ? AND member_id = ?")->execute([$recordId, $memberId]);
            if ($tables[$type]['kind'] === 'estimate') {
                member_retention_delete_estimate_metadata($pdo, $type, strtoupper($tables[$type]['source']), $recordId);
            }
            $removed++;
        }
        $pdo->prepare('DELETE FROM admin_member_data_retention WHERE record_type = ? AND record_id = ?')
            ->execute([$type, $recordId]);
    }
    return $removed;
}