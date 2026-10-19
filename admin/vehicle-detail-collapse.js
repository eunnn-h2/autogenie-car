(() => {
    const sections = [...document.querySelectorAll('#vehicle-basic, #vehicle-colors, #vehicle-trims, #vehicle-prices, #vehicle-info-editor')];
    const toggleAll = document.getElementById('vehicle-toggle-all');
    const anyOpen = () => sections.some(section => !section.classList.contains('vehicle-section-collapsed'));
    const syncToggleAll = () => {
        if (!toggleAll) return;
        const open = anyOpen();
        toggleAll.textContent = open ? '전체 접기' : '전체 펼치기';
        toggleAll.setAttribute('aria-expanded', String(open));
    };
    const setOpen = (section, open) => {
        section.classList.toggle('vehicle-section-collapsed', !open);
        const button = section.querySelector('.vehicle-section-toggle');
        button.setAttribute('aria-expanded', String(open));
        button.querySelector('span').textContent = open ? '접기 ▴' : '펼치기 ▾';
        syncToggleAll();
    };
    sections.forEach(section => {
        const heading = section.querySelector('h3');
        if (!heading) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'vehicle-section-toggle';
        button.append(document.createTextNode(heading.textContent));
        button.append(document.createElement('span'));
        heading.replaceChildren(button);
        button.addEventListener('click', () => setOpen(section, section.classList.contains('vehicle-section-collapsed')));
        setOpen(section, false);
    });
    const setAllOpen = open => {
        sections.forEach(section => setOpen(section, open));
        syncToggleAll();
    };
    toggleAll?.addEventListener('click', () => setAllOpen(!anyOpen()));
    const openHash = () => {
        const section = sections.find(item => '#' + item.id === location.hash);
        if (section) setOpen(section, true);
    };
    document.querySelectorAll('.vehicle-section-nav a').forEach(link => {
        link.addEventListener('click', () => {
            const section = sections.find(item => '#' + item.id === link.hash);
            if (section) setOpen(section, true);
        });
    });
    window.addEventListener('hashchange', openHash);
    document.addEventListener('invalid', event => {
        const section = sections.find(item => item.contains(event.target));
        if (section) setOpen(section, true);
        const item = event.target.closest('details');
        if (item) item.open = true;
    }, true);
})();
