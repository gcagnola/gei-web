<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

/**
 * Movimientos de la cuenta corriente operativa, acotados a rol y cuenta COBOL.
 * El controlador entrega únicamente cuentas canónicas autorizadas por sede.
 */
final class CuentaCorrienteMovimientosClienteService
{
    /** @return Collection<int, object> */
    public function consultar(Collection $cuentasAutorizadas, string $mes): Collection
    {
        if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/D', $mes) !== 1) {
            throw new InvalidArgumentException('Mes de cuenta corriente inválido.');
        }

        $claves = CuentaCorrienteSaldosClienteService::clavesAutorizadas($cuentasAutorizadas);
        if ($claves === []) {
            return collect();
        }

        $desde = substr($mes, 0, 4).'-'.substr($mes, 4, 2).'-01';
        $hasta = CarbonImmutable::parse($desde)->addMonthNoOverflow()->toDateString();

        $movimientos = DB::table('cuentas_corrientes as cc')
            ->join('cuentas_corrientes_movimientos as m', 'm.cuenta_corriente_id', '=', 'cc.id')
            ->where(static function ($q) use ($claves): void {
                foreach ($claves as $clave) {
                    $q->orWhere(static function ($cuenta) use ($clave): void {
                        $cuenta->where('cc.dominio', $clave['rol'])
                            ->where('cc.cuenta', $clave['cuenta']);
                    });
                }
            })
            ->where('m.fecha', '>=', $desde)
            ->where('m.fecha', '<', $hasta)
            ->orderBy('cc.dominio')
            ->orderBy('cc.cuenta')
            ->orderBy('m.fecha')
            ->orderBy('m.id')
            ->get([
                'cc.dominio as tipo', 'cc.cuenta as cuenta_cobol',
                'm.id', 'm.fecha', 'm.codigo', 'm.numero',
                'm.descripcion', 'm.debe', 'm.haber', 'm.afecta_saldo',
            ]);

        if ($movimientos->isEmpty()) {
            return $movimientos;
        }

        // El catálogo de conceptos es operativo: no se consulta Core.
        $codigos = $movimientos->pluck('codigo')
            ->map(static fn ($c): string => str_pad(trim((string) $c), 2, '0', STR_PAD_LEFT))
            ->filter()->unique()->values()->all();
        $conceptos = DB::table('conceptos')
            ->whereIn('dominio', ['INQ', 'PROP'])
            ->whereIn('codigo', $codigos)
            ->get(['id', 'dominio', 'codigo', 'descripcion'])
            ->keyBy(static fn ($c): string => strtoupper(trim((string) $c->dominio))
                .'|'.str_pad(trim((string) $c->codigo), 2, '0', STR_PAD_LEFT));

        // Conservar los indicadores de imputación Caja/Contabilidad que mostraba
        // el parcial heredado, consultando exclusivamente catálogos operativos.
        $imputaciones = collect();
        if ($conceptos->isNotEmpty()
            && Schema::hasTable('conceptos_imputaciones_caja')
            && Schema::hasTable('cuentas_caja')
            && Schema::hasTable('cuentas_contables')) {
            $imputaciones = DB::table('conceptos_imputaciones_caja as i')
                ->join('conceptos as c', 'c.id', '=', 'i.concepto_id')
                ->leftJoin('cuentas_caja as cj', 'cj.id', '=', 'i.cuenta_caja_id')
                ->leftJoin('cuentas_contables as ct', 'ct.id', '=', 'cj.cuenta_contable_id')
                ->whereIn('i.concepto_id', $conceptos->pluck('id')->all())
                ->whereNotNull('i.cuenta_caja_id')
                ->orderBy('c.dominio')->orderBy('c.codigo')
                ->orderBy('i.sede')->orderBy('i.moneda')->orderBy('i.judicial')
                ->get([
                    'c.dominio', 'c.codigo', 'i.sede', 'i.moneda', 'i.judicial',
                    'cj.codigo_cobol as cuenta_caja',
                    'ct.codigo as cuenta_contable',
                    'ct.descripcion as cuenta_contable_descripcion',
                ]);
        }
        $porConceptoYSede = $imputaciones->groupBy(
            static fn ($i): string => strtoupper(trim((string) $i->dominio)).'|'
                .str_pad(trim((string) $i->codigo), 2, '0', STR_PAD_LEFT).'|'
                .strtoupper(trim((string) $i->sede))
        );

        foreach ($movimientos as $m) {
            $dominio = $m->tipo === 'PROPIETARIO' ? 'PROP' : 'INQ';
            $codigo = str_pad(trim((string) $m->codigo), 2, '0', STR_PAD_LEFT);
            $concepto = $conceptos->get($dominio.'|'.$codigo);
            $m->concepto_descripcion = $concepto?->descripcion;
            $sede = match (substr((string) $m->cuenta_cobol, 0, 4)) {
                '1103', '1202' => 'SF',
                '2103', '2202' => 'ST',
                default => null,
            };
            $lineas = $sede !== null
                ? $porConceptoYSede->get($dominio.'|'.$codigo.'|'.$sede, collect())
                : collect();

            $detalle = $lineas->map(static function ($fila): string {
                $caja = trim((string) ($fila->cuenta_caja ?? ''));
                $contable = trim((string) ($fila->cuenta_contable ?? ''));
                $descripcion = trim((string) ($fila->cuenta_contable_descripcion ?? ''));
                return trim((string) $fila->sede).' · '.trim((string) $fila->moneda).' · '
                    .((bool) $fila->judicial ? 'Judicial' : 'Normal')
                    .' → Caja '.($caja !== '' ? $caja : '—')
                    .' → '.($contable !== ''
                        ? $contable.($descripcion !== '' ? ' - '.$descripcion : '')
                        : 'sin cuenta contable vinculada');
            })->unique()->values();

            $m->contabilidad_tooltip = $concepto === null ? null
                : 'Concepto: '.$dominio.' '.$codigo.' - '.trim((string) $concepto->descripcion)
                    ."\n".($detalle->isNotEmpty() ? $detalle->implode("\n") : 'Imputación Caja/contable: no configurada');
            $m->contabilidad_completa = $lineas->isNotEmpty()
                && $lineas->every(static fn ($fila): bool =>
                    trim((string) ($fila->cuenta_caja ?? '')) !== ''
                    && trim((string) ($fila->cuenta_contable ?? '')) !== '');
        }

        return $movimientos;
    }
}
