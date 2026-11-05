# SIMKEU Laravel

Aplikasi portal koperasi simpan pinjam (Admin / Teller / Anggota) berbasis **Laravel 13**, Livewire Flux, dan MySQL.

Folder ini (`laravel/`) adalah aplikasi modern. Skema data utama mengikuti database native; migrasi Laravel hanya menambah tabel/kolom pendukung (mis. `activity_logs`, `remember_token`).

---

## Kebutuhan sistem

| Komponen | Versi / catatan |
|---|---|
| PHP | **8.3+** (disarankan 8.4) + ekstensi `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `gd` |
| Composer | 2.x |
| Node.js | **20+** (untuk Vite / Tailwind) |
| MySQL / MariaDB | 8.x / 10.x |
| Opsional | XAMPP (Apache + MySQL) di Windows |

Cek cepat:

```bash
php -v
composer -V
node -v
npm -v
mysql --version
```

---

## 1. Clone repository

```bash
git clone https://github.com/ramadifa13/simkeu.git simkeu
cd simkeu/laravel
```

Semua perintah berikutnya dijalankan dari folder **`simkeu/laravel`**.

---

## 2. Siapkan database

Aplikasi memakai database terpisah (contoh: `simkeu_laravel`). **Jangan** mengarahkan `.env` ke database native produksi.

### Opsi A — Clone dari database native yang sudah ada (disarankan)

Jika MySQL lokal sudah punya database native (mis. `simkeusi_koperasi_db` / `koperasi_db`):

```bash
# Windows (XAMPP) — sesuaikan path mysql bila perlu
mysql -u root -e "CREATE DATABASE IF NOT EXISTS simkeu_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

mysqldump -u root simkeusi_koperasi_db > native_clone.sql
mysql -u root simkeu_laravel < native_clone.sql
```

Ganti `simkeusi_koperasi_db` dengan nama database native Anda.

### Opsi B — Import file dump `.sql`

Jika Anda punya file dump (dari backup / rekan):

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS simkeu_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root simkeu_laravel < path/ke/dump.sql
```

### Opsi C — Database kosong (hanya untuk eksperimen)

Migrasi Laravel **tidak** membuat seluruh skema bisnis. Tanpa clone/import skema native, portal tidak lengkap. Gunakan opsi A atau B untuk setup nyata.

---

## 3. Environment (`.env`)

```bash
copy .env.example .env
```

macOS / Linux:

```bash
cp .env.example .env
```

Edit `.env` minimal:

```env
APP_NAME=SIMKEU
APP_ENV=local
APP_DEBUG=true
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=simkeu_laravel
DB_USERNAME=root
DB_PASSWORD=

# Nama database native (referensi). Jangan sama dengan DB_DATABASE.
NATIVE_REFERENCE_DATABASE=simkeusi_koperasi_db
```

Catatan:

- `APP_URL` harus sesuai cara Anda membuka aplikasi (`artisan serve` atau VirtualHost Apache).
- `NATIVE_REFERENCE_DATABASE` dipakai sebagai pengaman agar aplikasi tidak menulis ke DB native di production.

---

## 4. Install dependensi & setup awal

### Cara cepat (script Composer)

```bash
composer setup
```

Script ini menjalankan: `composer install` → salin `.env` bila belum ada → `key:generate` → `migrate` → `npm install` → `npm run build`.

Setelah itu tetap lakukan **langkah 5** (`storage:link`) dan pastikan database sudah diisi (langkah 2).

### Cara manual (lebih jelas)

```bash
# PHP dependencies
composer install

# Kunci aplikasi
php artisan key:generate

# Migrasi tabel pendukung Laravel
php artisan migrate

# Frontend (Vite + Tailwind)
npm install
npm run build

# Symlink storage (wajib untuk file/logo publik)
php artisan storage:link
```

Izin tulis (Linux/macOS):

```bash
chmod -R ug+rwx storage bootstrap/cache
```

Di Windows/XAMPP pastikan folder `storage/` dan `bootstrap/cache/` bisa ditulis.

---

## 5. Jalankan aplikasi

### Development — `artisan serve` (paling sederhana)

```bash
php artisan serve
```

Buka: [http://127.0.0.1:8000](http://127.0.0.1:8000)

Login: [http://127.0.0.1:8000/login](http://127.0.0.1:8000/login)

### Development dengan hot-reload aset

Terminal 1:

```bash
php artisan serve
```

Terminal 2:

```bash
npm run dev
```

Atau memakai perintah gabungan Laravel (jika tersedia di environment Anda):

```bash
composer dev
```

### XAMPP / Apache

1. Document root mengarah ke `simkeu/laravel/public` (bukan folder `laravel/`).
2. Contoh `APP_URL`:

```env
APP_URL=http://localhost/simkeu/laravel/public
```

3. Pastikan `mod_rewrite` aktif dan `AllowOverride All`.
4. Setelah ubah `.env`:

```bash
php artisan config:clear
php artisan storage:link
npm run build
```

---

## 6. Login

Login memakai **username** + password akun yang statusnya **aktif** di tabel `users` (data dari clone/import).

Role yang didukung:

| Role | Portal |
|---|---|
| `admin` | `/portal/admin/dashboard` |
| `teller` | `/portal/teller/dashboard` |
| `anggota` | `/portal/anggota/dashboard` |

Jika Anda meng-clone database yang sudah ada, gunakan kredensial yang sama dengan aplikasi native.

> `php artisan db:seed` bawaan **belum** menyiapkan akun Admin/Teller production-ready. Untuk setup nyata andalkan data dari clone DB atau buat user lewat modul Admin setelah login.

---

## 7. Setelah install — checklist

- [ ] `php artisan migrate:status` — migrasi Laravel sukses
- [ ] `public/storage` ter-link (`php artisan storage:link`)
- [ ] `npm run build` sudah dijalankan (atau `npm run dev` saat develop)
- [ ] Bisa buka landing `/` dan login `/login`
- [ ] Admin: cek **Pengaturan koperasi** & template perjanjian/TTD bila dipakai pencairan
- [ ] Upload gambar (bukti bayar, foto) tersimpan di `storage/app`

---

## 8. Perintah yang sering dipakai

```bash
# Bersihkan cache config/view/route
php artisan optimize:clear

# Status migrasi
php artisan migrate:status

# Rebuild aset produksi
npm run build

# Jalankan tes
php artisan test

# Format kode PHP
vendor/bin/pint
```

---

## 9. Import migrasi data (opsional)

Admin yang sudah login dapat memakai modul **Migrasi** di portal untuk impor workbook (dry-run + impor final), sesuai template yang disediakan aplikasi.

Pastikan database target adalah `simkeu_laravel` (atau nama DB Laravel Anda), bukan database native.

---

## 10. Troubleshooting

| Masalah | Solusi |
|---|---|
| `No application encryption key` | `php artisan key:generate` |
| Halaman tanpa CSS / mentah | `npm install && npm run build`, hard-refresh browser |
| 403/404 aset `storage/...` | `php artisan storage:link` |
| Error koneksi DB | Cek `DB_*` di `.env`, pastikan MySQL jalan, `php artisan config:clear` |
| Tabel bisnis tidak ditemukan | Database belum di-clone/import skema native (lihat langkah 2) |
| Login gagal | Username/password salah, atau `status` user bukan `aktif` |
| Upload gagal | Naikkan `upload_max_filesize` & `post_max_size` di `php.ini`, restart Apache/PHP |
| `NATIVE_REFERENCE_DATABASE` / boot ditolak | Pastikan `DB_DATABASE` ≠ `NATIVE_REFERENCE_DATABASE` |

PHP `php.ini` (contoh XAMPP) yang disarankan untuk upload:

```ini
upload_max_filesize = 10M
post_max_size = 80M
extension=fileinfo
extension=gd
```

---

## Struktur singkat

```
simkeu/
├── laravel/          ← aplikasi Laravel (kerja di sini)
│   ├── app/
│   ├── resources/    ← Blade, CSS, JS
│   ├── routes/web.php
│   ├── .env.example
│   └── README.md     ← file ini
├── includes/         ← aplikasi native (legacy)
└── README.md         ← panduan native
```

---

## Kontribusi & lisensi

Ikuti konvensi proyek di folder ini (Actions, portal role middleware, Pint). Dokumentasi domain/legacy ada di `laravel/docs/`.

Framework Laravel dilisensikan di bawah [MIT license](https://opensource.org/licenses/MIT).
