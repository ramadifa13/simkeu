// Satu input asli tetap menangani upload, validasi required, dan pratinjau.
// Semua foto dikompres di browser terlebih dahulu agar ukuran upload <= 1 MB.
(function () {
    var BATAS_FOTO = 1024 * 1024;
    // Sisakan ruang agar hasil encoder tidak melewati batas server.
    var TARGET_FOTO = Math.floor(BATAS_FOTO * 0.92);
    // Batas aman memori browser saat memproses foto kamera mentah (sebelum kompres).
    var ASAL_MAKS_BYTE = 40 * 1024 * 1024;

    function formatUkuran(byte) {
        if (byte >= 1048576) return (byte / 1048576).toFixed(2) + ' MB';
        return Math.max(1, Math.round(byte / 1024)) + ' KB';
    }

    function setInputFiles(input, files) {
        if (!window.DataTransfer) return false;
        var transfer = new DataTransfer();
        files.forEach(function (file) {
            if (file) transfer.items.add(file);
        });
        input.files = transfer.files;
        return true;
    }

    function kompresSatuFoto(file) {
        if (!file || !/^image\//i.test(file.type)) {
            return Promise.resolve(file);
        }
        if (file.size > ASAL_MAKS_BYTE) {
            return Promise.reject(new Error(
                'Foto terlalu besar untuk diproses di perangkat (' + formatUkuran(file.size)
                + '). Pilih foto di bawah 40 MB.'
            ));
        }
        // Sudah ≤ 1 MB — tidak perlu dikompres lagi di browser.
        if (file.size <= BATAS_FOTO) {
            return Promise.resolve(file);
        }
        return new Promise(function (resolve, reject) {
            var img = new Image();
            var url = URL.createObjectURL(file);
            img.onload = function () {
                URL.revokeObjectURL(url);
                var canvas = document.createElement('canvas');
                var sisi = Math.max(img.width || 1, img.height || 1);
                var kualitas = 0.84;
                function coba() {
                    var skala = Math.min(1, 1920 / sisi);
                    canvas.width = Math.max(1, Math.round((img.width || 1) * skala));
                    canvas.height = Math.max(1, Math.round((img.height || 1) * skala));
                    var ctx = canvas.getContext('2d');
                    ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                    canvas.toBlob(function (blob) {
                        if (!blob) return reject(new Error('Browser tidak dapat memproses foto.'));
                        if (blob.size <= TARGET_FOTO || (blob.size <= BATAS_FOTO && kualitas <= 0.45)) {
                            resolve(new File(
                                [blob],
                                (file.name || 'foto').replace(/\.[^.]+$/, '') + '-kompres.jpg',
                                { type: 'image/jpeg', lastModified: Date.now() }
                            ));
                            return;
                        }
                        if (blob.size > BATAS_FOTO && sisi <= 720 && kualitas <= 0.40) {
                            return reject(new Error('Foto tidak dapat dikompres hingga maksimal 1 MB. Pilih foto lain.'));
                        }
                        kualitas -= 0.08;
                        if (kualitas < 0.48 && sisi > 720) {
                            kualitas = 0.78;
                            sisi = Math.max(720, Math.round(sisi * 0.75));
                        }
                        if (sisi <= 720 && kualitas <= 0.38) {
                            return reject(new Error('Foto tidak dapat dikompres hingga maksimal 1 MB. Pilih foto lain.'));
                        }
                        coba();
                    }, 'image/jpeg', Math.max(0.35, kualitas));
                }
                coba();
            };
            img.onerror = function () {
                URL.revokeObjectURL(url);
                reject(new Error('Foto tidak dapat dibaca.'));
            };
            img.src = url;
        });
    }

    async function kompresInput(input) {
        if (!input || !input.files || !input.files.length) return;
        var files = [];
        for (var i = 0; i < input.files.length; i++) {
            files.push(await kompresSatuFoto(input.files[i]));
        }
        // Tolak bila masih ada yang > 1 MB
        for (var j = 0; j < files.length; j++) {
            if (files[j] && /^image\//i.test(files[j].type) && files[j].size > BATAS_FOTO) {
                throw new Error('Foto "' + (files[j].name || '') + '" masih melebihi 1 MB setelah dikompres.');
            }
        }
        var changed = files.some(function (file, i) { return file !== input.files[i]; });
        if (changed) setInputFiles(input, files);
    }

    async function kompresSemuaInputDiForm(form) {
        if (!form) return;
        var inputs = Array.from(form.querySelectorAll('input[type="file"]')).filter(function (input) {
            return /image\//i.test(input.accept || '')
                || Array.from(input.files || []).some(function (file) { return /^image\//i.test(file.type); });
        });
        for (var i = 0; i < inputs.length; i++) {
            await kompresInput(inputs[i]);
        }
    }

    window.SimkeuKompresFoto = {
        BATAS_FOTO: BATAS_FOTO,
        TARGET_FOTO: TARGET_FOTO,
        kompresSatuFoto: kompresSatuFoto,
        kompresInput: kompresInput,
        kompresSemuaInputDiForm: kompresSemuaInputDiForm
    };

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('form').forEach(function (form) {
            // Form AJAX (fetch) mengompres sendiri — jangan submit native lagi.
            if (form.dataset.ajaxUpload === '1' || form.id === 'formPencairan') return;
            form.addEventListener('submit', async function (event) {
                if (event.defaultPrevented) return;
                if (form.dataset.mengompresFoto === '1') {
                    event.preventDefault();
                    return;
                }
                var inputs = Array.from(form.querySelectorAll('input[type="file"]')).filter(function (input) {
                    return /image\//i.test(input.accept || '')
                        || Array.from(input.files || []).some(function (file) { return /^image\//i.test(file.type); });
                });
                if (!inputs.length) return;
                event.preventDefault();
                form.dataset.mengompresFoto = '1';
                try {
                    await kompresSemuaInputDiForm(form);
                    HTMLFormElement.prototype.submit.call(form);
                } catch (error) {
                    delete form.dataset.mengompresFoto;
                    alert(error.message || 'Foto gagal diproses. Silakan pilih foto lain.');
                }
            });
        });

        document.querySelectorAll('input[type="file"][accept]').forEach(function (input) {
            if (!/image\//i.test(input.accept)) return;
            if (input.dataset.fotoUiReady === '1') return;
            input.dataset.fotoUiReady = '1';
            input.removeAttribute('capture');

            var isMultiple = !!input.multiple;
            var maxFiles = parseInt(input.getAttribute('data-max-files') || (isMultiple ? '10' : '1'), 10) || (isMultiple ? 10 : 1);
            var keepFiles = [];

            var pilihan = document.createElement('div');
            pilihan.className = 'foto-upload-actions d-flex flex-wrap gap-2 mb-2';
            var status = document.createElement('div');
            status.className = 'foto-upload-status';
            status.textContent = isMultiple
                ? 'Belum ada foto dipilih (bisa pilih sampai ' + maxFiles + ')'
                : 'Belum ada foto dipilih';
            input.classList.add('foto-upload-native');
            if (isMultiple) {
                // Clip 1×1 sering membuat Android/iOS hanya izinkan 1 file.
                input.classList.add('foto-upload-native-multi');
            }

            var label = input.getAttribute('data-label');
            if (!label) {
                var wadah = input.closest('.mb-3, .col-md-4, .col-12, .ui-pc-upload, .ui-pj-upload');
                var labelElement = wadah && wadah.querySelector('label');
                label = labelElement ? labelElement.textContent.trim() : 'foto';
            }

            function updateStatus() {
                var n = input.files ? input.files.length : 0;
                if (!n) {
                    status.textContent = isMultiple
                        ? 'Belum ada foto dipilih (bisa pilih sampai ' + maxFiles + ')'
                        : 'Belum ada foto dipilih';
                    status.classList.remove('has-file');
                    return;
                }
                status.classList.add('has-file');
                if (n === 1) {
                    var file = input.files[0];
                    status.textContent = file.name + ' · ' + formatUkuran(file.size) + ' ✓';
                    return;
                }
                var total = 0;
                for (var i = 0; i < n; i++) total += input.files[i].size || 0;
                status.textContent = n + ' foto dipilih · ' + formatUkuran(total) + ' ✓'
                    + (n >= maxFiles ? ' (penuh)' : ' · bisa tambah lagi');
            }

            function gabungFiles(lama, baru) {
                var hasil = lama.slice();
                baru.forEach(function (file) {
                    if (hasil.length >= maxFiles) return;
                    hasil.push(file);
                });
                return hasil;
            }

            function buatTombol(teks, kelas) {
                var tombol = document.createElement('button');
                tombol.type = 'button';
                tombol.className = kelas || 'btn btn-outline-primary btn-sm';
                tombol.textContent = teks;
                tombol.setAttribute('aria-label', teks + ': ' + label);
                pilihan.appendChild(tombol);
                return tombol;
            }

            // Kamera: jangan set capture di input multiple (itu yang bikin cuma 1 foto).
            // Pakai input sementara, lalu tambahkan hasilnya ke input utama.
            var btnKamera = buatTombol(isMultiple ? 'Ambil Foto (+)' : 'Ambil Foto');
            btnKamera.addEventListener('click', function () {
                if (input.disabled) return;
                if (isMultiple && input.files && input.files.length >= maxFiles) {
                    alert('Maksimal ' + maxFiles + ' gambar.');
                    return;
                }
                var temp = document.createElement('input');
                temp.type = 'file';
                temp.accept = input.accept || 'image/*';
                temp.setAttribute('capture', 'environment');
                temp.style.cssText = 'position:fixed;left:-9999px;width:1px;height:1px;opacity:0;';
                document.body.appendChild(temp);
                temp.addEventListener('change', async function () {
                    try {
                        if (!temp.files || !temp.files.length) return;
                        status.textContent = 'Mengompres foto ke maksimal 1 MB…';
                        status.classList.add('has-file');
                        var lama = isMultiple ? Array.from(input.files || []) : [];
                        var calon = gabungFiles(lama, Array.from(temp.files));
                        if (!setInputFiles(input, calon)) {
                            // Fallback tanpa DataTransfer: isi input utama sekali
                            return;
                        }
                        await kompresInput(input);
                        if (input.files.length > maxFiles) {
                            setInputFiles(input, Array.from(input.files).slice(0, maxFiles));
                        }
                        updateStatus();
                    } catch (error) {
                        if (!isMultiple) input.value = '';
                        updateStatus();
                        alert(error.message || 'Foto gagal dikompres di bawah 1 MB.');
                    } finally {
                        temp.remove();
                    }
                });
                temp.click();
            });

            var btnGaleri = buatTombol(isMultiple ? 'Pilih Beberapa dari Galeri' : 'Pilih dari Galeri');
            btnGaleri.addEventListener('click', function () {
                if (input.disabled) return;
                input.removeAttribute('capture');
                // Simpan yang sudah ada agar pilihan baru bisa digabung (multi).
                keepFiles = isMultiple ? Array.from(input.files || []) : [];
                input.click();
            });

            if (isMultiple) {
                var btnHapus = buatTombol('Hapus Semua', 'btn btn-outline-danger btn-sm');
                btnHapus.addEventListener('click', function () {
                    input.value = '';
                    keepFiles = [];
                    updateStatus();
                });
            }

            input.parentNode.insertBefore(pilihan, input);
            input.parentNode.insertBefore(status, input.nextSibling);

            input.addEventListener('click', function () {
                // Pastikan native picker (bukan kamera) saat user/label memicu input.
                input.removeAttribute('capture');
            });

            input.addEventListener('change', async function () {
                input.removeAttribute('capture');
                if (!input.files || !input.files.length) {
                    if (isMultiple && keepFiles.length) {
                        setInputFiles(input, keepFiles);
                    }
                    keepFiles = [];
                    updateStatus();
                    return;
                }
                status.textContent = 'Mengompres foto ke maksimal 1 MB…';
                status.classList.add('has-file');
                try {
                    var baru = Array.from(input.files);
                    var gabungan = isMultiple && keepFiles.length
                        ? gabungFiles(keepFiles, baru)
                        : baru;
                    if (gabungan.length > maxFiles) {
                        gabungan = gabungan.slice(0, maxFiles);
                        alert('Maksimal ' + maxFiles + ' gambar. Kelebihan diabaikan.');
                    }
                    if (isMultiple) setInputFiles(input, gabungan);
                    keepFiles = [];
                    await kompresInput(input);
                    updateStatus();
                } catch (error) {
                    input.value = '';
                    keepFiles = [];
                    updateStatus();
                    alert(error.message || 'Foto gagal dikompres di bawah 1 MB.');
                }
            });

            input.form && input.form.addEventListener('reset', function () {
                input.removeAttribute('capture');
                keepFiles = [];
                setTimeout(updateStatus, 0);
            });
        });
    });
})();
