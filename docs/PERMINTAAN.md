# Catatan Permintaan Revisi

Penomoran: **Permintaan 1** = `prompt-revisi-koperasi.md` (Revisi 1–6). **Permintaan 2** = kiriman klien
berikutnya (screenshot level admin + contoh slip bukti angsuran). Permintaan yang di luar isi Permintaan 1
ditandai **[di luar cakupan P1]**.

## Permintaan 1 — `prompt-revisi-koperasi.md`

| # | Isi | Status | Commit |
|---|---|---|---|
| R1 | Nominal pinjaman bebas (tidak harus bulat) | Selesai | a53e8d3 |
| R2 | Bunga flat per tenor (tarif per jenis pinjaman), batas bunga sampai 1000% | Selesai | 7348412 |
| R3 | Angsuran mingguan/bulanan + jadwal otomatis | Selesai | 7348412 |
| R4 | Upload KTP, KK, KTP suami/istri | Selesai, kini **wajib** saat tambah anggota | 7c1340c, 0d4c8c6 |
| R5 | Slip pencairan A5 (tidak ada bagian yang dihapus, sesuai konfirmasi klien) | Selesai | 5dcdc0d |
| R6 | Jadwal angsuran di dashboard anggota | Selesai | 2e4fadf |

### Tindak lanjut Permintaan 1 (balasan & laporan uji klien via WA)

| Isi | Status | Commit |
|---|---|---|
| Dokumen anggota wajib | Selesai | 0d4c8c6 |
| Bug hapus anggota (gagal diam-diam, simpanan ikut terhapus) **[di luar cakupan P1]** | Selesai | 0e5f9b4 |
| Error karena database belum dimigrasi (banner peringatan, pesan jelas) | Selesai | 869a7b1 |
| Kompatibilitas MySQL strict mode (kode transaksi 20 karakter, tanggal kosong = NULL) **[di luar cakupan P1]** | Selesai | 869a7b1 |
| Tombol **Lihat** di daftar anggota menampilkan 3 dokumen | Selesai | 869a7b1 |
| Edit anggota tidak wajib upload ulang | Selesai | 869a7b1 |
| Email dihapus dari form Tambah Anggota (Teller) **[di luar cakupan P1]** | Selesai | 869a7b1 |
| Tambah Anggota (Teller) membuat akun login anggota sendiri (bug lama) **[di luar cakupan P1]** | Selesai | 72a3198 |
| Denda cicilan: nominal per hari menurut besar pinjaman (1 jt = 1.500, 2 jt = 2.500, 3 jt = 3.500), otomatis + bisa input manual **[di luar cakupan P1]** | Selesai (11 Okt 2026). Migrasi `2026_10c_denda.sql`; tarif bisa diatur Admin; pinjaman hanya kelipatan 1-3 jt (klien); denda dihitung sampai jatuh tempo berikutnya; uji: `php tests/denda_test.php` | d7e374b |
| Tabel angsuran resmi dari klien | **Diterima & diverifikasi** (11 Okt 2026): 9 kasus (Rp 1/2/3 jt × 1/2/3 bulan) dan lampiran 12 minggu cocok persis dengan rumus yang ada, tidak ada perubahan kode. Uji ulang: `php tests/tabel_angsuran_test.php`. Admin cukup mengisi tarif 1 bln = 15%, 2 bln = 25%, 3 bln = 35% dan maksimal pinjaman ≥ Rp 3 jt | tests/ |

## Permintaan 2 — screenshot level admin + contoh slip bukti angsuran

| # | Isi | Status | Commit |
|---|---|---|---|
| 2.1 | Tambah anggota (Admin): kode anggota otomatis **[di luar cakupan P1]** | Selesai | 9ec4046 |
| 2.2 | Tambah anggota (Admin): hapus field email **[di luar cakupan P1]** | Selesai | 9ec4046 |
| 2.3 | Edit anggota (Admin): tombol Update tidak terlihat | Selesai (modal terpotong, diperbaiki) | 9ec4046 |
| 2.4 | Slip **Bukti Pembayaran Angsuran** (jenis angsuran, nama & alamat anggota, lunas/pelunasan, terbilang, TTD Admin & Teller) **[di luar cakupan P1]** | Selesai; butuh migrasi `2026_10b_cara_bayar_angsuran.sql` | 9ec4046 |

Perlu konfirmasi klien untuk 2.4: arti "Terbilang 0" (sekarang = nominal dalam kata + sisa tagihan terpisah),
dan judul slip ("Bukti Pembayaran Angsuran" sesuai gambar vs "Bukti Angsuran Lunas" sesuai catatan).

## Permintaan 3 — `prompt-revisi-koperasi-tahap2.md` (revisi tahap 2)

Migrasi: `database/migrations/2026_10_revisi_tahap2.sql` (jalankan setelah migrasi sebelumnya).

| # | Isi | Status | Commit |
|---|---|---|---|
| A | Cetak Bukti Pelunasan (Admin & Teller; hanya bila semua cicilan lunas; tanggal lunas dicatat) | Selesai | 07d11b2 |
| B | Pisahkan persetujuan dan pencairan (jadwal angsuran dibuat saat Teller mencairkan) | Selesai | e42c403 |
| C | Foto nasabah wajib saat pencairan, bisa dilihat Admin & Teller | Selesai | e42c403 |
| D | Profil anggota read-only (foto pencairan, tanpa form & upload KTP) | Selesai | 4a5b41b |
| E | Sidebar anggota: foto nasabah + badge status Aktif/Nonaktif | Selesai | 4a5b41b |
| F | Teller: Edit Profil berfungsi (+telepon, foto 2 MB), Ubah Password (min. 8, backdoor dibuang), foto di sidebar | Selesai | 6af5774 |

Perubahan di luar poin: pinjaman otomatis ditandai lunas bila semua cicilan lunas (sebelumnya pinjaman skema lama
tertahan `aktif` karena sisa pembulatan 0,01); `teller/ubah_password.php` kini cek role; backdoor password
`'password'` dibuang.

## Permintaan 4 — masukan klien setelah uji tahap 2 (screenshot, 12 Okt 2026)

| # | Isi | Status |
|---|---|---|
| 4.1 | Hapus tanda "@" (Proses Pencairan, detail pengajuan Teller/Admin, slip pencairan): kini "Mingguan — 12× Rp 112.500" | Selesai |
| 4.2 | Bukti Pembayaran Angsuran: hanya kolom Teller + nama (kolom Admin dihapus) | Selesai |
| 4.3 | Bukti Pembayaran Angsuran pada pinjaman yang sudah lunas: jumlah angsuran/denda/total/terbilang diganti kotak hijau "LUNAS" versi mini | Selesai |
| 4.4 | Bukti Pelunasan: kolom tanda tangan Admin dan Teller (sebelumnya Anggota dan Teller) | Selesai |
| 4.5 | Level Anggota: semua fitur cetak/unduh dihapus (PDF angsuran, buku tabungan, SHU); URL lama 404 **[di luar cakupan P1]** | Selesai |
| 4.6 | Pinjaman Saya (Anggota): angsuran belum lunas dan lunas dipisah jadi dua tabel | Selesai |
| 4.7 | Pembayaran Angsuran (Admin): tombol "Batalkan Pembayaran" (koreksi salah pencet bayar) dengan alasan wajib, jejak di log_koreksi; hanya Admin **[di luar cakupan P1]** | Selesai |
| 4.8 | Teks modal persetujuan diperbarui (jadwal dibuat saat pencairan, bukan saat persetujuan) | Selesai |
| 4.9 | Laporan Harian (PDF, layar, Excel): kolom Tgl Bayar dan Jatuh Tempo; kolom Waktu diperbaiki memakai jam pembayaran (sebelumnya jam jadwal dibuat) | Selesai |

Tidak ada migrasi baru untuk Permintaan 4.

## Permintaan 5 — masukan klien (screenshot, 12 Okt 2026, lanjutan)

| # | Isi | Status |
|---|---|---|
| 5.1 | Level Teller: tombol **Batalkan Pembayaran** seperti level Admin (modal + alasan wajib). Pembatasan keputusan saya: Teller hanya untuk pembayaran yang dicatat **hari ini**; koreksi lebih lama oleh Admin | Selesai |
| 5.2 | Jatuh tempo angsuran mengikuti **tanggal pengajuan/pendaftaran** (bukan tanggal pencairan). Tanggal pencairan tetap hari pencairan. Bertentangan dengan brief tahap 2 (jatuh tempo dari tanggal pencairan); bisa dikembalikan lewat `JADWAL_DARI_PENGAJUAN` di `includes/pinjaman_helper.php` | Selesai |

Catatan 5.2: bila pengajuan didaftarkan jauh sebelum pencairan, sebagian cicilan langsung terlambat dan kena denda
otomatis (contoh uji: daftar 9/9, cair 12/10 -> 4 cicilan terlambat, denda Rp 39.000 pada hari pencairan).

**Pertanyaan terbuka 5.2 (12 Okt 2026):** klien membantah dugaan bahwa ia mengubah tanggal komputer; ia mengaku
mengisi kolom "tanggal pendaftaran" di form. Di aplikasi, kolom itu hanya ada sebagai **Tanggal Daftar anggota**
(Tambah Anggota). Form pengajuan tidak punya kolom tanggal, sehingga implementasi saat ini (tanggal pengajuan =
waktu sekarang) tidak mengubah hasil tesnya. Menunggu keputusan klien: (1) pakai tanggal daftar anggota,
(2) tambah kolom Tanggal Pengajuan di form pengajuan (rekomendasi), atau (3) kembali ke tanggal pencairan.
Poin 5.1 (batas Teller hanya hari ini) juga masih dicek klien.

## Permintaan 6 — `prompt-revisi-koperasi-tahap3.md` (revisi tahap 3)

Migrasi: `database/migrations/2026_10_revisi_tahap3.sql`.

| # | Isi | Status |
|---|---|---|
| 1 | Pengajuan Pembatalan Pembayaran Angsuran (Teller -> Admin): Teller mengajukan dengan alasan wajib, pembayaran belum dibalik; Admin Approve (memakai fungsi batalkan yang sama) / Tolak (catatan opsional) / Cetak; riwayat di tabel `pengajuan_pembatalan_angsuran`; badge keputusan untuk Teller; penanda jumlah menunggu di halaman Admin + menu samping. **Menggantikan** batalkan langsung Teller (hari ini saja) dari 5.1: Teller kini tidak bisa membatalkan sendiri. Tombol batalkan langsung Admin dipertahankan | Selesai |
| 2 | Tambah Anggota Teller disamakan dengan Admin: kode otomatis (fungsi yang sama) di modal Data Anggota (sebelumnya manual); Tanggal Daftar edit read-only (sebelumnya tampak bisa diubah tapi tidak tersimpan); No. KTP wajib & unik; kode dikunci saat simpan (uji 6 simpan bersamaan -> 6 kode unik) | Selesai |

Perbedaan form Admin vs Teller yang ditemukan (poin 2): kode manual di modal Teller; Tanggal Daftar edit read-only di Admin tapi
editable (tidak tersimpan) di Teller; cek KTP wajib/unik hanya di Admin.

## Permintaan 7 — input data lama (tanggal mundur) + penyempurnaan pembatalan (13 Okt 2026) [di luar cakupan P1]

Jawaban klien atas pertanyaan 5.2: jatuh tempo mengikuti **tanggal cair**, tanggal pengajuan dan pencairan harus bisa diisi
(data nasabah lama, mis. pengajuan 9/9/2026) supaya tidak dicatat di Excel lagi. Disetujui klien: cicilan lama yang sudah dibayar
ditandai lunas tanpa masuk kas, denda cicilan yang belum dibayar tetap berjalan dari jatuh tempo asli, foto nasabah tetap **wajib**.

| # | Isi | Status |
|---|---|---|
| 7.1 | Kolom Tanggal Pengajuan (form pengajuan Teller) dan Tanggal Pencairan (Proses Pencairan); default hari ini, tidak boleh masa depan, pencairan tidak boleh sebelum pengajuan. Jatuh tempo dari tanggal pencairan (`JADWAL_DARI_PENGAJUAN` = false; **menggantikan 5.2**) | Selesai |
| 7.2 | "Sudah dibayar sampai cicilan ke-N" (muncul bila tanggal pencairan lampau): cicilan 1..N Lunas, tanggal bayar = jatuh tempo, tanpa transaksi/kas, sisa pokok berkurang; sisanya yang lewat jatuh tempo otomatis telat + denda dari jatuh tempo asli. Penanda `pinjaman.input_mundur`, `cicilan_dimuka` (migrasi `2026_10d_input_mundur.sql`). Uji: 1 jt mingguan cair 9/9, dibayar sampai ke-3, dicek 13/10 -> ke-4 telat 6 hari, denda Rp 9.000 | Selesai |
| 7.3 | Teller hanya bisa **mengajukan pembatalan untuk pembayaran hari ini** (tombol hilang untuk hari sebelumnya, dicek juga di server) | Selesai |
| 7.4 | Status pembatalan di daftar angsuran: kuning = menunggu, hijau = Disetujui (Approve Admin), merah = Ditolak Admin. Menu Angsuran Teller: indikator hijau/merah + jumlah keputusan Admin yang belum dilihat (hilang setelah halaman Angsuran dibuka; kolom `dilihat_teller`) | Selesai |

## Permintaan 8 — Hari Angsuran pada pengajuan (15 Okt 2026) [di luar cakupan P1]

| # | Isi | Status |
|---|---|---|
| 8.1 | Isian wajib **Hari Angsuran** (Senin-Minggu) di form pengajuan Teller (`pengajuan_pinjaman.hari_angsuran`, migrasi `2026_10e_hari_angsuran.sql`); tampil di daftar pengajuan Admin dan Teller. Admin tidak membuat pengajuan, jadi isiannya hanya di form Teller | Selesai |
| 8.2 | Menu Angsuran (Admin & Teller): filter hari Semua/Senin..Minggu dengan jumlah per hari, digabung dengan filter pembatalan. Hari hanya kategori: jatuh tempo tetap dari tanggal pencairan | Selesai |

## Permintaan 9 — perbaikan kecil lanjutan tahap 3 (15 Okt 2026, berkas prompt)

| # | Isi | Status |
|---|---|---|
| 9.1 | Kode Anggota di form Edit (Admin & Teller) read-only; server mengabaikan kiriman form dan mengambil kode dari DB berdasarkan ID (uji: kode palsu tidak mengubah kode maupun username login) | Selesai |
| 9.2 | Field Email dihapus dari form Edit (Admin & Teller) dan dikeluarkan dari UPDATE (email lama tidak tertimpa; kolom DB tidak diubah). Form Tambah sudah tanpa email. Baris email di Daftar Anggota Teller dihapus; tabel cetak di `teller/tambah_anggota.php` masih punya kolom Email (tidak diubah) | Selesai |

## Hal bawaan (bukan permintaan klien)

Banner "Database belum di-update", README, repo GitHub, `sql_mode` per koneksi di `config/config.php`,
pesan error tidak lagi hilang otomatis, perbaikan INSERT `admin/pengajuan.php`.
Bug lama yang sebelumnya belum diubah (tabel `pelunasan_dini` dan pembatasan role
`admin/jenis_pinjaman.php`) diselesaikan pada Permintaan 10 di bawah.

## Permintaan 10 — gadai + tenor 6 bulan (17 Okt 2026)

Migrasi: `database/migrations/2026_10f_gadai_tenor_6_bulan.sql`.

Revisi lanjutan gadai: nilai taksiran dihapus dari form dan validasi. Kolom database lama
tetap disimpan dengan nilai 0 untuk kompatibilitas, sehingga revisi ini tidak memerlukan
migrasi tambahan selain `2026_10g_batas_pinjaman_kode_gadai.sql` untuk kode AGTJ.

| Isi | Status |
|---|---|
| Jenis pinjaman dapat ditandai wajib jaminan/gadai; pengajuan menyimpan jenis, identitas, kondisi, nilai taksiran barang | Selesai |
| Admin melihat barang gadai sebelum menyetujui; pengajuan tanpa data jaminan ditolak oleh server | Selesai |
| Tenor 6 bulan tanpa jaminan, 12 cicilan setiap 2 minggu | Selesai |
| Bug tabel `pelunasan_dini` tidak ada | Selesai dalam migrasi 10f |
| Bug master jenis pinjaman dapat dibuka selain Admin | Selesai |

### Revisi rumus Permintaan 10

- Gadai: nominal kelipatan Rp 1 juta; bunga dasar flat dari tarif tenor ditambah biaya per minggu.
- Biaya mingguan mengikuti pola: Rp 1 jt = Rp 10 rb, Rp 2 jt = Rp 15 rb, Rp 3 jt = Rp 20 rb, lalu naik Rp 5 rb untuk setiap tambahan Rp 1 jt.
- Jumlah minggu gadai = tenor bulan x 4.
- Gadai dapat dibayar mingguan, 2 minggu sekali, atau bulanan; tenor maksimal 6 bulan. Pilihan frekuensi mengubah jumlah/jarak cicilan, bukan total kembali.
- Produk 6 bulan **tanpa jaminan** hanya untuk Rp 2 jt dan Rp 3 jt, bunga total 45%, 12 cicilan setiap 14 hari.
- Konfirmasi klien: tabel 45% tidak berlaku untuk gadai. Contoh gadai Rp 2 jt, 2 bulan, 2 minggu sekali = pokok Rp 2 jt + 15% (Rp 300 rb) + Rp 15 rb x 8 minggu (Rp 120 rb) = Rp 2,42 jt / 4 cicilan = Rp 605 rb per cicilan.
- Koreksi pembulatan cicilan terakhir diperbolehkan selama total dan nominal pembayaran sesuai tabel.
- Untuk jenis gadai, Admin cukup mencentang opsi gadai: sistem otomatis membuat tenor 1–6 bulan dengan bunga dasar 15% dan mengaktifkan frekuensi mingguan, 2 mingguan, serta bulanan. Tenor/frekuensi dipilih saat Teller membuat pengajuan; Admin melihat pilihannya saat persetujuan.
- Form Teller untuk gadai otomatis mengisi nominal dari Maksimal Pinjaman (tetap editable), menampilkan ketiga frekuensi, dan menyembunyikan Hari Angsuran. Jenis non-gadai tetap mewajibkan Hari Angsuran.
- Form jaminan Teller menyediakan pilihan HP, sepeda motor, atau emas sebelum frekuensi. Masing-masing menampilkan field identitas khusus (HP: IMEI/kondisi; motor: nopol/rangka/mesin/BPKB; emas: berat/kadar/ciri/nota). Label frekuensi hanya Bulanan, Mingguan, dan 2 minggu sekali.
