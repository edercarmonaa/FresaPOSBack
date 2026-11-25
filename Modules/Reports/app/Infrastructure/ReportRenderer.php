<?php

namespace Modules\Reports\Infrastructure;

use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Validation\ValidationException;
use Modules\Reports\Application\ReportPresentation;
use Modules\Reports\Contracts\GeneratedReport;
use Modules\Reports\Contracts\ReportData;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class ReportRenderer
{
    public function render(ReportData $data, string $format): GeneratedReport
    {
        $filename = 'report-'.$data->report.'-'.$data->filters['from'].'-'.$data->filters['until'].'.'.$format;

        return match ($format) {
            'pdf' => new GeneratedReport($this->pdf($data), 'application/pdf', $filename),
            'xlsx' => new GeneratedReport($this->xlsx($data), 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $filename),
            default => throw ValidationException::withMessages(['format' => 'Usa PDF o XLSX.']),
        };
    }

    private function pdf(ReportData $data): string
    {
        if ($data->total > 300) {
            throw ValidationException::withMessages(['export' => 'PDF admite hasta 300 filas. Reduce el rango o usa Excel (hasta 1000).']);
        }
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setIsJavascriptEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setChroot(module_path('Reports', 'resources/views'));
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('reports::pdf', ['data' => $data])->render(), 'UTF-8');
        $wide = count($data->columns) > 8;
        $pdf->setPaper($wide ? 'A3' : 'A4', $wide ? 'landscape' : 'portrait');
        $pdf->render();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        if ($font === null) {
            throw new \RuntimeException('No está disponible la fuente del reporte.');
        }
        $pdf->getCanvas()->page_text(24, $pdf->getCanvas()->get_height() - 24, 'Página {PAGE_NUM} de {PAGE_COUNT}', $font, 8, [0.2, 0.25, 0.3]);

        return $pdf->output();
    }

    private function xlsx(ReportData $data): string
    {
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Reporte');
        $sheet->setShowGridlines(false);
        $book->getProperties()->setCreator('FresaPOS')->setTitle($data->title)->setDescription('Reporte generado a partir de datos confirmados y filtros autorizados.');
        $last = Coordinate::stringFromColumnIndex(max(6, count($data->columns)));
        $lastData = Coordinate::stringFromColumnIndex(count($data->columns));
        $sheet->mergeCells('A1:'.$last.'1')->setCellValueExplicit('A1', $data->title, DataType::TYPE_STRING);
        $sheet->setCellValue('A2', 'Generado (UTC)');
        $sheet->setCellValue('B2', Date::PHPToExcel(CarbonImmutable::parse($data->generatedAt)->utc()));
        $sheet->getStyle('B2')->getNumberFormat()->setFormatCode('yyyy-mm-dd hh:mm:ss');
        $sheet->setCellValue('A3', 'Desde');
        $sheet->setCellValue('B3', Date::PHPToExcel(CarbonImmutable::parse($data->filters['from'])));
        $sheet->setCellValue('C3', 'Hasta');
        $sheet->setCellValue('D3', Date::PHPToExcel(CarbonImmutable::parse($data->filters['until'])));
        $sheet->getStyle('B3')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->getStyle('D3')->getNumberFormat()->setFormatCode('dd/mm/yyyy');
        $sheet->setCellValue('A4', 'Zona comercial');
        $sheet->setCellValueExplicit('B4', $data->timezone, DataType::TYPE_STRING);
        $sheet->mergeCells('B5:'.$last.'5')->setCellValue('A5', 'Filtros');
        $sheet->setCellValueExplicit('B5', ReportPresentation::filters($data->filters), DataType::TYPE_STRING);
        $sheet->getStyle('B5:'.$last.'5')->getAlignment()->setWrapText(true);
        $sheet->getRowDimension(5)->setRowHeight(16 * max(2, intdiv(mb_strlen(ReportPresentation::filters($data->filters)) + 74, 75)));
        $metric = 0;
        foreach ($data->totals as $key => $value) {
            $row = 7 + intdiv($metric, 3);
            $labelCell = Coordinate::stringFromColumnIndex(1 + ($metric % 3) * 2).$row;
            $valueCell = Coordinate::stringFromColumnIndex(2 + ($metric % 3) * 2).$row;
            $sheet->setCellValueExplicit($labelCell, $this->label($key), DataType::TYPE_STRING);
            if ($value !== null) {
                $this->number($sheet, $valueCell, $value, is_int($value) || preg_match('/\A[0-9]+\z/', (string) $value) ? 'integer' : 'money');
            }
            $sheet->getStyle($labelCell)->getAlignment()->setWrapText(true);
            $sheet->getRowDimension($row)->setRowHeight(24);
            $metric++;
        }
        $row = 7 + intdiv($metric + 2, 3);
        $header = $row + 1;
        $row = $header;
        $column = 1;
        foreach ($data->columns as [$label,$type]) {
            $sheet->setCellValueExplicit([$column++, $header], $label, DataType::TYPE_STRING);
        }
        foreach ($data->items as $item) {
            $row++;
            $column = 1;
            $lines = 2;
            foreach ($data->columns as $key => [$label,$type]) {
                $cell = Coordinate::stringFromColumnIndex($column++).$row;
                $value = $item[$key] ?? null;
                if ($value === null) {
                    continue;
                }
                $lines = max($lines, intdiv(mb_strlen((string) $value) + 22, 23));
                if (in_array($type, ['money', 'integer', 'decimal'], true)) {
                    $this->number($sheet, $cell, $value, $type);
                } elseif (in_array($type, ['datetime', 'date'], true)) {
                    $date = CarbonImmutable::parse((string) $value, 'UTC');
                    if ($type === 'datetime') {
                        $date = $date->setTimezone($data->timezone);
                    }
                    $sheet->setCellValue($cell, Date::PHPToExcel($date));
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode($type === 'date' ? 'dd/mm/yyyy' : 'dd/mm/yyyy hh:mm:ss');
                } else {
                    $sheet->setCellValueExplicit($cell, ReportPresentation::value($value, $type, $data->timezone), DataType::TYPE_STRING);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('@');
                }
            }
            $sheet->getRowDimension($row)->setRowHeight(16 * $lines);
        }
        $lastRow = max($row, $header);
        $row += 2;
        foreach ($data->notes as $note) {
            $sheet->mergeCells('A'.$row.':'.$last.$row)->setCellValueExplicit('A'.$row, $note, DataType::TYPE_STRING);
            $sheet->getStyle('A'.$row)->getAlignment()->setWrapText(true);
            $sheet->getStyle('A'.$row)->getFont()->setItalic(true)->setSize(10);
            $sheet->getRowDimension($row)->setRowHeight(16 * max(2, intdiv(mb_strlen($note) + 119, 120)));
            $row++;
        }
        $sheet->freezePane('A'.($header + 1));
        $sheet->setAutoFilter('A'.$header.':'.$lastData.$lastRow);
        $sheet->getStyle('A1:'.$last.'1')->getFont()->setBold(true)->setSize(18);
        foreach (['A1:'.$last.'1', 'A'.$header.':'.$lastData.$header] as $range) {
            $sheet->getStyle($range)->getFill()->setFillType('solid')->getStartColor()->setARGB('FF172554');
            $sheet->getStyle($range)->getFont()->getColor()->setARGB('FFFFFFFF');
        }
        $sheet->getStyle('A'.$header.':'.$lastData.$header)->getFont()->setBold(true);
        $sheet->getStyle('A'.$header.':'.$lastData.$lastRow)->getAlignment()->setWrapText(true)->setVertical(Alignment::VERTICAL_TOP);
        $sheet->getRowDimension(1)->setRowHeight(30);
        $sheet->getRowDimension($header)->setRowHeight(35);
        for ($i = 1; $i <= max(6, count($data->columns)); $i++) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i))->setWidth(in_array($i, [1, 3, 5], true) ? 34 : 25);
        }
        $sheet->getPageSetup()->setRowsToRepeatAtTopByStartAndEnd($header, $header)->setFitToWidth(1)->setFitToHeight(0)->setOrientation('landscape');
        $stream = tmpfile();
        if ($stream === false) {
            throw new \RuntimeException('No se pudo preparar el flujo temporal.');
        }
        try {
            $writer = new Xlsx($book);
            $writer->setPreCalculateFormulas(false);
            $writer->save($stream);
            rewind($stream);
            $contents = stream_get_contents($stream);
            if ($contents === false) {
                throw new \RuntimeException('No se pudo leer el documento.');
            }

            return $contents;
        } finally {
            fclose($stream);
            $book->disconnectWorksheets();
        }
    }

    private function number(Worksheet $sheet, string $cell, mixed $value, string $type): void
    {
        $text = (string) $value;
        if (! preg_match('/\A-?(?:0|[1-9][0-9]*)(?:\.[0-9]{1,4})?\z/', $text) || strlen(explode('.', ltrim($text, '-'))[0]) > ($type === 'integer' ? 15 : 11)) {
            throw ValidationException::withMessages(['export' => 'Excel no conserva estos importes con precisión suficiente. Usa PDF o reduce el conjunto; no se redondean silenciosamente.']);
        }
        // La preparación conserva texto decimal exacto; la librería serializa el tipo numérico Excel.
        $sheet->setCellValueExplicit($cell, $text, DataType::TYPE_NUMERIC);
        $sheet->getStyle($cell)->getNumberFormat()->setFormatCode(match ($type) {
            'integer' => '#,##0','money' => '"$"#,##0.0000;[Red]-"$"#,##0.0000','decimal' => '0.0000',default => 'General'
        });
    }

    public function label(string $key): string
    {
        return ReportPresentation::METRICS[$key] ?? 'Indicador';
    }
}
