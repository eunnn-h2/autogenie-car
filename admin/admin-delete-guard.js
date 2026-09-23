(() => {
    const DELETE_PATTERN = /delete|삭제/i;
    const CONFIRM_TEXT = '삭제';
    let approvedClick = false;

    function isDeleteForm(form, submitter) {
        const values = [
            form.querySelector('[name="crud_action"]')?.value,
            form.querySelector('[name="action"]')?.value,
            form.querySelector('[name="bulk_action"]')?.value,
            submitter?.value,
            submitter?.textContent,
        ].filter(Boolean);

        return values.some(value => DELETE_PATTERN.test(String(value)));
    }

    function requireTypedConfirmation() {
        while (true) {
            const typed = window.prompt('삭제를 진행하려면 "삭제"를 입력하세요.');
            if (typed === null || typed === '') return false;
            if (typed === CONFIRM_TEXT) return true;
            window.alert('잘못 입력되었습니다. "삭제"를 정확히 입력해야 합니다.');
        }
    }

    function cancelEvent(event) {
        event.preventDefault();
        event.stopImmediatePropagation();
    }

    document.addEventListener('submit', event => {
        const form = event.target;
        if (form instanceof HTMLFormElement && isDeleteForm(form, event.submitter)) {
            if (approvedClick || form.dataset.deleteGuardPassed === 'true') {
                approvedClick = false;
                delete form.dataset.deleteGuardPassed;
                return;
            }
            if (!requireTypedConfirmation()) cancelEvent(event);
        }
    }, true);

    document.addEventListener('click', event => {
        const button = event.target.closest('button');
        if (!button || !DELETE_PATTERN.test(button.className + ' ' + button.textContent + ' ' + button.value)) return;
        const form = button.form;
        if (!form) return;
        if (!requireTypedConfirmation()) {
            cancelEvent(event);
            return;
        }
        approvedClick = true;
        window.setTimeout(() => { approvedClick = false; }, 0);
        form.dataset.deleteGuardPassed = 'true';
    }, true);
})();
