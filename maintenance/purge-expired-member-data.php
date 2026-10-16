<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../api/member-retention-common.php';

try {
    $deleted = member_retention_purge_expired($pdo);
    fwrite(STDOUT, sprintf("Deleted %d expired member records.\n", $deleted));
} catch (Throwable $error) {
    error_log('[AutoGenie member retention cleanup] ' . $error->getMessage());
    fwrite(STDERR, "Member retention cleanup failed. Check the PHP error log.\n");
    exit(1);
}