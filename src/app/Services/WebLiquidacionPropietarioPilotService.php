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
    public function reconstruir(
        string $cuentaPropietario,
        ?string $periodo = null,
        int $detalleLimite = 200,
        bool $clasificarMovimientos = false,
        ?string $totalEsperado = null,
        bool $construirItems = false
    ): array {
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

        $movimientosCompletos = (clone $baseMovimientos)
            ->orderBy('r.numero_linea')
            ->orderBy('m.id')
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
            ->map(fn ($row) => $this->movimientoPayload($row))
            ->all();

        $movimientos = array_slice($movimientosCompletos, 0, $detalleLimite);

        $debeTotalCents = $this->decimalToCents((string) $totales->total_debe);
        $haberTotalCents = $this->decimalToCents((string) $totales->total_haber);
        $netoTotalCents = $this->decimalToCents((string) $totales->total_neto);

        $clasificacion = ($clasificarMovimientos || $construirItems)
            ? $this->clasificarMovimientos($movimientosCompletos, (string) $totales->total_neto, $totalEsperado)
            : null;

        if ($clasificacion !== null) {
            $movimientos = array_slice($clasificacion['movimientos_clasificados'], 0, $detalleLimite);
        }

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

        $resultado = [
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
            'advertencias' => $this->advertencias($movimientosSinContrato, count($contratos), $clasificarMovimientos),
        ];

        if ($clasificacion !== null) {
            $resultado['clasificacion_movimientos'] = $clasificacion;
        }

        if ($construirItems) {
            $resultado['items_experimentales'] = $this->construirItemsExperimentales(
                $clasificacion ?? $this->clasificarMovimientos($movimientosCompletos, (string) $totales->total_neto, $totalEsperado),
                $debeTotalCents,
                $haberTotalCents,
                $netoTotalCents,
                $totalEsperado
            );
        }

        return $resultado;
    }

    /**
     * @param object $row
     * @return array<string, mixed>
     */
    private function movimientoPayload(object $row): array
    {
        return [
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
        ];
    }

    /**
     * @param list<array<string, mixed>> $movimientos
     * @return array<string, mixed>
     */
    private function clasificarMovimientos(array $movimientos, string $netoCrudo, ?string $totalEsperado): array
    {
        $incluidos = [];
        $excluidos = [];
        $debeIncluido = 0;
        $haberIncluido = 0;
        $debeExcluido = 0;
        $haberExcluido = 0;
        $movimientosClasificados = [];

        foreach ($movimientos as $movimiento) {
            $clasificacion = $this->clasificarMovimiento($movimiento);
            $movimientoClasificado = [
                ...$movimiento,
                'clasificacion' => $clasificacion['clasificacion'],
                'liquidable' => $clasificacion['liquidable'],
                'motivo_clasificacion' => $clasificacion['motivo'],
                'regla_aplicada' => $clasificacion['regla'],
            ];

            $debe = $this->decimalToCents((string) $movimiento['debe']);
            $haber = $this->decimalToCents((string) $movimiento['haber']);

            if ($clasificacion['liquidable']) {
                $debeIncluido += $debe;
                $haberIncluido += $haber;
                $incluidos[] = $movimientoClasificado;
            } else {
                $debeExcluido += $debe;
                $haberExcluido += $haber;
                $excluidos[] = $movimientoClasificado;
            }

            $movimientosClasificados[] = $movimientoClasificado;
        }

        $totalLiquidable = $haberIncluido - $debeIncluido;
        $totalEsperadoCents = $totalEsperado === null ? null : $this->decimalToCents($totalEsperado);

        return [
            'version_regla' => 'EXPERIMENTAL_GIMB23_v1',
            'clasificaciones_disponibles' => [
                'LIQUIDABLE',
                'NO_LIQUIDABLE',
                'REFERENCIA_LIQUIDACION_ANTERIOR',
                'REGLA_PENDIENTE_COBOL',
            ],
            'total_crudo' => $this->centsToDecimal($this->decimalToCents($netoCrudo)),
            'debe_liquidable' => $this->centsToDecimal($debeIncluido),
            'haber_liquidable' => $this->centsToDecimal($haberIncluido),
            'total_liquidable' => $this->centsToDecimal($totalLiquidable),
            'debe_excluido_no_liquidable' => $this->centsToDecimal($debeExcluido),
            'haber_excluido_no_liquidable' => $this->centsToDecimal($haberExcluido),
            'total_excluido_no_liquidable' => $this->centsToDecimal($debeExcluido - $haberExcluido),
            'total_historico_esperado' => $totalEsperado,
            'diferencia_con_historico' => $totalEsperadoCents === null
                ? null
                : $this->centsToDecimal($totalLiquidable - $totalEsperadoCents),
            'cantidad_incluidos' => count($incluidos),
            'cantidad_excluidos' => count($excluidos),
            'movimientos_clasificados' => $movimientosClasificados,
            'movimientos_incluidos' => $incluidos,
            'movimientos_excluidos' => $excluidos,
        ];
    }

    /**
     * @param array<string, mixed> $movimiento
     * @return array{clasificacion: string, liquidable: bool, motivo: string, regla: string}
     */
    private function clasificarMovimiento(array $movimiento): array
    {
        $codigo = trim((string) $movimiento['codigo_concepto']);
        $descripcion = $this->normalizarTexto((string) $movimiento['descripcion']);

        if ($codigo === '29' || str_contains($descripcion, 'PAGO LIQ')) {
            return [
                'clasificacion' => 'REFERENCIA_LIQUIDACION_ANTERIOR',
                'liquidable' => false,
                'motivo' => 'Movimiento de pago/cancelacion de liquidacion anterior; se conserva como referencia y no descuenta el total corriente.',
                'regla' => 'EXPERIMENTAL_GIMB23: codigo 29 o detalle con Pago Liq.',
            ];
        }

        return [
            'clasificacion' => 'LIQUIDABLE',
            'liquidable' => true,
            'motivo' => 'Movimiento incluido en el total corriente por regla experimental inicial.',
            'regla' => 'EXPERIMENTAL_GIMB23: inclusion por defecto hasta modelar GIMB23 completo.',
        ];
    }

    /**
     * @param array<string, mixed> $clasificacion
     * @return array<string, mixed>
     */
    private function construirItemsExperimentales(
        array $clasificacion,
        int $debeCrudo,
        int $haberCrudo,
        int $netoCrudo,
        ?string $totalEsperado
    ): array {
        $movimientosIncluidos = $clasificacion['movimientos_incluidos'];
        $items = [];
        $orden = 1;

        $alquileres = $this->filtrarMovimientosPorCodigo($movimientosIncluidos, '01');
        foreach ($alquileres as $movimiento) {
            $items[] = $this->itemIndividual(
                $orden++,
                $movimiento,
                'ALQUILER',
                'EXPERIMENTAL_GIMB23_ITEM_BUILDER: codigo 01 se imprime individualmente.'
            );
        }

        $litoralGasDetalle = $this->filtrarMovimientosPorCodigo($movimientosIncluidos, '11');
        usort($litoralGasDetalle, fn (array $a, array $b): int => $this->ordenLitoralGasDetalle($a) <=> $this->ordenLitoralGasDetalle($b));
        foreach ($litoralGasDetalle as $movimiento) {
            $items[] = $this->itemIndividual(
                $orden++,
                $movimiento,
                'SERVICIO_DETALLE',
                'EXPERIMENTAL_GIMB23_ITEM_BUILDER: codigo 11 se imprime individualmente cuando coincide con Litoral Gas.'
            );
        }

        $comisionAlquileres = $this->filtrarMovimientosPorCodigo($movimientosIncluidos, '21');
        if ($comisionAlquileres !== []) {
            $items[] = $this->itemAgrupadoNetoIva(
                $orden++,
                $comisionAlquileres,
                '21',
                '07,5% Comision p/Admin.Alquileres',
                'COMISION_ADMINISTRACION',
                'EXPERIMENTAL_GIMB23_ITEM_BUILDER: codigo 21 se agrupa neto y el IVA se informa en item separado.'
            );
        }

        $comisionImpuestos = $this->filtrarMovimientosPorCodigo($movimientosIncluidos, '22');
        if ($comisionImpuestos !== []) {
            $items[] = $this->itemAgrupadoNetoIva(
                $orden++,
                $comisionImpuestos,
                '22',
                'Com.s/Imp,ExpyServ.',
                'COMISION_IMPUESTOS_SERVICIOS',
                'EXPERIMENTAL_GIMB23_ITEM_BUILDER: codigo 22 se agrupa neto y el IVA se informa en item separado.'
            );
        }

        $movimientosConIva = array_merge($comisionAlquileres, $comisionImpuestos);
        $ivaComisiones = $this->sumarCampoCents($movimientosConIva, 'iva');
        if ($ivaComisiones !== 0) {
            $items[] = [
                'orden_experimental' => $orden++,
                'codigo_origen' => 'IVA_21_22',
                'codigo_item' => 'IVA_COMISIONES',
                'descripcion' => '21,0% IVA sobre comisiones',
                'debe' => $this->centsToDecimal($ivaComisiones),
                'haber' => '0.00',
                'total' => $this->centsToDecimal($ivaComisiones),
                'clasificacion' => 'ITEM_AGRUPADO',
                'movimientos_origen_ids' => $this->movimientoIds($movimientosConIva),
                'numeros_movimiento_origen' => $this->movimientoNumeros($movimientosConIva),
                'cantidad_movimientos_origen' => count($movimientosConIva),
                'regla_aplicada' => 'EXPERIMENTAL_GIMB23_ITEM_BUILDER: IVA de codigos 21 y 22 agrupado en linea separada.',
                'advertencias' => ['IVA calculado desde el campo iva importado en web_movimientos_cuenta.'],
            ];
        }

        $otrosIndividuales = array_values(array_filter(
            $movimientosIncluidos,
            fn (array $movimiento): bool => in_array(trim((string) $movimiento['codigo_concepto']), ['32', '43'], true)
        ));
        usort($otrosIndividuales, fn (array $a, array $b): int => $this->ordenOtrosItems($a) <=> $this->ordenOtrosItems($b));

        foreach ($otrosIndividuales as $movimiento) {
            $items[] = $this->itemIndividual(
                $orden++,
                $movimiento,
                trim((string) $movimiento['codigo_concepto']) === '32' ? 'GASTO_EXPENSA_SERVICIO' : 'BONIFICACION',
                'EXPERIMENTAL_GIMB23_ITEM_BUILDER: codigos 32 y 43 se imprimen individualmente para el caso piloto.'
            );
        }

        $debeItems = 0;
        $haberItems = 0;
        foreach ($items as $item) {
            $debeItems += $this->decimalToCents((string) $item['debe']);
            $haberItems += $this->decimalToCents((string) $item['haber']);
        }

        $totalItems = $haberItems - $debeItems;
        $totalEsperadoCents = $totalEsperado === null ? null : $this->decimalToCents($totalEsperado);
        $movimientosAgrupados = count($comisionAlquileres) + count($comisionImpuestos);

        return [
            'version_regla' => 'EXPERIMENTAL_GIMB23_ITEM_BUILDER_v1',
            'total_historico_esperado' => $totalEsperado,
            'debe_crudo' => $this->centsToDecimal($debeCrudo),
            'haber_crudo' => $this->centsToDecimal($haberCrudo),
            'total_crudo' => $this->centsToDecimal($netoCrudo),
            'total_liquidable_desde_movimientos' => (string) $clasificacion['total_liquidable'],
            'debe_items' => $this->centsToDecimal($debeItems),
            'haber_items' => $this->centsToDecimal($haberItems),
            'total_items' => $this->centsToDecimal($totalItems),
            'diferencia_movimientos_vs_historico' => $clasificacion['diferencia_con_historico'],
            'diferencia_items_vs_historico' => $totalEsperadoCents === null
                ? null
                : $this->centsToDecimal($totalItems - $totalEsperadoCents),
            'cantidad_items_construidos' => count($items),
            'cantidad_movimientos_liquidables' => (int) $clasificacion['cantidad_incluidos'],
            'cantidad_movimientos_excluidos' => (int) $clasificacion['cantidad_excluidos'],
            'cantidad_movimientos_agrupados' => $movimientosAgrupados,
            'agrupaciones' => [
                [
                    'codigo_origen' => '21',
                    'descripcion' => '07,5% Comision p/Admin.Alquileres',
                    'cantidad_movimientos' => count($comisionAlquileres),
                    'debe_bruto' => $this->centsToDecimal($this->sumarCampoCents($comisionAlquileres, 'debe')),
                    'iva' => $this->centsToDecimal($this->sumarCampoCents($comisionAlquileres, 'iva')),
                    'debe_neto' => $this->centsToDecimal(
                        $this->sumarCampoCents($comisionAlquileres, 'debe') - $this->sumarCampoCents($comisionAlquileres, 'iva')
                    ),
                ],
                [
                    'codigo_origen' => '22',
                    'descripcion' => 'Com.s/Imp,ExpyServ.',
                    'cantidad_movimientos' => count($comisionImpuestos),
                    'debe_bruto' => $this->centsToDecimal($this->sumarCampoCents($comisionImpuestos, 'debe')),
                    'iva' => $this->centsToDecimal($this->sumarCampoCents($comisionImpuestos, 'iva')),
                    'debe_neto' => $this->centsToDecimal(
                        $this->sumarCampoCents($comisionImpuestos, 'debe') - $this->sumarCampoCents($comisionImpuestos, 'iva')
                    ),
                ],
                [
                    'codigo_origen' => 'IVA_21_22',
                    'descripcion' => 'IVA agrupado de comisiones',
                    'cantidad_movimientos' => count($movimientosConIva),
                    'debe' => $this->centsToDecimal($ivaComisiones),
                ],
            ],
            'items' => $items,
            'movimientos_excluidos' => $clasificacion['movimientos_excluidos'],
            'advertencias' => [
                'EXPERIMENTAL_GIMB23_ITEM_BUILDER: el orden, agrupacion y descripciones se validaron solo contra la cuenta 12020750010 periodo 202606.',
                'REGLA_PENDIENTE_COBOL: falta resolver relacion directa movimiento-inquilino-inmueble para todos los items.',
                'REGLA_PENDIENTE_COBOL: falta generalizar GIMB23/GIMB98 antes de PDF piloto.',
            ],
        ];
    }

    /**
     * @param list<array<string, mixed>> $movimientos
     * @return list<array<string, mixed>>
     */
    private function filtrarMovimientosPorCodigo(array $movimientos, string $codigo): array
    {
        return array_values(array_filter(
            $movimientos,
            fn (array $movimiento): bool => trim((string) $movimiento['codigo_concepto']) === $codigo
        ));
    }

    /**
     * @param array<string, mixed> $movimiento
     * @return array<string, mixed>
     */
    private function itemIndividual(int $orden, array $movimiento, string $codigoItem, string $regla): array
    {
        $debe = $this->decimalToCents((string) $movimiento['debe']);
        $haber = $this->decimalToCents((string) $movimiento['haber']);

        return [
            'orden_experimental' => $orden,
            'codigo_origen' => trim((string) $movimiento['codigo_concepto']),
            'codigo_item' => $codigoItem,
            'descripcion' => $this->descripcionItem($movimiento),
            'debe' => $this->centsToDecimal($debe),
            'haber' => $this->centsToDecimal($haber),
            'total' => $this->centsToDecimal($haber > 0 ? $haber : $debe),
            'clasificacion' => 'ITEM_COINCIDE',
            'movimientos_origen_ids' => [(int) $movimiento['id']],
            'numeros_movimiento_origen' => [(string) $movimiento['numero_movimiento']],
            'cantidad_movimientos_origen' => 1,
            'regla_aplicada' => $regla,
            'advertencias' => $movimiento['contrato_id'] === null
                ? ['REGLA_PENDIENTE_COBOL: movimiento sin contrato/inquilino/inmueble directo resuelto en web_movimientos_cuenta.']
                : [],
        ];
    }

    /**
     * @param list<array<string, mixed>> $movimientos
     * @return array<string, mixed>
     */
    private function itemAgrupadoNetoIva(
        int $orden,
        array $movimientos,
        string $codigoOrigen,
        string $descripcion,
        string $codigoItem,
        string $regla
    ): array {
        $debe = $this->sumarCampoCents($movimientos, 'debe');
        $iva = $this->sumarCampoCents($movimientos, 'iva');
        $neto = $debe - $iva;

        return [
            'orden_experimental' => $orden,
            'codigo_origen' => $codigoOrigen,
            'codigo_item' => $codigoItem,
            'descripcion' => $descripcion,
            'debe' => $this->centsToDecimal($neto),
            'haber' => '0.00',
            'total' => $this->centsToDecimal($neto),
            'clasificacion' => 'ITEM_AGRUPADO',
            'movimientos_origen_ids' => $this->movimientoIds($movimientos),
            'numeros_movimiento_origen' => $this->movimientoNumeros($movimientos),
            'cantidad_movimientos_origen' => count($movimientos),
            'regla_aplicada' => $regla,
            'advertencias' => ['El importe impreso usa neto sin IVA; el IVA se agrega en item agrupado separado.'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $movimientos
     */
    private function sumarCampoCents(array $movimientos, string $campo): int
    {
        $total = 0;
        foreach ($movimientos as $movimiento) {
            $total += $this->decimalToCents((string) ($movimiento[$campo] ?? '0.00'));
        }

        return $total;
    }

    /**
     * @param list<array<string, mixed>> $movimientos
     * @return list<int>
     */
    private function movimientoIds(array $movimientos): array
    {
        return array_map(fn (array $movimiento): int => (int) $movimiento['id'], $movimientos);
    }

    /**
     * @param list<array<string, mixed>> $movimientos
     * @return list<string>
     */
    private function movimientoNumeros(array $movimientos): array
    {
        return array_map(fn (array $movimiento): string => (string) $movimiento['numero_movimiento'], $movimientos);
    }

    /**
     * @param array<string, mixed> $movimiento
     */
    private function descripcionItem(array $movimiento): string
    {
        $descripcion = trim((string) $movimiento['descripcion']);
        if ($descripcion !== '') {
            return $descripcion;
        }

        $concepto = trim((string) $movimiento['concepto']);

        return $concepto !== '' ? $concepto : 'Movimiento '.$movimiento['numero_movimiento'];
    }

    /**
     * @param array<string, mixed> $movimiento
     */
    private function ordenLitoralGasDetalle(array $movimiento): int
    {
        $descripcion = $this->normalizarTexto((string) $movimiento['descripcion']);

        if (str_contains($descripcion, '2-2')) {
            return 1;
        }

        if (str_contains($descripcion, '1-2')) {
            return 2;
        }

        return 99;
    }

    /**
     * @param array<string, mixed> $movimiento
     */
    private function ordenOtrosItems(array $movimiento): int
    {
        $codigo = trim((string) $movimiento['codigo_concepto']);
        $descripcion = $this->normalizarTexto((string) $movimiento['descripcion']);

        if ($codigo === '32' && str_contains($descripcion, 'GASTOS BANCARIOS')) {
            return 1;
        }

        if ($codigo === '32' && str_contains($descripcion, 'LITORAL GAS')) {
            return 2;
        }

        if ($codigo === '32' && str_contains($descripcion, 'EXP.COMUNES')) {
            return 3;
        }

        if ($codigo === '43') {
            return 4;
        }

        return 99;
    }

    private function decimalToCents(string $value): int
    {
        $normalized = trim(str_replace(',', '.', $value));
        $negative = str_starts_with($normalized, '-');
        $normalized = ltrim($normalized, '+-');
        [$integer, $decimal] = array_pad(explode('.', $normalized, 2), 2, '0');
        $decimal = substr(str_pad($decimal, 2, '0'), 0, 2);
        $cents = ((int) $integer * 100) + (int) $decimal;

        return $negative ? -$cents : $cents;
    }

    private function centsToDecimal(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        $value = intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return $negative ? "-{$value}" : $value;
    }

    private function normalizarTexto(string $texto): string
    {
        $texto = strtr($texto, [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
        ]);

        return strtoupper($texto);
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
    private function advertencias(int $movimientosSinContrato, int $contratosRelacionados, bool $clasificarMovimientos = false): array
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

        if ($clasificarMovimientos) {
            $advertencias[] = 'EXPERIMENTAL_GIMB23: la clasificacion de movimientos solo excluye pagos de liquidaciones anteriores; no es regla definitiva.';
        }

        return $advertencias;
    }
}
