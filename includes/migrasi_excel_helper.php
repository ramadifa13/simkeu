<?php
/** Pembaca XLSX ringan khusus pratinjau migrasi; tidak menulis data database. */
if (!function_exists('migrasiKolomKeAngka')) {
    function migrasiKolomKeAngka($letters) {
        $n = 0;
        foreach (str_split($letters) as $letter) $n = ($n * 26) + (ord($letter) - 64);
        return $n;
    }
    function migrasiNilaiCell($cell, $shared) {
        $type = (string)$cell['t'];
        if ($type === 'inlineStr') {
            // XLSX yang dibuat Excel/OpenPyXL dapat menyimpan teks sebagai
            // inline string. Gunakan local-name agar tetap terbaca meski XML
            // memakai namespace berbeda.
            $texts = $cell->xpath('./*[local-name()="is"]/*[local-name()="t"]');
            return trim($texts ? implode('', array_map('strval', $texts)) : '');
        }
        $value = (string)$cell->v;
        return $type === 's' ? ($shared[(int)$value] ?? '') : $value;
    }
    function migrasiBacaXlsx($path) {
        if (!class_exists('ZipArchive')) throw new RuntimeException('Ekstensi ZIP PHP belum aktif.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('File bukan XLSX yang valid.');
        $shared = [];
        if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
            $sx = simplexml_load_string($xml);
            foreach ($sx->si as $si) {
                $texts = $si->xpath('.//*[local-name()="t"]');
                $shared[] = trim($texts ? implode('', array_map('strval', $texts)) : '');
            }
        }
        $workbook = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
        $map = [];
        $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($rels->xpath('//p:Relationship') as $rel) {
            $target = (string)$rel['Target'];
            $map[(string)$rel['Id']] = (substr($target, 0, 1) === '/') ? ltrim($target, '/') : 'xl/' . $target;
        }
        $ns = $workbook->getNamespaces(true);
        $sheets = [];
        $workbook->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        foreach ($workbook->xpath('//m:sheets/m:sheet') as $sheet) {
            $attrs = $sheet->attributes($ns['r']); $rid = (string)$attrs['id']; $target = $map[$rid] ?? '';
            if (!$target || ($xml = $zip->getFromName($target)) === false) continue;
            $sx = simplexml_load_string($xml); $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'); $rows = [];
            foreach ($sx->xpath('//m:sheetData/m:row') as $row) {
                $one = [];
                foreach ($row->c as $cell) {
                    preg_match('/([A-Z]+)/', (string)$cell['r'], $m);
                    $one[migrasiKolomKeAngka($m[1] ?? 'A')] = migrasiNilaiCell($cell, $shared);
                }
                $rows[(int)$row['r']] = $one;
            }
            $sheets[(string)$sheet['name']] = $rows;
        }
        $zip->close();
        return $sheets;
    }
    function migrasiRingkasanXlsx($path) {
        $sheets = migrasiBacaXlsx($path);
        $required = ['Konfigurasi_Koperasi','Master_Produk','Master_Simpanan','Master_Denda','Pendanaan_Dapin','Alokasi_Dapin','Nasabah','Pinjaman','Angsuran_Historis','Saldo_Awal_Kas'];
        $errors = []; $warnings = []; $counts = [];
        foreach ($required as $name) {
            if (!isset($sheets[$name])) { $errors[] = "Sheet wajib '$name' tidak ditemukan."; continue; }
            $rows = $sheets[$name]; $count = 0;
            foreach ($rows as $number => $row) if ($number >= 3 && count(array_filter($row, function($v) { return trim((string)$v) !== ''; })) > 0) $count++;
            $counts[$name] = $count;
            // Alokasi Dapin boleh kosong karena sebagian pinjaman memakai modal
            // dari luar Dapin. Sheet tetap wajib ada, tetapi tidak wajib berisi baris.
            if ($count === 0 && $name !== 'Alokasi_Dapin') $errors[] = "Sheet '$name' belum berisi data.";
        }
        foreach (['Nasabah'=>1,'Pinjaman'=>1,'Pendanaan_Dapin'=>1] as $sheet => $column) {
            if (!isset($sheets[$sheet])) continue;
            $seen = [];
            foreach ($sheets[$sheet] as $number => $row) {
                if ($number < 3 || trim((string)($row[$column] ?? '')) === '') continue;
                $value = trim((string)$row[$column]);
                if (isset($seen[$value])) $errors[] = "Duplikat '$value' pada sheet $sheet (baris $number dan {$seen[$value]}).";
                $seen[$value] = $number;
            }
        }
        $records = [];
        foreach ($required as $name) {
            if (!isset($sheets[$name])) continue;
            $headers = $sheets[$name][2] ?? []; $headers = array_map(function($v) { return trim((string)$v); }, $headers);
            $data = [];
            foreach ($sheets[$name] as $number => $row) {
                if ($number < 3 || count(array_filter($row, function($v) { return trim((string)$v) !== ''; })) === 0) continue;
                $one = ['_baris'=>$number];
                foreach ($headers as $column => $header) if ($header !== '') $one[$header] = trim((string)($row[$column] ?? ''));
                $data[] = $one;
            }
            $records[$name] = $data;
        }
        $index = function($sheet, $key) use ($records) {
            $out = []; foreach ($records[$sheet] ?? [] as $row) { $value=trim((string)($row[$key] ?? '')); if ($value !== '') $out[$value]=$row; } return $out;
        };
        $nasabah = $index('Nasabah', 'ID Nasabah Lama*');
        $produk = $index('Master_Produk', 'Kode Produk*');
        $pinjaman = $index('Pinjaman', 'ID Pinjaman Lama*');
        $dapin = $index('Pendanaan_Dapin', 'ID Dapin*');
        foreach ($records['Pinjaman'] ?? [] as $row) {
            $r=$row['_baris'];
            if (empty($nasabah[$row['ID Nasabah Lama*'] ?? ''])) $errors[]="Pinjaman baris $r memakai ID nasabah yang tidak ditemukan.";
            if (empty($produk[$row['Kode Produk*'] ?? ''])) $errors[]="Pinjaman baris $r memakai Kode Produk yang tidak ditemukan.";
            $p = $produk[$row['Kode Produk*'] ?? ''] ?? null;
            if ($p) {
                // Pinjaman historis boleh memakai produk terdekat meskipun aturan
                // lama (frekuensi/skema) berbeda. Jangan blokir migrasi; tandai
                // agar user tetap dapat merekonsiliasi perhitungannya.
                if (($row['Frekuensi*'] ?? '') !== ($p['Frekuensi*'] ?? '')) $warnings[]="Pinjaman baris $r: frekuensi historis berbeda dari Master_Produk; nilai Excel dipertahankan.";
                if (($row['Skema Bunga*'] ?? '') !== ($p['Skema Bunga*'] ?? '')) $warnings[]="Pinjaman baris $r: skema bunga historis berbeda dari Master_Produk; nilai Excel dipertahankan.";
            }
        }
        foreach ($records['Angsuran_Historis'] ?? [] as $row) {
            if (empty($pinjaman[$row['ID Pinjaman Lama*'] ?? ''])) $errors[]="Angsuran baris {$row['_baris']} memakai ID pinjaman yang tidak ditemukan.";
        }
        $seenPembayaran = [];
        foreach ($records['Angsuran_Historis'] ?? [] as $row) {
            $idPembayaran = trim((string)($row['ID Pembayaran'] ?? ''));
            if ($idPembayaran === '') { $errors[] = "Angsuran baris {$row['_baris']} belum memiliki ID Pembayaran."; continue; }
            if (isset($seenPembayaran[$idPembayaran])) $errors[] = "Duplikat ID Pembayaran '$idPembayaran' pada sheet Angsuran_Historis (baris {$seenPembayaran[$idPembayaran]} dan {$row['_baris']}).";
            $seenPembayaran[$idPembayaran] = $row['_baris'];
        }
        foreach ($records['Alokasi_Dapin'] ?? [] as $row) {
            if (empty($pinjaman[$row['ID Pinjaman Lama*'] ?? ''])) $errors[]="Alokasi Dapin baris {$row['_baris']} memakai ID pinjaman yang tidak ditemukan.";
            if (empty($dapin[$row['ID Dapin*'] ?? ''])) $errors[]="Alokasi Dapin baris {$row['_baris']} memakai ID Dapin yang tidak ditemukan.";
        }
        $uang = function($value) {
            if (is_numeric($value)) return (float)$value;
            $v = preg_replace('/[^0-9,.-]/', '', (string)$value);
            if (substr_count($v, '.') > 1) $v = str_replace('.', '', $v);
            if (substr_count($v, ',') > 1) $v = str_replace(',', '', $v);
            if (strpos($v, ',') !== false && strpos($v, '.') !== false) $v = str_replace(',', '', $v);
            elseif (strpos($v, ',') !== false) $v = str_replace(',', '.', $v);
            return is_numeric($v) ? (float)$v : 0.0;
        };
        $totalCair=0; $totalTagihan=0; foreach ($records['Pinjaman'] ?? [] as $row) { $totalCair += $uang($row['Plafon (Rp)*'] ?? 0); $totalTagihan += $uang($row['Total Tagihan (Rp)*'] ?? 0); }
        // Satu baris historis adalah satu penerimaan aktual. Toleransi status
        // membantu file Excel yang memakai variasi kapital/spasi; nominal hanya
        // dihitung bila ada total diterima dan tanggal bayar.
        $pembayaranAktual = function($row) use ($uang) {
            $status = strtolower(trim((string)($row['Status*'] ?? '')));
            $total = $uang($row['Total Diterima (Rp)*'] ?? 0);
            $tanggal = trim((string)($row['Tanggal Bayar*'] ?? ''));
            return $total > 0 && $tanggal !== '' && ($status === '' || in_array($status, ['dibayar','lunas','pelunasan'], true));
        };
        $angsuran=0; foreach ($records['Angsuran_Historis'] ?? [] as $row) if ($pembayaranAktual($row)) $angsuran += $uang($row['Total Diterima (Rp)*'] ?? 0);
        $modalDapin=0; $wajibDapin=0; foreach ($records['Pendanaan_Dapin'] ?? [] as $row) { $modalDapin += $uang($row['Modal Diterima (Rp)*'] ?? 0); $wajibDapin += $uang($row['Total Wajib Kembali (Rp)*'] ?? 0); }
        $alokasiModal=0; $alokasiWajib=0; foreach ($records['Alokasi_Dapin'] ?? [] as $row) { $alokasiModal += $uang($row['Modal Dialokasikan (Rp)*'] ?? 0); $alokasiWajib += $uang($row['Kewajiban Dapin (Rp)*'] ?? 0); }
        if (count($records['Alokasi_Dapin'] ?? []) === 0) $warnings[]='Tidak ada alokasi Dapin. Pinjaman tanpa alokasi dianggap memakai modal di luar Dapin.';
        foreach ($records['Saldo_Awal_Kas'] ?? [] as $saldo) {
            if ($uang($saldo['Saldo Kas Fisik (Rp)*'] ?? 0) <= 0) $warnings[]='Saldo awal kas masih Rp0. Isi sesuai uang kas fisik pada tanggal cut-off jika memang ada.';
        }
        if ($alokasiModal > $modalDapin + 0.01) $errors[]='Total modal Dapin yang dialokasikan melebihi modal Dapin yang dicatat.';
        $bayarPinjaman = [];
        foreach ($records['Angsuran_Historis'] ?? [] as $row) if ($pembayaranAktual($row)) {
            $id=$row['ID Pinjaman Lama*'] ?? ''; $bayarPinjaman[$id]=($bayarPinjaman[$id] ?? 0)+$uang($row['Total Diterima (Rp)*'] ?? 0);
        }
        foreach ($pinjaman as $id=>$row) {
            $dibayar=$bayarPinjaman[$id] ?? 0; $tagihan=$uang($row['Total Tagihan (Rp)*'] ?? 0);
            if ($dibayar > $tagihan + 0.01) $errors[]="Pinjaman $id: total pembayaran melebihi total tagihan.";
            if (($row['Status Saat Ini*'] ?? '') === 'lunas' && abs($dibayar-$tagihan) > 0.01) $warnings[]="Pinjaman $id berstatus lunas, tetapi total pembayaran Rp ".number_format($dibayar,0,',','.')." belum sama dengan total tagihan Rp ".number_format($tagihan,0,',','.').". Periksa diskon/pelunasan.";
        }
        foreach ($records as $sheet=>$rows) foreach ($rows as $row) foreach ($row as $value) {
            if (is_string($value) && stripos($value, 'CONTOH') !== false) { $warnings[]="Sheet $sheet masih memuat data CONTOH. Ganti atau hapus sebelum import final."; break 2; }
        }
        return ['counts'=>$counts, 'errors'=>$errors, 'sheet_names'=>array_keys($sheets), 'summary'=>[
            'pencairan'=>$totalCair, 'tagihan_nasabah'=>$totalTagihan, 'bunga_kotor'=>$totalTagihan-$totalCair,
            'angsuran_diterima'=>$angsuran, 'modal_dapin'=>$modalDapin, 'wajib_dapin'=>$wajibDapin,
            'modal_dapin_dialokasikan'=>$alokasiModal, 'wajib_dapin_dialokasikan'=>$alokasiWajib,
            'laba_bersih_proyeksi'=>$totalTagihan-$alokasiWajib
        ], 'warnings'=>array_values(array_unique($warnings))];
    }
}
