# Aturan Bisnis Native yang Harus Dipertahankan

Semua nilai uang memakai decimal/Rupiah; mutasi finansial harus berada dalam transaksi database dan menggunakan lock pada record yang berubah. Aturan di bawah diambil dari helper native dan menjadi acceptance criteria Fase 6–8.

## Perhitungan pinjaman dan jadwal

- Frekuensi `bulanan`, `mingguan`, `dua_mingguan` menghasilkan jumlah cicilan: tenor bulan × 1, × 4, atau × 2.
- Skema `flat`: total bunga = pembulatan `pokok × bunga%`; total = pokok + bunga. Cicilan dan pokok dibulatkan per Rupiah; sisa pembulatan selalu dialokasikan ke cicilan terakhir agar total pokok dan total tagihan tepat.
- Skema `tahunan` adalah rumus historis: bunga = pokok × bunga% × tenor/12. Nilai historis tidak boleh dihitung ulang dengan skema baru.
- Gadai hanya untuk kelipatan Rp1.000.000; biaya mingguan = Rp5.000 + (jumlah juta × Rp5.000), dikalikan tenor×4 dan ditambahkan pada bunga dasar sebelum jadwal dihitung.
- Jatuh tempo mingguan/dua mingguan = +7/+14 hari per cicilan. Bulanan mempertahankan tanggal tanggal awal atau memakai akhir bulan bila tanggal tersebut tidak ada.
- Ajuan yang disetujui menjadi satu pinjaman dan satu jadwal secara atomik; satu pengajuan tidak boleh menghasilkan dua pinjaman. Jadwal mengikuti tanggal pengajuan yang disetujui, meskipun pencairan kemudian terjadi pada tanggal berbeda.
- Cicilan di muka adalah histori migrasi: ditandai lunas pada tanggal jatuh tempo, mengurangi sisa pokok, tanpa membuat transaksi/kas baru.

## Status dan reversal pinjaman

| Objek | Status/aksi | Kontrak |
| --- | --- | --- |
| Pengajuan | `menunggu` → `disetujui`/`ditolak` → `dicairkan` atau `batal_cair` | Hanya Admin memutuskan persetujuan; pencairan hanya dari pengajuan yang valid. |
| Pinjaman | `aktif`, `lunas`, `macet` | `sisa_pokok` dan status hanya berubah melalui pembayaran, pelunasan, atau workflow layanan. |
| Angsuran | `belum_bayar`, `telat`, `lunas`; cara `cicilan`/`pelunasan` | Pembayaran memperbarui angsuran, pinjaman, transaksi, kas dan audit atomik. |
| Pembatalan | Teller ajukan pada hari bayar; Admin setuju/tolak atau batal langsung | Reversal meniadakan nilai transaksi/kas terkait, menyesuaikan saldo berantai, mencatat `log_koreksi`, lalu mengaktifkan kembali jadwal/pinjaman dengan denda yang dihitung ulang. |
| Closing | `dibuka`/`terkunci` per `YYYY-MM` | Mutasi pada bulan terkunci ditolak. Lock menyimpan snapshot angsuran dan total; unlock Admin menghapus detail snapshot agar lock ulang menghitung ulang. |

## Denda, prioritas, dan layanan

- Denda otomatis hanya untuk angsuran belum bayar/telat dan tidak pernah menimpa `denda_manual`.
- Tanpa jaminan memakai `denda_tarif`; nominal memakai tier terdekat di bawah. Dengan jaminan memakai kelipatan juta penuh × tarif konfigurasi per juta.
- Hari denda dimulai setelah jatuh tempo, dibatasi hingga jatuh tempo cicilan berikutnya; cicilan akhir terus berjalan. Teller tidak dapat mendiskon/mengubah denda.
- Reschedule hanya Admin, tanggal baru harus setelah tanggal lama dan hari ini; menggeser semua angsuran tersisa tanpa memutihkan denda terutang.
- Pelaporan macet butuh keterlambatan dan catatan minimal; satu laporan menunggu per pinjaman. Keputusan Admin: `ditindaklanjuti`, `selesai`, atau `ditolak`.
- Penghapusan denda harus melalui pengajuan dan keputusan Admin, hanya bila pemutihan aktif; nilai denda akhir tidak boleh kurang dari total denda yang sudah dibayar.
- Jaminan: eksekusi dahulu, baru lelang. Hasil lelang dialokasikan berurutan ke angsuran; kelebihan adalah hak anggota dan pengembaliannya dicatat sebagai kas keluar/bukti.
- Bonus hanya pinjaman lunas tanpa jaminan, sekali per pinjaman, nominal server-side = bunga diterima × persentase konfigurasi.
- Repeat order hanya pinjaman lunas dengan catatan; satu catatan aktif per pinjaman.

## SHU, dokumen, dan notifikasi

- Alokasi SHU memakai pembulatan largest-remainder deterministik agar jumlah hak tepat sama dengan pool Rupiah.
- Periode berubah `draft` → `dihitung` → `final`; pengesahan butuh periode selesai, referensi RAT, konfirmasi, dan hash snapshot yang masih sama. Final tidak diedit.
- Pembayaran SHU memakai nonce unik, bukti, transaksi kas dan audit; pembatalan adalah reversal setelah konfirmasi dana kembali.
- Perjanjian menyimpan snapshot JSON, hash dokumen, saksi, dan tanda tangan; setelah ditandatangani diperlakukan immutable.
- Pengingat WhatsApp H-1 hanya untuk pinjaman aktif/angsuran belum lunas; normalisasi nomor 62, deduplikasi per angsuran+tanggal, simpan hasil/gagal dan percobaan.
