<?php
declare(strict_types=1);
function ag_h(mixed $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ag_table_exists(PDO $pdo, string $table): bool {
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $s->execute([$table]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function ag_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
        $s = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $s->execute([$table, $column]);
        return (int)$s->fetchColumn() > 0;
    } catch (Throwable $e) {
        return false;
    }
}
function ag_scalar(PDO $pdo, string $sql, array $params=[]): int {
    try { $s=$pdo->prepare($sql); $s->execute($params); return (int)$s->fetchColumn(); } catch(Throwable $e){ return 0; }
}
function ag_status_labels(): array { return ['NEW'=>'신규','CONTACTED'=>'상담중','REVIEWING'=>'심사중','APPROVED'=>'승인','CONTRACTED'=>'계약','CLOSED'=>'종료','CANCELED'=>'취소']; }
function ag_upload_original_name(array $file, string $relativeDir, string $projectRoot): ?string {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) throw new RuntimeException('이미지 업로드에 실패했습니다.');
    $original = basename((string)$file['name']);
    if ($original === '') throw new RuntimeException('파일명이 없습니다.');
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp','gif'], true)) throw new RuntimeException('jpg, png, webp, gif 이미지만 업로드할 수 있습니다.');
    $relativeDir = trim($relativeDir, '/');
    $targetDir = rtrim($projectRoot, '/') . '/' . $relativeDir;
    if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) throw new RuntimeException('이미지 폴더를 만들 수 없습니다.');
    $target = $targetDir . '/' . $original;
    if (!move_uploaded_file((string)$file['tmp_name'], $target)) throw new RuntimeException('이미지를 저장하지 못했습니다.');
    return $relativeDir . '/' . $original;
}
