<?php

namespace App\Http\Controllers;

use App\Models\LiquidacionPropietario;
use App\Services\ComprobantesArcaService;
use App\Services\ImpuestosGarantizadosPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PortalDocumentosController extends Controller
{
    public function comprobantesArca(Request $request, ComprobantesArcaService $service): View
    {
        $portalUsuario = Auth::guard('portal')->user();
        $portalUsuario->loadMissing('cliente.cuentas');

        $cliente = $portalUsuario->cliente;
        $cuentas = $this->cuentasCliente($cliente->cuentas);

        $periodos = $this->periodosArcaDelCliente((int) $cliente->id, $cuentas, $service);
        $periodo = trim((string) $request->query('periodo', ''));

        if ($periodo === '' || ! $periodos->contains($periodo)) {
            $periodo = (string) ($periodos->first() ?? '');
        }

        $facturas = $periodo !== ''
            ? $this->facturasDelCliente((int) $cliente->id, $cuentas, $periodo)
            : collect();

        $historicos = collect();
        if ($periodo !== '' && $cuentas !== []) {
            $historicos = $service
                ->porCuentasYPeriodo($cuentas, $periodo)
                ->flatten(1)
                ->reject(function (object $item) use ($facturas): bool {
                    return $facturas->contains(
                        fn (object $factura): bool =>
                            (int) ($factura->punto_venta ?? 0) === (int) preg_replace('/\D+/', '', (string) ($item->punto_venta ?? ''))
                            && (int) ($factura->numero_comprobante ?? 0) === (int) preg_replace('/\D+/', '', (string) ($item->numero_comprobante ?? ''))
                    );
                })
                ->map(function (object $item): object {
                    $item->fuente_portal = 'KNG';
                    return $item;
                })
                ->values();
        }

        $comprobantes = $facturas
            ->concat($historicos)
            ->sortByDesc(fn (object $item): string => (string) ($item->fecha ?? $item->fecha_comprobante ?? ''))
            ->values();

        return view('portal.documentos.comprobantes-arca', [
            'portalUsuario' => $portalUsuario,
            'periodos' => $periodos,
            'periodo' => $periodo,
            'comprobantes' => $comprobantes,
        ]);
    }

    public function verFacturaArca(int $factura): StreamedResponse
    {
        $portalUsuario = Auth::guard('portal')->user();
        $portalUsuario->loadMissing('cliente.cuentas');

        $cliente = $portalUsuario->cliente;
        $cuentas = $this->cuentasCliente($cliente->cuentas);

        $registro = DB::table('facturas as f')
            ->where('f.id_factura', $factura)
            ->where(function ($query) use ($cliente, $cuentas): void {
                $query->where('f.cliente_id', $cliente->id);

                if ($cuentas !== [] && Schema::hasTable('facturas_cuentas')) {
                    $query->orWhere(function ($sinCliente) use ($cuentas): void {
                        $sinCliente
                            ->whereNull('f.cliente_id')
                            ->whereExists(function ($sub) use ($cuentas): void {
                                $sub->selectRaw('1')
                                    ->from('facturas_cuentas as fc')
                                    ->whereColumn('fc.factura_id', 'f.id_factura')
                                    ->whereIn('fc.cuenta', $cuentas);
                            });
                    });
                }
            })
            ->first(['f.id_factura', 'f.pdf_ruta']);

        abort_if(! $registro || empty($registro->pdf_ruta), 404);

        $ruta = (string) $registro->pdf_ruta;
        abort_unless(Storage::disk('arca_facturas')->exists($ruta), 404);

        return Storage::disk('arca_facturas')->response(
            $ruta,
            basename($ruta),
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.basename($ruta).'"',
                'Cache-Control' => 'private, max-age=300',
            ]
        );
    }

    public function verComprobanteArca(
        string $periodo,
        string $archivo,
        ComprobantesArcaService $service,
    ): BinaryFileResponse {
        abort_if($archivo !== basename($archivo), 404);

        $portalUsuario = Auth::guard('portal')->user();
        $portalUsuario->loadMissing('cliente.cuentas');
        $cuentas = $this->cuentasCliente($portalUsuario->cliente->cuentas);

        abort_if($cuentas === [], 404);

        $autorizado = $service
            ->porCuentasYPeriodo($cuentas, $periodo)
            ->flatten(1)
            ->contains(function (object $item) use ($archivo): bool {
                return (string) ($item->nombre_archivo ?? '') === $archivo
                    && (bool) ($item->pdf_disponible ?? false);
            });

        abort_unless($autorizado, 404);

        $ruta = $service->rutaFisica($periodo, $archivo);
        abort_if($ruta === null, 404);

        return response()->file($ruta, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.basename($ruta).'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    public function liquidacionesPropietario(Request $request): View
    {
        $portalUsuario = Auth::guard('portal')->user();
        abort_unless($this->esPropietario($portalUsuario->cliente_id), 404);

        $periodo = trim((string) $request->query('periodo', ''));

        $base = LiquidacionPropietario::query()
            ->where('cliente_id', $portalUsuario->cliente_id);

        $periodos = (clone $base)
            ->select('periodo')
            ->distinct()
            ->orderByDesc('periodo')
            ->pluck('periodo');

        if ($periodo !== '' && $periodos->contains($periodo)) {
            $base->where('periodo', $periodo);
        } else {
            $periodo = '';
        }

        $liquidaciones = $base
            ->orderByDesc('periodo')
            ->orderByDesc('fecha')
            ->orderByDesc('id')
            ->paginate(24)
            ->withQueryString();

        foreach ($liquidaciones->items() as $liquidacion) {
            $liquidacion->pdf_disponible = $liquidacion->estado === 'PDF_GENERADO'
                && filled($liquidacion->pdf_ruta)
                && Storage::disk('liquidaciones')->exists((string) $liquidacion->pdf_ruta);
        }

        return view('portal.documentos.liquidaciones-propietario', compact(
            'portalUsuario', 'periodos', 'periodo', 'liquidaciones'
        ));
    }

    public function verLiquidacionPropietario(int $liquidacion): StreamedResponse
    {
        $portalUsuario = Auth::guard('portal')->user();
        abort_unless($this->esPropietario($portalUsuario->cliente_id), 404);

        $registro = LiquidacionPropietario::query()
            ->whereKey($liquidacion)
            ->where('cliente_id', $portalUsuario->cliente_id)
            ->firstOrFail();

        abort_if(! $registro->pdf_ruta, 404);
        abort_unless(Storage::disk('liquidaciones')->exists((string) $registro->pdf_ruta), 404);

        return Storage::disk('liquidaciones')->response(
            (string) $registro->pdf_ruta,
            basename((string) $registro->pdf_ruta),
            ['Content-Type' => 'application/pdf'],
            'inline'
        );
    }

    public function impuestosGarantizados(Request $request, ImpuestosGarantizadosPdfService $service): View
    {
        $portalUsuario = Auth::guard('portal')->user();
        abort_unless($this->esPropietario($portalUsuario->cliente_id), 404);
        $periodo = trim((string) $request->query('periodo', ''));

        $base = LiquidacionPropietario::query()->where('cliente_id', $portalUsuario->cliente_id);
        $periodos = (clone $base)->select('periodo')->distinct()->orderByDesc('periodo')->pluck('periodo');

        if ($periodo !== '' && $periodos->contains($periodo)) {
            $base->where('periodo', $periodo);
        } else {
            $periodo = '';
        }

        $liquidaciones = $base->orderByDesc('periodo')->orderByDesc('fecha')->orderByDesc('id')->paginate(24)->withQueryString();

        foreach ($liquidaciones->items() as $liquidacion) {
            $ruta = $service->rutaPdfParaLiquidacion(
                (string) $liquidacion->periodo,
                $liquidacion->pdf_ruta ? (string) $liquidacion->pdf_ruta : null,
                (string) ($liquidacion->cuenta_impresa ?: $liquidacion->cuenta),
            );

            $liquidacion->impuestos_pdf_disponible = $ruta !== null
                && Storage::disk('liquidaciones')->exists($ruta);
        }

        return view('portal.documentos.impuestos-garantizados', compact(
            'portalUsuario', 'periodos', 'periodo', 'liquidaciones'
        ));
    }

    public function verImpuestosGarantizados(int $liquidacion, ImpuestosGarantizadosPdfService $service): StreamedResponse
    {
        $portalUsuario = Auth::guard('portal')->user();
        abort_unless($this->esPropietario($portalUsuario->cliente_id), 404);

        $registro = LiquidacionPropietario::query()
            ->whereKey($liquidacion)
            ->where('cliente_id', $portalUsuario->cliente_id)
            ->firstOrFail();

        $ruta = $service->rutaPdfParaLiquidacion(
            (string) $registro->periodo,
            $registro->pdf_ruta ? (string) $registro->pdf_ruta : null,
            (string) ($registro->cuenta_impresa ?: $registro->cuenta),
        );

        abort_if($ruta === null, 404);
        abort_unless(Storage::disk('liquidaciones')->exists($ruta), 404);

        return Storage::disk('liquidaciones')->response(
            $ruta,
            basename($ruta),
            ['Content-Type' => 'application/pdf'],
            'inline'
        );
    }

    /** @return Collection<int, object> */
    private function facturasDelCliente(int $clienteId, array $cuentas, string $periodo): Collection
    {
        if (! Schema::hasTable('facturas') || ! Schema::hasTable('facturaciones')) {
            return collect();
        }

        $query = DB::table('facturas as f')
            ->join('facturaciones as fg', 'fg.id_facturacion', '=', 'f.facturacion_id')
            ->leftJoin('puntos_venta as pv', 'pv.id_punto_venta', '=', 'f.punto_venta_id')
            ->where('fg.periodo', $periodo)
            ->where(function ($query) use ($clienteId, $cuentas): void {
                $query->where('f.cliente_id', $clienteId);

                if ($cuentas !== [] && Schema::hasTable('facturas_cuentas')) {
                    $query->orWhere(function ($sinCliente) use ($cuentas): void {
                        $sinCliente
                            ->whereNull('f.cliente_id')
                            ->whereExists(function ($sub) use ($cuentas): void {
                                $sub->selectRaw('1')
                                    ->from('facturas_cuentas as fc')
                                    ->whereColumn('fc.factura_id', 'f.id_factura')
                                    ->whereIn('fc.cuenta', $cuentas);
                            });
                    });
                }
            })
            ->select([
                'f.id_factura',
                'f.tipo_comprobante',
                'f.numero_comprobante',
                'f.fecha_comprobante',
                'f.total',
                'f.pdf_ruta',
                'pv.numero as punto_venta',
            ])
            ->orderByDesc('f.fecha_comprobante')
            ->orderByDesc('f.id_factura')
            ->limit(250)
            ->get();

        $cuentasPorFactura = collect();
        if ($query->isNotEmpty() && Schema::hasTable('facturas_cuentas')) {
            $cuentasPorFactura = DB::table('facturas_cuentas')
                ->whereIn('factura_id', $query->pluck('id_factura')->all())
                ->get(['factura_id', 'cuenta'])
                ->groupBy('factura_id');
        }

        return $query->map(function (object $factura) use ($cuentasPorFactura): object {
            $factura->fuente_portal = 'FACTURA';
            $factura->fecha = (string) ($factura->fecha_comprobante ?? '');
            $factura->cuenta_cobol = collect($cuentasPorFactura->get($factura->id_factura, collect()))
                ->pluck('cuenta')
                ->map(fn ($cuenta): string => preg_replace('/\D+/', '', (string) $cuenta) ?? '')
                ->filter()
                ->unique()
                ->implode(', ');
            $factura->tipo_codigo = (string) ($factura->tipo_comprobante ?? '');
            $factura->numero_comprobante = (string) ($factura->numero_comprobante ?? '');
            $factura->pdf_disponible = filled($factura->pdf_ruta)
                && Storage::disk('arca_facturas')->exists((string) $factura->pdf_ruta);
            return $factura;
        });
    }

    /** @return Collection<int, string> */
    private function periodosArcaDelCliente(int $clienteId, array $cuentas, ComprobantesArcaService $service): Collection
    {
        $periodos = collect();

        if (Schema::hasTable('facturas') && Schema::hasTable('facturaciones')) {
            $query = DB::table('facturas as f')
                ->join('facturaciones as fg', 'fg.id_facturacion', '=', 'f.facturacion_id')
                ->where(function ($query) use ($clienteId, $cuentas): void {
                    $query->where('f.cliente_id', $clienteId);

                    if ($cuentas !== [] && Schema::hasTable('facturas_cuentas')) {
                        $query->orWhere(function ($sinCliente) use ($cuentas): void {
                            $sinCliente
                                ->whereNull('f.cliente_id')
                                ->whereExists(function ($sub) use ($cuentas): void {
                                    $sub->selectRaw('1')
                                        ->from('facturas_cuentas as fc')
                                        ->whereColumn('fc.factura_id', 'f.id_factura')
                                        ->whereIn('fc.cuenta', $cuentas);
                                });
                        });
                    }
                })
                ->whereNotNull('fg.periodo')
                ->distinct()
                ->pluck('fg.periodo');

            $periodos = $periodos->concat($query);
        }

        if ($cuentas !== []) {
            foreach ($service->periodosDisponibles() as $periodo) {
                if ($service->porCuentasYPeriodo($cuentas, (string) $periodo)->isNotEmpty()) {
                    $periodos->push((string) $periodo);
                }
            }
        }

        return $periodos
            ->map(fn ($periodo): string => (string) $periodo)
            ->filter(fn (string $periodo): bool => preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) === 1)
            ->unique()
            ->sortDesc()
            ->values();
    }

    private function esPropietario(int $clienteId): bool
    {
        return DB::table('clientes_roles as cr')
            ->join('roles as r', 'r.id', '=', 'cr.rol_id')
            ->where('cr.cliente_id', $clienteId)
            ->where('r.codigo', 'PROPIETARIO')
            ->exists();
    }

    /** @param Collection<int, \App\Models\ClienteCuenta> $cuentas */
    private function cuentasCliente(Collection $cuentas): array
    {
        return $cuentas
            ->map(fn ($cuenta): string => preg_replace('/\D+/', '', (string) $cuenta->cuenta) ?? '')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
