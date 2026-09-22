<?php
declare(strict_types=1);
require_once __DIR__ . '/auth.php';
requireAdminCategory('inquiries');
require_once __DIR__ . '/../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ./inquiries.php'); exit; }

function return_url(string $suffix = ''): string {
    $raw = trim((string)($_POST['return_query'] ?? ''));
    $query = [];
    if ($raw !== '') parse_str($raw, $query);
    unset($query['saved'], $query['bulk'], $query['error']);
    if ($suffix === 'saved') $query['saved'] = 1;
    elseif ($suffix === 'bulk') $query['bulk'] = 1;
    elseif ($suffix === 'error') $query['error'] = 1;
    return './inquiries.php' . ($query ? '?' . http_build_query($query) : '');
}

function detail_return_url(int $id, string $flag): string {
    $query = [];
    parse_str((string)($_POST['return_query'] ?? ''), $query);
    // Only known list filters are carried back; never accept arbitrary redirect targets.
    $query = array_intersect_key($query, array_flip(['status','q','from','to','member_type','per_page','page']));
    return './inquiry-detail.php?' . http_build_query([
        'id' => $id,
        'return_query' => http_build_query($query),
        $flag => 1,
    ]);
}

$action = (string)($_POST['action'] ?? 'save_answer');

if ($action === 'bulk') {
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])), fn($id) => $id > 0)));
    $bulkAction = (string)($_POST['bulk_action'] ?? '');
    if (!$ids || !in_array($bulkAction, ['mark_answered','mark_new','delete'], true)) { header('Location: ' . return_url('error')); exit; }
    $marks = implode(',', array_fill(0, count($ids), '?'));
    if ($bulkAction === 'delete') {
        $stmt = $pdo->prepare("DELETE FROM customer_inquiries WHERE id IN ($marks)");
        $stmt->execute($ids);
    } elseif ($bulkAction === 'mark_new') {
        $stmt = $pdo->prepare("UPDATE customer_inquiries SET status='NEW' WHERE id IN ($marks)");
        $stmt->execute($ids);
    } else {
        $stmt = $pdo->prepare("UPDATE customer_inquiries SET status='ANSWERED', answered_by=?, answered_at=COALESCE(answered_at,NOW()) WHERE id IN ($marks)");
        $stmt->execute(array_merge([(int)($_SESSION['admin_id'] ?? 0)], $ids));
    }
    $detailId = (int)($_POST['detail_id'] ?? 0);
    if ($bulkAction !== 'delete' && $detailId > 0 && count($ids) === 1 && $ids[0] === $detailId) {
        header('Location: ' . detail_return_url($detailId, 'updated'));
    } else {
        header('Location: ' . return_url('bulk'));
    }
    exit;
}

$id = (int)($_POST['id'] ?? 0);
$answer = trim((string)($_POST['answer'] ?? ''));
if ($id <= 0 || $answer === '' || mb_strlen($answer) > 3000) { header('Location: ' . return_url('error')); exit; }
$stmt = $pdo->prepare("UPDATE customer_inquiries SET answer=?, status='ANSWERED', answered_by=?, answered_at=NOW() WHERE id=?");
$stmt->execute([$answer, (int)($_SESSION['admin_id'] ?? 0), $id]);
if ((int)($_POST['detail_id'] ?? 0) === $id) {
    header('Location: ' . detail_return_url($id, 'saved'));
} else {
    header('Location: ' . return_url('saved'));
}
exit;
