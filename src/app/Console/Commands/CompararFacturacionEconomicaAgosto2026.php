<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CompararFacturacionEconomicaAgosto2026 extends Command
{
    protected $signature = 'gei:facturacion-comparar-economica-agosto-2026
                            {--kng=storage/app/private/facturas_kng_agosto_2026.csv}
                            {--gei=storage/app/private/facturacion_simulada_agosto_2026.csv}
                            {--salida=storage/app/private/diferencias_economicas_agosto_2026.csv}';

    protected $description = 'Compara KNG vs GeI para agosto 2026 por cuenta/beneficiario y suma económica';

    public function handle(): int
    {
        $kngPath = base_path((string) $this->option('kng'));
        $geiPath = base_path((string) $this->option('gei'));
        $salida = base_path((string) $this->option('salida'));

        if (!is_file($kngPath)) {
            $this->error("No existe KNG: {$kngPath}");
            return self::FAILURE;
        }

        if (!is_file($geiPath)) {
            $this->error("No existe GeI: {$geiPath}");
            return self::FAILURE;
        }

        $kng = $this->leerKng($kngPath);
        $gei = $this->leerGei($geiPath);

        $claves = array_unique(array_merge(array_keys($kng), array_keys($gei)));

        $filas = [];
        $totalKng = 0.0;
        $totalGei = 0.0;

        foreach ($claves as $clave) {
            $a = $kng[$clave] ?? $this->vacio();
            $b = $gei[$clave] ?? $this->vacio();

            $dif = round($b['total'] - $a['total'], 2);

            $totalKng += $a['total'];
            $totalGei += $b['total'];

            $estado = 'OK';

            if ($a['cantidad'] === 0) {
                $estado = 'SOLO_GEI';
            } elseif ($b['cantidad'] === 0) {
                $estado = 'FALTA_GEI';
            } elseif (abs($dif) >= 0.01) {
                $estado = 'TOTAL_DISTINTO';
            } elseif ($a['cantidad'] !== $b['cantidad']) {
                $estado = 'CANTIDAD_DISTINTA';
            }

            $filas[] = [
                'clave' => $clave,
                'estado' => $estado,
                'total_kng' => round($a['total'], 2),
                'total_gei' => round($b['total'], 2),
                'diferencia' => $dif,
                'comprobantes_kng' => $a['cantidad'],
                'comprobantes_gei' => $b['cantidad'],
                'lotes_kng' => implode('|', array_keys($a['lotes'])),
                'lotes_gei' => implode('|', array_keys($b['lotes'])),
            ];
        }

        usort($filas, function (array $x, array $y): int {
            $cmp = abs($y['diferencia']) <=> abs($x['diferencia']);
            return $cmp !== 0 ? $cmp : strnatcasecmp($x['clave'], $y['clave']);
        });

        $dir = dirname($salida);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fh = fopen($salida, 'w');

        fputcsv($fh, [
            'clave',
            'estado',
            'total_kng',
            'total_gei',
            'diferencia',
            'comprobantes_kng',
            'comprobantes_gei',
            'lotes_kng',
            'lotes_gei',
        ], ',', '"', '');

        foreach ($filas as $fila) {
            fputcsv($fh, [
                $fila['clave'],
                $fila['estado'],
                number_format($fila['total_kng'], 2, '.', ''),
                number_format($fila['total_gei'], 2, '.', ''),
                number_format($fila['diferencia'], 2, '.', ''),
                $fila['comprobantes_kng'],
                $fila['comprobantes_gei'],
                $fila['lotes_kng'],
                $fila['lotes_gei'],
            ], ',', '"', '');
        }

        fclose($fh);

        $conteos = [];
        foreach ($filas as $fila) {
            $conteos[$fila['estado']] = ($conteos[$fila['estado']] ?? 0) + 1;
        }

        $this->newLine();
        $this->line('=== COMPARACIÓN ECONÓMICA AGOSTO 2026 ===');
        $this->line('Total KNG       : ' . number_format(round($totalKng, 2), 2, '.', ''));
        $this->line('Total GeI       : ' . number_format(round($totalGei, 2), 2, '.', ''));
        $this->line('Diferencia      : ' . number_format(round($totalGei - $totalKng, 2), 2, '.', ''));
        $this->line('Claves totales  : ' . count($filas));

        foreach (['OK', 'CANTIDAD_DISTINTA', 'TOTAL_DISTINTO', 'FALTA_GEI', 'SOLO_GEI'] as $estado) {
            $this->line(str_pad($estado, 18) . ': ' . ($conteos[$estado] ?? 0));
        }

        $top = array_values(array_filter(
            $filas,
            fn (array $f) => $f['estado'] !== 'OK'
        ));

        $top = array_slice($top, 0, 80);

        if ($top) {
            $this->newLine();
            $this->table(
                ['Clave', 'Estado', 'KNG', 'GeI', 'Dif.', 'Comp.KNG', 'Comp.GeI', 'Lote KNG', 'Lote GeI'],
                array_map(fn (array $f) => [
                    $f['clave'],
                    $f['estado'],
                    number_format($f['total_kng'], 2, '.', ''),
                    number_format($f['total_gei'], 2, '.', ''),
                    number_format($f['diferencia'], 2, '.', ''),
                    $f['comprobantes_kng'],
                    $f['comprobantes_gei'],
                    $f['lotes_kng'],
                    $f['lotes_gei'],
                ], $top)
            );
        }

        $this->newLine();
        $this->info("CSV: {$salida}");

        return self::SUCCESS;
    }

    private function leerKng(string $path): array
    {
        [$h, $map] = $this->abrirCsv($path);
        $out = [];

        while (($row = fgetcsv($h, null, ',', '"', '')) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }

            $ctaOrig = trim((string) $this->valor($row, $map, ['CTA_ORIG', 'cta_orig']));
            $idInq = trim((string) $this->valor($row, $map, ['ID_INQ', 'id_inq']));
            $total = $this->numero($this->valor($row, $map, ['TOTAL', 'total']));
            $lote = trim((string) $this->valor($row, $map, ['LOTE', 'lote']));

            /*
             * Convención KNG validada en julio:
             * - normal: ID_INQ representa la cuenta facturada
             * - FACT_IND: ID_INQ es beneficiario y CTA_ORIG la cuenta original
             */
            if ($ctaOrig !== '' && $ctaOrig !== '0') {
                $clave = 'BEN:' . $this->normalizarNumeroTexto($idInq)
                    . '|CTA:' . $this->normalizarNumeroTexto($ctaOrig);
            } else {
                $clave = 'CTA:' . $this->normalizarNumeroTexto($idInq);
            }

            $out[$clave] ??= $this->vacio();
            $out[$clave]['total'] += $total;
            $out[$clave]['cantidad']++;

            if ($lote !== '') {
                $out[$clave]['lotes'][$lote] = true;
            }
        }

        fclose($h);

        foreach ($out as &$fila) {
            $fila['total'] = round($fila['total'], 2);
        }
        unset($fila);

        return $out;
    }

    private function leerGei(string $path): array
    {
        [$h, $map] = $this->abrirCsv($path);
        $out = [];

        while (($row = fgetcsv($h, null, ',', '"', '')) !== false) {
            if ($row === [null] || $row === false) {
                continue;
            }

            $cuenta = trim((string) $this->valor($row, $map, ['cuenta', 'CUENTA']));
            $ident = trim((string) $this->valor($row, $map, ['identificador_origen', 'IDENTIFICADOR_ORIGEN']));
            $total = $this->numero($this->valor($row, $map, ['total', 'TOTAL']));
            $lote = trim((string) $this->valor($row, $map, ['lote', 'LOTE']));

            if ($ident !== '' && $ident !== '0') {
                $clave = 'BEN:' . $this->normalizarNumeroTexto($ident)
                    . '|CTA:' . $this->normalizarNumeroTexto($cuenta);
            } else {
                $clave = 'CTA:' . $this->normalizarNumeroTexto($cuenta);
            }

            $out[$clave] ??= $this->vacio();
            $out[$clave]['total'] += $total;
            $out[$clave]['cantidad']++;

            if ($lote !== '') {
                $out[$clave]['lotes'][$lote] = true;
            }
        }

        fclose($h);

        foreach ($out as &$fila) {
            $fila['total'] = round($fila['total'], 2);
        }
        unset($fila);

        return $out;
    }

    private function abrirCsv(string $path): array
    {
        $h = fopen($path, 'r');

        if (!$h) {
            throw new \RuntimeException("No se pudo abrir {$path}");
        }

        $header = fgetcsv($h, null, ',', '"', '');

        if ($header === false) {
            throw new \RuntimeException("CSV vacío: {$path}");
        }

        $map = [];

        foreach ($header as $i => $name) {
            $map[trim((string) $name)] = $i;
        }

        return [$h, $map];
    }

    private function valor(array $row, array $map, array $nombres): mixed
    {
        foreach ($nombres as $nombre) {
            if (array_key_exists($nombre, $map)) {
                return $row[$map[$nombre]] ?? null;
            }
        }

        return null;
    }

    private function numero(mixed $value): float
    {
        $v = trim((string) $value);

        if ($v === '') {
            return 0.0;
        }

        $v = str_replace(' ', '', $v);

        if (str_contains($v, ',') && !str_contains($v, '.')) {
            $v = str_replace(',', '.', $v);
        } elseif (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace(',', '', $v);
        }

        return (float) $v;
    }

    private function normalizarNumeroTexto(string $value): string
    {
        $value = trim($value);

        if ($value !== '' && preg_match('/^[0-9]+$/', $value)) {
            return ltrim($value, '0') ?: '0';
        }

        return $value;
    }

    private function vacio(): array
    {
        return [
            'total' => 0.0,
            'cantidad' => 0,
            'lotes' => [],
        ];
    }
}
