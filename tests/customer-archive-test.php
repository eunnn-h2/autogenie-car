<?php
declare(strict_types=1);
require_once __DIR__ . '/../admin/customer-archive.php';

function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec('CREATE TABLE admin_customer_archive (customer_hash TEXT PRIMARY KEY, snapshot TEXT NOT NULL, deleted_by INTEGER NOT NULL, deleted_at TEXT DEFAULT CURRENT_TIMESTAMP)');
foreach (['member_accounts', 'estimate_direct', 'estimate_quick', 'customer_inquiries'] as $table) {
    $pdo->exec("CREATE TABLE $table (id INTEGER PRIMARY KEY, content TEXT)");
    $pdo->exec("INSERT INTO $table VALUES (1, 'original')");
}
$hash = hash('sha256', 'phone:01012345678');
$customers = [$hash => ['name' => '테스트 고객', 'phone' => '010-1234-5678', 'customer_hash' => $hash]];
$post = ['csrf_token' => 'token', 'customer_hash' => $hash, 'action' => 'delete', 'delete_confirmation' => '삭제'];
foreach ([
    [false, $post, 403],
    [true, array_replace($post, ['csrf_token' => 'wrong']), 403],
    [true, array_replace($post, ['csrf_token' => []]), 403],
    [true, array_replace($post, ['delete_confirmation' => '']), 400],
    [true, array_replace($post, ['customer_hash' => []]), 400],
    [true, array_replace($post, ['customer_hash' => str_repeat('a', 64)]), 409],
    [true, array_replace($post, ['action' => 'invalid']), 400],
] as [$main, $request, $code]) {
    try {
        customerArchiveApply($pdo, $main, 'token', $request, $customers, [], 1);
        throw new LogicException('Invalid request was accepted');
    } catch (RuntimeException $error) {
        check($error->getCode() === $code, 'Unexpected rejection code');
    }
    check(customerArchiveLoad($pdo) === [], 'Rejected request mutated archive');
}
customerArchiveApply($pdo, true, 'token', $post, $customers, [], 1);
$archived = customerArchiveLoad($pdo);
check(isset($archived[$hash]), 'Deleted customer is missing from archive');
check($archived[$hash]['name'] === '테스트 고객', 'Snapshot lost customer data');
check(array_diff_key($customers, $archived) === [], 'Deleted customer remains visible');
try {
    customerArchiveApply($pdo, true, 'token', $post, $customers, $archived, 1);
    throw new LogicException('Repeated deletion was accepted');
} catch (RuntimeException $error) {
    check($error->getCode() === 409, 'Repeated deletion must conflict');
}
$restore = array_replace($post, ['action' => 'restore']);
try {
    customerArchiveApply($pdo, false, 'token', $restore, $customers, $archived, 2);
    throw new LogicException('Non-main admin restored a customer');
} catch (RuntimeException $error) {
    check($error->getCode() === 403, 'Restore permission was not checked');
}
customerArchiveApply($pdo, true, 'token', $restore, $customers, $archived, 1);
check(customerArchiveLoad($pdo) === [], 'Restore did not clear archive');
check(count(array_diff_key($customers, customerArchiveLoad($pdo))) === 1, 'Restored customer remains hidden');
foreach (['member_accounts', 'estimate_direct', 'estimate_quick', 'customer_inquiries'] as $table) {
    check($pdo->query("SELECT content FROM $table WHERE id = 1")->fetchColumn() === 'original', 'Original data changed');
}
echo "Customer archive: permission, CSRF, confirmation, deletion, restoration and preservation checks passed.\n";
