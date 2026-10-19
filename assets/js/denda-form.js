document.querySelectorAll('.denda-auto-fields').forEach(function (fields) {
    const form = fields.closest('form');
    const select = form.querySelector('[name="angsuran_id"]');
    const amount = form.querySelector('[name="jumlah"]');
    const data = JSON.parse(fields.dataset.denda);
    function update() {
        const row = data[select.value];
        if (!row) return;
        fields.querySelectorAll('[data-denda-field]').forEach(function (input) {
            input.value = Number(row[input.dataset.dendaField]).toLocaleString('id-ID');
        });
        amount.value = row.sisa;
        amount.max = row.sisa;
    }
    select.addEventListener('change', update);
    update();
});
