<form method="post" id="accountPermissionTableForm">
    <input type="hidden" name="action" value="save_permission_table">
    <input type="hidden" name="csrf_token" value="<?= h2($_SESSION['admin_accounts_csrf']) ?>">
    <input type="hidden" name="permission_changes" id="permissionChanges">
    <div class="permission-table-scroll">
        <table class="permission-table">
            <thead><tr>
                <th scope="col">선택</th><th scope="col">분류</th><th scope="col">이름</th><th scope="col">소속 팀</th><th scope="col">아이디</th>
                <?php foreach (adminCategoryLabels() as $label): ?><th scope="col"><?= h2($label) ?></th><?php endforeach; ?>
                <th scope="col">차량 등록</th><th scope="col">차량 수정</th><th scope="col">차량 삭제</th><th scope="col">상태</th><th scope="col">관리</th>
            </tr></thead>
            <tbody>
            <?php foreach ($admins as $account):
                $isSalesRow = $account['role'] === 'SALES';
                $rowName = trim((string)$account['name']) ?: (string)$account['username'];
                $rowCategories = $isSalesRow ? adminCategoriesForAccount($account) : array_keys(adminCategoryLabels());
                if (!$isSalesRow && $account['role'] !== 'SUPER_ADMIN') $rowCategories = array_diff($rowCategories, ['customers', 'contracts']);
            ?>
                <tr data-account-row data-account-id="<?= (int)$account['id'] ?>" data-account-name="<?= h2($rowName . ' ' . $account['username'] . ' ' . (string)($account['team_name'] ?? '')) ?>" data-role="<?= h2($account['role']) ?>" data-active="<?= (int)$account['is_active'] ?>">
                    <td><?php if ($isSalesRow): ?><input type="checkbox" class="sales-account-check" value="<?= (int)$account['id'] ?>" aria-label="<?= h2($rowName) ?> 일괄처리 선택"><?php endif; ?></td>
                    <td><span class="badge <?= $isSalesRow ? 'sales' : 'owner' ?>"><?= $isSalesRow ? '영업사원' : ($account['role'] === 'SUPER_ADMIN' ? '본 관리자' : h2($account['role'])) ?></span></td>
                    <th scope="row"><?= h2($rowName) ?></th><td class="permission-team"><?= $isSalesRow ? h2(trim((string)($account['team_name'] ?? '')) ?: '미지정') : '-' ?></td><td class="permission-username"><?= h2($account['username']) ?></td>
                    <?php foreach (adminCategoryLabels() as $key => $label): ?>
                    <td><input type="checkbox" data-category="<?= h2($key) ?>" aria-label="<?= h2($rowName . ' ' . $label . ' 접근') ?>" <?= in_array($key, $rowCategories, true) ? 'checked' : '' ?> <?= !$isSalesRow ? 'disabled' : '' ?>></td>
                    <?php endforeach; ?>
                    <?php foreach (['can_create' => '등록', 'can_update' => '수정', 'can_delete' => '삭제'] as $key => $label): ?>
                    <td><input type="checkbox" data-work="<?= h2($key) ?>" aria-label="<?= h2($rowName . ' 차량 ' . $label . ' 권한') ?>" <?= ($isSalesRow ? (int)$account[$key] === 1 : in_array($account['role'], ['SUPER_ADMIN', 'ADMIN'], true)) ? 'checked' : '' ?> <?= !$isSalesRow ? 'disabled' : '' ?>></td>
                    <?php endforeach; ?>
                    <td><span class="status <?= $account['is_active'] ? 'on' : 'off' ?>"><?= $account['is_active'] ? '활성' : '비활성' ?></span></td>
                    <td><button type="button" class="btn" data-open-account="accountDialog<?= (int)$account['id'] ?>">계정 설정</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="permission-table-footer">
        <span id="permissionTableStatus" aria-live="polite">권한을 체크한 후 설정을 저장하세요.</span>
        <button type="submit" class="primary" id="savePermissionTable" disabled>설정 저장</button>
        <button type="reset" class="btn" id="cancelPermissionTable" disabled>취소</button>
    </div>
</form>
