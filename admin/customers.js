document.addEventListener('submit', event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-customer-action]')) return;
    const action = form.elements.namedItem('action').value;
    const name = form.dataset.customerName;
    const confirmation = form.elements.namedItem('delete_confirmation');
    confirmation.value = '';
    if (action === 'restore') {
        if (!window.confirm(`${name} 고객을 목록으로 복원할까요?`)) event.preventDefault();
        return;
    }
    if (!window.confirm(`${name} 고객을 목록에서 삭제할까요?\n회원 계정과 견적·문의 기록은 보관되며, 삭제된 고객에서 복원할 수 있습니다.`)) {
        event.preventDefault();
        return;
    }
    const typed = window.prompt('2차 확인: 삭제를 진행하려면 "삭제"를 입력하세요.');
    if (typed !== '삭제') {
        event.preventDefault();
        if (typed !== null) window.alert('"삭제"를 정확히 입력해야 합니다.');
        return;
    }
    confirmation.value = typed;
});
