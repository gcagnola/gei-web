<?php

namespace Database\Seeders;

use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class WebModeloNuevoCargaMinimaCobolSeeder extends Seeder
{
    private const VERSION = 'carga-minima-cobol-v1';
    private const CUENTA_PROPIETARIO = '12020240300';
    private const CUENTA_INQUILINO = '11032433700';

    private string $basePath;

    public function run(): void
    {
        $database = DB::connection()->getDatabaseName();

        if ($database === 'db_gei') {
            throw new RuntimeException('Carga minima COBOL abortada: la conexion apunta a db_gei.');
        }

        if ($database !== 'db_gei_web_migraciones_test') {
            throw new RuntimeException("Carga minima COBOL abortada: base no temporal detectada ({$database}).");
        }

        $this->basePath = env(
            'GEI_COBOL_SAMPLE_DIR',
            storage_path('app/private/liquidaciones/cobol')
        );

        DB::transaction(function (): void {
            $loteId = $this->upsertLote();

            $propietario = $this->buscarLinea('PROPIETAR.TXT', self::CUENTA_PROPIETARIO, 0, 11);
            $inquilino = $this->buscarLinea('INQUILINO.TXT', self::CUENTA_INQUILINO, 0, 11);
            $movimientosPropietario = $this->buscarLineas('CTACTEPRO.TXT', self::CUENTA_PROPIETARIO, 0, 11, 3);
            $movimientosInquilino = $this->buscarLineas('INQCTACTE.TXT', self::CUENTA_INQUILINO, 0, 11, 3);

            $archivos = [];
            foreach (['PROPIETAR.TXT', 'INQUILINO.TXT', 'CTACTEPRO.TXT', 'INQCTACTE.TXT'] as $nombre) {
                $archivos[$nombre] = $this->upsertArchivo($loteId, $nombre);
            }

            $registroPropietarioId = $this->upsertRegistro($loteId, $archivos['PROPIETAR.TXT'], 'PROPIETAR.TXT', 'propietario', $propietario);
            $registroInquilinoId = $this->upsertRegistro($loteId, $archivos['INQUILINO.TXT'], 'INQUILINO.TXT', 'inquilino', $inquilino);

            $personaPropietarioId = $this->upsertPersonaPropietario($loteId, $archivos['PROPIETAR.TXT'], $registroPropietarioId, $propietario['raw']);
            $propietarioId = $this->upsertPropietario($loteId, $archivos['PROPIETAR.TXT'], $registroPropietarioId, $personaPropietarioId, $propietario['raw']);

            $personaInquilinoId = $this->upsertPersonaInquilino($loteId, $archivos['INQUILINO.TXT'], $registroInquilinoId, $inquilino['raw']);
            $inquilinoId = $this->upsertInquilino($loteId, $archivos['INQUILINO.TXT'], $registroInquilinoId, $personaInquilinoId, $inquilino['raw']);

            $inmuebleId = $this->upsertInmueble($loteId, $archivos['INQUILINO.TXT'], $registroInquilinoId, $inquilino['raw']);
            $contratoId = $this->upsertContrato($loteId, $archivos['INQUILINO.TXT'], $registroInquilinoId, $inquilino['raw']);

            $this->upsertRelacion('web_contrato_inquilinos', [
                'contrato_id' => $contratoId,
                'inquilino_id' => $inquilinoId,
                'rol' => 'TITULAR',
            ], $loteId, $archivos['INQUILINO.TXT'], $registroInquilinoId);

            $this->upsertRelacion('web_contrato_propietarios', [
                'contrato_id' => $contratoId,
                'propietario_id' => $propietarioId,
            ], $loteId, $archivos['INQUILINO.TXT'], $registroInquilinoId);

            $this->upsertRelacion('web_contrato_inmuebles', [
                'contrato_id' => $contratoId,
                'inmueble_id' => $inmuebleId,
            ], $loteId, $archivos['INQUILINO.TXT'], $registroInquilinoId);

            $this->upsertRelacion('web_inmuebles_propietarios', [
                'inmueble_id' => $inmuebleId,
                'propietario_id' => $propietarioId,
                'desde' => $this->fechaDmy(substr($inquilino['raw'], 325, 8)),
            ], $loteId, $archivos['INQUILINO.TXT'], $registroInquilinoId);

            $cuentaPropietarioId = $this->upsertCuentaCorriente($loteId, 'PROPIETARIO', self::CUENTA_PROPIETARIO, $personaPropietarioId, $propietarioId, null);
            $cuentaInquilinoId = $this->upsertCuentaCorriente($loteId, 'INQUILINO', self::CUENTA_INQUILINO, $personaInquilinoId, null, $inquilinoId);

            foreach ($movimientosPropietario as $movimiento) {
                $registroId = $this->upsertRegistro($loteId, $archivos['CTACTEPRO.TXT'], 'CTACTEPRO.TXT', 'cuenta_propietario', $movimiento);
                $this->upsertMovimientoPropietario($loteId, $archivos['CTACTEPRO.TXT'], $registroId, $cuentaPropietarioId, $propietarioId, $contratoId, $inmuebleId, $movimiento['raw']);
            }

            foreach ($movimientosInquilino as $movimiento) {
                $registroId = $this->upsertRegistro($loteId, $archivos['INQCTACTE.TXT'], 'INQCTACTE.TXT', 'cuenta_inquilino', $movimiento);
                $this->upsertMovimientoInquilino($loteId, $archivos['INQCTACTE.TXT'], $registroId, $cuentaInquilinoId, $inquilinoId, $contratoId, $inmuebleId, $movimiento['raw']);
            }
        });
    }

    private function upsertLote(): int
    {
        DB::table('web_lotes_importacion')->updateOrInsert(
            ['codigo_lote' => 'carga-minima-cobol-12020240300-11032433700'],
            [
                'repositorio_id' => 0,
                'periodo_detectado' => '202309',
                'origen' => 'COBOL',
                'estado' => 'IMPORTADO',
                'version_importador' => self::VERSION,
                'version_parser' => self::VERSION,
                'version_regla' => self::VERSION,
                'resumen' => json_encode(['tipo' => 'carga_minima', 'propietario' => self::CUENTA_PROPIETARIO, 'inquilino' => self::CUENTA_INQUILINO]),
                'observaciones' => 'Seeder experimental. Solo base temporal db_gei_web_migraciones_test.',
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        return (int) DB::table('web_lotes_importacion')
            ->where('codigo_lote', 'carga-minima-cobol-12020240300-11032433700')
            ->value('id');
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
                'ruta_logica' => "storage/app/private/liquidaciones/cobol/{$nombre}",
                'hash_archivo' => hash_file('sha256', $path),
                'tamano_bytes' => filesize($path),
                'encoding_detectado' => 'ISO-8859-1',
                'cantidad_lineas' => $this->contarLineas($path),
                'estado' => 'IMPORTADO',
                'resumen' => json_encode(['muestra' => true]),
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
                'payload_normalizado' => json_encode(['clave_origen' => $clave, 'muestra' => true]),
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
        DB::table('web_propietarios')->updateOrInsert(
            ['cuenta_propietario' => self::CUENTA_PROPIETARIO],
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

        return (int) DB::table('web_propietarios')->where('cuenta_propietario', self::CUENTA_PROPIETARIO)->value('id');
    }

    private function upsertInquilino(int $loteId, int $archivoId, int $registroId, int $personaId, string $raw): int
    {
        DB::table('web_inquilinos')->updateOrInsert(
            ['cuenta_inquilino' => self::CUENTA_INQUILINO],
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

        return (int) DB::table('web_inquilinos')->where('cuenta_inquilino', self::CUENTA_INQUILINO)->value('id');
    }

    private function upsertInmueble(int $loteId, int $archivoId, int $registroId, string $raw): int
    {
        $domicilio = $this->texto(substr($raw, 57, 35));
        $codigo = hash('sha256', self::CUENTA_PROPIETARIO.'|'.$domicilio);

        DB::table('web_inmuebles')->updateOrInsert(
            ['codigo_origen' => $codigo],
            [
                'domicilio' => $domicilio,
                'domicilio_normalizado' => mb_strtoupper($domicilio),
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
        $codigo = implode('|', [
            self::CUENTA_INQUILINO,
            self::CUENTA_PROPIETARIO,
            $this->texto(substr($raw, 57, 35)),
            substr($raw, 92, 8),
            substr($raw, 100, 8),
            substr($raw, 325, 8),
        ]);

        DB::table('web_contratos')->updateOrInsert(
            ['codigo_origen' => $codigo],
            [
                'cuenta_inquilino_origen' => self::CUENTA_INQUILINO,
                'cuenta_propietario_origen' => self::CUENTA_PROPIETARIO,
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

    private function upsertCuentaCorriente(int $loteId, string $dominio, string $cuenta, int $personaId, ?int $propietarioId, ?int $inquilinoId): int
    {
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

    private function upsertMovimientoPropietario(int $loteId, int $archivoId, int $registroId, int $cuentaId, int $propietarioId, int $contratoId, int $inmuebleId, string $raw): void
    {
        $codigo = $this->texto(substr($raw, 19, 2));
        $numero = $this->texto(substr($raw, 21, 6));
        $importe = $this->signedDecimal(substr($raw, 27, 12), 2);
        $debe = ((int) $codigo >= 21) ? abs($importe) : 0;
        $haber = ((int) $codigo < 21) ? abs($importe) : 0;

        $conceptoId = $this->upsertConcepto('PROPIETARIO', $codigo, $this->texto(substr($raw, 39, 40)));

        DB::table('web_movimientos_cuenta')->updateOrInsert(
            [
                'dominio' => 'PROPIETARIO',
                'cuenta_origen' => self::CUENTA_PROPIETARIO,
                'codigo_concepto' => $codigo,
                'numero_movimiento' => $numero,
                'hash_origen' => hash('sha256', $raw),
            ],
            [
                'cuenta_corriente_id' => $cuentaId,
                'contrato_id' => $contratoId,
                'inmueble_id' => $inmuebleId,
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

    private function upsertMovimientoInquilino(int $loteId, int $archivoId, int $registroId, int $cuentaId, int $inquilinoId, int $contratoId, int $inmuebleId, string $raw): void
    {
        $codigo = $this->texto(substr($raw, 19, 2));
        $numero = $this->texto(substr($raw, 21, 6));
        $importe = $this->signedDecimal(substr($raw, 27, 12), 2);
        $debe = $importe >= 0 ? abs($importe) : 0;
        $haber = $importe < 0 ? abs($importe) : 0;

        $conceptoId = $this->upsertConcepto('INQUILINO', $codigo, $this->texto(substr($raw, 63, 40)));

        DB::table('web_movimientos_cuenta')->updateOrInsert(
            [
                'dominio' => 'INQUILINO',
                'cuenta_origen' => self::CUENTA_INQUILINO,
                'codigo_concepto' => $codigo,
                'numero_movimiento' => $numero,
                'hash_origen' => hash('sha256', $raw),
            ],
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
     * @return array{line:int, raw:string}
     */
    private function buscarLinea(string $archivo, string $clave, int $desde, int $largo): array
    {
        foreach ($this->leerLineas($archivo) as $linea) {
            if (substr($linea['raw'], $desde, $largo) === $clave) {
                return $linea;
            }
        }

        throw new RuntimeException("No se encontro {$clave} en {$archivo}.");
    }

    /**
     * @return list<array{line:int, raw:string}>
     */
    private function buscarLineas(string $archivo, string $clave, int $desde, int $largo, int $limite): array
    {
        $encontradas = [];
        foreach ($this->leerLineas($archivo) as $linea) {
            if (substr($linea['raw'], $desde, $largo) === $clave) {
                $encontradas[] = $linea;
                if (count($encontradas) >= $limite) {
                    return $encontradas;
                }
            }
        }

        if ($encontradas === []) {
            throw new RuntimeException("No se encontraron movimientos {$clave} en {$archivo}.");
        }

        return $encontradas;
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

    private function decimal(string $value, int $scale): float
    {
        $digits = preg_replace('/\D/', '', $value) ?: '0';

        return ((int) $digits) / (10 ** $scale);
    }

    private function signedDecimal(string $value, int $scale): float
    {
        $sign = str_ends_with($value, '-') ? -1 : 1;

        return $sign * $this->decimal($value, $scale);
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
}
