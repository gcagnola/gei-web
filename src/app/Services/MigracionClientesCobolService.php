<?php

namespace App\Services;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

final class MigracionClientesCobolService
{
    private const SISTEMA = 'COBOL';

    private const CAMPOS_IDENTIDAD = [
        'nombre',
        'tipo_documento',
        'numero_documento',
        'cuit',
    ];

    private const CAMPOS_COMPLETABLES = [
        'condicion_iva',
        'domicilio',
        'codigo_postal',
        'localidad',
        'provincia',
        'telefono',
        'telefono_alternativo',
    ];

    /** @var null|Closure(array<string, mixed>):void */
    private ?Closure $incidencia = null;

    public function __construct(
        private readonly ClienteCobolNormalizer $normalizer,
    ) {
    }

    /**
     * @param null|Closure(string, int, int):void $avance
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

        $propietarios = $this->ultimosPorCuenta(
            $origen,
            'propietar',
            fn (object $fila): array => $this->normalizer->propietario($fila),
            $limite
        );
        $inquilinos = $this->ultimosPorCuenta(
            $origen,
            'inquilino',
            fn (object $fila): array => $this->normalizer->inquilino($fila),
            $limite
        );

        $total = count($propietarios) + count($inquilinos);
        $resultado = [
            'confirmado' => $confirmar,
            'fuentes_propietarios' => count($propietarios),
            'fuentes_inquilinos' => count($inquilinos),
            'procesados' => 0,
            'registros_validos' => 0,
            'clientes_creados' => 0,
            'clientes_actualizados' => 0,
            'clientes_unificados' => 0,
            'origenes_creados' => 0,
            'origenes_actualizados' => 0,
            'origenes_sin_cambios' => 0,
            'cuentas_creadas' => 0,
            'cuentas_actualizadas' => 0,
            'cuentas_sin_cambios' => 0,
            'roles_asignados' => 0,
            'diferencias_tributarias' => 0,
            'conflictos_nuevos' => 0,
            'conflictos_actualizados' => 0,
            'conflictos_sin_cambios' => 0,
            'conflictos_resueltos' => 0,
            'conflictos_pendientes' => 0,
            'omitidos' => 0,
        ];

        $procesar = function () use (
            $propietarios,
            $inquilinos,
            $confirmar,
            $avance,
            $total,
            &$resultado
        ): void {
            $estado = $this->cargarEstadoDestino();
            $clientesActividad = [];

            foreach ([
                ['entidad' => 'PROPIETAR', 'rol' => 'PROPIETARIO', 'filas' => $propietarios],
                ['entidad' => 'INQUILINO', 'rol' => 'INQUILINO', 'filas' => $inquilinos],
            ] as $grupo) {
                foreach ($grupo['filas'] as $datos) {
                    $this->procesarCuenta(
                        $datos,
                        $grupo['entidad'],
                        $grupo['rol'],
                        $confirmar,
                        $estado,
                        $clientesActividad,
                        $resultado
                    );

                    $resultado['procesados']++;
                    if ($avance !== null && (
                        $resultado['procesados'] === 1
                        || $resultado['procesados'] % 250 === 0
                        || $resultado['procesados'] === $total
                    )) {
                        $avance($grupo['entidad'], $resultado['procesados'], $total);
                    }
                }
            }

            foreach (array_keys($clientesActividad) as $clienteId) {
                $this->recalcularActividadCliente(
                    (int) $clienteId,
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

    /**
     * @param Closure(object):array<string, mixed> $normalizar
     * @return list<array<string, mixed>>
     */
    private function ultimosPorCuenta(
        ConnectionInterface $origen,
        string $tabla,
        Closure $normalizar,
        ?int $limite
    ): array {
        $ultimos = [];
        foreach (
            $origen->table($this->schema().'.'.$tabla)
                ->orderBy('archivo_id')
                ->orderBy('numero_linea')
                ->cursor() as $fila
        ) {
            $datos = $normalizar($fila);
            if ($datos['cuenta'] !== '') {
                $ultimos[$datos['cuenta']] = $datos;
            }
        }

        ksort($ultimos, SORT_STRING);
        $filas = array_values($ultimos);

        return $limite === null ? $filas : array_slice($filas, 0, max(0, $limite));
    }

    /**
     * @return array<string, mixed>
     */
    private function cargarEstadoDestino(): array
    {
        $clientes = [];
        $porCuit = [];
        $porDocumento = [];
        $maxId = 0;

        foreach (DB::table('clientes')->orderBy('id')->cursor() as $fila) {
            $cliente = (array) $fila;
            $id = (int) $fila->id;
            $clientes[$id] = $cliente;
            $maxId = max($maxId, $id);
            $this->indexarClienteEn($porCuit, $porDocumento, $id, $cliente);
        }

        $origenes = [];
        $estadosOrigen = [];
        foreach (DB::table('clientes_origenes')->cursor() as $fila) {
            $origen = (array) $fila;
            $origen['datos_origen'] = $this->decodificarJson($fila->datos_origen ?? null);
            $clave = $fila->entidad_origen.'|'.$fila->clave_origen;
            $origenes[$clave] = $origen;
            $estadosOrigen[(int) $fila->cliente_id][$clave] =
                $fila->estado_origen ?? 'DESCONOCIDO';
        }

        $conflictos = [];
        foreach (DB::table('clientes_conflictos')->cursor() as $fila) {
            $conflicto = (array) $fila;
            $conflicto['datos_origen'] = $this->decodificarJson($fila->datos_origen ?? null);
            $conflicto['detalle'] = $this->decodificarJson($fila->detalle ?? null);
            $conflictos[$fila->entidad_origen.'|'.$fila->clave_origen] = $conflicto;
        }

        $relaciones = [];
        foreach (DB::table('clientes_roles')->cursor() as $fila) {
            $relaciones[$fila->cliente_id.'|'.$fila->rol_id] = true;
        }

        $cuentas = [];
        foreach (DB::table('clientes_cuentas')->cursor() as $fila) {
            $cuenta = (array) $fila;
            $cuenta['datos_origen'] = $this->decodificarJson($fila->datos_origen ?? null);
            $clave = $fila->cliente_id.'|'.$fila->cuenta.'|'.$fila->rol;
            $cuentas[$clave] = $cuenta;
        }

        return [
            'clientes' => $clientes,
            'origenes' => $origenes,
            'estados_origen' => $estadosOrigen,
            'conflictos' => $conflictos,
            'por_cuit' => $porCuit,
            'por_documento' => $porDocumento,
            'roles' => DB::table('roles')->pluck('id', 'codigo')
                ->map(fn ($id) => (int) $id)->all(),
            'relaciones' => $relaciones,
            'cuentas' => $cuentas,
            'proximo_id' => $maxId + 1,
        ];
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $estado
     * @param array<int, bool> $clientesActividad
     * @param array<string, int|bool> $resultado
     */
    private function procesarCuenta(
        array $datos,
        string $entidad,
        string $rol,
        bool $confirmar,
        array &$estado,
        array &$clientesActividad,
        array &$resultado
    ): void {
        $clave = $entidad.'|'.$datos['cuenta'];
        $origenExistente = $estado['origenes'][$clave] ?? null;

        if ($origenExistente !== null) {
            $clienteId = (int) $origenExistente['cliente_id'];

            if (($origenExistente['hash_origen'] ?? null) === $datos['hash_origen']) {
                $this->sincronizarDatosTributarios(
                    $clienteId,
                    $entidad,
                    $datos,
                    $origenExistente['datos_origen'] ?? [],
                    $confirmar,
                    $estado,
                    $resultado
                );
                $this->guardarCuentaCliente(
                    $clienteId,
                    $entidad,
                    $rol,
                    $datos,
                    $confirmar,
                    $estado,
                    $resultado
                );
                $this->asignarRol($clienteId, $rol, $confirmar, $estado, $resultado);

                $resultado['registros_validos']++;
                if ($this->origenNormalizadoSinCambios($origenExistente, $entidad, $datos)) {
                    $resultado['origenes_sin_cambios']++;
                } else {
                    $this->guardarOrigen(
                        $clienteId,
                        $entidad,
                        $datos,
                        $origenExistente,
                        $confirmar,
                        $estado,
                        $resultado
                    );
                }
                $clientesActividad[$clienteId] = true;
                $this->resolverConflictoAnterior(
                    $clave,
                    $clienteId,
                    $confirmar,
                    $estado,
                    $resultado
                );

                return;
            }

            $identidadCambiada = $this->cambiosIdentidad(
                $origenExistente['datos_origen'] ?? [],
                $datos
            );
            if ($identidadCambiada !== []) {
                $this->guardarConflicto(
                    $entidad,
                    $datos,
                    'IDENTIDAD_CAMBIO_EN_CUENTA_EXISTENTE',
                    [$clienteId],
                    ['cambios' => $identidadCambiada],
                    $confirmar,
                    $estado,
                    $resultado
                );

                return;
            }

            $this->actualizarDatosCompletables(
                $clienteId,
                $origenExistente['datos_origen'] ?? [],
                $datos,
                $confirmar,
                $estado,
                $resultado
            );
            $this->sincronizarDatosTributarios(
                $clienteId,
                $entidad,
                $datos,
                $origenExistente['datos_origen'] ?? [],
                $confirmar,
                $estado,
                $resultado
            );
            $this->guardarOrigen(
                $clienteId,
                $entidad,
                $datos,
                $origenExistente,
                $confirmar,
                $estado,
                $resultado
            );
            $this->guardarCuentaCliente(
                $clienteId,
                $entidad,
                $rol,
                $datos,
                $confirmar,
                $estado,
                $resultado
            );
            $this->asignarRol($clienteId, $rol, $confirmar, $estado, $resultado);
            $this->resolverConflictoAnterior(
                $clave,
                $clienteId,
                $confirmar,
                $estado,
                $resultado
            );
            $resultado['registros_validos']++;
            $clientesActividad[$clienteId] = true;

            return;
        }

        $seleccion = $this->seleccionarCliente($datos, $estado);
        if (! $seleccion['valido']) {
            $this->guardarConflicto(
                $entidad,
                $datos,
                $seleccion['motivo'],
                $seleccion['candidatos'],
                $seleccion['detalle'],
                $confirmar,
                $estado,
                $resultado
            );

            return;
        }

        $clienteId = $seleccion['cliente_id'];
        if ($clienteId === null) {
            $fila = $this->filaCliente($datos, $this->estadoOrigen($entidad, $datos));
            if ($confirmar) {
                $clienteId = (int) DB::table('clientes')->insertGetId($fila);
            } else {
                $clienteId = $estado['proximo_id']++;
            }
            $fila['id'] = $clienteId;
            $estado['clientes'][$clienteId] = $fila;
            $this->indexarCliente($estado, $clienteId, $fila);
            $resultado['clientes_creados']++;
        } else {
            $resultado['clientes_unificados']++;
            $this->completarVaciosCliente(
                $clienteId,
                $datos,
                $confirmar,
                $estado,
                $resultado
            );
        }

        $this->guardarOrigen(
            $clienteId,
            $entidad,
            $datos,
            null,
            $confirmar,
            $estado,
            $resultado
        );
        $this->sincronizarDatosTributarios(
            $clienteId,
            $entidad,
            $datos,
            [],
            $confirmar,
            $estado,
            $resultado
        );
        $this->guardarCuentaCliente(
            $clienteId,
            $entidad,
            $rol,
            $datos,
            $confirmar,
            $estado,
            $resultado
        );
        $this->asignarRol($clienteId, $rol, $confirmar, $estado, $resultado);
        $this->resolverConflictoAnterior(
            $clave,
            $clienteId,
            $confirmar,
            $estado,
            $resultado
        );
        $resultado['registros_validos']++;
        $clientesActividad[$clienteId] = true;
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $estado
     * @return array{valido:bool,cliente_id:?int,motivo:string,candidatos:list<int>,detalle:array<string,mixed>}
     */
    private function seleccionarCliente(array $datos, array $estado): array
    {
        $claveDocumento = $this->claveDocumento($datos);
        $candidatoCuit = $datos['cuit'] === null
            ? false
            : $this->valorIndice($estado['por_cuit'], $datos['cuit']);
        $candidatoDocumento = $claveDocumento === null
            ? false
            : $this->valorIndice($estado['por_documento'], $claveDocumento);

        if ($candidatoCuit === null || $candidatoDocumento === null) {
            return $this->seleccionConflictiva(
                'IDENTIFICADOR_AMBIGUO',
                $this->candidatosCompatibles($datos, $estado),
                ['cuit' => $datos['cuit'], 'documento' => $claveDocumento]
            );
        }

        if (
            is_int($candidatoCuit)
            && is_int($candidatoDocumento)
            && $candidatoCuit !== $candidatoDocumento
        ) {
            return $this->seleccionConflictiva(
                'CUIT_Y_DOCUMENTO_APUNTAN_A_CLIENTES_DISTINTOS',
                [$candidatoCuit, $candidatoDocumento],
                ['cuit' => $candidatoCuit, 'documento' => $candidatoDocumento]
            );
        }

        $candidatoId = is_int($candidatoCuit)
            ? $candidatoCuit
            : (is_int($candidatoDocumento) ? $candidatoDocumento : null);

        if ($candidatoId === null) {
            return [
                'valido' => true,
                'cliente_id' => null,
                'motivo' => '',
                'candidatos' => [],
                'detalle' => [],
            ];
        }

        $compatibilidad = $this->compatibilidadCandidato(
            $estado['clientes'][$candidatoId],
            $datos
        );
        if (! $compatibilidad['compatible']) {
            return $this->seleccionConflictiva(
                (string) $compatibilidad['detalle']['regla'],
                [$candidatoId],
                $compatibilidad['detalle']
            );
        }

        return [
            'valido' => true,
            'cliente_id' => $candidatoId,
            'motivo' => '',
            'candidatos' => [$candidatoId],
            'detalle' => $compatibilidad['detalle'],
        ];
    }

    /**
     * @param list<int> $candidatos
     * @param array<string, mixed> $detalle
     * @return array{valido:bool,cliente_id:null,motivo:string,candidatos:list<int>,detalle:array<string,mixed>}
     */
    private function seleccionConflictiva(
        string $motivo,
        array $candidatos,
        array $detalle
    ): array {
        return [
            'valido' => false,
            'cliente_id' => null,
            'motivo' => $motivo,
            'candidatos' => array_values(array_unique(array_map('intval', $candidatos))),
            'detalle' => $detalle,
        ];
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $estado
     * @return list<int>
     */
    private function candidatosCompatibles(array $datos, array $estado): array
    {
        $claveDocumento = $this->claveDocumento($datos);
        $candidatos = [];

        foreach ($estado['clientes'] as $id => $cliente) {
            $mismoCuit = $datos['cuit'] !== null
                && $this->iguales($cliente['cuit'] ?? null, $datos['cuit']);
            $mismoDocumento = $claveDocumento !== null
                && $this->iguales($this->claveDocumento($cliente), $claveDocumento);
            if (($mismoCuit || $mismoDocumento)
                && $this->compatibilidadCandidato($cliente, $datos)['compatible']) {
                $candidatos[] = (int) $id;
            }
        }

        return $candidatos;
    }

    /**
     * @param array<string, mixed> $datos
     * @param list<int> $candidatos
     * @param array<string, mixed> $detalle
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function guardarConflicto(
        string $entidad,
        array $datos,
        string $motivo,
        array $candidatos,
        array $detalle,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $clave = $entidad.'|'.$datos['cuenta'];
        $existente = $estado['conflictos'][$clave] ?? null;
        $payload = $this->payload($datos);
        $estadoOrigen = $this->estadoOrigen($entidad, $datos);
        $sinCambios = $existente !== null
            && ($existente['estado'] ?? null) === 'PENDIENTE'
            && ($existente['hash_origen'] ?? null) === $datos['hash_origen']
            && ($existente['motivo'] ?? null) === $motivo
            && $this->jsonIgual($existente['datos_origen'] ?? [], $payload)
            && $this->jsonIgual($existente['detalle'] ?? [], $detalle);

        $fila = [
            'cliente_resuelto_id' => null,
            'sistema_origen' => self::SISTEMA,
            'entidad_origen' => $entidad,
            'clave_origen' => $datos['cuenta'],
            'motivo' => $motivo,
            'estado' => 'PENDIENTE',
            'estado_origen' => $estadoOrigen,
            'archivo_origen_id' => $datos['archivo_origen_id'],
            'numero_linea' => $datos['numero_linea'],
            'hash_origen' => $datos['hash_origen'],
            'datos_origen' => $payload,
            'clientes_candidatos' => array_values(array_unique($candidatos)),
            'detalle' => $detalle,
            'detectado_at' => $existente['detectado_at'] ?? now(),
            'ultima_deteccion_at' => now(),
            'resuelto_at' => null,
            'updated_at' => now(),
        ];

        if ($existente === null) {
            $fila['created_at'] = now();
            $resultado['conflictos_nuevos']++;
        } elseif ($sinCambios) {
            $resultado['conflictos_sin_cambios']++;
        } else {
            $resultado['conflictos_actualizados']++;
        }

        if ($confirmar && ! $sinCambios) {
            DB::table('clientes_conflictos')->upsert([
                array_merge($fila, [
                    'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    'clientes_candidatos' => json_encode($fila['clientes_candidatos']),
                    'detalle' => json_encode($detalle, JSON_UNESCAPED_UNICODE),
                    'created_at' => $fila['created_at'] ?? ($existente['created_at'] ?? now()),
                ]),
            ], ['sistema_origen', 'entidad_origen', 'clave_origen'], [
                'cliente_resuelto_id',
                'motivo',
                'estado',
                'estado_origen',
                'archivo_origen_id',
                'numero_linea',
                'hash_origen',
                'datos_origen',
                'clientes_candidatos',
                'detalle',
                'ultima_deteccion_at',
                'resuelto_at',
                'updated_at',
            ]);
        }

        $estado['conflictos'][$clave] = $fila;
        $this->registrarIncidencia([
            'tipo' => 'CONFLICTO',
            'motivo' => $motivo,
            'entidad' => $entidad,
            'cuenta' => $datos['cuenta'],
            'cliente_id' => null,
            'campo' => 'identificacion',
            'valor_actual' => $candidatos,
            'valor_origen' => $datos['nombre'],
            'detalle' => $detalle,
        ]);
    }

    /**
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function resolverConflictoAnterior(
        string $clave,
        int $clienteId,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $conflicto = $estado['conflictos'][$clave] ?? null;
        if ($conflicto === null || ($conflicto['estado'] ?? null) !== 'PENDIENTE') {
            return;
        }

        if ($confirmar) {
            DB::table('clientes_conflictos')
                ->where('sistema_origen', self::SISTEMA)
                ->where('entidad_origen', $conflicto['entidad_origen'])
                ->where('clave_origen', $conflicto['clave_origen'])
                ->update([
                    'cliente_resuelto_id' => $clienteId,
                    'estado' => 'RESUELTO',
                    'resuelto_at' => now(),
                    'updated_at' => now(),
                ]);
        }

        $estado['conflictos'][$clave]['cliente_resuelto_id'] = $clienteId;
        $estado['conflictos'][$clave]['estado'] = 'RESUELTO';
        $estado['conflictos'][$clave]['resuelto_at'] = now();
        $resultado['conflictos_resueltos']++;
    }

    /**
     * @param array<string, mixed> $datos
     * @return array<string, mixed>
     */
    private function filaCliente(array $datos, string $estadoOrigen): array
    {
        return [
            'tipo_persona' => $this->normalizer->tipoPersona(
                $datos['cuit'],
                $datos['numero_documento']
            ),
            'nombre' => $datos['nombre'],
            'tipo_documento' => $datos['tipo_documento'],
            'numero_documento' => $datos['numero_documento'],
            'cuit' => $datos['cuit'],
            'condicion_iva' => $datos['condicion_iva'],
            'domicilio' => $datos['domicilio'],
            'codigo_postal' => $datos['codigo_postal'],
            'localidad' => $datos['localidad'],
            'provincia' => $datos['provincia'],
            'telefono' => $datos['telefono'],
            'telefono_alternativo' => $datos['telefono_alternativo'],
            'email' => null,
            'activo' => match ($estadoOrigen) {
                'ACTIVO' => true,
                'BAJA' => false,
                default => null,
            },
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function completarVaciosCliente(
        int $clienteId,
        array $datos,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $actual = $estado['clientes'][$clienteId];
        $cambios = [];
        foreach (array_merge(self::CAMPOS_IDENTIDAD, self::CAMPOS_COMPLETABLES) as $campo) {
            if (($actual[$campo] ?? null) === null || ($actual[$campo] ?? '') === '') {
                if (($datos[$campo] ?? null) !== null && ($datos[$campo] ?? '') !== '') {
                    $cambios[$campo] = $datos[$campo];
                }
            }
        }
        $this->aplicarCambiosCliente($clienteId, $cambios, $confirmar, $estado, $resultado);
    }

    /**
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function actualizarDatosCompletables(
        int $clienteId,
        array $anterior,
        array $nuevo,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $actual = $estado['clientes'][$clienteId];
        $cambios = [];

        foreach (self::CAMPOS_COMPLETABLES as $campo) {
            $valorActual = $actual[$campo] ?? null;
            $valorAnterior = $anterior[$campo] ?? null;
            $valorNuevo = $nuevo[$campo] ?? null;

            if ($valorNuevo === null || $valorNuevo === '') {
                continue;
            }
            if ($valorActual === null || $valorActual === '') {
                $cambios[$campo] = $valorNuevo;
            } elseif ($this->iguales($valorActual, $valorAnterior)
                && ! $this->iguales($valorActual, $valorNuevo)) {
                $cambios[$campo] = $valorNuevo;
            }
        }

        $this->aplicarCambiosCliente($clienteId, $cambios, $confirmar, $estado, $resultado);
    }

    /**
     * @param array<string, mixed> $cambios
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function aplicarCambiosCliente(
        int $clienteId,
        array $cambios,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        if ($cambios === []) {
            return;
        }

        $cambios['updated_at'] = now();
        if ($confirmar) {
            DB::table('clientes')->where('id', $clienteId)->update($cambios);
        }
        $estado['clientes'][$clienteId] = array_merge(
            $estado['clientes'][$clienteId],
            $cambios
        );
        $this->indexarCliente($estado, $clienteId, $estado['clientes'][$clienteId]);
        $resultado['clientes_actualizados']++;
    }

    /**
     * @param array<string, mixed> $anterior
     * @param array<string, mixed> $nuevo
     * @return array<string, array{anterior:mixed,nuevo:mixed}>
     */
    private function cambiosIdentidad(array $anterior, array $nuevo): array
    {
        $cambios = [];
        foreach (self::CAMPOS_IDENTIDAD as $campo) {
            $iguales = $campo === 'nombre'
                ? $this->nombresCompatibles(
                    (string) ($anterior[$campo] ?? ''),
                    (string) ($nuevo[$campo] ?? '')
                )
                : $this->iguales($anterior[$campo] ?? null, $nuevo[$campo] ?? null);

            if (! $iguales) {
                $cambios[$campo] = [
                    'anterior' => $anterior[$campo] ?? null,
                    'nuevo' => $nuevo[$campo] ?? null,
                ];
            }
        }

        return $cambios;
    }

    /**
     * @param array<string, mixed>|null $existente
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function guardarOrigen(
        int $clienteId,
        string $entidad,
        array $datos,
        ?array $existente,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $clave = $entidad.'|'.$datos['cuenta'];
        $payload = $this->payload($datos);
        $fila = [
            'cliente_id' => $clienteId,
            'sistema_origen' => self::SISTEMA,
            'entidad_origen' => $entidad,
            'clave_origen' => $datos['cuenta'],
            'estado_origen' => $this->estadoOrigen($entidad, $datos),
            'archivo_origen_id' => $datos['archivo_origen_id'],
            'numero_linea' => $datos['numero_linea'],
            'hash_origen' => $datos['hash_origen'],
            'datos_origen' => $payload,
            'ultimo_importado_at' => now(),
            'updated_at' => now(),
        ];

        if ($existente === null) {
            $fila['created_at'] = now();
            if ($confirmar) {
                DB::table('clientes_origenes')->insert(array_merge($fila, [
                    'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]));
            }
            $resultado['origenes_creados']++;
        } else {
            if ($confirmar) {
                DB::table('clientes_origenes')
                    ->where('sistema_origen', self::SISTEMA)
                    ->where('entidad_origen', $entidad)
                    ->where('clave_origen', $datos['cuenta'])
                    ->update(array_merge($fila, [
                        'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                    ]));
            }
            $fila['created_at'] = $existente['created_at'] ?? now();
            $resultado['origenes_actualizados']++;
        }

        $estado['origenes'][$clave] = $fila;
        $estado['estados_origen'][$clienteId][$clave] = $fila['estado_origen'];
    }

    /**
     * @param array<string, mixed> $existente
     * @param array<string, mixed> $datos
     */
    private function origenNormalizadoSinCambios(
        array $existente,
        string $entidad,
        array $datos
    ): bool {
        return ($existente['estado_origen'] ?? 'DESCONOCIDO')
                === $this->estadoOrigen($entidad, $datos)
            && $this->jsonIgual(
                $existente['datos_origen'] ?? [],
                $this->payload($datos)
            );
    }

    /**
     * Completa tipo de persona y condición de IVA sin pisar correcciones manuales.
     *
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $origenAnterior
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function sincronizarDatosTributarios(
        int $clienteId,
        string $entidad,
        array $datos,
        array $origenAnterior,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $actual = $estado['clientes'][$clienteId];
        $cambios = [];
        $tipoPersona = $this->normalizer->tipoPersona(
            $datos['cuit'],
            $datos['numero_documento']
        );

        if (
            $tipoPersona !== 'DESCONOCIDA'
            && in_array($actual['tipo_persona'] ?? null, [null, '', 'DESCONOCIDA'], true)
        ) {
            $cambios['tipo_persona'] = $tipoPersona;
        }

        $ivaActual = $actual['condicion_iva'] ?? null;
        $ivaNuevo = $datos['condicion_iva'] ?? null;
        $ivaAnterior = $origenAnterior['condicion_iva'] ?? null;

        if ($ivaNuevo !== null && $ivaNuevo !== '') {
            if ($ivaActual === null || $ivaActual === '') {
                $cambios['condicion_iva'] = $ivaNuevo;
            } elseif (
                $ivaAnterior !== null
                && $ivaAnterior !== ''
                && $this->iguales($ivaActual, $ivaAnterior)
                && ! $this->iguales($ivaActual, $ivaNuevo)
            ) {
                // Corrige valores previamente importados con una regla anterior,
                // pero sólo si el cliente todavía conserva exactamente ese valor.
                $cambios['condicion_iva'] = $ivaNuevo;
            } elseif (! $this->iguales($ivaActual, $ivaNuevo)) {
                $this->registrarDiferenciaTributaria(
                    $clienteId,
                    $entidad,
                    $datos,
                    (string) $ivaActual,
                    (string) $ivaNuevo,
                    $confirmar,
                    $resultado
                );
            }
        }

        $this->aplicarCambiosCliente(
            $clienteId,
            $cambios,
            $confirmar,
            $estado,
            $resultado
        );
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, int|bool> $resultado
     */
    private function registrarDiferenciaTributaria(
        int $clienteId,
        string $entidad,
        array $datos,
        string $actual,
        string $origen,
        bool $confirmar,
        array &$resultado
    ): void {
        $firma = hash('sha256', implode('|', [
            self::SISTEMA,
            $entidad,
            $datos['cuenta'],
            'condicion_iva',
            $actual,
            $origen,
        ]));
        $detalle = [
            'regla' => 'NO_PISAR_DATO_TRIBUTARIO_DIFERENTE',
            'tipo_iva_origen' => $datos['tipo_iva_origen'] ?? null,
            'nro_iva_origen' => $datos['nro_iva_origen'] ?? null,
        ];

        if ($confirmar) {
            DB::table('clientes_migracion_conflictos')->insertOrIgnore([
                'cliente_id' => $clienteId,
                'sistema_origen' => self::SISTEMA,
                'entidad_origen' => $entidad,
                'clave_origen' => $datos['cuenta'],
                'campo' => 'condicion_iva',
                'valor_actual' => $actual,
                'valor_origen' => $origen,
                'firma' => $firma,
                'estado' => 'PENDIENTE',
                'detalle' => json_encode($detalle, JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $resultado['diferencias_tributarias']++;
        $this->registrarIncidencia([
            'tipo' => 'DIFERENCIA_TRIBUTARIA',
            'motivo' => 'CONDICION_IVA_DIFERENTE',
            'entidad' => $entidad,
            'cuenta' => $datos['cuenta'],
            'cliente_id' => $clienteId,
            'campo' => 'condicion_iva',
            'valor_actual' => $actual,
            'valor_origen' => $origen,
            'detalle' => $detalle,
        ]);
    }

    /**
     * @param array<string, mixed> $datos
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function guardarCuentaCliente(
        int $clienteId,
        string $entidad,
        string $rol,
        array $datos,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $clave = $clienteId.'|'.$datos['cuenta'].'|'.$rol;
        $existente = $estado['cuentas'][$clave] ?? null;
        $estadoOrigen = $this->estadoOrigen($entidad, $datos);
        $activo = match ($estadoOrigen) {
            'ACTIVO' => true,
            'BAJA' => false,
            default => null,
        };
        $payload = $this->payload($datos);
        $fila = [
            'cliente_id' => $clienteId,
            'cuenta' => $datos['cuenta'],
            'rol' => $rol,
            'activo' => $activo,
            'datos_origen' => $payload,
            'updated_at' => now(),
        ];
        $sinCambios = $existente !== null
            && ($existente['activo'] ?? null) === $activo
            && $this->jsonIgual($existente['datos_origen'] ?? [], $payload);

        if ($existente === null) {
            $fila['created_at'] = now();
            if ($confirmar) {
                DB::table('clientes_cuentas')->insert(array_merge($fila, [
                    'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                ]));
            }
            $resultado['cuentas_creadas']++;
        } elseif ($sinCambios) {
            $fila['created_at'] = $existente['created_at'] ?? now();
            $resultado['cuentas_sin_cambios']++;
        } else {
            $fila['created_at'] = $existente['created_at'] ?? now();
            if ($confirmar) {
                DB::table('clientes_cuentas')
                    ->where('cliente_id', $clienteId)
                    ->where('cuenta', $datos['cuenta'])
                    ->where('rol', $rol)
                    ->update([
                        'activo' => $activo,
                        'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                        'updated_at' => now(),
                    ]);
            }
            $resultado['cuentas_actualizadas']++;
        }

        $estado['cuentas'][$clave] = $fila;
    }

    /**
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function asignarRol(
        int $clienteId,
        string $codigoRol,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $rolId = $estado['roles'][$codigoRol] ?? null;
        if ($rolId === null) {
            throw new RuntimeException("No existe el rol {$codigoRol}.");
        }

        $clave = $clienteId.'|'.$rolId;
        if (isset($estado['relaciones'][$clave])) {
            return;
        }

        if ($confirmar) {
            DB::table('clientes_roles')->insert([
                'cliente_id' => $clienteId,
                'rol_id' => $rolId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $estado['relaciones'][$clave] = true;
        $resultado['roles_asignados']++;
    }

    /**
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function recalcularActividadCliente(
        int $clienteId,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        $estados = array_values($estado['estados_origen'][$clienteId] ?? []);

        $activo = in_array('ACTIVO', $estados, true)
            ? true
            : (in_array('DESCONOCIDO', $estados, true) ? null : false);
        if (($estado['clientes'][$clienteId]['activo'] ?? null) === $activo) {
            return;
        }

        if ($confirmar) {
            DB::table('clientes')->where('id', $clienteId)->update([
                'activo' => $activo,
                'updated_at' => now(),
            ]);
        }
        $estado['clientes'][$clienteId]['activo'] = $activo;
    }

    /**
     * @param array<string, mixed> $datos
     */
    private function estadoOrigen(string $entidad, array $datos): string
    {
        if ($entidad === 'PROPIETAR') {
            return 'DESCONOCIDO';
        }

        return ($datos['activo'] ?? false) ? 'ACTIVO' : 'BAJA';
    }

    /**
     * @param array<string, mixed> $datos
     * @return array<string, mixed>
     */
    private function payload(array $datos): array
    {
        return array_diff_key($datos, array_flip([
            'archivo_origen_id',
            'numero_linea',
            'hash_origen',
        ]));
    }

    /**
     * @param array<string, mixed> $actual
     * @param array<string, mixed> $origen
     * @return array{compatible:bool,detalle:array<string,mixed>}
     */
    private function compatibilidadCandidato(array $actual, array $origen): array
    {
        $coincidencias = [];
        $contradicciones = [];

        foreach ([
            'cuit' => [$actual['cuit'] ?? null, $origen['cuit'] ?? null],
            'documento' => [$this->claveDocumento($actual), $this->claveDocumento($origen)],
        ] as $campo => [$valorActual, $valorOrigen]) {
            if ($valorActual === null || $valorOrigen === null) {
                continue;
            }
            if ($this->iguales($valorActual, $valorOrigen)) {
                $coincidencias[] = $campo;
            } else {
                $contradicciones[$campo] = [
                    'actual' => $valorActual,
                    'origen' => $valorOrigen,
                ];
            }
        }

        if ($contradicciones !== []) {
            return [
                'compatible' => false,
                'detalle' => [
                    'regla' => 'IDENTIFICADORES_CONTRADICTORIOS',
                    'coincidencias' => $coincidencias,
                    'contradicciones' => $contradicciones,
                ],
            ];
        }

        if (! $this->nombresCompatibles(
            (string) ($actual['nombre'] ?? ''),
            (string) ($origen['nombre'] ?? '')
        )) {
            return [
                'compatible' => false,
                'detalle' => [
                    'regla' => count($coincidencias) >= 2
                        ? 'IDENTIFICADORES_COINCIDENTES_NOMBRE_INCOMPATIBLE'
                        : 'IDENTIFICACION_PARCIAL_CON_NOMBRE_INCOMPATIBLE',
                    'coincidencias' => $coincidencias,
                    'nombre_actual' => $actual['nombre'] ?? null,
                    'nombre_origen' => $origen['nombre'] ?? null,
                ],
            ];
        }

        return [
            'compatible' => true,
            'detalle' => [
                'regla' => count($coincidencias) >= 2
                    ? 'DOS_IDENTIFICADORES_COINCIDENTES'
                    : 'IDENTIFICACION_Y_NOMBRE_COMPATIBLES',
                'coincidencias' => $coincidencias,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $estado
     * @param array<string, mixed> $cliente
     */
    private function indexarCliente(array &$estado, int $clienteId, array $cliente): void
    {
        $this->indexarClienteEn(
            $estado['por_cuit'],
            $estado['por_documento'],
            $clienteId,
            $cliente
        );
    }

    /**
     * @param array<string, int|null> $porCuit
     * @param array<string, int|null> $porDocumento
     * @param array<string, mixed> $cliente
     */
    private function indexarClienteEn(
        array &$porCuit,
        array &$porDocumento,
        int $clienteId,
        array $cliente
    ): void {
        if (! empty($cliente['cuit'])) {
            $this->agregarIndice($porCuit, (string) $cliente['cuit'], $clienteId);
        }
        $claveDocumento = $this->claveDocumento($cliente);
        if ($claveDocumento !== null) {
            $this->agregarIndice($porDocumento, $claveDocumento, $clienteId);
        }
    }

    /**
     * @param array<string, int|null> $indice
     */
    private function agregarIndice(array &$indice, string $clave, int $clienteId): void
    {
        if (! array_key_exists($clave, $indice)) {
            $indice[$clave] = $clienteId;
        } elseif ($indice[$clave] !== $clienteId) {
            $indice[$clave] = null;
        }
    }

    /**
     * Devuelve false cuando la clave no existe y conserva null cuando el
     * identificador está asociado a más de un cliente.
     *
     * @param array<string, int|null> $indice
     */
    private function valorIndice(array $indice, string $clave): int|null|false
    {
        return array_key_exists($clave, $indice) ? $indice[$clave] : false;
    }

    /**
     * @param array<string, mixed> $datos
     */
    private function claveDocumento(array $datos): ?string
    {
        if (empty($datos['tipo_documento']) || empty($datos['numero_documento'])) {
            return null;
        }

        return $datos['tipo_documento'].'|'.$datos['numero_documento'];
    }

    private function nombresCompatibles(string $a, string $b): bool
    {
        $a = $this->normalizarNombreComparacion($a);
        $b = $this->normalizarNombreComparacion($b);
        if ($a === '' || $b === '') {
            return false;
        }
        if ($a === $b || str_contains($a, $b) || str_contains($b, $a)) {
            return true;
        }

        $ruido = array_flip([
            'DE', 'DEL', 'LA', 'LAS', 'LOS', 'Y', 'E',
            'SUC', 'SUCESION', 'SRL', 'SA', 'CIA', 'HNOS',
        ]);
        $tokensA = array_values(array_unique(array_filter(
            explode(' ', $a),
            fn (string $token): bool => ! isset($ruido[$token])
        )));
        $tokensB = array_values(array_unique(array_filter(
            explode(' ', $b),
            fn (string $token): bool => ! isset($ruido[$token])
        )));
        $comunes = array_intersect($tokensA, $tokensB);
        $base = min(count($tokensA), count($tokensB));

        return count($comunes) >= 2
            && $base > 0
            && (count($comunes) / $base) >= 0.5;
    }

    private function normalizarNombreComparacion(string $valor): string
    {
        $valor = mb_strtoupper(trim($valor));
        $valor = preg_replace('/\s+NO\s*$/u', '', $valor) ?? $valor;
        $valor = preg_replace('/[^\pL\pN]+/u', ' ', $valor) ?? $valor;

        return preg_replace('/\s+/u', ' ', trim($valor)) ?? trim($valor);
    }

    private function iguales(mixed $a, mixed $b): bool
    {
        return mb_strtoupper(trim((string) $a)) === mb_strtoupper(trim((string) $b));
    }

    private function jsonIgual(mixed $a, mixed $b): bool
    {
        return $this->normalizarJson($a) === $this->normalizarJson($b);
    }

    private function normalizarJson(mixed $valor): mixed
    {
        if (is_object($valor)) {
            $valor = (array) $valor;
        }

        if (! is_array($valor)) {
            return $valor;
        }

        foreach ($valor as $clave => $item) {
            $valor[$clave] = $this->normalizarJson($item);
        }

        if (! array_is_list($valor)) {
            ksort($valor);
        }

        return $valor;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodificarJson(mixed $valor): array
    {
        if (is_array($valor)) {
            return $valor;
        }
        if (is_object($valor)) {
            return (array) $valor;
        }
        if (! is_string($valor) || $valor === '') {
            return [];
        }

        try {
            $decodificado = json_decode($valor, true, 512, JSON_THROW_ON_ERROR);

            return is_array($decodificado) ? $decodificado : [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array<string, mixed> $incidencia
     */
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
        foreach (['propietar', 'inquilino'] as $tabla) {
            $existe = $origen->selectOne(
                'select to_regclass(?) as tabla',
                [$this->schema().'.'.$tabla]
            );
            if (($existe->tabla ?? null) === null) {
                throw new RuntimeException(
                    "No existe {$this->schema()}.{$tabla} en gei_exploracion."
                );
            }
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
