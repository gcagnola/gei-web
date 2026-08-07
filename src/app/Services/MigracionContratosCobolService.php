<?php

namespace App\Services;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MigracionContratosCobolService
{
    private const SISTEMA = 'COBOL';

    private const ENTIDAD = 'INQUILINO';

    /** @var null|Closure(array<string, mixed>):void */
    private ?Closure $incidencia = null;

    public function __construct(
        private readonly ContratoCobolNormalizer $normalizer,
    ) {
    }

    /**
     * @param null|Closure(int, int):void $avance
     * @param null|Closure(array<string, mixed>):void $incidencia
     * @return array<string, int|bool>
     */
    public function ejecutar(
        bool $confirmar = false,
        ?int $limite = null,
        ?Closure $avance = null,
        ?Closure $incidencia = null
    ): array {
        $this->incidencia = $incidencia;
        $origen = $this->conexionExploracion();
        $this->validarOrigen($origen);
        $filas = $this->ultimosInquilinos($origen, $limite);
        $resultado = $this->resultadoInicial($confirmar, count($filas));

        $procesar = function () use ($filas, $confirmar, $avance, &$resultado): void {
            $estado = $this->cargarEstadoDestino();
            $total = count($filas);

            foreach ($filas as $indice => $datos) {
                $resultado['procesados']++;
                $firmasDetectadas = [];

                if (
                    $datos['cuenta_inquilino'] === ''
                    || $datos['cuenta_propietario'] === ''
                    || $datos['clave_contrato'] === ''
                ) {
                    $resultado['omitidos']++;
                    $motivo = $datos['cuenta_inquilino'] === ''
                        ? 'CUENTA_INQUILINO_VACIA'
                        : 'CUENTA_PROPIETARIO_VACIA';
                    $firmasDetectadas[] = $this->registrarIncidencia(
                        null,
                        $datos,
                        'CONFLICTO',
                        $motivo,
                        true,
                        [],
                        $confirmar,
                        $estado,
                        $resultado
                    );
                } else {
                    $resultado['registros_validos']++;
                    $resultado[$datos['activo'] ? 'contratos_activos' : 'contratos_baja']++;
                    $contrato = $this->guardarContrato(
                        $datos,
                        $confirmar,
                        $estado,
                        $resultado
                    );
                    $this->guardarOrigen($contrato, $datos, $confirmar, $estado, $resultado);

                    $firma = $this->guardarRelacionInquilino(
                        $contrato,
                        $datos,
                        $confirmar,
                        $estado,
                        $resultado
                    );
                    if ($firma !== null) {
                        $firmasDetectadas[] = $firma;
                    }

                    $firma = $this->guardarRelacionInmueble(
                        $contrato,
                        $datos,
                        $confirmar,
                        $estado,
                        $resultado
                    );
                    if ($firma !== null) {
                        $firmasDetectadas[] = $firma;
                    }

                    foreach ($datos['advertencias'] as $motivo => $detalle) {
                        $firmasDetectadas[] = $this->registrarIncidencia(
                            $contrato,
                            $datos,
                            'ADVERTENCIA',
                            $motivo,
                            false,
                            $detalle,
                            $confirmar,
                            $estado,
                            $resultado
                        );
                    }

                    $this->resolverIncidenciasAusentes(
                        $contrato,
                        $datos['cuenta_inquilino'],
                        $firmasDetectadas,
                        $confirmar,
                        $estado,
                        $resultado
                    );
                }

                $procesados = $indice + 1;
                if ($avance !== null && (
                    $procesados === 1
                    || $procesados % 1000 === 0
                    || $procesados === $total
                )) {
                    $avance($procesados, $total);
                }
            }

            $resultado['conflictos_pendientes'] = count(array_filter(
                $estado['incidencias'],
                fn (array $fila): bool => ($fila['estado'] ?? null) === 'PENDIENTE'
                    && ($fila['tipo'] ?? null) === 'CONFLICTO'
            ));
            $resultado['advertencias_pendientes'] = count(array_filter(
                $estado['incidencias'],
                fn (array $fila): bool => ($fila['estado'] ?? null) === 'PENDIENTE'
                    && ($fila['tipo'] ?? null) === 'ADVERTENCIA'
            ));
        };

        try {
            if ($confirmar) {
                DB::transaction($procesar, 3);
            } else {
                $procesar();
            }
        } finally {
            $this->incidencia = null;
        }

        return $resultado;
    }

    /** @return array<string, int|bool> */
    private function resultadoInicial(bool $confirmar, int $fuentes): array
    {
        return [
            'confirmado' => $confirmar,
            'fuentes_inquilinos' => $fuentes,
            'procesados' => 0,
            'registros_validos' => 0,
            'contratos_activos' => 0,
            'contratos_baja' => 0,
            'contratos_creados' => 0,
            'contratos_actualizados' => 0,
            'contratos_sin_cambios' => 0,
            'origenes_creados' => 0,
            'origenes_actualizados' => 0,
            'origenes_sin_cambios' => 0,
            'inquilinos_creados' => 0,
            'inquilinos_actualizados' => 0,
            'inquilinos_sin_cambios' => 0,
            'inmuebles_creados' => 0,
            'inmuebles_actualizados' => 0,
            'inmuebles_sin_cambios' => 0,
            'conflictos_nuevos' => 0,
            'conflictos_actualizados' => 0,
            'conflictos_sin_cambios' => 0,
            'advertencias_nuevas' => 0,
            'advertencias_actualizadas' => 0,
            'advertencias_sin_cambios' => 0,
            'incidencias_resueltas' => 0,
            'conflictos_pendientes' => 0,
            'advertencias_pendientes' => 0,
            'omitidos' => 0,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function ultimosInquilinos(ConnectionInterface $origen, ?int $limite): array
    {
        $ultimos = [];
        foreach (
            $origen->table($this->schema().'.inquilino')
                ->orderBy('archivo_id')
                ->orderBy('numero_linea')
                ->cursor() as $fila
        ) {
            $datos = $this->normalizer->inquilino($fila);
            if ($datos['cuenta_inquilino'] !== '') {
                $ultimos[$datos['cuenta_inquilino']] = $datos;
            }
        }

        ksort($ultimos, SORT_STRING);
        $filas = array_values($ultimos);

        return $limite === null ? $filas : array_slice($filas, 0, max(0, $limite));
    }

    /** @return array<string, mixed> */
    private function cargarEstadoDestino(): array
    {
        $contratos = [];
        $maxContratoId = 0;
        foreach (DB::table('contratos')->orderBy('id')->cursor() as $fila) {
            $contrato = (array) $fila;
            $contratos[$fila->clave_migracion] = $contrato;
            $maxContratoId = max($maxContratoId, (int) $fila->id);
        }

        $origenes = [];
        foreach (DB::table('contratos_origenes')->cursor() as $fila) {
            $origen = (array) $fila;
            $origen['datos_origen'] = $this->decodificarJson($fila->datos_origen ?? null);
            $origenes[$fila->entidad_origen.'|'.$fila->clave_origen] = $origen;
        }

        $cuentasInquilinos = [];
        foreach (DB::table('clientes_cuentas')->where('rol', 'INQUILINO')->cursor() as $fila) {
            $cuentasInquilinos[$fila->cuenta][] = (array) $fila;
        }

        $inmueblesPorCuenta = [];
        foreach (
            DB::table('inmuebles_origenes')
                ->where('sistema_origen', self::SISTEMA)
                ->where('entidad_origen', self::ENTIDAD)
                ->cursor() as $fila
        ) {
            $inmueblesPorCuenta[$fila->clave_origen][] = (array) $fila;
        }

        $inquilinos = [];
        foreach (DB::table('contratos_inquilinos')->cursor() as $fila) {
            $relacion = (array) $fila;
            $relacion['datos_origen'] = $this->decodificarJson($fila->datos_origen ?? null);
            $inquilinos[$fila->contrato_id.'|'.$fila->cliente_cuenta_id.'|'.$fila->rol] = $relacion;
        }

        $inmuebles = [];
        foreach (DB::table('contratos_inmuebles')->cursor() as $fila) {
            $relacion = (array) $fila;
            $relacion['datos_origen'] = $this->decodificarJson($fila->datos_origen ?? null);
            $inmuebles[$fila->contrato_id.'|'.$fila->inmueble_id] = $relacion;
        }

        $incidencias = [];
        foreach (DB::table('contratos_conflictos')->cursor() as $fila) {
            $incidencia = (array) $fila;
            $incidencia['detalle'] = $this->decodificarJson($fila->detalle ?? null);
            $incidencias[$fila->firma] = $incidencia;
        }

        return [
            'contratos' => $contratos,
            'origenes' => $origenes,
            'cuentas_inquilinos' => $cuentasInquilinos,
            'inmuebles_por_cuenta' => $inmueblesPorCuenta,
            'inquilinos' => $inquilinos,
            'inmuebles' => $inmuebles,
            'incidencias' => $incidencias,
            'proximo_contrato_id' => $maxContratoId + 1,
        ];
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     * @return array<string, mixed>
     */
    private function guardarContrato(
        array $datos,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): array {
        $existente = $estado['contratos'][$datos['clave_contrato']] ?? null;
        $fila = $this->filaContrato($datos, $existente);
        $campos = array_keys($fila);

        if ($existente === null) {
            $fila['created_at'] = now();
            $fila['updated_at'] = now();
            if ($confirmar) {
                $fila['id'] = DB::table('contratos')->insertGetId($fila);
            } else {
                $fila['id'] = $estado['proximo_contrato_id']++;
            }
            $resultado['contratos_creados']++;
        } else {
            $fila['id'] = (int) $existente['id'];
            $fila['created_at'] = $existente['created_at'] ?? now();
            if ($this->camposIguales($existente, $fila, $campos)) {
                $fila['updated_at'] = $existente['updated_at'] ?? now();
                $resultado['contratos_sin_cambios']++;
            } else {
                $fila['updated_at'] = now();
                if ($confirmar) {
                    DB::table('contratos')->where('id', $fila['id'])->update(
                        array_merge(array_intersect_key($fila, array_flip($campos)), [
                            'updated_at' => $fila['updated_at'],
                        ])
                    );
                }
                $resultado['contratos_actualizados']++;
            }
        }

        $estado['contratos'][$datos['clave_contrato']] = $fila;

        return $fila;
    }

    /** @param null|array<string, mixed> $existente @return array<string, mixed> */
    private function filaContrato(array $datos, ?array $existente): array
    {
        return [
            'clave_migracion' => $datos['clave_contrato'],
            'codigo_origen' => $datos['cuenta_inquilino'],
            'cuenta_inquilino' => $datos['cuenta_inquilino'],
            'cuenta_propietario' => $datos['cuenta_propietario'],
            'fecha_contrato' => $datos['fecha_contrato'],
            'fecha_celebracion' => $datos['fecha_celebracion'],
            'fecha_inicio' => $datos['fecha_inicio'],
            'fecha_fin' => $datos['fecha_fin'],
            'fecha_primer_ajuste' => $datos['fecha_primer_ajuste'],
            'fecha_baja' => $datos['fecha_baja'],
            'plazo_meses' => $datos['plazo_meses'],
            'plazo_dias' => $datos['plazo_dias'],
            'indice_ajuste' => $datos['indice_ajuste'],
            'tipo_ajuste' => $datos['tipo_ajuste'],
            'cuota_1' => $datos['cuota_1'],
            'cuota_2' => $datos['cuota_2'],
            'cuota_2_dolar' => $datos['cuota_2_dolar'],
            'alquiler_inicial' => $datos['alquiler_inicial'],
            'cotizacion_dolar' => $datos['cotizacion_dolar'],
            'administracion_responsable' => $datos['administracion_responsable'],
            'destino_codigo' => $datos['destino_codigo'],
            'penalidad_porcentaje' => $datos['penalidad_porcentaje'],
            'penalidad_importe' => $datos['penalidad_importe'],
            'acumulado_penalidad' => $datos['acumulado_penalidad'],
            'comision_anterior' => $datos['comision_anterior'],
            'comision_impuestos' => $datos['comision_impuestos'],
            'reparacion' => $datos['reparacion'],
            'dias_reparacion' => $datos['dias_reparacion'],
            'fecha_juicio' => $datos['fecha_juicio'],
            'abogado_codigo' => $datos['abogado_codigo'],
            'marca_intimacion' => $datos['marca_intimacion'],
            'estado' => $datos['estado'],
            // Los datos manuales no son propiedad del importador COBOL.
            'observaciones' => $existente['observaciones'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $contrato
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function guardarOrigen(
        array $contrato,
        array $datos,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $clave = self::ENTIDAD.'|'.$datos['cuenta_inquilino'];
        $existente = $estado['origenes'][$clave] ?? null;
        $payload = [
            'datos_originales' => $datos['datos_originales'],
            'ajustes_adicionales' => $datos['ajustes_adicionales'],
            'impuestos_porcentajes' => $datos['impuestos_porcentajes'],
            'copias_contrato' => $datos['copias_contrato'],
            'regla_redefine' => $datos['fecha_celebracion'] !== null
                ? 'OTROS_DATOS_FECHA_CELEBRACION'
                : 'IMPUESTOS_PORCENTAJES',
        ];
        $fila = [
            'contrato_id' => (int) $contrato['id'],
            'sistema_origen' => self::SISTEMA,
            'entidad_origen' => self::ENTIDAD,
            'clave_origen' => $datos['cuenta_inquilino'],
            'archivo_origen_id' => $datos['archivo_origen_id'],
            'numero_linea' => $datos['numero_linea'],
            'hash_origen' => $datos['hash_origen'],
            'datos_origen' => $payload,
        ];

        if ($existente === null) {
            if ($confirmar) {
                DB::table('contratos_origenes')->insert(array_merge($fila, [
                    'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'ultimo_importado_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
            $resultado['origenes_creados']++;
        } else {
            $sinCambios = $this->camposIguales($existente, $fila, [
                'contrato_id', 'archivo_origen_id', 'numero_linea', 'hash_origen',
            ]) && $this->jsonIgual($existente['datos_origen'] ?? [], $payload);

            if ($sinCambios) {
                $resultado['origenes_sin_cambios']++;
            } else {
                if ($confirmar) {
                    DB::table('contratos_origenes')
                        ->where('sistema_origen', self::SISTEMA)
                        ->where('entidad_origen', self::ENTIDAD)
                        ->where('clave_origen', $datos['cuenta_inquilino'])
                        ->update(array_merge($fila, [
                            'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                            'ultimo_importado_at' => now(),
                            'updated_at' => now(),
                        ]));
                }
                $resultado['origenes_actualizados']++;
            }
        }

        $estado['origenes'][$clave] = $fila;
    }

    /** @return ?string Firma del conflicto detectado. */
    private function guardarRelacionInquilino(
        array $contrato,
        array $datos,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): ?string {
        $candidatos = $estado['cuentas_inquilinos'][$datos['cuenta_inquilino']] ?? [];
        if (count($candidatos) !== 1) {
            return $this->registrarIncidencia(
                $contrato,
                $datos,
                'CONFLICTO',
                count($candidatos) > 1 ? 'CUENTA_INQUILINO_AMBIGUA' : 'CUENTA_INQUILINO_NO_ENCONTRADA',
                true,
                ['clientes_cuentas_candidatas' => array_column($candidatos, 'id')],
                $confirmar,
                $estado,
                $resultado
            );
        }

        $cuenta = $candidatos[0];
        $clave = $contrato['id'].'|'.$cuenta['id'].'|TITULAR';
        $existente = $estado['inquilinos'][$clave] ?? null;
        $payload = ['cuenta_inquilino' => $datos['cuenta_inquilino']];
        $fila = [
            'contrato_id' => (int) $contrato['id'],
            'cliente_id' => (int) $cuenta['cliente_id'],
            'cliente_cuenta_id' => (int) $cuenta['id'],
            'rol' => 'TITULAR',
            'vigencia_desde' => $datos['fecha_inicio'] ?? $datos['fecha_contrato'],
            'vigencia_hasta' => $datos['fecha_fin'],
            'activo' => $datos['activo'],
            'origen' => self::SISTEMA,
            'datos_origen' => $payload,
        ];
        $this->guardarRelacion(
            'contratos_inquilinos',
            $existente,
            $fila,
            ['cliente_id', 'rol', 'vigencia_desde', 'vigencia_hasta', 'activo', 'origen'],
            'inquilinos',
            $confirmar,
            $resultado
        );
        $estado['inquilinos'][$clave] = $fila;

        return null;
    }

    /** @return ?string Firma del conflicto detectado. */
    private function guardarRelacionInmueble(
        array $contrato,
        array $datos,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): ?string {
        $candidatos = $estado['inmuebles_por_cuenta'][$datos['cuenta_inquilino']] ?? [];
        if (count($candidatos) !== 1) {
            return $this->registrarIncidencia(
                $contrato,
                $datos,
                'CONFLICTO',
                count($candidatos) > 1 ? 'INMUEBLE_AMBIGUO' : 'INMUEBLE_NO_ENCONTRADO',
                true,
                ['inmuebles_origenes_candidatos' => array_column($candidatos, 'inmueble_id')],
                $confirmar,
                $estado,
                $resultado
            );
        }

        $inmuebleId = (int) $candidatos[0]['inmueble_id'];
        $clave = $contrato['id'].'|'.$inmuebleId;
        $existente = $estado['inmuebles'][$clave] ?? null;
        $payload = [
            'cuenta_inquilino' => $datos['cuenta_inquilino'],
            'cuenta_propietario' => $datos['cuenta_propietario'],
            'regla' => 'INQUILINO_CUENTA_A_INMUEBLES_ORIGENES',
        ];
        $fila = [
            'contrato_id' => (int) $contrato['id'],
            'inmueble_id' => $inmuebleId,
            'vigencia_desde' => $datos['fecha_inicio'] ?? $datos['fecha_contrato'],
            'vigencia_hasta' => $datos['fecha_fin'],
            'activo' => $datos['activo'],
            'origen' => self::SISTEMA,
            'datos_origen' => $payload,
        ];
        $this->guardarRelacion(
            'contratos_inmuebles',
            $existente,
            $fila,
            ['inmueble_id', 'vigencia_desde', 'vigencia_hasta', 'activo', 'origen'],
            'inmuebles',
            $confirmar,
            $resultado
        );
        $estado['inmuebles'][$clave] = $fila;

        return null;
    }

    /**
     * @param null|array<string, mixed> $existente
     * @param array<string, mixed> $fila
     * @param list<string> $campos
     * @param array<string, int|bool> $resultado
     */
    private function guardarRelacion(
        string $tabla,
        ?array $existente,
        array $fila,
        array $campos,
        string $prefijo,
        bool $confirmar,
        array &$resultado
    ): void {
        $payload = $fila['datos_origen'];
        if ($existente === null) {
            if ($confirmar) {
                DB::table($tabla)->insert(array_merge($fila, [
                    'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
            $resultado[$prefijo.'_creados']++;

            return;
        }

        $sinCambios = $this->camposIguales($existente, $fila, $campos)
            && $this->jsonIgual($existente['datos_origen'] ?? [], $payload);
        if ($sinCambios) {
            $resultado[$prefijo.'_sin_cambios']++;

            return;
        }

        if ($confirmar) {
            DB::table($tabla)->where('id', $existente['id'])->update(array_merge($fila, [
                'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                'updated_at' => now(),
            ]));
        }
        $resultado[$prefijo.'_actualizados']++;
    }

    /**
     * @param null|array<string, mixed> $contrato
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $detalle
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function registrarIncidencia(
        ?array $contrato,
        array $datos,
        string $tipo,
        string $motivo,
        bool $bloqueante,
        array $detalle,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): string {
        $firma = hash('sha256', $datos['cuenta_inquilino'].'|'.$tipo.'|'.$motivo);
        $existente = $estado['incidencias'][$firma] ?? null;
        $fila = [
            'contrato_id' => $contrato['id'] ?? null,
            'cuenta_inquilino' => $datos['cuenta_inquilino'] ?: null,
            'cuenta_propietario' => $datos['cuenta_propietario'] ?: null,
            'tipo' => $tipo,
            'motivo' => $motivo,
            'bloqueante' => $bloqueante,
            'estado' => 'PENDIENTE',
            'firma' => $firma,
            'detalle' => $detalle,
        ];
        $prefijo = $tipo === 'ADVERTENCIA' ? 'advertencias' : 'conflictos';

        if ($existente === null) {
            if ($confirmar) {
                DB::table('contratos_conflictos')->insert(array_merge($fila, [
                    'detalle' => json_encode($detalle, JSON_UNESCAPED_UNICODE),
                    'detectado_at' => now(),
                    'ultima_deteccion_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
            $claveNuevos = $tipo === 'ADVERTENCIA'
                ? 'advertencias_nuevas'
                : 'conflictos_nuevos';
            $resultado[$claveNuevos]++;
        } else {
            $sinCambios = ($existente['estado'] ?? null) === 'PENDIENTE'
                && $this->camposIguales($existente, $fila, [
                    'contrato_id', 'cuenta_inquilino', 'cuenta_propietario',
                    'tipo', 'motivo', 'bloqueante',
                ])
                && $this->jsonIgual($existente['detalle'] ?? [], $detalle);
            if ($sinCambios) {
                $resultado[$prefijo.'_sin_cambios']++;
            } else {
                if ($confirmar) {
                    DB::table('contratos_conflictos')->where('firma', $firma)->update([
                        'contrato_id' => $fila['contrato_id'],
                        'cuenta_inquilino' => $fila['cuenta_inquilino'],
                        'cuenta_propietario' => $fila['cuenta_propietario'],
                        'tipo' => $tipo,
                        'motivo' => $motivo,
                        'bloqueante' => $bloqueante,
                        'estado' => 'PENDIENTE',
                        'detalle' => json_encode($detalle, JSON_UNESCAPED_UNICODE),
                        'ultima_deteccion_at' => now(),
                        'resuelto_at' => null,
                        'updated_at' => now(),
                    ]);
                }
                $resultado[$prefijo.'_actualizados']++;
            }
        }

        $estado['incidencias'][$firma] = $fila;
        $this->registrarDetalle([
            'tipo' => $tipo,
            'motivo' => $motivo,
            'bloqueante' => $bloqueante,
            'cuenta_inquilino' => $datos['cuenta_inquilino'] ?? null,
            'cuenta_propietario' => $datos['cuenta_propietario'] ?? null,
            'fecha_contrato' => $datos['datos_originales']['fecha_contrato'] ?? null,
            'fecha_vencimiento' => $datos['datos_originales']['fecha_vencimiento'] ?? null,
            'detalle' => $detalle,
        ]);

        return $firma;
    }

    /**
     * @param array<string, mixed> $contrato
     * @param list<string> $firmasDetectadas
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function resolverIncidenciasAusentes(
        array $contrato,
        string $cuenta,
        array $firmasDetectadas,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        foreach ($estado['incidencias'] as $firma => $incidencia) {
            if (
                ($incidencia['estado'] ?? null) !== 'PENDIENTE'
                || ($incidencia['cuenta_inquilino'] ?? null) !== $cuenta
                || in_array($firma, $firmasDetectadas, true)
            ) {
                continue;
            }

            if ($confirmar) {
                DB::table('contratos_conflictos')->where('firma', $firma)->update([
                    'contrato_id' => $contrato['id'],
                    'estado' => 'RESUELTO',
                    'resuelto_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $estado['incidencias'][$firma]['estado'] = 'RESUELTO';
            $resultado['incidencias_resueltas']++;
        }
    }

    /** @param array<string, mixed> $actual @param array<string, mixed> $esperado @param list<string> $campos */
    private function camposIguales(array $actual, array $esperado, array $campos): bool
    {
        foreach ($campos as $campo) {
            if (($actual[$campo] ?? null) != ($esperado[$campo] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function jsonIgual(mixed $izquierda, mixed $derecha): bool
    {
        return $this->ordenarJson($this->decodificarJson($izquierda))
            === $this->ordenarJson($this->decodificarJson($derecha));
    }

    private function decodificarJson(mixed $valor): mixed
    {
        if (is_string($valor)) {
            $decodificado = json_decode($valor, true);

            return json_last_error() === JSON_ERROR_NONE ? $decodificado : $valor;
        }

        return $valor ?? [];
    }

    private function ordenarJson(mixed $valor): mixed
    {
        if (! is_array($valor)) {
            return $valor;
        }
        if (array_is_list($valor)) {
            return array_map(fn (mixed $item): mixed => $this->ordenarJson($item), $valor);
        }

        ksort($valor);
        foreach ($valor as $clave => $item) {
            $valor[$clave] = $this->ordenarJson($item);
        }

        return $valor;
    }

    /** @param array<string, mixed> $detalle */
    private function registrarDetalle(array $detalle): void
    {
        if ($this->incidencia !== null) {
            ($this->incidencia)($detalle);
        }
    }

    private function conexionExploracion(): ConnectionInterface
    {
        $base = config('database.connections.pgsql');
        if (! is_array($base)) {
            throw new RuntimeException('No existe la conexión PostgreSQL base.');
        }

        $base['host'] = config('gei.exploracion.host');
        $base['port'] = config('gei.exploracion.port');
        $base['database'] = config('gei.exploracion.database');
        $base['username'] = config('gei.exploracion.username');
        $base['password'] = config('gei.exploracion.password');
        $base['search_path'] = 'public';
        config(['database.connections.gei_exploracion' => $base]);
        DB::purge('gei_exploracion');

        return DB::connection('gei_exploracion');
    }

    private function validarOrigen(ConnectionInterface $origen): void
    {
        $existe = $origen->selectOne('select to_regclass(?) as tabla', [$this->schema().'.inquilino']);
        if (($existe->tabla ?? null) === null) {
            throw new RuntimeException('No existe '.$this->schema().'.inquilino en gei_exploracion.');
        }
    }

    private function schema(): string
    {
        $schema = (string) config('gei.exploracion.schema', 'cobol_staging');
        if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $schema)) {
            throw new RuntimeException('GEI_EXPLORACION_SCHEMA no es válido.');
        }

        return $schema;
    }
}
