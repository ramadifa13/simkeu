document.querySelectorAll('.denda-auto-fields').forEach(function (fields) {
    const form = fields.closest('form');
    const select = form.querySelector('[name="angsuran_id"]');
    const amount = form.querySelector('[name="jumlah"]');
    const data = JSON.parse(fields.dataset.denda);
    function update() {
        const row = data[select.value];
        if (!row) return;
        fields.querySelectorAll('[data-denda-field]').forEach(function (input) {
            const key = input.dataset.dendaField;
            if (key === 'tarif') {
                input.value = Number(row.tarif).toLocaleString('id-ID') + ' / ' + row.satuan + (row.satuan === 'minggu' ? ' (' + (Number(row.minggu) || 0) + ' minggu)' : '');
            } else {
                input.value = Number(row[key]).toLocaleString('id-ID');
            }
        });
        const remaining = Math.max(0, Number(row.sisa) || 0);
        amount.max = remaining;
        amount.value = remaining > 0 ? remaining : '';
        amount.disabled = remaining <= 0;
        amount.setCustomValidity(remaining > 0 ? '' : 'Tidak ada sisa denda pada angsuran ini.');
    }
    select.addEventListener('change', update);
    update();
});
