<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Proyeccion de lectura sobre movimientos OPERATIVOS existentes.
 * No reemplaza el calculo GIMB68/COCCTE ni la conciliacion del cierre COBOL.
 * NO representa por si sola una deuda exigible.
 */
final class CuentaCorrienteSaldosClienteService
{
    /** @return array<int, array{rol:string,cuenta:string}> */
    public static function clavesAutorizadas(Collection $cuentas): array
    {
        $claves = [];
        foreach ($cuentas as $fila) {
            $rol = strtoupper(trim((string) ($fila->rol ?? '')));
            $cuenta = trim((string) ($fila->cuenta ?? ''));
            if (! in_array($rol, ['PROPIETARIO', 'INQUILINO'], true)) {
                continue;
            }
            $esperado = match (substr($cuenta, 0, 4)) {
                '1103', '2103' => 'INQUILINO',
                '1202', '2202' => 'PROPIETARIO',
                default => null,
            };
            if (preg_match('/^[0-9]{11}$/D', $cuenta) !== 1 || $esperado !== $rol) {
                throw new InvalidArgumentException('Cuenta COBOL y rol incompatibles.');
            }
            $claves[$rol.'|'.$cuenta] = ['rol' => $rol, 'cuenta' => $cuenta];
        }

        return array_values($claves);
    }

    /**
     * Las cuentas provienen de ClienteMaestroController DESPUES de filtrar
     * cliente canonico y sedes. Nunca se aceptan IDs de cuenta via navegador.
     * Los resultados monetarios son NUMERIC de PostgreSQL expresados como string.
     */
    public function consultar(Collection $cuentasAutorizadas, string $mes, ?string $fechaConsulta = null): Collection
    {
        if (preg_match('/^(19|20)[0-9]{2}(0[1-9]|1[0-2])$/D', $mes) !== 1) {
            throw new InvalidArgumentException('Período mensual inválido.');
        }
        $claves = self::clavesAutorizadas($cuentasAutorizadas);
        if ($claves === []) {
            return collect();
        }

        $desde = substr($mes, 0, 4).'-'.substr($mes, 4, 2).'-01';
        $hasta = CarbonImmutable::parse($desde)->addMonthNoOverflow()->toDateString();
        // GEI_PORTAL_CC_INTERESES_UNIFICADOS_20261110: excluir asientos posteriores
        // a la fecha informada en pantalla. No cambia los demas consumidores.
        if ($fechaConsulta !== null) {
            if (! preg_match('/^20\d{2}-\d{2}-\d{2}$/D', $fechaConsulta)
                || ! checkdate((int) substr($fechaConsulta, 5, 2), (int) substr($fechaConsulta, 8, 2), (int) substr($fechaConsulta, 0, 4))) {
                throw new InvalidArgumentException('Fecha de consulta invalida.');
            }
            $hasta = min($hasta, CarbonImmutable::parse($fechaConsulta)->addDay()->toDateString());
            // Un mes futuro no puede proyectar deuda a una fecha anterior.
            if ($hasta < $desde) {
                throw new InvalidArgumentException('No se puede consultar un periodo futuro.');
            }
        }
        $cuentas = DB::table('cuentas_corrientes')
            ->where(function ($q) use ($claves): void {
                foreach ($claves as $clave) {
                    $q->orWhere(static fn ($sub) => $sub
                        ->where('dominio', $clave['rol'])
                        ->where('cuenta', $clave['cuenta']));
                }
            })
            ->get(['id', 'dominio', 'cuenta']);

        if ($cuentas->isEmpty()) {
            return collect();
        }
        $ids = $cuentas->pluck('id')->all();

        // El total sin filtro se cuenta para informar fechas ausentes.
        // Para saldos, SOLO registros que afectan saldo; no inventar apuntes.
        // El corte se hace por fecha del movimiento, no periodo de importacion.
        $base = DB::table('cuentas_corrientes_movimientos as m')
            ->whereIn('m.cuenta_corriente_id', $ids)
            ->select('m.cuenta_corriente_id')
            ->selectRaw('COUNT(*) FILTER (WHERE m.fecha IS NULL) AS fechas_sin_definir')
            ->selectRaw('COALESCE(SUM(CASE WHEN m.fecha < ? AND COALESCE(m.afecta_saldo, true) THEN COALESCE(m.debe, 0) ELSE 0 END),0) AS debe_anterior', [$desde])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.fecha < ? AND COALESCE(m.afecta_saldo, true) THEN COALESCE(m.haber, 0) ELSE 0 END),0) AS haber_anterior', [$desde])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.fecha >= ? AND m.fecha < ? AND COALESCE(m.afecta_saldo, true) THEN COALESCE(m.debe, 0) ELSE 0 END),0) AS debe_mes', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.fecha >= ? AND m.fecha < ? AND COALESCE(m.afecta_saldo, true) THEN COALESCE(m.haber, 0) ELSE 0 END),0) AS haber_mes', [$desde, $hasta])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.fecha < ? AND COALESCE(m.afecta_saldo, true) THEN COALESCE(m.debe, 0) - COALESCE(m.haber, 0) ELSE 0 END),0) AS saldo_anterior', [$desde])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.fecha < ? AND COALESCE(m.afecta_saldo, true) THEN COALESCE(m.debe, 0) - COALESCE(m.haber, 0) ELSE 0 END),0) AS saldo_al_corte', [$hasta])
            ->selectRaw('COALESCE(SUM(CASE WHEN m.fecha < ? THEN COALESCE(m.importe_penalidad, 0) ELSE 0 END),0) AS penalidades_registradas', [$hasta])
            ->groupBy('m.cuenta_corriente_id')
            ->get()
            ->keyBy('cuenta_corriente_id');

        return $cuentas->map(static function ($c) use ($base): object {
            $r = $base->get($c->id);
            return (object) [
                'rol' => $c->dominio,
                'cuenta' => $c->cuenta,
                'debe_anterior' => (string) ($r->debe_anterior ?? '0.00'),
                'haber_anterior' => (string) ($r->haber_anterior ?? '0.00'),
                'saldo_anterior' => (string) ($r->saldo_anterior ?? '0.00'),
                'debe_mes' => (string) ($r->debe_mes ?? '0.00'),
                'haber_mes' => (string) ($r->haber_mes ?? '0.00'),
                'saldo_al_corte' => (string) ($r->saldo_al_corte ?? '0.00'),
                'penalidades_registradas' => (string) ($r->penalidades_registradas ?? '0.00'),
                'fechas_sin_definir' => (int) ($r->fechas_sin_definir ?? 0),
            ];
        });
    }
}
