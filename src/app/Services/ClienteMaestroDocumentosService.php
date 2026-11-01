<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use App\Services\ImpuestosGarantizadosPdfService;
use Illuminate\Support\Facades\Storage;

/** Consultas de sólo lectura por cuenta COBOL EXACTA (nunca por CUIT o nombre). */
final class ClienteMaestroDocumentosService
{
    public function __construct(
        private readonly ImpuestosGarantizadosPdfService $impuestos,
        private readonly ComprobantesArcaService $arca,
    ) {}

    /** @param list<string> $cuentasPropietarios */
    public function liquidaciones(array $cuentasPropietarios, string $periodo, bool $soloImpuestos = false): Collection
    {
        if ($cuentasPropietarios === []) {
            return collect();
        }

        $filas = DB::table('liquidaciones_propietarios')
            ->whereIn('cuenta', $cuentasPropietarios)
            ->where('periodo', $periodo)
            ->orderByDesc('numero_interno')
            ->limit(200)
            ->get([
                'id', 'periodo', 'fecha', 'sede', 'cuenta', 'cuenta_impresa',
                'comprobante', 'numero_interno', 'propietario', 'total_final',
                'estado', 'pdf_ruta',
            ]);

        return $filas->map(function (object $fila) use ($soloImpuestos): object {
            $fila->pdf_disponible = $fila->estado === 'PDF_GENERADO'
                && $fila->pdf_ruta !== null
                && Storage::disk('liquidaciones')->exists((string) $fila->pdf_ruta);
            if ($soloImpuestos) {
                $cuentaImpresa = preg_replace('/[^0-9]/', '', (string) ($fila->cuenta_impresa ?: $fila->cuenta));
                $ruta = $cuentaImpresa === (string) $fila->cuenta
                    ? $this->impuestos->rutaPdfParaLiquidacion(
                    (string) $fila->periodo,
                    $fila->pdf_ruta ? (string) $fila->pdf_ruta : null,
                    (string) ($fila->cuenta_impresa ?: $fila->cuenta)
                ) : null;
                $fila->impuestos_pdf_disponible = $ruta !== null
                    && Storage::disk('liquidaciones')->exists($ruta);
            }
            return $fila;
        });
    }

    /**
     * Facturas KNG tal como las consulta el módulo ARCA existente de GeI.
     * Sólo la cuenta destinataria registrada en KNG recibe el comprobante:
     * ID_INQ si está informado; de lo contrario CTA_ORIG.
     *
     * @param list<string> $cuentas
     */
    public function comprobantes(array $cuentas, string $periodo): Collection
    {
        if ($cuentas === []) {
            return collect();
        }

        return $this->arca->porCuentasYPeriodo($cuentas, $periodo)
            ->flatMap(static fn (Collection $filas): array => $filas->all())
            ->sortByDesc(static fn (object $fila): string => (string) ($fila->fecha ?? ''))
            ->values();
    }
}
