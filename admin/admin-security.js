(() => {
    const token = document.querySelector('meta[name="admin-csrf-token"]')?.content;
    if (!token) return;
    function attach(form) {
        if(form.method.toLowerCase()!=='post')return;
        let input = form.querySelector('[name="admin_csrf_token"]');
        if (!input) { input=document.createElement('input');input.type='hidden';input.name='admin_csrf_token';form.append(input); }
        input.value=token;
    }
    document.querySelectorAll('form').forEach(attach);
    document.addEventListener('submit', event => { if(event.target instanceof HTMLFormElement)attach(event.target); },true);
})();
