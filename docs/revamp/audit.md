# Audit statis SIMKEU

Baseline `6eab4d2fcbed51d831df6a913ebb04760f18bc35`. Temuan bersumber dari source, bukan pengamatan produksi. Referensi path menunjuk file pada baseline. Inventaris otomatis mencakup file aplikasi; pembacaan mendalam diprioritaskan pada auth, helper domain, migrasi, pencairan, approval, pembayaran, koreksi, simpanan, Waserda, SHU, dan cron. Review visual per halaman dan pengujian database belum selesai.

## Bentuk sistem lama

PHP prosedural dengan PDO MySQL; SQL, action handler, HTML, CSS, dan JavaScript banyak digabung dalam halaman. Tiga role login: `admin`, `teller`, `anggota`. Admin bukan otomatis berhak melakukan semua tindakan Teller: pencairan memeriksa `isTeller()` secara eksplisit.

`config/functions.php` menghubungkan pinjaman, dokumen anggota, akun, angsuran, denda, layanan, dan perjanjian. `includes/auth.php` memeriksa area URL, akun aktif/role terbaru, serta CSRF untuk POST pada area terlindungi; halaman masih memiliki guard tambahan. Izin efektif adalah gabungan guard tersebut, bukan sekadar item sidebar.

Dependensi teramati: Bootstrap 5.1.3, Font Awesome 6.0.0 dari CDN; TCPDF vendored dengan metadata versi 6.10.0; WhatsApp Cloud API melalui cURL; MySQL/MariaDB dengan SQL khusus (`GET_LOCK`, `FOR UPDATE`, `DATE_ADD`, `INSERT IGNORE`). Tidak ada manifest Composer/NPM tingkat aplikasi. README menyebut PHP 7.0+, tetapi source memakai fitur yang lebih baru (misalnya arrow function pada test dan `JSON_THROW_ON_ERROR`), sehingga klaim kompatibilitas harus diuji. `config/config.php` mengatur WIB, SQL non-strict, dan timezone DB +07:00; cron membuat koneksi terpisah tanpa pengaturan timezone DB yang sama.

## Modul dan ketergantungan

| Modul | Source utama | Ketergantungan dan efek |
|---|---|---|
| Identitas, akun, anggota | `login_process.php`, `includes/auth.php`, `includes/anggota_helper.php`, halaman anggota Admin/Teller | users ↔ anggota, status akun, AGT/AGTJ, dokumen wajib, ketua kelompok; penghapusan bergantung histori |
| Master dan konfigurasi | `admin/jenis_pinjaman.php`, `jenis_simpanan.php`, `pengaturan*.php`, `teller.php` | tarif tenor, frekuensi, jaminan, denda, identitas koperasi, template dan TTD Admin |
| Simpanan | `admin/setoran.php`, `penarikan.php`, `get_saldo.php` dan pasangan Teller | transaksi + simpanan + kas; sumber saldo berbeda antarrole |
| Pengajuan/approval | `admin/pengajuan.php`, `persetujuan.php`, `teller/pengajuan.php`, `teller/persetujuan.php` | anggota, tarif, jaminan, profil SHU, prioritas; approval belum membuat jadwal |
| Pencairan/perjanjian | `teller/pencairan.php`, `includes/pinjaman_helper.php`, `perjanjian_helper.php` | pinjaman, jadwal, foto, 5 TTD, saksi, snapshot perjanjian, cicilan historis |
| Angsuran/denda/koreksi | `admin/angsuran.php`, `teller/angsuran.php`, `includes/angsuran_helper.php`, `denda_helper.php`, `admin/koreksi.php` | kas, saldo ANG, sisa pokok, pengajuan pembatalan, reaktivasi anggota |
| Layanan pinjaman | `includes/layanan_actions.php`, `layanan_lanjutan.php`, `layanan_helper.php` | pelunasan dini, penghapusan denda, reschedule, macet, jaminan, lelang, prioritas, bonus, repeat order |
| Waserda | `admin/pembelian.php`, `teller/pembelian.php`, `anggota/waserda.php` | items JSON, penjualan, kas, histori anggota; Admin memiliki penghapusan |
| SHU pinjaman aktif | `includes/shu_page.php`, `shu_pinjaman_helper.php`, `anggota/shu_saya.php` | hak pelunasan/referensi, kategori snapshot, pembayaran Admin, kas, bukti |
| SHU tahunan tersisa | `includes/shu_helper.php`, `tests/shu_test.php` | periode, kontribusi, RAT, pembayaran dan pembalikan; pemanggilan `shuProses()` ditemukan pada test, bukan handler halaman aktif |
| Laporan/dokumen | seluruh `laporan_*`, `cetak_*`, `export_*`, `kwitansi_*` | filter, scope role/anggota, saldo, snapshot, PDF/CSV/Excel; perlu parity per output |
| Operasional | `admin/backup.php`, `scripts/hitung_denda.php`, `cron/wa_jatuh_tempo.php`, `notifikasi.php` | backup/restore, otomatisasi denda, pengingat H-1, indikator pekerjaan perrole |

## Hak akses yang telah ditelusuri

Tabel ini kontrak awal untuk tindakan inti, bukan matriks final seluruh endpoint.

| Tindakan | Admin | Teller | Anggota | Bukti |
|---|---|---|---|---|
| Menyetujui/menolak pinjaman | Ya | Lihat status | Baca data sendiri | `admin/persetujuan.php`, `teller/persetujuan.php` |
| Proses pencairan | Ditolak pada jalur pencairan | Ya | Tidak | `admin/pencairan.php:52`, `teller/pencairan.php:8` |
| Pembayaran angsuran | Ya | Ya | Tidak | kedua `angsuran.php` |
| Mengubah ketetapan denda | Ya, tunduk pengaturan pemutihan | Tidak | Tidak | `dendaUntukPembayaran()` |
| Ajukan pembatalan | Koreksi langsung | Hari bayar yang sama; pinjaman belum lunas | Tidak | `ajukanPembatalanAngsuran()` |
| Putuskan pembatalan | Ya | Tidak | Tidak | `putuskanPembatalanAngsuran()` |
| Bayar denda/ajukan pelunasan | Ya | Ya | Tidak | `prosesLayanan()` |
| Diskon/reschedule/eksekusi/lelang/bonus | Ya | Tidak | Tidak | `layananAdmin()`, `prosesLayananLanjutan()` |
| Serahkan jaminan | Handler bersama mengizinkan | Handler bersama mengizinkan | Tidak | wajib approval, cek fisik, bukti dan TTD |
| Bayar SHU | Ya | Tidak | Lihat hak sendiri | `shuBayarHakPinjaman()`, `shu_page.php` |
| Hapus transaksi Waserda | Ya | Tidak ditemukan handler hapus | Tidak | `admin/pembelian.php` |

Scope data wajib diuji tersendiri: role, kepemilikan anggota, identitas Teller pengaju, status proses, dan perubahan status akun pada sesi aktif. Menyembunyikan tombol bukan kontrol akses.

## Rumus dan aturan yang harus dikunci

1. **Jumlah cicilan:** tenor bulan ×4 untuk mingguan, ×2 untuk dua mingguan, tenor untuk bulanan. Interval 7/14 hari; bulanan mempertahankan tanggal awal dan menjepit ke akhir bulan. Bukan empat minggu kalender yang dipaksakan menjadi satu bulan.
2. **Flat:** `total_bunga = round(pokok × persen / 100)`. Total dan pokok cicilan reguler dibulatkan sendiri; cicilan terakhir menanggung sisa masing-masing. Bunga per baris = jumlah − pokok.
3. **Tahunan legacy:** `bunga = pokok × persen / 100 × tenor / 12`; dibagi rata tanpa pembulatan rupiah pada helper. Kolom `skema_bunga` yang tidak ada pada row dianggap tahunan. Jangan mengonversi pinjaman lama menjadi flat atau membulatkan ulang jadwal tersimpan.
4. **Gadai:** pokok kelipatan Rp1 juta; biaya mingguan `5000 + (pokok/1000000 × 5000)`. Tambahan = biaya × tenor ×4; digabung bunga dasar yang dibulatkan menjadi bunga efektif dan dihitung melalui helper flat. Presisi bunga efektif yang disimpan pada `DECIMAL(6,2)` perlu diuji round-trip untuk nominal besar.
5. **Denda:** mulai sehari setelah jatuh tempo, dibatasi jatuh tempo cicilan berikutnya; cicilan terakhir tidak dibatasi. Tanpa jaminan: tarif tingkat terdekat di bawah pokok, minimum tingkat pertama; tabel kosong menghasilkan nol. Dengan jaminan: `max(1, floor(pokok/1jt)) × tarif_jaminan`, default 5000. Denda manual tidak ditimpa otomatis.
6. **Pencairan:** hanya Teller; tanggal tidak boleh melebihi hari ini atau mendahului pengajuan. Foto nasabah, TTD nasabah/Teller/Admin/dua saksi dan nama saksi wajib. Gadai memerlukan foto barang dan nasabah dengan barang. Maksimal 10 lampiran foto. Perjanjian menyimpan snapshot dan hash. Approval Admin belum membuat pinjaman/jadwal.
7. **Input historis:** cicilan 1..N lunas pada tanggal jatuh tempo, denda nol, sisa pokok berkurang, tanpa kas baru. Hanya untuk tanggal pencairan lampau. Urutan aktual memanggil penutupan pinjaman sebelum pengajuan berubah menjadi `dicairkan`; kasus semua cicilan historis lunas perlu diuji terkait penonaktifan akun.
8. **Pembayaran:** sisa denda harus dibayar terpisah dengan bukti terlebih dahulu. Cicilan kemudian memperhitungkan denda yang sudah dibayar dan kredit lelang. Seluruh cicilan lunas → pinjaman lunas, sisa pokok nol, catat SHU, kemungkinan akun nonaktif. Pinjaman lain atau pengajuan menunggu/disetujui mencegah penonaktifan.
9. **Pembatalan:** pengajuan Teller belum membalik kas. Admin menyetujui → transaksi pembayaran dinolkan, saldo ANG dikurangi, kas dan saldo kas berikutnya dikoreksi, log disimpan, cicilan kembali terutang, pokok bertambah, akun diaktifkan. Pinjaman dengan lelang, pelunasan layanan aktif, atau jaminan sudah diserahkan tidak boleh dibatalkan per cicilan.
10. **Pelunasan:** jumlah pokok belum lunas + bunga belum lunas − diskon bunga + max(0, denda − denda dibayar) − kredit lelang terpakai. Dibulatkan dua desimal; diskon hanya keputusan Admin. Pelunasan langsung menggunakan diskon approval yang sudah ada, atau nol. Sisa denda tetap wajib dibayar terpisah.
11. **Reschedule:** Admin menggeser semua jatuh tempo belum lunas sejumlah hari yang sama, mempertahankan denda terutang dan menandainya manual. Jangan diam-diam menghapus atau meneruskan perhitungan denda otomatis.
12. **Lelang:** keputusan eksekusi belum mengurangi utang. Penjualan mencatat kas seluruh harga; kredit maksimal sisa tagihan; kelebihan milik nasabah. Alokasi menyelesaikan cicilan urut jika kredit cukup melunasi satu cicilan; kredit kurang tersisa. Pengembalian kelebihan dicatat sebagai kas keluar.
13. **Prioritas:** dua pinjaman lunas terakhir tanpa keterlambatan, approval Admin, tanpa jaminan dan maksimal Rp1 juta. **Bonus:** pinjaman lunas tanpa jaminan, persentase pengaturan × bunga diterima setelah diskon, round 2; sekali per pinjaman.
14. **SHU pinjaman:** hanya tanpa jaminan dan lunas; anggota 1% pokok, ketua 2%. Referensi ketua 0,25% pokok hanya jika anggota biasa dan semua angsuran tepat waktu. Hak personal tidak mensyaratkan lancar. Dibulatkan rupiah `PHP_ROUND_HALF_UP`; snapshot kategori di pinjaman harus dipertahankan.
15. **SHU tahunan tersisa:** alokasi largest remainder, kontribusi saldo akhir/rata-rata harian, bunga dibayar setelah diskon, belanja Waserda. Total kebijakan 100%, pengesahan setelah tahun selesai dan hash belum berubah. Jangan menambahkan kembali menu tahunan tanpa memastikan status penggunaannya.
16. **Waserda:** total = Σ harga × jumlah; `tunai/transfer` berbeda label dari layanan `cash/transfer`. Jangan mengubah transaksi tersimpan saat menormalkan label UI.

Sumber rumus: `includes/pinjaman_helper.php`, `denda_helper.php`, `angsuran_helper.php`, `layanan_helper.php`, `layanan_actions.php`, `layanan_lanjutan.php`, `shu_pinjaman_helper.php`, `shu_helper.php`, `assets/js/pinjaman.js`.

## Database: diketahui vs belum diketahui

Relasi aplikasi inti yang terlihat di JOIN/INSERT: users → anggota; anggota → simpanan/transaksi/pengajuan/pinjaman/Waserda; jenis pinjaman → tarif/pengajuan/pinjaman; pengajuan → pinjaman/jaminan; pinjaman → angsuran/perjanjian/layanan/hak SHU; perjanjian → lampiran. Ini **belum membuktikan FK fisik** pada tabel awal.

Migrasi universal mendefinisikan FK eksplisit pada tarif pinjaman, jaminan, perjanjian/lampiran, pembatalan, dan sebagian SHU. Banyak tabel `layanan_*` hanya mempunyai index, sehingga orphan perlu diperiksa sebelum menambah constraint. Charset bercampur latin1/utf8mb4. Beberapa tabel dibuat dalam request (`waserda_transaksi`, `log_koreksi`, `wa_pengingat_log`); schema tidak boleh diturunkan dari file migrasi saja. Jenis/default/nullability/index/FK tabel dasar belum tersedia.

## Register keputusan: jangan diperbaiki dengan asumsi

| ID | Bukti dan dampak | Yang diperlukan |
|---|---|---|
| D-01 | `admin/setoran.php`, `penarikan.php` membaca saldo `simpanan`; Teller mengagregasi `transaksi`. Saldo dapat berbeda bila histori/koreksi tidak konsisten. | Rekonsiliasi anonim saldo awal + mutasi + saldo akhir; tetapkan hasil rujukan per kasus. |
| D-02 | Handler `teller/pencairan.php` dan `buatPinjamanDanJadwal()` tidak memasukkan kas keluar pencairan. | Pastikan apakah pendanaan dicatat di luar aplikasi atau ini bug. Jangan otomatis menambah kas keluar. |
| D-03 | `admin/koreksi.php` menambah selisih pada saldo tanpa membedakan arah penarikan; tidak memperbarui seluruh saldo berikutnya seperti pembatalan angsuran. | Contoh koreksi setoran dan penarikan; hasil yang disahkan sebelum mengganti algoritme. |
| D-04 | Waserda memakai `kas.transaksi_id = waserda_transaksi.id`, sementara modul lain memakai `transaksi.id`; schema kas tidak tersedia. Penghapusan Waserda tidak terlihat menghitung ulang saldo kas selanjutnya. | DDL kas, FK aktual, contoh ID bertabrakan dan hasil setelah hapus. |
| D-05 | `batalkanPembayaranAngsuran()` mengaktifkan kembali pinjaman tetapi tidak terlihat mencabut hak pada `shu_hak_pinjaman`. | Keputusan untuk hak belum dibayar/sudah dibayar setelah pembatalan cicilan terakhir. |
| D-06 | SHU tahunan punya helper/test/tabel, tetapi halaman aktif memakai hak pinjaman. | Konfirmasi fitur tahunan masih dipakai atau hanya kode tersisa; jangan membayar dua mekanisme secara otomatis. |
| D-07 | `hari_angsuran` disimpan pada pengajuan, tetapi helper jadwal menghitung dari tanggal pencairan. | Konfirmasi kategori hari hanya filter/label atau seharusnya mengubah jadwal; tetap ikuti source sampai diputuskan. |
| D-08 | Profil AGTJ awal dinormalisasi; satu anggota dapat memiliki kode biasa dan kode jaminan. | Preserve identitas/user_id, jangan pecah menjadi dua akun dalam migrasi. |

## Risiko teknis teramati

- Mutasi melalui GET, misalnya hapus Waserda, melewati proteksi CSRF yang hanya memeriksa POST. Revamp harus memakai metode mutasi dengan CSRF dan policy sambil mempertahankan kewenangan aksinya.
- Perubahan kas memakai strategi berbeda: SUM ledger atau saldo baris terakhir; lock mutex `koperasi.id=1` ada di sebagian jalur, tidak di Waserda. Uji transaksi bersamaan dan saldo awal kosong.
- Denda/notifikasi/SHU memiliki query per baris dan pemeriksaan metadata schema saat request; beberapa list memakai `fetchAll` tanpa pagination. Optimasi harus memakai fixture pembanding agar scope dan filter tidak berubah.
- Upload banyak bergantung pada folder publik dan `.htaccess`; dokumen KTP/KK/TTD harus dilayani dengan pemeriksaan hak akses pada rancangan baru. Verifikasi cetak/download anggota lain lewat manipulasi ID.
- Password awal `123456` disebut README. Perubahan onboarding/reset wajib disepakati agar hak/alur login lama tidak berubah diam-diam. Login memakai hash password dan regenerasi sesi; rate limiting belum terlihat pada handler yang dibaca.
- Mematikan strict SQL dapat menyembunyikan data tanggal/numerik tidak valid. Aktifkan validasi/import quarantine; jangan memotong nilai historis agar lolos migrasi.
- Cron WhatsApp mengirim sebelum menyimpan status; dua eksekusi paralel dapat mengirim ganda. Preserve template/parameter, tambahkan klaim job dan strategi retry yang diuji dengan transport palsu.

Temuan di atas merupakan review statis, belum hasil penetration test atau pengukuran performa produksi.
