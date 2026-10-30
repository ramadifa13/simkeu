# Koperasi Simpan Pinjam Sejahtera

Panduan urutan import untuk hosting tersedia di [MIGRASI_HOSTING.md](MIGRASI_HOSTING.md).

Aplikasi koperasi simpan pinjam (PHP + MySQL) dengan 3 level login: Admin, Teller, Anggota.

## Kebutuhan

- PHP **8.2 atau lebih baru** (disarankan; lokal sering 8.3/8.4) dengan ekstensi `pdo_mysql` dan `fileinfo`
- Lihat rencana revamp UI di [docs/IMPLEMENTASI_PLAN.md](docs/IMPLEMENTASI_PLAN.md)
- MySQL / MariaDB
- Apache dengan `AllowOverride All` (agar `.htaccess` di folder upload berlaku), misalnya XAMPP

## Instalasi

1. Clone ke folder web server, misalnya `C:\xampp\htdocs\koperasi2`:
   ```bash
   git clone https://github.com/ramadifa13/simkeu.git simkeu
   ```
2. Buat database `koperasi_db`, lalu import struktur & data awal:
   ```bash
   mysql -u root -e "CREATE DATABASE koperasi_db"
   mysql -u root koperasi_db < simkeusi_koperasi_db.sql
   ```
3. Backup database, lalu jalankan **satu file migrasi universal**:
   ```bash
   mysql -u root koperasi_db < database/migrations/MIGRASI_UNIVERSAL.sql
   ```
   File ini bisa dipakai pada database yang belum, sebagian, atau sudah pernah dimigrasi.
   Struktur yang sudah tersedia akan dilewati sehingga tidak terjadi error duplikat.
   Setelah update, Admin wajib membuka **Pengaturan → Surat Perjanjian & TTD Admin**,
   memeriksa template, menggambar TTD Admin, lalu menyimpannya sebelum Teller mencairkan pinjaman.
4. Sesuaikan koneksi database di `config/database.php` bila user/password MySQL berbeda.
5. Atur `php.ini` lalu restart Apache:
   ```ini
   upload_max_filesize = 5M
   post_max_size = 80M
   extension=fileinfo
   ```
6. Pastikan folder `uploads/anggota/` bisa ditulis oleh web server.
7. Buka `http://localhost/koperasi2/`.

## Jika muncul pesan merah "Database belum di-update"

Berarti langkah 3 (file migrasi) belum dijalankan pada database yang dipakai. Tanpa migrasi,
muncul error seperti `Undefined index: frekuensi_diizinkan` / `foto_kk` dan dokumen anggota
tidak tersimpan. Jalankan migrasinya sekali (bisa juga lewat phpMyAdmin → database
`koperasi_db` → tab **Import** → pilih `database/migrations/MIGRASI_UNIVERSAL.sql`).

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
# Pengingat WhatsApp H-1 jatuh tempo

Proses otomatis tersedia di `cron/wa_jatuh_tempo.php` dan memakai WhatsApp Cloud API. Buat template WhatsApp bernama `pengingat_angsuran_h1` dengan lima parameter body berurutan: nama, angsuran ke, tanggal jatuh tempo, nominal, dan kode pinjaman.

Salin `.env.example` menjadi `.env`, kemudian isi kredensial database dan WhatsApp. File `.env` dibaca otomatis oleh aplikasi serta sudah dikecualikan dari Git agar token tidak ikut terunggah.

Atur environment hosting: `WA_PHONE_NUMBER_ID`, `WA_ACCESS_TOKEN`, dan `WA_GRAPH_VERSION` sesuai versi aktif pada akun Meta, serta opsional `WA_TEMPLATE_NAME` dan `WA_TEMPLATE_LANG` (default `id`). Jalankan Cron Job sekali sehari, misalnya pukul 08:00 WIB:

```sh
0 8 * * * /usr/local/bin/php /home/USER/public_html/cron/wa_jatuh_tempo.php
```

Jika hosting hanya menyediakan URL cron, atur `CRON_SECRET` lalu panggil `/cron/wa_jatuh_tempo.php?token=SECRET`. Log pada `wa_pengingat_log` mencegah pesan H-1 yang sama terkirim dua kali dan menyimpan kegagalan untuk dicoba kembali.
