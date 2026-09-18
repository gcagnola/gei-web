<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CompararFacturacionJulio2026Kng extends Command
{
    protected $signature = 'gei:facturacion-comparar-julio-2026
                            {--kng=storage/app/private/facturas_kng_julio_2026.csv}
                            {--gei=storage/app/private/facturacion_simulada_julio_2026.csv}
                            {--tolerancia=0.02}
                            {--salida=storage/app/private/diferencias_facturacion_julio_2026_v2.csv}';

    protected $description =
        'Compara la simulación GeI-Web contra FACTURAS.DBF de KNG por identidad económica';

    public function handle(): int
    {
        $archivoKng = base_path($this->option('kng'));
        $archivoGei = base_path($this->option('gei'));
        $salida = base_path($this->option('salida'));
        $tolerancia = (float) $this->option('tolerancia');

        foreach ([$archivoKng, $archivoGei] as $archivo) {
            if (!is_file($archivo)) {
                $this->error("No existe: {$archivo}");
                return self::FAILURE;
            }
        }

        $kng = $this->normalizarKng($this->leerCsv($archivoKng));
        $gei = $this->normalizarGei($this->leerCsv($archivoGei));

        $this->line('KNG: ' . count($kng));
        $this->line('GeI: ' . count($gei));
        $this->newLine();

        $kngBuckets = $this->agrupar($kng);
        $geiBuckets = $this->agrupar($gei);

        $claves = array_values(array_unique(array_merge(
            array_keys($kngBuckets),
            array_keys($geiBuckets)
        )));
        sort($claves);

        $stats = [];
        foreach (range(1236, 1245) as $lote) {
            $stats[$lote] = $this->statsBase();
        }

        $diferencias = [];

        foreach ($claves as $clave) {
            $filasKng = $kngBuckets[$clave] ?? [];
            $filasGei = $geiBuckets[$clave] ?? [];

            $lote = (int) (
                $filasKng[0]['lote']
                ?? $filasGei[0]['lote']
                ?? 0
            );

            /*
             * Emparejamiento dentro de la misma identidad económica.
             *
             * Si existe más de un comprobante para una misma identidad,
             * elegimos para cada KNG el GeI más cercano priorizando:
             *   1. mismo tipo
             *   2. total más cercano
             *   3. número más cercano
             *
             * Esto evita que un cambio de TIPO genere artificialmente
             * FALTA + SOBRA.
             */
            while ($filasKng && $filasGei) {
                $mejor = null;

                foreach ($filasKng as $ik => $rk) {
                    foreach ($filasGei as $ig => $rg) {
                        $score = $this->scoreEmparejamiento($rk, $rg);

                        if ($mejor === null || $score < $mejor['score']) {
                            $mejor = [
                                'ik' => $ik,
                                'ig' => $ig,
                                'score' => $score,
                            ];
                        }
                    }
                }

                $rk = $filasKng[$mejor['ik']];
                $rg = $filasGei[$mejor['ig']];

                unset($filasKng[$mejor['ik']], $filasGei[$mejor['ig']]);
                $filasKng = array_values($filasKng);
                $filasGei = array_values($filasGei);

                $tipoOk = $rk['tipo'] === $rg['tipo'];
                $totalOk = abs($rk['total'] - $rg['total']) <= $tolerancia;
                $numeroOk = $rk['numero'] === $rg['numero'];

                if (!$numeroOk) {
                    $stats[$lote]['numero_distinto']++;
                }

                if ($tipoOk && $totalOk) {
                    if ($numeroOk) {
                        $stats[$lote]['ok_exacto']++;
                        continue;
                    }

                    $stats[$lote]['contenido_ok_numero_distinto']++;

                    $diferencias[] = $this->diferencia(
                        'NUMERO_DISTINTO',
                        $clave,
                        $rk,
                        $rg
                    );

                    continue;
                }

                if (!$tipoOk && $totalOk) {
                    $stats[$lote]['tipo_distinto']++;

                    $diferencias[] = $this->diferencia(
                        'TIPO_DISTINTO',
                        $clave,
                        $rk,
                        $rg
                    );

                    continue;
                }

                if ($tipoOk && !$totalOk) {
                    $stats[$lote]['total_distinto']++;

                    $diferencias[] = $this->diferencia(
                        'TOTAL_DISTINTO',
                        $clave,
                        $rk,
                        $rg
                    );

                    continue;
                }

                $stats[$lote]['tipo_y_total_distintos']++;

                $diferencias[] = $this->diferencia(
                    'TIPO_Y_TOTAL_DISTINTOS',
                    $clave,
                    $rk,
                    $rg
                );
            }

            foreach ($filasKng as $rk) {
                $stats[$lote]['falta_gei']++;

                $diferencias[] = $this->diferencia(
                    'FALTA_EN_GEI',
                    $clave,
                    $rk,
                    null
                );
            }

            foreach ($filasGei as $rg) {
                $stats[$lote]['sobra_gei']++;

                $diferencias[] = $this->diferencia(
                    'SOBRA_EN_GEI',
                    $clave,
                    null,
                    $rg
                );
            }
        }

        $this->table(
            [
                'Lote',
                'OK exacto',
                'Contenido OK / Nº distinto',
                'Tipo distinto',
                'Total distinto',
                'Tipo+Total',
                'Falta GeI',
                'Sobra GeI',
                'Nº distinto total',
            ],
            array_map(
                fn ($lote) => [
                    $lote,
                    $stats[$lote]['ok_exacto'],
                    $stats[$lote]['contenido_ok_numero_distinto'],
                    $stats[$lote]['tipo_distinto'],
                    $stats[$lote]['total_distinto'],
                    $stats[$lote]['tipo_y_total_distintos'],
                    $stats[$lote]['falta_gei'],
                    $stats[$lote]['sobra_gei'],
                    $stats[$lote]['numero_distinto'],
                ],
                range(1236, 1245)
            )
        );

        $global = $this->statsBase();

        foreach ($stats as $s) {
            foreach ($global as $k => $_) {
                $global[$k] += $s[$k];
            }
        }

        $this->newLine();
        $this->line('=== TOTALES ===');
        $this->line("OK exacto                  : {$global['ok_exacto']}");
        $this->line("Contenido OK / Nº distinto : {$global['contenido_ok_numero_distinto']}");
        $this->line("Tipo distinto              : {$global['tipo_distinto']}");
        $this->line("Total distinto             : {$global['total_distinto']}");
        $this->line("Tipo + total distintos     : {$global['tipo_y_total_distintos']}");
        $this->line("Falta en GeI               : {$global['falta_gei']}");
        $this->line("Sobra en GeI               : {$global['sobra_gei']}");
        $this->line("Nº distinto (todos)        : {$global['numero_distinto']}");

        $this->guardarDiferencias($salida, $diferencias);

        $this->newLine();
        $this->info('Diferencias detalladas: ' . count($diferencias));
        $this->info("CSV: {$salida}");

        /*
         * Muestra sólo diferencias de contenido/identidad.
         * Los NUMERO_DISTINTO puros quedan en CSV porque suelen ser
         * consecuencia de una factura faltante/sobrante anterior.
         */
        $importantes = array_values(array_filter(
            $diferencias,
            fn ($d) => $d['estado'] !== 'NUMERO_DISTINTO'
        ));

        foreach (array_slice($importantes, 0, 100) as $d) {
            $this->newLine();
            $this->warn($d['estado'] . ' | ' . $d['clave']);

            if ($d['kng']) {
                $k = $d['kng'];

                $this->line(sprintf(
                    '  KNG: lote=%d pv=%d tipo=%d nro=%d total=%.2f id_inq=%s cta_orig=%s',
                    $k['lote'],
                    $k['pv'],
                    $k['tipo'],
                    $k['numero'],
                    $k['total'],
                    $k['id_inq'],
                    $k['cta_orig']
                ));
            }

            if ($d['gei']) {
                $g = $d['gei'];

                $this->line(sprintf(
                    '  GeI: lote=%d pv=%d tipo=%d nro=%d total=%.2f id_inq=%s cuenta=%s',
                    $g['lote'],
                    $g['pv'],
                    $g['tipo'],
                    $g['numero'],
                    $g['total'],
                    $g['id_inq'],
                    $g['cuenta']
                ));
            }
        }

        return self::SUCCESS;
    }

    private function normalizarKng(array $filas): array
    {
        $resultado = [];

        foreach ($filas as $r) {
            $idInq = trim((string) ($r['ID_INQ'] ?? ''));
            $ctaOrig = trim((string) ($r['CTA_ORIG'] ?? ''));

            /*
             * En facturación individual de propietarios:
             * FACTURAS.ID_INQ = CLIENTES.DBF.ID_CLIENTE (número chico)
             * FACTURAS.CTA_ORIG = cuenta del propietario.
             *
             * Para el resto CTA_ORIG no forma parte de la identidad.
             */
            $esIndividual =
                $ctaOrig !== ''
                && ctype_digit($idInq)
                && (int) $idInq > 0
                && (int) $idInq <= 999999;

            $resultado[] = [
                'clave' => $this->claveIdentidad(
                    (int) $r['LOTE'],
                    (int) $r['P_VENTA'],
                    $idInq,
                    $esIndividual ? $ctaOrig : null
                ),
                'lote' => (int) $r['LOTE'],
                'pv' => (int) $r['P_VENTA'],
                'tipo' => (int) $r['TIPO'],
                'numero' => (int) $r['ID_FACTURA'],
                'id_inq' => $idInq,
                'total' => (float) $r['TOTAL'],
                'cta_orig' => $ctaOrig,
                'individual' => $esIndividual,
                'items' => isset($r['ITEMS']) ? (int) $r['ITEMS'] : null,
                'gravado' => isset($r['GRAVADO']) ? (float) $r['GRAVADO'] : null,
                'no_gravado' => isset($r['NO_GRAVADO']) ? (float) $r['NO_GRAVADO'] : null,
                'iva' => isset($r['IVA']) ? (float) $r['IVA'] : null,
                'percepcion' => isset($r['PERCEPCION']) ? (float) $r['PERCEPCION'] : null,
            ];
        }

        return $resultado;
    }

    private function normalizarGei(array $filas): array
    {
        $resultado = [];

        foreach ($filas as $r) {
            $identificador = trim((string) ($r['identificador_origen'] ?? ''));
            $cuenta = trim((string) ($r['cuenta'] ?? ''));

            $esIndividual = $identificador !== '';
            $idInq = $esIndividual ? $identificador : $cuenta;

            $resultado[] = [
                'clave' => $this->claveIdentidad(
                    (int) $r['lote'],
                    (int) $r['pv'],
                    $idInq,
                    $esIndividual ? $cuenta : null
                ),
                'lote' => (int) $r['lote'],
                'pv' => (int) $r['pv'],
                'tipo' => (int) $r['tipo'],
                'numero' => (int) $r['numero'],
                'id_inq' => $idInq,
                'cuenta' => $cuenta,
                'total' => (float) $r['total'],
                'cliente_id' => ($r['cliente_id'] ?? '') !== ''
                    ? (int) $r['cliente_id']
                    : null,
                'identificador_origen' => $identificador,
                'individual' => $esIndividual,
            ];
        }

        return $resultado;
    }

    private function agrupar(array $filas): array
    {
        $resultado = [];

        foreach ($filas as $r) {
            $resultado[$r['clave']][] = $r;
        }

        return $resultado;
    }

    private function claveIdentidad(
        int $lote,
        int $pv,
        string $idInq,
        ?string $ctaOrig
    ): string {
        if ($ctaOrig !== null && $ctaOrig !== '') {
            return sprintf(
                '%d|%d|%s|CTA:%s',
                $lote,
                $pv,
                $idInq,
                $ctaOrig
            );
        }

        return sprintf(
            '%d|%d|%s',
            $lote,
            $pv,
            $idInq
        );
    }

    private function scoreEmparejamiento(array $kng, array $gei): float
    {
        /*
         * Tipo tiene prioridad fuerte.
         * Luego acercamos por total y finalmente por correlativo.
         */
        $scoreTipo = $kng['tipo'] === $gei['tipo'] ? 0.0 : 1000000000.0;
        $scoreTotal = abs($kng['total'] - $gei['total']) * 1000.0;
        $scoreNumero = abs($kng['numero'] - $gei['numero']) * 0.001;

        return $scoreTipo + $scoreTotal + $scoreNumero;
    }

    private function diferencia(
        string $estado,
        string $clave,
        ?array $kng,
        ?array $gei
    ): array {
        return [
            'estado' => $estado,
            'clave' => $clave,
            'kng' => $kng,
            'gei' => $gei,
        ];
    }

    private function statsBase(): array
    {
        return [
            'ok_exacto' => 0,
            'contenido_ok_numero_distinto' => 0,
            'tipo_distinto' => 0,
            'total_distinto' => 0,
            'tipo_y_total_distintos' => 0,
            'falta_gei' => 0,
            'sobra_gei' => 0,
            'numero_distinto' => 0,
        ];
    }

    private function leerCsv(string $archivo): array
    {
        $fh = fopen($archivo, 'r');

        $header = fgetcsv(
            $fh,
            null,
            ',',
            '"',
            '\\'
        );

        if (!$header) {
            fclose($fh);
            return [];
        }

        $resultado = [];

        while (
            ($fila = fgetcsv(
                $fh,
                null,
                ',',
                '"',
                '\\'
            )) !== false
        ) {
            if (count($fila) !== count($header)) {
                continue;
            }

            $resultado[] = array_combine($header, $fila);
        }

        fclose($fh);

        return $resultado;
    }

    private function guardarDiferencias(
        string $salida,
        array $diferencias
    ): void {
        $dir = dirname($salida);

        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fh = fopen($salida, 'w');

        fputcsv($fh, [
            'estado',
            'clave',

            'kng_lote',
            'kng_pv',
            'kng_tipo',
            'kng_numero',
            'kng_total',
            'kng_id_inq',
            'kng_cta_orig',
            'kng_individual',
            'kng_items',
            'kng_gravado',
            'kng_no_gravado',
            'kng_iva',
            'kng_percepcion',

            'gei_lote',
            'gei_pv',
            'gei_tipo',
            'gei_numero',
            'gei_total',
            'gei_id_inq',
            'gei_cuenta',
            'gei_cliente_id',
            'gei_identificador_origen',
            'gei_individual',

            'diferencia_total',
            'diferencia_numero',
        ], ',', '"', '\\');

        foreach ($diferencias as $d) {
            $k = $d['kng'] ?? [];
            $g = $d['gei'] ?? [];

            $difTotal =
                isset($k['total'], $g['total'])
                    ? $g['total'] - $k['total']
                    : '';

            $difNumero =
                isset($k['numero'], $g['numero'])
                    ? $g['numero'] - $k['numero']
                    : '';

            fputcsv($fh, [
                $d['estado'],
                $d['clave'],

                $k['lote'] ?? '',
                $k['pv'] ?? '',
                $k['tipo'] ?? '',
                $k['numero'] ?? '',
                $k['total'] ?? '',
                $k['id_inq'] ?? '',
                $k['cta_orig'] ?? '',
                isset($k['individual']) ? ($k['individual'] ? 1 : 0) : '',
                $k['items'] ?? '',
                $k['gravado'] ?? '',
                $k['no_gravado'] ?? '',
                $k['iva'] ?? '',
                $k['percepcion'] ?? '',

                $g['lote'] ?? '',
                $g['pv'] ?? '',
                $g['tipo'] ?? '',
                $g['numero'] ?? '',
                $g['total'] ?? '',
                $g['id_inq'] ?? '',
                $g['cuenta'] ?? '',
                $g['cliente_id'] ?? '',
                $g['identificador_origen'] ?? '',
                isset($g['individual']) ? ($g['individual'] ? 1 : 0) : '',

                $difTotal,
                $difNumero,
            ], ',', '"', '\\');
        }

        fclose($fh);
    }
}
