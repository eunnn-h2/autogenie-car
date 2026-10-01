document.addEventListener('DOMContentLoaded', () => {
    const teamManager = document.getElementById('teamManager');
    document.getElementById('openTeamManager')?.addEventListener('click', () => {
        if (teamManager && !teamManager.open) teamManager.showModal();
    });
    document.getElementById('closeTeamManager')?.addEventListener('click', () => teamManager?.close());
    teamManager?.addEventListener('click', event => {
        const bounds = teamManager.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) teamManager.close();
    });
    if (teamManager?.dataset.reopen === '1' && !teamManager.open) teamManager.showModal();

    const create = document.getElementById('createAccount');
    document.getElementById('openCreateAccount')?.addEventListener('click', () => {
        if (create && !create.open) create.showModal();
    });
    document.getElementById('closeCreateAccount')?.addEventListener('click', () => create?.close());
    create?.addEventListener('click', event => {
        if (event.target !== create) return;
        const bounds = create.getBoundingClientRect();
        if (event.clientX < bounds.left || event.clientX > bounds.right || event.clientY < bounds.top || event.clientY > bounds.bottom) create.close();
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

    document.querySelectorAll('[data-open-account]').forEach(button => {
        button.addEventListener('click', () => {
            const dialog = document.getElementById(button.dataset.openAccount);
            const editor = dialog.querySelector('.account-manage');
            const toggle = dialog.querySelector('.account-toggle');
            editor.hidden = false;
            toggle.setAttribute('aria-expanded', 'true');
            toggle.textContent = '설정 닫기';
            dialog.showModal();
        });
    });
    document.querySelectorAll('[data-close-account]').forEach(button => {
        button.addEventListener('click', () => button.closest('dialog').close());
    });

    const cards = [...document.querySelectorAll('[data-account-row]')];
    const permissionForm = document.getElementById('accountPermissionTableForm');
    const editableRows = cards.filter(row => row.dataset.role === 'SALES');
    const readPermissions = row => {
        const value = { id: Number(row.dataset.accountId), categories: [] };
        row.querySelectorAll('[data-category]').forEach(input => {
            if (input.checked) value.categories.push(input.dataset.category);
        });
        row.querySelectorAll('[data-work]').forEach(input => { value[input.dataset.work] = input.checked ? 1 : 0; });
        return value;
    };
    const originalPermissions = new Map(editableRows.map(row => [row, JSON.stringify(readPermissions(row))]));
    const changedRows = () => editableRows.filter(row => JSON.stringify(readPermissions(row)) !== originalPermissions.get(row));
    const syncPermissions = () => {
        if (!permissionForm) return;
        const changed = changedRows();
        editableRows.forEach(row => row.classList.toggle('permissions-changed', changed.includes(row)));
        document.getElementById('savePermissionTable').disabled = !changed.length;
        document.getElementById('cancelPermissionTable').disabled = !changed.length;
        document.getElementById('permissionTableStatus').textContent = changed.length
            ? `${changed.length}개 계정의 변경사항이 있습니다. 검색으로 숨겨진 변경도 저장됩니다.`
            : '권한을 체크한 후 설정을 저장하세요.';
    };
    permissionForm?.addEventListener('change', syncPermissions);
    permissionForm?.addEventListener('reset', () => setTimeout(() => {
        syncPermissions();
        syncSelection();
    }, 0));
    permissionForm?.addEventListener('submit', event => {
        const changed = changedRows();
        if (!changed.length || !confirm(`${changed.length}개 계정의 권한 설정을 저장할까요?`)) {
            event.preventDefault();
            return;
        }
        document.getElementById('permissionChanges').value = JSON.stringify(changed.map(readPermissions));
    });
    document.querySelectorAll('form').forEach(form => {
        if (form === permissionForm) return;
        form.addEventListener('submit', event => {
            if (changedRows().length && !confirm('표에서 수정한 권한이 아직 저장되지 않았습니다. 저장하지 않고 이 작업을 진행할까요?')) event.preventDefault();
        });
    });
    const search = document.getElementById('accountSearch');
    const role = document.getElementById('accountRole');
    const status = document.getElementById('accountStatus');
    const checks = [...document.querySelectorAll('.sales-account-check')];
    const bulkForm = document.getElementById('bulkPermissionForm');
    const selectAll = document.getElementById('selectAllSales');
    const apply = document.getElementById('bulkPermissionApply');
    const bulkAction = document.getElementById('bulkAction');
    const bulkChoices = [...(bulkForm?.querySelectorAll('.bulk-settings select') || [])];
    const visibleChecks = () => checks.filter(check => !check.closest('[data-account-row]').hidden);

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
        apply.disabled = !selected.length || !bulkChoices.some(select => select.value);
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
    bulkAction?.addEventListener('change', syncSelection);
    bulkForm?.querySelectorAll('[data-bulk-choice]').forEach(select => select.addEventListener('change', syncSelection));
    bulkForm?.addEventListener('submit', event => {
        const selected = checks.filter(check => check.checked);
        const choices = bulkChoices.filter(select => select.value);
        const labels = choices.map(select => select.selectedOptions[0].textContent).join(', ');
        const message = `선택한 ${selected.length}명(검색으로 숨겨진 선택 포함)에 '${labels}'을 적용할까요?`;
        if (!selected.length || !choices.length || !confirm(message)) {
            event.preventDefault();
        }
    });
    filter();
});
