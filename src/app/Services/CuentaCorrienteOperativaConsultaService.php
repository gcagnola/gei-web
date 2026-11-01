<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Listado administrativo, exclusivamente de lectura, sobre gei_db.public.
 * La correspondencia de titulares se resuelve por (rol, cuenta) y cliente
 * CANONICO; nunca por ID de Persona Core ni por similitud de nombre/CUIT.
 * Los importes operativos no son equivalentes a un cierre GIMB68/GIMB38.
 */
final class CuentaCorrienteOperativaConsultaService
{
    /** @return array<int,string> */
    public static function prefijosSedes(array $sedes): array
    {
        $prefijos = [];
        foreach ($sedes as $sede) {
            foreach (match (strtoupper(trim((string) $sede))) {
                'SF' => ['1103', '1202'],
                'ST' => ['2103', '2202'],
                default => [],
            } as $prefijo) {
                $prefijos[$prefijo] = $prefijo;
            }
        }

        return array_values($prefijos);
    }

    public static function tipoValido(string $tipo): string
    {
        $tipo = strtoupper(trim($tipo));
        return in_array($tipo, ['PROPIETARIO', 'INQUILINO'], true)
            ? $tipo : 'PROPIETARIO';
    }

    private function base(string $tipo, array $prefijos): Builder
    {
        return DB::table('cuentas_corrientes as c')
            ->where('c.dominio', self::tipoValido($tipo))
            ->whereIn(DB::raw('LEFT(c.cuenta, 4)'), $prefijos);
    }

    /**
     * Si una cuenta corresponde a varios clientes canónicos, se muestra como
     * ambigua y NO se enlaza ni se escoge arbitrariamente un titular.
     */
    private function titularesUnicos(): Builder
    {
        return DB::table('clientes_cuentas as cc')
            ->join('clientes as origen', 'origen.id', '=', 'cc.cliente_id')
            ->whereIn('cc.rol', ['PROPIETARIO', 'INQUILINO'])
            ->select('cc.rol', 'cc.cuenta')
            ->selectRaw('COUNT(DISTINCT COALESCE(origen.id_cliente_canonico, origen.id))::integer AS cantidad_canonicos')
            ->selectRaw('MIN(COALESCE(origen.id_cliente_canonico, origen.id)) AS cliente_canonico_id')
            ->groupBy('cc.rol', 'cc.cuenta');
    }

    private function conTitular(string $tipo, array $prefijos): Builder
    {
        return $this->base($tipo, $prefijos)
            ->leftJoinSub($this->titularesUnicos(), 'v', static function ($join): void {
                $join->on('v.rol', '=', 'c.dominio')
                    ->on('v.cuenta', '=', 'c.cuenta');
            })
            ->leftJoin('clientes as titular', static function ($join): void {
                $join->on('titular.id', '=', 'v.cliente_canonico_id')
                    ->where('v.cantidad_canonicos', '=', 1);
            });
    }

    /** @return array{cuentas:int,activas:int,movimientos:int} */
    public function cuentasResumen(string $tipo, array $prefijos, string $periodo): array
    {
        $cuentas = $this->base($tipo, $prefijos)
            ->selectRaw('COUNT(*)::bigint AS cuentas')
            ->first();

        // GEI_ACTIVIDAD_DB_PERIODO_20261030: PK (periodo, dominio, cuenta).
        $activas = $this->base($tipo, $prefijos)
            ->join('cuentas_actividad_periodos as actividad', static function ($join) use ($periodo): void {
                $join->on('actividad.cuenta', '=', 'c.cuenta')
                    ->on('actividad.dominio', '=', 'c.dominio')
                    ->where('actividad.periodo', '=', $periodo);
            })
            ->where('actividad.con_actividad', true)
            ->count();

        $movimientos = DB::table('cuentas_corrientes_movimientos as m')
            ->join('cuentas_corrientes as c', 'c.id', '=', 'm.cuenta_corriente_id')
            ->where('c.dominio', self::tipoValido($tipo))
            ->whereIn(DB::raw('LEFT(c.cuenta, 4)'), $prefijos)
            ->count('m.id');

        return [
            'cuentas' => (int) ($cuentas->cuentas ?? 0),
            'activas' => (int) $activas,
            'movimientos' => (int) $movimientos,
        ];
    }

    public function cuentas(
        string $tipo,
        string $estado,
        string $buscar,
        array $prefijos,
        string $periodo,
        int $porPagina = 50
    ): LengthAwarePaginator {
        $estado = in_array($estado, ['activas', 'todas'], true) ? $estado : 'activas';
        $buscar = trim($buscar);

        return $this->conTitular($tipo, $prefijos)
            ->leftJoin('cuentas_actividad_periodos as actividad', static function ($join) use ($periodo): void {
                $join->on('actividad.cuenta', '=', 'c.cuenta')
                    ->on('actividad.dominio', '=', 'c.dominio')
                    ->where('actividad.periodo', '=', $periodo);
            })
            ->when($estado === 'activas', static fn ($q) => $q->where('actividad.con_actividad', true))
            ->when($buscar !== '', static function ($q) use ($buscar): void {
                // Búsqueda de personas sólo cuando la vinculación es UNICA.
                $like = '%'.$buscar.'%';
                $q->where(static function ($s) use ($like): void {
                    $s->where('c.cuenta', 'ILIKE', $like)
                        ->orWhere('titular.nombre', 'ILIKE', $like)
                        ->orWhere('titular.cuit', 'ILIKE', $like)
                        ->orWhere('titular.numero_documento', 'ILIKE', $like);
                });
            })
            ->select([
                'c.id', 'c.cuenta as cuenta_cobol', 'c.activo as activa',
                'titular.nombre', 'titular.cuit as nro_iva',
                'titular.numero_documento as nro_documento',
                'titular.id as cliente_canonico_id',
            ])
            ->selectRaw('COALESCE(v.cantidad_canonicos, 0) AS cantidad_canonicos')
            ->selectRaw('CASE WHEN actividad.con_actividad IS TRUE THEN 1 ELSE 0 END AS activa_periodo')
            // Subconsultas por fila de la página; no multiplican cuentas por movimientos.
            ->selectRaw('(SELECT COUNT(*) FROM cuentas_corrientes_movimientos AS m WHERE m.cuenta_corriente_id = c.id) AS movimientos')
            ->selectRaw('(SELECT MIN(m.periodo) FROM cuentas_corrientes_movimientos AS m WHERE m.cuenta_corriente_id = c.id) AS primer_periodo')
            ->selectRaw('(SELECT MAX(m.periodo) FROM cuentas_corrientes_movimientos AS m WHERE m.cuenta_corriente_id = c.id) AS ultimo_periodo')
            ->orderByRaw('titular.nombre NULLS LAST')
            ->orderBy('c.cuenta')
            ->paginate(max(1, min($porPagina, 100)))
            ->withQueryString();
    }

    /** @return array{cuenta:object,movimientos:\Illuminate\Support\Collection,orden:string} */
    public function cuentaDetalle(int $id, string $tipo, array $prefijos, string $orden = 'desc'): array
    {
        $orden = strtolower($orden) === 'asc' ? 'asc' : 'desc';
        $cuenta = $this->conTitular($tipo, $prefijos)
            ->where('c.id', $id)
            ->select([
                'c.id', 'c.cuenta as cuenta_cobol', 'c.dominio', 'c.activo as activa',
                'c.contrato_id', 'titular.id as cliente_canonico_id',
                'titular.nombre', 'titular.cuit as nro_iva',
                'titular.numero_documento as nro_documento',
            ])
            ->selectRaw('COALESCE(v.cantidad_canonicos, 0) AS cantidad_canonicos')
            ->first();

        abort_if($cuenta === null, 404, 'Cuenta corriente no encontrada en la sede y rol autorizados.');

        $cuenta->domicilio_actual = null;
        // Un contrato puede vincular varios inmuebles: no elegir uno al azar.
        if ($cuenta->contrato_id !== null) {
            $domicilios = DB::table('contratos_inmuebles as ci')
                ->join('inmuebles as i', 'i.id', '=', 'ci.inmueble_id')
                ->where('ci.contrato_id', $cuenta->contrato_id)
                ->whereNotNull('i.domicilio')
                ->distinct()->orderBy('i.domicilio')
                ->pluck('i.domicilio')->all();
            $cuenta->domicilio_actual = $domicilios !== [] ? implode(' · ', $domicilios) : null;
        }

        $movimientos = DB::table('cuentas_corrientes_movimientos as m')
            ->where('m.cuenta_corriente_id', $cuenta->id)
            ->select([
                'm.id', 'm.codigo', 'm.numero', 'm.descripcion',
                'm.importe', 'm.debe', 'm.haber', 'm.periodo',
                'm.fecha_vencimiento', 'm.afecta_saldo',
                'm.importe_penalidad', 'm.importe_abonado',
                'm.iva', 'm.no_gravado',
            ])
            ->selectRaw('COALESCE(m.fecha::text, m.fecha_origen) AS fecha_original')
            ->selectRaw('m.liquidado_origen AS liquidado')
            ->orderByRaw('m.fecha '.$orden.' NULLS LAST')
            ->orderBy('m.id', $orden)
            ->limit(150)
            ->get();

        return compact('cuenta', 'movimientos', 'orden');
    }
}
