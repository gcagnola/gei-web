<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\Process\Process;

final class KngDbfImportService
{
    /** @return array<string, mixed> */
    public function estado(): array
    {
        $root = rtrim((string) config('gei.kng.root'), DIRECTORY_SEPARATOR);
        $facturas = $root.DIRECTORY_SEPARATOR.(string) config('gei.kng.facturas_dbf', 'facturas.DBF');
        $lotes = $root.DIRECTORY_SEPARATOR.(string) config('gei.kng.lotes_dbf', 'LOTES.DBF');
        $pdf = (string) config('gei.kng.pdf_destino', '/archivo-kng/FacturasImportacion');
        $origenPdf = (string) config('gei.kng.pdf_origen', '/archivo-kng/FacturasOrigen');

        $ultima = Schema::hasTable('kng_importaciones')
            ? DB::table('kng_importaciones')->orderByDesc('id_kng_importacion')->first()
            : null;

        if ($ultima) {
            $ultima->facturas_metadata_array = json_decode((string) ($ultima->facturas_metadata ?? ''), true) ?: [];
            $ultima->lotes_metadata_array = json_decode((string) ($ultima->lotes_metadata ?? ''), true) ?: [];
        }

        return [
            'root' => $root,
            'facturas' => $this->estadoArchivo($facturas),
            'lotes' => $this->estadoArchivo($lotes),
            'pdf_origen' => [
                'ruta' => $origenPdf,
                'existe' => is_dir($origenPdf),
                'legible' => is_dir($origenPdf) && is_readable($origenPdf),
            ],
            'pdf_dir' => [
                'ruta' => $pdf,
                'existe' => is_dir($pdf),
                'legible' => is_dir($pdf) && is_readable($pdf),
                'escribible' => is_dir($pdf) && is_writable($pdf),
            ],
            'ultima' => $ultima,
        ];
    }


    /** @return array<string, mixed> */
    public function progreso(): array
    {
        $ruta = $this->rutaProgreso();
        if (! is_file($ruta)) {
            return [
                'estado' => 'IDLE',
                'etapa' => 'PREPARANDO',
                'detalle' => 'Esperando una importación KNG.',
                'porcentaje' => 0,
                'procesados' => null,
                'total' => null,
            ];
        }

        try {
            $datos = json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
            return is_array($datos) ? $datos : [];
        } catch (\Throwable) {
            return [
                'estado' => 'PROCESANDO',
                'etapa' => 'PREPARANDO',
                'detalle' => 'Actualizando información de progreso.',
                'porcentaje' => null,
            ];
        }
    }

    /** @return array<string, mixed> */
    public function importar(): array
    {
        $lockPath = storage_path('app/private/kng-import/importacion.lock');
        File::ensureDirectoryExists(dirname($lockPath));
        $lock = fopen($lockPath, 'c');
        if ($lock === false || ! flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new RuntimeException('Ya se está ejecutando una importación KNG. Espere a que finalice.');
        }

        $root = rtrim((string) config('gei.kng.root'), DIRECTORY_SEPARATOR);
        $tmpDir = '';

        try {
            $this->guardarProgreso([
                'estado' => 'PROCESANDO',
                'etapa' => 'PREPARANDO',
                'detalle' => 'Preparando importación KNG...',
                'procesados' => 0,
                'total' => null,
                'porcentaje' => 0,
            ]);
            // No iniciar la importación si falta acceso al origen o al destino de PDF.
            app(KngPdfSyncService::class)->verificarConfiguracion();
            if ($root === '' || ! is_dir($root) || ! is_readable($root)) {
                throw new RuntimeException("No se puede leer GEI_KNG_ROOT: {$root}");
            }

            $sources = [
                'facturas' => $root.DIRECTORY_SEPARATOR.(string) config('gei.kng.facturas_dbf', 'facturas.DBF'),
                'lotes' => $root.DIRECTORY_SEPARATOR.(string) config('gei.kng.lotes_dbf', 'LOTES.DBF'),
            ];

            foreach ($sources as $nombre => $ruta) {
                if (! is_file($ruta) || ! is_readable($ruta)) {
                    throw new RuntimeException("No se puede leer {$nombre}: {$ruta}");
                }
            }

            $tmpDir = storage_path('app/private/kng-import/'.date('Ymd_His').'_'.bin2hex(random_bytes(3)));
            File::ensureDirectoryExists($tmpDir);

            $exportados = [];
            foreach ($sources as $nombre => $ruta) {
                $exportados[$nombre] = $this->exportarDbf($nombre, $ruta, $tmpDir);
            }

            $resultado = DB::transaction(function () use ($exportados): array {
                $this->guardarProgreso([
                    'estado' => 'PROCESANDO',
                    'etapa' => 'PREPARANDO_BASE',
                    'detalle' => 'Preparando tablas locales...',
                    'procesados' => 0,
                    'total' => null,
                    'porcentaje' => 0,
                ]);

                $facturasTotal = (int) ($exportados['facturas']['metadata']['records_exported'] ?? 0);
                $lotesTotal = (int) ($exportados['lotes']['metadata']['records_exported'] ?? 0);

                $facturasImportadas = $this->cargarJsonl(
                    'kng_facturas',
                    $exportados['facturas']['jsonl'],
                    'CARGANDO_FACTURAS',
                    'Importando FACTURAS.DBF a PostgreSQL',
                    $facturasTotal
                );
                $lotesImportados = $this->cargarJsonl(
                    'kng_lotes',
                    $exportados['lotes']['jsonl'],
                    'CARGANDO_LOTES',
                    'Importando LOTES.DBF a PostgreSQL',
                    $lotesTotal
                );

                $ahora = now();
                $id = DB::table('kng_importaciones')->insertGetId([
                    'estado' => 'OK',
                    'facturas_registros' => $facturasImportadas['total'],
                    'lotes_registros' => $lotesImportados['total'],
                    'facturas_metadata' => json_encode($exportados['facturas']['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'lotes_metadata' => json_encode($exportados['lotes']['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'iniciado_at' => $ahora,
                    'finalizado_at' => $ahora,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ], 'id_kng_importacion');

                return [
                    'id' => $id,
                    'facturas' => $facturasImportadas['total'],
                    'lotes' => $lotesImportados['total'],
                    'facturas_nuevas' => $facturasImportadas['nuevos'],
                    'facturas_actualizadas' => $facturasImportadas['actualizados'],
                    'lotes_nuevos' => $lotesImportados['nuevos'],
                    'lotes_actualizados' => $lotesImportados['actualizados'],
                    'registros_cambiados' => $facturasImportadas['cambiados'],
                ];
            });

            $extra = [
                'facturas_campos' => count($exportados['facturas']['metadata']['fields'] ?? []),
                'lotes_campos' => count($exportados['lotes']['metadata']['fields'] ?? []),
            ];

            $extra['pdfs'] = app(KngPdfSyncService::class)->sincronizar(
                $resultado['registros_cambiados'],
                false,
                function (array $progreso): void { $this->guardarProgreso($progreso); }
            );
            unset($resultado['registros_cambiados']);
            $metadata = $exportados['facturas']['metadata'];
            $metadata['pdfs'] = $extra['pdfs'];
            DB::table('kng_importaciones')->where('id_kng_importacion', $resultado['id'])->update([
                'estado' => 'OK', 'finalizado_at' => now(), 'updated_at' => now(),
                'facturas_metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $this->guardarProgreso([
                'estado' => 'COMPLETO',
                'etapa' => 'COMPLETO',
                'detalle' => 'Importación KNG completada.',
                'procesados' => null,
                'total' => null,
                'porcentaje' => 100,
            ]);

            return $resultado + $extra;
        } catch (\Throwable $e) {
            $this->guardarProgreso([
                'estado' => 'ERROR',
                'etapa' => 'ERROR',
                'detalle' => $e->getMessage(),
                'porcentaje' => null,
            ]);
            throw $e;
        } finally {
            if ($tmpDir !== '') {
                File::deleteDirectory($tmpDir);
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }


    /** @return array<string, mixed> */
    private function exportarDbf(string $nombre, string $ruta, string $tmpDir): array
    {
        $python = (string) config('gei.kng.python', config('gei.importador.python_bin', '/usr/bin/python3'));
        $script = (string) config('gei.kng.export_script', base_path('python/kng_dbf_export.py'));
        $jsonl = $tmpDir.'/'.$nombre.'.jsonl';
        $metadataFile = $tmpDir.'/'.$nombre.'.metadata.json';

        $process = new Process([
            $python,
            $script,
            '--input', $ruta,
            '--output', $jsonl,
            '--metadata', $metadataFile,
            '--encoding', (string) config('gei.kng.encoding', 'cp1252'),
            '--progress-file', $this->rutaProgreso(),
            '--stage', 'EXPORTANDO_'.strtoupper($nombre),
        ]);
        $process->setTimeout((float) config('gei.kng.timeout', 600));
        $process->run();

        if (! $process->isSuccessful()) {
            $detalle = trim($process->getErrorOutput().' '.$process->getOutput());
            throw new RuntimeException("No se pudo leer {$nombre}.DBF: {$detalle}");
        }

        if (! is_file($jsonl) || ! is_file($metadataFile)) {
            throw new RuntimeException("La exportación de {$nombre}.DBF no generó los archivos temporales esperados.");
        }

        $metadata = json_decode((string) file_get_contents($metadataFile), true);
        if (! is_array($metadata)) {
            throw new RuntimeException("Metadata inválida para {$nombre}.DBF.");
        }

        return [
            'jsonl' => $jsonl,
            'metadata' => $metadata,
        ];
    }

    /**
     * Mantiene el identificador local de los registros ya importados.
     * Los DBF se exportan completos para detectar también modificaciones;
     * solamente los registros nuevos o modificados se escriben en PostgreSQL.
     *
     * @return array{total:int,nuevos:int,actualizados:int,cambiados:list<int>}
     */
    private function cargarJsonl(string $tabla, string $archivo, string $etapa, string $detalle, int $esperados): array
    {
        $fh = fopen($archivo, 'rb');
        if ($fh === false) {
            throw new RuntimeException("No se pudo abrir temporal: {$archivo}");
        }

        $chunk = [];
        $total = 0;
        $nuevos = 0;
        $actualizados = 0;
        $cambiados = [];
        $ahora = now();

        $this->guardarProgreso([
            'estado' => 'PROCESANDO', 'etapa' => $etapa, 'detalle' => $detalle,
            'procesados' => 0, 'total' => $esperados, 'porcentaje' => 0,
        ]);

        try {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $row = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($row) || ! isset($row['registro'], $row['datos']) || ! is_array($row['datos'])) {
                    throw new RuntimeException("Registro JSONL inválido en {$archivo}.");
                }
                $registro = (int) $row['registro'];
                if ($registro < 0) {
                    throw new RuntimeException("Número de registro DBF inválido en {$archivo}.");
                }
                if (isset($chunk[$registro])) {
                    throw new RuntimeException("Número de registro DBF duplicado: {$registro} en {$archivo}.");
                }
                $chunk[$registro] = [
                    'registro' => $registro,
                    'eliminado' => (bool) ($row['eliminado'] ?? false),
                    'datos_array' => $row['datos'],
                    'datos' => json_encode($row['datos'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                    'texto_busqueda' => $this->textoBusqueda($row['datos']),
                ];

                if (count($chunk) >= 500) {
                    [$ins, $upd, $ids] = $this->guardarLoteIncremental($tabla, $chunk, $ahora);
                    $nuevos += $ins;
                    $actualizados += $upd;
                    if ($tabla === 'kng_facturas') {
                        array_push($cambiados, ...$ids);
                    }
                    $total += count($chunk);
                    $chunk = [];
                    $this->guardarProgreso([
                        'estado' => 'PROCESANDO', 'etapa' => $etapa, 'detalle' => $detalle,
                        'procesados' => $total, 'total' => $esperados,
                        'porcentaje' => $esperados > 0 ? round($total * 100 / $esperados, 2) : 100,
                    ]);
                }
            }

            if ($chunk !== []) {
                [$ins, $upd, $ids] = $this->guardarLoteIncremental($tabla, $chunk, $ahora);
                $nuevos += $ins;
                $actualizados += $upd;
                if ($tabla === 'kng_facturas') {
                    array_push($cambiados, ...$ids);
                }
                $total += count($chunk);
                $this->guardarProgreso([
                    'estado' => 'PROCESANDO', 'etapa' => $etapa, 'detalle' => $detalle,
                    'procesados' => $total, 'total' => $esperados,
                    'porcentaje' => $esperados > 0 ? round($total * 100 / $esperados, 2) : 100,
                ]);
            }
        } finally {
            fclose($fh);
        }

        if ($esperados !== $total) {
            throw new RuntimeException("Cantidad de registros inconsistente en {$archivo}: exportados {$esperados}, procesados {$total}.");
        }

        return ['total' => $total, 'nuevos' => $nuevos, 'actualizados' => $actualizados, 'cambiados' => $cambiados];
    }

    /** @param array<int,array<string,mixed>> $chunk
     * @return array{int,int,list<int>}
     */
    private function guardarLoteIncremental(string $tabla, array $chunk, mixed $ahora): array
    {
        $enBase = DB::table($tabla)->whereIn('registro', array_keys($chunk))
            ->get(['registro', 'datos', 'eliminado'])->keyBy('registro');
        if ($enBase->count() !== DB::table($tabla)->whereIn('registro', array_keys($chunk))->count()) {
            throw new RuntimeException("Hay registros duplicados por número de DBF en {$tabla}. No se actualizó esa tanda.");
        }

        $insertar = [];
        $actualizados = 0;
        $cambiados = [];
        foreach ($chunk as $registro => $row) {
            $anterior = $enBase->get($registro);
            if ($anterior === null) {
                $insertar[] = [
                    'registro' => $registro, 'eliminado' => $row['eliminado'],
                    'datos' => $row['datos'], 'texto_busqueda' => $row['texto_busqueda'],
                    'importado_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora,
                ];
                $cambiados[] = $registro;
                continue;
            }
            $datosAnteriores = json_decode((string) $anterior->datos, true);
            if ((bool) $anterior->eliminado === $row['eliminado'] && $datosAnteriores == $row['datos_array']) {
                continue;
            }
            DB::table($tabla)->where('registro', $registro)->update([
                'eliminado' => $row['eliminado'], 'datos' => $row['datos'],
                'texto_busqueda' => $row['texto_busqueda'],
                'importado_at' => $ahora, 'updated_at' => $ahora,
            ]);
            $actualizados++;
            $cambiados[] = $registro;
        }
        if ($insertar !== []) {
            DB::table($tabla)->insert($insertar);
        }
        return [count($insertar), $actualizados, $cambiados];
    }

    /** @param array<string, mixed> $cambios */
    private function guardarProgreso(array $cambios): void
    {
        $ruta = $this->rutaProgreso();
        File::ensureDirectoryExists(dirname($ruta));

        $actual = [];
        if (is_file($ruta)) {
            try {
                $leido = json_decode((string) file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($leido)) {
                    $actual = $leido;
                }
            } catch (\Throwable) {
                $actual = [];
            }
        }

        $datos = array_merge($actual, $cambios, [
            'actualizado_at' => now()->toIso8601String(),
        ]);

        $tmp = $ruta.'.tmp';
        file_put_contents($tmp, json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @rename($tmp, $ruta);
    }

    private function rutaProgreso(): string
    {
        return storage_path('app/private/kng-import/progreso.json');
    }

    /** @param array<string, mixed> $datos */
    private function textoBusqueda(array $datos): string
    {
        $partes = [];
        foreach ($datos as $campo => $valor) {
            if ($valor === null || $valor === '') {
                continue;
            }
            if (is_bool($valor)) {
                $valor = $valor ? 'true' : 'false';
            } elseif (! is_scalar($valor)) {
                continue;
            }
            $partes[] = $campo;
            $partes[] = (string) $valor;
        }

        return mb_substr(implode(' ', $partes), 0, 12000);
    }

    /** @return array<string, mixed> */
    private function estadoArchivo(string $ruta): array
    {
        $existe = is_file($ruta);

        return [
            'ruta' => $ruta,
            'existe' => $existe,
            'legible' => $existe && is_readable($ruta),
            'tamano' => $existe ? (filesize($ruta) ?: 0) : 0,
            'modificado' => $existe ? date('d/m/Y H:i:s', filemtime($ruta) ?: time()) : null,
        ];
    }
}
