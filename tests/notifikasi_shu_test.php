<?php
require __DIR__.'/../includes/shu_pinjaman_helper.php';
$gagal=0;
function cekRevisi($kondisi,$nama){global $gagal;echo ($kondisi?'OK   ':'GAGAL').' '.$nama.PHP_EOL;if(!$kondisi)$gagal++;}
cekRevisi(shuProfilUntukKode('AGTJ001','ketua_kelompok',99)===['anggota',null],'AGTJ tidak dapat memperoleh peran SHU atau ketua');
cekRevisi(shuProfilUntukKode('AGT0020','ketua_kelompok',99)===['ketua_kelompok',99],'AGT biasa tetap dapat menjadi ketua');
cekRevisi(shuProfilUntukKode('AGT0020','anggota',99)===['anggota',99],'AGT biasa tetap dapat memilih ketua');
if($gagal)exit(1);
echo "Semua aturan profil SHU lulus.\n";
