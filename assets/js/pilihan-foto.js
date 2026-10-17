// Satu input asli tetap menangani upload, validasi required, dan pratinjau.
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('input[type="file"][accept]').forEach(function (input) {
        if (!/image\//i.test(input.accept)) return;
        input.removeAttribute('capture');

        var pilihan = document.createElement('div');
        pilihan.className = 'd-flex flex-wrap gap-2 mb-2';
        var label = input.getAttribute('data-label');
        if (!label) {
            var wadah = input.closest('.mb-3, .col-md-4');
            var labelElement = wadah && wadah.querySelector('label');
            label = labelElement ? labelElement.textContent.trim() : 'foto';
        }

        function tambahTombol(teks, kamera) {
            var tombol = document.createElement('button');
            tombol.type = 'button';
            tombol.className = 'btn btn-outline-primary btn-sm';
            tombol.textContent = teks;
            tombol.setAttribute('aria-label', teks + ': ' + label);
            tombol.addEventListener('click', function () {
                if (input.disabled) return;
                if (kamera) input.setAttribute('capture', 'environment');
                else input.removeAttribute('capture');
                input.click();
            });
            pilihan.appendChild(tombol);
        }

        tambahTombol('Ambil Foto', true);
        tambahTombol('Pilih dari Galeri', false);
        input.parentNode.insertBefore(pilihan, input);
        // Klik langsung pada input bawaan selalu menawarkan pemilihan berkas.
        input.addEventListener('click', function (event) {
            if (event.isTrusted) input.removeAttribute('capture');
        });
        input.addEventListener('change', function () {
            input.removeAttribute('capture');
        });
        input.form && input.form.addEventListener('reset', function () {
            input.removeAttribute('capture');
        });
    });
});
