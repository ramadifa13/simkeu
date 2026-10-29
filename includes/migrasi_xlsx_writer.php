<?php
/**
 * Penulis XLSX ringan (inlineStr) agar file hasil backup
 * bisa dibaca ulang oleh migrasiBacaXlsx / import migrasi.
 */
if (!function_exists('migrasiXmlEscape')) {
    function migrasiXmlEscape($value) {
        return htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('migrasiTulisXlsx')) {
    /**
     * @param string $path path file tujuan .xlsx
     * @param array $sheets [nama_sheet => ['title'=>string,'headers'=>string[],'rows'=>array[]]]
     */
    function migrasiTulisXlsx($path, array $sheets) {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Ekstensi ZIP PHP belum aktif.');
        }
        if ($sheets === []) {
            throw new RuntimeException('Tidak ada sheet untuk diekspor.');
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            throw new RuntimeException('Gagal membuat folder backup.');
        }

        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        $zip = new ZipArchive();
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Gagal membuat file XLSX.');
        }

        $sheetNames = array_keys($sheets);
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        foreach ($sheetNames as $i => $_) {
            $n = $i + 1;
            $contentTypes .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }
        $contentTypes .= '</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        $zip->addFromString('_rels/.rels',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>'
        );

        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
            . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($sheetNames as $i => $name) {
            $n = $i + 1;
            $safeName = preg_replace('/[\\\\\/?*\\[\\]:]/', '_', (string) $name);
            $safeName = substr($safeName !== '' ? $safeName : ('Sheet' . $n), 0, 31);
            $workbook .= '<sheet name="' . migrasiXmlEscape($safeName) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
            $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        }
        $workbook .= '</sheets></workbook>';
        $rels .= '<Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $rels .= '</Relationships>';
        $zip->addFromString('xl/workbook.xml', $workbook);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml',
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="1"><font><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="1"><fill><patternFill patternType="none"/></fill></fills>'
            . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            . '<cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="1"><xf xfId="0"/></cellXfs>'
            . '</styleSheet>'
        );

        $colLetter = function ($index) {
            $letter = '';
            while ($index > 0) {
                $mod = ($index - 1) % 26;
                $letter = chr(65 + $mod) . $letter;
                $index = intdiv($index - 1, 26);
            }
            return $letter;
        };

        foreach ($sheetNames as $i => $name) {
            $meta = $sheets[$name];
            $title = (string) ($meta['title'] ?? $name);
            $headers = array_values($meta['headers'] ?? []);
            $rows = $meta['rows'] ?? [];
            $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';

            $writeRow = function ($rowNum, array $cells) use (&$xml, $colLetter) {
                $xml .= '<row r="' . $rowNum . '">';
                foreach (array_values($cells) as $ci => $value) {
                    $ref = $colLetter($ci + 1) . $rowNum;
                    $text = migrasiXmlEscape($value);
                    $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $text . '</t></is></c>';
                }
                $xml .= '</row>';
            };

            $writeRow(1, [$title]);
            $writeRow(2, $headers);
            $rowNum = 3;
            foreach ($rows as $row) {
                $line = [];
                $assoc = is_array($row) && $row !== [] && !array_key_exists(0, $row);
                foreach ($headers as $hi => $header) {
                    if ($assoc) {
                        $line[] = array_key_exists($header, $row) ? $row[$header] : '';
                    } else {
                        $line[] = array_key_exists($hi, $row) ? $row[$hi] : '';
                    }
                }
                $writeRow($rowNum, $line);
                $rowNum++;
            }
            $xml .= '</sheetData></worksheet>';
            $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $xml);
        }

        $zip->close();
        if (!rename($tmp, $path)) {
            @unlink($path);
            if (!rename($tmp, $path)) {
                @unlink($tmp);
                throw new RuntimeException('Gagal menyimpan file XLSX.');
            }
        }
    }
}
