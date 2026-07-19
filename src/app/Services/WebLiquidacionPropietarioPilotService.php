<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WebLiquidacionPropietarioPilotService
{
    private const TEMP_DATABASE = 'db_gei_web_migraciones_test';

    /**
     * @return array<string, mixed>
     */
    public function reconstruir(string $cuentaPropietario, ?string $periodo = null, int $detalleLimite = 200): array
    {
        $version = DB::selectOne('select version() as version, current_database() as database');
        $this->assertTemporalPostgresql17((string) $version->version, (string) $version->database);

        $propietario = DB::table('web_propietarios as p')
            ->join('web_personas as pe', 'p.persona_id', '=', 'pe.id')
            ->where('p.cuenta_propietario', $cuentaPropietario)
            ->select([
                'p.id',
                'p.cuenta_propietario',
                'p.forma_pago_codigo',
                'p.subforma_pago_codigo',
                'p.comision_administracion',
                'p.comision_impuestos',
                'p.liquidar',
                'p.estado',
                'pe.nombre',
                'pe.razon_social',
                'pe.cuit',
                'pe.domicilio_principal',
                'pe.localidad',
                'pe.provincia',
            ])
            ->first();

        if ($propietario === null) {
            $alternativa = $this->cuentaAlternativaConMovimientos();

            return [
                'database' => $version->database,
                'postgresql_version' => $version->version,
                'cuenta_propietario' => $cuentaPropietario,
                'estado' => 'SIN_PROPIETARIO',
                'alternativa_sugerida' => $alternativa,
                'advertencias' => ['La cuenta solicitada no existe en web_propietarios.'],
            ];
        }

        $periodoUsado = $periodo ?? $this->detectarPeriodo($cuentaPropietario);
        if ($periodoUsado === null) {
            return [
                'database' => $version->database,
                'postgresql_version' => $version->version,
                'cuenta_propietario' => $cuentaPropietario,
                'propietario' => $this->propietarioPayload($propietario),
                'estado' => 'SIN_MOVIMIENTOS',
                'advertencias' => ['La cuenta no tiene movimientos de propietario cargados en web_movimientos_cuenta.'],
            ];
        }

        $baseMovimientos = DB::table('web_movimientos_cuenta as m')
            ->leftJoin('web_conceptos_movimiento as c', 'm.concepto_id', '=', 'c.id')
            ->leftJoin('web_registros_origen as r', 'm.registro_origen_id', '=', 'r.id')
            ->where('m.dominio', 'PROPIETARIO')
            ->where('m.cuenta_origen', $cuentaPropietario)
            ->where('m.periodo', $periodoUsado);

        $totales = (clone $baseMovimientos)
            ->selectRaw('count(*) as cantidad, coalesce(sum(m.debe), 0) as total_debe, coalesce(sum(m.haber), 0) as total_haber, coalesce(sum(m.haber), 0) - coalesce(sum(m.debe), 0) as total_neto')
            ->first();

        $movimientos = (clone $baseMovimientos)
            ->orderBy('r.numero_linea')
            ->orderBy('m.id')
            ->limit($detalleLimite)
            ->get([
                'm.id',
                'm.fecha',
                'm.periodo',
                'm.codigo_concepto',
                'c.descripcion as concepto_catalogo',
                'm.numero_movimiento',
                'm.descripcion',
                'm.debe',
                'm.haber',
                'm.importe',
                'm.iva',
                'm.no_gravado',
                'm.liquidado_origen',
                'm.contrato_id',
                'm.inquilino_id',
                'm.inmueble_id',
                'r.archivo_origen',
                'r.numero_linea',
                'r.hash_registro',
            ])
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'fecha' => $row->fecha,
                'periodo' => $row->periodo,
                'codigo_concepto' => $row->codigo_concepto,
                'concepto' => $row->concepto_catalogo ?: $row->descripcion,
                'numero_movimiento' => $row->numero_movimiento,
                'descripcion' => $row->descripcion,
                'debe' => (string) $row->debe,
                'haber' => (string) $row->haber,
                'importe' => (string) $row->importe,
                'iva' => $row->iva === null ? null : (string) $row->iva,
                'no_gravado' => $row->no_gravado === null ? null : (string) $row->no_gravado,
                'liquidado_origen' => $row->liquidado_origen,
                'contrato_id' => $row->contrato_id === null ? null : (int) $row->contrato_id,
                'inquilino_id' => $row->inquilino_id === null ? null : (int) $row->inquilino_id,
                'inmueble_id' => $row->inmueble_id === null ? null : (int) $row->inmueble_id,
                'archivo_origen' => $row->archivo_origen,
                'numero_linea' => $row->numero_linea === null ? null : (int) $row->numero_linea,
                'hash_registro' => $row->hash_registro,
            ])
            ->all();

        [$periodoInicio, $periodoFin] = $this->periodoRango($periodoUsado);

        $contratos = DB::table('web_contrato_propietarios as cp')
            ->join('web_contratos as co', 'cp.contrato_id', '=', 'co.id')
            ->join('web_contrato_inquilinos as ci', 'ci.contrato_id', '=', 'co.id')
            ->join('web_inquilinos as i', 'ci.inquilino_id', '=', 'i.id')
            ->join('web_personas as pi', 'i.persona_id', '=', 'pi.id')
            ->join('web_contrato_inmuebles as cin', 'cin.contrato_id', '=', 'co.id')
            ->join('web_inmuebles as inm', 'cin.inmueble_id', '=', 'inm.id')
            ->where('cp.propietario_id', $propietario->id)
            ->where(function ($query) use ($periodoFin): void {
                $query
                    ->whereNull('co.fecha_inicio')
                    ->orWhere('co.fecha_inicio', '<=', $periodoFin);
            })
            ->where(function ($query) use ($periodoInicio): void {
                $query
                    ->whereNull('co.fecha_fin')
                    ->orWhere('co.fecha_fin', '>=', $periodoInicio);
            })
            ->where(function ($query) use ($periodoInicio): void {
                $query
                    ->whereNull('co.fecha_baja')
                    ->orWhere('co.fecha_baja', '>=', $periodoInicio);
            })
            ->orderBy('co.fecha_inicio')
            ->limit(100)
            ->get([
                'co.id as contrato_id',
                'co.codigo_origen',
                'co.cuenta_inquilino_origen',
                'co.fecha_inicio',
                'co.fecha_fin',
                'co.fecha_baja',
                'i.cuenta_inquilino',
                'pi.nombre as inquilino',
                'inm.id as inmueble_id',
                'inm.domicilio',
            ])
            ->map(fn ($row) => [
                'contrato_id' => (int) $row->contrato_id,
                'codigo_origen' => $row->codigo_origen,
                'cuenta_inquilino' => $row->cuenta_inquilino,
                'cuenta_inquilino_origen' => $row->cuenta_inquilino_origen,
                'inquilino' => $row->inquilino,
                'inmueble_id' => (int) $row->inmueble_id,
                'domicilio' => $row->domicilio,
                'fecha_inicio' => $row->fecha_inicio,
                'fecha_fin' => $row->fecha_fin,
                'fecha_baja' => $row->fecha_baja,
            ])
            ->all();

        $movimientosSinContrato = (clone $baseMovimientos)
            ->whereNull('m.contrato_id')
            ->count();

        $totalDebe = (string) $totales->total_debe;
        $totalHaber = (string) $totales->total_haber;

        return [
            'database' => $version->database,
            'postgresql_version' => $version->version,
            'estado' => 'LIQUIDACION_PILOTO_RECONSTRUIDA',
            'cuenta_propietario' => $cuentaPropietario,
            'propietario' => $this->propietarioPayload($propietario),
            'periodo_usado' => $periodoUsado,
            'criterio_periodo' => $periodo === null ? 'ultimo_periodo_con_movimientos_propietario' : 'periodo_parametrizado',
            'cantidad_movimientos' => (int) $totales->cantidad,
            'total_debe' => $totalDebe,
            'total_haber' => $totalHaber,
            'total_neto' => (string) $totales->total_neto,
            'detalle_limite' => $detalleLimite,
            'detalle_movimientos' => $movimientos,
            'contratos_inquilinos_relacionados' => $contratos,
            'movimientos_sin_contrato' => $movimientosSinContrato,
            'advertencias' => $this->advertencias($movimientosSinContrato, count($contratos)),
        ];
    }

    private function assertTemporalPostgresql17(string $version, string $database): void
    {
        if ($database === 'db_gei') {
            throw new RuntimeException('Liquidacion piloto abortada: DB_DATABASE apunta a db_gei.');
        }

        if ($database !== self::TEMP_DATABASE) {
            throw new RuntimeException("Liquidacion piloto abortada: base no temporal detectada ({$database}).");
        }

        if (! str_contains($version, 'PostgreSQL 17')) {
            throw new RuntimeException('Liquidacion piloto abortada: la conexion no apunta a PostgreSQL 17.');
        }
    }

    private function detectarPeriodo(string $cuentaPropietario): ?string
    {
        $periodo = DB::table('web_movimientos_cuenta')
            ->where('dominio', 'PROPIETARIO')
            ->where('cuenta_origen', $cuentaPropietario)
            ->whereNotNull('periodo')
            ->max('periodo');

        return $periodo === null ? null : (string) $periodo;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function periodoRango(string $periodo): array
    {
        $inicio = CarbonImmutable::createFromFormat('Ymd', "{$periodo}01")->startOfDay();

        return [
            $inicio->toDateString(),
            $inicio->endOfMonth()->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function cuentaAlternativaConMovimientos(): ?array
    {
        $row = DB::table('web_movimientos_cuenta')
            ->where('dominio', 'PROPIETARIO')
            ->select('cuenta_origen', DB::raw('count(*) as movimientos'))
            ->groupBy('cuenta_origen')
            ->orderByDesc('movimientos')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'cuenta_propietario' => $row->cuenta_origen,
            'movimientos' => (int) $row->movimientos,
        ];
    }

    /**
     * @param object $propietario
     * @return array<string, mixed>
     */
    private function propietarioPayload(object $propietario): array
    {
        return [
            'id' => (int) $propietario->id,
            'cuenta_propietario' => $propietario->cuenta_propietario,
            'nombre' => $propietario->razon_social ?: $propietario->nombre,
            'cuit' => $propietario->cuit,
            'domicilio' => $propietario->domicilio_principal,
            'localidad' => $propietario->localidad,
            'provincia' => $propietario->provincia,
            'forma_pago_codigo' => $propietario->forma_pago_codigo,
            'subforma_pago_codigo' => $propietario->subforma_pago_codigo,
            'comision_administracion' => $propietario->comision_administracion === null ? null : (string) $propietario->comision_administracion,
            'comision_impuestos' => $propietario->comision_impuestos === null ? null : (string) $propietario->comision_impuestos,
            'liquidar' => $propietario->liquidar,
            'estado' => $propietario->estado,
        ];
    }

    /**
     * @return list<string>
     */
    private function advertencias(int $movimientosSinContrato, int $contratosRelacionados): array
    {
        $advertencias = [
            'REGLA_PENDIENTE_COBOL: esta reconstruccion suma movimientos de propietario por periodo; aun no reproduce GIMB23 completo.',
            'REGLA_PENDIENTE_COBOL: no se aplican todavia ordenes NOLIQ.PROPI, cotizaciones, correlativos ni marcas de consumo de liquidacion.',
        ];

        if ($movimientosSinContrato > 0) {
            $advertencias[] = "Los {$movimientosSinContrato} movimientos de propietario no tienen contrato directo; la relacion con inquilinos se informa por contratos vigentes del propietario.";
        }

        if ($contratosRelacionados === 0) {
            $advertencias[] = 'No se encontraron contratos/inquilinos relacionados para el propietario en el periodo usado.';
        }

        return $advertencias;
    }
}
