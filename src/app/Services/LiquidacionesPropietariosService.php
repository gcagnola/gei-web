<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use JsonException;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

final class LiquidacionesPropietariosService
{
    private const ARCHIVOS = [
        'liquida.sf.txt',
        'liquidb.sf.txt',
        'liquida.st.txt',
        'liquidb.st.txt',
        'pliqloc.sf.txt',
        'pliqloc.st.txt',
    ];

    /** @return array<string, mixed> */
    public function procesar(string $periodo, ?int $numeroInicial = null): array
    {
        $this->validarPeriodo($periodo);
        $this->validarEsquema();
        $directorio = $this->directorioPeriodo($periodo);
        $this->validarArchivos($directorio);

        $timeout = (int) config('gei.liquidaciones_propietarios.timeout', 1800);
        $lock = Cache::store((string) config('gei.liquidaciones_propietarios.lock_store', 'file'))
            ->lock('gei:liquidaciones-propietarios', $timeout + 60);

        if (! $lock->get()) {
            throw new RuntimeException('Ya hay un período de propietarios en proceso.');
        }

        $procesoId = DB::table('liquidaciones_propietarios_procesos')->insertGetId([
            'periodo' => $periodo,
            'estado' => 'PROCESANDO',
            'lote_hash' => $this->hashLote($directorio),
            'iniciado_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            $resultadoImportacion = $this->importar($periodo, $directorio, $numeroInicial, $timeout);
            $resultadoRepartos = app(SincronizacionRepartosPropietariosService::class)
                ->sincronizar($periodo, true);
            $resultadoPdf = $this->generarPdf($periodo, $timeout);
            $resultado = [
                'periodo' => $periodo,
                ...$resultadoImportacion,
                'repartos' => $resultadoRepartos,
                ...$resultadoPdf,
            ];

            DB::table('liquidaciones_propietarios_procesos')
                ->where('id', $procesoId)
                ->update([
                    'estado' => 'FINALIZADO',
                    'detectadas' => $resultado['detectadas'],
                    'insertadas' => $resultado['insertadas'],
                    'actualizadas' => $resultado['actualizadas'],
                    'omitidas' => $resultado['omitidas'],
                    'pdf_generados' => $resultado['pdf_generados'],
                    'errores' => 0,
                    'resultado' => json_encode($resultado, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    'finalizado_at' => now(),
                    'updated_at' => now(),
                ]);

            return $resultado;
        } catch (Throwable $error) {
            DB::table('liquidaciones_propietarios_procesos')
                ->where('id', $procesoId)
                ->update([
                    'estado' => 'ERROR',
                    'errores' => 1,
                    'mensaje_error' => $error->getMessage(),
                    'finalizado_at' => now(),
                    'updated_at' => now(),
                ]);
            throw $error;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, int> */
    private function importar(
        string $periodo,
        string $directorio,
        ?int $numeroInicial,
        int $timeout
    ): array {
        $temporal = storage_path("app/private/liquidaciones/tmp/propietarios_{$periodo}_".uniqid('', true).'.jsonl');
        File::ensureDirectoryExists(dirname($temporal));

        try {
            $process = new Process([
                $this->python(),
                $this->script(),
                'extraer',
                '--directorio',
                $directorio,
                '--periodo',
                $periodo,
                '--salida',
                $temporal,
            ], base_path());
            $process->setTimeout($timeout);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException($this->errorProceso($process, 'No se pudieron interpretar las liquidaciones.'));
            }

            return DB::transaction(function () use ($temporal, $numeroInicial, $periodo): array {
                $archivo = fopen($temporal, 'rb');
                if ($archivo === false) {
                    throw new RuntimeException("No se pudo leer el temporal {$temporal}.");
                }

                $resultado = ['detectadas' => 0, 'insertadas' => 0, 'actualizadas' => 0, 'omitidas' => 0];
                $siguiente = $this->siguienteNumero($periodo, $numeroInicial);

                try {
                    while (($linea = fgets($archivo)) !== false) {
                        if (trim($linea) === '') {
                            continue;
                        }
                        $data = json_decode($linea, true, 512, JSON_THROW_ON_ERROR);
                        $resultado['detectadas']++;
                        $existente = DB::table('liquidaciones_propietarios')
                            ->where('clave_origen', $data['clave_origen'])
                            ->lockForUpdate()
                            ->first();
                        $contenidoHash = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                        $relacion = $this->resolverCuenta((string) $data['cuenta_normalizada']);

                        if ($existente !== null && hash_equals((string) $existente->contenido_hash, $contenidoHash)) {
                            if ((int) ($existente->cliente_id ?? 0) !== (int) ($relacion['cliente_id'] ?? 0)
                                || (int) ($existente->cuenta_corriente_id ?? 0) !== (int) ($relacion['cuenta_corriente_id'] ?? 0)) {
                                DB::table('liquidaciones_propietarios')
                                    ->where('id', $existente->id)
                                    ->update([...$relacion, 'updated_at' => now()]);
                                $resultado['actualizadas']++;
                                continue;
                            }
                            $resultado['omitidas']++;
                            continue;
                        }

                        $cabecera = $this->cabecera($data, $contenidoHash, $relacion);

                        if ($existente === null) {
                            $cabecera['numero_interno'] = $siguiente++;
                            $cabecera['created_at'] = now();
                            $cabecera['updated_at'] = now();
                            $liquidacionId = DB::table('liquidaciones_propietarios')->insertGetId($cabecera);
                            $resultado['insertadas']++;
                        } else {
                            $liquidacionId = (int) $existente->id;
                            $cabecera['estado'] = 'IMPORTADA';
                            $cabecera['pdf_ruta'] = null;
                            $cabecera['pdf_bytes'] = null;
                            $cabecera['pdf_generado_at'] = null;
                            $cabecera['updated_at'] = now();
                            DB::table('liquidaciones_propietarios')->where('id', $liquidacionId)->update($cabecera);
                            DB::table('liquidaciones_propietarios_items')
                                ->where('liquidacion_propietario_id', $liquidacionId)
                                ->delete();
                            $resultado['actualizadas']++;
                        }

                        $this->insertarItems($liquidacionId, $data['items'] ?? []);
                    }
                } finally {
                    fclose($archivo);
                }

                return $resultado;
            }, 3);
        } catch (JsonException $error) {
            throw new RuntimeException('El motor devolvió una liquidación JSON inválida.', 0, $error);
        } finally {
            File::delete($temporal);
        }
    }

    /** @return array{pdf_generados: int} */
    private function generarPdf(string $periodo, int $timeout): array
    {
        $baseSalida = Storage::disk('liquidaciones')->path('');
        $entrada = storage_path("app/private/liquidaciones/tmp/pdf_{$periodo}_".uniqid('', true).'.jsonl');
        $resultadoPath = storage_path("app/private/liquidaciones/tmp/pdf_{$periodo}_".uniqid('', true).'.json');
        File::ensureDirectoryExists(dirname($entrada));
        File::ensureDirectoryExists($baseSalida);

        $archivo = fopen($entrada, 'wb');
        if ($archivo === false) {
            throw new RuntimeException('No se pudo crear la entrada temporal para PDF.');
        }

        try {
            foreach (DB::table('liquidaciones_propietarios')->where('periodo', $periodo)->orderBy('numero_interno')->cursor() as $liquidacion) {
                $payload = (array) $liquidacion;
                // PostgreSQL conserva la fecha como DATE (AAAA-MM-DD), pero el
                // diseño histórico de la liquidación la imprime como DD/MM/AAAA.
                $payload['fecha'] = CarbonImmutable::parse((string) $liquidacion->fecha)->format('d/m/Y');
                $payload['pdf_ruta'] = sprintf(
                    '%s/%s/l%04d-%08d.pdf',
                    substr($periodo, 0, 4),
                    substr($periodo, 4, 2),
                    0,
                    (int) $liquidacion->numero_interno
                );
                $payload['periodo_aaaamm'] = $periodo;
                $payload['cuenta'] = $liquidacion->cuenta_impresa;
                $payload['cp'] = $liquidacion->codigo_postal;
                $payload['origen'] = $liquidacion->archivo_origen;
                $payload['items'] = DB::table('liquidaciones_propietarios_items')
                    ->where('liquidacion_propietario_id', $liquidacion->id)
                    ->orderBy('orden')
                    ->get()
                    ->map(fn (object $item): array => (array) $item)
                    ->all();
                fwrite($archivo, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).PHP_EOL);
            }
        } finally {
            fclose($archivo);
        }

        try {
            $process = new Process([
                $this->python(),
                $this->script(),
                'generar',
                '--entrada',
                $entrada,
                '--salida',
                $baseSalida,
                '--resultado',
                $resultadoPath,
            ], base_path());
            $process->setTimeout($timeout);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new RuntimeException($this->errorProceso($process, 'No se pudieron generar los PDF.'));
            }

            $resultado = json_decode((string) File::get($resultadoPath), true, 512, JSON_THROW_ON_ERROR);
            $rutasObsoletas = [];
            DB::transaction(function () use ($resultado, &$rutasObsoletas): void {
                foreach ($resultado['resultados'] ?? [] as $pdf) {
                    $anterior = DB::table('liquidaciones_propietarios')
                        ->where('id', (int) $pdf['id'])
                        ->value('pdf_ruta');
                    if ($anterior && $anterior !== $pdf['pdf_ruta']) {
                        $rutasObsoletas[] = (string) $anterior;
                    }

                    DB::table('liquidaciones_propietarios')
                        ->where('id', (int) $pdf['id'])
                        ->update([
                            'estado' => 'PDF_GENERADO',
                            'pdf_ruta' => $pdf['pdf_ruta'],
                            'pdf_bytes' => (int) $pdf['bytes'],
                            'pdf_generado_at' => now(),
                            'updated_at' => now(),
                        ]);
                }
            }, 3);

            if ($rutasObsoletas !== []) {
                Storage::disk('liquidaciones')->delete(array_values(array_unique($rutasObsoletas)));
            }

            return ['pdf_generados' => (int) ($resultado['generadas'] ?? 0)];
        } catch (JsonException $error) {
            throw new RuntimeException('El resultado de generación PDF no es JSON válido.', 0, $error);
        } finally {
            File::delete([$entrada, $resultadoPath]);
        }
    }

    /** @return array{cliente_id: int|null, cuenta_corriente_id: int|null} */
    private function resolverCuenta(string $cuenta): array
    {
        $cuentaCorriente = DB::table('cuentas_corrientes')
            ->where('dominio', 'PROPIETARIO')
            ->where('cuenta', $cuenta)
            ->first();

        return [
            'cliente_id' => $cuentaCorriente?->cliente_id === null ? null : (int) $cuentaCorriente->cliente_id,
            'cuenta_corriente_id' => $cuentaCorriente?->id === null ? null : (int) $cuentaCorriente->id,
        ];
    }

    /** @param array<string, mixed> $data @param array<string, int|null> $relacion @return array<string, mixed> */
    private function cabecera(array $data, string $contenidoHash, array $relacion): array
    {
        $control = $data['control_pliqloc'] ?? [];

        return [
            'clave_origen' => $data['clave_origen'],
            'contenido_hash' => $contenidoHash,
            ...$relacion,
            'periodo' => $data['periodo_aaaamm'],
            'sede' => $data['sede'],
            'tipo' => $data['tipo'],
            'fecha' => CarbonImmutable::createFromFormat('d/m/Y', $data['fecha'])->toDateString(),
            'cuenta' => $data['cuenta_normalizada'],
            'cuenta_impresa' => $data['cuenta'],
            'comprobante' => $data['comprobante'],
            'codigo_aux' => $this->nuloSiVacio($data['codigo_aux'] ?? null),
            'propietario' => $data['propietario'],
            'domicilio' => $this->nuloSiVacio($data['domicilio'] ?? null),
            'codigo_postal' => $this->nuloSiVacio($data['cp'] ?? null),
            'localidad' => $this->nuloSiVacio($data['localidad'] ?? null),
            'provincia' => $this->nuloSiVacio($data['provincia'] ?? null),
            'condicion_iva' => $this->nuloSiVacio($data['condicion_iva'] ?? null),
            'cuit' => $this->nuloSiVacio($data['cuit'] ?? null),
            'banco' => $this->nuloSiVacio($data['banco'] ?? null),
            'tipo_cuenta_banco' => $this->nuloSiVacio($data['tipo_cuenta_banco'] ?? null),
            'copropietario' => $this->nuloSiVacio($data['copropietario'] ?? null),
            'porcentaje' => $this->nuloSiVacio($data['porcentaje'] ?? null),
            'total' => $data['total'],
            'total_bruto' => $data['total_bruto'],
            'total_copropietario' => $data['total_copropietario'],
            'total_debe' => $data['total_debe'],
            'total_haber' => $data['total_haber'],
            'total_neto_gravado' => $data['total_neto_gravado'],
            'total_iva' => $data['total_iva'],
            'total_final' => $data['total_final'],
            'archivo_origen' => $data['origen'],
            'control_estado' => $control['estado'] ?? 'SIN_PLIQLOC',
            'control_pliqloc' => json_encode($control, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'estado' => 'IMPORTADA',
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function insertarItems(int $liquidacionId, array $items): void
    {
        $ahora = now();
        $filas = [];
        foreach ($items as $indice => $item) {
            $filas[] = [
                'liquidacion_propietario_id' => $liquidacionId,
                'orden' => $indice + 1,
                'nombre' => $this->nuloSiVacio($item['nombre'] ?? null),
                'detalle' => $this->nuloSiVacio($item['detalle'] ?? null),
                'vencimiento' => $this->nuloSiVacio($item['vencimiento'] ?? null),
                'debe' => $item['debe'] ?? '0',
                'haber' => $item['haber'] ?? '0',
                'referencia' => $this->nuloSiVacio($item['referencia'] ?? null),
                'numero_movimiento_origen' => $this->nuloSiVacio($item['numero_movimiento_origen'] ?? null),
                'fecha_movimiento_origen' => $this->nuloSiVacio($item['fecha_movimiento_origen'] ?? null),
                'archivo_origen' => $this->nuloSiVacio($item['archivo_origen'] ?? null),
                'orden_origen' => (int) ($item['orden_origen'] ?? 0) ?: null,
                'tipo_movimiento' => $this->nuloSiVacio($item['tipo_movimiento'] ?? null),
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ];
            if (count($filas) === 500) {
                DB::table('liquidaciones_propietarios_items')->insert($filas);
                $filas = [];
            }
        }
        if ($filas !== []) {
            DB::table('liquidaciones_propietarios_items')->insert($filas);
        }
    }

    private function siguienteNumero(string $periodo, ?int $numeroInicial): int
    {
        $primera = DB::table('liquidaciones_propietarios')
            ->where('periodo', $periodo)
            ->orderBy('numero_interno')
            ->lockForUpdate()
            ->first(['numero_interno']);

        if ($primera !== null) {
            $primeroActual = (int) $primera->numero_interno;

            if ($numeroInicial !== null && $numeroInicial !== $primeroActual) {
                return $this->renumerarPeriodo($periodo, $numeroInicial);
            }

            $ultima = DB::table('liquidaciones_propietarios')
                ->where('periodo', $periodo)
                ->orderByDesc('numero_interno')
                ->first(['numero_interno']);

            return (int) $ultima->numero_interno + 1;
        }

        $inicial = $numeroInicial ?? (int) config('gei.liquidaciones_propietarios.numero_inicial', 0);
        if ($inicial < 1) {
            throw new RuntimeException('Indicá el primer número interno para iniciar la numeración de PDF.');
        }

        return $inicial;
    }

    private function renumerarPeriodo(string $periodo, int $numeroInicial): int
    {
        if ($numeroInicial < 1) {
            throw new RuntimeException('El primer número interno debe ser mayor que cero.');
        }

        $liquidaciones = DB::table('liquidaciones_propietarios')
            ->where('periodo', $periodo)
            ->orderBy('numero_interno')
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'pdf_ruta']);

        if ($liquidaciones->isEmpty()) {
            return $numeroInicial;
        }

        // Alejar temporalmente la serie evita colisiones mientras se reasignan
        // números dentro del mismo período.
        DB::table('liquidaciones_propietarios')
            ->where('periodo', $periodo)
            ->increment('numero_interno', 1000000000000);

        foreach ($liquidaciones as $indice => $liquidacion) {
            DB::table('liquidaciones_propietarios')
                ->where('id', $liquidacion->id)
                ->update([
                    'numero_interno' => $numeroInicial + $indice,
                    'estado' => 'IMPORTADA',
                    'updated_at' => now(),
                ]);
        }

        return $numeroInicial + $liquidaciones->count();
    }

    private function validarEsquema(): void
    {
        foreach ([
            'clientes',
            'cuentas_corrientes',
            'liquidaciones_propietarios',
            'liquidaciones_propietarios_items',
            'repartos_propietarios',
        ] as $tabla) {
            if (! Schema::hasTable($tabla)) {
                throw new RuntimeException("Falta la tabla {$tabla}. Ejecutá las migraciones antes de procesar.");
            }
        }
    }

    private function validarPeriodo(string $periodo): void
    {
        if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            throw new RuntimeException('El período debe tener formato AAAAMM.');
        }
    }

    private function directorioPeriodo(string $periodo): string
    {
        return Storage::path("liquidaciones/periodos/{$periodo}");
    }

    private function validarArchivos(string $directorio): void
    {
        $faltantes = [];
        foreach (self::ARCHIVOS as $nombre) {
            if (! is_file("{$directorio}/liquidaciones/{$nombre}")) {
                $faltantes[] = $nombre;
            }
        }
        if ($faltantes !== []) {
            throw new RuntimeException('Faltan archivos para generar PDF: '.implode(', ', $faltantes).'.');
        }
    }

    private function hashLote(string $directorio): string
    {
        $hash = hash_init('sha256');
        foreach (self::ARCHIVOS as $nombre) {
            hash_update($hash, $nombre."\0");
            hash_update_file($hash, "{$directorio}/liquidaciones/{$nombre}");
        }
        return hash_final($hash);
    }

    private function python(): string
    {
        $python = (string) config('gei.liquidaciones_propietarios.python');
        if (! is_file($python) || ! is_executable($python)) {
            throw new RuntimeException("No se encontró el runtime Python para PDF: {$python}");
        }
        return $python;
    }

    private function script(): string
    {
        $script = (string) config('gei.liquidaciones_propietarios.script');
        if (! is_file($script)) {
            throw new RuntimeException("No se encontró el adaptador de liquidaciones: {$script}");
        }
        return $script;
    }

    private function errorProceso(Process $process, string $fallback): string
    {
        $mensaje = trim($process->getErrorOutput());
        return $mensaje !== '' ? $mensaje : (trim($process->getOutput()) ?: $fallback);
    }

    private function nuloSiVacio(mixed $valor): ?string
    {
        $texto = trim((string) ($valor ?? ''));
        return $texto === '' ? null : $texto;
    }
}
