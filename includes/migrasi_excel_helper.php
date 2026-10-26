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
        if ($type === 'inlineStr') return trim((string)$cell->is->t);
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
            foreach ($sx->si as $si) $shared[] = trim(implode('', $si->xpath('.//t')));
        }
        $workbook = simplexml_load_string($zip->getFromName('xl/workbook.xml'));
        $rels = simplexml_load_string($zip->getFromName('xl/_rels/workbook.xml.rels'));
        $map = [];
        $rels->registerXPathNamespace('p', 'http://schemas.openxmlformats.org/package/2006/relationships');
        foreach ($rels->xpath('//p:Relationship') as $rel) {
            $target = (string)$rel['Target'];
            $map[(string)$rel['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
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
            foreach ($rows as $number => $row) if ($number >= 3 && count(array_filter($row, fn($v) => trim((string)$v) !== '')) > 0) $count++;
            $counts[$name] = $count;
            if ($count === 0) $errors[] = "Sheet '$name' belum berisi data.";
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
            $headers = $sheets[$name][2] ?? []; $headers = array_map(fn($v) => trim((string)$v), $headers);
            $data = [];
            foreach ($sheets[$name] as $number => $row) {
                if ($number < 3 || count(array_filter($row, fn($v) => trim((string)$v) !== '')) === 0) continue;
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
                if (($row['Frekuensi*'] ?? '') !== ($p['Frekuensi*'] ?? '')) $errors[]="Pinjaman baris $r: frekuensi tidak sesuai Master_Produk.";
                if (($row['Skema Bunga*'] ?? '') !== ($p['Skema Bunga*'] ?? '')) $errors[]="Pinjaman baris $r: skema bunga tidak sesuai Master_Produk.";
            }
        }
        foreach ($records['Angsuran_Historis'] ?? [] as $row) {
            if (empty($pinjaman[$row['ID Pinjaman Lama*'] ?? ''])) $errors[]="Angsuran baris {$row['_baris']} memakai ID pinjaman yang tidak ditemukan.";
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
            if (str_contains($v, ',') && str_contains($v, '.')) $v = str_replace(',', '', $v);
            elseif (str_contains($v, ',')) $v = str_replace(',', '.', $v);
            return is_numeric($v) ? (float)$v : 0.0;
        };
        $totalCair=0; $totalTagihan=0; foreach ($records['Pinjaman'] ?? [] as $row) { $totalCair += $uang($row['Plafon (Rp)*'] ?? 0); $totalTagihan += $uang($row['Total Tagihan (Rp)*'] ?? 0); }
        $angsuran=0; foreach ($records['Angsuran_Historis'] ?? [] as $row) if (($row['Status*'] ?? '') === 'dibayar') $angsuran += $uang($row['Total Diterima (Rp)*'] ?? 0);
        $modalDapin=0; $wajibDapin=0; foreach ($records['Pendanaan_Dapin'] ?? [] as $row) { $modalDapin += $uang($row['Modal Diterima (Rp)*'] ?? 0); $wajibDapin += $uang($row['Total Wajib Kembali (Rp)*'] ?? 0); }
        $alokasiModal=0; $alokasiWajib=0; foreach ($records['Alokasi_Dapin'] ?? [] as $row) { $alokasiModal += $uang($row['Modal Dialokasikan (Rp)*'] ?? 0); $alokasiWajib += $uang($row['Kewajiban Dapin (Rp)*'] ?? 0); }
        if ($alokasiModal > $modalDapin + 0.01) $errors[]='Total modal Dapin yang dialokasikan melebihi modal Dapin yang dicatat.';
        $bayarPinjaman = [];
        foreach ($records['Angsuran_Historis'] ?? [] as $row) if (($row['Status*'] ?? '') === 'dibayar') {
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
