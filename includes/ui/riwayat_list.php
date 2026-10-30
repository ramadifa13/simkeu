<?php
/**
 * Shared paginated riwayat transaksi (setoran/penarikan) — UI only, tidak mengubah posting.
 *
 * @return array{rows:array,pager:array,q:string,filters:array}
 */
function ui_riwayat_transaksi(PDO $db, string $jenisTransaksi, array $opts = []): array
{
    $client = !empty($opts['client']); // DataTables client-side: tanpa filter/pager server
    $ui = ui_query_params(['jenis_id', 'mulai', 'sampai']);
    $q = $client ? '' : $ui['q'];
    $jenisId = $client ? 0 : (int)($ui['filters']['jenis_id'] ?? 0);
    $mulai = $client ? '' : ($ui['filters']['mulai'] ?? '');
    $sampai = $client ? '' : ($ui['filters']['sampai'] ?? '');
    $exclude = $opts['exclude_kode'] ?? ['ANG', 'BONUS', 'SHU'];

    $where = " WHERE t.jenis_transaksi = :jenis AND j.kode_jenis NOT IN ('" . implode("','", array_map('addslashes', $exclude)) . "')";
    $params = [':jenis' => $jenisTransaksi];
    if ($q !== '') {
        $where .= ' AND (a.nama_lengkap LIKE :q1 OR a.kode_anggota LIKE :q2 OR t.kode_transaksi LIKE :q3 OR t.keterangan LIKE :q4)';
        $params[':q1'] = $params[':q2'] = $params[':q3'] = $params[':q4'] = '%' . $q . '%';
    }
    if ($jenisId > 0) {
        $where .= ' AND t.jenis_simpanan_id = :jenis_id';
        $params[':jenis_id'] = $jenisId;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai)) {
        $where .= ' AND DATE(t.tanggal_transaksi) >= :mulai';
        $params[':mulai'] = $mulai;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
        $where .= ' AND DATE(t.tanggal_transaksi) <= :sampai';
        $params[':sampai'] = $sampai;
    }

    $from = " FROM transaksi t
              JOIN anggota a ON t.anggota_id = a.id
              JOIN jenis_simpanan j ON t.jenis_simpanan_id = j.id
              JOIN users u ON t.user_id = u.id
              $where";

    $count = $db->prepare('SELECT COUNT(*)' . $from);
    foreach ($params as $k => $v) {
        $count->bindValue($k, $v);
    }
    $count->execute();
    $total = (int)$count->fetchColumn();

    if ($client) {
        $limit = max(1, min(2000, (int)($opts['limit'] ?? 1000)));
        $pager = ui_paginate($total, 1, $limit);
    } else {
        $pager = ui_paginate($total, $ui['page'], $ui['per_page']);
    }

    $sql = "SELECT t.*, a.nama_lengkap, a.kode_anggota, j.nama_jenis, u.nama_lengkap AS petugas
            $from
            ORDER BY t.tanggal_transaksi DESC
            LIMIT {$pager['per_page']} OFFSET {$pager['offset']}";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();

    return [
        'rows' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'pager' => $pager,
        'q' => $q,
        'filters' => [
            'jenis_id' => $jenisId ? (string)$jenisId : '',
            'mulai' => $mulai,
            'sampai' => $sampai,
        ],
        'client' => $client,
    ];
}

/**
 * Riwayat waserda/pembelian dengan search + pagination.
 * @param array{client?:bool,limit?:int} $opts
 */
function ui_riwayat_waserda(PDO $db, array $opts = []): array
{
    $client = !empty($opts['client']);
    $ui = ui_query_params(['mulai', 'sampai']);
    $q = $client ? '' : $ui['q'];
    $mulai = $client ? '' : ($ui['filters']['mulai'] ?? '');
    $sampai = $client ? '' : ($ui['filters']['sampai'] ?? '');
    $where = ' WHERE 1=1';
    $params = [];
    if ($q !== '') {
        $where .= ' AND (a.nama_lengkap LIKE :q1 OR a.kode_anggota LIKE :q2 OR w.kode_transaksi LIKE :q3)';
        $params[':q1'] = $params[':q2'] = $params[':q3'] = '%' . $q . '%';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $mulai)) {
        $where .= ' AND DATE(w.created_at) >= :mulai';
        $params[':mulai'] = $mulai;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $sampai)) {
        $where .= ' AND DATE(w.created_at) <= :sampai';
        $params[':sampai'] = $sampai;
    }
    $from = " FROM waserda_transaksi w
              JOIN anggota a ON w.anggota_id = a.id
              JOIN users u ON w.user_id = u.id
              $where";
    $count = $db->prepare('SELECT COUNT(*)' . $from);
    foreach ($params as $k => $v) {
        $count->bindValue($k, $v);
    }
    $count->execute();
    $total = (int)$count->fetchColumn();
    if ($client) {
        $limit = max(1, min(2000, (int)($opts['limit'] ?? 1000)));
        $pager = ui_paginate($total, 1, $limit);
    } else {
        $pager = ui_paginate($total, $ui['page'], $ui['per_page']);
    }
    $sql = "SELECT w.*, a.nama_lengkap, a.kode_anggota, u.nama_lengkap AS petugas
            $from ORDER BY w.created_at DESC
            LIMIT {$pager['per_page']} OFFSET {$pager['offset']}";
    $stmt = $db->prepare($sql);
    foreach ($params as $k => $v) {
        $stmt->bindValue($k, $v);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    return [
        'rows' => $rows,
        'pager' => $pager,
        'q' => $q,
        'filters' => ['mulai' => $mulai, 'sampai' => $sampai],
        'client' => $client,
    ];
}
