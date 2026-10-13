<?php
declare(strict_types=1);
require_once __DIR__ . '/estimate-edit.php';
require_once __DIR__ . '/estimate-assignment.php';
require_once __DIR__ . '/estimate-contact-tags.php';

function saveEstimateForm(PDO $pdo, string $table, int $id, string $role, int $authorId, string $authorName, array $input, bool $notesReady, bool $tagsReady): void {
    if (!in_array($table, ['estimate_direct', 'estimate_quick'], true) || $id < 1 || $authorId < 1) throw new InvalidArgumentException('잘못된 견적 정보입니다.');
    $admin = canEditEstimateDetails($role);
    $sales = $role === 'SALES';
    if (!$admin && !$sales) throw new RuntimeException('견적을 수정할 권한이 없습니다.');
    $status = $input['status'] ?? null;
    if (!is_string($status) || !in_array($status, ['NEW', 'CONTACTED', 'REVIEWING', 'APPROVED', 'CONTRACTED', 'CANCELED'], true)) throw new InvalidArgumentException('상태를 확인해 주세요.');
    $editDetails = ($input['edit_details'] ?? '') === '1';
    if (($editDetails || array_key_exists('assigned_admin_id', $input)) && !$admin) throw new RuntimeException('관리자만 고객·차량정보와 담당자를 수정할 수 있습니다.');
    if ($editDetails) estimateEditValues($input, $table === 'estimate_quick');
    $note = null;
    if (array_key_exists('note_body', $input)) {
        if (!$notesReady) throw new RuntimeException('상담 메모 저장 공간을 준비하지 못했습니다. 잠시 후 다시 시도해 주세요.');
        if (!is_string($input['note_body']) || mb_strlen(trim($input['note_body']), 'UTF-8') > 5000) throw new InvalidArgumentException('상담 메모는 5,000자 이하로 입력해 주세요.');
        $note = trim($input['note_body']);
    }
    $tag = null;
    if (array_key_exists('contact_tag', $input)) {
        if (!$tagsReady) throw new RuntimeException('상담 구분 저장 공간을 준비하지 못했습니다. 잠시 후 다시 시도해 주세요.');
        if (!is_string($input['contact_tag'])) throw new InvalidArgumentException('상담 구분을 확인해 주세요.');
        $tag = $input['contact_tag'];
    }
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare("SELECT * FROM $table WHERE id = ? FOR UPDATE");
        $query->execute([$id]);
        $current = $query->fetch(PDO::FETCH_ASSOC);
        if (!$current) throw new RuntimeException('견적을 찾을 수 없습니다.');
        if ($sales && (int)($current['assigned_admin_id'] ?? 0) !== $authorId) throw new RuntimeException('본인 담당 견적만 수정할 수 있습니다.');
        if ($editDetails) saveEstimateDetails($pdo, $table, $id, $role, $input, $current);
        if ($status !== (string)$current['status'] && !updateEstimateStatus($pdo, $table, $id, $status, $sales ? $authorId : null)) throw new RuntimeException('상태를 저장하지 못했습니다.');
        if (array_key_exists('assigned_admin_id', $input)) {
            $assignee = $input['assigned_admin_id'];
            if (!is_string($assignee) || !ctype_digit($assignee) || (float)$assignee > 2147483647) throw new InvalidArgumentException('담당자를 확인해 주세요.');
            $assigneeId = (int)$assignee;
            $assigneeChanged = $assigneeId !== (int)($current['assigned_admin_id'] ?? 0);
            if ($assigneeChanged && $assigneeId > 0) {
                $query = $pdo->prepare("SELECT id FROM admin_accounts WHERE id = ? AND role = 'SALES' AND is_active = 1");
                $query->execute([$assigneeId]);
                if (!$query->fetch(PDO::FETCH_ASSOC)) throw new InvalidArgumentException('활동 중인 영업사원을 선택해 주세요.');
            }
            if ($assigneeChanged && !assignEstimate($pdo, $table, $id, $assigneeId ?: null)) throw new RuntimeException('담당자를 저장하지 못했습니다.');
        }
        $source = $table === 'estimate_quick' ? 'QUICK' : 'DIRECT';
        if ($tag !== null) saveEstimateContactTag($pdo, $source, $id, $tag, $authorId);
        if ($note !== null) {
            $query = $pdo->prepare('SELECT body FROM admin_estimate_notes WHERE estimate_source = ? AND estimate_id = ? ORDER BY id DESC LIMIT 1');
            $query->execute([$source, $id]);
            $previous = $query->fetch(PDO::FETCH_ASSOC);
            if ($note !== trim((string)($previous['body'] ?? ''))) {
                $pdo->prepare('INSERT INTO admin_estimate_notes (estimate_source, estimate_id, author_id, author_name, body) VALUES (?, ?, ?, ?, ?)')->execute([$source, $id, $authorId, $authorName, $note]);
            }
        }
        $pdo->commit();
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
