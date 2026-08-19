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

            // Detectar dentro del MISMO archivo importado partidas que intentan
            // identificar más de una entidad (canónica o clave nueva). No elegimos.
            $identidadesFuentePorPartida = [];
            $origenesNuevosPorClave = [];
            foreach ($filas as $datosFuente) {
                if (
                    $datosFuente['cuenta_inquilino'] === ''
                    || $datosFuente['cuenta_propietario'] === ''
                    || $datosFuente['direccion_normalizada'] === ''
                    || $datosFuente['clave_inmueble'] === ''
                ) {
                    continue;
                }
                $idExistenteFuente = $this->resolverInmuebleExistenteId($datosFuente, $estado);
                $crearSeparadoFuente = $this->tieneResolucionCrearSeparado($datosFuente, $estado);
                $identidadFuente = $idExistenteFuente === null
                    ? 'ORIGEN|'.$datosFuente['cuenta_inquilino']
                    : 'ID|'.$idExistenteFuente;
                if ($idExistenteFuente === null && ! $crearSeparadoFuente) {
                    $origenesNuevosPorClave[(string) $datosFuente['clave_inmueble']][(string) $datosFuente['cuenta_inquilino']] = true;
                }
                foreach ($datosFuente['partidas'] ?? [] as $partidaFuente) {
                    $identidadesFuentePorPartida[(string) $partidaFuente][$identidadFuente] = true;
                }
            }

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
                    $inmuebleExistenteId = $this->resolverInmuebleExistenteId($datos, $estado);
                    $crearSeparado = $this->tieneResolucionCrearSeparado($datos, $estado);

                    if (
                        $inmuebleExistenteId === null
                        && ! $crearSeparado
                        && count($origenesNuevosPorClave[(string) $datos['clave_inmueble']] ?? []) > 1
                    ) {
                        $resultado['requieren_revision_identidad']++;
                        $this->registrarConflicto(
                            ['id' => null, 'clave_migracion' => $datos['clave_inmueble']],
                            $datos,
                            'CLAVE_MIGRACION_COMPARTIDA_POR_VARIOS_ORIGENES',
                            [
                                'cuentas_inquilino_detectadas' => array_keys(
                                    $origenesNuevosPorClave[(string) $datos['clave_inmueble']] ?? []
                                ),
                                'regla' => 'MISMA_CUENTA_PROPIETARIO_Y_DOMICILIO_NO_AUTORIZA_AGRUPAR_ORIGENES',
                            ],
                            $confirmar,
                            $estado,
                            $resultado
                        );
                        continue;
                    }

                    if ($inmuebleExistenteId === null && ! $crearSeparado) {
                        $candidatosClave = $this->candidatosPorClaveMigracion($datos, $estado);
                        if ($candidatosClave !== []) {
                            $resultado['requieren_revision_identidad']++;
                            $this->registrarConflicto(
                                ['id' => null, 'clave_migracion' => $datos['clave_inmueble']],
                                $datos,
                                'CLAVE_MIGRACION_COINCIDENTE_REQUIERE_REVISION',
                                [
                                    'inmuebles_candidatos' => $candidatosClave,
                                    'regla' => 'CUENTA_PROPIETARIO_MAS_DOMICILIO_ES_EVIDENCIA_NO_IDENTIDAD',
                                ],
                                $confirmar,
                                $estado,
                                $resultado
                            );
                            continue;
                        }
                    }

                    $partidasAmbiguasFuente = ($inmuebleExistenteId === null && ! $crearSeparado)
                        ? $this->partidasConMultiplesIdentidadesFuente($datos, $identidadesFuentePorPartida)
                        : [];
                    if ($partidasAmbiguasFuente !== []) {
                        $resultado['requieren_revision_identidad']++;
                        $this->registrarConflicto(
                            ['id' => null, 'clave_migracion' => $datos['clave_inmueble']],
                            $datos,
                            'PARTIDA_MULTIPLE_IDENTIDAD_EN_ARCHIVO',
                            [
                                'partidas_ambiguas' => $partidasAmbiguasFuente,
                                'identidades_detectadas' => array_map(
                                    fn (string $partida): array => array_keys($identidadesFuentePorPartida[$partida] ?? []),
                                    $partidasAmbiguasFuente
                                ),
                                'regla' => 'NO_CREAR_NI_REASIGNAR_REQUIERE_REVISION_HUMANA',
                            ],
                            $confirmar,
                            $estado,
                            $resultado
                        );
                        continue;
                    }

                    // Si es una identidad COBOL nueva, una partida vigente ya asociada a
                    // otro inmueble es evidencia fuerte, pero NO autoriza una fusión.
                    // Se detiene el alta de ese posible duplicado y queda para revisión humana.
                    if ($inmuebleExistenteId === null && ! $crearSeparado) {
                        $candidatosPartida = $this->candidatosPorPartidas($datos, $estado);
                        if ($candidatosPartida !== []) {
                            $resultado['requieren_revision_identidad']++;
                            $this->registrarConflicto(
                                ['id' => null, 'clave_migracion' => $datos['clave_inmueble']],
                                $datos,
                                count($candidatosPartida) === 1
                                    ? 'PARTIDA_ASOCIADA_A_OTRO_INMUEBLE'
                                    : 'PARTIDA_ASOCIADA_A_VARIOS_INMUEBLES',
                                [
                                    'inmuebles_candidatos' => $candidatosPartida,
                                    'partidas_coincidentes' => $this->partidasCoincidentes($datos, $estado),
                                    'regla' => 'NO_CREAR_AUTOMATICAMENTE_REQUIERE_REVISION_HUMANA',
                                ],
                                $confirmar,
                                $estado,
                                $resultado
                            );
                            continue;
                        }
                    }

                    $claveGrupo = $inmuebleExistenteId === null
                        ? 'ORIGEN|'.$datos['cuenta_inquilino']
                        : 'ID|'.$inmuebleExistenteId;

                    if (! isset($grupos[$claveGrupo])) {
                        $grupos[$claveGrupo] = [
                            'id_inmueble_existente' => $inmuebleExistenteId,
                            'representante' => $datos,
                            'representante_canonico' => false,
                            'filas' => [],
                            'activo' => false,
                        ];
                    }

                    if ($inmuebleExistenteId !== null) {
                        $existente = $estado['inmuebles_por_id'][$inmuebleExistenteId] ?? null;
                        if (
                            $existente !== null
                            && $datos['clave_inmueble'] === ($existente['clave_migracion'] ?? null)
                        ) {
                            // Si entre varias identidades COBOL hay una que coincide con la
                            // clave canónica conservada, ésa es la única autorizada a refrescar
                            // los datos maestros del inmueble.
                            $grupos[$claveGrupo]['representante'] = $datos;
                            $grupos[$claveGrupo]['representante_canonico'] = true;
                        }
                    }

                    $grupos[$claveGrupo]['filas'][] = $datos;
                    $grupos[$claveGrupo]['activo'] = $grupos[$claveGrupo]['activo']
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
                    $resultado,
                    $grupo['id_inmueble_existente'],
                    (bool) $grupo['representante_canonico']
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

                // Al resolver varias identidades históricas al mismo inmueble canónico
                // pueden coexistir distintas cuentas de propietario. Procesamos sólo las
                // que están ACTIVAS en la fuente actual; una baja histórica no reactiva titularidad.
                $propietariosActivos = [];
                foreach ($grupo['filas'] as $datosPropietario) {
                    if ($datosPropietario['estado_origen'] !== 'ACTIVO') {
                        continue;
                    }
                    $propietariosActivos[$datosPropietario['cuenta_propietario']] = $datosPropietario;
                }
                ksort($propietariosActivos, SORT_STRING);
                foreach ($propietariosActivos as $datosPropietario) {
                    $this->guardarRelacionPropietario(
                        $inmueble,
                        $datosPropietario,
                        $confirmar,
                        $estado,
                        $resultado
                    );
                }

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
            'requieren_revision_identidad' => 0,
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
        $inmueblesPorId = [];
        $maxInmuebleId = 0;
        foreach (DB::table('inmuebles')->orderBy('id')->cursor() as $fila) {
            $inmueblesPorId[(int) $fila->id] = (array) $fila;
            $maxInmuebleId = max($maxInmuebleId, (int) $fila->id);
        }

        $inmueblesPorClave = [];
        foreach ($inmueblesPorId as $fila) {
            $canonicoId = $this->resolverCanonicoIdDesdeMapa((int) $fila['id'], $inmueblesPorId);
            $canonico = $inmueblesPorId[$canonicoId] ?? $fila;
            $inmueblesPorClave[(string) $fila['clave_migracion']][$canonicoId] = $canonico;
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

        $inmueblesPorPartida = [];
        foreach ($partidas as $clavePartida => $partida) {
            $id = (int) $partida['inmueble_id'];
            $canonicoId = $this->resolverCanonicoIdDesdeMapa($id, $inmueblesPorId);
            $valorPartida = (string) $partida['partida'];
            $inmueblesPorPartida[$valorPartida][$canonicoId] = true;
        }

        $resolucionesOrigen = [];
        foreach (DB::table('inmuebles_resoluciones_origen')->cursor() as $fila) {
            $resolucionesOrigen[$fila->sistema_origen.'|'.$fila->entidad_origen.'|'.$fila->clave_origen] = (array) $fila;
        }

        $conflictos = [];
        foreach (DB::table('inmuebles_conflictos')->cursor() as $fila) {
            $conflicto = (array) $fila;
            $conflicto['detalle'] = $this->decodificarJson($fila->detalle ?? null);
            $conflictos[$fila->firma] = $conflicto;
        }

        return [
            'inmuebles_por_id' => $inmueblesPorId,
            'inmuebles_por_clave' => $inmueblesPorClave,
            'origenes' => $origenes,
            'cuentas_propietarios' => $cuentasPropietarios,
            'conflictos_clientes' => $conflictosClientes,
            'relaciones' => $relaciones,
            'partidas' => $partidas,
            'inmuebles_por_partida' => $inmueblesPorPartida,
            'resoluciones_origen' => $resolucionesOrigen,
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
        array &$resultado,
        ?int $inmuebleExistenteId = null,
        bool $actualizarDatosMaestros = false
    ): array {
        $clave = $datos['clave_inmueble'];
        // Una clave derivada (propietario + domicilio) NO identifica por sí sola.
        // Sólo reutilizamos inmueble cuando el origen o una decisión humana aportó ID.
        $existente = $inmuebleExistenteId === null
            ? null
            : ($estado['inmuebles_por_id'][$inmuebleExistenteId] ?? null);

        if ($existente !== null && ($existente['id_inmueble_canonico'] ?? null) !== null) {
            $canonicoId = $this->resolverCanonicoIdDesdeMapa((int) $existente['id'], $estado['inmuebles_por_id']);
            $existente = $estado['inmuebles_por_id'][$canonicoId] ?? $existente;
        }

        if ($existente === null) {
            $fila = [
                'clave_migracion' => $clave,
                'id_inmueble_canonico' => null,
                'codigo_origen' => null,
                'domicilio' => $datos['direccion_normalizada'],
                'domicilio_normalizado' => $datos['direccion_normalizada'],
                'destino_codigo' => $datos['destino'],
                'identificador_cochera' => $datos['identificador_cochera'],
                'estado' => $activo ? 'ACTIVO' : 'INACTIVO',
                'observaciones' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
            if ($confirmar) {
                $fila['id'] = DB::table('inmuebles')->insertGetId($fila);
            } else {
                $fila['id'] = $estado['proximo_inmueble_id']++;
            }
            $resultado['inmuebles_creados']++;
        } else {
            $fila = $existente;
            $fila['id'] = (int) $existente['id'];
            $fila['estado'] = $activo ? 'ACTIVO' : 'INACTIVO';

            if ($actualizarDatosMaestros || $clave === ($existente['clave_migracion'] ?? null)) {
                $fila['codigo_origen'] = $existente['codigo_origen'] ?? null;
                $fila['domicilio'] = $datos['direccion_normalizada'];
                $fila['domicilio_normalizado'] = $datos['direccion_normalizada'];
                $fila['destino_codigo'] = $datos['destino'];
                $fila['identificador_cochera'] = $datos['identificador_cochera'];
                $fila['observaciones'] = $existente['observaciones'] ?? null;
            }

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

        $estado['inmuebles_por_id'][(int) $fila['id']] = $fila;
        $estado['inmuebles_por_clave'][(string) $clave][(int) $fila['id']] = $fila;
        $estado['inmuebles_por_clave'][(string) $fila['clave_migracion']][(int) $fila['id']] = $fila;

        return $fila;
    }

    private function tieneResolucionCrearSeparado(array $datos, array $estado): bool
    {
        $clave = self::SISTEMA.'|'.self::ENTIDAD.'|'.$datos['cuenta_inquilino'];

        return ($estado['resoluciones_origen'][$clave]['decision'] ?? null) === 'CREAR_SEPARADO';
    }

    /** @return list<string> */
    private function partidasConMultiplesIdentidadesFuente(array $datos, array $identidadesFuentePorPartida): array
    {
        $resultado = [];
        foreach ($datos['partidas'] ?? [] as $partida) {
            $partida = (string) $partida;
            if (count($identidadesFuentePorPartida[$partida] ?? []) > 1) {
                $resultado[] = $partida;
            }
        }
        $resultado = array_values(array_unique($resultado));
        sort($resultado, SORT_STRING);

        return $resultado;
    }

    /** @return list<int> */
    private function candidatosPorClaveMigracion(array $datos, array $estado): array
    {
        $ids = array_map(
            'intval',
            array_keys($estado['inmuebles_por_clave'][(string) $datos['clave_inmueble']] ?? [])
        );
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @return list<int> */
    private function candidatosPorPartidas(array $datos, array $estado): array
    {
        $ids = [];
        foreach ($datos['partidas'] ?? [] as $partida) {
            foreach (array_keys($estado['inmuebles_por_partida'][(string) $partida] ?? []) as $id) {
                $ids[(int) $id] = true;
            }
        }
        $resultado = array_map('intval', array_keys($ids));
        sort($resultado, SORT_NUMERIC);

        return $resultado;
    }

    /** @return list<string> */
    private function partidasCoincidentes(array $datos, array $estado): array
    {
        $partidas = [];
        foreach ($datos['partidas'] ?? [] as $partida) {
            if (isset($estado['inmuebles_por_partida'][(string) $partida])) {
                $partidas[] = (string) $partida;
            }
        }
        $partidas = array_values(array_unique($partidas));
        sort($partidas, SORT_STRING);

        return $partidas;
    }

    /**
     * Un origen COBOL ya asociado tiene prioridad sobre cualquier similitud o
     * clave calculada. Si ese inmueble fue unificado, se resuelve al canónico.
     */
    private function resolverInmuebleExistenteId(array $datos, array $estado): ?int
    {
        $claveOrigen = self::ENTIDAD.'|'.$datos['cuenta_inquilino'];
        $origen = $estado['origenes'][$claveOrigen] ?? null;

        if ($origen !== null) {
            return $this->resolverCanonicoIdDesdeMapa(
                (int) $origen['inmueble_id'],
                $estado['inmuebles_por_id']
            );
        }

        $resolucion = $estado['resoluciones_origen'][self::SISTEMA.'|'.self::ENTIDAD.'|'.$datos['cuenta_inquilino']] ?? null;
        if (($resolucion['decision'] ?? null) === 'ASOCIAR_EXISTENTE' && ($resolucion['inmueble_id'] ?? null) !== null) {
            return $this->resolverCanonicoIdDesdeMapa(
                (int) $resolucion['inmueble_id'],
                $estado['inmuebles_por_id']
            );
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $mapa */
    private function resolverCanonicoIdDesdeMapa(int $id, array $mapa): int
    {
        $visitados = [];
        $actual = $id;

        while (isset($mapa[$actual])) {
            if (isset($visitados[$actual])) {
                throw new RuntimeException('Se detectó un ciclo en la canonicalización de inmuebles.');
            }
            $visitados[$actual] = true;
            $siguiente = $mapa[$actual]['id_inmueble_canonico'] ?? null;
            if ($siguiente === null) {
                return $actual;
            }
            $actual = (int) $siguiente;
        }

        return $id;
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

        if ($candidatos === []) {
            $motivo = isset($estado['conflictos_clientes'][$cuenta])
                ? 'PROPIETARIO_EN_CONFLICTO'
                : 'CUENTA_PROPIETARIO_NO_ENCONTRADA';
            $this->registrarConflicto(
                $inmueble,
                $datos,
                $motivo,
                ['clientes_cuentas_candidatas' => []],
                $confirmar,
                $estado,
                $resultado
            );

            return;
        }

        /*
         * Una cuenta COBOL puede corresponder a varios propietarios/copropietarios.
         * INQUILINO sólo aporta la cuenta del propietario; no permite decidir por sí
         * solo qué clientes integran la titularidad. Si las relaciones ya fueron
         * reconstruidas por una fuente más precisa, este migrador debe respetarlas.
         */
        if (count($candidatos) > 1) {
            $relacionesExistentes = [];
            foreach ($candidatos as $candidato) {
                $claveRelacion = $inmueble['id'].'|'.$candidato['id'];
                if (isset($estado['relaciones'][$claveRelacion])) {
                    $relacionesExistentes[] = $estado['relaciones'][$claveRelacion];
                }
            }

            if (count($relacionesExistentes) === count($candidatos)) {
                // La titularidad múltiple ya está resuelta. No modificar porcentaje,
                // vigencias, origen ni ningún otro dato de esas relaciones.
                $resultado['relaciones_sin_cambios'] += count($relacionesExistentes);
                $this->resolverConflictosPropietario(
                    $inmueble,
                    $cuenta,
                    $confirmar,
                    $estado,
                    $resultado
                );

                return;
            }

            $this->registrarConflicto(
                $inmueble,
                $datos,
                'CUENTA_PROPIETARIO_MULTIPLE_RELACION_INCOMPLETA',
                [
                    'clientes_cuentas_candidatas' => array_column($candidatos, 'id'),
                    'clientes_cuentas_con_relacion' => array_values(array_map(
                        fn (array $relacion): int => (int) $relacion['cliente_cuenta_id'],
                        $relacionesExistentes
                    )),
                ],
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
            // INQUILINO no contiene este dato. Nunca borrar un porcentaje que ya
            // haya sido cargado desde liquidaciones o desde una fuente histórica.
            'porcentaje' => $existente['porcentaje'] ?? null,
            'vigencia_desde' => $existente['vigencia_desde'] ?? null,
            'vigencia_hasta' => $existente['vigencia_hasta'] ?? null,
            'activo' => true,
            'origen' => $existente['origen'] ?? self::SISTEMA,
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
                            // Deliberadamente no se actualizan porcentaje ni vigencias.
                            'activo' => true,
                            'origen' => $fila['origen'],
                            'datos_origen' => json_encode($payload, JSON_UNESCAPED_UNICODE),
                            'updated_at' => now(),
                        ]);
                }
                $resultado['relaciones_actualizadas']++;
            }
        }

        $estado['relaciones'][$clave] = array_merge($existente ?? [], $fila);
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
        // El conflicto queda identificado por la identidad de origen (cuenta
        // de inquilino), no sólo por la clave derivada del inmueble. Esto evita
        // colapsar conflictos de dos inmuebles distintos con mismo propietario/domicilio.
        $firma = hash('sha256', implode('|', [
            $motivo,
            $datos['clave_inmueble'] ?? '',
            $datos['cuenta_propietario'] ?? '',
            $datos['cuenta_inquilino'] ?? '',
        ]));
        $firmaAnterior = hash('sha256', implode('|', [
            $motivo,
            $datos['clave_inmueble'] ?? '',
            $datos['cuenta_propietario'] ?? '',
            str_starts_with($motivo, 'CUENTA_INQUILINO')
                || str_starts_with($motivo, 'DIRECCION_FINCA')
                || str_starts_with($motivo, 'PARTIDA_')
                || str_starts_with($motivo, 'CLAVE_MIGRACION_')
                ? ($datos['cuenta_inquilino'] ?? '')
                : '',
        ]));
        $firmaExistente = isset($estado['conflictos'][$firma])
            ? $firma
            : (isset($estado['conflictos'][$firmaAnterior]) ? $firmaAnterior : null);
        $existente = $firmaExistente === null ? null : $estado['conflictos'][$firmaExistente];
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
            $sinCambios = $firmaExistente === $firma
                && ($existente['estado'] ?? null) === 'PENDIENTE'
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
                    DB::table('inmuebles_conflictos')->where('firma', $firmaExistente ?? $firma)->update([
                        'firma' => $firma,
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

        if ($firmaExistente !== null && $firmaExistente !== $firma) {
            unset($estado['conflictos'][$firmaExistente]);
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
                || (int) ($conflicto['inmueble_id'] ?? 0) !== (int) $inmueble['id']
                || ($conflicto['clave_inmueble'] ?? null) !== $inmueble['clave_migracion']
                || ($conflicto['cuenta_propietario'] ?? null) !== $cuenta
                || ! in_array($conflicto['motivo'] ?? '', [
                    'CUENTA_PROPIETARIO_AMBIGUA',
                    'CUENTA_PROPIETARIO_MULTIPLE_RELACION_INCOMPLETA',
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
