document.addEventListener('DOMContentLoaded', () => {
    const create = document.getElementById('createAccount');
    document.getElementById('openCreateAccount')?.addEventListener('click', () => {
        if (create) create.open = true;
    });

    document.querySelectorAll('.account-toggle').forEach(button => {
        button.addEventListener('click', () => {
            const editor = document.getElementById(button.getAttribute('aria-controls'));
            editor.hidden = !editor.hidden;
            button.setAttribute('aria-expanded', String(!editor.hidden));
            button.textContent = editor.hidden ? '권한 / 계정 설정' : '설정 닫기';
        });
    });

    document.querySelectorAll('[data-permission-group]').forEach(group => {
        const all = group.querySelector('[data-permission-all]');
        const items = [...group.querySelectorAll('[data-permission-item]')];
        if (!all || !items.length) return;
        const sync = () => {
            all.checked = items.every(item => item.checked);
            all.indeterminate = !all.checked && items.some(item => item.checked);
        };
        all.addEventListener('change', () => {
            items.forEach(item => { item.checked = all.checked; });
            sync();
        });
        items.forEach(item => item.addEventListener('change', sync));
        sync();
    });

    document.querySelectorAll('.account-update-form').forEach(form => {
        const markDirty = () => {
            form.closest('.account-manage').querySelector('.dirty-indicator').hidden = false;
        };
        form.addEventListener('input', markDirty);
        form.addEventListener('change', markDirty);
    });

    const cards = [...document.querySelectorAll('.account-card')];
    const search = document.getElementById('accountSearch');
    const role = document.getElementById('accountRole');
    const status = document.getElementById('accountStatus');
    const checks = [...document.querySelectorAll('.sales-account-check')];
    const bulkForm = document.getElementById('bulkPermissionForm');
    const selectAll = document.getElementById('selectAllSales');
    const apply = document.getElementById('bulkPermissionApply');
    const visibleChecks = () => checks.filter(check => !check.closest('.account-card').hidden);

    const syncSelection = () => {
        if (!bulkForm) return;
        const selected = checks.filter(check => check.checked);
        bulkForm.querySelectorAll('input[name="selected_admin_ids[]"]').forEach(input => input.remove());
        selected.forEach(check => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'selected_admin_ids[]';
            input.value = check.value;
            bulkForm.appendChild(input);
        });
        document.getElementById('bulkSelectedCount').textContent = String(selected.length);
        apply.disabled = !selected.length;
        const visible = visibleChecks();
        selectAll.checked = visible.length > 0 && visible.every(check => check.checked);
        selectAll.indeterminate = !selectAll.checked && visible.some(check => check.checked);
        selectAll.disabled = !visible.length;
    };
    const filter = () => {
        const query = (search?.value || '').trim().toLocaleLowerCase();
        cards.forEach(card => {
            const matchesRole = !role.value || (role.value === 'OTHER' ? card.dataset.role !== 'SALES' : card.dataset.role === role.value);
            card.hidden = !(card.dataset.accountName.toLocaleLowerCase().includes(query) && matchesRole && (!status.value || card.dataset.active === status.value));
        });
        const count = cards.filter(card => !card.hidden).length;
        const result = document.getElementById('accountResultCount');
        if (result) result.textContent = `${count}명 표시`;
        const empty = document.getElementById('accountNoResults');
        if (empty) empty.hidden = count > 0;
        syncSelection();
    };
    search?.addEventListener('input', filter);
    role?.addEventListener('change', filter);
    status?.addEventListener('change', filter);
    selectAll?.addEventListener('change', () => {
        visibleChecks().forEach(check => { check.checked = selectAll.checked; });
        syncSelection();
    });
    checks.forEach(check => check.addEventListener('change', syncSelection));
    bulkForm?.addEventListener('submit', event => {
        const selected = checks.filter(check => check.checked);
        if (!selected.length || !confirm(`선택한 ${selected.length}명(검색으로 숨겨진 선택 포함)의 작업·카테고리 권한을 현재 체크한 내용으로 변경할까요?`)) {
            event.preventDefault();
        }
    });
    filter();
});
