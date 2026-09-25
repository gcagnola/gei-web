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
        $pdf = $root.DIRECTORY_SEPARATOR.(string) config('gei.kng.facturas_dir', 'Facturas');

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
    public function importar(bool $organizarPdfs = false): array
    {
        $this->guardarProgreso([
            'estado' => 'PROCESANDO',
            'etapa' => 'PREPARANDO',
            'detalle' => 'Preparando importación KNG...',
            'procesados' => 0,
            'total' => null,
            'porcentaje' => 0,
        ]);

        $root = rtrim((string) config('gei.kng.root'), DIRECTORY_SEPARATOR);
        $tmpDir = '';

        try {
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

                DB::statement('TRUNCATE TABLE kng_facturas RESTART IDENTITY');
                DB::statement('TRUNCATE TABLE kng_lotes RESTART IDENTITY');

                $facturasTotal = (int) ($exportados['facturas']['metadata']['records_exported'] ?? 0);
                $lotesTotal = (int) ($exportados['lotes']['metadata']['records_exported'] ?? 0);

                $facturas = $this->cargarJsonl(
                    'kng_facturas',
                    $exportados['facturas']['jsonl'],
                    'CARGANDO_FACTURAS',
                    'Importando FACTURAS.DBF a PostgreSQL',
                    $facturasTotal
                );
                $lotes = $this->cargarJsonl(
                    'kng_lotes',
                    $exportados['lotes']['jsonl'],
                    'CARGANDO_LOTES',
                    'Importando LOTES.DBF a PostgreSQL',
                    $lotesTotal
                );

                $ahora = now();
                $id = DB::table('kng_importaciones')->insertGetId([
                    'estado' => 'OK',
                    'facturas_registros' => $facturas,
                    'lotes_registros' => $lotes,
                    'facturas_metadata' => json_encode($exportados['facturas']['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'lotes_metadata' => json_encode($exportados['lotes']['metadata'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'iniciado_at' => $ahora,
                    'finalizado_at' => $ahora,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ], 'id_kng_importacion');

                return [
                    'id' => $id,
                    'facturas' => $facturas,
                    'lotes' => $lotes,
                ];
            });

            $extra = [
                'facturas_campos' => count($exportados['facturas']['metadata']['fields'] ?? []),
                'lotes_campos' => count($exportados['lotes']['metadata']['fields'] ?? []),
            ];

            if ($organizarPdfs) {
                $extra['pdfs'] = $this->organizarPdfsPorLote($root);
            }

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
        }
    }


    /** @return array<string, int> */
    private function organizarPdfsPorLote(string $root): array
    {
        $pdfDir = $root.DIRECTORY_SEPARATOR.(string) config('gei.kng.facturas_dir', 'Facturas');
        if (! is_dir($pdfDir) || ! is_readable($pdfDir) || ! is_writable($pdfDir)) {
            throw new RuntimeException("La carpeta Facturas no tiene lectura/escritura: {$pdfDir}");
        }

        $this->guardarProgreso([
            'estado' => 'PROCESANDO',
            'etapa' => 'ORGANIZANDO_PDFS',
            'detalle' => 'Preparando relación de comprobantes y lotes...',
            'procesados' => 0,
            'total' => null,
            'porcentaje' => 0,
        ]);

        $mapa = $this->mapaFacturasPorComprobante();
        $totales = [
            'movidos' => 0,
            'ya_ubicados' => 0,
            'sin_correspondencia' => 0,
            'conflictos' => 0,
            'ignorados' => 0,
        ];

        $nombres = scandir($pdfDir, SCANDIR_SORT_NONE);
        if (! is_array($nombres)) {
            throw new RuntimeException("No se pudo listar la carpeta Facturas: {$pdfDir}");
        }

        $archivos = array_values(array_filter($nombres, function (string $nombre) use ($pdfDir): bool {
            return $nombre !== '.' && $nombre !== '..' && is_file($pdfDir.DIRECTORY_SEPARATOR.$nombre);
        }));
        $cantidadArchivos = count($archivos);
        $procesadosArchivos = 0;

        $this->guardarProgreso([
            'estado' => 'PROCESANDO',
            'etapa' => 'ORGANIZANDO_PDFS',
            'detalle' => 'Organizando PDFs por lote',
            'procesados' => 0,
            'total' => $cantidadArchivos,
            'porcentaje' => 0,
        ]);

        foreach ($archivos as $nombre) {
            if ($nombre === '.' || $nombre === '..') {
                continue;
            }

            $origen = $pdfDir.DIRECTORY_SEPARATOR.$nombre;
            $procesadosArchivos++;
            if ($procesadosArchivos === 1 || $procesadosArchivos % 100 === 0 || $procesadosArchivos === $cantidadArchivos) {
                $this->guardarProgreso([
                    'estado' => 'PROCESANDO',
                    'etapa' => 'ORGANIZANDO_PDFS',
                    'detalle' => 'Organizando PDFs por lote',
                    'procesados' => $procesadosArchivos,
                    'total' => $cantidadArchivos,
                    'porcentaje' => $cantidadArchivos > 0 ? round($procesadosArchivos * 100 / $cantidadArchivos, 2) : 100,
                ]);
            }

            if (preg_match('/^([A-Z]{2})-(\d{4})-(\d{8})-(\d{11})\.pdf$/i', $nombre, $m) !== 1) {
                $totales['ignorados']++;
                continue;
            }

            $tipo = strtoupper($m[1]);
            $pv = $this->normalizarNumero($m[2]);
            $numero = $this->normalizarNumero($m[3]);
            $cuenta = $this->normalizarNumero($m[4]);
            $claveTipo = $tipo.'|'.$pv.'|'.$numero.'|'.$cuenta;
            $claveBase = $pv.'|'.$numero.'|'.$cuenta;

            $lotes = $mapa['con_tipo'][$claveTipo] ?? $mapa['sin_tipo'][$claveBase] ?? [];
            $lotes = array_values(array_unique(array_filter(array_map('strval', $lotes), static fn ($v) => $v !== '' && $v !== '0')));

            if (count($lotes) !== 1) {
                $totales['sin_correspondencia']++;
                continue;
            }

            $lote = preg_replace('/\D+/', '', $lotes[0]) ?: '';
            if ($lote === '') {
                $totales['sin_correspondencia']++;
                continue;
            }

            $destinoDir = $pdfDir.DIRECTORY_SEPARATOR.'lote_'.$lote;
            File::ensureDirectoryExists($destinoDir);
            $destino = $destinoDir.DIRECTORY_SEPARATOR.$nombre;

            if (is_file($destino)) {
                $mismoTamano = @filesize($origen) === @filesize($destino);
                if ($mismoTamano) {
                    $totales['ya_ubicados']++;
                } else {
                    $totales['conflictos']++;
                }
                continue;
            }

            if (! @rename($origen, $destino)) {
                $totales['conflictos']++;
                continue;
            }

            $totales['movidos']++;
        }

        return $totales;
    }

    /** @return array{con_tipo: array<string, array<int, string>>, sin_tipo: array<string, array<int, string>>} */
    private function mapaFacturasPorComprobante(): array
    {
        $mapa = ['con_tipo' => [], 'sin_tipo' => []];

        DB::table('kng_facturas')
            ->where('eliminado', false)
            ->select(['id_kng_factura', 'datos'])
            ->orderBy('id_kng_factura')
            ->chunkById(1000, function ($rows) use (&$mapa): void {
                foreach ($rows as $row) {
                    $datos = json_decode((string) $row->datos, true);
                    if (! is_array($datos)) {
                        continue;
                    }

                    $pv = $this->valorCampo($datos, ['P_VENTA', 'PVENTA', 'PV', 'PTO_VTA', 'PUNTO_VENTA']);
                    $numero = $this->valorCampo($datos, ['ID_FACTURA', 'FACTURA', 'NRO_FACTURA', 'NUMERO', 'NRO_COMPROB']);
                    $lote = $this->valorCampo($datos, ['LOTE', 'ID_LOTE']);
                    $tipo = $this->valorCampo($datos, ['TIPO', 'TIPO_COMP', 'COMPROBANTE']);

                    if ($pv === null || $numero === null || $lote === null) {
                        continue;
                    }

                    $cuentas = [];
                    foreach (['ID_INQ', 'CTA_ORIG', 'CUENTA', 'NRO_CUENTA'] as $campo) {
                        $v = $this->valorCampo($datos, [$campo]);
                        if ($v !== null && $this->normalizarNumero((string) $v) !== '0') {
                            $cuentas[] = (string) $v;
                        }
                    }
                    $cuentas = array_values(array_unique($cuentas));
                    if ($cuentas === []) {
                        continue;
                    }

                    $pvN = $this->normalizarNumero((string) $pv);
                    $numN = $this->normalizarNumero((string) $numero);
                    $tipoN = strtoupper(trim((string) ($tipo ?? '')));
                    $loteN = trim((string) $lote);

                    foreach ($cuentas as $cuenta) {
                        $ctaN = $this->normalizarNumero($cuenta);
                        $claveBase = $pvN.'|'.$numN.'|'.$ctaN;
                        $mapa['sin_tipo'][$claveBase][] = $loteN;
                        if ($tipoN !== '') {
                            $mapa['con_tipo'][$tipoN.'|'.$claveBase][] = $loteN;
                        }
                    }
                }
            }, 'id_kng_factura');

        return $mapa;
    }

    /** @param array<string, mixed> $datos */
    private function valorCampo(array $datos, array $candidatos): mixed
    {
        $normalizados = [];
        foreach ($datos as $k => $v) {
            $normalizados[$this->normalizarNombreCampo((string) $k)] = $v;
        }

        foreach ($candidatos as $candidato) {
            $key = $this->normalizarNombreCampo((string) $candidato);
            if (array_key_exists($key, $normalizados) && $normalizados[$key] !== '' && $normalizados[$key] !== null) {
                return $normalizados[$key];
            }
        }

        return null;
    }

    private function normalizarNombreCampo(string $campo): string
    {
        return strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', $campo));
    }

    private function normalizarNumero(string $valor): string
    {
        $solo = preg_replace('/\D+/', '', $valor) ?? '';
        $solo = ltrim($solo, '0');
        return $solo === '' ? '0' : $solo;
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

    private function cargarJsonl(string $tabla, string $archivo, string $etapa, string $detalle, int $esperados): int
    {
        $fh = fopen($archivo, 'rb');
        if ($fh === false) {
            throw new RuntimeException("No se pudo abrir temporal: {$archivo}");
        }

        $chunk = [];
        $total = 0;
        $ahora = now();

        $this->guardarProgreso([
            'estado' => 'PROCESANDO',
            'etapa' => $etapa,
            'detalle' => $detalle,
            'procesados' => 0,
            'total' => $esperados,
            'porcentaje' => 0,
        ]);

        try {
            while (($line = fgets($fh)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $row = json_decode($line, true);
                if (! is_array($row) || ! isset($row['registro']) || ! isset($row['datos']) || ! is_array($row['datos'])) {
                    throw new RuntimeException("Registro JSONL inválido en {$archivo}.");
                }

                $texto = $this->textoBusqueda($row['datos']);
                $chunk[] = [
                    'registro' => (int) $row['registro'],
                    'eliminado' => (bool) ($row['eliminado'] ?? false),
                    'datos' => json_encode($row['datos'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'texto_busqueda' => $texto,
                    'importado_at' => $ahora,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ];

                if (count($chunk) >= 500) {
                    DB::table($tabla)->insert($chunk);
                    $total += count($chunk);
                    $chunk = [];
                    $this->guardarProgreso([
                        'estado' => 'PROCESANDO',
                        'etapa' => $etapa,
                        'detalle' => $detalle,
                        'procesados' => $total,
                        'total' => $esperados,
                        'porcentaje' => $esperados > 0 ? round($total * 100 / $esperados, 2) : 0,
                    ]);
                }
            }

            if ($chunk !== []) {
                DB::table($tabla)->insert($chunk);
                $total += count($chunk);
                $this->guardarProgreso([
                    'estado' => 'PROCESANDO',
                    'etapa' => $etapa,
                    'detalle' => $detalle,
                    'procesados' => $total,
                    'total' => $esperados,
                    'porcentaje' => $esperados > 0 ? round($total * 100 / $esperados, 2) : 100,
                ]);
            }
        } finally {
            fclose($fh);
        }

        return $total;
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
