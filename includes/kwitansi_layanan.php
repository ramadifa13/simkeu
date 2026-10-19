<?php
require_once __DIR__.'/../config/config.php';
require_once __DIR__.'/auth.php';
if (!isLoggedIn()) { header('Location: ../login.php');exit; }
if (!isAdmin() && !isTeller()) { http_response_code(403);exit('Akses ditolak.'); }
$jenis=$_GET['jenis'] ?? '';$id=(int)($_GET['id'] ?? 0);
$tables=['denda'=>'layanan_bayar_denda','bayar'=>'layanan_pembayaran','bast'=>'layanan_jaminan_kembali','lelang'=>'layanan_lelang','bonus'=>'layanan_bonus'];
if($jenis==='bonus' && !isAdmin()){http_response_code(403);exit('Khusus Admin.');}
if (!isset($tables[$jenis]) || !layananSiap($db)) { http_response_code(404);exit('Dokumen tidak ditemukan.'); }
$table=$tables[$jenis];
$alias=$jenis==='lelang'?'b.harga_jual AS jumlah,':'';
$stmt=$db->prepare("SELECT b.*,$alias p.kode_pinjaman,ag.nama_lengkap,ag.ktp,u.nama_lengkap teller FROM $table b JOIN pinjaman p ON p.id=b.pinjaman_id JOIN anggota ag ON ag.id=p.anggota_id JOIN users u ON u.id=b.user_id WHERE b.id=?");$stmt->execute([$id]);$b=$stmt->fetch(PDO::FETCH_ASSOC);
if (!$b || ($jenis==='denda' && !isAdmin() && (int)$b['user_id']!==(int)$_SESSION['user_id'])) { http_response_code(404);exit('Dokumen tidak ditemukan.'); }
if ($jenis==='bast' && $b['status']!=='diserahkan') { http_response_code(404);exit('BAST belum tersedia.'); }
if ($jenis==='bayar' && !empty($b['dibatalkan_at'])) { http_response_code(410);exit('Pembayaran ini sudah dibatalkan.'); }
$file=$jenis==='bast'?$b['foto']:$b['bukti'];
$root=realpath(__DIR__.'/../uploads');$path=$file?realpath($root.'/'.$file):false;
$foto='';if($path && strpos($path,$root.DIRECTORY_SEPARATOR)===0 && is_file($path)) {
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($path);
    if(in_array($mime,['image/jpeg','image/png','image/webp'],true)) $foto='data:'.$mime.';base64,'.base64_encode(file_get_contents($path));
}
$judul=$jenis==='bast'?'BERITA ACARA SERAH TERIMA JAMINAN':($jenis==='denda'?'KWITANSI PEMBAYARAN DENDA':'KWITANSI PEMBAYARAN');
$teksBast='';if($jenis==='bast'){$template=$db->query('SELECT bast_jaminan FROM layanan_pengaturan WHERE id=1')->fetchColumn();$teksBast=strtr((string)$template,['{nama}'=>$b['nama_lengkap'],'{norek}'=>$b['kode_pinjaman'],'{tanggal}'=>$b['diserahkan_at']]);}
header('Cache-Control: private, no-store');
?><!doctype html><html lang="id"><head><meta charset="utf-8"><title><?= $judul ?></title><style>body{font-family:Arial;max-width:800px;margin:30px auto;padding:15px}td{padding:7px}.foto{max-width:100%;max-height:400px}.ttd{width:250px}@media print{button{display:none}}</style></head><body>
<button onclick="window.print()">Cetak / Simpan PDF</button><h2><?= $judul ?></h2>
<?php if($teksBast): ?><p><?= nl2br(layananEscape($teksBast)) ?></p><?php endif; ?>
<table><tr><td>Nomor</td><td><?= strtoupper($jenis).'-'.str_pad($id,8,'0',STR_PAD_LEFT) ?></td></tr><tr><td>Nasabah</td><td><?= layananEscape($b['nama_lengkap']) ?></td></tr><tr><td>Pinjaman</td><td><?= layananEscape($b['kode_pinjaman']) ?></td></tr><tr><td>Teller</td><td><?= layananEscape($b['teller']) ?></td></tr><tr><td>Tanggal</td><td><?= layananEscape($jenis==='bast'?$b['diserahkan_at']:$b['created_at']) ?></td></tr>
<?php if($jenis!=='bast'): ?><tr><td>Jumlah dibayar</td><td><strong><?= formatRupiah($b['jumlah']) ?></strong></td></tr><tr><td>Metode</td><td><?= layananEscape($b['metode']) ?></td></tr><?php else: ?><tr><td>Pernyataan</td><td>Jaminan telah diperiksa fisiknya dan diserahkan kepada nasabah.</td></tr><?php endif; ?></table>
<?php if($foto): ?><h3>Foto Bukti</h3><img class="foto" src="<?= layananEscape($foto) ?>" alt="Foto bukti"><?php endif; ?>
<?php if($jenis==='bast'): ?><p>Tanda tangan nasabah penerima jaminan:</p><img class="ttd" src="<?= layananEscape($b['ttd']) ?>" alt="Tanda tangan nasabah"><?php endif; ?>
</body></html>
