<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SimularFacturacionAgosto2026 extends Command
{
    protected $signature = 'gei:facturacion-simular-agosto-2026
                            {--salida=storage/app/private/facturacion_simulada_agosto_2026.csv}';

    protected $description =
        'Simula agosto 2026 con las reglas/configuración actual de GeI-Web, sin modificar la base ni llamar a ARCA';

    /*
     * Los lotes sólo se usan para reproducir los cortes operativos reales
     * de agosto y poder comparar contra KNG. La lógica de facturación es GeI.
     */
    private array $lotes = [
        ['lote' => 1246, 'pv' => 38, 'dominio' => 'INQUILINO',   'desde' => '2026-08-01', 'hasta' => '2026-08-04', 'fecha' => '2026-08-04'],
        ['lote' => 1247, 'pv' => 39, 'dominio' => 'INQUILINO',   'desde' => '2026-08-01', 'hasta' => '2026-08-04', 'fecha' => '2026-08-04'],
        ['lote' => 1248, 'pv' => 38, 'dominio' => 'INQUILINO',   'desde' => '2026-08-05', 'hasta' => '2026-08-19', 'fecha' => '2026-08-19'],
        ['lote' => 1249, 'pv' => 39, 'dominio' => 'INQUILINO',   'desde' => '2026-08-05', 'hasta' => '2026-08-19', 'fecha' => '2026-08-19'],
        ['lote' => 1250, 'pv' => 38, 'dominio' => 'PROPIETARIO', 'desde' => '2026-08-01', 'hasta' => '2026-08-19', 'fecha' => '2026-08-19'],
        ['lote' => 1251, 'pv' => 39, 'dominio' => 'PROPIETARIO', 'desde' => '2026-08-01', 'hasta' => '2026-08-19', 'fecha' => '2026-08-19'],
        ['lote' => 1252, 'pv' => 38, 'dominio' => 'INQUILINO',   'desde' => '2026-08-20', 'hasta' => '2026-08-31', 'fecha' => '2026-08-31'],
        ['lote' => 1253, 'pv' => 39, 'dominio' => 'INQUILINO',   'desde' => '2026-08-20', 'hasta' => '2026-08-31', 'fecha' => '2026-08-31'],
        ['lote' => 1254, 'pv' => 38, 'dominio' => 'PROPIETARIO', 'desde' => '2026-08-20', 'hasta' => '2026-08-31', 'fecha' => '2026-08-31'],
        ['lote' => 1255, 'pv' => 39, 'dominio' => 'PROPIETARIO', 'desde' => '2026-08-20', 'hasta' => '2026-08-31', 'fecha' => '2026-08-31'],
    ];

    private array $codigosPropietario = [
        13, 15, 16, 17,
        21, 22, 23, 24, 25, 27,
        31, 32, 33, 40, 41, 42, 43,
    ];

    private array $codigosInquilinoNoFacturables = [48, 90];

    private int $sinIdentidadFiscal = 0;
    private int $cuentasPorInmueble = 0;
    private int $beneficiariosInvalidos = 0;

    public function handle(): int
    {
        $salida = base_path((string) $this->option('salida'));
        $dir = dirname($salida);

        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fh = fopen($salida, 'w');
        if (! $fh) {
            $this->error("No se pudo crear {$salida}");
            return self::FAILURE;
        }

        fputcsv($fh, [
            'lote', 'pv', 'tipo', 'dominio', 'cuenta', 'cliente_id',
            'identificador_origen', 'fecha_desde', 'fecha_hasta',
            'fecha_comprobante', 'total', 'cantidad_movimientos',
            'agrupacion',
        ], ',', '"', '');

        $totalComprobantes = 0;
        $totalImporte = 0.0;
        $porTipoPv = [];

        foreach ($this->lotes as $config) {
            $resultado = $config['dominio'] === 'INQUILINO'
                ? $this->simularInquilinos($config)
                : $this->simularPropietarios($config);

            $importeLote = 0.0;

            foreach ($resultado as $c) {
                fputcsv($fh, [
                    $config['lote'],
                    $config['pv'],
                    $c['tipo'],
                    $config['dominio'],
                    $c['cuenta'],
                    $c['cliente_id'],
                    $c['identificador_origen'],
                    $config['desde'],
                    $config['hasta'],
                    $config['fecha'],
                    number_format($c['total'], 2, '.', ''),
                    $c['cantidad_movimientos'],
                    $c['agrupacion'],
                ], ',', '"', '');

                $importeLote += $c['total'];
                $totalImporte += $c['total'];
                $totalComprobantes++;

                $clave = $config['pv'].'-'.$c['tipo'];
                $porTipoPv[$clave] = ($porTipoPv[$clave] ?? 0) + 1;
            }

            $this->line(sprintf(
                'Lote %d PV %d %-11s: %d comprobantes | %.2f',
                $config['lote'],
                $config['pv'],
                $config['dominio'],
                count($resultado),
                round($importeLote, 2)
            ));
        }

        fclose($fh);

        $this->newLine();
        $this->line('=== SIMULACIÓN GEI-WEB AGOSTO 2026 ===');
        $this->line('Comprobantes : '.$totalComprobantes);
        $this->line('Total        : '.number_format(round($totalImporte, 2), 2, '.', ''));

        foreach ([38, 39] as $pv) {
            foreach ([1, 3, 6, 8] as $tipo) {
                $clave = $pv.'-'.$tipo;
                if (($porTipoPv[$clave] ?? 0) > 0) {
                    $this->line("PV {$pv} TIPO {$tipo}: ".$porTipoPv[$clave]);
                }
            }
        }

        if ($this->sinIdentidadFiscal > 0) {
            $this->warn("Comprobantes omitidos por identidad fiscal sin resolver: {$this->sinIdentidadFiscal}");
        }

        if ($this->beneficiariosInvalidos > 0) {
            $this->warn("Cuentas individuales omitidas por beneficiarios inválidos/incompletos: {$this->beneficiariosInvalidos}");
        }

        if ($this->cuentasPorInmueble > 0) {
            $this->warn(
                "Cuentas con agrupación POR_INMUEBLE tratadas como CONSOLIDADA en esta primera simulación: ".
                $this->cuentasPorInmueble
            );
        }

        $this->info("CSV: {$salida}");

        return self::SUCCESS;
    }

    private function simularInquilinos(array $config): array
    {
        $query = DB::table('cuentas_corrientes as cc')
            ->join('cuentas_corrientes_movimientos as m', 'm.cuenta_corriente_id', '=', 'cc.id')
            ->where('cc.dominio', 'INQUILINO')
            ->where('cc.facturable', true)
            ->whereBetween('m.fecha', [$config['desde'], $config['hasta']])
            ->select([
                'cc.id',
                'cc.cuenta',
                'cc.cliente_id',
                'cc.agrupacion_facturacion',
                'cc.tratamiento_fiscal',
            ])
            ->distinct();

        $query = $this->aplicarPuntoVenta($query, $config['pv']);

        $resultado = [];

        foreach ($query->orderBy('cc.cuenta')->get() as $cc) {
            $movimientos = DB::table('cuentas_corrientes_movimientos')
                ->where('cuenta_corriente_id', $cc->id)
                ->whereBetween('fecha', [$config['desde'], $config['hasta']])
                ->whereNotIn('codigo', $this->codigosInquilinoNoFacturables)
                ->orderBy('fecha')
                ->orderBy('id')
                ->get();

            if ($movimientos->isEmpty()) {
                continue;
            }

            $familia = $this->familiaFiscal(
                $cc->cliente_id,
                (string) ($cc->tratamiento_fiscal ?? 'AUTOMATICO')
            );

            if ($familia === null) {
                $this->sinIdentidadFiscal++;
                continue;
            }

            $agrupacion = (string) ($cc->agrupacion_facturacion ?? 'CONSOLIDADA');

            if ($agrupacion === 'POR_INMUEBLE') {
                // Falta resolver de forma explícita inmueble por movimiento.
                // No inventamos esa asociación en esta primera prueba.
                $this->cuentasPorInmueble++;
                $agrupacion = 'CONSOLIDADA';
            }

            if ($agrupacion === 'POR_MOVIMIENTO') {
                foreach ($movimientos as $m) {
                    $tipo = $this->tipoInquilino((int) $m->codigo, $familia);
                    if ($tipo === null) {
                        continue;
                    }

                    $resultado[] = $this->comprobante(
                        $tipo,
                        (string) $cc->cuenta,
                        $cc->cliente_id,
                        null,
                        round((float) $m->importe, 2, PHP_ROUND_HALF_UP),
                        1,
                        'POR_MOVIMIENTO'
                    );
                }

                continue;
            }

            $grupos = [];

            foreach ($movimientos as $m) {
                $tipo = $this->tipoInquilino((int) $m->codigo, $familia);
                if ($tipo === null) {
                    continue;
                }

                $grupos[$tipo][] = $m;
            }

            foreach ($grupos as $tipo => $movs) {
                $total = round(
                    array_sum(array_map(fn ($m) => (float) $m->importe, $movs)),
                    2,
                    PHP_ROUND_HALF_UP
                );

                $resultado[] = $this->comprobante(
                    (int) $tipo,
                    (string) $cc->cuenta,
                    $cc->cliente_id,
                    null,
                    $total,
                    count($movs),
                    'CONSOLIDADA'
                );
            }
        }

        return $resultado;
    }

    private function simularPropietarios(array $config): array
    {
        $emitirNc = (bool) DB::table('configuraciones_facturacion')
            ->orderByDesc('id_configuracion_facturacion')
            ->value('emitir_nc_locadores');

        $query = DB::table('cuentas_corrientes as cc')
            ->join('cuentas_corrientes_movimientos as m', 'm.cuenta_corriente_id', '=', 'cc.id')
            ->where('cc.dominio', 'PROPIETARIO')
            ->where('cc.facturable', true)
            ->whereBetween('m.fecha', [$config['desde'], $config['hasta']])
            ->whereIn('m.codigo', $this->codigosPropietario)
            ->select([
                'cc.id',
                'cc.cuenta',
                'cc.cliente_id',
                'cc.modalidad_facturacion',
                'cc.destinatario_facturacion',
                'cc.agrupacion_facturacion',
                'cc.tratamiento_fiscal',
            ])
            ->distinct();

        $query = $this->aplicarPuntoVenta($query, $config['pv']);

        $resultado = [];

        foreach ($query->orderBy('cc.cuenta')->get() as $cc) {
            $movimientos = DB::table('cuentas_corrientes_movimientos')
                ->where('cuenta_corriente_id', $cc->id)
                ->whereBetween('fecha', [$config['desde'], $config['hasta']])
                ->whereIn('codigo', $this->codigosPropietario)
                ->orderBy('fecha')
                ->orderBy('id')
                ->get()
                ->filter(fn ($m) => ((int) $m->codigo > 20) || $emitirNc)
                ->values();

            if ($movimientos->isEmpty()) {
                continue;
            }

            $esIndividual =
                (string) ($cc->modalidad_facturacion ?? 'NORMAL') === 'INDIVIDUAL'
                || (string) ($cc->destinatario_facturacion ?? 'PROPIETARIO') === 'COPROPIETARIOS';

            if ($esIndividual) {
                $beneficiarios = DB::table('cuentas_facturacion_beneficiarios')
                    ->where('cuenta_corriente_id', $cc->id)
                    ->where('activo', true)
                    ->orderBy('identificador_origen')
                    ->get();

                $suma = round((float) $beneficiarios->sum('porcentaje'), 6);
                if ($beneficiarios->isEmpty()
                    || abs($suma - 100.0) > 0.000001
                    || $beneficiarios->contains(fn ($b) => empty($b->cliente_id))) {
                    $this->beneficiariosInvalidos++;
                    continue;
                }

                $agrupacion = (string) ($cc->agrupacion_facturacion ?? 'CONSOLIDADA');

                if ($agrupacion === 'POR_INMUEBLE') {
                    $this->cuentasPorInmueble++;
                    $agrupacion = 'CONSOLIDADA';
                }

                if ($agrupacion === 'POR_MOVIMIENTO') {
                    foreach ($movimientos as $m) {
                        $esNc = (int) $m->codigo < 21;

                        foreach ($beneficiarios as $b) {
                            $familia = $this->familiaFiscal(
                                $b->cliente_id,
                                (string) ($cc->tratamiento_fiscal ?? 'AUTOMATICO')
                            );

                            if ($familia === null) {
                                $this->sinIdentidadFiscal++;
                                continue;
                            }

                            $tipo = $this->tipoPropietario($familia, $esNc);
                            $importe = round(
                                ((float) $m->importe) * ((float) $b->porcentaje) / 100,
                                2,
                                PHP_ROUND_HALF_UP
                            );

                            $resultado[] = $this->comprobante(
                                $tipo,
                                (string) $cc->cuenta,
                                $b->cliente_id,
                                (string) $b->identificador_origen,
                                $importe,
                                1,
                                'POR_MOVIMIENTO'
                            );
                        }
                    }

                    continue;
                }

                $grupos = [];

                foreach ($movimientos as $m) {
                    $esNc = (int) $m->codigo < 21;

                    foreach ($beneficiarios as $b) {
                        $familia = $this->familiaFiscal(
                            $b->cliente_id,
                            (string) ($cc->tratamiento_fiscal ?? 'AUTOMATICO')
                        );

                        if ($familia === null) {
                            $this->sinIdentidadFiscal++;
                            continue;
                        }

                        $tipo = $this->tipoPropietario($familia, $esNc);
                        $clave = $b->identificador_origen.'|'.$tipo;

                        $grupos[$clave]['tipo'] = $tipo;
                        $grupos[$clave]['cliente_id'] = $b->cliente_id;
                        $grupos[$clave]['identificador_origen'] = (string) $b->identificador_origen;
                        $grupos[$clave]['importes'][] = round(
                            ((float) $m->importe) * ((float) $b->porcentaje) / 100,
                            2,
                            PHP_ROUND_HALF_UP
                        );
                    }
                }

                foreach ($grupos as $g) {
                    $resultado[] = $this->comprobante(
                        $g['tipo'],
                        (string) $cc->cuenta,
                        $g['cliente_id'],
                        $g['identificador_origen'],
                        round(array_sum($g['importes']), 2, PHP_ROUND_HALF_UP),
                        count($g['importes']),
                        'CONSOLIDADA'
                    );
                }

                continue;
            }

            $familia = $this->familiaFiscal(
                $cc->cliente_id,
                (string) ($cc->tratamiento_fiscal ?? 'AUTOMATICO')
            );

            if ($familia === null) {
                $this->sinIdentidadFiscal++;
                continue;
            }

            $agrupacion = (string) ($cc->agrupacion_facturacion ?? 'CONSOLIDADA');

            if ($agrupacion === 'POR_INMUEBLE') {
                $this->cuentasPorInmueble++;
                $agrupacion = 'CONSOLIDADA';
            }

            if ($agrupacion === 'POR_MOVIMIENTO') {
                foreach ($movimientos as $m) {
                    $tipo = $this->tipoPropietario($familia, (int) $m->codigo < 21);

                    $resultado[] = $this->comprobante(
                        $tipo,
                        (string) $cc->cuenta,
                        $cc->cliente_id,
                        null,
                        round((float) $m->importe, 2, PHP_ROUND_HALF_UP),
                        1,
                        'POR_MOVIMIENTO'
                    );
                }

                continue;
            }

            $grupos = [];

            foreach ($movimientos as $m) {
                $tipo = $this->tipoPropietario($familia, (int) $m->codigo < 21);
                $grupos[$tipo][] = $m;
            }

            foreach ($grupos as $tipo => $movs) {
                $resultado[] = $this->comprobante(
                    (int) $tipo,
                    (string) $cc->cuenta,
                    $cc->cliente_id,
                    null,
                    round(
                        array_sum(array_map(fn ($m) => (float) $m->importe, $movs)),
                        2,
                        PHP_ROUND_HALF_UP
                    ),
                    count($movs),
                    'CONSOLIDADA'
                );
            }
        }

        return $resultado;
    }

    private function familiaFiscal(?int $clienteId, string $tratamiento): ?string
    {
        if ($tratamiento === 'FORZAR_A') {
            return 'A';
        }

        if ($tratamiento === 'FORZAR_B') {
            return 'B';
        }

        if ($tratamiento === 'SIN_CATEGORIZAR') {
            return null;
        }

        if (! $clienteId) {
            return null;
        }

        $valor = DB::table('clientes_origenes')
            ->where('cliente_id', $clienteId)
            ->orderByDesc('id')
            ->value(DB::raw("datos_origen->>'tipo_iva_origen'"));

        if ($valor === null || $valor === '') {
            return null;
        }

        $pFiscal = (int) $valor;

        /*
         * Regla ya identificada en KNG:
         * propietarios 1/5 => A; para inquilinos históricamente 1 => A.
         * En GeI usamos la condición fiscal disponible; 5 también se trata A.
         */
        return in_array($pFiscal, [1, 5], true) ? 'A' : 'B';
    }

    private function tipoInquilino(int $codigo, string $familia): ?int
    {
        if ($codigo < 50) {
            return $familia === 'A' ? 1 : 6;
        }

        if ($codigo > 50) {
            return $familia === 'A' ? 3 : 8;
        }

        return null;
    }

    private function tipoPropietario(string $familia, bool $esNc): int
    {
        if ($esNc) {
            return $familia === 'A' ? 3 : 8;
        }

        return $familia === 'A' ? 1 : 6;
    }

    private function comprobante(
        int $tipo,
        string $cuenta,
        ?int $clienteId,
        ?string $identificadorOrigen,
        float $total,
        int $cantidadMovimientos,
        string $agrupacion
    ): array {
        return [
            'tipo' => $tipo,
            'cuenta' => $cuenta,
            'cliente_id' => $clienteId,
            'identificador_origen' => $identificadorOrigen,
            'total' => round($total, 2, PHP_ROUND_HALF_UP),
            'cantidad_movimientos' => $cantidadMovimientos,
            'agrupacion' => $agrupacion,
        ];
    }

    private function aplicarPuntoVenta($query, int $pv, string $alias = 'cc')
    {
        if ($pv === 38) {
            return $query->whereRaw(
                "{$alias}.cuenta ~ '^[0-9]+$' AND {$alias}.cuenta::numeric < 20000000000"
            );
        }

        if ($pv === 39) {
            return $query->whereRaw(
                "{$alias}.cuenta ~ '^[0-9]+$' AND {$alias}.cuenta::numeric > 20000000000"
            );
        }

        throw new \RuntimeException("PV no soportado: {$pv}");
    }
}
