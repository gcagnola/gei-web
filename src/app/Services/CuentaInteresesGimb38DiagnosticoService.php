<?php

namespace App\Services;

use App\Services\Portal\PortalCuentaCorrienteService;
use App\Support\Cobol\Gimb38InteresDiagnostico;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Mismo calculador GIMB38 que usa la simulacion del portal.
 * EXCLUSIVAMENTE contra un cierre COBOL PRUEBA autorizado de INQUILINO.
 * No representa deuda exigible ni modifica movimientos.
 */
final class CuentaInteresesGimb38DiagnosticoService
{
    public function simular(PortalCuentaCorrienteService $servicio, array $snapshot, string $cuenta, string $fecha): array
    {
        if (! app()->environment('local', 'testing')
            || ! config('portal.cuenta_corriente.web_prueba', false)
            || ! in_array(substr($cuenta, 0, 4), ['1103'], true)
            || ($snapshot['fuente'] ?? '') !== 'CIERRE_PRUEBA') {
            throw new RuntimeException('Simulación GIMB38 no habilitada para esta cuenta o cierre.');
        }
        $cierre = (string) ($snapshot['cierre'] ?? '');
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fecha)
            || ! checkdate((int) substr($fecha, 5, 2), (int) substr($fecha, 8, 2), (int) substr($fecha, 0, 4))) {
            throw ValidationException::withMessages(['fecha_calculo' => 'Fecha de simulación inválida.']);
        }
        if ($fecha < $cierre) {
            throw ValidationException::withMessages(['fecha_calculo' => 'La fecha no puede preceder el cierre COBOL.']);
        }
        $iva = $servicio->tipoIvaInquilinoEnPrueba($cuenta);
        if ((string) ($iva['cierre'] ?? '') !== $cierre) {
            throw new RuntimeException('Cierres de movimientos y TIPOIVA no coinciden.');
        }
        $calc = Gimb38InteresDiagnostico::desdeDirectorio(
            storage_path('app/private/cobol/parametros_autogestion')
        );
        $centavos = static function ($valor): int {
            $valor = (string) $valor;
            if (! preg_match('/^([0-9]+)\.([0-9]{2})$/D', $valor, $m)) {
                throw new RuntimeException('Importe COBOL inválido.');
            }
            return (int) $m[1] * 100 + (int) $m[2];
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
                throw new RuntimeException('Cargo con importe abonado superior al original.');
            }
            if ($pendiente === 0) {
                continue;
            }
            if ($tipo === 'CREDITO') {
                $creditos += $pendiente;
                $filas[] = ['fecha' => $m['fecha'], 'codigo' => $m['codigo'], 'numero' => $m['numero'],
                    'concepto' => 'CREDITO: '.$m['descripcion'], 'vence' => '-', 'dias' => 0,
                    'capital' => -$pendiente, 'interes' => 0, 'iva' => 0, 'sobretasa' => 0];
                continue;
            }
            if (empty($m['fecha_vencimiento'])) {
                throw new RuntimeException('Cargo pendiente sin vencimiento.');
            }
            $r = $calc->calcular($pendiente, (string) $m['fecha_vencimiento'], $fecha, (int) $iva['tipo_iva']);
            $capital += $pendiente;
            $interes += $r['interes'];
            $impuesto += $r['iva'];
            $sobre += $r['sobretasa'];
            $filas[] = ['fecha' => $m['fecha'], 'codigo' => $m['codigo'], 'numero' => $m['numero'],
                'concepto' => $m['descripcion'], 'vence' => $m['fecha_vencimiento'], 'dias' => $r['dias'],
                'capital' => $pendiente, 'interes' => $r['interes'], 'iva' => $r['iva'],
                'sobretasa' => $r['sobretasa']];
        }
        return ['cuenta' => $cuenta, 'fecha' => $fecha, 'cierre' => $cierre,
            'tipo_iva' => $iva['tipo_iva'], 'capital' => $capital, 'creditos' => $creditos,
            'interes' => $interes, 'iva' => $impuesto, 'sobretasa' => $sobre,
            'total' => $capital + $interes + $impuesto + $sobre - $creditos,
            'filas' => $filas];
    }
}
