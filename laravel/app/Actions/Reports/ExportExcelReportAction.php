<?php

namespace App\Actions\Reports;

use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Excel export for operational report rows using PhpSpreadsheet. */
class ExportExcelReportAction
{
    /**
     * @param  array<string, string>  $columns
     * @param  Collection<int, object>  $rows
     */
    public function handle(string $title, array $columns, Collection $rows, string $filename): StreamedResponse
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle(mb_substr($title, 0, 31));
        $col = 1;
        foreach ($columns as $label) {
            $sheet->setCellValue([$col, 1], $label);
            $col++;
        }
        $rowNum = 2;
        foreach ($rows as $row) {
            $col = 1;
            foreach (array_keys($columns) as $key) {
                $value = data_get($row, $key);
                $sheet->setCellValue([$col, $rowNum], is_scalar($value) || $value === null ? $value : json_encode($value));
                $col++;
            }
            $rowNum++;
        }

        return response()->streamDownload(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }
}
