<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Throwable;

class CobolImpresionParser
{
    public function parsear(string $contenido): array
    {
        $texto = preg_replace('/[^\x09\x0A\x0C\x0D\x20-\x7E\xA0-\xFF]/', '', $contenido) ?? $contenido;

        $tipo = null;
        $cuenta = null;
        $nombreCliente = null;
        $fechaDocumento = null;
        $estado = 'DESCONOCIDO';
        $importeTotal = null;

        if (str_contains($texto, 'LIQUIDACION DE DEUDA')) {
            $tipo = 'LIQUIDACION_DEUDA';
            $estado = 'PARSEADO';

            if (preg_match('/CTA\.NRO\.\.:\s*([0-9\/]+)/', $texto, $m)) {
                $cuenta = trim($m[1]);
            }

            if (preg_match('/DEUDOR\s*\.\.:\s*(.*?)\s{2,}FECHA:/', $texto, $m)) {
                $nombreCliente = trim($m[1]);
            }

            if (preg_match('/FECHA:\s*([0-9]{2}\/[0-9]{2}\/[0-9]{4})/', $texto, $m)) {
                $fechaDocumento = $this->normalizarFecha($m[1]);
            }

            $importeTotal = $this->importeLiquidacionDeuda($texto);
        } elseif ($this->pareceReciboLiquidacion($texto)) {
            $tipo = 'RECIBO_LIQUIDACION';
            $estado = 'PARSEADO';

            if (preg_match('/\b([0-9]{4}\/[0-9]{5}\/[0-9]{2})\b/', $texto, $m)) {
                $cuenta = trim($m[1]);
            }

            if ($cuenta !== null && preg_match('/^[ \t]*([^\r\n]*?\S)[ \t]{2,}'.preg_quote($cuenta, '/').'\b/m', $texto, $m)) {
                $nombreCliente = trim($m[1]);
            }

            if (preg_match('/\b([0-9]{2}\/[0-9]{2}\/[0-9]{4})\b/', $texto, $m)) {
                $fechaDocumento = $this->normalizarFecha($m[1]);
            }

            $importeTotal = $this->importeReciboLiquidacion($texto);
        }

        return [
            'tipo_documento' => $tipo,
            'cuenta' => $cuenta,
            'nombre_cliente' => $nombreCliente,
            'fecha_documento' => $fechaDocumento,
            'importe_total' => $importeTotal,
            'estado' => $estado,
        ];
    }


    private function importeLiquidacionDeuda(string $texto): ?string
    {
        if (preg_match('/DEUDA\s+LIQUIDA\s+AL\s+\d{2}\/\d{2}\/\d{4}\s*=+\s*([0-9.]+,[0-9]{2})/i', $texto, $m) === 1) {
            return $this->normalizarImporte($m[1]);
        }

        return null;
    }

    private function importeReciboLiquidacion(string $texto): ?string
    {
        $hasta = preg_split('/HORARIO\s+DE\s+ATENCION/i', $texto, 2)[0] ?? $texto;

        if (preg_match_all('/^[ \t]*([0-9]+(?:\.[0-9]{3})*,[0-9]{2})(?:[ \t]+\1)?[ \t\r]*$/m', $hasta, $m) < 1) {
            return null;
        }

        $ultimo = end($m[1]);

        return is_string($ultimo) ? $this->normalizarImporte($ultimo) : null;
    }

    private function normalizarImporte(string $importe): ?string
    {
        $normalizado = str_replace(['.', ','], ['', '.'], trim($importe));

        return preg_match('/^\d+(?:\.\d{2})$/', $normalizado) === 1
            ? $normalizado
            : null;
    }

    private function pareceReciboLiquidacion(string $texto): bool
    {
        return str_contains($texto, 'D E B I T O S')
            && preg_match('/\b[0-9]{4}\/[0-9]{5}\/[0-9]{2}\b/', $texto) === 1
            && preg_match('/\bPESOS\b/', $texto) === 1;
    }

    private function normalizarFecha(string $fecha): ?string
    {
        try {
            return CarbonImmutable::createFromFormat('d/m/Y', $fecha)->toDateString();
        } catch (Throwable) {
            return null;
        }
    }
}
