<?php
require __DIR__ . '/../includes/denda_helper.php';

// Uji cakupan query tanpa membutuhkan database hosting.
function kolomAda($db, $tabel, $kolom) { return true; }
class ScopeStatement {
    private $db;
    private $sql;
    function __construct($db, $sql) { $this->db = $db; $this->sql = $sql; }
    function execute($params = []) { $this->db->queries[] = [$this->sql, $params]; }
    function fetchAll($mode) { return []; }
}
class ScopeDatabase {
    public $queries = [];
    function prepare($sql) { return new ScopeStatement($this, $sql); }
}
function pastikan($ok, $pesan) {
    if (!$ok) { fwrite(STDERR, $pesan . "\n"); exit(1); }
}
$db = new ScopeDatabase();
perbaruiDendaOtomatis($db, false, 123);
pastikan(count($db->queries) === 1, 'Pembaruan satu pinjaman tidak boleh membaca/menulis penanda harian');
pastikan(strpos($db->queries[0][0], 'AND a.pinjaman_id = ?') !== false, 'Query wajib membatasi pinjaman');
pastikan($db->queries[0][1] === [date('Y-m-d'), 123], 'Parameter pinjaman harus diteruskan');
$db = new ScopeDatabase();
perbaruiDendaOtomatis($db, true);
pastikan(count($db->queries) === 2, 'Pembaruan global tetap menulis penanda harian');
pastikan(strpos($db->queries[0][0], 'AND a.pinjaman_id = ?') === false, 'Pembaruan global mencakup seluruh pinjaman');
pastikan(strpos($db->queries[1][0], 'INSERT INTO settings') !== false, 'Penanda harian harus disimpan');
echo "Semua uji cakupan denda lulus.\n";
