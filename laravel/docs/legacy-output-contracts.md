# Kontrak Keluaran PDF, Excel, dan Bukti

Keluaran bukan sekadar tampilan cetak. Query, filter, pembulatan, policy, dan watermark/status pembatalan harus berasal dari sumber yang sama dengan layar domain.

| Keluaran native | Domain / fase | Kontrak Laravel |
| --- | --- | --- |
| Bukti angsuran, buku angsuran, bukti pelunasan, slip pencairan | Pinjaman / 7 | Endpoint detail berpolicy, angka dari snapshot pembayaran/jadwal, PDF server-side, akses anggota hanya untuk miliknya. |
| Draft/perjanjian pinjaman, lampiran, BAST jaminan | Legal/jaminan / 7–8 | Snapshot dan hash dipakai; tanda tangan/media privat; keluaran ulang tidak mengubah isi kontrak. |
| Laporan pinjaman, angsuran, lunas, denda | Kredit / 7 | Filter periode/status/anggota, pagination pada layar dan ekspor seluruh hasil terfilter. |
| Laporan anggota, simpanan, transaksi, kas, harian | Anggota/ledger / 5–6 | Policy Admin/Teller sesuai sumber; total menggunakan query yang sama dengan tabel. |
| Kwitansi layanan dan SHU, laporan SHU | Layanan/SHU / 8 | Hanya record final/pembayaran sah; pembatalan tampil jelas dan tidak menimpa artefak lama. |
| Export anggota, kas, simpanan, transaksi, pinjaman, angsuran, laporan harian | Export / 5–8 | Excel mengikuti filter URL, nama file aman, streaming, policy resource dan audit unduhan. |
| Template/import/backup Excel dan ZIP | Migrasi / 8 | Admin saja; template berversi, dry-run sebelum tulis, import idempoten; backup tidak mengekspos file privat tanpa otorisasi. |

Semua tombol ekspor/cetak di tabel berada dalam menu tiga titik per baris atau toolbar tabel; aksi primer seperti membuat transaksi/pengajuan tidak disembunyikan di menu tersebut.
