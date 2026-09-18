<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ImportarComprobantesKngLotesCommand extends Command
{
    protected $signature = 'gei:importar-comprobantes-kng
        {periodo : Período AAAAMM}
        {--lotes= : Rango/lista, por ejemplo 1236-1245 o 1236,1238,1240-1245}
        {--root= : Directorio raíz de Facturas KNG}
        {--dry-run : Audita sin grabar}';

    protected $description = 'Importa el catálogo físico de comprobantes KNG desde directorios lote_* sin modificar KNG.';

    private const PATRON = '/^([A-Z]{2})-(\d{4})-(\d{8})-(\d{11})\.pdf$/i';

    public function handle(): int
    {
        $periodo = trim((string) $this->argument('periodo'));
        if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            $this->error('Período inválido. Use AAAAMM, por ejemplo 202607.');
            return self::FAILURE;
        }

        if (! Schema::hasTable('comprobantes_arca')) {
            $this->error('No existe la tabla comprobantes_arca.');
            return self::FAILURE;
        }

        foreach (['lote', 'ruta_relativa'] as $columna) {
            if (! Schema::hasColumn('comprobantes_arca', $columna)) {
                $this->error("Falta la columna comprobantes_arca.{$columna}. Ejecute gei-artisan migrate.");
                return self::FAILURE;
            }
        }

        $lotes = $this->parsearLotes((string) $this->option('lotes'));
        if ($lotes === []) {
            $this->error('Debe indicar --lotes=. Ejemplo: --lotes=1236-1245');
            return self::FAILURE;
        }

        $root = trim((string) ($this->option('root') ?: config('filesystems.disks.arca_facturas.root')));
        $root = rtrim($root, DIRECTORY_SEPARATOR);

        if ($root === '' || ! is_dir($root) || ! is_readable($root)) {
            $this->error("No se puede leer el directorio raíz: {$root}");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');

        $totales = [
            'lotes_solicitados' => count($lotes),
            'lotes_existentes' => 0,
            'pdf' => 0,
            'validos' => 0,
            'nombre_no_interpretable' => 0,
            'insertados_o_actualizados' => 0,
        ];

        $porLote = [];
        $porTipo = [];

        foreach ($lotes as $lote) {
            $dirNombre = 'lote_'.$lote;
            $directorio = $root.DIRECTORY_SEPARATOR.$dirNombre;

            if (! is_dir($directorio) || ! is_readable($directorio)) {
                $porLote[$lote] = ['estado' => 'NO_ENCONTRADO', 'pdf' => 0, 'validos' => 0];
                continue;
            }

            $totales['lotes_existentes']++;
            $nombres = scandir($directorio, SCANDIR_SORT_NONE);
            if (! is_array($nombres)) {
                $porLote[$lote] = ['estado' => 'NO_LEIBLE', 'pdf' => 0, 'validos' => 0];
                continue;
            }

            $pdfLote = 0;
            $validosLote = 0;
            $rows = [];

            foreach ($nombres as $nombre) {
                if ($nombre === '.' || $nombre === '..' || ! str_ends_with(strtolower($nombre), '.pdf')) {
                    continue;
                }

                $pdfLote++;
                $totales['pdf']++;

                if (preg_match(self::PATRON, $nombre, $m) !== 1) {
                    $totales['nombre_no_interpretable']++;
                    continue;
                }

                $rutaCompleta = $directorio.DIRECTORY_SEPARATOR.$nombre;
                if (! is_file($rutaCompleta)) {
                    continue;
                }

                $tipo = strtoupper($m[1]);
                $validosLote++;
                $totales['validos']++;
                $porTipo[$tipo] = ($porTipo[$tipo] ?? 0) + 1;

                $mtime = @filemtime($rutaCompleta);
                $size = @filesize($rutaCompleta);

                $rows[] = [
                    'cuenta_cobol' => $m[4],
                    'tipo_codigo' => $tipo,
                    'punto_venta' => $m[2],
                    'numero_comprobante' => $m[3],
                    'nombre_archivo' => $nombre,
                    'ruta_relativa' => $dirNombre.'/'.$nombre,
                    'periodo' => $periodo,
                    'lote' => $lote,
                    'fecha_archivo' => date('Y-m-d H:i:s', $mtime !== false ? $mtime : time()),
                    'tamano_bytes' => $size !== false ? (int) $size : 0,
                    'valido' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            if (! $dryRun && $rows !== []) {
                foreach (array_chunk($rows, 500) as $chunk) {
                    DB::table('comprobantes_arca')->upsert(
                        $chunk,
                        ['nombre_archivo'],
                        [
                            'cuenta_cobol',
                            'tipo_codigo',
                            'punto_venta',
                            'numero_comprobante',
                            'ruta_relativa',
                            'periodo',
                            'lote',
                            'fecha_archivo',
                            'tamano_bytes',
                            'valido',
                            'updated_at',
                        ]
                    );
                }
                $totales['insertados_o_actualizados'] += count($rows);
            }

            $porLote[$lote] = [
                'estado' => 'OK',
                'pdf' => $pdfLote,
                'validos' => $validosLote,
            ];
        }

        krsort($porLote, SORT_NUMERIC);
        ksort($porTipo, SORT_STRING);

        $this->newLine();
        $this->info($dryRun ? 'AUDITORÍA KNG - SIN GRABAR' : 'IMPORTACIÓN KNG COMPLETADA');
        $this->table(
            ['Dato', 'Valor'],
            [
                ['Período', $periodo],
                ['Raíz', $root],
                ['Lotes solicitados', $totales['lotes_solicitados']],
                ['Lotes encontrados', $totales['lotes_existentes']],
                ['PDF encontrados', $totales['pdf']],
                ['Nombres válidos', $totales['validos']],
                ['Nombres no interpretables', $totales['nombre_no_interpretable']],
                ['Grabados', $dryRun ? '0 (dry-run)' : $totales['insertados_o_actualizados']],
            ]
        );

        $filasLotes = [];
        foreach ($porLote as $lote => $d) {
            $filasLotes[] = [$lote, $d['estado'], $d['pdf'], $d['validos']];
        }
        $this->table(['Lote', 'Estado', 'PDF', 'Válidos'], $filasLotes);

        if ($porTipo !== []) {
            $this->table(
                ['Tipo', 'Cantidad'],
                array_map(
                    static fn ($tipo, $cantidad) => [$tipo, $cantidad],
                    array_keys($porTipo),
                    array_values($porTipo)
                )
            );
        }

        return self::SUCCESS;
    }

    /** @return list<int> */
    private function parsearLotes(string $texto): array
    {
        $texto = trim($texto);
        if ($texto === '') {
            return [];
        }

        $resultado = [];
        foreach (preg_split('/\s*,\s*/', $texto) ?: [] as $parte) {
            if ($parte === '') {
                continue;
            }

            if (preg_match('/^(\d+)-(\d+)$/', $parte, $m) === 1) {
                $desde = (int) $m[1];
                $hasta = (int) $m[2];
                if ($desde <= 0 || $hasta <= 0 || $hasta < $desde || ($hasta - $desde) > 5000) {
                    continue;
                }

                for ($i = $desde; $i <= $hasta; $i++) {
                    $resultado[$i] = true;
                }
                continue;
            }

            if (ctype_digit($parte) && (int) $parte > 0) {
                $resultado[(int) $parte] = true;
            }
        }

        $lotes = array_keys($resultado);
        sort($lotes, SORT_NUMERIC);

        return array_values($lotes);
    }
}
