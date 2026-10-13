(() => {
    const data = document.getElementById('estimateVehicleCatalog');
    if (!data) return;
    const catalog = JSON.parse(data.textContent);
    const brand = document.getElementById('edit-brand_id');
    const vehicle = document.getElementById('edit-vehicle_id');
    const trim = document.getElementById('edit-trim_id');
    const color = document.getElementById('edit-color_id');
    const original = {
        brand: brand.value,
        vehicle: vehicle.value,
        vehicleName: vehicle.selectedOptions[0]?.textContent || '',
        trim: trim.value,
        trimName: trim.selectedOptions[0]?.textContent || '',
        color: color.value,
        colorName: color.selectedOptions[0]?.textContent || '',
    };
    function setOptions(select, rows, placeholder, selected = '') {
        select.replaceChildren(new Option(placeholder, ''));
        rows.forEach(row => select.add(new Option(row.name, String(row.id))));
        select.value = selected;
        if (select.selectedIndex < 0) select.value = '';
    }
    function updateDetails() {
        const restoring = vehicle.value === original.vehicle;
        [['trims', trim, 'trim'], ['colors', color, 'color']].forEach(([key, select, kind]) => {
            const rows = catalog[key].filter(row => String(row.vehicle_id) === vehicle.value);
            if (restoring && original[kind] && !rows.some(row => String(row.id) === original[kind])) {
                rows.push({ id: original[kind], name: original[`${kind}Name`] });
            }
            setOptions(select, rows, '미정', restoring ? original[kind] : '');
        });
    }
    brand.addEventListener('change', () => {
        const rows = catalog.vehicles.filter(row => String(row.brand_id) === brand.value);
        if (brand.value === original.brand && !rows.some(row => String(row.id) === original.vehicle)) {
            rows.push({ id: original.vehicle, name: original.vehicleName });
        }
        setOptions(vehicle, rows, '차량 선택', brand.value === original.brand ? original.vehicle : '');
        updateDetails();
    });
    vehicle.addEventListener('change', updateDetails);
})();
