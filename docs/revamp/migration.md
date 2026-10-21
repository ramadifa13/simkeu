# Migrasi bertahap dan pembuktian kesetaraan

## Urutan kerja

| Tahap | Deliverable | Syarat selesai |
|---|---|---|
| 0 — audit | DDL aktual, katalog setiap endpoint/action, matriks role, aturan/perhitungan, keputusan D-01–D-08 | Tidak ada asumsi pada bagian yang dimigrasikan; baseline legacy dapat dijalankan |
| 1 — fondasi | Laravel/React/Inertia/TypeScript/Tailwind/shadcn, shell, auth, policies, clock WIB, pipeline | Login legacy/status/role teruji; build sesuai hosting; tidak ada registrasi publik tambahan |
| 2 — master/anggota | Konfigurasi, profil, AGT/AGTJ, ketua, dokumen | CRUD dan larangan hapus berhistori sama; upload/download sesuai scope |
| 3 — simpanan/kas | Setoran/penarikan/koreksi dan rekonsiliasi | Keputusan saldo diselesaikan; kas dan saldo konsisten per transaksi dan concurrency |
| 4 — pinjaman | Pengajuan, approval, preview server, gadai, pencairan, perjanjian | Golden cases flat/tahunan/gadai/jadwal cocok; Teller-only; input mundur tanpa kas ganda |
| 5 — angsuran/layanan | Denda, bayar, pembatalan, lunas, reschedule, lelang, jaminan, prioritas, bonus | Hasil, side effect, proof dan akses sama; retry/parallel tests lulus |
| 6 — Waserda/SHU | Transaksi retail, hak SHU aktif, kuitansi | Tidak menggandakan hak/kas; keputusan SHU tahunan jelas |
| 7 — laporan/operasional | Semua cetak/export, backup/restore, notifikasi, cron | Total/filter/scope sama, template terbaca, restore rehearsal, WhatsApp fake transport lulus |
| 8 — pilot/cutover | Paket shared hosting, migration report, rollback rehearsal | Acceptance role bisnis, saldo final, bukti, dan histori cocok |

## Strategi data

1. Pin commit dan ambil backup DB + uploads secara terpisah; verifikasi restore ke staging. DDL saja untuk mulai audit, data uji anonim untuk pembandingan. Jangan mengunggah data operasional ke repository publik.
2. Catat engine/version/sql_mode/timezone/charset, seluruh index/FK/defaults, triggers/views/routines/events. Cocokkan dengan SQL yang dieksekusi dari halaman. Buat schema snapshot versioned yang tidak memuat data pribadi.
3. Gunakan schema legacy yang telah diverifikasi pada fase awal; pertahankan ID, kode, timestamps, status, nominal, metode, snapshot JSON, hash perjanjian dan path dokumen. Hindari perubahan schema besar sekaligus penggantian UI.
4. Import dry-run pada DB baru: hitung jumlah row, orphan, duplicate, saldo negatif/tanggal invalid, checksum berkas, total perjenis dan perperiode. Data yang gagal validasi masuk laporan pengecualian; tidak diperbaiki/dihapus otomatis.
5. **Jangan menghitung ulang jadwal pinjaman aktif atau historis** saat import. Bandingkan jadwal tersimpan; kalkulator baru hanya untuk transaksi baru setelah parity terbukti.
6. Rekonsiliasi `simpanan.saldo` vs signed sum transaksi peranggota/jenis; `kas.saldo_sesudah` terakhir vs signed sum kas; sisa pokok vs jadwal belum lunas; denda vs pembayaran terpisah; total SHU hak/bayar; lelang harga/terpakai/kredit/kelebihan/kembali. Laporkan selisih, bukan menyamarkannya sebagai saldo awal.
7. Shadow read/compute pada salinan atau snapshot; jangan dual-write ke database finansial produksi. Modul yang berbagi kas/SHU tidak boleh berganti writer terpisah tanpa desain transaksi lintas versi yang terbukti.
8. Saat cutover: maintenance tulis, hentikan cron legacy, backup final, migrasi delta terukur, rekonsiliasi final, smoke perrole, aktifkan satu writer dan satu scheduler.
9. Rollback: jika belum ada transaksi baru, kembali ke release dan backup sebelum cutover. Jika sudah ada transaksi baru, jangan restore backup lama secara buta; freeze, ekspor delta dan lampiran, rekonsiliasi balik atau forward-fix sesuai runbook. Catat siapa memutuskan dan hasil saldo.

## Matriks pengujian wajib

| Area | Kasus minimum | Pembandingan |
|---|---|---|
| Pinjaman | 1/2/3 juta; 15/25/35/45%; tenor 1/2/3/6; 3 frekuensi; tahunan; pecahan pembulatan | total, bunga, setiap baris pokok/bunga/jumlah, residual akhir |
| Kalender | 31 Jan, Feb kabisat, 30/31 bulan, lintas tahun, WIB 23:59/00:00 | seluruh tanggal jatuh tempo |
| Gadai | juta penuh, tidak valid, nominal besar dengan bunga efektif disimpan 2 desimal | preview → database → jadwal → perjanjian |
| Denda | H-1/H/H+1, batas cicilan berikutnya, terakhir, tarif kosong/bertingkat, manual/admin/teller | nominal, hari dihitung vs hari telat, penolakan |
| Akun | aktif/nonaktif, selesai satu dari dua pinjaman, pengajuan pending, pembatalan, historis semua lunas | status users/anggota dan akses sesi aktif |
| Pembayaran | klik ulang, dua Teller bersamaan, bukti invalid, denda terpisah, kredit lelang | efek DB sekali, rollback file, kas/ANG/sisa pokok |
| Pembatalan | hari sama/lalu, pending/ditolak/disetujui, pinjaman lunas, lelang/serah jaminan, SHU | scope dan semua efek; D-05 harus diputuskan |
| Pelunasan/lelang | diskon 0/100, approval berubah, kredit kurang/cukup/lebih, return surplus | kas tidak double count, alokasi tepat, bukti & audit |
| Simpanan/koreksi | saldo awal, setoran, penarikan, koreksi naik/turun, uang desimal, concurrent | balance source sesuai keputusan D-01/D-03 |
| SHU | anggota/ketua/referensi, telat satu hari, jaminan, pembayaran ganda, pembatalan setelah lunas | nominal/hak/kas dan snapshot kategori |
| Laporan | semua filter, pagination, ekspor, pembatalan/historis, antarrole/anggota | baris, total, period cutoffs, kepemilikan |
| Hosting/security | deploy public root, nonaktif debug, file privat, CSRF, IDOR, session, upload, cron overlap | akses terlarang ditolak; tidak bocor data |

Test harness legacy dan revamp berjalan pada salinan fixture identik dengan waktu WIB dibekukan. Untuk nilai uang bandingkan sesuai skala penyimpanan aslinya; selisih sekecil Rp0,01 harus dilaporkan, bukan diberi toleransi global. Bandingkan juga exception/penolakan dan seluruh row yang berubah, bukan nominal UI saja.

## Hosting acceptance

Konfirmasi PHP web dan CLI sama-sama sesuai dependency lock; ekstensi PDO MySQL, fileinfo, mbstring, OpenSSL, cURL, DOM/XML dan persyaratan framework tersedia. Build vendor/aset sebelum upload jika Composer/Node tidak ada di server. `storage`/cache writable tanpa permission 777. Limit upload menampung bukti/TTD/lampiran yang wajib. Cron menggunakan WIB dan mutex. Uji PDF dan backup di memory/time limit hosting aktual. Tidak menyatakan kompatibel shared hosting sebelum rehearsal di target.
