<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ImportarFacturacionHistorica extends Command
{
    protected $signature = 'gei:facturacion-importar-historico
                            {--periodo=202607}
                            {--facturas=storage/app/private/facturas_kng_julio_2026.csv}
                            {--items=storage/app/private/item_factura_julio_2026.csv}
                            {--aplicar : Inserta los datos. Sin esta opción sólo analiza}
                            {--reemplazar : Elimina una importación MIGRADO_KNG previa del período antes de insertar}';

    protected $description =
        'Importa literalmente la facturación histórica de KNG a las tablas operativas de GeI-Web';

    /**
     * Julio 2026: lotes reales de KNG ya auditados.
     * Estos datos NO gobiernan la facturación futura.
     */
    private array $lotesJulio2026 = [
        1236 => ['pv' => 38, 'dominio' => 'INQUILINO',   'desde' => '2026-07-01', 'hasta' => '2026-07-03'],
        1237 => ['pv' => 39, 'dominio' => 'INQUILINO',   'desde' => '2026-07-01', 'hasta' => '2026-07-03'],
        1238 => ['pv' => 38, 'dominio' => 'INQUILINO',   'desde' => '2026-07-04', 'hasta' => '2026-07-20'],
        1239 => ['pv' => 39, 'dominio' => 'INQUILINO',   'desde' => '2026-07-04', 'hasta' => '2026-07-20'],
        1240 => ['pv' => 38, 'dominio' => 'PROPIETARIO', 'desde' => '2026-07-01', 'hasta' => '2026-07-20'],
        1241 => ['pv' => 39, 'dominio' => 'PROPIETARIO', 'desde' => '2026-07-01', 'hasta' => '2026-07-20'],
        1242 => ['pv' => 38, 'dominio' => 'INQUILINO',   'desde' => '2026-07-21', 'hasta' => '2026-07-31'],
        1243 => ['pv' => 39, 'dominio' => 'INQUILINO',   'desde' => '2026-07-21', 'hasta' => '2026-07-31'],
        1244 => ['pv' => 38, 'dominio' => 'PROPIETARIO', 'desde' => '2026-07-21', 'hasta' => '2026-07-31'],
        1245 => ['pv' => 39, 'dominio' => 'PROPIETARIO', 'desde' => '2026-07-21', 'hasta' => '2026-07-31'],
    ];

    public function handle(): int
    {
        $periodo = trim((string) $this->option('periodo'));

        if ($periodo !== '202607') {
            $this->error(
                'Este importador histórico está validado únicamente para 202607. ' .
                'No se reutiliza automáticamente para otros períodos.'
            );
            return self::FAILURE;
        }

        $facturasPath = base_path((string) $this->option('facturas'));
        $itemsPath = base_path((string) $this->option('items'));

        if (! is_file($facturasPath)) {
            $this->error("No existe: {$facturasPath}");
            return self::FAILURE;
        }

        if (! is_file($itemsPath)) {
            $this->error("No existe: {$itemsPath}");
            return self::FAILURE;
        }

        try {
            $facturas = $this->leerFacturas($facturasPath);
            $items = $this->leerItemsNecesarios($itemsPath, $facturas);
            $analisis = $this->analizar($facturas, $items);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->mostrarAnalisis($analisis);

        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->warn('SIMULACIÓN: no se modificó la base.');
            $this->line(
                'Si el resumen es correcto, repetir agregando --aplicar.'
            );
            return self::SUCCESS;
        }

        if ($analisis['facturas_sin_items'] > 0 ||
            $analisis['facturas_items_distintos'] > 0 ||
            $analisis['lotes_desconocidos'] > 0 ||
            $analisis['puntos_venta_faltantes'] > 0) {
            $this->error(
                'No se aplica: existen inconsistencias estructurales en la fuente.'
            );
            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($periodo, $facturas, $items): void {
                if ($this->option('reemplazar')) {
                    $ids = DB::table('facturaciones')
                        ->where('periodo', $periodo)
                        ->where('origen', 'MIGRADO_KNG')
                        ->pluck('id_facturacion');

                    if ($ids->isNotEmpty()) {
                        DB::table('facturaciones')
                            ->whereIn('id_facturacion', $ids)
                            ->delete();
                    }
                } elseif (
                    DB::table('facturaciones')
                        ->where('periodo', $periodo)
                        ->where('origen', 'MIGRADO_KNG')
                        ->exists()
                ) {
                    throw new RuntimeException(
                        'Ya existe una importación MIGRADO_KNG para 202607. ' .
                        'Use --reemplazar sólo si realmente desea reconstruirla.'
                    );
                }

                $this->importar($periodo, $facturas, $items);
            });
        } catch (\Throwable $e) {
            $this->error('Importación revertida: ' . $e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Julio 2026 fue importado como histórico MIGRADO_KNG.');
        $this->info('No se consumió numeración ni se realizó ninguna llamada a ARCA.');

        return self::SUCCESS;
    }

    private function leerFacturas(string $path): array
    {
        [$fh, $map] = $this->abrirCsv($path);

        foreach ([
            'P_VENTA', 'ID_FACTURA', 'FECHA', 'TOTAL', 'ITEMS',
            'GRAVADO', 'NO_GRAVADO', 'IVA', 'LOTE', 'ID_INQ',
            'PERCEPCION', 'TIPO', 'CAE', 'VTO_CAE', 'ERROR',
            'MOTIVO', 'FECHA_RECH', 'CTA_ORIG',
        ] as $campo) {
            if (! array_key_exists($campo, $map)) {
                fclose($fh);
                throw new RuntimeException("FACTURAS sin columna {$campo}");
            }
        }

        $out = [];

        while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $pv = (int) $this->valor($row, $map, 'P_VENTA');
            $numero = (int) $this->valor($row, $map, 'ID_FACTURA');
            $tipo = (int) $this->valor($row, $map, 'TIPO');

            $clave = $this->clave($pv, $numero, $tipo);

            if (isset($out[$clave])) {
                fclose($fh);
                throw new RuntimeException("Factura histórica duplicada: {$clave}");
            }

            $out[$clave] = [
                'clave' => $clave,
                'pv' => $pv,
                'numero' => $numero,
                'tipo' => $tipo,
                'fecha' => trim((string) $this->valor($row, $map, 'FECHA')),
                'total' => $this->numero($this->valor($row, $map, 'TOTAL')),
                'items_esperados' => (int) $this->valor($row, $map, 'ITEMS'),
                'gravado' => $this->numero($this->valor($row, $map, 'GRAVADO')),
                'no_gravado' => $this->numero($this->valor($row, $map, 'NO_GRAVADO')),
                'iva' => $this->numero($this->valor($row, $map, 'IVA')),
                'lote' => (int) $this->valor($row, $map, 'LOTE'),
                'id_inq' => trim((string) $this->valor($row, $map, 'ID_INQ')),
                'percepcion' => $this->numero($this->valor($row, $map, 'PERCEPCION')),
                'cae' => $this->nullableString($this->valor($row, $map, 'CAE')),
                'vto_cae' => $this->nullableString($this->valor($row, $map, 'VTO_CAE')),
                'error' => $this->numero($this->valor($row, $map, 'ERROR')),
                'motivo' => $this->nullableString($this->valor($row, $map, 'MOTIVO')),
                'fecha_rech' => $this->nullableString($this->valor($row, $map, 'FECHA_RECH')),
                'cta_orig' => $this->nullableString($this->valor($row, $map, 'CTA_ORIG')),
            ];
        }

        fclose($fh);

        return $out;
    }

    private function leerItemsNecesarios(string $path, array $facturas): array
    {
        [$fh, $map] = $this->abrirCsv($path);

        foreach ([
            'P_VENTA', 'ID_FACTURA', 'ITEM', 'IMPORTE', 'CODIGO', 'TIPO',
        ] as $campo) {
            if (! array_key_exists($campo, $map)) {
                fclose($fh);
                throw new RuntimeException("ITEM_FACTURA sin columna {$campo}");
            }
        }

        $items = [];
        $leidos = 0;
        $seleccionados = 0;

        while (($row = fgetcsv($fh, null, ',', '"', '')) !== false) {
            if ($row === [null]) {
                continue;
            }

            $leidos++;

            $pv = (int) $this->valor($row, $map, 'P_VENTA');
            $numero = (int) $this->valor($row, $map, 'ID_FACTURA');
            $tipo = (int) $this->valor($row, $map, 'TIPO');
            $clave = $this->clave($pv, $numero, $tipo);

            if (! isset($facturas[$clave])) {
                continue;
            }

            $items[$clave][] = [
                'descripcion' => trim((string) $this->valor($row, $map, 'ITEM')),
                'importe' => $this->numero($this->valor($row, $map, 'IMPORTE')),
                'codigo' => trim((string) $this->valor($row, $map, 'CODIGO')),
            ];

            $seleccionados++;
        }

        fclose($fh);

        $this->line(
            'ITEM_FACTURA leído: ' .
            number_format($leidos, 0, ',', '.') .
            ' filas; seleccionadas para julio: ' .
            number_format($seleccionados, 0, ',', '.')
        );

        return $items;
    }

    private function analizar(array $facturas, array $items): array
    {
        $porLote = [];
        $sinItems = 0;
        $itemsDistintos = 0;
        $lotesDesconocidos = 0;
        $total = 0.0;
        $autorizadas = 0;
        $rechazadas = 0;
        $sinCliente = 0;
        $sinCuentaCorriente = 0;

        $puntosVenta = DB::table('puntos_venta')
            ->whereIn('numero', [38, 39])
            ->pluck('id_punto_venta', 'numero');

        $puntosVentaFaltantes = 0;
        foreach ([38, 39] as $pv) {
            if (! isset($puntosVenta[$pv])) {
                $puntosVentaFaltantes++;
            }
        }

        foreach ($facturas as $f) {
            if (! isset($this->lotesJulio2026[$f['lote']])) {
                $lotesDesconocidos++;
                continue;
            }

            $encontrados = count($items[$f['clave']] ?? []);

            if ($encontrados === 0) {
                $sinItems++;
            }

            if ($encontrados !== $f['items_esperados']) {
                $itemsDistintos++;
            }

            $porLote[$f['lote']]['cantidad'] =
                ($porLote[$f['lote']]['cantidad'] ?? 0) + 1;

            $porLote[$f['lote']]['total'] =
                ($porLote[$f['lote']]['total'] ?? 0.0) + $f['total'];

            $total += $f['total'];

            if ($this->estadoFactura($f) === 'AUTORIZADA') {
                $autorizadas++;
            } elseif ($this->estadoFactura($f) === 'RECHAZADA') {
                $rechazadas++;
            }

            [$cuenta, $dominio] = $this->cuentaYDominio($f);

            $cc = DB::table('cuentas_corrientes')
                ->where('dominio', $dominio)
                ->where('cuenta', $cuenta)
                ->first(['id', 'cliente_id']);

            if (! $cc) {
                $sinCuentaCorriente++;
            }

            $clienteId = $this->resolverClienteId($f, $cc);

            if (! $clienteId) {
                $sinCliente++;
            }
        }

        ksort($porLote);

        return [
            'cantidad' => count($facturas),
            'total' => round($total, 2),
            'por_lote' => $porLote,
            'facturas_sin_items' => $sinItems,
            'facturas_items_distintos' => $itemsDistintos,
            'lotes_desconocidos' => $lotesDesconocidos,
            'puntos_venta_faltantes' => $puntosVentaFaltantes,
            'autorizadas' => $autorizadas,
            'rechazadas' => $rechazadas,
            'sin_cliente' => $sinCliente,
            'sin_cuenta_corriente' => $sinCuentaCorriente,
        ];
    }

    private function mostrarAnalisis(array $a): void
    {
        $this->newLine();
        $this->line('=== IMPORTACIÓN HISTÓRICA KNG — JULIO 2026 ===');
        $this->line('Facturas                : ' . $a['cantidad']);
        $this->line('Importe total           : ' . number_format($a['total'], 2, ',', '.'));
        $this->line('Autorizadas             : ' . $a['autorizadas']);
        $this->line('Rechazadas              : ' . $a['rechazadas']);
        $this->line('Sin items               : ' . $a['facturas_sin_items']);
        $this->line('ITEMS con cantidad dif. : ' . $a['facturas_items_distintos']);
        $this->line('Lotes desconocidos      : ' . $a['lotes_desconocidos']);
        $this->line('PV faltantes en GeI     : ' . $a['puntos_venta_faltantes']);
        $this->line('Sin cuenta corriente    : ' . $a['sin_cuenta_corriente']);
        $this->line('Sin cliente resuelto    : ' . $a['sin_cliente']);

        $filas = [];

        foreach ($a['por_lote'] as $lote => $r) {
            $cfg = $this->lotesJulio2026[$lote];

            $filas[] = [
                $lote,
                $cfg['pv'],
                $cfg['dominio'],
                $r['cantidad'],
                number_format(round($r['total'], 2), 2, ',', '.'),
            ];
        }

        $this->newLine();
        $this->table(
            ['Lote', 'PV', 'Dominio', 'Facturas', 'Total'],
            $filas
        );
    }

    private function importar(string $periodo, array $facturas, array $items): void
    {
        $ahora = now();

        $pvIds = DB::table('puntos_venta')
            ->whereIn('numero', [38, 39])
            ->pluck('id_punto_venta', 'numero');

        foreach ([38, 39] as $pv) {
            if (! isset($pvIds[$pv])) {
                throw new RuntimeException(
                    "No existe punto de venta {$pv} en puntos_venta."
                );
            }
        }

        $facturacionIds = [];

        foreach ($this->lotesJulio2026 as $lote => $cfg) {
            $cantidad = 0;
            $total = 0.0;

            foreach ($facturas as $f) {
                if ($f['lote'] === $lote) {
                    $cantidad++;
                    $total += $f['total'];
                }
            }

            $facturacionIds[$lote] = DB::table('facturaciones')->insertGetId([
                'periodo' => $periodo,
                'fecha_desde' => $cfg['desde'],
                'fecha_hasta' => $cfg['hasta'],
                'origen' => 'MIGRADO_KNG',
                'estado' => 'VALIDADA',
                'modo_emision' => 'RECE',
                'ambiente_arca' => null,
                'fecha_proceso' => $ahora,
                'cantidad_facturas' => $cantidad,
                'importe_total' => round($total, 2),
                'observaciones' =>
                    "Importación histórica literal KNG. Lote {$lote}, PV {$cfg['pv']}, {$cfg['dominio']}.",
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ], 'id_facturacion');
        }

        $insertadas = 0;

        foreach ($facturas as $f) {
            $cfg = $this->lotesJulio2026[$f['lote']];
            [$cuenta, $dominio] = $this->cuentaYDominio($f);

            $cc = DB::table('cuentas_corrientes')
                ->where('dominio', $dominio)
                ->where('cuenta', $cuenta)
                ->first(['id', 'cliente_id']);

            $clienteId = $this->resolverClienteId($f, $cc);
            $cliente = $clienteId
                ? DB::table('clientes')->where('id', $clienteId)->first()
                : null;

            $comprobanteArcaId = $this->buscarComprobanteArcaId(
                $periodo,
                $f['pv'],
                $f['tipo'],
                $f['numero']
            );

            $idFactura = DB::table('facturas')->insertGetId([
                'facturacion_id' => $facturacionIds[$f['lote']],
                'punto_venta_id' => $pvIds[$f['pv']],
                'cliente_id' => $clienteId,
                'tipo_comprobante' => $f['tipo'],
                'numero_comprobante' => $f['numero'],
                'fecha_comprobante' => $f['fecha'],

                'cliente_nombre' => $cliente?->nombre,
                'condicion_iva' => $cliente?->condicion_iva,
                'tipo_documento' => $cliente?->tipo_documento,
                'numero_documento' => $cliente?->numero_documento,
                'cuit' => $cliente?->cuit,

                'neto_gravado' => $f['gravado'],
                'iva' => $f['iva'],
                'no_gravado' => $f['no_gravado'],
                'otros_tributos' => $f['percepcion'],
                'total' => $f['total'],

                'estado' => $this->estadoFactura($f),
                'origen' => 'MIGRADO_KNG',
                'cae' => $f['cae'],
                'vencimiento_cae' => $f['vto_cae'],
                'comprobante_arca_id' => $comprobanteArcaId,
                'clave_origen' => $f['clave'],
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ], 'id_factura');

            DB::table('facturas_cuentas')->insert([
                'factura_id' => $idFactura,
                'cuenta_corriente_id' => $cc?->id,
                'cuenta' => $cuenta,
                'dominio' => $dominio,
                'importe' => $f['total'],
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            $beneficiarioId = $f['cta_orig']
                ? $clienteId
                : null;

            $beneficiarioOrigen = $f['cta_orig']
                ? $f['id_inq']
                : null;

            $loteItems = [];

            foreach ($items[$f['clave']] ?? [] as $item) {
                $loteItems[] = [
                    'factura_id' => $idFactura,
                    'cuenta_corriente_movimiento_id' => null,
                    'codigo' => $item['codigo'] !== '' ? $item['codigo'] : null,
                    'descripcion' => $item['descripcion'] !== '' ? $item['descripcion'] : null,
                    /*
                     * En histórico KNG el renglón de ITEM_FACTURA ya es el
                     * resultado final facturado. No se reconstruye el reparto.
                     */
                    'importe_origen' => $item['importe'],
                    'porcentaje_aplicado' => 100,
                    'importe' => $item['importe'],
                    'neto_gravado' => 0,
                    'iva' => 0,
                    'no_gravado' => 0,
                    'alicuota_iva' => null,
                    'beneficiario_cliente_id' => $beneficiarioId,
                    'beneficiario_identificador_origen' => $beneficiarioOrigen,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];
            }

            foreach (array_chunk($loteItems, 500) as $chunk) {
                DB::table('facturas_items')->insert($chunk);
            }

            $insertadas++;

            if ($insertadas % 250 === 0) {
                $this->line("Importadas {$insertadas} / " . count($facturas));
            }
        }
    }

    private function resolverClienteId(array $f, ?object $cc): ?int
    {
        /*
         * Caso normal: el destinatario es la propia cuenta.
         */
        if (! $f['cta_orig']) {
            if ($cc?->cliente_id) {
                return (int) $cc->cliente_id;
            }

            $clienteId = DB::table('clientes_cuentas')
                ->where('cuenta', $f['id_inq'])
                ->value('cliente_id');

            return $clienteId ? (int) $clienteId : null;
        }

        /*
         * FACT_IND histórico:
         * CTA_ORIG = cuenta propietaria madre
         * ID_INQ   = identificador KNG del beneficiario.
         */
        if (! $cc) {
            return null;
        }

        $clienteId = DB::table('cuentas_facturacion_beneficiarios')
            ->where('cuenta_corriente_id', $cc->id)
            ->where('identificador_origen', $f['id_inq'])
            ->value('cliente_id');

        return $clienteId ? (int) $clienteId : null;
    }

    private function cuentaYDominio(array $f): array
    {
        $cfg = $this->lotesJulio2026[$f['lote']] ?? null;

        if (! $cfg) {
            throw new RuntimeException(
                "Lote histórico desconocido: {$f['lote']}"
            );
        }

        $cuenta = $f['cta_orig'] ?: $f['id_inq'];

        if ($cuenta === '') {
            throw new RuntimeException(
                "Factura {$f['clave']} sin cuenta histórica."
            );
        }

        return [$cuenta, $cfg['dominio']];
    }

    private function estadoFactura(array $f): string
    {
        /*
        * En FACTURAS.DBF, ERROR puede contener diferencias de redondeo
        * aun cuando el comprobante fue autorizado.
        *
        * La presencia de CAE es la evidencia de autorización fiscal.
        */
        if ($f['cae'] !== null) {
            return 'AUTORIZADA';
        }

        if (
            $f['motivo'] !== null ||
            $f['fecha_rech'] !== null
        ) {
            return 'RECHAZADA';
        }

        return 'VALIDADA';
    }

    private function buscarComprobanteArcaId(
        string $periodo,
        int $pv,
        int $tipo,
        int $numero
    ): ?int {
        $fila = DB::table('comprobantes_arca')
            ->where('periodo', $periodo)
            ->whereRaw(
                "regexp_replace(COALESCE(punto_venta, ''), '[^0-9]', '', 'g') = ?",
                [(string) $pv]
            )
            ->whereRaw(
                "regexp_replace(COALESCE(tipo_codigo, ''), '[^0-9]', '', 'g')::integer = ?",
                [$tipo]
            )
            ->whereRaw(
                "regexp_replace(COALESCE(numero_comprobante, ''), '[^0-9]', '', 'g')::bigint = ?",
                [$numero]
            )
            ->value('id_comprobante_arca');

        return $fila ? (int) $fila : null;
    }

    private function abrirCsv(string $path): array
    {
        $fh = fopen($path, 'r');

        if (! $fh) {
            throw new RuntimeException("No se pudo abrir {$path}");
        }

        $header = fgetcsv($fh, null, ',', '"', '');

        if ($header === false) {
            fclose($fh);
            throw new RuntimeException("CSV vacío: {$path}");
        }

        $map = [];

        foreach ($header as $i => $campo) {
            $map[trim((string) $campo)] = $i;
        }

        return [$fh, $map];
    }

    private function valor(array $row, array $map, string $campo): mixed
    {
        return $row[$map[$campo]] ?? null;
    }

    private function numero(mixed $value): float
    {
        $v = trim((string) $value);

        if ($v === '') {
            return 0.0;
        }

        $v = str_replace(' ', '', $v);

        if (str_contains($v, ',') && ! str_contains($v, '.')) {
            $v = str_replace(',', '.', $v);
        } elseif (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace(',', '', $v);
        }

        return (float) $v;
    }

    private function nullableString(mixed $value): ?string
    {
        $v = trim((string) $value);

        return $v !== '' ? $v : null;
    }

    private function clave(int $pv, int $numero, int $tipo): string
    {
        return "KNG:202607:PV{$pv}:T{$tipo}:N{$numero}";
    }
}
