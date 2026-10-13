<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=utf-8');
require_once __DIR__ . '/auth.php';
if (!isSuperAdmin()) {
    http_response_code(403);
    exit('메인 관리자만 상담 구분 색상을 변경할 수 있습니다.');
}
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/estimate-contact-tags.php';
$currentAdminId = (int)($_SESSION['admin_id'] ?? 0);
$stmt = $pdo->prepare('SELECT role, is_active FROM admin_accounts WHERE id = ? LIMIT 1');
$stmt->execute([$currentAdminId]);
$account = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$account || $account['role'] !== 'SUPER_ADMIN' || !(int)$account['is_active']) {
    http_response_code(403);
    exit('메인 관리자만 상담 구분 색상을 변경할 수 있습니다.');
}
if (empty($_SESSION['contact_tag_colors_csrf'])) $_SESSION['contact_tag_colors_csrf'] = bin2hex(random_bytes(32));
$message = null;
$error = null;
function esc(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['contact_tag_colors_csrf'], $_POST['csrf'])) {
            throw new RuntimeException('요청이 만료되었습니다. 새로고침 후 다시 시도해 주세요.');
        }
        saveEstimateContactTagColors($pdo, is_array($_POST['colors'] ?? null) ? $_POST['colors'] : [], $currentAdminId);
        $message = '상담 구분 색상을 저장했습니다.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$contactTagColors = getEstimateContactTagColors($pdo);
?>
<!DOCTYPE html>
<html lang="ko"><head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>상담 색상</title>
<link rel="stylesheet" href="./sidebar.css?v=<?=filemtime(__DIR__ . '/sidebar.css')?>">
<link rel="stylesheet" href="./account-settings-page.css">
<link rel="stylesheet" href="./admin-ui.css">
</head><body><div class="admin-shell">
<?php $currentAdminPage = 'contact-tag-colors'; require __DIR__ . '/sidebar.php'; ?>
<main class="main"><div class="wrap">
<section class="card head ag-page-card"><h1>상담 색상</h1></section>
<?php if ($message): ?><div class="alert ok" role="status"><?=esc($message)?></div><?php endif; ?>
<?php if ($error): ?><div class="alert err" role="alert"><?=esc($error)?></div><?php endif; ?>

    <section class="card" id="contact-tag-colors">
        <h2 class="form-title">상담별 색상</h2>
        <p class="help">저장한 색상은 모든 관리자의 견적 목록 행 배경과 상담 구분 선택에 적용됩니다.</p>
        <form method="post">
            <input type="hidden" name="action" value="save_contact_tag_colors">
            <input type="hidden" name="csrf" value="<?=esc($_SESSION['contact_tag_colors_csrf'])?>">
            <div style="display:flex;flex-wrap:wrap;gap:16px;margin:20px 0">
            <?php foreach (estimateContactTagOptions() as $tag => $label): ?>
                <label style="display:flex;align-items:center;gap:10px;padding:12px;border:1px solid #dfe6ea;border-radius:6px">
                    <span><?=esc($label)?></span>
                    <input type="color" name="colors[<?=esc($tag)?>]" value="<?=esc($contactTagColors[$tag])?>" aria-label="<?=esc($label)?> 배경 색상" data-default="<?=esc(estimateContactTagDefaultColors()[$tag])?>">
                </label>
            <?php endforeach; ?>
            </div>
            <button class="btn" type="submit">색상 저장</button>
            <button class="btn" type="button" onclick="this.form.querySelectorAll('input[type=color]').forEach(input => input.value = input.dataset.default)">기본 색상으로 되돌리기</button>
            <p class="help">기본 색상으로 되돌린 후 색상 저장을 누르면 적용됩니다.</p>
        </form>
    </section>

</div></main></div></body></html>
