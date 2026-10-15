<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Copia PDFs desde KNG (sólo lectura) hacia el archivo definitivo, por lote.
 * No modifica los PDF originales y reintenta los pendientes en cada ejecución.
 */
final class KngPdfSyncService
{
    /** @return array{origen:string,destino:string} */
    public function verificarConfiguracion(): array
    {
        if (! Schema::hasTable('kng_pdfs_pendientes')) {
            throw new RuntimeException('Falta la migración kng_pdfs_pendientes. Ejecute php artisan migrate en DESARROLLO.');
        }

        $origen = rtrim((string) config('gei.kng.pdf_origen', '/archivo-kng/FacturasOrigen'), '/');
        $destino = rtrim((string) config('gei.kng.pdf_destino', '/archivo-kng/FacturasImportacion'), '/');
        if ($origen === '' || ! is_dir($origen) || ! is_readable($origen)) {
            throw new RuntimeException("No se pueden leer los PDF originales de KNG: {$origen}");
        }
        if ($destino === '' || ! is_dir($destino) || ! is_writable($destino)) {
            throw new RuntimeException("El destino de importación de PDF no es escribible: {$destino}");
        }
        if (realpath($origen) === realpath($destino)) {
            throw new RuntimeException('Origen y destino de los PDF no pueden ser el mismo directorio.');
        }
        return ['origen' => $origen, 'destino' => $destino];
    }

    /**
     * @param list<int> $cambiados Registros nuevos o modificados del DBF.
     * @param callable(array<string,mixed>):void|null $progreso
     * @return array<string,int>
     */
    public function sincronizar(array $cambiados = [], bool $todos = false, ?callable $progreso = null): array
    {
        $rutas = $this->verificarConfiguracion();
        $origen = $rutas['origen'];
        $destino = $rutas['destino'];
        // Sólo para ensayos: limita TODOS los candidatos (nuevos, recientes y
        // pendientes) al último lote. No modifica el comportamiento normal.
        $pruebaUltimoLote = (bool) config('gei.kng.pdf_prueba_ultimo_lote', false);
        if ($pruebaUltimoLote && $todos) {
            throw new RuntimeException('Desactive GEI_KNG_PDF_PRUEBA_ULTIMO_LOTE antes de usar --todos.');
        }
        $ultimoLote = $pruebaUltimoLote ? $this->ultimoLoteActivo() : null;
        $totales = [
            'copiados' => 0, 'ya_existentes' => 0, 'sin_pdf_origen' => 0,
            'conflictos' => 0, 'sin_referencia' => 0, 'lotes_creados' => 0,
            'revisados' => 0, 'pendientes_total' => 0,
            'lote_prueba' => $ultimoLote ?? 0,
        ];

        $this->emitir($progreso, 'Creando directorios de lotes...', 0, null);
        if ($ultimoLote !== null) {
            $carpeta = $destino.'/lote_'.$ultimoLote;
            if (! is_dir($carpeta)) {
                File::ensureDirectoryExists($carpeta, 0770, true);
                $totales['lotes_creados']++;
            }
        } else {
            DB::table('kng_lotes')->where('eliminado', false)
                ->select(['id_kng_lote', 'datos'])
                ->orderBy('id_kng_lote')
                ->chunkById(500, function ($rows) use ($destino, &$totales): void {
                    foreach ($rows as $row) {
                        $datos = json_decode((string) $row->datos, true);
                        $lote = is_array($datos) ? $this->soloDigitos($this->campo($datos, ['ID_LOTE', 'LOTE'])) : '';
                        if ($lote === '' || (int) $lote === 0) {
                            continue;
                        }
                        $carpeta = $destino.'/lote_'.$lote;
                        if (! is_dir($carpeta)) {
                            File::ensureDirectoryExists($carpeta, 0770, true);
                            $totales['lotes_creados']++;
                        }
                    }
                }, 'id_kng_lote');
        }

        $ids = [];
        if ($ultimoLote !== null) {
            // Escaneo en tandas con memoria limitada. A diferencia de limitar
            // a los últimos N registros, esto revisa exclusivamente UN lote,
            // incluso si hay registros cambiados o pendientes de otros lotes.
            $this->emitir($progreso, "Seleccionando facturas del lote {$ultimoLote}...", 0, null);
            DB::table('kng_facturas')->where('eliminado', false)
                ->select(['registro', 'datos'])
                ->orderBy('registro')
                ->chunkById(2000, function ($rows) use ($ultimoLote, &$ids): void {
                    foreach ($rows as $row) {
                        $datos = json_decode((string) $row->datos, true);
                        if (is_array($datos) && $this->esDelLote($datos, $ultimoLote)) {
                            $ids[(int) $row->registro] = true;
                        }
                    }
                }, 'registro');
            if ($ids === []) {
                throw new RuntimeException("El último lote {$ultimoLote} no tiene facturas vigentes para esta prueba.");
            }
        } else {
            foreach ($cambiados as $id) {
                $ids[(int) $id] = true;
            }
            // Los PDF pueden generarse después de importar la metadata.
            if ($todos) {
                DB::table('kng_facturas')->where('eliminado', false)->orderBy('registro')
                    ->select('registro')->chunk(5000, function ($rows) use (&$ids): void {
                        foreach ($rows as $row) {
                            $ids[(int) $row->registro] = true;
                        }
                    });
            } else {
                $ultimos = max(0, (int) config('gei.kng.pdf_revisar_ultimos', 5000));
                if ($ultimos > 0) {
                    foreach (DB::table('kng_facturas')->where('eliminado', false)
                        ->orderByDesc('registro')->limit($ultimos)->pluck('registro') as $id) {
                        $ids[(int) $id] = true;
                    }
                }
            }
            // Reintentar todos los pendientes anteriores en modo normal.
            foreach (DB::table('kng_pdfs_pendientes')->pluck('registro') as $id) {
                $ids[(int) $id] = true;
            }
        }
        $registros = array_keys($ids);
        sort($registros, SORT_NUMERIC);
        unset($ids);
        $cantidad = count($registros);
        $procesados = 0;
        $this->emitir($progreso, 'Buscando y copiando PDFs nuevos o pendientes...', 0, $cantidad);

        foreach (array_chunk($registros, 500) as $loteRegistros) {
            $filas = DB::table('kng_facturas')->whereIn('registro', $loteRegistros)
                ->get(['registro', 'eliminado', 'datos'])->keyBy('registro');
            foreach ($loteRegistros as $registro) {
                $procesados++;
                $fila = $filas->get($registro);
                if ($fila === null || $fila->eliminado) {
                    DB::table('kng_pdfs_pendientes')->where('registro', $registro)->delete();
                    continue;
                }
                $datos = json_decode((string) $fila->datos, true);
                $referencia = is_array($datos) ? $this->referenciaPdf($datos) : null;
                if ($referencia === null) {
                    $totales['sin_referencia']++;
                    DB::table('kng_pdfs_pendientes')->where('registro', $registro)->delete();
                    continue;
                }
                [$nombre, $lote] = $referencia;
                $carpeta = $destino.'/lote_'.$lote;
                if (! is_dir($carpeta)) {
                    File::ensureDirectoryExists($carpeta, 0770, true);
                    $totales['lotes_creados']++;
                }
                $objetivo = $carpeta.'/'.$nombre;
                // Primero Debian: si el PDF ya existe y no está vacío, jamás
                // consultar Windows/Samba para este registro.
                [$existeLocal, $fuente, $tamanoFuente] = $this->origenSoloSiFaltaLocal(
                    $objetivo, $origen, $lote, $nombre
                );
                if ($existeLocal) {
                    $totales['ya_existentes']++;
                    DB::table('kng_pdfs_pendientes')->where('registro', $registro)->delete();
                    continue;
                }
                if ($fuente === null) {
                    $totales['sin_pdf_origen']++;
                    $this->pendiente($registro, $nombre, $lote, 'NO_ENCONTRADO');
                    continue;
                }
                $temporal = $carpeta.'/.'.basename($nombre).'.tmp.'.bin2hex(random_bytes(6));
                try {
                    if (! @copy($fuente, $temporal) || filesize($temporal) !== $tamanoFuente) {
                        throw new RuntimeException("Error copiando {$nombre} del lote {$lote}");
                    }
                    if (! @rename($temporal, $objetivo)) {
                        throw new RuntimeException("Error finalizando {$nombre} del lote {$lote}");
                    }
                    $totales['copiados']++;
                    DB::table('kng_pdfs_pendientes')->where('registro', $registro)->delete();
                } catch (\Throwable $e) {
                    $totales['conflictos']++;
                    $this->pendiente($registro, $nombre, $lote, 'ERROR_COPIA');
                    report($e);
                } finally {
                    @unlink($temporal);
                }
            }
            $this->emitir($progreso, 'Copiando PDF a los lotes', $procesados, $cantidad);
        }
        $totales['revisados'] = $procesados;
        $totales['pendientes_total'] = DB::table('kng_pdfs_pendientes')->count();
        return $totales;
    }

    /**
     * Consulta el origen KNG únicamente cuando el PDF no existe (o está vacío)
     * en el repositorio local. Los originales de Windows están todos en la raíz
     * de Facturas; el subdirectorio por lote se conserva como alternativa.
     *
     * @return array{bool, ?string, ?int} [existeLocal, rutaOrigen, tamañoOrigen]
     */
    private function origenSoloSiFaltaLocal(
        string $objetivo,
        string $origen,
        string $lote,
        string $nombre
    ): array {
        if (is_file($objetivo) && filesize($objetivo) > 0) {
            return [true, null, null];
        }

        // Sólo para archivos ausentes: comprobar SMB de Windows.
        foreach ([$origen.'/'.$nombre, $origen.'/lote_'.$lote.'/'.$nombre] as $candidata) {
            if (! is_file($candidata) || ! is_readable($candidata)) {
                continue;
            }
            $tamano = filesize($candidata);
            if ($tamano !== false && $tamano > 0) {
                return [false, $candidata, $tamano];
            }
        }

        return [false, null, null];
    }

    /** El lote de número más alto en LOTES.DBF importado y no eliminado. */
    private function ultimoLoteActivo(): int
    {
        $ultimo = 0;
        DB::table('kng_lotes')->where('eliminado', false)
            ->select(['id_kng_lote', 'datos'])
            ->orderBy('id_kng_lote')
            ->chunkById(500, function ($rows) use (&$ultimo): void {
                foreach ($rows as $row) {
                    $datos = json_decode((string) $row->datos, true);
                    if (! is_array($datos)) {
                        continue;
                    }
                    $lote = $this->soloDigitos($this->campo($datos, ['ID_LOTE', 'LOTE']));
                    if ($lote !== '' && (int) $lote > $ultimo) {
                        $ultimo = (int) $lote;
                    }
                }
            }, 'id_kng_lote');
        if ($ultimo < 1) {
            throw new RuntimeException('No se pudo identificar el último lote activo en kng_lotes.');
        }
        return $ultimo;
    }

    /** @param array<string,mixed> $datos */
    private function esDelLote(array $datos, int $lote): bool
    {
        $valor = $this->soloDigitos($this->campo($datos, ['LOTE', 'ID_LOTE']));
        return $valor !== '' && (int) $valor === $lote;
    }

    /** @param array<string,mixed> $datos
     * @return array{string,string}|null
     */
    private function referenciaPdf(array $datos): ?array
    {
        $tipoRaw = strtoupper(trim((string) ($this->campo($datos, ['TIPO', 'TIPO_COMP']) ?? '')));
        $tipo = in_array($tipoRaw, ['FA', 'CA', 'FB', 'CB'], true)
            ? $tipoRaw
            : match ((int) $tipoRaw) { 1 => 'FA', 3 => 'CA', 6 => 'FB', 8 => 'CB', default => '' };
        $pv = $this->soloDigitos($this->campo($datos, ['P_VENTA', 'PVENTA']));
        $numero = $this->soloDigitos($this->campo($datos, ['ID_FACTURA', 'NRO_FACTURA']));
        $lote = $this->soloDigitos($this->campo($datos, ['LOTE', 'ID_LOTE']));
        // ID_INQ identifica el destinatario PDF; CTA_ORIG sólo es respaldo.
        $cuenta = $this->soloDigitos($this->campo($datos, ['ID_INQ']));
        if ($cuenta === '' || (int) $cuenta === 0) {
            $cuenta = $this->soloDigitos($this->campo($datos, ['CTA_ORIG']));
        }
        if ($tipo === '' || $pv === '' || $numero === '' || $lote === '' || $cuenta === ''
            || strlen($pv) > 4 || strlen($numero) > 8 || strlen($cuenta) > 11
            || (int) $pv === 0 || (int) $numero === 0 || (int) $cuenta === 0 || (int) $lote === 0) {
            return null;
        }
        return [sprintf('%s-%04d-%08d-%011d.pdf', $tipo, (int) $pv, (int) $numero, (int) $cuenta), $lote];
    }

    /** @param array<string,mixed> $datos */
    private function campo(array $datos, array $nombres): mixed
    {
        foreach ($nombres as $nombre) {
            foreach ($datos as $clave => $valor) {
                if (strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', (string) $clave))
                    === strtoupper((string) preg_replace('/[^A-Z0-9]/i', '', (string) $nombre))
                    && $valor !== null && $valor !== '') {
                    return $valor;
                }
            }
        }
        return null;
    }

    private function soloDigitos(mixed $valor): string
    {
        $numero = preg_replace('/\D+/', '', (string) ($valor ?? '')) ?? '';
        return ltrim($numero, '0');
    }

    private function pendiente(int $registro, string $nombre, string $lote, string $estado): void
    {
        DB::table('kng_pdfs_pendientes')->updateOrInsert(
            ['registro' => $registro],
            ['nombre_pdf' => $nombre, 'lote' => $lote, 'estado' => $estado, 'updated_at' => now()]
        );
    }

    /** @param callable(array<string,mixed>):void|null $progreso */
    private function emitir(?callable $progreso, string $detalle, int $procesados, ?int $total): void
    {
        if ($progreso === null) {
            return;
        }
        $progreso([
            'estado' => 'PROCESANDO', 'etapa' => 'ORGANIZANDO_PDFS',
            'detalle' => $detalle, 'procesados' => $procesados, 'total' => $total,
            'porcentaje' => $total === null || $total === 0 ? 0 : round($procesados * 100 / $total, 2),
        ]);
    }
}
