# Koperasi Simpan Pinjam Sejahtera

Aplikasi koperasi simpan pinjam (PHP + MySQL) dengan 3 level login: Admin, Teller, Anggota.

## Kebutuhan

- PHP **7.0 atau lebih baru** dengan ekstensi `pdo_mysql` dan `fileinfo`
- MySQL / MariaDB
- Apache dengan `AllowOverride All` (agar `.htaccess` di folder upload berlaku), misalnya XAMPP

## Instalasi

1. Clone ke folder web server, misalnya `C:\xampp\htdocs\koperasi2`:
   ```bash
   git clone https://github.com/ganova/koperasi.git koperasi2
   ```
2. Buat database `koperasi_db`, lalu import struktur & data awal:
   ```bash
   mysql -u root -e "CREATE DATABASE koperasi_db"
   mysql -u root koperasi_db < koperasi_db.sql
   ```
3. Jalankan migrasi revisi **sekali saja**:
   ```bash
   mysql -u root koperasi_db < database/migrations/2026_10_revisi_koperasi.sql
   ```
   Untuk database yang sudah berisi data, **backup dulu** sebelum menjalankan migrasi.
   Lalu jalankan migrasi tambahan (penanda pelunasan untuk Bukti Pembayaran Angsuran), juga **sekali saja**:
   ```bash
   mysql -u root koperasi_db < database/migrations/2026_10b_cara_bayar_angsuran.sql
   ```
   Terakhir, migrasi **revisi tahap 2** (pencairan oleh Teller + foto nasabah, tanggal lunas, profil teller), juga **sekali saja**:
   ```bash
   mysql -u root koperasi_db < database/migrations/2026_10_revisi_tahap2.sql
   ```
   Terakhir, migrasi **denda otomatis**, juga **sekali saja**:
   ```bash
   mysql -u root koperasi_db < database/migrations/2026_10c_denda.sql
   ```
   Dan migrasi **pengajuan pembatalan angsuran** (tahap 3), juga **sekali saja**:
   ```bash
   mysql -u root koperasi_db < database/migrations/2026_10_revisi_tahap3.sql
   ```
   Dan migrasi **input data lama / tanggal mundur**, juga **sekali saja**:
   ```bash
   mysql -u root koperasi_db < database/migrations/2026_10d_input_mundur.sql
   ```
   Dan migrasi **hari angsuran**, juga **sekali saja**:
   ```bash
   mysql -u root koperasi_db < database/migrations/2026_10e_hari_angsuran.sql
   ```
   Dan migrasi **gadai + tenor 6 bulan / 2 minggu sekali**, juga **sekali saja**:
   ```bash
   mysql -u root koperasi_db < database/migrations/2026_10f_gadai_tenor_6_bulan.sql
   ```
   Dan migrasi **batas satu pinjaman per kategori + kode anggota gadai AGTJ**, juga **sekali saja**:
   ```bash
   mysql -u root koperasi_db < database/migrations/2026_10g_batas_pinjaman_kode_gadai.sql
   ```
   Dan migrasi **foto pencairan gadai**, juga **sekali saja**:
   ```bash
   mysql -u root koperasi_db < database/migrations/2026_10h_foto_pencairan_gadai.sql
   ```
4. Sesuaikan koneksi database di `config/database.php` bila user/password MySQL berbeda.
5. Atur `php.ini` lalu restart Apache:
   ```ini
   upload_max_filesize = 10M
   post_max_size = 40M
   extension=fileinfo
   ```
6. Pastikan folder `uploads/anggota/` bisa ditulis oleh web server.
7. Buka `http://localhost/koperasi2/`.

## Jika muncul pesan merah "Database belum di-update"

Berarti langkah 3 (file migrasi) belum dijalankan pada database yang dipakai. Tanpa migrasi,
muncul error seperti `Undefined index: frekuensi_diizinkan` / `foto_kk` dan dokumen anggota
tidak tersimpan. Jalankan migrasinya sekali (bisa juga lewat phpMyAdmin → database
`koperasi_db` → tab **Import** → pilih `database/migrations/2026_10_revisi_koperasi.sql`).

## Setelah instalasi

- Jenis pinjaman lama (Umum, Pendidikan, Usaha, Mendesak) perlu diisi **tarif bunga flat per tenor** di menu Admin → Jenis Pinjaman sebelum bisa dipakai di form pengajuan.
- Anggota baru yang ditambahkan Teller mendapat akun login dengan password awal `123456`.

## Pembatalan pembayaran angsuran

Teller **tidak** membatalkan sendiri: pada angsuran Lunas yang dibayar **hari ini** (pembayaran hari sebelumnya tidak bisa diajukan) ia menekan *Ajukan Pembatalan* (alasan wajib). Pembayaran
belum dibalik sampai Admin memutuskan di halaman Pembayaran Angsuran: *Approve* (pembayaran dibatalkan, kas/saldo
dikoreksi) atau *Tolak* (angsuran tetap Lunas). Admin juga masih bisa *Batalkan Pembayaran* langsung.

## Denda keterlambatan

Denda nominal **per hari** menurut besar pinjaman (default: Rp 1 jt = 1.500, Rp 2 jt = 2.500, Rp 3 jt = 3.500;
bisa diatur Admin di Pengaturan Pinjaman). Dihitung mulai sehari setelah jatuh tempo sampai jatuh tempo cicilan
berikutnya, otomatis sekali sehari. Saat pembayaran angka denda bisa diubah manual (denda manual tidak ditimpa).

## Alur pinjaman (sejak tahap 2)

`Menunggu` (Teller/Admin buat pengajuan) → `Disetujui, belum dicairkan` (Admin) → **Teller** menekan
*Proses Pencairan* di halaman Status Pencairan dan wajib mengunggah foto nasabah → `Aktif`
(jadwal angsuran dibuat saat pencairan; jatuh tempo dihitung dari **tanggal pencairan** yang diisi Teller. Untuk data
lama isi tanggal lampau dan "sudah dibayar sampai cicilan ke-N"; cicilan itu Lunas tanpa masuk kas, sisanya yang telat kena denda) → `Lunas` (bisa cetak Bukti Pelunasan).
Foto nasabah juga menjadi foto profil anggota dan foto di sidebar anggota.

## Catatan revisi Oktober 2026

- Rumus bunga & angsuran ada di satu tempat: `includes/pinjaman_helper.php` (PHP) dan `assets/js/pinjaman.js` (preview form).
- Pinjaman sebelum migrasi tetap memakai skema bunga lama (`skema_bunga = 'tahunan'`); pinjaman baru memakai bunga flat per tenor.
- Dokumen anggota (KTP, KK, KTP suami/istri) **wajib** dan disimpan di `uploads/anggota/{id_anggota}/`. Daftar dokumen wajib diatur di `DOKUMEN_ANGGOTA_WAJIB` (`includes/dokumen_anggota.php`).
