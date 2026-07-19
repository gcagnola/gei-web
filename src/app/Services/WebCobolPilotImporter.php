<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WebCobolPilotImporter
{
    private const VERSION = 'web-cobol-piloto-v1';
    private const TEMP_DATABASE = 'db_gei_web_migraciones_test';

    private string $basePath;

    /**
     * @param array{
     *     modo?: string,
     *     chunk_size?: int,
     *     base_dir: string,
     *     limite_propietarios: ?int,
     *     limite_inquilinos: ?int,
     *     limite_movimientos_propietario: ?int,
     *     limite_movimientos_inquilino: ?int,
     *     cuenta_propietario?: ?string,
     *     cuenta_inquilino?: ?string,
     *     sin_limite: bool,
     *     dry_run: bool
     * } $options
     * @return array<string, mixed>
     */
    public function importar(array $options): array
    {
        $inicio = microtime(true);
        $database = DB::connection()->getDatabaseName();
        $this->assertTemporalDatabase($database);

        $this->basePath = $this->resolveBasePath($options['base_dir']);

        foreach (['PROPIETAR.TXT', 'INQUILINO.TXT', 'CTACTEPRO.TXT', 'INQCTACTE.TXT'] as $archivo) {
            $this->path($archivo);
        }

        $modo = $options['modo'] ?? 'piloto';
        if ($modo === 'bulk') {
            return $this->importarBulk($options, $database, $inicio);
        }

        $propietarios = $this->seleccionarPropietarios(
            $options['cuenta_propietario'] ?? null,
            $options['limite_propietarios']
        );
        $inquilinos = $this->seleccionarInquilinos(
            $options['cuenta_inquilino'] ?? null,
            $options['limite_inquilinos']
        );

        $propietarios = $this->agregarPropietariosReferenciados($propietarios, $inquilinos);

        $cuentasPropietarios = array_keys($propietarios);
        $cuentasInquilinos = array_keys($inquilinos);

        $movimientosPropietarios = $this->seleccionarMovimientos(
            'CTACTEPRO.TXT',
            $cuentasPropietarios,
            $options['limite_movimientos_propietario']
        );
        $movimientosInquilinos = $this->seleccionarMovimientos(
            'INQCTACTE.TXT',
            $cuentasInquilinos,
            $options['limite_movimientos_inquilino']
        );

        $resultado = [
            'database' => $database,
            'base_dir' => $this->basePath,
            'dry_run' => $options['dry_run'],
            'sin_limite' => $options['sin_limite'],
            'version' => self::VERSION,
            'candidatos' => [
                'propietarios' => count($propietarios),
                'inquilinos' => count($inquilinos),
                'movimientos_propietario' => count($movimientosPropietarios),
                'movimientos_inquilino' => count($movimientosInquilinos),
            ],
            'tablas' => [],
            'errores' => [],
            'metricas' => [],
        ];

        if ($options['dry_run']) {
            $resultado['mensaje'] = 'Dry-run completado: no se escribieron tablas.';
            $resultado['metricas'] = $this->metricas($inicio);

            return $resultado;
        }

        return DB::transaction(function () use ($propietarios, $inquilinos, $movimientosPropietarios, $movimientosInquilinos, $resultado, $inicio): array {
            $before = $this->conteos();

            $loteId = $this->upsertLote($propietarios, $inquilinos);
            $archivos = [];
            foreach (['PROPIETAR.TXT', 'INQUILINO.TXT', 'CTACTEPRO.TXT', 'INQCTACTE.TXT'] as $archivo) {
                $archivos[$archivo] = $this->upsertArchivo($loteId, $archivo);
            }

            $personasPropietario = [];
            $propietariosIds = [];
            foreach ($propietarios as $cuenta => $linea) {
                $registroId = $this->upsertRegistro($loteId, $archivos['PROPIETAR.TXT'], 'PROPIETAR.TXT', 'propietario', $linea);
                $personasPropietario[$cuenta] = $this->upsertPersonaPropietario($loteId, $archivos['PROPIETAR.TXT'], $registroId, $linea['raw']);
                $propietariosIds[$cuenta] = $this->upsertPropietario($loteId, $archivos['PROPIETAR.TXT'], $registroId, $personasPropietario[$cuenta], $linea['raw']);
            }

            $personasInquilino = [];
            $inquilinosIds = [];
            $inmueblesIds = [];
            $contratosIds = [];
            foreach ($inquilinos as $cuenta => $linea) {
                $raw = $linea['raw'];
                $cuentaPropietario = substr($raw, 11, 11);
                $registroId = $this->upsertRegistro($loteId, $archivos['INQUILINO.TXT'], 'INQUILINO.TXT', 'inquilino', $linea);
                $personasInquilino[$cuenta] = $this->upsertPersonaInquilino($loteId, $archivos['INQUILINO.TXT'], $registroId, $raw);
                $inquilinosIds[$cuenta] = $this->upsertInquilino($loteId, $archivos['INQUILINO.TXT'], $registroId, $personasInquilino[$cuenta], $raw);
                $inmueblesIds[$cuenta] = $this->upsertInmueble($loteId, $archivos['INQUILINO.TXT'], $registroId, $raw);
                $contratosIds[$cuenta] = $this->upsertContrato($loteId, $archivos['INQUILINO.TXT'], $registroId, $raw);

                $this->upsertRelacion('web_contrato_inquilinos', [
                    'contrato_id' => $contratosIds[$cuenta],
                    'inquilino_id' => $inquilinosIds[$cuenta],
                    'rol' => 'TITULAR',
                ], $loteId, $archivos['INQUILINO.TXT'], $registroId);

                if (isset($propietariosIds[$cuentaPropietario])) {
                    $this->upsertRelacion('web_contrato_propietarios', [
                        'contrato_id' => $contratosIds[$cuenta],
                        'propietario_id' => $propietariosIds[$cuentaPropietario],
                    ], $loteId, $archivos['INQUILINO.TXT'], $registroId);
                    $this->upsertRelacion('web_inmuebles_propietarios', [
                        'inmueble_id' => $inmueblesIds[$cuenta],
                        'propietario_id' => $propietariosIds[$cuentaPropietario],
                        'desde' => $this->fechaDmy(substr($raw, 325, 8)),
                    ], $loteId, $archivos['INQUILINO.TXT'], $registroId);
                }

                $this->upsertRelacion('web_contrato_inmuebles', [
                    'contrato_id' => $contratosIds[$cuenta],
                    'inmueble_id' => $inmueblesIds[$cuenta],
                ], $loteId, $archivos['INQUILINO.TXT'], $registroId);
            }

            $cuentasCorrientesPropietario = [];
            foreach ($propietariosIds as $cuenta => $propietarioId) {
                $cuentasCorrientesPropietario[$cuenta] = $this->upsertCuentaCorriente(
                    $loteId,
                    'PROPIETARIO',
                    $cuenta,
                    $personasPropietario[$cuenta],
                    $propietarioId,
                    null
                );
            }

            $cuentasCorrientesInquilino = [];
            foreach ($inquilinosIds as $cuenta => $inquilinoId) {
                $cuentasCorrientesInquilino[$cuenta] = $this->upsertCuentaCorriente(
                    $loteId,
                    'INQUILINO',
                    $cuenta,
                    $personasInquilino[$cuenta],
                    null,
                    $inquilinoId
                );
            }

            $this->procesarMovimientosPropietarios(
                $loteId,
                $archivos['CTACTEPRO.TXT'],
                $movimientosPropietarios,
                $cuentasCorrientesPropietario,
                $propietariosIds
            );

            $this->procesarMovimientosInquilinos(
                $loteId,
                $archivos['INQCTACTE.TXT'],
                $movimientosInquilinos,
                $cuentasCorrientesInquilino,
                $inquilinosIds,
                $contratosIds,
                $inmueblesIds
            );

            $after = $this->conteos();
            $resultado['tablas'] = [
                'antes' => $before,
                'despues' => $after,
                'delta' => $this->delta($before, $after),
            ];
            $resultado['mensaje'] = 'Importacion piloto completada sobre base temporal.';
            $resultado['metricas'] = $this->metricas($inicio);

            return $resultado;
        });
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function importarBulk(array $options, string $database, float $inicio): array
    {
        $chunkSize = (int) ($options['chunk_size'] ?? 5000);
        $propietarios = $this->seleccionarPropietarios(
            $options['cuenta_propietario'] ?? null,
            $options['limite_propietarios']
        );
        $inquilinos = $this->seleccionarInquilinos(
            $options['cuenta_inquilino'] ?? null,
            $options['limite_inquilinos']
        );
        $propietarios = $this->agregarPropietariosReferenciados($propietarios, $inquilinos);

        $cantidadMovimientosPropietarios = $this->contarMovimientos(
            'CTACTEPRO.TXT',
            array_keys($propietarios),
            $options['limite_movimientos_propietario']
        );
        $cantidadMovimientosInquilinos = $this->contarMovimientos(
            'INQCTACTE.TXT',
            array_keys($inquilinos),
            $options['limite_movimientos_inquilino']
        );

        $resultado = [
            'database' => $database,
            'base_dir' => $this->basePath,
            'modo' => 'bulk',
            'chunk_size' => $chunkSize,
            'dry_run' => $options['dry_run'],
            'sin_limite' => $options['sin_limite'],
            'version' => self::VERSION,
            'candidatos' => [
                'propietarios' => count($propietarios),
                'inquilinos' => count($inquilinos),
                'movimientos_propietario' => $cantidadMovimientosPropietarios,
                'movimientos_inquilino' => $cantidadMovimientosInquilinos,
            ],
            'tablas' => [],
            'errores' => [],
            'metricas' => [],
        ];

        if ($options['dry_run']) {
            $resultado['mensaje'] = 'Dry-run bulk completado: no se escribieron tablas.';
            $resultado['metricas'] = $this->metricas($inicio);

            return $resultado;
        }

        $before = $this->conteos();
        $loteId = null;
        $archivos = [];

        DB::transaction(function () use (&$loteId, &$archivos, $propietarios, $inquilinos): void {
            $loteId = $this->upsertLote($propietarios, $inquilinos);
            foreach (['PROPIETAR.TXT', 'INQUILINO.TXT', 'CTACTEPRO.TXT', 'INQCTACTE.TXT'] as $archivo) {
                $archivos[$archivo] = $this->upsertArchivo($loteId, $archivo);
            }
        });

        $registrosProp = $this->bulkRegistros($loteId, $archivos['PROPIETAR.TXT'], 'PROPIETAR.TXT', 'propietario', array_values($propietarios), $chunkSize);
        $registrosInq = $this->bulkRegistros($loteId, $archivos['INQUILINO.TXT'], 'INQUILINO.TXT', 'inquilino', array_values($inquilinos), $chunkSize);

        $this->bulkPersonasPropietarios($loteId, $archivos['PROPIETAR.TXT'], $propietarios, $registrosProp, $chunkSize);
        $this->bulkPersonasInquilinos($loteId, $archivos['INQUILINO.TXT'], $inquilinos, $registrosInq, $chunkSize);

        $personaPropIds = $this->personasPorRegistro($registrosProp);
        $personaInqIds = $this->personasPorRegistro($registrosInq);

        $this->bulkPropietarios($loteId, $archivos['PROPIETAR.TXT'], $propietarios, $registrosProp, $personaPropIds, $chunkSize);
        $this->bulkInquilinos($loteId, $archivos['INQUILINO.TXT'], $inquilinos, $registrosInq, $personaInqIds, $chunkSize);

        $propietariosIds = DB::table('web_propietarios')->pluck('id', 'cuenta_propietario')->map(fn ($id) => (int) $id)->all();
        $inquilinosIds = DB::table('web_inquilinos')->pluck('id', 'cuenta_inquilino')->map(fn ($id) => (int) $id)->all();

        $this->bulkInmuebles($loteId, $archivos['INQUILINO.TXT'], $inquilinos, $registrosInq, $chunkSize);
        $this->bulkContratos($loteId, $archivos['INQUILINO.TXT'], $inquilinos, $registrosInq, $chunkSize);

        $inmueblesIds = DB::table('web_inmuebles')->pluck('id', 'codigo_origen')->map(fn ($id) => (int) $id)->all();
        $contratosIds = DB::table('web_contratos')->pluck('id', 'codigo_origen')->map(fn ($id) => (int) $id)->all();

        $this->bulkRelaciones($loteId, $archivos['INQUILINO.TXT'], $inquilinos, $registrosInq, $propietariosIds, $inquilinosIds, $inmueblesIds, $contratosIds, $chunkSize);
        $this->bulkCuentas($loteId, $propietarios, $inquilinos, $registrosProp, $registrosInq, $personaPropIds, $personaInqIds, $propietariosIds, $inquilinosIds, $chunkSize);

        $cuentasProp = DB::table('web_cuentas_corrientes')->where('dominio', 'PROPIETARIO')->pluck('id', 'cuenta_origen')->map(fn ($id) => (int) $id)->all();
        $cuentasInq = DB::table('web_cuentas_corrientes')->where('dominio', 'INQUILINO')->pluck('id', 'cuenta_origen')->map(fn ($id) => (int) $id)->all();

        foreach ($this->movimientosPorChunks('CTACTEPRO.TXT', array_keys($propietarios), $options['limite_movimientos_propietario'], $chunkSize) as $chunk) {
            $this->procesarMovimientosPropietarios($loteId, $archivos['CTACTEPRO.TXT'], $chunk, $cuentasProp, $propietariosIds);
        }
        foreach ($this->movimientosPorChunks('INQCTACTE.TXT', array_keys($inquilinos), $options['limite_movimientos_inquilino'], $chunkSize) as $chunk) {
            $this->procesarMovimientosInquilinos($loteId, $archivos['INQCTACTE.TXT'], $chunk, $cuentasInq, $inquilinosIds, $contratosIds, $inmueblesIds);
        }

        $after = $this->conteos();
        $resultado['tablas'] = [
            'antes' => $before,
            'despues' => $after,
            'delta' => $this->delta($before, $after),
        ];
        $resultado['metricas'] = $this->metricas($inicio);
        $resultado['mensaje'] = 'Importacion bulk completada sobre base temporal.';

        return $resultado;
    }

    private function assertTemporalDatabase(string $database): void
    {
        if ($database === 'db_gei') {
            throw new RuntimeException('Importador piloto abortado: la conexion apunta a db_gei.');
        }

        if ($database !== self::TEMP_DATABASE) {
            throw new RuntimeException("Importador piloto abortado: base no temporal detectada ({$database}).");
        }
    }

    private function resolveBasePath(string $baseDir): string
    {
        $path = str_starts_with($baseDir, DIRECTORY_SEPARATOR)
            ? $baseDir
            : base_path($baseDir);

        if (! is_dir($path)) {
            throw new RuntimeException("Directorio COBOL no encontrado: {$path}");
        }

        return rtrim($path, DIRECTORY_SEPARATOR);
    }

    /**
     * @return array<string, array{line:int, raw:string}>
     */
    private function seleccionarPropietarios(?string $cuenta, ?int $limite): array
    {
        $seleccion = [];
        foreach ($this->leerLineas('PROPIETAR.TXT') as $linea) {
            $cuentaLinea = substr($linea['raw'], 0, 11);
            if ($cuenta !== null && $cuentaLinea !== $cuenta) {
                continue;
            }

            $seleccion[$cuentaLinea] = $linea;
            if ($cuenta !== null || ($limite !== null && count($seleccion) >= $limite)) {
                break;
            }
        }

        return $seleccion;
    }

    /**
     * @return array<string, array{line:int, raw:string}>
     */
    private function seleccionarInquilinos(?string $cuenta, ?int $limite): array
    {
        $seleccion = [];
        foreach ($this->leerLineas('INQUILINO.TXT') as $linea) {
            $cuentaLinea = substr($linea['raw'], 0, 11);
            if ($cuenta !== null && $cuentaLinea !== $cuenta) {
                continue;
            }

            $seleccion[$cuentaLinea] = $linea;
            if ($cuenta !== null || ($limite !== null && count($seleccion) >= $limite)) {
                break;
            }
        }

        return $seleccion;
    }

    /**
     * @param array<string, array{line:int, raw:string}> $propietarios
     * @param array<string, array{line:int, raw:string}> $inquilinos
     * @return array<string, array{line:int, raw:string}>
     */
    private function agregarPropietariosReferenciados(array $propietarios, array $inquilinos): array
    {
        $faltantes = [];
        foreach ($inquilinos as $linea) {
            $cuentaPropietario = substr($linea['raw'], 11, 11);
            if (! isset($propietarios[$cuentaPropietario])) {
                $faltantes[$cuentaPropietario] = true;
            }
        }

        if ($faltantes === []) {
            return $propietarios;
        }

        foreach ($this->leerLineas('PROPIETAR.TXT') as $linea) {
            $cuenta = substr($linea['raw'], 0, 11);
            if (isset($faltantes[$cuenta])) {
                $propietarios[$cuenta] = $linea;
                unset($faltantes[$cuenta]);
            }
            if ($faltantes === []) {
                break;
            }
        }

        return $propietarios;
    }

    /**
     * @param list<string> $cuentas
     * @return list<array{line:int, raw:string}>
     */
    private function seleccionarMovimientos(string $archivo, array $cuentas, ?int $limite): array
    {
        if ($cuentas === [] || $limite === 0) {
            return [];
        }

        $set = array_fill_keys($cuentas, true);
        $seleccion = [];
        foreach ($this->leerLineas($archivo) as $linea) {
            if (! isset($set[substr($linea['raw'], 0, 11)])) {
                continue;
            }

            $seleccion[] = $linea;
            if ($limite !== null && count($seleccion) >= $limite) {
                break;
            }
        }

        return $seleccion;
    }

    /**
     * @param list<string> $cuentas
     */
    private function contarMovimientos(string $archivo, array $cuentas, ?int $limite): int
    {
        $cantidad = 0;
        foreach ($this->movimientosPorChunks($archivo, $cuentas, $limite, 5000) as $chunk) {
            $cantidad += count($chunk);
        }

        return $cantidad;
    }

    /**
     * @param list<string> $cuentas
     * @return iterable<list<array{line:int, raw:string}>>
     */
    private function movimientosPorChunks(string $archivo, array $cuentas, ?int $limite, int $chunkSize): iterable
    {
        if ($cuentas === [] || $limite === 0) {
            return;
        }

        $set = array_fill_keys($cuentas, true);
        $chunk = [];
        $cantidad = 0;
        foreach ($this->leerLineas($archivo) as $linea) {
            if (! isset($set[substr($linea['raw'], 0, 11)])) {
                continue;
            }

            $chunk[] = $linea;
            $cantidad++;

            if (count($chunk) >= $chunkSize) {
                yield $chunk;
                $chunk = [];
            }

            if ($limite !== null && $cantidad >= $limite) {
                break;
            }
        }

        if ($chunk !== []) {
            yield $chunk;
        }
    }

    /**
     * @param list<array{line:int, raw:string}> $lineas
     * @return array<int, int>
     */
    private function bulkRegistros(int $loteId, int $archivoId, string $archivo, string $tipoRegistro, array $lineas, int $chunkSize): array
    {
        $ids = [];
        foreach (array_chunk($lineas, $this->safeChunkSize($chunkSize, 18)) as $chunk) {
            $ids += $this->upsertRegistrosBatch($loteId, $archivoId, $archivo, $tipoRegistro, $chunk);
        }

        return $ids;
    }

    /**
     * @param array<string, array{line:int, raw:string}> $propietarios
     * @param array<int, int> $registroIds
     */
    private function bulkPersonasPropietarios(int $loteId, int $archivoId, array $propietarios, array $registroIds, int $chunkSize): void
    {
        $rows = [];
        foreach ($propietarios as $linea) {
            $raw = $linea['raw'];
            $registroId = $registroIds[$linea['line']] ?? null;
            if ($registroId === null) {
                continue;
            }
            $rows[] = [
                'registro_origen_id' => $registroId,
                'tipo_persona' => 'DESCONOCIDA',
                'nombre' => $this->texto(substr($raw, 11, 35)),
                'razon_social' => $this->texto(substr($raw, 11, 35)),
                'cuit' => $this->texto(substr($raw, 185, 12)),
                'personeria_fiscal' => $this->texto(substr($raw, 184, 1)),
                'telefono' => $this->texto(substr($raw, 116, 14)),
                'domicilio_principal' => $this->texto(substr($raw, 46, 30)),
                'codigo_postal' => $this->texto(substr($raw, 76, 4)),
                'localidad' => $this->texto(substr($raw, 80, 26)),
                'provincia' => $this->texto(substr($raw, 106, 10)),
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'hash_origen' => hash('sha256', $raw),
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $this->insertPersonasIfMissing($rows, $chunkSize);
    }

    /**
     * @param array<string, array{line:int, raw:string}> $inquilinos
     * @param array<int, int> $registroIds
     */
    private function bulkPersonasInquilinos(int $loteId, int $archivoId, array $inquilinos, array $registroIds, int $chunkSize): void
    {
        $rows = [];
        foreach ($inquilinos as $linea) {
            $raw = $linea['raw'];
            $registroId = $registroIds[$linea['line']] ?? null;
            if ($registroId === null) {
                continue;
            }
            $rows[] = [
                'registro_origen_id' => $registroId,
                'tipo_persona' => 'DESCONOCIDA',
                'nombre' => $this->texto(substr($raw, 22, 35)),
                'tipo_documento' => $this->texto(substr($raw, 347, 1)),
                'numero_documento' => $this->texto(substr($raw, 348, 9)),
                'cuit' => $this->texto(substr($raw, 527, 12)),
                'personeria_fiscal' => $this->texto(substr($raw, 526, 1)),
                'telefono' => $this->texto(substr($raw, 156, 14)),
                'domicilio_principal' => $this->texto(substr($raw, 357, 35)),
                'codigo_postal' => $this->texto(substr($raw, 392, 4)),
                'localidad' => $this->texto(substr($raw, 396, 26)),
                'provincia' => $this->texto(substr($raw, 422, 10)),
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'hash_origen' => hash('sha256', $raw),
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $this->insertPersonasIfMissing($rows, $chunkSize);
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function insertPersonasIfMissing(array $rows, int $chunkSize): void
    {
        foreach (array_chunk($rows, $chunkSize) as $chunk) {
            $columns = array_keys($chunk[0]);
            $this->insertWhereNotExists(
                'web_personas',
                $columns,
                $chunk,
                'registro_origen_id',
                'registro_origen_id'
            );
        }
    }

    /**
     * @param array<int, int> $registroIds
     * @return array<int, int>
     */
    private function personasPorRegistro(array $registroIds): array
    {
        if ($registroIds === []) {
            return [];
        }

        return DB::table('web_personas')
            ->whereIn('registro_origen_id', array_values($registroIds))
            ->pluck('id', 'registro_origen_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param array<string, array{line:int, raw:string}> $propietarios
     * @param array<int, int> $registroIds
     * @param array<int, int> $personaIds
     */
    private function bulkPropietarios(int $loteId, int $archivoId, array $propietarios, array $registroIds, array $personaIds, int $chunkSize): void
    {
        $rows = [];
        foreach ($propietarios as $cuenta => $linea) {
            $raw = $linea['raw'];
            $registroId = $registroIds[$linea['line']] ?? null;
            if ($registroId === null || ! isset($personaIds[$registroId])) {
                continue;
            }
            $rows[] = [
                'persona_id' => $personaIds[$registroId],
                'cuenta_propietario' => $cuenta,
                'forma_pago_codigo' => $this->texto(substr($raw, 151, 2)),
                'subforma_pago_codigo' => $this->texto(substr($raw, 153, 1)),
                'cuenta_deposito' => $this->texto(substr($raw, 167, 14)),
                'liquidar' => $this->texto(substr($raw, 154, 1)) !== 'N',
                'liquidacion_sin_reserva' => $this->texto(substr($raw, 144, 1)) === 'S',
                'comision_administracion' => $this->decimal(substr($raw, 148, 3), 1),
                'comision_impuestos' => $this->decimal(substr($raw, 145, 3), 1),
                'nro_ultima_liquidacion' => (int) $this->texto(substr($raw, 155, 4)),
                'fecha_ultima_liquidacion' => $this->fechaYmd(substr($raw, 159, 8)),
                'marca_sucursal' => $this->texto(substr($raw, 197, 1)),
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'registro_origen_id' => $registroId,
                'hash_origen' => hash('sha256', $raw),
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $this->upsertChunks('web_propietarios', $rows, ['cuenta_propietario'], $chunkSize);
    }

    /**
     * @param array<string, array{line:int, raw:string}> $inquilinos
     * @param array<int, int> $registroIds
     * @param array<int, int> $personaIds
     */
    private function bulkInquilinos(int $loteId, int $archivoId, array $inquilinos, array $registroIds, array $personaIds, int $chunkSize): void
    {
        $rows = [];
        foreach ($inquilinos as $cuenta => $linea) {
            $raw = $linea['raw'];
            $registroId = $registroIds[$linea['line']] ?? null;
            if ($registroId === null || ! isset($personaIds[$registroId])) {
                continue;
            }
            $rows[] = [
                'persona_id' => $personaIds[$registroId],
                'cuenta_inquilino' => $cuenta,
                'telefono_particular' => $this->texto(substr($raw, 156, 14)),
                'telefono_laboral' => $this->texto(substr($raw, 170, 14)),
                'marca_baja' => $this->texto(substr($raw, 144, 1)),
                'fecha_baja' => $this->fechaDmy(substr($raw, 145, 8)),
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'registro_origen_id' => $registroId,
                'hash_origen' => hash('sha256', $raw),
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $this->upsertChunks('web_inquilinos', $rows, ['cuenta_inquilino'], $chunkSize);
    }

    /**
     * @param array<string, array{line:int, raw:string}> $inquilinos
     * @param array<int, int> $registroIds
     */
    private function bulkInmuebles(int $loteId, int $archivoId, array $inquilinos, array $registroIds, int $chunkSize): void
    {
        $rows = [];
        foreach ($inquilinos as $linea) {
            $raw = $linea['raw'];
            $registroId = $registroIds[$linea['line']] ?? null;
            if ($registroId === null) {
                continue;
            }
            $domicilio = $this->texto(substr($raw, 57, 35));
            $rows[] = [
                'codigo_origen' => $this->codigoInmueble($raw),
                'domicilio' => $domicilio !== '' ? $domicilio : 'SIN DOMICILIO',
                'domicilio_normalizado' => strtoupper($domicilio),
                'destino_codigo' => $this->texto(substr($raw, 289, 3)),
                'cochera_codigo' => $this->texto(substr($raw, 547, 2)),
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'registro_origen_id' => $registroId,
                'hash_origen' => hash('sha256', $raw),
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $this->upsertChunks('web_inmuebles', $rows, ['codigo_origen'], $chunkSize);
    }

    /**
     * @param array<string, array{line:int, raw:string}> $inquilinos
     * @param array<int, int> $registroIds
     */
    private function bulkContratos(int $loteId, int $archivoId, array $inquilinos, array $registroIds, int $chunkSize): void
    {
        $rows = [];
        foreach ($inquilinos as $linea) {
            $raw = $linea['raw'];
            $registroId = $registroIds[$linea['line']] ?? null;
            if ($registroId === null) {
                continue;
            }
            [$fechaInicio, $fechaFin] = $this->rangoContrato($raw);
            $rows[] = [
                'codigo_origen' => $this->codigoContrato($raw),
                'cuenta_inquilino_origen' => substr($raw, 0, 11),
                'cuenta_propietario_origen' => substr($raw, 11, 11),
                'fecha_contrato' => $this->fechaDmy(substr($raw, 92, 8)),
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'fecha_baja' => $this->fechaDmy(substr($raw, 145, 8)),
                'marca_baja' => $this->texto(substr($raw, 144, 1)),
                'plazo_meses' => (int) $this->texto(substr($raw, 116, 3)),
                'indice_ajuste' => $this->texto(substr($raw, 119, 3)),
                'tipo_ajuste' => $this->texto(substr($raw, 122, 2)),
                'fecha_primer_ajuste' => $this->fechaDmy(substr($raw, 108, 8)),
                'cuota_1' => $this->decimal(substr($raw, 124, 10), 2),
                'cuota_2' => $this->decimal(substr($raw, 134, 10), 2),
                'alquiler_inicial' => $this->decimal(substr($raw, 333, 10), 2),
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'registro_origen_id' => $registroId,
                'hash_origen' => hash('sha256', $raw),
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $this->upsertChunks('web_contratos', $rows, ['codigo_origen'], $chunkSize);
    }

    /**
     * @param array<string, array{line:int, raw:string}> $inquilinos
     * @param array<int, int> $registroIds
     * @param array<string, int> $propietariosIds
     * @param array<string, int> $inquilinosIds
     * @param array<string, int> $inmueblesIds
     * @param array<string, int> $contratosIds
     */
    private function bulkRelaciones(
        int $loteId,
        int $archivoId,
        array $inquilinos,
        array $registroIds,
        array $propietariosIds,
        array $inquilinosIds,
        array $inmueblesIds,
        array $contratosIds,
        int $chunkSize
    ): void {
        $contratoInquilinos = [];
        $contratoPropietarios = [];
        $contratoInmuebles = [];
        $inmueblesPropietarios = [];
        foreach ($inquilinos as $cuenta => $linea) {
            $raw = $linea['raw'];
            $registroId = $registroIds[$linea['line']] ?? null;
            $contratoId = $contratosIds[$this->codigoContrato($raw)] ?? null;
            $inmuebleId = $inmueblesIds[$this->codigoInmueble($raw)] ?? null;
            $inquilinoId = $inquilinosIds[$cuenta] ?? null;
            if ($registroId === null || $contratoId === null || $inmuebleId === null || $inquilinoId === null) {
                continue;
            }
            $base = [
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'registro_origen_id' => $registroId,
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ];
            $contratoInquilinos[] = $base + [
                'contrato_id' => $contratoId,
                'inquilino_id' => $inquilinoId,
                'rol' => 'TITULAR',
                'hash_origen' => hash('sha256', 'web_contrato_inquilinos'.$contratoId.'|'.$inquilinoId),
            ];
            $contratoInmuebles[] = $base + [
                'contrato_id' => $contratoId,
                'inmueble_id' => $inmuebleId,
                'hash_origen' => hash('sha256', 'web_contrato_inmuebles'.$contratoId.'|'.$inmuebleId),
            ];
            $cuentaPropietario = substr($raw, 11, 11);
            $propietarioId = $propietariosIds[$cuentaPropietario] ?? null;
            if ($propietarioId !== null) {
                $contratoPropietarios[] = $base + [
                    'contrato_id' => $contratoId,
                    'propietario_id' => $propietarioId,
                    'hash_origen' => hash('sha256', 'web_contrato_propietarios'.$contratoId.'|'.$propietarioId),
                ];
                $inmueblesPropietarios[] = $base + [
                    'inmueble_id' => $inmuebleId,
                    'propietario_id' => $propietarioId,
                    'desde' => $this->fechaDmy(substr($raw, 325, 8)),
                    'hash_origen' => hash('sha256', 'web_inmuebles_propietarios'.$inmuebleId.'|'.$propietarioId.'|'.substr($raw, 325, 8)),
                ];
            }
        }

        $this->upsertChunks('web_contrato_inquilinos', $contratoInquilinos, ['contrato_id', 'inquilino_id', 'rol'], $chunkSize);
        $this->upsertChunks('web_contrato_propietarios', $contratoPropietarios, ['contrato_id', 'propietario_id'], $chunkSize);
        $this->upsertChunks('web_contrato_inmuebles', $contratoInmuebles, ['contrato_id', 'inmueble_id'], $chunkSize);

        foreach ($inmueblesPropietarios as $row) {
            DB::table('web_inmuebles_propietarios')->updateOrInsert(
                [
                    'inmueble_id' => $row['inmueble_id'],
                    'propietario_id' => $row['propietario_id'],
                    'desde' => $row['desde'],
                ],
                $row
            );
        }
    }

    /**
     * @param array<string, array{line:int, raw:string}> $propietarios
     * @param array<string, array{line:int, raw:string}> $inquilinos
     * @param array<int, int> $registrosProp
     * @param array<int, int> $registrosInq
     * @param array<int, int> $personaPropIds
     * @param array<int, int> $personaInqIds
     * @param array<string, int> $propietariosIds
     * @param array<string, int> $inquilinosIds
     */
    private function bulkCuentas(
        int $loteId,
        array $propietarios,
        array $inquilinos,
        array $registrosProp,
        array $registrosInq,
        array $personaPropIds,
        array $personaInqIds,
        array $propietariosIds,
        array $inquilinosIds,
        int $chunkSize
    ): void {
        $rows = [];
        foreach ($propietarios as $cuenta => $linea) {
            $registroId = $registrosProp[$linea['line']] ?? null;
            $rows[] = [
                'dominio' => 'PROPIETARIO',
                'cuenta_origen' => $cuenta,
                'persona_id' => $personaPropIds[$registroId] ?? null,
                'propietario_id' => $propietariosIds[$cuenta] ?? null,
                'inquilino_id' => null,
                'moneda' => 'ARS',
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        foreach ($inquilinos as $cuenta => $linea) {
            $registroId = $registrosInq[$linea['line']] ?? null;
            $rows[] = [
                'dominio' => 'INQUILINO',
                'cuenta_origen' => $cuenta,
                'persona_id' => $personaInqIds[$registroId] ?? null,
                'propietario_id' => null,
                'inquilino_id' => $inquilinosIds[$cuenta] ?? null,
                'moneda' => 'ARS',
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }
        $rows = array_values(array_filter($rows, fn ($row) => $row['persona_id'] !== null && ($row['propietario_id'] !== null || $row['inquilino_id'] !== null)));
        $this->upsertChunks('web_cuentas_corrientes', $rows, ['dominio', 'cuenta_origen'], $chunkSize);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $uniqueBy
     */
    private function upsertChunks(string $table, array $rows, array $uniqueBy, int $chunkSize): void
    {
        $rows = $this->uniqueRows($rows, $uniqueBy);
        $safeChunkSize = $this->safeChunkSize($chunkSize, count($rows[0] ?? []));
        foreach (array_chunk($rows, $safeChunkSize) as $chunk) {
            if ($chunk === []) {
                continue;
            }
            $this->insertWhereNotExistsComposite($table, array_keys($chunk[0]), $chunk, $uniqueBy);
        }
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     */
    private function insertWhereNotExists(string $table, array $columns, array $rows, string $sourceColumn, string $targetColumn): void
    {
        $this->insertWhereNotExistsComposite($table, $columns, $rows, [$targetColumn], [$sourceColumn]);
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     * @param list<string> $targetColumns
     * @param list<string>|null $sourceColumns
     */
    private function insertWhereNotExistsComposite(string $table, array $columns, array $rows, array $targetColumns, ?array $sourceColumns = null): void
    {
        if ($rows === []) {
            return;
        }

        $sourceColumns ??= $targetColumns;
        $quotedColumns = array_map(fn ($column) => "\"{$column}\"", $columns);
        $selectColumns = array_map(fn ($column) => $this->typedInsertExpression($column), $columns);
        foreach (array_chunk($rows, $this->safeChunkSize(5000, count($columns))) as $chunk) {
            $placeholders = [];
            $bindings = [];
            foreach ($chunk as $row) {
                $placeholders[] = '('.implode(',', array_fill(0, count($columns), '?')).')';
                foreach ($columns as $column) {
                    $bindings[] = $row[$column] ?? null;
                }
            }

            $conditions = [];
            foreach ($targetColumns as $index => $targetColumn) {
                $sourceColumn = $sourceColumns[$index] ?? $targetColumn;
                $conditions[] = 't."'.$targetColumn.'" = '.$this->typedValueExpression($sourceColumn, $targetColumn);
            }

            $sql = 'INSERT INTO "'.$table.'" ('.implode(',', $quotedColumns).') '
                .'SELECT '.implode(',', $selectColumns).' FROM (VALUES '.implode(',', $placeholders).') AS v ('.implode(',', $quotedColumns).') '
                .'WHERE NOT EXISTS (SELECT 1 FROM "'.$table.'" t WHERE '.implode(' AND ', $conditions).')';

            DB::statement($sql, $bindings);
        }
    }

    private function safeChunkSize(int $requestedChunkSize, int $columnCount): int
    {
        if ($columnCount <= 0) {
            return max(1, $requestedChunkSize);
        }

        return max(1, min($requestedChunkSize, 1000, intdiv(30000, $columnCount)));
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @param list<string> $uniqueBy
     * @return list<array<string, mixed>>
     */
    private function uniqueRows(array $rows, array $uniqueBy): array
    {
        $seen = [];
        $unique = [];
        foreach ($rows as $row) {
            $keyParts = [];
            foreach ($uniqueBy as $column) {
                $keyParts[] = (string) ($row[$column] ?? '');
            }
            $key = implode('|', $keyParts);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $row;
        }

        return $unique;
    }

    private function typedValueExpression(string $sourceColumn, string $targetColumn): string
    {
        $expression = 'v."'.$sourceColumn.'"';

        if (str_ends_with($targetColumn, '_id')) {
            return 'CAST('.$expression.' AS bigint)';
        }

        if (in_array($targetColumn, ['numero_linea', 'orden_origen', 'orden_cobol', 'orden_liquidacion', 'orden_impresion', 'secuencia_item'], true)) {
            return 'CAST('.$expression.' AS integer)';
        }

        if (str_starts_with($targetColumn, 'fecha')) {
            return 'CAST('.$expression.' AS date)';
        }

        return $expression;
    }

    private function typedInsertExpression(string $column): string
    {
        $expression = 'v."'.$column.'"';

        if (str_ends_with($column, '_id')) {
            return 'CAST('.$expression.' AS bigint)';
        }

        if (in_array($column, ['numero_linea', 'orden_origen', 'orden_cobol', 'orden_liquidacion', 'orden_impresion', 'secuencia_item', 'nro_ultima_liquidacion', 'plazo_meses'], true)) {
            return 'CAST('.$expression.' AS integer)';
        }

        if (in_array($column, ['payload_normalizado', 'resumen', 'parametros', 'metadata', 'datos_origen', 'datos_calculo'], true)) {
            return 'CAST('.$expression.' AS jsonb)';
        }

        if (in_array($column, ['created_at', 'updated_at', 'deleted_at', 'fecha_importacion', 'fecha_generacion'], true)) {
            return 'CAST('.$expression.' AS timestamp with time zone)';
        }

        if (str_starts_with($column, 'fecha')) {
            return 'CAST('.$expression.' AS date)';
        }

        if (in_array($column, ['debe', 'haber', 'saldo', 'importe', 'importe_original', 'importe_convertido', 'cotizacion_aplicada', 'porcentaje_participacion', 'comision_administracion', 'comision_impuestos', 'cuota_1', 'cuota_2', 'alquiler_inicial', 'penalidad', 'abonado', 'iva', 'no_gravado'], true)) {
            return 'CAST('.$expression.' AS numeric)';
        }

        if (str_starts_with($column, 'es_') || in_array($column, ['liquidar', 'liquidacion_sin_reserva'], true)) {
            return 'CAST('.$expression.' AS boolean)';
        }

        return $expression;
    }

    /**
     * @param array<string, array{line:int, raw:string}> $propietarios
     * @param array<string, array{line:int, raw:string}> $inquilinos
     */
    private function upsertLote(array $propietarios, array $inquilinos): int
    {
        $codigo = 'piloto-cobol-'.hash('sha256', implode(',', array_keys($propietarios)).'|'.implode(',', array_keys($inquilinos)));

        DB::table('web_lotes_importacion')->updateOrInsert(
            ['codigo_lote' => $codigo],
            [
                'repositorio_id' => 0,
                'periodo_detectado' => null,
                'origen' => 'COBOL',
                'estado' => 'IMPORTADO',
                'version_importador' => self::VERSION,
                'version_parser' => self::VERSION,
                'version_regla' => self::VERSION,
                'resumen' => json_encode([
                    'tipo' => 'piloto_cobol',
                    'propietarios' => array_keys($propietarios),
                    'inquilinos' => array_keys($inquilinos),
                ], JSON_UNESCAPED_UNICODE),
                'observaciones' => 'Importador piloto experimental. Solo base temporal db_gei_web_migraciones_test.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('web_lotes_importacion')->where('codigo_lote', $codigo)->value('id');
    }

    private function upsertArchivo(int $loteId, string $nombre): int
    {
        $path = $this->path($nombre);
        $tipo = pathinfo($nombre, PATHINFO_FILENAME);

        DB::table('web_archivos_importados')->updateOrInsert(
            [
                'lote_importacion_id' => $loteId,
                'tipo_archivo' => $tipo,
                'nombre_original' => $nombre,
            ],
            [
                'ruta_logica' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $path),
                'hash_archivo' => hash_file('sha256', $path),
                'tamano_bytes' => filesize($path),
                'encoding_detectado' => 'ISO-8859-1',
                'cantidad_lineas' => $this->contarLineas($path),
                'estado' => 'IMPORTADO',
                'resumen' => json_encode(['piloto' => true], JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('web_archivos_importados')
            ->where('lote_importacion_id', $loteId)
            ->where('tipo_archivo', $tipo)
            ->where('nombre_original', $nombre)
            ->value('id');
    }

    /**
     * @param array{line:int, raw:string} $linea
     */
    private function upsertRegistro(int $loteId, int $archivoId, string $archivo, string $tipoRegistro, array $linea): int
    {
        $tipoArchivo = pathinfo($archivo, PATHINFO_FILENAME);
        $raw = $linea['raw'];
        $clave = $this->claveRegistro($tipoArchivo, $raw);

        DB::table('web_registros_origen')->updateOrInsert(
            [
                'archivo_importado_id' => $archivoId,
                'numero_linea' => $linea['line'],
            ],
            [
                'lote_importacion_id' => $loteId,
                'archivo_origen' => $archivo,
                'tipo_archivo' => $tipoArchivo,
                'tipo_registro' => $tipoRegistro,
                'orden_origen' => $linea['line'],
                'clave_origen' => $clave,
                'hash_registro' => hash('sha256', $raw),
                'contenido_original' => $raw,
                'payload_normalizado' => json_encode(['clave_origen' => $clave, 'piloto' => true], JSON_UNESCAPED_UNICODE),
                'version_parser' => self::VERSION,
                'version_regla' => self::VERSION,
                'origen' => 'COBOL',
                'estado' => 'GENERADO',
                'entidad_destino' => null,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('web_registros_origen')
            ->where('archivo_importado_id', $archivoId)
            ->where('numero_linea', $linea['line'])
            ->value('id');
    }

    private function upsertPersonaPropietario(int $loteId, int $archivoId, int $registroId, string $raw): int
    {
        DB::table('web_personas')->updateOrInsert(
            ['registro_origen_id' => $registroId],
            [
                'tipo_persona' => 'DESCONOCIDA',
                'nombre' => $this->texto(substr($raw, 11, 35)),
                'razon_social' => $this->texto(substr($raw, 11, 35)),
                'cuit' => $this->texto(substr($raw, 185, 12)),
                'personeria_fiscal' => $this->texto(substr($raw, 184, 1)),
                'telefono' => $this->texto(substr($raw, 116, 14)),
                'domicilio_principal' => $this->texto(substr($raw, 46, 30)),
                'codigo_postal' => $this->texto(substr($raw, 76, 4)),
                'localidad' => $this->texto(substr($raw, 80, 26)),
                'provincia' => $this->texto(substr($raw, 106, 10)),
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'hash_origen' => hash('sha256', $raw),
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('web_personas')->where('registro_origen_id', $registroId)->value('id');
    }

    private function upsertPersonaInquilino(int $loteId, int $archivoId, int $registroId, string $raw): int
    {
        DB::table('web_personas')->updateOrInsert(
            ['registro_origen_id' => $registroId],
            [
                'tipo_persona' => 'DESCONOCIDA',
                'nombre' => $this->texto(substr($raw, 22, 35)),
                'tipo_documento' => $this->texto(substr($raw, 347, 1)),
                'numero_documento' => $this->texto(substr($raw, 348, 9)),
                'cuit' => $this->texto(substr($raw, 527, 12)),
                'personeria_fiscal' => $this->texto(substr($raw, 526, 1)),
                'telefono' => $this->texto(substr($raw, 156, 14)),
                'domicilio_principal' => $this->texto(substr($raw, 357, 35)),
                'codigo_postal' => $this->texto(substr($raw, 392, 4)),
                'localidad' => $this->texto(substr($raw, 396, 26)),
                'provincia' => $this->texto(substr($raw, 422, 10)),
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'hash_origen' => hash('sha256', $raw),
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('web_personas')->where('registro_origen_id', $registroId)->value('id');
    }

    private function upsertPropietario(int $loteId, int $archivoId, int $registroId, int $personaId, string $raw): int
    {
        $cuenta = substr($raw, 0, 11);

        DB::table('web_propietarios')->updateOrInsert(
            ['cuenta_propietario' => $cuenta],
            [
                'persona_id' => $personaId,
                'forma_pago_codigo' => $this->texto(substr($raw, 151, 2)),
                'subforma_pago_codigo' => $this->texto(substr($raw, 153, 1)),
                'cuenta_deposito' => $this->texto(substr($raw, 167, 14)),
                'liquidar' => $this->texto(substr($raw, 154, 1)) !== 'N',
                'liquidacion_sin_reserva' => $this->texto(substr($raw, 144, 1)) === 'S',
                'comision_administracion' => $this->decimal(substr($raw, 148, 3), 1),
                'comision_impuestos' => $this->decimal(substr($raw, 145, 3), 1),
                'nro_ultima_liquidacion' => (int) $this->texto(substr($raw, 155, 4)),
                'fecha_ultima_liquidacion' => $this->fechaYmd(substr($raw, 159, 8)),
                'marca_sucursal' => $this->texto(substr($raw, 197, 1)),
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'registro_origen_id' => $registroId,
                'hash_origen' => hash('sha256', $raw),
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('web_propietarios')->where('cuenta_propietario', $cuenta)->value('id');
    }

    private function upsertInquilino(int $loteId, int $archivoId, int $registroId, int $personaId, string $raw): int
    {
        $cuenta = substr($raw, 0, 11);

        DB::table('web_inquilinos')->updateOrInsert(
            ['cuenta_inquilino' => $cuenta],
            [
                'persona_id' => $personaId,
                'telefono_particular' => $this->texto(substr($raw, 156, 14)),
                'telefono_laboral' => $this->texto(substr($raw, 170, 14)),
                'marca_baja' => $this->texto(substr($raw, 144, 1)),
                'fecha_baja' => $this->fechaDmy(substr($raw, 145, 8)),
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'registro_origen_id' => $registroId,
                'hash_origen' => hash('sha256', $raw),
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('web_inquilinos')->where('cuenta_inquilino', $cuenta)->value('id');
    }

    private function upsertInmueble(int $loteId, int $archivoId, int $registroId, string $raw): int
    {
        $cuentaPropietario = substr($raw, 11, 11);
        $domicilio = $this->texto(substr($raw, 57, 35));
        $codigo = hash('sha256', $cuentaPropietario.'|'.$domicilio);

        DB::table('web_inmuebles')->updateOrInsert(
            ['codigo_origen' => $codigo],
            [
                'domicilio' => $domicilio !== '' ? $domicilio : 'SIN DOMICILIO',
                'domicilio_normalizado' => strtoupper($domicilio),
                'destino_codigo' => $this->texto(substr($raw, 289, 3)),
                'cochera_codigo' => $this->texto(substr($raw, 547, 2)),
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'registro_origen_id' => $registroId,
                'hash_origen' => hash('sha256', $raw),
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('web_inmuebles')->where('codigo_origen', $codigo)->value('id');
    }

    private function upsertContrato(int $loteId, int $archivoId, int $registroId, string $raw): int
    {
        $codigo = $this->codigoContrato($raw);
        [$fechaInicio, $fechaFin] = $this->rangoContrato($raw);

        DB::table('web_contratos')->updateOrInsert(
            ['codigo_origen' => $codigo],
            [
                'cuenta_inquilino_origen' => substr($raw, 0, 11),
                'cuenta_propietario_origen' => substr($raw, 11, 11),
                'fecha_contrato' => $this->fechaDmy(substr($raw, 92, 8)),
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'fecha_baja' => $this->fechaDmy(substr($raw, 145, 8)),
                'marca_baja' => $this->texto(substr($raw, 144, 1)),
                'plazo_meses' => (int) $this->texto(substr($raw, 116, 3)),
                'indice_ajuste' => $this->texto(substr($raw, 119, 3)),
                'tipo_ajuste' => $this->texto(substr($raw, 122, 2)),
                'fecha_primer_ajuste' => $this->fechaDmy(substr($raw, 108, 8)),
                'cuota_1' => $this->decimal(substr($raw, 124, 10), 2),
                'cuota_2' => $this->decimal(substr($raw, 134, 10), 2),
                'alquiler_inicial' => $this->decimal(substr($raw, 333, 10), 2),
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'registro_origen_id' => $registroId,
                'hash_origen' => hash('sha256', $raw),
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('web_contratos')->where('codigo_origen', $codigo)->value('id');
    }

    /**
     * @param array<string, mixed> $keys
     */
    private function upsertRelacion(string $tabla, array $keys, int $loteId, int $archivoId, int $registroId): void
    {
        DB::table($tabla)->updateOrInsert(
            $keys,
            array_merge($keys, [
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'archivo_origen_id' => $archivoId,
                'registro_origen_id' => $registroId,
                'hash_origen' => hash('sha256', $tabla.json_encode($keys)),
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'updated_at' => now(),
                'created_at' => now(),
            ])
        );
    }

    private function upsertCuentaCorriente(
        int $loteId,
        string $dominio,
        string $cuenta,
        int $personaId,
        ?int $propietarioId,
        ?int $inquilinoId
    ): int {
        DB::table('web_cuentas_corrientes')->updateOrInsert(
            ['dominio' => $dominio, 'cuenta_origen' => $cuenta],
            [
                'persona_id' => $personaId,
                'propietario_id' => $propietarioId,
                'inquilino_id' => $inquilinoId,
                'moneda' => 'ARS',
                'origen' => 'COBOL',
                'lote_importacion_id' => $loteId,
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('web_cuentas_corrientes')
            ->where('dominio', $dominio)
            ->where('cuenta_origen', $cuenta)
            ->value('id');
    }

    private function upsertMovimientoPropietario(
        int $loteId,
        int $archivoId,
        int $registroId,
        int $cuentaId,
        int $propietarioId,
        string $raw
    ): void {
        $codigo = $this->texto(substr($raw, 19, 2));
        $numero = $this->texto(substr($raw, 21, 6));
        $importe = $this->signedDecimal(substr($raw, 27, 12), 2);
        $debe = ((int) $codigo >= 21) ? $this->absDecimal($importe) : '0.00';
        $haber = ((int) $codigo < 21) ? $this->absDecimal($importe) : '0.00';
        $conceptoId = $this->upsertConcepto('PROPIETARIO', $codigo, $this->texto(substr($raw, 39, 40)));

        DB::table('web_movimientos_cuenta')->updateOrInsert(
            $this->movimientoKeys('PROPIETARIO', substr($raw, 0, 11), $codigo, $numero, $raw),
            [
                'cuenta_corriente_id' => $cuentaId,
                'propietario_id' => $propietarioId,
                'fecha' => $this->fechaYmd(substr($raw, 11, 8)),
                'periodo' => substr($raw, 11, 6),
                'concepto_id' => $conceptoId,
                'descripcion' => $this->texto(substr($raw, 39, 40)),
                'importe' => $importe,
                'debe' => $debe,
                'haber' => $haber,
                'iva' => $this->decimal(substr($raw, 91, 10), 2),
                'no_gravado' => $this->decimal(substr($raw, 101, 10), 2),
                'liquidado_origen' => $this->texto(substr($raw, 90, 1)),
                'archivo_origen_id' => $archivoId,
                'registro_origen_id' => $registroId,
                'origen' => 'COBOL',
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function upsertMovimientoInquilino(
        int $loteId,
        int $archivoId,
        int $registroId,
        int $cuentaId,
        int $inquilinoId,
        ?int $contratoId,
        ?int $inmuebleId,
        string $raw
    ): void {
        $codigo = $this->texto(substr($raw, 19, 2));
        $numero = $this->texto(substr($raw, 21, 6));
        $importe = $this->signedDecimal(substr($raw, 27, 12), 2);
        $debe = ((float) $importe >= 0) ? $this->absDecimal($importe) : '0.00';
        $haber = ((float) $importe < 0) ? $this->absDecimal($importe) : '0.00';
        $conceptoId = $this->upsertConcepto('INQUILINO', $codigo, $this->texto(substr($raw, 63, 40)));

        DB::table('web_movimientos_cuenta')->updateOrInsert(
            $this->movimientoKeys('INQUILINO', substr($raw, 0, 11), $codigo, $numero, $raw),
            [
                'cuenta_corriente_id' => $cuentaId,
                'contrato_id' => $contratoId,
                'inmueble_id' => $inmuebleId,
                'inquilino_id' => $inquilinoId,
                'fecha' => $this->fechaYmd(substr($raw, 11, 8)),
                'periodo' => substr($raw, 11, 6),
                'concepto_id' => $conceptoId,
                'descripcion' => $this->texto(substr($raw, 63, 40)),
                'importe' => $importe,
                'debe' => $debe,
                'haber' => $haber,
                'penalidad' => $this->signedDecimal(substr($raw, 39, 12), 2),
                'abonado' => $this->signedDecimal(substr($raw, 51, 12), 2),
                'archivo_origen_id' => $archivoId,
                'registro_origen_id' => $registroId,
                'origen' => 'COBOL',
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    private function upsertConcepto(string $dominio, string $codigo, string $descripcion): int
    {
        DB::table('web_conceptos_movimiento')->updateOrInsert(
            ['dominio' => $dominio, 'codigo_origen' => $codigo],
            [
                'descripcion' => $descripcion !== '' ? $descripcion : "Concepto {$codigo}",
                'afecta' => 'SEGUN_SIGNO',
                'requiere_iva' => false,
                'genera_item_liquidacion' => true,
                'origen' => 'COBOL',
                'version_regla' => self::VERSION,
                'estado' => 'ACTIVO',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('web_conceptos_movimiento')
            ->where('dominio', $dominio)
            ->where('codigo_origen', $codigo)
            ->value('id');
    }

    /**
     * @param list<array{line:int, raw:string}> $movimientos
     * @param array<string, int> $cuentasCorrientes
     * @param array<string, int> $propietariosIds
     */
    private function procesarMovimientosPropietarios(
        int $loteId,
        int $archivoId,
        array $movimientos,
        array $cuentasCorrientes,
        array $propietariosIds
    ): void {
        $conceptos = [];
        foreach (array_chunk($movimientos, 500) as $chunk) {
            $registroIds = $this->upsertRegistrosBatch($loteId, $archivoId, 'CTACTEPRO.TXT', 'cuenta_propietario', $chunk);
            $rows = [];
            foreach ($chunk as $linea) {
                $raw = $linea['raw'];
                $cuenta = substr($raw, 0, 11);
                if (! isset($cuentasCorrientes[$cuenta], $propietariosIds[$cuenta], $registroIds[$linea['line']])) {
                    continue;
                }

                $codigo = $this->texto(substr($raw, 19, 2));
                $numero = $this->texto(substr($raw, 21, 6));
                $importe = $this->signedDecimal(substr($raw, 27, 12), 2);
                $conceptoId = $this->conceptoCached($conceptos, 'PROPIETARIO', $codigo, $this->texto(substr($raw, 39, 40)));

                $rows[] = [
                    'cuenta_corriente_id' => $cuentasCorrientes[$cuenta],
                    'dominio' => 'PROPIETARIO',
                    'cuenta_origen' => $cuenta,
                    'contrato_id' => null,
                    'inmueble_id' => null,
                    'propietario_id' => $propietariosIds[$cuenta],
                    'inquilino_id' => null,
                    'fecha' => $this->fechaYmd(substr($raw, 11, 8)),
                    'periodo' => substr($raw, 11, 6),
                    'codigo_concepto' => $codigo,
                    'concepto_id' => $conceptoId,
                    'numero_movimiento' => $numero,
                    'descripcion' => $this->texto(substr($raw, 39, 40)),
                    'importe' => $importe,
                    'debe' => ((int) $codigo >= 21) ? $this->absDecimal($importe) : '0.00',
                    'haber' => ((int) $codigo < 21) ? $this->absDecimal($importe) : '0.00',
                    'penalidad' => null,
                    'abonado' => null,
                    'iva' => $this->decimal(substr($raw, 91, 10), 2),
                    'no_gravado' => $this->decimal(substr($raw, 101, 10), 2),
                    'liquidado_origen' => $this->texto(substr($raw, 90, 1)),
                    'archivo_origen_id' => $archivoId,
                    'registro_origen_id' => $registroIds[$linea['line']],
                    'hash_origen' => hash('sha256', $raw),
                    'origen' => 'COBOL',
                    'version_regla' => self::VERSION,
                    'estado' => 'ACTIVO',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            $this->bulkUpsertMovimientos($rows);
        }
    }

    /**
     * @param list<array{line:int, raw:string}> $movimientos
     * @param array<string, int> $cuentasCorrientes
     * @param array<string, int> $inquilinosIds
     * @param array<string, int> $contratosIds
     * @param array<string, int> $inmueblesIds
     */
    private function procesarMovimientosInquilinos(
        int $loteId,
        int $archivoId,
        array $movimientos,
        array $cuentasCorrientes,
        array $inquilinosIds,
        array $contratosIds,
        array $inmueblesIds
    ): void {
        $conceptos = [];
        foreach (array_chunk($movimientos, 500) as $chunk) {
            $registroIds = $this->upsertRegistrosBatch($loteId, $archivoId, 'INQCTACTE.TXT', 'cuenta_inquilino', $chunk);
            $rows = [];
            foreach ($chunk as $linea) {
                $raw = $linea['raw'];
                $cuenta = substr($raw, 0, 11);
                if (! isset($cuentasCorrientes[$cuenta], $inquilinosIds[$cuenta], $registroIds[$linea['line']])) {
                    continue;
                }

                $codigo = $this->texto(substr($raw, 19, 2));
                $numero = $this->texto(substr($raw, 21, 6));
                $importe = $this->signedDecimal(substr($raw, 27, 12), 2);
                $conceptoId = $this->conceptoCached($conceptos, 'INQUILINO', $codigo, $this->texto(substr($raw, 63, 40)));

                $rows[] = [
                    'cuenta_corriente_id' => $cuentasCorrientes[$cuenta],
                    'dominio' => 'INQUILINO',
                    'cuenta_origen' => $cuenta,
                    'contrato_id' => $contratosIds[$cuenta] ?? null,
                    'inmueble_id' => $inmueblesIds[$cuenta] ?? null,
                    'propietario_id' => null,
                    'inquilino_id' => $inquilinosIds[$cuenta],
                    'fecha' => $this->fechaYmd(substr($raw, 11, 8)),
                    'periodo' => substr($raw, 11, 6),
                    'codigo_concepto' => $codigo,
                    'concepto_id' => $conceptoId,
                    'numero_movimiento' => $numero,
                    'descripcion' => $this->texto(substr($raw, 63, 40)),
                    'importe' => $importe,
                    'debe' => ((float) $importe >= 0) ? $this->absDecimal($importe) : '0.00',
                    'haber' => ((float) $importe < 0) ? $this->absDecimal($importe) : '0.00',
                    'penalidad' => $this->signedDecimal(substr($raw, 39, 12), 2),
                    'abonado' => $this->signedDecimal(substr($raw, 51, 12), 2),
                    'iva' => null,
                    'no_gravado' => null,
                    'liquidado_origen' => null,
                    'archivo_origen_id' => $archivoId,
                    'registro_origen_id' => $registroIds[$linea['line']],
                    'hash_origen' => hash('sha256', $raw),
                    'origen' => 'COBOL',
                    'version_regla' => self::VERSION,
                    'estado' => 'ACTIVO',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            $this->bulkUpsertMovimientos($rows);
        }
    }

    /**
     * @param list<array{line:int, raw:string}> $lineas
     * @return array<int, int>
     */
    private function upsertRegistrosBatch(int $loteId, int $archivoId, string $archivo, string $tipoRegistro, array $lineas): array
    {
        $tipoArchivo = pathinfo($archivo, PATHINFO_FILENAME);
        $now = now();
        $rows = [];
        foreach ($lineas as $linea) {
            $raw = $linea['raw'];
            $clave = $this->claveRegistro($tipoArchivo, $raw);
            $rows[] = [
                'lote_importacion_id' => $loteId,
                'archivo_importado_id' => $archivoId,
                'archivo_origen' => $archivo,
                'tipo_archivo' => $tipoArchivo,
                'tipo_registro' => $tipoRegistro,
                'numero_linea' => $linea['line'],
                'orden_origen' => $linea['line'],
                'clave_origen' => $clave,
                'hash_registro' => hash('sha256', $raw),
                'contenido_original' => $raw,
                'payload_normalizado' => json_encode(['clave_origen' => $clave, 'piloto' => true], JSON_UNESCAPED_UNICODE),
                'version_parser' => self::VERSION,
                'version_regla' => self::VERSION,
                'origen' => 'COBOL',
                'estado' => 'GENERADO',
                'entidad_destino' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $this->insertWhereNotExistsComposite(
            'web_registros_origen',
            array_keys($rows[0] ?? []),
            $rows,
            ['archivo_importado_id', 'numero_linea']
        );

        return DB::table('web_registros_origen')
            ->where('archivo_importado_id', $archivoId)
            ->whereIn('numero_linea', array_column($lineas, 'line'))
            ->pluck('id', 'numero_linea')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param array<string, int> $cache
     */
    private function conceptoCached(array &$cache, string $dominio, string $codigo, string $descripcion): int
    {
        $key = "{$dominio}|{$codigo}";
        if (! isset($cache[$key])) {
            $cache[$key] = $this->upsertConcepto($dominio, $codigo, $descripcion);
        }

        return $cache[$key];
    }

    /**
     * @param list<array<string, mixed>> $rows
     */
    private function bulkUpsertMovimientos(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $columns = array_keys($rows[0]);
        $quotedColumns = array_map(fn ($column) => "\"{$column}\"", $columns);
        $placeholders = [];
        $bindings = [];
        foreach ($rows as $row) {
            $placeholders[] = '('.implode(',', array_fill(0, count($columns), '?')).')';
            foreach ($columns as $column) {
                $bindings[] = $row[$column];
            }
        }

        $sql = 'INSERT INTO "web_movimientos_cuenta" ('.implode(',', $quotedColumns).') '
            .'SELECT '.implode(',', array_map(fn ($column) => $this->typedInsertExpression($column), $columns)).' '
            .'FROM (VALUES '.implode(',', $placeholders).') AS v ('.implode(',', $quotedColumns).') '
            .'WHERE NOT EXISTS (SELECT 1 FROM "web_movimientos_cuenta" t WHERE t."registro_origen_id" = CAST(v."registro_origen_id" AS bigint))';

        DB::statement($sql, $bindings);
    }

    /**
     * @return array<string, string>
     */
    private function movimientoKeys(string $dominio, string $cuenta, string $codigo, string $numero, string $raw): array
    {
        return [
            'dominio' => $dominio,
            'cuenta_origen' => $cuenta,
            'codigo_concepto' => $codigo,
            'numero_movimiento' => $numero,
            'hash_origen' => hash('sha256', $raw),
        ];
    }

    /**
     * @return \Generator<int, array{line:int, raw:string}>
     */
    private function leerLineas(string $archivo): \Generator
    {
        $handle = fopen($this->path($archivo), 'rb');
        if ($handle === false) {
            throw new RuntimeException("No se pudo abrir {$archivo}.");
        }

        $line = 0;
        while (($raw = fgets($handle)) !== false) {
            $line++;
            yield [
                'line' => $line,
                'raw' => rtrim(mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1'), "\r\n"),
            ];
        }

        fclose($handle);
    }

    private function path(string $archivo): string
    {
        $path = $this->basePath.DIRECTORY_SEPARATOR.$archivo;
        if (! is_file($path)) {
            throw new RuntimeException("Archivo COBOL no encontrado: {$path}");
        }

        return $path;
    }

    private function claveRegistro(string $tipoArchivo, string $raw): string
    {
        return match ($tipoArchivo) {
            'PROPIETAR' => substr($raw, 0, 11),
            'INQUILINO' => substr($raw, 0, 11).'|'.substr($raw, 11, 11).'|'.substr($raw, 57, 35).'|'.substr($raw, 92, 8).'|'.substr($raw, 100, 8),
            'CTACTEPRO', 'INQCTACTE' => substr($raw, 0, 11).'|'.substr($raw, 11, 8).'|'.substr($raw, 19, 2).'|'.substr($raw, 21, 6),
            default => hash('sha256', $raw),
        };
    }

    private function codigoContrato(string $raw): string
    {
        return implode('|', [
            substr($raw, 0, 11),
            substr($raw, 11, 11),
            $this->texto(substr($raw, 57, 35)),
            substr($raw, 92, 8),
            substr($raw, 100, 8),
            substr($raw, 325, 8),
        ]);
    }

    private function codigoInmueble(string $raw): string
    {
        return hash('sha256', substr($raw, 11, 11).'|'.$this->texto(substr($raw, 57, 35)));
    }

    private function contarLineas(string $path): int
    {
        $count = 0;
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return 0;
        }
        while (fgets($handle) !== false) {
            $count++;
        }
        fclose($handle);

        return $count;
    }

    private function texto(string $value): string
    {
        return trim($value);
    }

    private function decimal(string $value, int $scale): string
    {
        $digits = preg_replace('/\D/', '', $value) ?: '0';
        $number = ((int) $digits) / (10 ** $scale);

        return number_format($number, $scale, '.', '');
    }

    private function signedDecimal(string $value, int $scale): string
    {
        $sign = str_ends_with($value, '-') ? -1 : 1;
        $number = $sign * (float) $this->decimal($value, $scale);

        return number_format($number, $scale, '.', '');
    }

    private function absDecimal(string $value): string
    {
        return number_format(abs((float) $value), 2, '.', '');
    }

    private function fechaYmd(string $value): ?string
    {
        if (! preg_match('/^\d{8}$/', $value) || $value === '00000000') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('Ymd', $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    private function fechaDmy(string $value): ?string
    {
        if (! preg_match('/^\d{8}$/', $value) || $value === '00000000') {
            return null;
        }

        try {
            return CarbonImmutable::createFromFormat('dmY', $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private function rangoContrato(string $raw): array
    {
        $inicio = $this->fechaDmy(substr($raw, 325, 8));
        $fin = $this->fechaDmy(substr($raw, 100, 8));

        if ($inicio !== null && $fin !== null && $inicio > $fin) {
            return [null, $fin];
        }

        return [$inicio, $fin];
    }

    /**
     * @return array<string, int>
     */
    private function conteos(): array
    {
        $tables = [
            'web_lotes_importacion',
            'web_archivos_importados',
            'web_registros_origen',
            'web_personas',
            'web_propietarios',
            'web_inquilinos',
            'web_inmuebles',
            'web_contratos',
            'web_contrato_inquilinos',
            'web_contrato_propietarios',
            'web_contrato_inmuebles',
            'web_inmuebles_propietarios',
            'web_cuentas_corrientes',
            'web_conceptos_movimiento',
            'web_movimientos_cuenta',
        ];

        $out = [];
        foreach ($tables as $table) {
            $out[$table] = DB::table($table)->count();
        }

        return $out;
    }

    /**
     * @param array<string, int> $before
     * @param array<string, int> $after
     * @return array<string, int>
     */
    private function delta(array $before, array $after): array
    {
        $delta = [];
        foreach ($after as $table => $count) {
            $delta[$table] = $count - ($before[$table] ?? 0);
        }

        return $delta;
    }

    /**
     * @return array<string, int|float>
     */
    private function metricas(float $inicio): array
    {
        $duracion = round(microtime(true) - $inicio, 3);

        return [
            'duracion_segundos' => $duracion,
            'memoria_pico_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
        ];
    }
}
