# Verifikasi baseline

Tanggal 22 Oktober 2026. Baseline source `6eab4d2fcbed51d831df6a913ebb04760f18bc35`.

| Pemeriksaan | Status | Batas kesimpulan |
|---|---|---|
| Snapshot source dari GitHub | Tersedia | Tree lengkap; commit lokal sama |
| Inventaris entrypoint, field POST, include, kandidat tabel | Dibuat otomatis | Regex, bukan parsing semantik atau bukti parity |
| Preview JavaScript legacy | 15 kasus lulus | Tabel contoh, residual, gadai tiga frekuensi, nominal gadai invalid, tahunan |
| PHP CLI lokal | Tidak tersedia | Test PHP belum dijalankan lokal |
| Tujuh test PHP tanpa database | Runner disiapkan | Belum dinyatakan lulus |
| Test integrasi layanan/notifikasi/SHU lama | Terblokir schema dasar dan runtime DB | Tidak ada hasil transaksi database yang dapat diklaim |
| UI/browser perrole | Belum dijalankan | Tidak ada klaim seluruh halaman responsif/akses benar |
| Laravel/React revamp | Belum diimplementasikan | Belum ada klaim kesetaraan lama–baru |

Percobaan pemasangan runtime melalui package manager lingkungan gagal karena pembatasan permission. Tidak ada perubahan runtime aplikasi produksi.

`tests/revamp/legacy-preview.test.cjs` menjalankan source JavaScript lama yang nyata lewat Node VM, dengan nilai harapan dari tabel test legacy. Ini hanya mengunci sebagian baseline. Tidak menggantikan test PHP, round-trip database, approval, role, kas, dokumen, atau end-to-end.

Test DB lama `tests/layanan_integration_test.php`, `tests/notifikasi_integration_test.php`, dan `tests/shu_test.php` harus dijalankan terpisah di database uji setelah schema/fixture tersedia. Test tersebut bergantung pada kondisi fixture; kegagalan tidak boleh disamarkan dengan melewati assertion. Akun, data pinjaman, saldo, dan periodenya harus disiapkan deterministik.
