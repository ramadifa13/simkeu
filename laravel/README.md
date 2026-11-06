# SIMKEU Laravel

Aplikasi portal koperasi simpan pinjam (Admin / Teller / Anggota) berbasis **Laravel 13**, Livewire Flux, dan MySQL.

Folder ini (`laravel/`) adalah aplikasi modern. Skema data utama mengikuti database native; migrasi Laravel hanya menambah tabel/kolom pendukung (mis. `activity_logs`, `jobs`, `kas.ref_type`, `remember_token`).

---

## Kebutuhan sistem

| Komponen | Versi / catatan |
|---|---|
| PHP | **8.3+** (disarankan 8.4) + ekstensi `pdo_mysql`, `mbstring`, `openssl`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `gd`, `zip` |
| Composer | 2.x |
| Node.js | **20+** (untuk Vite / Tailwind) |
| MySQL / MariaDB | 8.x / 10.x |
| Opsional | XAMPP (Apache + MySQL) di Windows; Redis bila `QUEUE_CONNECTION=redis` |

Cek cepat:

```bash
php -v
composer -V
node -v
npm -v
mysql --version
php -m
```

Pastikan ekstensi PHP di atas muncul di `php -m` (khususnya `gd`, `fileinfo`, `zip`, `pdo_mysql`).

---

## Ringkasan langkah (checklist)

1. [ ] Clone repo, masuk folder `simkeu/laravel`
2. [ ] Buat DB Laravel + clone/import skema native
3. [ ] Salin `.env.example` → `.env`, isi `DB_*` dan `NATIVE_REFERENCE_DATABASE`
4. [ ] `composer setup` (atau install manual) + `php artisan storage:link`
5. [ ] Jalankan app (`php artisan serve` / Apache)
6. [ ] Login dengan user aktif dari clone DB
7. [ ] (Dev/prod) queue worker + scheduler bila memakai antrean/WA/denda terjadwal

---

## 1. Clone repository

```bash
git clone https://github.com/ramadifa13/simkeu.git simkeu
cd simkeu/laravel
```

Semua perintah berikutnya dijalankan dari folder **`simkeu/laravel`**, kecuali disebutkan lain.

---

## 2. Siapkan database

Aplikasi memakai database **terpisah** (contoh: `simkeu_laravel`). **Jangan** mengarahkan `DB_DATABASE` ke database native produksi.

### Opsi A — Clone dari database native yang sudah ada (disarankan)

Jika MySQL lokal sudah punya database native (mis. `simkeusi_koperasi_db` / `koperasi_db`):

**CMD / bash:**

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS simkeu_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

mysqldump -u root simkeusi_koperasi_db > native_clone.sql
mysql -u root simkeu_laravel < native_clone.sql
```

**PowerShell** (redirect `<` / `>` ke mysql sering bermasalah):

```powershell
mysql -u root -e "CREATE DATABASE IF NOT EXISTS simkeu_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysqldump -u root simkeusi_koperasi_db | Out-File -Encoding utf8 native_clone.sql
Get-Content native_clone.sql -Raw | mysql -u root simkeu_laravel
```

Ganti `simkeusi_koperasi_db` dengan nama database native Anda. Di XAMPP Windows, bila `mysql` tidak di PATH:

```text
C:\xampp\mysql\bin\mysql.exe
C:\xampp\mysql\bin\mysqldump.exe
```

### Opsi B — Import file dump `.sql`

```bash
mysql -u root -e "CREATE DATABASE IF NOT EXISTS simkeu_laravel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root simkeu_laravel < path/ke/dump.sql
```

PowerShell: `Get-Content path\ke\dump.sql -Raw | mysql -u root simkeu_laravel`

### Opsi C — Database kosong (hanya eksperimen)

Migrasi Laravel **tidak** membuat seluruh skema bisnis (anggota, pinjaman, kas, dll.). Tanpa clone/import skema native, portal tidak lengkap. Gunakan opsi A atau B untuk setup nyata.

> Setelah clone, jalankan juga migrasi native legacy bila dump belum up-to-date: dari root repo, `database/migrations/MIGRASI_UNIVERSAL.sql` (lihat README native di root). Lalu lanjut migrasi Laravel di langkah 4.

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
APP_TIMEZONE=Asia/Jakarta

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=simkeu_laravel
DB_USERNAME=root
DB_PASSWORD=

# Nama database native (referensi). WAJIB berbeda dari DB_DATABASE.
NATIVE_REFERENCE_DATABASE=simkeusi_koperasi_db

# local: sync OK. produksi: database (atau redis) + jalankan queue:work
QUEUE_CONNECTION=database

FILESYSTEM_DISK=local
SESSION_DRIVER=file
CACHE_STORE=file
```

Opsional — pengingat WhatsApp H-1 (kosongkan jika tidak dipakai):

```env
WA_PHONE_NUMBER_ID=
WA_ACCESS_TOKEN=
WA_GRAPH_VERSION=v20.0
WA_TEMPLATE_NAME=pengingat_angsuran_h1
WA_TEMPLATE_LANG=id
```

Catatan:

- `APP_URL` harus sesuai cara Anda membuka aplikasi (`artisan serve` atau VirtualHost Apache).
- `NATIVE_REFERENCE_DATABASE` dipakai sebagai pengaman: di **production**, aplikasi **menolak boot** jika `DB_DATABASE` sama dengan nilai ini.
- Jangan commit file `.env`.

---

## 4. Install dependensi & setup awal

### Cara cepat (script Composer)

```bash
composer setup
```

Script `composer setup` menjalankan: `composer install` → salin `.env` bila belum ada → `key:generate` → `migrate` → `storage:link` → `npm install` → `npm run build`.

Pastikan langkah 2 (isi database bisnis) sudah selesai sebelum / sesudah migrate. Isi `DB_*` di `.env` sebelum `composer setup` jika `.env` sudah ada.

### Cara manual (lebih jelas)

```bash
composer install
php artisan key:generate
php artisan migrate
npm install
npm run build
php artisan storage:link
```

Izin tulis (Linux/macOS):

```bash
chmod -R ug+rwx storage bootstrap/cache
```

Di Windows/XAMPP pastikan folder `storage/` dan `bootstrap/cache/` bisa ditulis oleh PHP/Apache.

Migrasi Laravel menambah antara lain: `activity_logs`, `jobs` / `failed_jobs` / `job_batches`, index hot-path, `kas.ref_type`, kolom pendukung lain. **Tidak** menggantikan dump native.

---

## 5. Jalankan aplikasi

### Development — `artisan serve` (paling sederhana)

```bash
php artisan serve
```

- App: [http://127.0.0.1:8000](http://127.0.0.1:8000)
- Login: [http://127.0.0.1:8000/login](http://127.0.0.1:8000/login)

### Development dengan hot-reload aset

Terminal 1: `php artisan serve`  
Terminal 2: `npm run dev`

Atau:

```bash
composer dev
```

### Queue + scheduler (disarankan saat develop fitur denda/WA/backup)

Bila `QUEUE_CONNECTION=database` (default di `.env.example`):

```bash
# Terminal terpisah
php artisan queue:work

# Scheduler lokal (setara cron)
php artisan schedule:work
```

Jadwal bawaan:

| Waktu | Perintah |
|---|---|
| 00:10 | `loans:refresh-penalties` (antrekan job denda; pakai `--sync` untuk langsung) |
| 08:00 | `wa:remind-due` (butuh kredensial WA) |

Produksi: cron `* * * * * php /path/ke/laravel/artisan schedule:run` + proses `queue:work` (supervisor/systemd).

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

| Role | Portal |
|---|---|
| `admin` | `/portal/admin/dashboard` |
| `teller` | `/portal/teller/dashboard` |
| `anggota` | `/portal/anggota/dashboard` |

Jika Anda meng-clone database yang sudah ada, gunakan kredensial yang sama dengan aplikasi native.

> `php artisan db:seed` bawaan Laravel **tidak** menyiapkan akun Admin/Teller production-ready (seeder skeleton tidak cocok dengan skema `users` SIMKEU). Untuk setup nyata: andalkan data clone DB, atau buat/reset user lewat modul Admin setelah login.

Reset kata sandi publik (`/lupa-kata-sandi`) **sengaja ditutup** (parity native: hanya Admin/Teller yang mereset).

Avatar anggota memakai **foto pencairan** (nasabah menerima uang), bukan upload foto profil terpisah.

---

## 7. Setelah install — checklist

- [ ] `php artisan migrate:status` — migrasi Laravel sukses (termasuk `jobs`, `kas.ref_type` bila ada)
- [ ] `public/storage` ter-link (`php artisan storage:link`)
- [ ] `npm run build` sudah dijalankan (atau `npm run dev` saat develop)
- [ ] Bisa buka landing `/` dan login `/login`
- [ ] Admin: **Pengaturan koperasi** & template perjanjian/TTD Admin sebelum pencairan
- [ ] Upload gambar tersimpan di `storage/app` (disk `local`)
- [ ] (Opsional) `php artisan queue:work` + `schedule:work` jalan
- [ ] (Opsional cutover) `php artisan simkeu:uat-gate` — lihat `docs/cutover-uat-checklist.md`

---

## 8. Perintah yang sering dipakai

```bash
# Bersihkan cache config/view/route
php artisan optimize:clear

# Status migrasi
php artisan migrate:status

# Rebuild aset produksi
npm run build

# Queue & denda
php artisan queue:work
php artisan loans:refresh-penalties          # antrekan (butuh worker)
php artisan loans:refresh-penalties --sync   # langsung tanpa antrean

# Gate UAT / parity (butuh NATIVE_REFERENCE_DATABASE terisi & terbaca)
php artisan simkeu:uat-gate
php artisan phase7:loan-parity

# Tes & format
php artisan test
vendor/bin/pint
```

---

## 9. Import migrasi data (opsional)

Admin yang sudah login dapat memakai modul **Migrasi** di portal untuk impor workbook (dry-run + impor final), sesuai template aplikasi.

Pastikan database target adalah `simkeu_laravel` (atau nama DB Laravel Anda), bukan database native.

---

## 10. Produksi singkat

1. `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL` https yang benar  
2. `QUEUE_CONNECTION=database` (atau redis) + `queue:work` selalu hidup  
3. Cron `schedule:run` tiap menit  
4. `php artisan config:cache` / `route:cache` / `view:cache` setelah deploy  
5. `npm run build` di CI/server  
6. Pastikan `DB_DATABASE` ≠ `NATIVE_REFERENCE_DATABASE`  
7. Ikuti `docs/cutover-uat-checklist.md` sebelum mematikan write di PHP native  

---

## 11. Troubleshooting

| Masalah | Solusi |
|---|---|
| `No application encryption key` | `php artisan key:generate` |
| Halaman tanpa CSS / mentah | `npm install && npm run build`, hard-refresh browser |
| 403/404 aset `storage/...` | `php artisan storage:link` |
| Error koneksi DB | Cek `DB_*` di `.env`, MySQL jalan, `php artisan config:clear` |
| Tabel bisnis tidak ditemukan | Belum clone/import skema native (langkah 2) |
| Login gagal | Username/password salah, atau `status` user bukan `aktif` |
| Upload gagal | Naikkan `upload_max_filesize` & `post_max_size` di `php.ini`, pastikan `gd`/`fileinfo` aktif, restart Apache/PHP |
| `NATIVE_REFERENCE_DATABASE` / boot ditolak | Production: `DB_DATABASE` harus ≠ `NATIVE_REFERENCE_DATABASE` |
| Job / denda / WA tidak jalan | `QUEUE_CONNECTION=database`, migrate tabel `jobs`, jalankan `queue:work` + scheduler |
| PowerShell `mysql < file.sql` gagal | Pakai `Get-Content file.sql -Raw \| mysql ...` |
| `composer setup` OK tapi logo/file 404 | Lupa `php artisan storage:link` |
| DomPDF / Excel error | Pastikan ekstensi `zip` dan `gd` aktif |

PHP `php.ini` (contoh XAMPP) yang disarankan:

```ini
upload_max_filesize = 64M
post_max_size = 160M
extension=fileinfo
extension=gd
extension=zip
```

---

## Struktur singkat

```
simkeu/
├── laravel/                 ← aplikasi Laravel (kerja di sini)
│   ├── app/
│   ├── docs/                ← cutover UAT & catatan domain
│   ├── resources/           ← Blade, CSS, JS
│   ├── routes/web.php
│   ├── .env.example
│   └── README.md            ← file ini
├── includes/                ← aplikasi native (legacy)
├── database/migrations/     ← MIGRASI_UNIVERSAL.sql (native)
└── README.md                ← panduan native
```

Dokumentasi cutover: [`docs/cutover-uat-checklist.md`](docs/cutover-uat-checklist.md).

---

## Kontribusi & lisensi

Ikuti konvensi proyek (Actions, portal role middleware, Pint, Form Request untuk mutasi uang).

Framework Laravel dilisensikan di bawah [MIT license](https://opensource.org/licenses/MIT).
