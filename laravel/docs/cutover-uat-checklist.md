# Checklist UAT & cutover SIMKEU Laravel

Gunakan sebelum mematikan write di PHP native. Setup lokal dulu: lihat [README.md](../README.md).

## 1. Persiapan environment

- [ ] `NATIVE_REFERENCE_DATABASE` mengarah ke DB native **read-only clone** (bukan DB produksi Laravel)
- [ ] `QUEUE_CONNECTION=database` (atau `redis`) di produksi; jalankan `php artisan queue:work`
- [ ] Migrasi Laravel tambahan sudah jalan: `jobs`, `failed_jobs`, `job_batches`, `activity_logs`, `remember_token`, index hot-path, `kas.ref_type`
- [ ] Schedule aktif: `php artisan schedule:work` / cron `* * * * * php artisan schedule:run`
- [ ] `php artisan queue:work` berjalan (WA H-1 + `loans:refresh-penalties` antrean)
- [ ] Reset kata sandi publik **tetap ditutup** (parity native: hanya Admin/Teller yang mereset). Jangan aktifkan `/lupa-kata-sandi` tanpa keputusan produk.

## 2. Gate otomatis angka

```bash
php artisan simkeu:uat-gate
php artisan phase7:loan-parity
# UI admin: /portal/admin/laporan/validasi-data
```

- [ ] Gate lulus tanpa error
- [ ] Jika warning “native belum tersedia”: bandingkan manual kas/simpanan/sisa pinjaman

## 3. Smoke uji operasional (sample)

- [ ] Setoran + penarikan teller → saldo anggota & kas cocok
- [ ] Pengajuan → setuju → cair (TTD canvas + foto) → jadwal angsuran benar
- [ ] Bayar angsuran + keranjang + pembatalan same-day teller
- [ ] Denda auto (cron/refresh) + bayar denda
- [ ] Pelunasan + (opsional) pengembalian jaminan
- [ ] Layanan: macet / TC / prioritas sample
- [ ] SHU hitung + bayar sample kecil
- [ ] Laporan teller (UI + PDF): harian/simpanan/lunas/denda/waserda saja (bukan pinjaman/angsuran)
- [ ] Avatar anggota = foto pencairan (sidebar, daftar anggota, daftar pinjaman)
- [ ] WA H-1 dry-run (token sandbox) + cek `wa_pengingat_log`

## 4. Cutover

- [ ] Freeze write native (maintenance / readonly user)
- [ ] Delta sync terakhir (transaksi hari H) → gate ulang
- [ ] Switch DNS/vhost ke Laravel `public/`
- [ ] Monitor 24 jam: kas, angsuran, failed jobs, WA

## Perintah terkait

| Perintah | Fungsi |
|---|---|
| `simkeu:uat-gate` | Gate gabungan fase 6+7 + integritas |
| `phase7:uat-check` | Gate pinjaman saja |
| `phase7:loan-parity` | Cetak ringkasan parity pinjaman |
| `phase6:repair-parity --apply` | Koreksi known mismatch (hati-hati) |
| `loans:refresh-penalties` | Antrekan refresh denda (butuh `queue:work`) |
| `loans:refresh-penalties --sync` | Refresh denda langsung tanpa antrean |
| `wa:remind-due` | Kirim pengingat H-1 |
