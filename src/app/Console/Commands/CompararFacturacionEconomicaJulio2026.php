<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CompararFacturacionEconomicaJulio2026 extends Command
{
    protected $signature = 'gei:facturacion-comparar-economica-julio-2026
                            {--kng=storage/app/private/facturas_kng_julio_2026.csv}
                            {--gei=storage/app/private/facturacion_simulada_julio_2026.csv}
                            {--salida=storage/app/private/diferencias_economicas_julio_2026.csv}';

    protected $description = 'Compara KNG vs GeI por suma económica, ignorando cantidad y numeración de comprobantes';

    public function handle(): int
    {
        $kngPath = base_path($this->option('kng'));
        $geiPath = base_path($this->option('gei'));
        $salida = base_path($this->option('salida'));

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
        sort($claves, SORT_NATURAL);

        $dir = dirname($salida);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $fh = fopen($salida, 'w');
        fputcsv($fh, ['clave','total_kng','total_gei','diferencia','comprobantes_kng','comprobantes_gei'], ',', '"', '');

        $totalKng = 0.0;
        $totalGei = 0.0;
        $diferencias = 0;
        $filasPantalla = [];

        foreach ($claves as $clave) {
            $a = $kng[$clave] ?? ['total'=>0.0,'cantidad'=>0];
            $b = $gei[$clave] ?? ['total'=>0.0,'cantidad'=>0];

            $dif = round($b['total'] - $a['total'], 2);
            $totalKng += $a['total'];
            $totalGei += $b['total'];

            fputcsv($fh, [
                $clave,
                number_format($a['total'], 2, '.', ''),
                number_format($b['total'], 2, '.', ''),
                number_format($dif, 2, '.', ''),
                $a['cantidad'],
                $b['cantidad'],
            ], ',', '"', '');

            if (abs($dif) >= 0.01) {
                $diferencias++;
                if (count($filasPantalla) < 80) {
                    $filasPantalla[] = [
                        $clave,
                        number_format($a['total'], 2, '.', ''),
                        number_format($b['total'], 2, '.', ''),
                        number_format($dif, 2, '.', ''),
                        $a['cantidad'],
                        $b['cantidad'],
                    ];
                }
            }
        }

        fclose($fh);

        $totalKng = round($totalKng, 2);
        $totalGei = round($totalGei, 2);
        $difGeneral = round($totalGei - $totalKng, 2);

        $this->newLine();
        $this->line('=== COMPARACIÓN ECONÓMICA JULIO 2026 ===');
        $this->line('Total KNG : ' . number_format($totalKng, 2, '.', ''));
        $this->line('Total GeI : ' . number_format($totalGei, 2, '.', ''));
        $this->line('Diferencia: ' . number_format($difGeneral, 2, '.', ''));
        $this->line('Claves con diferencia >= 0,01: ' . $diferencias);

        if ($filasPantalla) {
            $this->newLine();
            $this->table(
                ['Clave','KNG','GeI','Dif.','Comp.KNG','Comp.GeI'],
                $filasPantalla
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
            if ($row === [null] || $row === false) continue;

            $ctaOrig = $this->valor($row, $map, ['CTA_ORIG','cta_orig']);
            $idInq = $this->valor($row, $map, ['ID_INQ','id_inq']);
            $total = $this->numero($this->valor($row, $map, ['TOTAL','total']));

            $ctaOrigN = trim((string)$ctaOrig);
            $idInqN = trim((string)$idInq);

            if ($ctaOrigN !== '' && $ctaOrigN !== '0') {
                $clave = "BEN:{$idInqN}|CTA:{$ctaOrigN}";
            } else {
                $clave = "CTA:{$idInqN}";
            }

            $out[$clave]['total'] = ($out[$clave]['total'] ?? 0.0) + $total;
            $out[$clave]['cantidad'] = ($out[$clave]['cantidad'] ?? 0) + 1;
        }

        fclose($h);
        return $out;
    }

    private function leerGei(string $path): array
    {
        [$h, $map] = $this->abrirCsv($path);
        $out = [];

        while (($row = fgetcsv($h, null, ',', '"', '')) !== false) {
            if ($row === [null] || $row === false) continue;

            $cuenta = trim((string)$this->valor($row, $map, ['cuenta','CUENTA']));
            $ident = trim((string)$this->valor($row, $map, ['identificador_origen','IDENTIFICADOR_ORIGEN']));
            $total = $this->numero($this->valor($row, $map, ['total','TOTAL']));

            if ($ident !== '' && $ident !== '0') {
                $clave = "BEN:{$ident}|CTA:{$cuenta}";
            } else {
                $clave = "CTA:{$cuenta}";
            }

            $out[$clave]['total'] = ($out[$clave]['total'] ?? 0.0) + $total;
            $out[$clave]['cantidad'] = ($out[$clave]['cantidad'] ?? 0) + 1;
        }

        fclose($h);
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
            $map[trim((string)$name)] = $i;
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
        $v = trim((string)$value);
        if ($v === '') return 0.0;
        $v = str_replace(' ', '', $v);
        if (str_contains($v, ',') && !str_contains($v, '.')) {
            $v = str_replace(',', '.', $v);
        } elseif (str_contains($v, ',') && str_contains($v, '.')) {
            $v = str_replace(',', '', $v);
        }
        return (float)$v;
    }
}
