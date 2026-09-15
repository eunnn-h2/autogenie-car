<?php
declare(strict_types=1);

function adminCategoryLabels(): array {
    return [
        'dashboard' => '운영 현황',
        'vehicles' => '차량 데이터',
        'estimates' => '견적문의',
        'inquiries' => '고객문의',
        'customers' => '고객관리',
        'traffic' => '유입 분석',
    ];
}

function normalizeAdminCategories(mixed $categories): array {
    if (!is_array($categories)) return [];
    return array_values(array_filter(array_keys(adminCategoryLabels()),
        static fn(string $key): bool => in_array($key, $categories, true)));
}

function adminCategoriesForAccount(array $account): array {
    if (($account['role'] ?? '') !== 'SALES') return [];
    if (isset($account['category_permissions'])) {
        return normalizeAdminCategories(json_decode((string)$account['category_permissions'], true));
    }
    // NULL means the account has not yet been configured; preserve its previous access.
    $categories = ['dashboard', 'estimates', 'inquiries'];
    if (!empty($account['can_create']) || !empty($account['can_update']) || !empty($account['can_delete'])) {
        $categories[] = 'vehicles';
    }
    return $categories;
}
