(() => {
    const list = document.getElementById('vehicle-info-items');
    if (!list) return;
    let nextIndex = list.children.length;
    document.getElementById('vehicle-info-add').addEventListener('click', () => {
        list.insertAdjacentHTML('beforeend', document.getElementById('vehicle-info-template').innerHTML.replaceAll('__INDEX__', String(nextIndex++)));
        list.lastElementChild.open = true;
        list.lastElementChild.querySelector('input').focus();
    });
    list.addEventListener('input', event => {
        if (!event.target.name?.endsWith('[option_name]')) return;
        event.target.closest('.vehicle-info-item').querySelector('summary').textContent = event.target.value.trim() || '새 사양 항목';
    });
    list.addEventListener('click', event => {
        const button = event.target.closest('[data-info-action]');
        if (!button) return;
        const item = button.closest('.vehicle-info-item');
        if (button.dataset.infoAction === 'remove') item.remove();
        if (button.dataset.infoAction === 'up' && item.previousElementSibling) list.insertBefore(item, item.previousElementSibling);
        if (button.dataset.infoAction === 'down' && item.nextElementSibling) list.insertBefore(item.nextElementSibling, item);
    });
})();
