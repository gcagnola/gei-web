<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SimularFacturacionJulio2026 extends Command
{
    protected $signature = 'gei:facturacion-simular-julio-2026
                            {--salida=storage/app/private/facturacion_simulada_julio_2026.csv}
                            {--snapshot-inquilinos=storage/app/private/inquilinos_facturacion.csv}
                            {--snapshot-fiscal-julio=storage/app/private/inquilinos_fiscal_julio_2026_por_lote.csv}
                            {--snapshot-movimientos-julio=storage/app/private/cta_cte_facturacion_julio_2026.csv}
                            {--snapshot-movimientos-propietarios-julio=storage/app/private/cta_prop_facturacion_julio_2026.csv}';

    protected $description =
        'Simula facturación de julio 2026 sin modificar la base, reproduciendo reglas históricas KNG';

    private array $lotes = [
        ['lote' => 1236, 'pv' => 38, 'dominio' => 'INQUILINO',   'desde' => '2026-07-01', 'hasta' => '2026-07-03'],
        ['lote' => 1237, 'pv' => 39, 'dominio' => 'INQUILINO',   'desde' => '2026-07-01', 'hasta' => '2026-07-03'],
        ['lote' => 1238, 'pv' => 38, 'dominio' => 'INQUILINO',   'desde' => '2026-07-04', 'hasta' => '2026-07-20'],
        ['lote' => 1239, 'pv' => 39, 'dominio' => 'INQUILINO',   'desde' => '2026-07-04', 'hasta' => '2026-07-20'],
        ['lote' => 1240, 'pv' => 38, 'dominio' => 'PROPIETARIO', 'desde' => '2026-07-01', 'hasta' => '2026-07-20'],
        ['lote' => 1241, 'pv' => 39, 'dominio' => 'PROPIETARIO', 'desde' => '2026-07-01', 'hasta' => '2026-07-20'],
        ['lote' => 1242, 'pv' => 38, 'dominio' => 'INQUILINO',   'desde' => '2026-07-21', 'hasta' => '2026-07-31'],
        ['lote' => 1243, 'pv' => 39, 'dominio' => 'INQUILINO',   'desde' => '2026-07-21', 'hasta' => '2026-07-31'],
        ['lote' => 1244, 'pv' => 38, 'dominio' => 'PROPIETARIO', 'desde' => '2026-07-21', 'hasta' => '2026-07-31'],
        ['lote' => 1245, 'pv' => 39, 'dominio' => 'PROPIETARIO', 'desde' => '2026-07-21', 'hasta' => '2026-07-31'],
    ];

    private array $numeradores = [
        38 => [1 => 64919, 3 => 1785, 6 => 273763, 8 => 10535],
        39 => [1 => 12660, 3 => 309, 6 => 51597, 8 => 2347],
    ];

    private array $codigosPropietario = [
        13, 15, 16, 17,
        21, 22, 23, 24, 25, 27,
        31, 32, 33, 40, 41, 42, 43,
    ];

    private array $codigosInquilinoNoFacturables = [48, 90];

    private array $inquilinosKng = [];

    private array $fiscalJulioPorLote = [];

    private array $movimientosJulioKng = [];

    private int $movimientosJulioFacturados = 0;

    private int $movimientosJulioLoteCero = 0;

    private int $movimientosPgSinSnapshot = 0;

    private array $movimientosPropietariosJulioKng = [];

    private int $movimientosPropietariosJulioFacturados = 0;

    private int $movimientosPropietariosJulioLoteCero = 0;

    private int $movimientosPropietariosPgSinSnapshot = 0;

    public function handle(): int
    {
        if (!$this->cargarSnapshotInquilinos()) {
            return self::FAILURE;
        }

        if (!$this->cargarSnapshotFiscalJulio()) {
            return self::FAILURE;
        }

        if (!$this->cargarSnapshotMovimientosJulio()) {
            return self::FAILURE;
        }

        if (!$this->cargarSnapshotMovimientosPropietariosJulio()) {
            return self::FAILURE;
        }

        $salida = base_path($this->option('salida'));
        $dir = dirname($salida);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fh = fopen($salida, 'w');

        if (!$fh) {
            $this->error("No se pudo abrir para escritura: {$salida}");
            return self::FAILURE;
        }

        fputcsv($fh, [
            'lote','pv','tipo','numero','dominio','cuenta','cliente_id',
            'identificador_origen','fecha_desde','fecha_hasta','total',
            'cantidad_movimientos',
        ], ',', '"', '');

        $totales = [];

        foreach ($this->lotes as $config) {
            $resultado = $config['dominio'] === 'INQUILINO'
                ? $this->simularInquilinos($config)
                : $this->simularPropietarios($config);

            foreach ($resultado as $comprobante) {
                $tipo = $comprobante['tipo'];
                $pv = $config['pv'];
                $numero = $this->numeradores[$pv][$tipo]++;

                fputcsv($fh, [
                    $config['lote'],
                    $pv,
                    $tipo,
                    $numero,
                    $config['dominio'],
                    $comprobante['cuenta'],
                    $comprobante['cliente_id'],
                    $comprobante['identificador_origen'],
                    $config['desde'],
                    $config['hasta'],
                    number_format($comprobante['total'], 2, '.', ''),
                    $comprobante['cantidad_movimientos'],
                ], ',', '"', '');

                $clave = "{$pv}-{$tipo}";
                $totales[$clave] = ($totales[$clave] ?? 0) + 1;
            }

            $this->line(sprintf(
                'Lote %d PV %d %-11s: %d comprobantes',
                $config['lote'],
                $config['pv'],
                $config['dominio'],
                count($resultado)
            ));
        }

        fclose($fh);

        $this->newLine();

        $suma = 0;

        foreach ([38, 39] as $pv) {
            foreach ([1, 3, 6, 8] as $tipo) {
                $clave = "{$pv}-{$tipo}";
                $cantidad = $totales[$clave] ?? 0;

                if ($cantidad === 0) {
                    continue;
                }

                $this->line("PV {$pv} TIPO {$tipo}: {$cantidad}");
                $suma += $cantidad;
            }
        }

        $this->newLine();
        $this->info("TOTAL: {$suma}");
        $this->info("CSV: {$salida}");

        if ($this->movimientosPgSinSnapshot > 0) {
            $this->warn(
                'Movimientos PG de julio sin equivalencia exacta en snapshot KNG: ' .
                $this->movimientosPgSinSnapshot
            );
        }

        return self::SUCCESS;
    }

    private function cargarSnapshotInquilinos(): bool
    {
        $archivo = base_path($this->option('snapshot-inquilinos'));

        if (!is_file($archivo)) {
            $this->error("No existe snapshot de inquilinos: {$archivo}");
            return false;
        }

        $fh = fopen($archivo, 'r');

        if (!$fh) {
            $this->error("No se pudo abrir snapshot: {$archivo}");
            return false;
        }

        $header = fgetcsv($fh, null, ',', '"', '');

        if (!$header) {
            fclose($fh);
            $this->error('El snapshot de inquilinos no tiene encabezado.');
            return false;
        }

        $header = array_map(
            fn ($v) => strtolower(trim((string) $v)),
            $header
        );

        while (($fila = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if (count($fila) !== count($header)) {
                continue;
            }

            $r = array_combine($header, $fila);

            $cuenta = trim((string) ($r['cuenta'] ?? ''));

            if ($cuenta === '') {
                continue;
            }

            $pFiscalRaw = trim((string) ($r['p_fiscal'] ?? ''));
            $destinoRaw = trim((string) ($r['destino'] ?? ''));

            $this->inquilinosKng[$cuenta] = [
                'facturable' => $this->boolCsv($r['facturable'] ?? null),
                'p_fiscal' => $pFiscalRaw !== '' ? (int) $pFiscalRaw : null,
                'destino' => $destinoRaw !== '' ? (int) $destinoRaw : null,
            ];
        }

        fclose($fh);

        $this->line(
            'Snapshot INQUILINOS KNG cargado: ' .
            count($this->inquilinosKng) .
            ' cuentas'
        );

        return true;
    }

    private function cargarSnapshotFiscalJulio(): bool
    {
        $archivo = base_path($this->option('snapshot-fiscal-julio'));

        if (!is_file($archivo)) {
            $this->error("No existe snapshot fiscal histórico de julio: {$archivo}");
            return false;
        }

        $fh = fopen($archivo, 'r');

        if (!$fh) {
            $this->error("No se pudo abrir snapshot fiscal histórico: {$archivo}");
            return false;
        }

        $header = fgetcsv($fh, null, ',', '"', '');

        if (!$header) {
            fclose($fh);
            $this->error('El snapshot fiscal histórico no tiene encabezado.');
            return false;
        }

        $header = array_map(
            fn ($v) => strtolower(trim((string) $v)),
            $header
        );

        while (($fila = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if (count($fila) !== count($header)) {
                continue;
            }

            $r = array_combine($header, $fila);

            $cuenta = trim((string) ($r['cuenta'] ?? ''));
            $loteRaw = trim((string) ($r['lote'] ?? ''));
            $pFiscalRaw = trim((string) ($r['p_fiscal_regresion'] ?? ''));

            if ($cuenta === '' || $loteRaw === '' || $pFiscalRaw === '') {
                continue;
            }

            $lote = (int) $loteRaw;
            $pFiscal = (int) $pFiscalRaw;

            $this->fiscalJulioPorLote[$cuenta][$lote] = [
                'familia_fiscal' => trim((string) ($r['familia_fiscal'] ?? '')),
                'p_fiscal' => $pFiscal,
                'tipos_kng' => trim((string) ($r['tipos_kng'] ?? '')),
            ];
        }

        fclose($fh);

        $registros = 0;
        foreach ($this->fiscalJulioPorLote as $porLote) {
            $registros += count($porLote);
        }

        $this->line(
            'Snapshot fiscal histórico julio cargado: ' .
            $registros .
            ' combinaciones cuenta+lote'
        );

        return true;
    }


    private function cargarSnapshotMovimientosJulio(): bool
    {
        $archivo = base_path($this->option('snapshot-movimientos-julio'));

        if (!is_file($archivo)) {
            $this->error("No existe snapshot histórico CTA_CTE julio: {$archivo}");
            return false;
        }

        $fh = fopen($archivo, 'r');

        if (!$fh) {
            $this->error("No se pudo abrir snapshot histórico CTA_CTE: {$archivo}");
            return false;
        }

        $header = fgetcsv($fh, null, ',', '"', '');

        if (!$header) {
            fclose($fh);
            $this->error('El snapshot histórico CTA_CTE no tiene encabezado.');
            return false;
        }

        $header = array_map(
            fn ($v) => strtolower(trim((string) $v)),
            $header
        );

        $duplicados = 0;

        while (($fila = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if (count($fila) !== count($header)) {
                continue;
            }

            $r = array_combine($header, $fila);

            $cuenta = trim((string) ($r['cuenta'] ?? ''));
            $fecha = trim((string) ($r['fecha'] ?? ''));
            $codigoRaw = trim((string) ($r['codigo'] ?? ''));
            $numeroRaw = trim((string) ($r['numero'] ?? ''));
            $loteRaw = trim((string) ($r['lote'] ?? ''));

            if (
                $cuenta === '' ||
                $fecha === '' ||
                $codigoRaw === '' ||
                $numeroRaw === '' ||
                $loteRaw === ''
            ) {
                continue;
            }

            $codigo = (int) $codigoRaw;
            $numero = (int) $numeroRaw;
            $lote = (int) $loteRaw;
            $clave = $this->claveMovimientoHistorico(
                $cuenta,
                $fecha,
                $codigo,
                $numero
            );

            if (isset($this->movimientosJulioKng[$clave])) {
                $duplicados++;
            }

            $this->movimientosJulioKng[$clave] = [
                'lote' => $lote,
                'iva_tipo' => trim((string) ($r['iva_tipo'] ?? '')),
                'importe' => trim((string) ($r['importe'] ?? '')),
            ];

            if ($lote === 0) {
                $this->movimientosJulioLoteCero++;
            } else {
                $this->movimientosJulioFacturados++;
            }
        }

        fclose($fh);

        $this->line(
            'Snapshot CTA_CTE julio cargado: ' .
            count($this->movimientosJulioKng) .
            ' movimientos únicos (' .
            $this->movimientosJulioFacturados .
            ' facturados, ' .
            $this->movimientosJulioLoteCero .
            ' lote 0)'
        );

        if ($duplicados > 0) {
            $this->warn(
                "Snapshot CTA_CTE: {$duplicados} claves duplicadas; se usó la última fila."
            );
        }

        return true;
    }

    private function simularInquilinos(array $config): array
    {
        /*
         * En regresión histórica julio 2026, la pertenencia al lote NO se
         * infiere por fecha. Se toma del snapshot CTA_CTE de KNG.
         *
         * Consultamos todo julio para no perder movimientos cuya fecha esté
         * cerca del corte pero KNG haya asignado explícitamente a otro lote.
         */
        $query = DB::table('cuentas_corrientes as cc')
            ->join(
                'cuentas_corrientes_movimientos as m',
                'm.cuenta_corriente_id',
                '=',
                'cc.id'
            )
            ->where('cc.dominio', 'INQUILINO')
            ->whereBetween('m.fecha', [
                '2026-07-01',
                '2026-07-31',
            ])
            ->select([
                'cc.id',
                'cc.cuenta',
                'cc.cliente_id',
            ])
            ->distinct();

        $query = $this->aplicarPuntoVenta($query, $config['pv']);

        $cuentas = $query
            ->orderBy('cc.cuenta')
            ->get();

        $resultado = [];

        foreach ($cuentas as $cc) {
            $cuenta = trim((string) $cc->cuenta);
            $maestro = $this->inquilinosKng[$cuenta] ?? null;

            /*
             * REGRESIÓN JULIO 2026:
             * el FACTURABLE actual de INQUILINOS.DBF no es una fuente
             * histórica confiable. Si existe cuenta+lote en el snapshot
             * fiscal construido desde FACTURAS.DBF, KNG facturó esa cuenta
             * en ese lote y por lo tanto debe considerarse facturable aquí.
             */
            $fiscalHistorico =
                $this->fiscalJulioPorLote[$cuenta][$config['lote']]
                ?? null;

            if ($fiscalHistorico === null) {
                continue;
            }

            $pFiscal = $fiscalHistorico['p_fiscal'];

            if ($pFiscal === null || $pFiscal <= 0) {
                continue;
            }

            $movimientos = DB::table('cuentas_corrientes_movimientos')
                ->where('cuenta_corriente_id', $cc->id)
                ->whereBetween('fecha', [
                    '2026-07-01',
                    '2026-07-31',
                ])
                ->orderBy('fecha')
                ->orderBy('id')
                ->get();

            if ($movimientos->isEmpty()) {
                continue;
            }

            $grupos = [1 => [], 3 => [], 6 => [], 8 => []];

            foreach ($movimientos as $m) {
                $codigo = (int) $m->codigo;

                if (in_array(
                    $codigo,
                    $this->codigosInquilinoNoFacturables,
                    true
                )) {
                    continue;
                }

                $fecha = $this->normalizarFechaMovimiento($m->fecha);
                $numero = (int) ($m->numero ?? 0);
                $clave = $this->claveMovimientoHistorico(
                    $cuenta,
                    $fecha,
                    $codigo,
                    $numero
                );

                $historico = $this->movimientosJulioKng[$clave] ?? null;

                if ($historico === null) {
                    $this->movimientosPgSinSnapshot++;
                    continue;
                }

                /*
                 * lote=0 significa que el movimiento existía en CTA_CTE,
                 * pero KNG no lo incluyó en ninguna corrida de julio.
                 */
                if ((int) $historico['lote'] !== (int) $config['lote']) {
                    continue;
                }

                $tipo = $this->tipoMovimientoInquilino($codigo, $pFiscal);

                if ($tipo === null) {
                    continue;
                }

                $grupos[$tipo][] = $m;
            }

            foreach ([1, 3, 6, 8] as $tipo) {
                $movs = $grupos[$tipo];

                if (!$movs) {
                    continue;
                }

                $total = 0.0;

                foreach ($movs as $m) {
                    $total += (float) $m->importe;
                }

                $resultado[] = [
                    'tipo' => $tipo,
                    'cuenta' => $cuenta,
                    'cliente_id' => $cc->cliente_id,
                    'identificador_origen' => null,
                    'total' => $total,
                    'cantidad_movimientos' => count($movs),
                ];
            }
        }

        return $resultado;
    }

    private function claveMovimientoHistorico(
        string $cuenta,
        string $fecha,
        int $codigo,
        int $numero
    ): string {
        return implode('|', [
            trim($cuenta),
            trim($fecha),
            $codigo,
            $numero,
        ]);
    }

    private function normalizarFechaMovimiento(mixed $fecha): string
    {
        if ($fecha instanceof \DateTimeInterface) {
            return $fecha->format('Y-m-d');
        }

        $valor = trim((string) $fecha);

        if ($valor === '') {
            return '';
        }

        return substr($valor, 0, 10);
    }

    private function tipoMovimientoInquilino(
        int $codigo,
        int $pFiscal
    ): ?int {
        if ($codigo < 50) {
            return $pFiscal === 1 ? 1 : 6;
        }

        if ($codigo > 50) {
            return $pFiscal === 1 ? 3 : 8;
        }

        return null;
    }

    private function cargarSnapshotMovimientosPropietariosJulio(): bool
    {
        $path = base_path(
            $this->option('snapshot-movimientos-propietarios-julio')
        );

        if (!is_file($path)) {
            $this->error(
                "No existe snapshot CTA_PROP julio: {$path}"
            );

            return false;
        }

        $fh = fopen($path, 'r');

        if (!$fh) {
            $this->error(
                "No se pudo abrir snapshot CTA_PROP julio: {$path}"
            );

            return false;
        }

        $header = fgetcsv($fh, null, ',', '"', '');

        if ($header === false) {
            fclose($fh);
            $this->error(
                "Snapshot CTA_PROP julio vacío: {$path}"
            );

            return false;
        }

        $map = [];

        foreach ($header as $i => $campo) {
            $map[trim((string) $campo)] = $i;
        }

        foreach ([
            'id_cuenta',
            'fecha',
            'codigo',
            'numero',
            'lote',
        ] as $campo) {
            if (!array_key_exists($campo, $map)) {
                fclose($fh);

                $this->error(
                    "Snapshot CTA_PROP julio sin columna: {$campo}"
                );

                return false;
            }
        }

        while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $cuenta = trim(
                (string) ($row[$map['id_cuenta']] ?? '')
            );

            $fecha = trim(
                (string) ($row[$map['fecha']] ?? '')
            );

            $codigo = (int) (
                $row[$map['codigo']] ?? 0
            );

            $numero = (int) (
                $row[$map['numero']] ?? 0
            );

            $lote = (int) (
                $row[$map['lote']] ?? 0
            );

            if ($cuenta === '' || $fecha === '') {
                continue;
            }

            $clave = $this->claveMovimientoPropietarioJulio(
                $cuenta,
                $fecha,
                $codigo,
                $numero
            );

            if (isset(
                $this->movimientosPropietariosJulioKng[$clave]
            )) {
                fclose($fh);

                $this->error(
                    "Clave duplicada CTA_PROP julio: {$clave}"
                );

                return false;
            }

            $this->movimientosPropietariosJulioKng[$clave] = $lote;

            if (in_array(
                $lote,
                [1240, 1241, 1244, 1245],
                true
            )) {
                $this->movimientosPropietariosJulioFacturados++;
            } elseif ($lote === 0) {
                $this->movimientosPropietariosJulioLoteCero++;
            }
        }

        fclose($fh);

        $this->line(sprintf(
            'Snapshot CTA_PROP julio cargado: %d movimientos únicos (%d facturados, %d lote 0)',
            count($this->movimientosPropietariosJulioKng),
            $this->movimientosPropietariosJulioFacturados,
            $this->movimientosPropietariosJulioLoteCero
        ));

        return true;
    }

    private function movimientoPropietarioCorrespondeAlLote(
        string $cuenta,
        object $movimiento,
        int $lote
    ): bool {
        $fecha = substr(
            (string) ($movimiento->fecha ?? ''),
            0,
            10
        );

        $clave = $this->claveMovimientoPropietarioJulio(
            $cuenta,
            $fecha,
            (int) $movimiento->codigo,
            (int) $movimiento->numero
        );

        if (!array_key_exists(
            $clave,
            $this->movimientosPropietariosJulioKng
        )) {
            $this->movimientosPropietariosPgSinSnapshot++;

            return false;
        }

        return
            $this->movimientosPropietariosJulioKng[$clave]
            === $lote;
    }

    private function claveMovimientoPropietarioJulio(
        string $cuenta,
        string $fecha,
        int $codigo,
        int $numero
    ): string {
        return implode('|', [
            trim($cuenta),
            $fecha,
            $codigo,
            $numero,
        ]);
    }

    private function simularPropietarios(array $config): array
    {
        $query = DB::table('cuentas_corrientes as cc')
            ->join(
                'cuentas_corrientes_movimientos as m',
                'm.cuenta_corriente_id',
                '=',
                'cc.id'
            )
            ->where('cc.dominio', 'PROPIETARIO')
            ->where('cc.facturable', true)
            ->whereBetween('m.fecha', [
                $config['desde'],
                $config['hasta'],
            ])
            ->whereIn('m.codigo', $this->codigosPropietario)
            ->select([
                'cc.id',
                'cc.cuenta',
                'cc.cliente_id',
                'cc.modalidad_facturacion',
            ])
            ->distinct();

        $query = $this->aplicarPuntoVenta($query, $config['pv']);

        $cuentas = $query
            ->orderBy('cc.cuenta')
            ->get();

        $resultado = [];

        foreach ($cuentas as $cc) {
            $movimientos = DB::table('cuentas_corrientes_movimientos')
                ->where('cuenta_corriente_id', $cc->id)
                ->whereBetween('fecha', [
                    $config['desde'],
                    $config['hasta'],
                ])
                ->whereIn('codigo', $this->codigosPropietario)
                ->orderBy('fecha')
                ->orderBy('id')
                ->get();

            /*
             * CTA_PROP histórico:
             *
             * - propietarios normales: el LOTE del movimiento madre
             *   identifica exactamente en qué corrida KNG lo facturó.
             *
             * - FACT_IND / INDIVIDUAL: KNG elimina el movimiento madre
             *   del cursor movp y genera en memoria movimientos derivados
             *   para los copropietarios. Por eso el CTA_PROP original
             *   puede quedar LOTE=0 aunque haya sido facturado.
             *
             * Para FACT_IND no corresponde filtrar por LOTE histórico.
             */
            if ($cc->modalidad_facturacion !== 'INDIVIDUAL') {
                $movimientos = $movimientos
                    ->filter(
                        fn ($m) => $this->movimientoPropietarioCorrespondeAlLote(
                            (string) $cc->cuenta,
                            $m,
                            (int) $config['lote']
                        )
                    )
                    ->values();
            }

            if ($movimientos->isEmpty()) {
                continue;
            }

            $facturas = $movimientos->filter(
                fn ($m) => (int) $m->codigo > 20
            );

            $notasCredito = $movimientos->filter(
                fn ($m) => (int) $m->codigo < 21
            );

            foreach ([
                'FACTURA' => $facturas,
                'NC' => $notasCredito,
            ] as $familia => $movs) {
                if ($movs->isEmpty()) {
                    continue;
                }

                if ($familia === 'NC') {
                    continue;
                }

                if ($cc->modalidad_facturacion === 'INDIVIDUAL') {
                    $beneficiarios = DB::table(
                        'cuentas_facturacion_beneficiarios'
                    )
                        ->where('cuenta_corriente_id', $cc->id)
                        ->where('activo', true)
                        ->orderBy('identificador_origen')
                        ->get();

                    /*
                     * Emulación de FACT_IND según fact_prop.sc2.
                     *
                     * KNG hace:
                     *   1) replica cada movimiento por COPROP;
                     *   2) guarda el importe en N(11,2), por lo que redondea
                     *      cada participación a 2 decimales;
                     *   3) calcula ORDEN N(17) como
                     *          id_prop * 1000000 + id_cliente
                     *      usando la precisión numérica de VFP;
                     *   4) ORDER BY orden;
                     *   5) para cada tipo fiscal emite un comprobante por cada
                     *      BLOQUE CONTIGUO de id_cuenta (DO WHILE cuenta=id_cuenta).
                     *
                     * Esta reproducción es exclusivamente para la regresión KNG.
                     * El motor productivo no debe heredar esta clave numérica.
                     */
                    $replicas = [];
                    $secuencia = 0;

                    foreach ($movs as $mov) {
                        foreach ($beneficiarios as $b) {
                            $tipo = $this->tipoPropietarioIndividual(
                                (string) $b->identificador_origen,
                                $b->cliente_id,
                                false
                            );

                            if ($tipo === null) {
                                continue;
                            }

                            $porcentaje = (float) $b->porcentaje;

                            // nuevos.importe es N(11,2) en fact_prop.sc2.
                            // KNG/VFP redondea cada importe distribuido antes
                            // de incorporarlo al cursor y luego sumar la factura.
                            $importeDistribuido = round(
                                ((float) $mov->importe) * $porcentaje / 100,
                                2,
                                PHP_ROUND_HALF_UP
                            );

                            // VFP limita las operaciones Numeric a la precisión
                            // de double (16 dígitos aprox.). PHP float reproduce
                            // esa pérdida de precisión para este valor de 17 dígitos.
                            $ordenKng = ((float) $cc->cuenta * 1000000.0)
                                + (float) $b->identificador_origen;

                            $replicas[] = [
                                'orden_kng' => $ordenKng,
                                'secuencia' => $secuencia++,
                                'tipo' => $tipo,
                                'cuenta' => $cc->cuenta,
                                'cliente_id' => $b->cliente_id,
                                'identificador_origen' => (string) $b->identificador_origen,
                                'importe' => $importeDistribuido,
                            ];
                        }
                    }

                    // ORDER BY orden. Para empates conservamos el orden de
                    // inserción del SELECT cambiar LEFT JOIN coprop.
                    usort(
                        $replicas,
                        static function (array $a, array $b): int {
                            if ($a['orden_kng'] < $b['orden_kng']) {
                                return -1;
                            }
                            if ($a['orden_kng'] > $b['orden_kng']) {
                                return 1;
                            }

                            return $a['secuencia'] <=> $b['secuencia'];
                        }
                    );

                    // fact_prop.sc2 crea un cursor por iva_tipo y dentro de él
                    // corta el comprobante solamente cuando cambia id_cuenta.
                    foreach ([1, 6] as $tipoBuscado) {
                        $filtradas = array_values(array_filter(
                            $replicas,
                            static fn (array $r): bool =>
                                (int) $r['tipo'] === $tipoBuscado
                        ));

                        $i = 0;
                        $cantidad = count($filtradas);

                        while ($i < $cantidad) {
                            $primera = $filtradas[$i];
                            $beneficiario = $primera['identificador_origen'];
                            $total = 0.0;
                            $items = 0;

                            while (
                                $i < $cantidad
                                && $filtradas[$i]['identificador_origen'] === $beneficiario
                            ) {
                                $total += (float) $filtradas[$i]['importe'];
                                $items++;
                                $i++;
                            }

                            $resultado[] = [
                                'tipo' => $tipoBuscado,
                                'cuenta' => $primera['cuenta'],
                                'cliente_id' => $primera['cliente_id'],
                                'identificador_origen' => $beneficiario,
                                'total' => round($total, 2),
                                'cantidad_movimientos' => $items,
                            ];
                        }
                    }

                    continue;
                }

                $tipo = $this->tipoPropietario(
                    $cc->cliente_id,
                    false
                );

                if ($tipo === null) {
                    continue;
                }

                $resultado[] = [
                    'tipo' => $tipo,
                    'cuenta' => $cc->cuenta,
                    'cliente_id' => $cc->cliente_id,
                    'identificador_origen' => null,
                    'total' => (float) $movs->sum('importe'),
                    'cantidad_movimientos' => $movs->count(),
                ];
            }
        }

        return $resultado;
    }

    private function tipoPropietarioIndividual(
        string $identificadorOrigen,
        ?int $clienteId,
        bool $esNc
    ): ?int {
        /*
         * Snapshot histórico CLIENTES.DBF usado únicamente por esta
         * regresión de julio 2026. No es lógica operativa futura.
         */
        $pFiscalKng = [
            '1009' => 1,
            '1011' => 1,
            '1083' => 1,
            '1084' => 1,
            '1248' => 3,
            '2243' => 5,
            '2246' => 5,
            '2247' => 5,
        ];

        $idKng = trim($identificadorOrigen);
        $pFiscal = $pFiscalKng[$idKng] ?? null;

        if ($pFiscal === null && $clienteId) {
            $pFiscal = $this->tipoIvaOrigen($clienteId);
        }

        if ($pFiscal === null) {
            return null;
        }

        $esA = in_array($pFiscal, [1, 5], true);

        if ($esNc) {
            return $esA ? 3 : 8;
        }

        return $esA ? 1 : 6;
    }

    private function tipoPropietario(
        ?int $clienteId,
        bool $esNc
    ): ?int {
        if (!$clienteId) {
            return $esNc ? 8 : 6;
        }

        $pFiscal = $this->tipoIvaOrigen($clienteId);

        if ($pFiscal === null) {
            return null;
        }

        $esA = in_array($pFiscal, [1, 5], true);

        if ($esNc) {
            return $esA ? 3 : 8;
        }

        return $esA ? 1 : 6;
    }

    private function tipoIvaOrigen(int $clienteId): ?int
    {
        $valor = DB::table('clientes_origenes')
            ->where('cliente_id', $clienteId)
            ->orderByDesc('id')
            ->value(
                DB::raw("datos_origen->>'tipo_iva_origen'")
            );

        if ($valor === null || $valor === '') {
            return null;
        }

        return (int) $valor;
    }

    private function aplicarPuntoVenta(
        $query,
        int $pv,
        string $alias = 'cc'
    ) {
        if ($pv === 38) {
            return $query->whereRaw(
                "{$alias}.cuenta ~ '^[0-9]+$' " .
                "AND {$alias}.cuenta::numeric < 20000000000"
            );
        }

        if ($pv === 39) {
            return $query->whereRaw(
                "{$alias}.cuenta ~ '^[0-9]+$' " .
                "AND {$alias}.cuenta::numeric > 20000000000"
            );
        }

        throw new \RuntimeException(
            "Punto de venta no soportado en simulación: {$pv}"
        );
    }

    private function boolCsv(mixed $valor): ?bool
    {
        if ($valor === null) {
            return null;
        }

        $v = strtolower(trim((string) $valor));

        if (in_array(
            $v,
            ['true', 't', '1', 'yes', 'si', 'sí'],
            true
        )) {
            return true;
        }

        if (in_array(
            $v,
            ['false', 'f', '0', 'no'],
            true
        )) {
            return false;
        }

        return null;
    }
}
