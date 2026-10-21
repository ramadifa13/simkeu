// Satu input asli tetap menangani upload, validasi required, dan pratinjau.
// Semua foto dikompres di browser terlebih dahulu agar ukuran upload <= 2 MB.
document.addEventListener('DOMContentLoaded', function () {
    var BATAS_FOTO = 2 * 1024 * 1024;
    var TARGET_FOTO = Math.floor(BATAS_FOTO * 0.94);

    function kompresSatuFoto(file) {
        if (!file || !/^image\//i.test(file.type) || file.size <= TARGET_FOTO) {
            return Promise.resolve(file);
        }
        return new Promise(function (resolve, reject) {
            var img = new Image();
            var url = URL.createObjectURL(file);
            img.onload = function () {
                URL.revokeObjectURL(url);
                var canvas = document.createElement('canvas');
                var sisi = Math.max(img.width, img.height);
                var kualitas = 0.86;
                function coba() {
                    var skala = Math.min(1, 2400 / sisi);
                    canvas.width = Math.max(1, Math.round(img.width * skala));
                    canvas.height = Math.max(1, Math.round(img.height * skala));
                    var ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob(function (blob) {
                        if (!blob) return reject(new Error('Browser tidak dapat memproses foto.'));
                        if (blob.size <= TARGET_FOTO || kualitas <= 0.45) {
                            resolve(new File([blob], (file.name || 'foto').replace(/\.[^.]+$/, '') + '.jpg', {type: 'image/jpeg', lastModified: Date.now()}));
                            return;
                        }
                        kualitas -= 0.08;
                        if (kualitas < 0.55) sisi = Math.max(1200, sisi * 0.8);
                        coba();
                    }, 'image/jpeg', kualitas);
                }
                coba();
            };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('Foto tidak dapat dibaca.')); };
            img.src = url;
        });
    }

    async function kompresInput(input) {
        if (!input.files || !input.files.length) return;
        var files = [];
        for (var i = 0; i < input.files.length; i++) files.push(await kompresSatuFoto(input.files[i]));
        var changed = files.some(function (file, i) { return file !== input.files[i]; });
        if (changed && window.DataTransfer) {
            var transfer = new DataTransfer();
            files.forEach(function (file) { transfer.items.add(file); });
            input.files = transfer.files;
        }
    }

    document.querySelectorAll('form').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            if (form.dataset.mengompresFoto === '1') { event.preventDefault(); return; }
            var inputs = Array.from(form.querySelectorAll('input[type="file"]')).filter(function (input) {
                return /image\//i.test(input.accept || '') || Array.from(input.files || []).some(function (file) { return /^image\//i.test(file.type); });
            });
            if (!inputs.length) return;
            event.preventDefault();
            form.dataset.mengompresFoto = '1';
            try {
                for (var i = 0; i < inputs.length; i++) await kompresInput(inputs[i]);
                HTMLFormElement.prototype.submit.call(form);
            } catch (error) {
                delete form.dataset.mengompresFoto;
                alert(error.message || 'Foto gagal diproses. Silakan pilih foto lain.');
            }
        });
    });

    document.querySelectorAll('input[type="file"][accept]').forEach(function (input) {
        if (!/image\//i.test(input.accept)) return;
        input.removeAttribute('capture');

        var pilihan = document.createElement('div');
        pilihan.className = 'foto-upload-actions d-flex flex-wrap gap-2 mb-2';
        var status = document.createElement('div');
        status.className = 'foto-upload-status';
        status.textContent = 'Belum ada foto dipilih';
        input.classList.add('foto-upload-native');
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
        input.parentNode.insertBefore(status, input.nextSibling);
        // Klik langsung pada input bawaan selalu menawarkan pemilihan berkas.
        input.addEventListener('click', function (event) {
            if (event.isTrusted) input.removeAttribute('capture');
        });
        input.addEventListener('change', function () {
            input.removeAttribute('capture');
            if (!input.files || !input.files.length) {
                status.textContent = 'Belum ada foto dipilih';
                status.classList.remove('has-file');
                return;
            }
            var file = input.files[0];
            var ukuran = file.size >= 1048576 ? (file.size / 1048576).toFixed(1) + ' MB' : Math.max(1, Math.round(file.size / 1024)) + ' KB';
            status.textContent = file.name + ' · ' + ukuran;
            status.classList.add('has-file');
        });
        input.form && input.form.addEventListener('reset', function () {
            input.removeAttribute('capture');
        });
    });
});
