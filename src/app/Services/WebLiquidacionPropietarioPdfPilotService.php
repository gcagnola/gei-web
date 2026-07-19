<?php

namespace App\Services;

use Illuminate\Support\Facades\File;
use RuntimeException;

class WebLiquidacionPropietarioPdfPilotService
{
    /**
     * @return array<string, mixed>
     */
    public function generar(string $jsonPath, string $outputPath): array
    {
        if (! File::exists($jsonPath)) {
            throw new RuntimeException("JSON piloto no encontrado: {$jsonPath}");
        }

        $payload = json_decode((string) File::get($jsonPath), true, 512, JSON_THROW_ON_ERROR);
        $this->validarPayload($payload);

        $lines = $this->lineasPdf($payload, $jsonPath);
        $pdf = $this->renderPdf($lines);

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, $pdf);

        return [
            'estado' => 'PDF_PILOTO_GENERADO',
            'json' => $jsonPath,
            'output' => $outputPath,
            'bytes' => File::size($outputPath),
            'total' => $payload['encabezado']['total_items'],
            'items' => count($payload['items']),
            'advertencia' => 'PILOTO_NO_PRODUCTIVO',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validarPayload(array $payload): void
    {
        if (($payload['metadata']['advertencia'] ?? null) !== 'EXPERIMENTAL_NO_PRODUCTIVO') {
            throw new RuntimeException('JSON piloto invalido: falta metadata experimental.');
        }

        if (($payload['metadata']['origen'] ?? null) !== 'WEB_PILOTO') {
            throw new RuntimeException('JSON piloto invalido: origen no es WEB_PILOTO.');
        }

        if (($payload['encabezado']['diferencia'] ?? null) !== '0.00') {
            throw new RuntimeException('JSON piloto invalido: diferencia contra historico no es cero.');
        }

        $totalItems = $this->decimalToCents((string) $payload['encabezado']['total_items']);
        $sumatoria = 0;
        foreach ($payload['items'] as $item) {
            $sumatoria += $this->decimalToCents((string) $item['haber'])
                - $this->decimalToCents((string) $item['debe']);
        }

        if ($sumatoria !== $totalItems) {
            throw new RuntimeException(sprintf(
                'JSON piloto invalido: total_items %s no coincide con suma de items %s.',
                $payload['encabezado']['total_items'],
                $this->centsToDecimal($sumatoria)
            ));
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return list<string>
     */
    private function lineasPdf(array $payload, string $jsonPath): array
    {
        $encabezado = $payload['encabezado'];
        $propietario = $encabezado['propietario'];
        $resumen = $payload['resumen'];
        $lines = [
            'PILOTO / NO PRODUCTIVO',
            'Liquidacion propietario desde JSON web_*',
            'Origen JSON: '.$jsonPath,
            'Regla: '.$payload['metadata']['version_regla'],
            'Generado: '.$payload['metadata']['generado_en'],
            '',
            'Propietario: '.$propietario['nombre'],
            'Cuenta: '.$encabezado['cuenta_propietario'].'    Periodo: '.$encabezado['periodo_texto'],
            'Comprobante historico: '.$encabezado['comprobante_historico_tipo'].' '.$encabezado['comprobante_historico_numero'],
            'Total historico: '.$this->moneda($encabezado['total_historico_esperado'])
                .'    Total items: '.$this->moneda($encabezado['total_items'])
                .'    Diferencia: '.$this->moneda($encabezado['diferencia']),
            'Items: '.$resumen['items_construidos']
                .'    Mov. liquidables: '.$resumen['movimientos_liquidables']
                .'    Excluidos: '.$resumen['movimientos_excluidos']
                .'    Agrupados: '.$resumen['movimientos_agrupados'],
            '',
            str_pad('Ord', 4).' '.str_pad('Cod', 8).' '.str_pad('Descripcion', 46).' '.str_pad('Debe', 14, ' ', STR_PAD_LEFT).' '.str_pad('Haber', 14, ' ', STR_PAD_LEFT).' '.str_pad('Total', 14, ' ', STR_PAD_LEFT),
            str_repeat('-', 106),
        ];

        foreach ($payload['items'] as $item) {
            $lines[] =
                str_pad((string) $item['orden'], 4).' '.
                str_pad(substr((string) $item['codigo'], 0, 8), 8).' '.
                str_pad(substr((string) $item['descripcion'], 0, 46), 46).' '.
                str_pad($this->moneda($item['debe']), 14, ' ', STR_PAD_LEFT).' '.
                str_pad($this->moneda($item['haber']), 14, ' ', STR_PAD_LEFT).' '.
                str_pad($this->moneda($item['total']), 14, ' ', STR_PAD_LEFT);
        }

        $lines[] = '';
        $lines[] = 'Movimientos excluidos';
        $lines[] = str_repeat('-', 106);
        foreach ($payload['movimientos_excluidos'] as $movimiento) {
            $lines[] = sprintf(
                '%s | codigo %s | %s | %s | %s',
                $movimiento['id'],
                $movimiento['codigo'],
                $movimiento['detalle'],
                $this->moneda($movimiento['importe']),
                $movimiento['clasificacion']
            );
        }

        $lines[] = '';
        $lines[] = 'Advertencias';
        $lines[] = str_repeat('-', 106);
        foreach ($payload['advertencias'] as $advertencia) {
            $lines[] = '- '.$advertencia;
        }

        return $lines;
    }

    /**
     * @param list<string> $lines
     */
    private function renderPdf(array $lines): string
    {
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pageIds = [];
        $linesPerPage = 58;
        $chunks = array_chunk($lines, $linesPerPage);

        foreach ($chunks as $chunk) {
            $pageId = count($objects) + 1;
            $contentId = count($objects) + 2;
            $pageIds[] = $pageId;
            $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 3 0 R >> >> /Contents {$contentId} 0 R >>";
            $stream = $this->contentStream($chunk);
            $objects[] = "<< /Length ".strlen($stream)." >>\nstream\n{$stream}\nendstream";
        }

        $kids = implode(' ', array_map(fn (int $id): string => "{$id} 0 R", $pageIds));
        $objects[1] = "<< /Type /Pages /Kids [{$kids}] /Count ".count($pageIds).' >>';

        $pdf = "%PDF-1.4\n";
        $offsets = [0];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $number = $index + 1;
            $pdf .= "{$number} 0 obj\n{$object}\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= count($objects); $i++) {
            $pdf .= str_pad((string) $offsets[$i], 10, '0', STR_PAD_LEFT)." 00000 n \n";
        }

        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\n";
        $pdf .= "startxref\n{$xrefOffset}\n%%EOF\n";

        return $pdf;
    }

    /**
     * @param list<string> $lines
     */
    private function contentStream(array $lines): string
    {
        $stream = "BT\n/F1 8 Tf\n10 TL\n40 805 Td\n";
        foreach ($lines as $line) {
            $stream .= '('.$this->pdfText($line).") Tj\nT*\n";
        }
        $stream .= "ET";

        return $stream;
    }

    private function pdfText(string $text): string
    {
        $ascii = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $ascii = $ascii === false ? $text : $ascii;

        return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $ascii);
    }

    private function moneda(string|int|float|null $value): string
    {
        if ($value === null || $value === '') {
            return '0,00';
        }

        $cents = $this->decimalToCents((string) $value);
        $negative = $cents < 0;
        $absolute = abs($cents);
        $formatted = number_format($absolute / 100, 2, ',', '.');

        return $negative ? "-{$formatted}" : $formatted;
    }

    private function decimalToCents(string $value): int
    {
        $normalized = trim(str_replace(',', '.', $value));
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        [$integer, $decimal] = array_pad(explode('.', $normalized, 2), 2, '0');
        $decimal = substr(str_pad($decimal, 2, '0'), 0, 2);
        $cents = ((int) $integer * 100) + (int) $decimal;

        return $negative ? -$cents : $cents;
    }

    private function centsToDecimal(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        $value = intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return $negative ? "-{$value}" : $value;
    }
}
