document.querySelector('[data-copy-consultation]')?.addEventListener('click', async () => {
    const input = document.getElementById('consultationCopyText');
    const status = document.getElementById('consultationCopyStatus');
    try {
        if (navigator.clipboard && window.isSecureContext) await navigator.clipboard.writeText(input.value);
        else { input.focus(); input.select(); if (!document.execCommand('copy')) throw new Error('copy'); }
        status.textContent = '복사했습니다. 카카오톡 상담창에 붙여 넣어 주세요.';
    } catch (_) {
        input.focus(); input.select(); status.textContent = '선택된 상담 내용을 직접 복사해 주세요.';
    }
});
