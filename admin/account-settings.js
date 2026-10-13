document.addEventListener('DOMContentLoaded', () => {
    const password = document.getElementById('new-password');
    const confirmation = document.getElementById('confirm-password');
    const status = document.getElementById('password-match-status');
    if (!password || !confirmation || !status) return;

    const updateMatch = () => {
        const hasValues = password.value.length > 0 && confirmation.value.length > 0;
        const matches = password.value === confirmation.value;
        status.hidden = !hasValues;
        status.textContent = hasValues
            ? (matches ? '새 비밀번호가 일치합니다.' : '새 비밀번호가 일치하지 않습니다.')
            : '';
        status.classList.toggle('match', hasValues && matches);
        status.classList.toggle('mismatch', hasValues && !matches);
        confirmation.setCustomValidity(hasValues && !matches ? '새 비밀번호 확인이 일치하지 않습니다.' : '');
        if (hasValues && !matches) confirmation.setAttribute('aria-invalid', 'true');
        else confirmation.removeAttribute('aria-invalid');
    };

    [password, confirmation].forEach(input => {
        input.addEventListener('input', updateMatch);
        input.addEventListener('change', updateMatch);
    });
    updateMatch();
});
