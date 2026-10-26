<?php

namespace App\Http\Controllers;

use App\Services\Portal\PortalCuentaCorrienteService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

final class PortalCuentaCorrienteController extends Controller
{
    public function index(Request $request, PortalCuentaCorrienteService $servicio): View
    {
        $datos = $request->validate([
            'seleccion' => ['nullable', 'string', 'max:30'],
            'periodo' => ['nullable', 'regex:/^(19|20)[0-9]{2}(0[1-9]|1[0-2])$/'],
            'page' => ['nullable', 'integer', 'min:1'],
            'fecha_calculo' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $portalUsuario = Auth::guard('portal')->user();
        $portalUsuario->loadMissing('cliente.cuentas');

        // La selección parte exclusivamente de las cuentas asociadas al cliente
        // autenticado. Nunca se consulta una cuenta indicada por URL sin validarla.
        $cuentas = $portalUsuario->cliente->cuentas
            ->filter(static fn ($fila): bool => (bool) $fila->activo)
            ->map(static function ($fila): ?array {
                $cuenta = preg_replace('/\D+/', '', (string) $fila->cuenta) ?? '';
                $rol = strtoupper(trim((string) $fila->rol));
                if (! preg_match('/^[0-9]{11}$/D', $cuenta)
                    || ! in_array($rol, ['INQUILINO', 'PROPIETARIO'], true)) {
                    return null;
                }
                return ['cuenta' => $cuenta, 'rol' => $rol];
            })
            ->filter()
            ->unique(static fn (array $fila): string => $fila['rol'].'|'.$fila['cuenta'])
            ->values();

        $seleccionada = null;
        $seleccionSolicitud = trim((string) ($datos['seleccion'] ?? ''));
        if ($seleccionSolicitud !== '') {
            abort_unless(preg_match('/^(INQUILINO|PROPIETARIO)\|[0-9]{11}$/D', $seleccionSolicitud) === 1, 404);
            [$rol, $cuenta] = explode('|', $seleccionSolicitud, 2);
            $seleccionada = $cuentas->first(static fn (array $fila): bool =>
                $fila['cuenta'] === $cuenta && $fila['rol'] === $rol);
            abort_unless($seleccionada !== null, 404);
        } else {
            $seleccionada = $cuentas->first();
        }

        $periodo = (string) ($datos['periodo'] ?? '');
        $paginador = null;
        $saldosCoccte = null;
        $snapshot = null;
        $diagnostico = null;
        $fechaCalculo = (string) ($datos['fecha_calculo'] ?? '');
        $pruebaWeb = app()->environment('local', 'testing')
            && (bool) config('portal.cuenta_corriente.web_prueba', false);

        if ($seleccionada !== null) {
            // WEB_CTACTE_DIAGNOSTICO_20261026
            // Solo en desarrollo, y siempre para cuentas asociadas al portal autenticado.
            $sf = in_array(substr($seleccionada['cuenta'], 0, 4), ['1103', '1202'], true);
            $snapshot = $pruebaWeb && $sf
                ? $servicio->consultarEnPrueba($seleccionada['cuenta'], $seleccionada['rol'])
                : $servicio->consultar($seleccionada['cuenta'], $seleccionada['rol']);

            // Período inicial: mes del último cierre COBOL disponible.
            // Un período enviado explícitamente, incluso vacío, se respeta.
            if (! $request->exists('periodo')) {
                $cierreCobol = (string) ($snapshot['cierre'] ?? '');

                if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $cierreCobol) === 1) {
                    $periodo = substr($cierreCobol, 0, 4)
                        .substr($cierreCobol, 5, 2);
                }
            }

            $fechaCalculo = $fechaCalculo !== ''
                ? $fechaCalculo : (string) ($snapshot['cierre'] ?? now()->toDateString());

            if ($pruebaWeb && $sf && $seleccionada['rol'] === 'INQUILINO'
                && $snapshot['fuente'] === 'CIERRE_PRUEBA') {
                $diagnostico = $this->simularDeuda($servicio, $snapshot, $seleccionada['cuenta'], $fechaCalculo);
            }
            // Diagnóstico contable GIMB68/COCCTE: exclusivamente desarrollo.
            if ($pruebaWeb
                && $seleccionada['rol'] === 'INQUILINO'
                && ($snapshot['fuente'] ?? '') === 'CIERRE_PRUEBA') {
                $saldosCoccte = \App\Support\Cobol\Gimb68Saldos::calcular(
                    $snapshot['movimientos'],
                    $periodo
                );
                $movimientos = collect($saldosCoccte['movimientos']);
            } else {
                $movimientos = collect($snapshot['movimientos'])
                    ->when($periodo !== '', static fn ($items) => $items->filter(
                        static fn (array $mov): bool => (string) ($mov['periodo'] ?? '') === $periodo
                    ))
                    ->sortByDesc(static fn (array $mov): string => implode('|', [
                        (string) ($mov['fecha_origen'] ?? ''),
                        (string) ($mov['codigo'] ?? ''),
                        (string) ($mov['numero'] ?? ''),
                    ]))->values();
            }

            $pagina = max(1, (int) ($datos['page'] ?? 1));
            $porPagina = 100;
            $paginador = new LengthAwarePaginator(
                $movimientos->slice(($pagina - 1) * $porPagina, $porPagina)->values(),
                $movimientos->count(),
                $porPagina,
                $pagina,
                ['path' => $request->url(), 'query' => $request->only('seleccion', 'periodo')]
            );
        }

        return view('portal.cuenta-corriente', [
            'portalUsuario' => $portalUsuario,
            'cuentas' => $cuentas,
            'seleccionada' => $seleccionada,
            'periodo' => $periodo,
            'snapshot' => $snapshot,
            'movimientos' => $paginador,
            'saldosCoccte' => $saldosCoccte,
            'diagnostico' => $diagnostico,
            'fechaCalculo' => $fechaCalculo,
            'pruebaWeb' => $pruebaWeb,
        ]);
    }
    /**
     * Simulación de deuda y mora SOLO para desarrollo. No es una liquidación exigible.
     * Reconstruye movimientos y TIPOIVA desde el mismo cierre PRUEBA.
     */
    private function simularDeuda(
        PortalCuentaCorrienteService $servicio,
        array $snapshot,
        string $cuenta,
        string $fecha
    ): array {
        if ($fecha < (string) $snapshot['cierre']) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'fecha_calculo' => 'La simulación no puede preceder al cierre COBOL.',
            ]);
        }

        $iva = $servicio->tipoIvaInquilinoEnPrueba($cuenta);
        if ((string) $iva['cierre'] !== (string) $snapshot['cierre']) {
            throw new \RuntimeException('Cierres de movimientos y TIPOIVA no coinciden.');
        }

        $calc = \App\Support\Cobol\Gimb38InteresDiagnostico::desdeDirectorio(
            storage_path('app/private/cobol/parametros_autogestion')
        );
        $centavos = static function ($valor): int {
            $v = (string) $valor;
            if (! preg_match('/^([0-9]+)\.([0-9]{2})$/D', $v, $m)) {
                throw new \RuntimeException('Importe COBOL inválido.');
            }
            return ((int) $m[1]) * 100 + (int) $m[2];
        };

        $capital = $interes = $impuesto = $sobre = $creditos = 0;
        $filas = [];
        foreach ($snapshot['movimientos'] as $m) {
            $tipo = $m['tipo_movimiento'] ?? '';

            if (! in_array($tipo, ['DEBITO', 'CREDITO'], true)) {
                continue;
            }
            $pendiente = $centavos($m['importe']) - $centavos($m['importe_abonado'] ?? '0.00');
            if ($pendiente < 0) {
                throw new \RuntimeException('Cargo con importe abonado superior al original.');
            }
            if ($pendiente === 0) {
                continue;
            }

            // GIMB38: los creditos con remanente
            // participan del saldo sin generar intereses.
            if ($tipo === 'CREDITO') {
                $creditos += $pendiente;

                $filas[] = [
                    'fecha' => $m['fecha'],
                    'codigo' => $m['codigo'],
                    'numero' => $m['numero'],
                    'concepto' => 'CREDITO: '.$m['descripcion'],
                    'vence' => '-',
                    'dias' => 0,
                    'capital' => -$pendiente,
                    'interes' => 0,
                    'iva' => 0,
                    'sobretasa' => 0,
                ];

                continue;
            }

            if (empty($m['fecha_vencimiento'])) {
                throw new \RuntimeException('Cargo pendiente sin vencimiento.');
            }
            $r = $calc->calcular(
                $pendiente,
                (string) $m['fecha_vencimiento'],
                $fecha,
                (int) $iva['tipo_iva']
            );
            $capital += $pendiente;
            $interes += $r['interes'];
            $impuesto += $r['iva'];
            $sobre += $r['sobretasa'];
            $filas[] = [
                'fecha' => $m['fecha'],
                'codigo' => $m['codigo'],
                'numero' => $m['numero'],
                'concepto' => $m['descripcion'],
                'vence' => $m['fecha_vencimiento'],
                'dias' => $r['dias'],
                'capital' => $pendiente,
                'interes' => $r['interes'],
                'iva' => $r['iva'],
                'sobretasa' => $r['sobretasa'],
            ];
        }
        return [
            'cuenta' => $cuenta,
            'fecha' => $fecha,
            'cierre' => $snapshot['cierre'],
            'tipo_iva' => $iva['tipo_iva'],
            'capital' => $capital,
            'creditos' => $creditos,
            'interes' => $interes,
            'iva' => $impuesto,
            'sobretasa' => $sobre,
            'total' => $capital + $interes + $impuesto + $sobre - $creditos,
            'filas' => $filas,
        ];
    }

}
