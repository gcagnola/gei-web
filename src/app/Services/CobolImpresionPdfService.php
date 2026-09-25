<?php

namespace App\Services;

use App\Models\CobolImpresion;
use RuntimeException;
use Symfony\Component\Process\Process;

class CobolImpresionPdfService
{
    public function generar(CobolImpresion $impresion): string
    {
        if (! in_array($impresion->tipo_documento, ['LIQUIDACION_DEUDA', 'RECIBO_LIQUIDACION'], true)) {
            throw new RuntimeException('El tipo de impresión COBOL todavía no tiene generador PDF.');
        }

        $rawAbsoluto = storage_path('app/private/'.$impresion->archivo_raw);
        if (! is_file($rawAbsoluto)) {
            throw new RuntimeException('No se encontró el archivo RAW de la impresión.');
        }

        $python = base_path('python/.venv/bin/python');
        $script = base_path('python/cobol_impresion_pdf.py');
        $logo = base_path('python/liquidaciones_propietarios/GeI_fox.png');

        if (! is_file($python) || ! is_executable($python)) {
            throw new RuntimeException("No se encontró Python ejecutable: {$python}");
        }

        if (! is_file($script)) {
            throw new RuntimeException("No se encontró el generador PDF COBOL: {$script}");
        }

        $directorioRelativo = 'cobol/impresiones/pdf/'.($impresion->recibido_en?->format('Y/m') ?? now()->format('Y/m'));
        $nombrePdf = pathinfo($impresion->archivo_origen, PATHINFO_FILENAME).'.pdf';
        $pdfRelativo = $directorioRelativo.'/'.$nombrePdf;
        $pdfAbsoluto = storage_path('app/private/'.$pdfRelativo);

        if (! is_dir(dirname($pdfAbsoluto))) {
            mkdir(dirname($pdfAbsoluto), 0775, true);
        }

        $command = [
            $python,
            $script,
            '--raw', $rawAbsoluto,
            '--tipo', (string) $impresion->tipo_documento,
            '--salida', $pdfAbsoluto,
        ];

        if (is_file($logo)) {
            $command[] = '--logo';
            $command[] = $logo;
        }

        $process = new Process($command, base_path(), null, null, 30);
        $process->run();

        if (! $process->isSuccessful() || ! is_file($pdfAbsoluto) || filesize($pdfAbsoluto) === 0) {
            throw new RuntimeException(
                'No se pudo generar el PDF COBOL. '.trim($process->getErrorOutput())
            );
        }

        $impresion->pdf_path = $pdfRelativo;
        $impresion->save();

        return $pdfRelativo;
    }
}
