<?php

namespace App\Services;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class MigracionInmueblesCobolService
{
    private const SISTEMA = 'COBOL';

    private const ENTIDAD = 'INQUILINO';

    /** @var null|Closure(array<string, mixed>):void */
    private ?Closure $incidencia = null;

    public function __construct(
        private readonly InmuebleCobolNormalizer $normalizer,
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

        $procesar = function () use (
            $filas,
            $confirmar,
            $avance,
            &$resultado
        ): void {
            $estado = $this->cargarEstadoDestino();
            $grupos = [];
            $total = count($filas);

            foreach ($filas as $indice => $datos) {
                $resultado['procesados']++;

                if (
                    $datos['cuenta_inquilino'] === ''
                    || $datos['cuenta_propietario'] === ''
                    || $datos['direccion_normalizada'] === ''
                    || $datos['clave_inmueble'] === ''
                ) {
                    $resultado['omitidos']++;
                    $this->registrarConflictoInvalido(
                        $datos,
                        $confirmar,
                        $estado,
                        $resultado
                    );
                } else {
                    $resultado['registros_validos']++;
                    $clave = $datos['clave_inmueble'];
                    if (! isset($grupos[$clave])) {
                        $grupos[$clave] = [
                            'representante' => $datos,
                            'filas' => [],
                            'activo' => false,
                        ];
                    }
                    $grupos[$clave]['filas'][] = $datos;
                    $grupos[$clave]['activo'] = $grupos[$clave]['activo']
                        || $datos['estado_origen'] === 'ACTIVO';
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

            ksort($grupos, SORT_STRING);
            foreach ($grupos as $grupo) {
                $inmueble = $this->guardarInmueble(
                    $grupo['representante'],
                    (bool) $grupo['activo'],
                    $confirmar,
                    $estado,
                    $resultado
                );

                foreach ($grupo['filas'] as $datos) {
                    $this->guardarOrigen(
                        $inmueble,
                        $datos,
                        $confirmar,
                        $estado,
                        $resultado
                    );
                }

                $this->guardarRelacionPropietario(
                    $inmueble,
                    $grupo['representante'],
                    $confirmar,
                    $estado,
                    $resultado
                );
                $this->guardarPartidas(
                    $inmueble,
                    $grupo['filas'],
                    $confirmar,
                    $estado,
                    $resultado
                );
            }

            $resultado['conflictos_pendientes'] = count(array_filter(
                $estado['conflictos'],
                fn (array $fila): bool => ($fila['estado'] ?? null) === 'PENDIENTE'
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
            'inmuebles_creados' => 0,
            'inmuebles_actualizados' => 0,
            'inmuebles_sin_cambios' => 0,
            'origenes_creados' => 0,
            'origenes_actualizados' => 0,
            'origenes_sin_cambios' => 0,
            'relaciones_creadas' => 0,
            'relaciones_actualizadas' => 0,
            'relaciones_sin_cambios' => 0,
            'partidas_creadas' => 0,
            'partidas_actualizadas' => 0,
            'partidas_sin_cambios' => 0,
            'conflictos_nuevos' => 0,
            'conflictos_actualizados' => 0,
            'conflictos_sin_cambios' => 0,
            'conflictos_resueltos' => 0,
            'conflictos_pendientes' => 0,
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
        $inmuebles = [];
        $maxInmuebleId = 0;
        foreach (DB::table('inmuebles')->orderBy('id')->cursor() as $fila) {
            $inmuebles[$fila->clave_migracion] = (array) $fila;
            $maxInmuebleId = max($maxInmuebleId, (int) $fila->id);
        }

        $origenes = [];
        foreach (DB::table('inmuebles_origenes')->cursor() as $fila) {
            $origen = (array) $fila;
            $origen['datos_origen'] = $this->decodificarJson($fila->datos_origen ?? null);
            $origenes[$fila->entidad_origen.'|'.$fila->clave_origen] = $origen;
        }

        $cuentasPropietarios = [];
        foreach (
            DB::table('clientes_cuentas')
                ->where('rol', 'PROPIETARIO')
                ->orderBy('id')
                ->cursor() as $fila
        ) {
            $cuenta = (array) $fila;
            $cuenta['datos_origen'] = $this->decodificarJson($fila->datos_origen ?? null);
            $cuentasPropietarios[$fila->cuenta][] = $cuenta;
        }

        $conflictosClientes = [];
        foreach (
            DB::table('clientes_conflictos')
                ->where('entidad_origen', 'PROPIETAR')
                ->where('estado', 'PENDIENTE')
                ->cursor() as $fila
        ) {
            $conflictosClientes[$fila->clave_origen] = true;
        }

        $relaciones = [];
        foreach (
            DB::table('inmuebles_propietarios')
                ->whereNull('vigencia_hasta')
                ->cursor() as $fila
        ) {
            $relacion = (array) $fila;
            $relacion['datos_origen'] = $this->decodificarJson($fila->datos_origen ?? null);
            $relaciones[$fila->inmueble_id.'|'.$fila->cliente_cuenta_id] = $relacion;
        }

        $partidas = [];
        foreach (
            DB::table('inmuebles_partidas')
                ->whereNull('vigencia_hasta')
                ->cursor() as $fila
        ) {
            $partida = (array) $fila;
            $partida['datos_origen'] = $this->decodificarJson($fila->datos_origen ?? null);
            $partidas[$fila->inmueble_id.'|'.$fila->partida] = $partida;
        }

        $conflictos = [];
        foreach (DB::table('inmuebles_conflictos')->cursor() as $fila) {
            $conflicto = (array) $fila;
            $conflicto['detalle'] = $this->decodificarJson($fila->detalle ?? null);
            $conflictos[$fila->firma] = $conflicto;
        }

        return [
            'inmuebles' => $inmuebles,
            'origenes' => $origenes,
            'cuentas_propietarios' => $cuentasPropietarios,
            'conflictos_clientes' => $conflictosClientes,
            'relaciones' => $relaciones,
            'partidas' => $partidas,
            'conflictos' => $conflictos,
            'proximo_inmueble_id' => $maxInmuebleId + 1,
        ];
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     * @return array<string, mixed>
     */
    private function guardarInmueble(
        array $datos,
        bool $activo,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): array {
        $clave = $datos['clave_inmueble'];
        $existente = $estado['inmuebles'][$clave] ?? null;
        $fila = [
            'clave_migracion' => $clave,
            'codigo_origen' => $existente['codigo_origen'] ?? null,
            'domicilio' => $datos['direccion_normalizada'],
            'domicilio_normalizado' => $datos['direccion_normalizada'],
            'destino_codigo' => $datos['destino'],
            'identificador_cochera' => $datos['identificador_cochera'],
            'estado' => $activo ? 'ACTIVO' : 'INACTIVO',
            'observaciones' => $existente['observaciones'] ?? null,
        ];

        if ($existente === null) {
            $fila['created_at'] = now();
            $fila['updated_at'] = now();
            if ($confirmar) {
                $fila['id'] = DB::table('inmuebles')->insertGetId($fila);
            } else {
                $fila['id'] = $estado['proximo_inmueble_id']++;
            }
            $resultado['inmuebles_creados']++;
        } else {
            $fila['id'] = (int) $existente['id'];
            $fila['created_at'] = $existente['created_at'] ?? now();
            $sinCambios = $this->camposIguales($existente, $fila, [
                'codigo_origen',
                'domicilio',
                'domicilio_normalizado',
                'destino_codigo',
                'identificador_cochera',
                'estado',
                'observaciones',
            ]);

            if ($sinCambios) {
                $fila['updated_at'] = $existente['updated_at'] ?? now();
                $resultado['inmuebles_sin_cambios']++;
            } else {
                $fila['updated_at'] = now();
                if ($confirmar) {
                    DB::table('inmuebles')->where('id', $fila['id'])->update([
                        'codigo_origen' => $fila['codigo_origen'],
                        'domicilio' => $fila['domicilio'],
                        'domicilio_normalizado' => $fila['domicilio_normalizado'],
                        'destino_codigo' => $fila['destino_codigo'],
                        'identificador_cochera' => $fila['identificador_cochera'],
                        'estado' => $fila['estado'],
                        'observaciones' => $fila['observaciones'],
                        'updated_at' => $fila['updated_at'],
                    ]);
                }
                $resultado['inmuebles_actualizados']++;
            }
        }

        $estado['inmuebles'][$clave] = $fila;

        return $fila;
    }

    /**
     * @param array<string, mixed> $inmueble
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function guardarOrigen(
        array $inmueble,
        array $datos,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $clave = self::ENTIDAD.'|'.$datos['cuenta_inquilino'];
        $existente = $estado['origenes'][$clave] ?? null;
        $payload = $this->payloadOrigen($datos);
        $fila = [
            'inmueble_id' => (int) $inmueble['id'],
            'sistema_origen' => self::SISTEMA,
            'entidad_origen' => self::ENTIDAD,
            'clave_origen' => $datos['cuenta_inquilino'],
            'cuenta_propietario' => $datos['cuenta_propietario'],
            'direccion_finca' => $datos['direccion_finca'],
            'direccion_normalizada' => $datos['direccion_normalizada'],
            'clave_inmueble' => $datos['clave_inmueble'],
            'estado_origen' => $datos['estado_origen'],
            'archivo_origen_id' => $datos['archivo_origen_id'],
            'numero_linea' => $datos['numero_linea'],
            'hash_origen' => $datos['hash_origen'],
            'datos_origen' => $payload,
        ];

        if ($existente === null) {
            if ($confirmar) {
                DB::table('inmuebles_origenes')->insert(array_merge($fila, [
                    'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'ultimo_importado_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
            $resultado['origenes_creados']++;
        } else {
            $sinCambios = $this->camposIguales($existente, $fila, [
                'inmueble_id',
                'cuenta_propietario',
                'direccion_finca',
                'direccion_normalizada',
                'clave_inmueble',
                'estado_origen',
                'archivo_origen_id',
                'numero_linea',
                'hash_origen',
            ]) && $this->jsonIgual($existente['datos_origen'] ?? [], $payload);

            if ($sinCambios) {
                $resultado['origenes_sin_cambios']++;
            } else {
                if ($confirmar) {
                    DB::table('inmuebles_origenes')
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

    /**
     * @param array<string, mixed> $inmueble
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function guardarRelacionPropietario(
        array $inmueble,
        array $datos,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $cuenta = $datos['cuenta_propietario'];
        $candidatos = $estado['cuentas_propietarios'][$cuenta] ?? [];

        if (count($candidatos) !== 1) {
            $motivo = count($candidatos) > 1
                ? 'CUENTA_PROPIETARIO_AMBIGUA'
                : (isset($estado['conflictos_clientes'][$cuenta])
                    ? 'PROPIETARIO_EN_CONFLICTO'
                    : 'CUENTA_PROPIETARIO_NO_ENCONTRADA');
            $this->registrarConflicto(
                $inmueble,
                $datos,
                $motivo,
                ['clientes_cuentas_candidatas' => array_column($candidatos, 'id')],
                $confirmar,
                $estado,
                $resultado
            );

            return;
        }

        $cuentaCliente = $candidatos[0];
        $clave = $inmueble['id'].'|'.$cuentaCliente['id'];
        $existente = $estado['relaciones'][$clave] ?? null;
        $payload = [
            'cuenta_propietario' => $cuenta,
            'clave_inmueble' => $datos['clave_inmueble'],
            'regla' => 'INQUILINO_CTA_PROPIETARIO_A_CLIENTES_CUENTAS',
        ];
        $fila = [
            'inmueble_id' => (int) $inmueble['id'],
            'cliente_id' => (int) $cuentaCliente['cliente_id'],
            'cliente_cuenta_id' => (int) $cuentaCliente['id'],
            'porcentaje' => null,
            'vigencia_desde' => null,
            'vigencia_hasta' => null,
            'activo' => true,
            'origen' => self::SISTEMA,
            'datos_origen' => $payload,
        ];

        if ($existente === null) {
            if ($confirmar) {
                DB::table('inmuebles_propietarios')->insert(array_merge($fila, [
                    'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
            $resultado['relaciones_creadas']++;
        } else {
            $sinCambios = $this->camposIguales($existente, $fila, [
                'cliente_id',
                'porcentaje',
                'vigencia_desde',
                'vigencia_hasta',
                'activo',
                'origen',
            ]) && $this->jsonIgual($existente['datos_origen'] ?? [], $payload);

            if ($sinCambios) {
                $resultado['relaciones_sin_cambios']++;
            } else {
                if ($confirmar) {
                    DB::table('inmuebles_propietarios')
                        ->where('id', $existente['id'])
                        ->update([
                            'cliente_id' => $fila['cliente_id'],
                            'porcentaje' => null,
                            'activo' => true,
                            'origen' => self::SISTEMA,
                            'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                            'updated_at' => now(),
                        ]);
                }
                $resultado['relaciones_actualizadas']++;
            }
        }

        $estado['relaciones'][$clave] = $fila;
        $this->resolverConflictosPropietario(
            $inmueble,
            $cuenta,
            $confirmar,
            $estado,
            $resultado
        );
    }

    /**
     * @param array<string, mixed> $inmueble
     * @param list<array<string, mixed>> $filas
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function guardarPartidas(
        array $inmueble,
        array $filas,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $partidas = [];
        foreach ($filas as $datos) {
            foreach ($datos['partidas'] as $posicion => $partida) {
                $partidas[$partida]['cuentas_inquilino'][$datos['cuenta_inquilino']] = true;
                $partidas[$partida]['posiciones'][(int) $posicion] = true;
            }
        }

        ksort($partidas, SORT_STRING);
        foreach ($partidas as $partida => $referencias) {
            $cuentasInquilino = array_keys($referencias['cuentas_inquilino']);
            $posiciones = array_map('intval', array_keys($referencias['posiciones']));
            sort($cuentasInquilino, SORT_STRING);
            sort($posiciones, SORT_NUMERIC);
            $payload = [
                'cuentas_inquilino' => $cuentasInquilino,
                'posiciones_origen' => $posiciones,
            ];
            $clave = $inmueble['id'].'|'.$partida;
            $existente = $estado['partidas'][$clave] ?? null;
            $fila = [
                'inmueble_id' => (int) $inmueble['id'],
                'partida' => $partida,
                'vigencia_desde' => null,
                'vigencia_hasta' => null,
                'activo' => true,
                'origen' => self::SISTEMA,
                'datos_origen' => $payload,
            ];

            if ($existente === null) {
                if ($confirmar) {
                    DB::table('inmuebles_partidas')->insert(array_merge($fila, [
                        'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
                }
                $resultado['partidas_creadas']++;
            } else {
                $sinCambios = $this->camposIguales($existente, $fila, [
                    'vigencia_desde',
                    'vigencia_hasta',
                    'activo',
                    'origen',
                ]) && $this->jsonIgual($existente['datos_origen'] ?? [], $payload);

                if ($sinCambios) {
                    $resultado['partidas_sin_cambios']++;
                } else {
                    if ($confirmar) {
                        DB::table('inmuebles_partidas')
                            ->where('id', $existente['id'])
                            ->update([
                                'activo' => true,
                                'origen' => self::SISTEMA,
                                'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                                'updated_at' => now(),
                            ]);
                    }
                    $resultado['partidas_actualizadas']++;
                }
            }

            $estado['partidas'][$clave] = $fila;
        }
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function registrarConflictoInvalido(
        array $datos,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $motivo = $datos['cuenta_propietario'] === ''
            ? 'CUENTA_PROPIETARIO_VACIA'
            : ($datos['direccion_normalizada'] === ''
                ? 'DIRECCION_FINCA_VACIA'
                : 'CUENTA_INQUILINO_VACIA');
        $inmueble = [
            'id' => null,
            'clave_migracion' => $datos['clave_inmueble'] ?: null,
        ];
        $this->registrarConflicto(
            $inmueble,
            $datos,
            $motivo,
            [],
            $confirmar,
            $estado,
            $resultado
        );
    }

    /**
     * @param array<string, mixed> $inmueble
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $detalle
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function registrarConflicto(
        array $inmueble,
        array $datos,
        string $motivo,
        array $detalle,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $firma = hash('sha256', implode('|', [
            $motivo,
            $datos['clave_inmueble'] ?? '',
            $datos['cuenta_propietario'] ?? '',
            str_starts_with($motivo, 'CUENTA_INQUILINO')
                || str_starts_with($motivo, 'DIRECCION_FINCA')
                ? ($datos['cuenta_inquilino'] ?? '')
                : '',
        ]));
        $existente = $estado['conflictos'][$firma] ?? null;
        $detalleCompleto = array_merge($detalle, [
            'direccion_finca' => $datos['direccion_finca'] ?? null,
            'direccion_normalizada' => $datos['direccion_normalizada'] ?? null,
        ]);
        $fila = [
            'inmueble_id' => $inmueble['id'] ?? null,
            'cuenta_inquilino' => $datos['cuenta_inquilino'] ?? null,
            'cuenta_propietario' => $datos['cuenta_propietario'] ?: null,
            'clave_inmueble' => $datos['clave_inmueble'] ?: null,
            'motivo' => $motivo,
            'estado' => 'PENDIENTE',
            'firma' => $firma,
            'detalle' => $detalleCompleto,
        ];

        if ($existente === null) {
            if ($confirmar) {
                DB::table('inmuebles_conflictos')->insert(array_merge($fila, [
                    'detalle' => json_encode($detalleCompleto, JSON_UNESCAPED_UNICODE),
                    'detectado_at' => now(),
                    'ultima_deteccion_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]));
            }
            $resultado['conflictos_nuevos']++;
        } else {
            $sinCambios = ($existente['estado'] ?? null) === 'PENDIENTE'
                && $this->camposIguales($existente, $fila, [
                    'inmueble_id',
                    'cuenta_inquilino',
                    'cuenta_propietario',
                    'clave_inmueble',
                    'motivo',
                ])
                && $this->jsonIgual($existente['detalle'] ?? [], $detalleCompleto);

            if ($sinCambios) {
                $resultado['conflictos_sin_cambios']++;
            } else {
                if ($confirmar) {
                    DB::table('inmuebles_conflictos')->where('firma', $firma)->update([
                        'inmueble_id' => $fila['inmueble_id'],
                        'cuenta_inquilino' => $fila['cuenta_inquilino'],
                        'cuenta_propietario' => $fila['cuenta_propietario'],
                        'clave_inmueble' => $fila['clave_inmueble'],
                        'motivo' => $motivo,
                        'estado' => 'PENDIENTE',
                        'detalle' => json_encode($detalleCompleto, JSON_UNESCAPED_UNICODE),
                        'ultima_deteccion_at' => now(),
                        'resuelto_at' => null,
                        'updated_at' => now(),
                    ]);
                }
                $resultado['conflictos_actualizados']++;
            }
        }

        $estado['conflictos'][$firma] = $fila;
        $this->registrarIncidencia([
            'tipo' => 'CONFLICTO',
            'motivo' => $motivo,
            'cuenta_inquilino' => $datos['cuenta_inquilino'] ?? null,
            'cuenta_propietario' => $datos['cuenta_propietario'] ?? null,
            'direccion' => $datos['direccion_finca'] ?? null,
            'clave_inmueble' => $datos['clave_inmueble'] ?? null,
            'detalle' => $detalleCompleto,
        ]);
    }

    /**
     * @param array<string, mixed> $inmueble
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function resolverConflictosPropietario(
        array $inmueble,
        string $cuenta,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        foreach ($estado['conflictos'] as $firma => $conflicto) {
            if (
                ($conflicto['estado'] ?? null) !== 'PENDIENTE'
                || ($conflicto['clave_inmueble'] ?? null) !== $inmueble['clave_migracion']
                || ($conflicto['cuenta_propietario'] ?? null) !== $cuenta
                || ! in_array($conflicto['motivo'] ?? '', [
                    'CUENTA_PROPIETARIO_AMBIGUA',
                    'PROPIETARIO_EN_CONFLICTO',
                    'CUENTA_PROPIETARIO_NO_ENCONTRADA',
                ], true)
            ) {
                continue;
            }

            if ($confirmar) {
                DB::table('inmuebles_conflictos')->where('firma', $firma)->update([
                    'estado' => 'RESUELTO',
                    'resuelto_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $estado['conflictos'][$firma]['estado'] = 'RESUELTO';
            $resultado['conflictos_resueltos']++;
        }
    }

    /** @param array<string, mixed> $datos */
    private function payloadOrigen(array $datos): array
    {
        return [
            'cuenta_inquilino' => $datos['cuenta_inquilino'],
            'cuenta_propietario' => $datos['cuenta_propietario'],
            'direccion_finca' => $datos['direccion_finca'],
            'destino' => $datos['destino'],
            'identificador_cochera' => $datos['identificador_cochera'],
            'partidas' => $datos['partidas'],
            'marca_baja' => $datos['estado_origen'] === 'BAJA' ? 'B' : null,
            'fecha_baja' => $datos['fecha_baja'],
        ];
    }

    /**
     * @param array<string, mixed> $actual
     * @param array<string, mixed> $esperado
     * @param list<string> $campos
     */
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
        $izquierda = $this->ordenarJson($this->decodificarJson($izquierda));
        $derecha = $this->ordenarJson($this->decodificarJson($derecha));

        return $izquierda === $derecha;
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

    /** @param array<string, mixed> $incidencia */
    private function registrarIncidencia(array $incidencia): void
    {
        if ($this->incidencia !== null) {
            ($this->incidencia)($incidencia);
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
        $existe = $origen->selectOne(
            'select to_regclass(?) as tabla',
            [$this->schema().'.inquilino']
        );
        if (($existe->tabla ?? null) === null) {
            throw new RuntimeException(
                'No existe '.$this->schema().'.inquilino en gei_exploracion.'
            );
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
