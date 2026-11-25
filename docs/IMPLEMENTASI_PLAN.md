# Rencana Implementasi Perbaikan dan Update Sistem

Tanggal kajian: 25 November 2026  
Sumber kebutuhan: `PERBAIKAN DAN UPDATE SISTEM.pdf` (4 halaman)  
Status: fitur utama sudah diimplementasikan dan diaudit pada 25 November 2026. Seluruh skema tambahan kini digabung ke `MIGRASI_UNIVERSAL.sql`; database lokal sudah menerapkan perubahan tersebut sebelum file migrasi terpisah dihapus. Status terverifikasi dan batas pekerjaan tersisa dijelaskan pada bagian **Hasil implementasi dan audit terbaru** di bawah.

Catatan membaca dokumen: tabel dan tahapan awal merekam baseline serta rencana sebelum keputusan bisnis lengkap diterima. Keputusan yang sudah dikonfirmasi dan status hasil kerja di bagian akhir menjadi acuan terbaru.

## Tujuan dan batas rencana

Dokumen ini menerjemahkan 15 permintaan dalam PDF menjadi rencana kerja yang dapat dilaksanakan dan diverifikasi. Kajian membandingkan permintaan dengan struktur PHP/MySQL, migrasi dan helper yang ada. Rencana mengutamakan perlindungan data dan saldo, kompatibilitas data pinjaman lama, audit tindakan, serta pengujian berlapis.

Targetnya ialah memenuhi kebutuhan yang sudah cukup jelas dan mengisolasi keputusan bisnis yang belum pasti sebelum mengubah perhitungan atau jadwal. Tidak ada rencana yang dapat menjamin nol bug; mitigasi yang dipakai adalah implementasi bertahap, transaksi database, migrasi idempoten, uji regresi dan uji penerimaan berbasis skenario.

## Ringkasan temuan kode

- Aplikasi operasional adalah PHP biasa dengan PDO/MySQL. `config/database.php`, `config/env.php`, dan `config/config.php` mengatur koneksi, `.env`, zona waktu WIB, serta mode SQL. Folder `laravel/` bukan jalur utama untuk fitur koperasi.
- Perhitungan pokok, bunga, jumlah angsuran, dan jadwal terpusat di `includes/pinjaman_helper.php`. Skema pinjaman lama memakai `tahunan`; perubahan tidak boleh mengubah hasil kontrak lama.
- `includes/denda_helper.php` menghitung denda harian. Pembaruan denda dipanggil dari header/dashboard dan alur pembayaran/pengaturan; dengan demikian pembaruan bergantung pada aktivitas aplikasi, bukan worker jadwal mandiri. `dendaPerHari()` saat ini juga menghitung tarif gadai per hari.
- `includes/pencairan_page.php` membentuk dokumen/alur pencairan; permintaan TTD adalah perbaikan tampilan tablet.
- Data agunan memakai `jaminan_pinjaman.identitas_barang` dan `deskripsi_barang` sebagai teks. AGTJ sudah dikenali, tetapi atribut HP/BPKB/STNK belum menjadi field terstruktur.
- `anggota.hari_angsuran` serta snapshot SHU sudah tersedia. Master kelompok penagihan memakai tabel dan relasi tersendiri; `ketua_kelompok_id` tetap khusus SHU.
- Dashboard admin dan laporan kas ada; `admin/laporan_kas.php` menggunakan Chart.js untuk saldo kas. Belum ditemukan grafik laba harian dengan drill-down dan agregasi yang diminta.
- Sistem telah mempunyai riwayat transaksi/kas, pembayaran transfer di beberapa alur, audit layanan, serta beberapa skrip pengujian PHP langsung di `tests/`. Fitur transfer untuk anggota belum ditemukan.
- Skema saat ini memiliki migrasi universal `database/migrations/MIGRASI_UNIVERSAL.sql`. Blok skema kelompok penagihan digabungkan di sana; perubahan berikutnya tetap perlu dibackup dan diuji pada salinan database.

## Pemetaan permintaan PDF

| No. | Kebutuhan | Status awal | Area utama | Kriteria hasil |
|---|---|---|---|---|
| 1 | TTD pencairan tidak melebar ke samping pada tablet | Perbaikan UI terlokalisasi | `includes/pencairan_page.php`, CSS dan JavaScript tanda tangan | Kanvas tetap proporsional di portrait/landscape, tanda tangan dapat digambar dan disimpan pada beberapa ukuran layar, hasil cetak tetap benar. |
| 2 | Denda otomatis saat nasabah terlambat | Perhitungan sudah ada; pemicu operasional perlu ditinjau | `includes/denda_helper.php`, `includes/header.php`, halaman angsuran, cron | Denda untuk jadwal lewat jatuh tempo selalu dihitung konsisten walau tidak ada pengguna yang membuka dashboard; transaksi, pembatalan dan pembayaran ulang tidak membuat denda ganda. |
| 3 | Hari libur menggeser jadwal angsuran | Belum ditemukan kalender libur | Helper jadwal, admin kalender, jadwal pinjaman | Libur tanggal 7 menggeser cicilan terkait ke tanggal 14 dan cicilan berikutnya tetap berjarak sesuai frekuensi; jadwal lunas tidak berubah diam-diam. |
| 4 | Nominal pembiayaan bertambah dengan pecahan Rp500.000 | Belum tervalidasi sebagai aturan lintas jenis | Form pengajuan, helper kalkulasi, preview JS, tarif jenis | Form, server, kalkulasi, pencairan dan dokumen menerima nominal sesuai langkah Rp500.000; permintaan bernilai di luar batas/kelipatan ditolak dengan pesan jelas. |
| 5 | Pengajuan pindah kelompok atau hari angsuran dengan keputusan Admin | Hari angsuran ada di pengajuan; workflow pindah belum ditemukan | Halaman pengajuan/anggota, admin persetujuan, tabel riwayat | Pengajuan tercatat, Admin dapat setuju/tolak, dan perubahan yang disetujui berlaku mulai tanggal efektif yang terlihat serta tercatat di audit. |
| 6 | Denda AGTJ dihitung/dijelaskan per minggu | Kode saat ini bertarif per hari | `includes/denda_helper.php`, halaman bayar/laporan denda | Nasabah melihat dasar dan akumulasi per minggu dengan aturan pembulatan yang disepakati; pembayaran/rekonsiliasi tetap memakai nilai rupiah yang sama di semua layar. |
| 7 | Status KOL 1–5, warna, dashboard dan aksi penyelamatan Kol 2–4 | Belum ditemukan mesin KOL | Helper aging, admin dashboard, notifikasi, layanan restrukturisasi | KOL ditentukan dari DPD tertua yang belum dibayar; definisi rentang tunggal dipakai oleh daftar, dashboard, reminder dan tindakan Admin. Kol 2–4 menawarkan rescheduling; Kol 5 diarahkan ke proses recovery, tidak otomatis dieksekusi. |
| 8 | Angsuran hasil rescheduling tampil di item terpisah | Belum ditemukan jenis pinjaman/jadwal rescheduling | Menu pinjaman, filter angsuran, skema/tabel relasi | Pinjaman hasil reschedule dapat dicari di menu/daftar “Angsuran Rescheduling”, terhubung ke kontrak asal, dan seluruh pembayaran tetap tercatat sekali. |
| 9 | Rumus total angsuran khusus AGTJ | Aturan PDF tidak sama jelasnya dengan formula AGTJ yang sudah ada | `includes/pinjaman_helper.php`, tarif produk AGTJ | Total kewajiban, pokok, bunga/biaya, angsuran per periode, pembulatan dan tenor dipratinjau sama persis di server, UI, kuitansi dan laporan. |
| 10 | Tambah kapasitas memori untuk AGTJ HP | Belum ditemukan field khusus | Form agunan HP, `jaminan_pinjaman`, detail/cetak | Nilai kapasitas memori disimpan/ditampilkan untuk barang HP; jenis barang lain tidak diwajibkan mengisi field ini. |
| 11 | Perubahan data kendaraan AGTJ: BPKB dan STNK | Saat ini BPKB dan “Atas nama BPKB” disimpan di teks | Form agunan kendaraan, skema, detail/cetak | BPKB dan nama di BPKB dihapus sesuai instruksi; nomor/nama STNK ditampilkan dan disimpan menurut definisi bisnis yang dikonfirmasi. Data arsip lama tetap dapat dibaca. |
| 12 | Sisa tagihan/saldo pinjaman dashboard Admin otomatis berkurang ketika Teller menerima angsuran | `sisa_pokok` sudah dikelola di flow pembayaran; dashboard perlu rekonsiliasi | Pembayaran Teller, `includes/dashboard_helper.php`, widget Admin | Satu pembayaran yang berhasil memperbarui angsuran, `sisa_pokok`, kas dan dashboard secara atomik; pembatalan mengembalikan saldo sekali dan laporan konsisten. |
| 13 | Grafik pertumbuhan laba dapat di-zoom, detail harian; ringkas mingguan/bulanan/tahunan | Ada grafik saldo kas, bukan yang diminta | Admin dashboard/laporan, kueri agregasi, Chart.js | Mode harian menunjukkan angsuran masuk dan pencairan per hari; zoom dan agregasi minggu/bulan/tahun memakai rentang waktu dan definisi laba yang konsisten. |
| 14 | Fitur transfer di level anggota | Transfer tersedia di beberapa pembayaran internal, bukan fitur anggota | Portal `anggota/`, saldo/rekening, API dan audit | Anggota dapat membuat instruksi transfer sesuai tujuan yang diizinkan, melihat status/referensi, dan tidak dapat memalsukan status lunas atau saldo. |
| 15 | Validasi angsuran/pemeriksaan menggunakan NIK, tanggal lahir dan nama | Belum ditemukan pemeriksaan kontrak anggota berbasis tiga field | Portal/form lookup, layanan validasi, audit dan privasi | Validasi format NIK dan pencocokan internal mengembalikan informasi minimum; pemanggilan eksternal hanya aktif bila ada akses sah, persetujuan dan dasar pemrosesan. Credit scoring tetap nonaktif sebagaimana catatan PDF. |

## Urutan implementasi yang disarankan

### Tahap 0 — Keputusan aturan, baseline, dan perlindungan data

1. Simpan backup database dan jalankan rancangan di salinan lokal/staging, bukan database aktif.
2. Catat hitungan dan checksum/ekspor ringkas pinjaman, angsuran, saldo pokok, kas, jenis produk serta status sebelum perubahan.
3. Dokumentasikan aturan yang telah dijawab di bagian bawah. Sebelum hitungan tagihan diprogram, putuskan sisa rincian: cara menerapkan plafon Rp2 juta untuk nasabah lanjutan; alokasi pembayaran parsial; rumus/satuan tambahan biaya AGTJ; serta pasangan tenor/frekuensi rescheduling.
4. Buat katalog skenario penerimaan dari contoh PDF dan data historis tersamarkan. Data NIK/KTP tidak boleh masuk log, fixture, atau laporan pengujian.
5. Pastikan backup dapat dipulihkan. Setiap perubahan skema dibuat dalam migrasi bernomor/idempoten, punya pemeriksaan kondisi sebelum `ALTER`, dan mempunyai prosedur pemulihan yang jelas.

**Gerbang:** sisa aturan nominal/rescheduling/AGTJ dan kebijakan privasi disetujui sebelum perubahan yang mengubah kewajiban nasabah.

### Tahap 1 — Perbaikan UI dan pembenahan data agunan

1. Perbaiki kanvas tanda tangan pencairan: ukuran visual responsif, koordinat pointer dipetakan ke resolusi kanvas, dukungan sentuh, orientasi tablet, tombol hapus dan validasi tanda tangan kosong.
2. Tambah kolom kapasitas memori yang nullable pada tabel agunan, tampilkan hanya pada kategori HP, dan update penyimpanan/detail/dokumen.
3. Untuk sepeda motor, field baru menyimpan nama pemilik STNK saja. Sembunyikan field BPKB dari form baru tanpa menghapus data lama; arsip lama tetap dapat dibaca Admin bila diperlukan.

**Gerbang:** alur lama untuk agunan non-HP/kendaraan, PDF perjanjian dan pencairan tetap dapat dibuka serta dicetak.

### Tahap 2 — Konsistensi nominal, jadwal, hari libur dan denda

1. Jadikan validasi server sebagai sumber kebenaran nominal. Semua jenis pembiayaan memakai langkah Rp500.000; minimum Rp1.000.000 untuk nasabah baru dan Rp500.000 untuk nasabah lanjutan. Pinjaman nasabah baru maksimal Rp2.000.000 untuk frekuensi mingguan maupun dua mingguan. Nasabah lanjutan maksimal naik Rp2.000.000 dibanding dasar pinjaman yang disepakati, dengan limit dapat ditetapkan Admin/Teller. Kontrak aktif lama tetap memakai nominal lama sampai diperbarui.
2. Buat kalender hari libur (`tanggal`, nama/alasan, aktif, pencatat) dengan validasi admin. Otomatis masukkan Idulfitri dan Iduladha, masing-masing satu angsuran mingguan libur; admin dapat menambah libur eksternal manual. Jika tanggal pengganti juga libur/bertabrakan, geser satu minggu lagi. Jadwal 2 mingguan bergeser 14 hari dan tanggal terakhir ikut mundur 14 hari.
3. Untuk pinjaman berjalan, perubahan kalender hanya menggeser cicilan belum lunas mulai tanggal efektif. Pertahankan nomor cicilan, transaksi pembayaran dan histori lama. Cegah dua cicilan jatuh pada tanggal sama tanpa kebijakan eksplisit.
4. Jalankan pembaruan denda terjadwal melalui CLI cron dengan lock/idempotensi dan log hasil; pertahankan pemanggilan saat pembayaran untuk menghitung nilai terkini. Denda keterlambatan AGTJ terpisah dari biaya pembiayaan dan memakai Rp10.000 per Rp1 juta per minggu; keterlambatan 1–6 hari dibulatkan menjadi satu minggu. Samakan teks di PDF pencairan.

**Gerbang:** hasil kalkulasi PHP sama dengan preview JavaScript dan cetakan, sementara semua pinjaman lama menghasilkan jadwal/tagihan yang sama sebelum perubahan kalender/denda.

### Tahap 3 — Mutasi kelompok/hari dan mesin KOL

1. Tambah entitas permohonan mutasi dengan jenis (kelompok/hari), nilai lama/baru, alasan, pemohon, waktu, status, pemeriksa, catatan, serta tanggal efektif. Gunakan persetujuan Admin dan jejak audit.
2. Pindah hari hanya dapat diajukan setelah seluruh angsuran yang sudah jatuh tempo lunas. Hari baru mulai pada jatuh tempo berikutnya (contoh Selasa ke Jumat minggu berikutnya); jumlah cicilan tidak berubah dan tanggal pelunasan mengikuti hari baru. Mutasi berlaku pada pinjaman berjalan.
3. Simpan snapshot kelompok/hari pada kontrak atau riwayat efektif agar perubahan profil anggota tidak mengubah arti data kontrak lama.
4. Implementasikan satu helper DPD/KOL yang mengambil angsuran belum lunas paling awal dan membandingkan tanggal jatuh tempo efektif dengan tanggal evaluasi. Pembayaran parsial dialokasikan ke angsuran tertua sampai lunas, lalu ke angsuran berikutnya. DPD dihitung dari tanggal jatuh tempo tertua yang masih bersisa, tetap berjalan saat rescheduling, dan tidak berhenti karena libur. Simpan tanggal awal tunggakan agar jadwal reschedule yang dimulai dari angsuran ke-1 tidak mereset umur tunggakan.
5. Gunakan batas PDF secara tepat: Kol 1 = 0 hari; Kol 2 = 1–90; Kol 3 = 91–120; Kol 4 = 121–180; Kol 5 = lebih dari 180. Uji nilai batas 0/1/90/91/120/121/180/181 hari.
6. Tambahkan indikator warna plus teks dan angka DPD (warna tidak menjadi satu-satunya penanda). Dashboard menampilkan jumlah dan daftar per KOL, saldo pokok/outstanding serta tautan ke aksi.
7. Untuk anggota dengan beberapa pinjaman aktif, saran: tampilkan KOL terburuk sebagai status ringkas anggota, total baki pokok semua pinjaman, dan rincian KOL/saldo tiap kontrak. Jangan saling mengimbangi tunggakan antar pinjaman.
8. Pembayaran sebagian mengurangi tunggakan; tunggakan angsuran dihapus setelah angsuran terkait lunas.
9. Jadwalkan pengingat sebelum jatuh tempo melalui kanal yang benar-benar terkonfigurasi. Log pengiriman dan idempotensi per anggota/angsuran/tanggal mencegah duplikasi; kegagalan layanan tidak boleh mengubah status pembayaran.

**Gerbang:** dashboard, ekspor, reminder dan halaman detail memberikan KOL yang identik untuk setiap kontrak.

### Tahap 4 — Rescheduling dan daftar angsuran terpisah

1. Rancang kontrak hasil rescheduling sebagai versi/riwayat yang terhubung dengan pinjaman asal. Jadwal baru dimulai dari angsuran ke-1; jangan mengedit atau menghapus jadwal/pembayaran historis pinjaman asal.
2. Aturan tenor: gandakan tenor kontrak asal (1→2, 2→4, 3→6 bulan; 6 bulan dua mingguan→12 bulan) dan pertahankan frekuensi angsuran asal. Angsuran pertama dimulai tujuh hari setelah Admin menjalankan rescheduling, lalu berulang sesuai frekuensi asal. Jumlah angsuran baru dua kali jumlah pada tenor asal.
3. Simulasi Admin menampilkan sisa pokok, sisa keuntungan belum dibayar, tunggakan, denda yang dibebaskan, tenor/frekuensi baru, jumlah cicilan dan total. Hitung ulang keuntungan dari sisa pokok dengan suku bunga kontrak asal; jangan menambah keuntungan yang sudah dibayar.
4. Batasi aksi rescheduling ke Admin serta pinjaman dengan KOL 2–4. Bebaskan denda saat reschedule disetujui, tetapi DPD tetap berjalan dari tanggal tunggakan awal. Simpan alasan, snapshot rumus dan audit sebelum/sesudah.
5. Buat mode rescheduling yang terpisah dari pinjaman reguler di menu, filter, laporan dan bukti angsuran, tetapi gunakan mesin pembayaran/kas yang sama.
6. Jalankan perubahan angsuran, status pinjaman lama/baru, hubungan kontrak, jadwal, sisa pokok, saldo dashboard dan audit dalam satu transaksi database. Token/idempotency cegah klik ganda menghasilkan kontrak ganda.
7. Pertahankan aturan pembatalan pembayaran: pembalikan harus memulihkan pokok dan menghitung ulang KOL tanpa menghapus histori rescheduling.

**Gerbang:** tidak ada pokok/bunga yang tercatat dua kali; pembayaran historis, saldo kas, saldo pokok dan jadwal versi baru dapat direkonsiliasi.

### Tahap 5 — Dashboard finansial dan transfer anggota

1. Verifikasi jalur pembayaran Teller sekarang: cek apakah seluruh pembayaran selalu memperbarui `angsuran`, `pinjaman.sisa_pokok`, kas dan audit dalam transaksi yang sama. Perbaiki celah sebelum menambah grafik.
2. Buat grafik basis kas dengan total pencairan, collection reguler, collection rescheduling dan denda diterima per hari; sediakan ringkasan mingguan/bulanan/tahunan. Denda diterima dihitung sebagai pendapatan. Pencairan ditampilkan sebagai arus kas keluar terpisah dari laba.
3. Buat query agregasi dengan rentang tanggal konsisten dan indeks tanggal; hindari query per titik chart. Zoom memilih interval harian; tampilan keluar mengagregasi mingguan/bulanan/tahunan.
4. Buat alur transfer anggota khusus setoran angsuran via transfer. Anggota mengirim instruksi dan referensi/bukti; pembayaran dianggap lunas setelah verifikasi mutasi oleh Teller/Admin.
5. Terapkan CSRF, autentikasi kepemilikan akun, idempotency key, validasi nominal, referensi unik, status pending/berhasil/gagal/dibatalkan serta rekonsiliasi. Jangan pernah menerima “sukses” hanya dari nilai POST atau redirect browser.

**Gerbang:** total grafik direkonsiliasi dengan laporan kas/transaksi; dua pengiriman request transfer yang sama tidak mencatat dua transaksi.

### Tahap 6 — Pemeriksaan identitas dan integrasi eksternal (opsional bersyarat)

1. Pencocokan internal dapat dikerjakan terpisah: normalisasi NIK sebagai string 16 digit (nol awal terjaga), cocokkan NIK, tanggal lahir dan nama dengan aturan toleransi yang disetujui; hasil menampilkan kontrak aktif dan kewajiban minimum yang diperlukan.
2. Tambahkan pembatasan percobaan, otorisasi teller/admin, audit tanpa menyimpan NIK penuh, masking hasil, dan respons generik untuk mencegah enumerasi NIK.
3. Pisahkan hasil pencocokan internal dari data eksternal. Catat sumber dan waktu data, bukan menyimpulkan skor kolektibilitas SLIK dari KOL internal.
4. Pertahankan credit scoring, approval otomatis, OCR dan biometrik dalam keadaan nonaktif sampai ada permintaan dan rancangan tersendiri.
5. SLIK H2H bukan akses otomatis bagi koperasi hanya karena badan hukum/terdaftar. SEOJK 11/2024 menyebut koperasi simpan pinjam sebagai lembaga non-LJK yang dapat mengajukan diri menjadi Pelapor SLIK dengan persyaratan dan persetujuan OJK; setelah menjadi Pelapor, permintaan H2H juga perlu persetujuan OJK tersendiri. Pengurus perlu memeriksa status dan kelengkapan syarat langsung ke OJK. Jika belum menjadi Pelapor atau belum mendapat persetujuan H2H, jangan buat integrasi langsung; tahap awal cukup lookup internal atau nasabah meminta iDeb sendiri melalui kanal resmi. [SEOJK OJK 11/2024](https://ojk.go.id/id/regulasi/Pages/Pelaporan-dan-Permintaan-Informasi-Debitur-Melalui-Sistem-Layanan-Informasi-Keuangan-11-SEOJK01-2024.aspx) · [Halaman layanan SLIK OJK](https://ojk.go.id/id/kanal/perbankan/Pages/Sistem-Layanan-Informasi-Keuangan-SLIK.aspx)
6. Sebelum mengirim NIK/nama/tanggal lahir ke layanan pihak ketiga, tetapkan dasar pemrosesan, pemberitahuan/persetujuan yang tepat, kontrol akses, masa simpan, penghapusan dan penanganan insiden sesuai kewajiban pelindungan data pribadi. [UU 27/2022 tentang Pelindungan Data Pribadi](https://peraturan.bpk.go.id/Details/229798/uu-no-27-tahun-2022)

**Gerbang:** tidak ada panggilan eksternal aktif sebelum status Pelapor/izin akses, tujuan pemrosesan dan kontrol perlindungan data disahkan.

## Keputusan bisnis yang sudah dijawab

1. Semua jenis pembiayaan memakai langkah nominal Rp500.000. Pinjaman pertama nasabah baru maksimal Rp2.000.000 untuk frekuensi mingguan maupun dua mingguan. Minimum pengajuan Rp1.000.000 untuk nasabah baru dan Rp500.000 untuk nasabah lanjutan. Nasabah lanjutan boleh menaikkan pinjaman maksimal Rp2.000.000 dari pokok pinjaman terakhir yang sudah lunas; Admin/Teller dapat menetapkan limit lebih rendah. Sistem menolak pengajuan yang melampaui limit dengan alert. Validasi nominal berlaku pada proses impor; kontrak lama yang sudah ada dipertahankan sampai diperbarui.
2. Libur otomatis hanya Idulfitri dan Iduladha, satu angsuran mingguan diliburkan pada masing-masing hari raya. Libur eksternal lain dimasukkan manual. Jika bentrok dengan tanggal pengganti, undur satu minggu lagi. Jadwal dua mingguan mengikuti frekuensinya.
3. Pindah hari/kelompok berlaku pada pinjaman berjalan. Pindah hari dapat diajukan setelah seluruh angsuran yang sudah jatuh tempo lunas; perubahan hari berlaku mulai jadwal berikutnya, misalnya Selasa ke Jumat minggu depan. Jumlah cicilan tetap; tanggal lunas menyesuaikan hari baru.
4. Denda AGTJ Rp10.000 per Rp1 juta per minggu. Telat 1–6 hari dibulatkan menjadi satu minggu dan teks PDF pencairan juga harus menyatakan per minggu.
5. DPD tetap berjalan saat rescheduling dan hari libur. Pembayaran sebagian mengurangi tunggakan; tunggakan terhapus setelah angsuran terkait lunas.
6. Rescheduling membuat jadwal baru dari angsuran ke-1, dimulai tujuh hari setelah Admin menjalankan aksi. Keuntungan dihitung ulang dari sisa pokok memakai tingkat bunga awal; sisa keuntungan yang belum dibayar ditagih dan denda dibebaskan. DPD tetap berjalan.
7. Angka PDF hanya contoh; hitung nilai aktual dari saldo nasabah. Untuk contoh saldo Rp416.669 + sisa keuntungan Rp145.834 = Rp562.503, dibagi 24 rata-rata Rp23.437,625. Dengan pembulatan rupiah, 23 cicilan Rp23.438 dan cicilan terakhir dikoreksi agar jumlah tepat Rp562.503. Pokok Rp17.500 × 24 tidak dipakai karena melampaui sisa pokok.
8. Formula AGTJ untuk tenor enam bulan: Rp1.000.000 pokok + Rp150.000 bunga (15%) + Rp240.000 biaya mingguan selama 24 minggu + biaya tenor Rp10.000 per Rp1 juta per bulan selama 6 bulan (Rp60.000) = Rp1.450.000. Untuk pokok Rp2 juta, biaya tenor enam bulan menjadi Rp120.000. Total ini belum termasuk denda keterlambatan Rp10.000 per minggu per Rp1 juta. Denda dan biaya pembiayaan merupakan komponen berbeda.
9. AGTJ SEPEDA adalah sepeda motor. Form baru hanya memakai nama pemilik STNK; data BPKB lama diarsipkan/disembunyikan, tidak dihapus.
10. Transfer anggota hanya untuk setoran angsuran melalui transfer.
11. Grafik memakai basis kas dan memisahkan pokok angsuran, keuntungan/bunga, denda, collection rescheduling, dan pencairan. Denda diterima masuk pendapatan; pembebasan denda saat rescheduling ditampilkan sebagai metrik tersendiri.
12. Untuk akses SLIK yang belum tersedia, tahap awal cukup menggunakan data internal koperasi.

## Keputusan tambahan yang sudah dikonfirmasi

1. Nasabah baru maksimal Rp2 juta untuk pinjaman pertama; nasabah lanjutan maksimal naik Rp2 juta dari pinjaman terakhir yang lunas. Admin/Teller boleh menetapkan limit lebih rendah.
2. Seluruh angsuran yang sudah jatuh tempo harus lunas sebelum pengajuan pindah hari.
3. Pembayaran parsial dialokasikan ke angsuran tertua sampai lunas, lalu ke angsuran berikutnya. DPD dihitung dari tanggal jatuh tempo tertua yang masih memiliki sisa.
4. Untuk rescheduling, tenor digandakan, frekuensi asal dipertahankan, jumlah cicilan menjadi dua kali, dan cicilan pertama mulai tujuh hari setelah Admin memprosesnya.
5. **Grafik laba:** pemisahan metrik dan metrik pembebasan denda sudah disetujui. Pokok collection tetap dipisahkan dari laba; pencairan ditampilkan sebagai arus kas keluar.
6. **SLIK:** lookup internal dipilih untuk tahap awal. Jika kelak dibutuhkan SLIK, pengurus memastikan status Pelapor dan izin H2H kepada OJK.

## Rencana uji dan pengendalian regresi

Sebelum rilis, tambahkan atau perluas pengujian pada pola yang sudah dipakai proyek (`php tests/...`). Rencana ini menjabarkan skenario, bukan menyatakan tes telah dijalankan.

- **Kalkulasi:** batas nominal dan kelipatan Rp500.000; kecocokan preview PHP/JS; total pokok/bunga sama dengan jumlah seluruh cicilan; pembulatan cicilan terakhir; pinjaman lama skema `tahunan` tidak berubah.
- **Jadwal/libur:** tanggal 7/14 pada contoh; pergantian bulan/tahun; bulan Februari/tahun kabisat; libur berturut-turut; jadwal lunas, pembayaran parsial, dan jadwal ulang.
- **Denda:** tepat jatuh tempo, telat 1 hari, batas periode mingguan, cicilan terakhir, tarif berubah, denda manual/waiver, libur, rescheduling, pembayaran dan pembatalan. Jalankan cron dua kali untuk memastikan idempotensi.
- **KOL:** uji DPD tepat pada 0, 1, 90, 91, 120, 121, 180, 181; pembayaran hari ini/terlambat, pembayaran sebagian, beberapa pinjaman dan tanggal evaluasi WIB.
- **Persetujuan/mutasi:** Teller tidak dapat memutuskan; Admin dapat setuju/tolak; klik ganda tidak membuat dua mutasi/reschedule; nilai sebelum/sesudah dan pelaku ter-audit.
- **Rescheduling:** contoh hitungan yang telah disetujui; histori jadwal lama utuh; jumlah pokok/bunga/angsuran baru rekonsiliasi; pembayaran berbarengan dan pembatalan tidak mengubah saldo dua kali; KOL 1/Kol 5 tidak bisa menjalankan aksi Kol 2–4.
- **Saldo/laporan:** setelah pembayaran, pembatalan, waiver, pencairan dan reschedule, cocokkan saldo pinjaman, kas, transaksi, dashboard dan ekspor.
- **Tampilan/perangkat:** tablet portrait/landscape, sentuh/pena/mouse, berbagai DPR; seluruh field agunan dan PDF; browser desktop.
- **Transfer/identitas:** akun anggota hanya melihat data sendiri; CSRF; validasi referensi dan nominal; duplicate request; timeout; enumerasi NIK; log tidak berisi data identitas sensitif.
- **Database:** migrasi pada dump bersih dan skema sudah termigrasi; jalankan migrasi ulang; uji restore backup; verifikasi index/foreign key dan `EXPLAIN` untuk kueri dashboard/aging.

## Kriteria rilis

Rilis dilakukan per tahap, setelah backup dan semua gerbang tahap terpenuhi. Wajib tersedia: catatan migrasi, hasil uji yang dapat diulang, contoh sebelum/sesudah saldo, prosedur rollback/restore, dan persetujuan Admin atas nilai tagihan serta perubahan jadwal. Aktifkan pekerjaan berisiko melalui konfigurasi/feature flag, lalu amati rekonsiliasi sebelum memperluas cakupan data.

## Referensi kode yang ditinjau

- `config/config.php`, `config/database.php`, `config/env.php`
- `includes/pinjaman_helper.php`, `includes/denda_helper.php`, `includes/header.php`
- `includes/pencairan_page.php`, `includes/pengajuan_page.php`, `includes/angsuran_page.php`
- `includes/dashboard_helper.php`, `admin/dashboard.php`, `admin/laporan_kas.php`
- `database/migrations/MIGRASI_UNIVERSAL.sql`, `database/fixtures/`
- `tests/denda_test.php`, `tests/denda_jaminan_test.php`, `tests/gadai_tenor_6_bulan_test.php`, `tests/layanan_integration_test.php`, `tests/notifikasi_integration_test.php`
## Status implementasi per 25 November 2026

Implementasi masih berjalan dan **belum memenuhi seluruh permintaan PDF**. Berikut perubahan yang sudah dibuat setelah backup database:

- Pengajuan petugas memvalidasi minimum nasabah baru Rp1 juta/lanjutan Rp500 ribu, maksimum nasabah baru Rp2 juta, kenaikan lanjutan maksimal Rp2 juta dari pinjaman lunas terakhir, dan langkah Rp500 ribu. Admin/Teller dapat menyimpan batas lebih rendah per anggota; form menampilkan batas yang berlaku dan peringatan saat produk melebihi limit.
- Rumus AGTJ konsisten di PHP/JavaScript: biaya mingguan dan tambahan tenor lebih dari dua bulan. Denda AGTJ Rp10 ribu per Rp1 juta per minggu, pembulatan minggu, konfigurasi Admin, dan teks pada snapshot perjanjian pencairan sudah disesuaikan.
- Form jaminan HP menyimpan kapasitas memori; form motor memakai nama pemilik STNK. Nilai BPKB historis dipertahankan di teks arsip. Tanda tangan dibuat responsif. Kapasitas memori/STNK kini disimpan sebagai field terstruktur di snapshot dan dicetak terpisah pada perjanjian.
- Kalender libur Admin menerima tanggal Idulfitri/Iduladha/eksternal manual. Jadwal yang belum jatuh tempo disesuaikan sesuai frekuensi dan benturan tanggal; kalender belum menghitung tanggal hari raya otomatis.
- Pembayaran parsial petugas dicatat FIFO ke cicilan tertua, beserta pemisahan pokok/bunga, kas, saldo, audit, idempotensi, dan pembatalan Admin yang membalik seluruh pembayaran/alokasinya. Transfer anggota masuk status menunggu, pemilik pinjaman divalidasi, dan Teller/Admin dapat verifikasi/tolak. Bukti transfer disimpan.
- Rescheduling Admin dibatasi KOL 2–4; kontrak baru memakai sisa pokok, keuntungan yang belum lunas dibatasi hitungan ulang pada rate kontrak, tenor/cicilan digandakan, frekuensi dipertahankan, cicilan pertama tujuh hari kemudian, denda dibebaskan, DPD diwariskan, dan audit snapshot tersimpan. Kontrak asal diberi status `reschedule`; daftar angsuran rescheduling terpisah.
- Label/rentang KOL diperbarui. Halaman Monitoring KOL menampilkan detail per kontrak dan ringkasan per anggota: KOL terburuk, DPD tertinggi, jumlah pinjaman aktif, dan total sisa pokok.
- Job `cron/hitung_denda_otomatis.php` tersedia untuk CLI dengan lock dan log. Task Windows `SIMKEU_Hitung_Denda` sudah dipasang harian pukul 02.00; mode saat ini `Interactive only` sehingga akun Windows harus sedang login.

**Catatan saat pembaruan awal:** master kelompok penagihan belum tersedia saat status pertama ditulis. Fitur tersebut kini ditambahkan melalui migrasi khusus dan workflow di bawah.

**Baru diimplementasikan:** UI limit anggota dan alert dinamis; pengajuan pindah hari oleh anggota dengan persetujuan Admin, pengecekan tunggakan sebelum pengajuan/persetujuan, jadwal efektif paling cepat tujuh hari setelah persetujuan; pencocokan NIK internal untuk Admin/Teller dengan pembatasan 10 pencarian per 15 menit dan audit tanpa NIK penuh; laporan laba/kas berbasis kas dengan filter harian/bulanan/tahunan, pembesaran grafik, pencairan, collection rescheduling, dan pembebasan denda; field kapasitas memori/STNK terstruktur dalam arsip perjanjian; serta monitoring KOL dengan ringkasan gabungan per anggota.

## Pekerjaan tersisa sebelum rilis

1. Lakukan uji browser manual pada tablet, semua peran, alur pencairan/cetak, dan konfirmasi akuntansi pemilik proses.
2. Jika denda harus diperbarui ketika XAMPP/Windows tidak aktif, pasang MySQL dan job sebagai layanan Windows dengan kebijakan operasional yang disetujui. Saat ini XAMPP tidak berjalan sebagai Windows Service.
3. Mulai ulang Apache dari XAMPP Control Panel agar modul ZIP yang sudah diaktifkan pada `php.ini` juga tersedia di request web; proses Apache yang sedang berjalan masih melaporkan ZIP belum aktif.

## Perubahan lokal dan validasi

- Database: `simkeusi_koperasi_db`.
- Backup sebelum migrasi: `backups/simkeusi_koperasi_db_before_updates_20261125.sql`.
- Migrasi skema tambahan dan kelompok penagihan kini berada pada satu file: `database/migrations/MIGRASI_UNIVERSAL.sql`. Database lokal sudah menerapkan seluruh skema sebelum migrasi terpisah dihapus. Backup sebelum perubahan kelompok: `backups/simkeusi_koperasi_db_before_kelompok_angsuran_20261125.sql`.
- Nilai lokal `layanan_pengaturan.denda_jaminan_per_juta`: Rp10.000.
- Catatan ini adalah status sebelum audit terbaru; hasil tes setelah implementasi tercatat pada bagian **Hasil implementasi dan audit terbaru**.

## Hasil implementasi dan audit terbaru — 25 November 2026

### Yang sudah dikerjakan

- Kebijakan nominal baru/lanjutan, perubahan formula AGTJ (biaya mingguan dihitung dari lama tenor; tambahan tenor Rp10.000 per Rp1 juta per bulan), pembulatan denda mingguan, jadwal hari libur dan mutasi hari berjalan telah diterapkan.
- Alokasi pembayaran sebagian memakai FIFO; transfer anggota tetap menunggu verifikasi petugas. Rescheduling membentuk kontrak dan jadwal baru, mewariskan DPD, menghapus denda yang belum dibayar, dan menyimpan audit.
- Monitor KOL per kontrak dan ringkasan terburuk per anggota, laporan arus kas/laba, field jaminan, tanda tangan tablet, serta pencocokan internal NIK+nama+tanggal lahir tersedia. Lookup menyimpan empat digit akhir NIK saja dan dibatasi 10 percobaan per 15 menit. SLIK, scoring, OCR dan biometrik tidak diaktifkan.
- Teks tarif AGTJ sudah dibedakan sebagai per minggu pada ringkasan denda, kuitansi dan draft perjanjian. Biaya tenor mengikuti Rp10.000 per Rp1 juta per bulan untuk tenor di atas dua bulan. Preview JavaScript dan hitungan PHP menghasilkan angka yang sama pada tiga skenario AGTJ yang diperiksa.
- Master kelompok penagihan kini dipisahkan dari relasi SHU. Admin dapat membuat/menonaktifkan kelompok, pengajuan baru dapat menyimpan kelompok opsional, anggota dapat meminta mutasi kelompok pada pinjaman berjalan setelah tunggakan jatuh tempo lunas, dan Admin menyetujui/menolak. Pinjaman hasil rescheduling mewarisi kelompok saat ini; perubahan kelompok tidak mengedit jadwal atau histori.

### Verifikasi yang dijalankan

- Lint sintaks PHP: 317 file lulus; `git diff --check` lulus.
- Unit: denda harian, hak perubahan denda, cakupan denda, denda AGTJ, formula/pembulatan pinjaman, profil SHU, dan tabel angsuran lulus.
- Integrasi pada salinan database: layanan 47 pemeriksaan lulus dan rollback; notifikasi lulus; SHU 27 pemeriksaan lulus dan rollback; mutasi kelompok, penyimpanan kelompok pada pencairan baru, rescheduling dengan pewarisan kelompok, dan impor Excel idempoten lulus.
- Pemeriksaan transaksi sementara pada salinan DB: rescheduling menghasilkan jadwal baru dan cicilan pertama +7 hari; alokasi parsial mengikuti FIFO; limit nominal baru/lanjutan menolak nilai yang tidak sesuai; mutasi hari dan geser kalender mingguan berjalan. Seluruh perubahan pemeriksaan tersebut di-rollback.

### Batasan dan risiko operasional yang masih ada

1. Task Windows `SIMKEU_Hitung_Denda` berjalan harian pukul 02.00 hanya saat akun Windows login (`Interactive only`). XAMPP/MySQL tidak terpasang sebagai layanan Windows; komputer harus menyala, XAMPP berjalan, dan akun tetap login pada waktu job.
2. Ekstensi ZIP sudah diaktifkan pada `C:\xampp\php\php.ini` dan tersedia di PHP CLI. Apache aktif saat perubahan dibuat masih memuat konfigurasi lama; mulai ulang Apache melalui XAMPP Control Panel agar impor XLSX dari web memakai ZIP.
3. Audit mencakup tes PHP/database dan pemeriksaan statis; uji browser manual pada tablet, seluruh peran, alur pencairan/cetak, serta verifikasi akuntansi dengan pemilik proses belum dilakukan. Lulus tes bukan jaminan absolut bebas bug.

Database uji dan berkas dump sementara dari pemeriksaan sudah dihapus setelah tes; database operasional tidak dipakai untuk tes transaksi ini. Backup sebelum migrasi tetap tersedia pada `backups/simkeusi_koperasi_db_before_updates_20261125.sql`.
