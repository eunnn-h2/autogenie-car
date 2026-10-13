(() => {
    const form = document.getElementById('vehicleCreateForm');
    if (!form) return;
    const draft = JSON.parse(document.getElementById('vehicleCreateDraft').textContent);
    const definitions = {
        colors: [
            ['name', '색상명', 'text', '', true], ['hex_code', 'HEX 코드', 'text', ''],
            ['border_color', '테두리 코드', 'text', ''], ['image_path', '기존 이미지 경로', 'text', ''],
            ['sort_order', '정렬', 'number', '0'], ['is_active', '상태', 'select', '1'],
        ],
        trims: [
            ['name', '트림명', 'text', '', true], ['price', '차량가 (원)', 'number', '0', true],
            ['description', '설명', 'text', ''],
            ['sort_order', '정렬', 'number', '0'], ['is_active', '상태', 'select', '1'],
        ],
        prices: [
            ['trim_key', '트림', 'select', '', true], ['product_type', '상품', 'select', 'RENT', true],
            ['contract_months', '기간(개월)', 'number', '48', true],
            ['prepayment_rate', '선납금%', 'number', '0', true],
            ['annual_mileage', '주행거리(km)', 'number', '20000', true],
            ['monthly_payment', '월 납입금(원)', 'number', '', true],
            ['is_active', '상태', 'select', '1'],
        ],
    };
    const counters = {colors: 0, trims: 0, prices: 0};
    const containers = Object.fromEntries(Object.keys(counters).map(group => [group, form.querySelector(`[data-create-rows="${group}"]`)]));
    const field = (row, name) => row.querySelector(`[data-field="${name}"]`);
    const mainInput = document.getElementById('vehicleCreateImage');
    let mainImageUrl = '';
    function refreshMainPhoto() {
        const selected = form.querySelector('[name="representative_color_key"]:checked');
        const row = selected?.closest('.vehicle-create-row');
        const path = row ? field(row, 'image_path').value.trim() : '';
        const source = row ? (row.dataset.previewUrl || (path ? '../' + path.replace(/^\/+/, '') : '')) : mainImageUrl;
        const preview = document.getElementById('vehicleCreatePreview');
        preview.hidden = !source;
        document.getElementById('vehicleCreatePlaceholder').hidden = !!source;
        if (source) preview.src = source; else preview.removeAttribute('src');
    }
    mainInput.addEventListener('change', () => {
        if (mainImageUrl) URL.revokeObjectURL(mainImageUrl);
        mainImageUrl = mainInput.files.length ? URL.createObjectURL(mainInput.files[0]) : '';
        form.querySelectorAll('[name="representative_color_key"]').forEach(radio => radio.checked = false);
        refreshMainPhoto();
    });
    function refreshCounts(group) {
        const section = form.querySelector(`[data-create-group="${group}"]`);
        const count = containers[group].children.length;
        section.querySelector('[data-row-count]').textContent = count;
        form.querySelector(`[data-nav-count="${group}"]`).textContent = count;
        section.querySelector('[data-empty-rows]').hidden = count > 0;
        section.querySelector('[data-add-row]').disabled = count >= 100;
    }
    function refreshTrims() {
        const trims = Array.from(containers.trims.children).map(row => [row.dataset.key, field(row, 'name').value.trim() || '이름 미입력 트림']);
        for (const row of containers.prices.children) {
            const select = field(row, 'trim_key');
            const selected = select.value || select.dataset.initial || '';
            select.replaceChildren(new Option('트림 선택', ''));
            trims.forEach(([key, label]) => select.add(new Option(label, key)));
            select.value = trims.some(([key]) => key === selected) ? selected : '';
            delete select.dataset.initial;
        }
    }
    function addRow(group, values = {}, restoredKey) {
        if (containers[group].children.length >= 100) return;
        const key = restoredKey === undefined ? counters[group]++ : Number(restoredKey);
        counters[group] = Math.max(counters[group], key + 1);
        const row = document.createElement('div');
        row.className = `vehicle-create-row vehicle-create-row--${group}`;
        row.dataset.key = key;
        for (const [name, label, type, fallback, required] of definitions[group]) {
            const wrapper = document.createElement('label');
            wrapper.className = 'vehicle-edit-field';
            wrapper.dataset.column = name;
            const caption = document.createElement('span');
            caption.textContent = label;
            const input = document.createElement(type === 'select' ? 'select' : 'input');
            input.dataset.field = name;
            input.name = `${group}[${key}][${name}]`;
            input.required = !!required;
            if (type !== 'select') input.type = type;
            if (type === 'number') { input.min = ['monthly_payment', 'contract_months'].includes(name) ? '1' : '0'; input.step = name === 'prepayment_rate' ? '0.01' : '1'; }
            if (name === 'contract_months') input.max = '120';
            if (name === 'prepayment_rate') input.max = '100';
            if (['hex_code', 'border_color'].includes(name)) { input.placeholder = '#FFFFFF'; input.pattern = '#[0-9a-fA-F]{6}'; }
            if (name === 'product_type') input.replaceChildren(new Option('렌트', 'RENT'), new Option('리스', 'LEASE'));
            if (name === 'is_active') input.replaceChildren(new Option('사용', '1'), new Option('중지', '0'));
            if (name === 'trim_key') input.dataset.initial = values[name] ?? '';
            else input.value = values[name] ?? fallback;
            wrapper.append(caption, input);
            row.append(wrapper);
        }
        if (group === 'colors') {
            const radio = document.createElement('input');
            radio.type = 'radio'; radio.name = 'representative_color_key'; radio.value = String(key);
            radio.className = 'create-representative-radio';
            radio.setAttribute('aria-label', '이 색상 사진을 대표 이미지로 선택');
            radio.title = '대표 이미지 선택';
            radio.checked = String(draft.representative_color_key ?? '') === String(key) && restoredKey !== undefined;
            radio.addEventListener('change', refreshMainPhoto);
            const label = document.createElement('div');
            label.className = 'vehicle-edit-field create-color-photo';
            const caption = document.createElement('span'); caption.textContent = '색상 사진';
            const file = document.createElement('input');
            file.type = 'file'; file.name = `color_images[${key}]`; file.accept = '.jpg,.jpeg,.png,.webp,.gif';
            file.hidden = true;
            const photoButton = document.createElement('button');
            photoButton.type = 'button'; photoButton.className = 'create-color-photo-button';
            photoButton.setAttribute('aria-label', '색상 사진 첨부');
            const photoText = document.createElement('span'); photoText.textContent = '+ 사진 첨부';
            photoButton.addEventListener('click', () => file.click());
            const preview = document.createElement('img'); preview.hidden = true; preview.alt = '색상 사진 미리보기'; preview.className = 'vehicle-create-color-preview';
            const attached = document.createElement('input'); attached.type = 'hidden'; attached.name = `colors[${key}][image_attached]`; attached.value = '0';
            file.addEventListener('change', () => {
                attached.value = file.files.length ? '1' : '0';
                if (row.dataset.previewUrl) URL.revokeObjectURL(row.dataset.previewUrl);
                delete row.dataset.previewUrl;
                preview.hidden = !file.files.length;
                photoButton.classList.toggle('has-photo', !!file.files.length);
                photoText.textContent = file.files.length ? '사진 변경' : '+ 사진 첨부';
                photoButton.setAttribute('aria-label', file.files.length ? '색상 사진 변경' : '색상 사진 첨부');
                if (file.files.length) { row.dataset.previewUrl = URL.createObjectURL(file.files[0]); preview.src = row.dataset.previewUrl; }
                else preview.removeAttribute('src');
                updateColorPhoto();
            });
            function updateColorPhoto() {
                const path = field(row, 'image_path').value.trim();
                const source = row.dataset.previewUrl || (path ? '../' + path.replace(/^\/+/, '') : '');
                if (!file.files.length) {
                    delete row.dataset.previewUrl;
                    preview.hidden = !path;
                    if (path) preview.src = '../' + path.replace(/^\/+/, '');
                }
                radio.disabled = !source;
                if (radio.disabled) radio.checked = false;
                photoButton.classList.toggle('has-photo', !!source);
                photoText.textContent = source ? '사진 변경' : '+ 사진 첨부';
                refreshMainPhoto();
            }
            field(row, 'image_path').addEventListener('input', updateColorPhoto);
            photoButton.append(preview, photoText);
            label.append(caption, photoButton, file, attached); row.prepend(radio, label);
            // Run after attachment so the selected radio is available to the main preview.
            row.updateColorPhoto = updateColorPhoto;
        }
        const actions = document.createElement('div'); actions.className = 'vehicle-create-row-actions';
        const copy = document.createElement('button'); copy.type = 'button'; copy.className = 'gray-btn'; copy.textContent = '행 복사';
        copy.addEventListener('click', () => {
            const values = Object.fromEntries(definitions[group].map(([name]) => [name, field(row, name).value]));
            addRow(group, values);
        });
        const remove = document.createElement('button'); remove.type = 'button'; remove.className = 'gray-btn'; remove.textContent = '행 제거';
        remove.addEventListener('click', () => {
            if (group === 'trims' && Array.from(containers.prices.children).some(price => field(price, 'trim_key').value === String(key))) {
                alert('이 트림에 연결된 가격 조건을 먼저 제거하거나 다른 트림으로 변경해 주세요.'); return;
            }
            if (row.dataset.previewUrl) URL.revokeObjectURL(row.dataset.previewUrl);
            row.remove(); refreshCounts(group); if (group === 'trims') refreshTrims();
            refreshMainPhoto();
        });
        actions.append(copy, remove); row.append(actions); containers[group].append(row);
        row.updateColorPhoto?.();
        if (group === 'trims') field(row, 'name').addEventListener('input', refreshTrims);
        refreshCounts(group); refreshTrims();
        return row;
    }
    for (const group of Object.keys(counters)) {
        Object.entries(draft[group] || {}).forEach(([key, row]) => addRow(group, row, key));
        form.querySelector(`[data-add-row="${group}"]`).addEventListener('click', () => {
            const row = addRow(group); row?.querySelector('input, select')?.focus();
        });
    }
    function refreshSummary() {
        form.querySelectorAll('[data-summary]').forEach(output => {
            const input = document.getElementById(output.dataset.summary);
            let value = input.value;
            if (input.tagName === 'SELECT') value = value ? input.selectedOptions[0].textContent : '';
            if (input.id === 'createPrice' && value) value = Number(value).toLocaleString('ko-KR') + '원';
            output.textContent = value || '-';
        });
        const brand = document.getElementById('createBrand');
        document.getElementById('createSummaryTitle').textContent = [brand.value ? brand.selectedOptions[0].textContent : '', document.getElementById('createName').value].filter(Boolean).join(' ') || '새 차량 등록';
    }
    form.addEventListener('input', refreshSummary);
    form.addEventListener('change', refreshSummary);
    refreshSummary();
})();
