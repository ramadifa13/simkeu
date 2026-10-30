<?php
/**
 * Modal perbesar dokumen + validasi sisi klien untuk input dokumen anggota.
 * Sertakan SEKALI per halaman, setelah bootstrap.bundle.js.
 */
?>
<style>
.thumb-dokumen { max-height: 110px; cursor: zoom-in; object-fit: cover; }
</style>
<div class="modal fade" id="modalDokumen" tabindex="-1">
    <div class="modal-dialog modal-xl modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modalDokumenJudul">Dokumen</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <img id="modalDokumenGambar" src="" alt="" class="img-fluid">
            </div>
            <div class="modal-footer">
                <a id="modalDokumenBuka" href="#" target="_blank" rel="noopener" class="btn btn-outline-secondary">
                    <i class="ti ti-external-link me-1"></i> Buka di tab baru
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="modalDokumenAnggota" tabindex="-1">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><i class="ti ti-id me-2"></i>Dokumen <span id="modalDokumenAnggotaNama"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3 text-center" id="modalDokumenAnggotaIsi"></div>
            </div>
        </div>
    </div>
</div>
<script>
    var DOKUMEN_LABEL_SEMUA = <?php echo json_encode(daftarDokumenAnggota()); ?>;

    /** Tampilkan KTP, KK & KTP suami/istri seorang anggota. docs = {field: url} */
    function lihatDokumenAnggota(nama, docs) {
        document.getElementById('modalDokumenAnggotaNama').textContent = nama;
        var isi = document.getElementById('modalDokumenAnggotaIsi');
        isi.innerHTML = '';
        Object.keys(DOKUMEN_LABEL_SEMUA).forEach(function (field) {
            var label = DOKUMEN_LABEL_SEMUA[field];
            var col = document.createElement('div');
            col.className = 'col-md-4';
            if (docs[field]) {
                var img = document.createElement('img');
                img.src = docs[field];
                img.alt = label;
                img.className = 'img-thumbnail w-100';
                img.style.cssText = 'height:180px;object-fit:cover;cursor:zoom-in';
                img.onerror = function () {
                    col.innerHTML = '<div class="border rounded d-flex align-items-center justify-content-center text-muted px-2 text-center" style="height:180px"><span class="small">File tidak ditemukan di server<br><span class="text-secondary">Unggah ulang atau pastikan uploads/ utuh setelah reset</span></span></div><div class="small mt-1 fw-semibold"></div>';
                    col.lastChild.textContent = label;
                };
                img.onclick = function () {
                    bootstrap.Modal.getInstance(document.getElementById('modalDokumenAnggota')).hide();
                    lihatDokumen(docs[field], label + ' - ' + nama);
                };
                col.appendChild(img);
            } else {
                col.innerHTML = '<div class="border rounded d-flex align-items-center justify-content-center text-muted" style="height:180px">Belum ada</div>';
            }
            col.insertAdjacentHTML('beforeend', '<div class="small mt-1 fw-semibold"></div>');
            col.lastChild.textContent = label;
            isi.appendChild(col);
        });
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDokumenAnggota')).show();
    }

    var DOKUMEN_MAKS_BYTE = <?php echo DOKUMEN_MAKS_BYTE; ?>;
    var DOKUMEN_TIPE = ['image/jpeg', 'image/png', 'image/webp'];

    function lihatDokumen(url, label) {
        document.getElementById('modalDokumenJudul').textContent = label;
        document.getElementById('modalDokumenGambar').src = url;
        document.getElementById('modalDokumenGambar').alt = label;
        document.getElementById('modalDokumenBuka').href = url;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalDokumen')).show();
    }

    /** Isi pratinjau dokumen lama di form edit (modal). docs = {field: url} */
    function isiPreviewDokumen(prefix, docs, labels) {
        Object.keys(labels).forEach(function (field) {
            var box = document.getElementById(prefix + '_preview_' + field);
            if (!box) return;
            // Saat edit tidak perlu unggah ulang; pilih file hanya untuk mengganti
            var input = box.parentElement.querySelector('.input-dokumen');
            if (input) input.required = false;
            box.innerHTML = '';
            if (docs[field]) {
                var img = document.createElement('img');
                img.src = docs[field];
                img.alt = labels[field];
                img.className = 'img-thumbnail thumb-dokumen';
                img.onclick = function () { lihatDokumen(docs[field], labels[field]); };
                box.appendChild(img);
                box.insertAdjacentHTML('beforeend', '<div><small class="text-muted">File saat ini &mdash; pilih file baru untuk mengganti</small></div>');
            } else {
                box.innerHTML = '<small class="text-muted">Belum ada file</small>';
            }
        });
    }

    // Validasi klien: tipe & ukuran (server tetap memvalidasi ulang dengan finfo)
    document.addEventListener('change', function (e) {
        var input = e.target;
        if (!input.classList || !input.classList.contains('input-dokumen') || !input.files.length) return;
        var file = input.files[0];
        var label = input.getAttribute('data-label');
        if (DOKUMEN_TIPE.indexOf(file.type) === -1) {
            alert(label + ' harus berupa gambar JPG, PNG, atau WEBP.');
            input.value = '';
        } else if (file.size > DOKUMEN_MAKS_BYTE) {
            alert(label + ' melebihi 10 MB (' + (file.size / 1048576).toFixed(1) + ' MB).');
            input.value = '';
        }
    });
</script>
