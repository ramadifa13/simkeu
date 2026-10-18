<?php
require __DIR__ . '/../includes/denda_helper.php';

function pastikanDenda($expected, $actual, $label) {
    if ((float) $expected !== (float) $actual) {
        throw new RuntimeException($label . ': harapan ' . $expected . ', hasil ' . $actual);
    }
}

class DendaJaminanStatement {
    private $value;
    function __construct($value) { $this->value = $value; }
    function execute($params = []) {}
    function fetchColumn() { return $this->value; }
    function fetchAll($mode) { return $this->value; }
}
class DendaJaminanDatabase {
    public $jaminan = 1;
    public $berikutnya = '2026-10-17';
    function prepare($sql) {
        return new DendaJaminanStatement(strpos($sql, 'MIN(') !== false ? $this->berikutnya : $this->jaminan);
    }
    function query($sql) {
        return new DendaJaminanStatement([
            ['jumlah_pinjaman' => 1000000, 'denda_per_hari' => 1500],
            ['jumlah_pinjaman' => 2000000, 'denda_per_hari' => 2500],
            ['jumlah_pinjaman' => 3000000, 'denda_per_hari' => 3500],
        ]);
    }
}

$db = new DendaJaminanDatabase();
foreach ([1, 2, 3, 4, 10, 25] as $juta) {
    pastikanDenda($juta * 5000, dendaPerHari($db, $juta * 1000000, true), 'Tarif jaminan ' . $juta . ' juta');
}
pastikanDenda(1500, dendaPerHari($db, 1000000), 'Tanpa jaminan tetap tarif lama');
pastikanDenda(3500, dendaPerHari($db, 3000000), 'Tanpa jaminan 3 juta');
$a = ['pinjaman_id' => 7, 'angsuran_ke' => 1, 'tanggal_jatuh_tempo' => '2026-10-10'];
foreach (['2026-10-09' => 0, '2026-10-10' => 0, '2026-10-11' => 15000, '2026-10-13' => 45000, '2026-10-18' => 105000] as $date => $expected) {
    $hasil = hitungDendaAngsuran($db, $a, 3000000, $date);
    pastikanDenda($expected, $hasil['denda'], 'Denda jaminan tanggal ' . $date);
}
$db->berikutnya = false;
$hasil = hitungDendaAngsuran($db, $a, 3000000, '2026-10-30');
pastikanDenda(300000, $hasil['denda'], 'Cicilan terakhir terus berjalan');
$db->jaminan = 0;
$hasil = hitungDendaAngsuran($db, $a, 3000000, '2026-10-11');
pastikanDenda(3500, $hasil['denda'], 'Deteksi tanpa jaminan dari pinjaman');
echo "Semua uji denda jaminan lulus.\n";
