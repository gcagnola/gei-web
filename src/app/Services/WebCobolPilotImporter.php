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
     *     base_dir: string,
     *     limite_propietarios: int,
     *     limite_inquilinos: int,
     *     limite_movimientos_propietario: int,
     *     limite_movimientos_inquilino: int,
     *     cuenta_propietario?: ?string,
     *     cuenta_inquilino?: ?string,
     *     dry_run: bool
     * } $options
     * @return array<string, mixed>
     */
    public function importar(array $options): array
    {
        $database = DB::connection()->getDatabaseName();
        $this->assertTemporalDatabase($database);

        $this->basePath = $this->resolveBasePath($options['base_dir']);

        foreach (['PROPIETAR.TXT', 'INQUILINO.TXT', 'CTACTEPRO.TXT', 'INQCTACTE.TXT'] as $archivo) {
            $this->path($archivo);
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
            'version' => self::VERSION,
            'candidatos' => [
                'propietarios' => count($propietarios),
                'inquilinos' => count($inquilinos),
                'movimientos_propietario' => count($movimientosPropietarios),
                'movimientos_inquilino' => count($movimientosInquilinos),
            ],
            'tablas' => [],
            'errores' => [],
        ];

        if ($options['dry_run']) {
            $resultado['mensaje'] = 'Dry-run completado: no se escribieron tablas.';

            return $resultado;
        }

        return DB::transaction(function () use ($propietarios, $inquilinos, $movimientosPropietarios, $movimientosInquilinos, $resultado): array {
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

            foreach ($movimientosPropietarios as $linea) {
                $raw = $linea['raw'];
                $cuenta = substr($raw, 0, 11);
                if (! isset($cuentasCorrientesPropietario[$cuenta], $propietariosIds[$cuenta])) {
                    continue;
                }

                $registroId = $this->upsertRegistro($loteId, $archivos['CTACTEPRO.TXT'], 'CTACTEPRO.TXT', 'cuenta_propietario', $linea);
                $this->upsertMovimientoPropietario(
                    $loteId,
                    $archivos['CTACTEPRO.TXT'],
                    $registroId,
                    $cuentasCorrientesPropietario[$cuenta],
                    $propietariosIds[$cuenta],
                    $raw
                );
            }

            foreach ($movimientosInquilinos as $linea) {
                $raw = $linea['raw'];
                $cuenta = substr($raw, 0, 11);
                if (! isset($cuentasCorrientesInquilino[$cuenta], $inquilinosIds[$cuenta])) {
                    continue;
                }

                $registroId = $this->upsertRegistro($loteId, $archivos['INQCTACTE.TXT'], 'INQCTACTE.TXT', 'cuenta_inquilino', $linea);
                $this->upsertMovimientoInquilino(
                    $loteId,
                    $archivos['INQCTACTE.TXT'],
                    $registroId,
                    $cuentasCorrientesInquilino[$cuenta],
                    $inquilinosIds[$cuenta],
                    $contratosIds[$cuenta] ?? null,
                    $inmueblesIds[$cuenta] ?? null,
                    $raw
                );
            }

            $after = $this->conteos();
            $resultado['tablas'] = [
                'antes' => $before,
                'despues' => $after,
                'delta' => $this->delta($before, $after),
            ];
            $resultado['mensaje'] = 'Importacion piloto completada sobre base temporal.';

            return $resultado;
        });
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
    private function seleccionarPropietarios(?string $cuenta, int $limite): array
    {
        $seleccion = [];
        foreach ($this->leerLineas('PROPIETAR.TXT') as $linea) {
            $cuentaLinea = substr($linea['raw'], 0, 11);
            if ($cuenta !== null && $cuentaLinea !== $cuenta) {
                continue;
            }

            $seleccion[$cuentaLinea] = $linea;
            if ($cuenta !== null || count($seleccion) >= $limite) {
                break;
            }
        }

        return $seleccion;
    }

    /**
     * @return array<string, array{line:int, raw:string}>
     */
    private function seleccionarInquilinos(?string $cuenta, int $limite): array
    {
        $seleccion = [];
        foreach ($this->leerLineas('INQUILINO.TXT') as $linea) {
            $cuentaLinea = substr($linea['raw'], 0, 11);
            if ($cuenta !== null && $cuentaLinea !== $cuenta) {
                continue;
            }

            $seleccion[$cuentaLinea] = $linea;
            if ($cuenta !== null || count($seleccion) >= $limite) {
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
    private function seleccionarMovimientos(string $archivo, array $cuentas, int $limite): array
    {
        if ($cuentas === [] || $limite <= 0) {
            return [];
        }

        $set = array_fill_keys($cuentas, true);
        $seleccion = [];
        foreach ($this->leerLineas($archivo) as $linea) {
            if (! isset($set[substr($linea['raw'], 0, 11)])) {
                continue;
            }

            $seleccion[] = $linea;
            if (count($seleccion) >= $limite) {
                break;
            }
        }

        return $seleccion;
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

        DB::table('web_contratos')->updateOrInsert(
            ['codigo_origen' => $codigo],
            [
                'cuenta_inquilino_origen' => substr($raw, 0, 11),
                'cuenta_propietario_origen' => substr($raw, 11, 11),
                'fecha_contrato' => $this->fechaDmy(substr($raw, 92, 8)),
                'fecha_inicio' => $this->fechaDmy(substr($raw, 325, 8)),
                'fecha_fin' => $this->fechaDmy(substr($raw, 100, 8)),
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
}
