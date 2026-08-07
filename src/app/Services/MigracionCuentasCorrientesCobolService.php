<?php

namespace App\Services;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class MigracionCuentasCorrientesCobolService
{
    private const LOTE = 1000;

    /** @var null|Closure(array<string, mixed>):void */
    private ?Closure $incidencia = null;

    public function __construct(
        private readonly MovimientoCuentaCobolNormalizer $normalizer,
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
        ?Closure $incidencia = null,
        bool $reiniciar = false
    ): array {
        if ($reiniciar && ! $confirmar) {
            throw new RuntimeException('--reiniciar requiere --confirmar.');
        }
        if ($reiniciar && $limite !== null) {
            throw new RuntimeException('--reiniciar no puede combinarse con --limite.');
        }

        $this->incidencia = $incidencia;
        $origen = $this->conexionExploracion();
        $this->validarOrigen($origen);
        $archivosFuente = [
            'PROPIETARIO' => $this->archivoFuenteMasReciente($origen, 'ctactepro'),
            'INQUILINO' => $this->archivoFuenteMasReciente($origen, 'inqctacte'),
        ];
        $totales = [
            'PROPIETARIO' => $this->totalFuente($origen, 'ctactepro', $archivosFuente['PROPIETARIO'], $limite),
            'INQUILINO' => $this->totalFuente($origen, 'inqctacte', $archivosFuente['INQUILINO'], $limite),
        ];
        $resultado = $this->resultadoInicial($confirmar, $totales);
        $resultado['archivo_propietarios_id'] = $archivosFuente['PROPIETARIO'];
        $resultado['archivo_inquilinos_id'] = $archivosFuente['INQUILINO'];

        $procesar = function () use ($origen, $limite, $avance, $confirmar, $reiniciar, $totales, $archivosFuente, &$resultado): void {
            if ($reiniciar) {
                $resultado['incidencias_eliminadas'] = DB::table('cuentas_corrientes_incidencias')->delete();
                $resultado['movimientos_eliminados'] = DB::table('cuentas_corrientes_movimientos')->delete();
                $resultado['cuentas_eliminadas'] = DB::table('cuentas_corrientes')->delete();
            }
            $estado = $this->cargarEstadoDestino();
            $detectadas = [];

            foreach ([
                ['dominio' => 'PROPIETARIO', 'rol' => 'PROPIETARIO', 'tabla' => 'ctactepro'],
                ['dominio' => 'INQUILINO', 'rol' => 'INQUILINO', 'tabla' => 'inqctacte'],
            ] as $grupo) {
                $this->prepararCuentas(
                    $origen,
                    $grupo['tabla'],
                    $archivosFuente[$grupo['dominio']],
                    $grupo['dominio'],
                    $grupo['rol'],
                    $limite,
                    $confirmar,
                    $estado,
                    $detectadas,
                    $resultado
                );

                $lote = [];
                $procesadosGrupo = 0;
                foreach ($this->filasFuente($origen, $grupo['tabla'], $archivosFuente[$grupo['dominio']], $limite) as $fila) {
                    $lote[] = $grupo['dominio'] === 'PROPIETARIO'
                        ? $this->normalizer->propietario($fila)
                        : $this->normalizer->inquilino($fila);
                    $procesadosGrupo++;

                    if (count($lote) === self::LOTE) {
                        $this->procesarLote($lote, $confirmar, $estado, $detectadas, $resultado);
                        $lote = [];
                        $this->informarAvance($avance, $grupo['tabla'], $procesadosGrupo, $totales[$grupo['dominio']]);
                    }
                }
                if ($lote !== []) {
                    $this->procesarLote($lote, $confirmar, $estado, $detectadas, $resultado);
                }
                $this->informarAvance($avance, $grupo['tabla'], $procesadosGrupo, $totales[$grupo['dominio']], true);
            }

            if ($limite === null) {
                $this->resolverIncidenciasAusentes($detectadas, $confirmar, $estado, $resultado);
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

    /** @param array<string, int> $totales @return array<string, int|bool> */
    private function resultadoInicial(bool $confirmar, array $totales): array
    {
        return [
            'confirmado' => $confirmar,
            'cuentas_eliminadas' => 0,
            'movimientos_eliminados' => 0,
            'incidencias_eliminadas' => 0,
            'fuentes_propietarios' => $totales['PROPIETARIO'],
            'fuentes_inquilinos' => $totales['INQUILINO'],
            'procesados' => 0,
            'registros_validos' => 0,
            'cuentas_creadas' => 0,
            'cuentas_actualizadas' => 0,
            'cuentas_sin_cambios' => 0,
            'movimientos_creados' => 0,
            'movimientos_actualizados' => 0,
            'movimientos_sin_cambios' => 0,
            'movimientos_debito' => 0,
            'movimientos_credito' => 0,
            'movimientos_sin_efecto' => 0,
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

    /** @return array<string, mixed> */
    private function cargarEstadoDestino(): array
    {
        $cuentas = [];
        $maxCuentaId = 0;
        foreach (DB::table('cuentas_corrientes')->cursor() as $fila) {
            $cuentas[$fila->dominio.'|'.$fila->cuenta] = (array) $fila;
            $maxCuentaId = max($maxCuentaId, (int) $fila->id);
        }

        $clientesCuentas = [];
        foreach (DB::table('clientes_cuentas')->cursor() as $fila) {
            $clientesCuentas[$fila->rol.'|'.$fila->cuenta][] = (array) $fila;
        }

        $contratos = [];
        foreach (DB::table('contratos')->select(['id', 'cuenta_inquilino'])->cursor() as $fila) {
            $contratos[$fila->cuenta_inquilino][] = (int) $fila->id;
        }

        $incidencias = [];
        foreach (DB::table('cuentas_corrientes_incidencias')->cursor() as $fila) {
            $incidencia = (array) $fila;
            $incidencia['detalle'] = $this->decodificarJson($fila->detalle ?? null);
            $incidencias[$fila->firma] = $incidencia;
        }

        return [
            'cuentas' => $cuentas,
            'clientes_cuentas' => $clientesCuentas,
            'contratos' => $contratos,
            'incidencias' => $incidencias,
            'proximo_cuenta_id' => $maxCuentaId + 1,
        ];
    }

    /**
     * @param array<string, mixed> $estado
     * @param array<string, bool> $detectadas
     * @param array<string, int|bool> $resultado
     */
    private function prepararCuentas(
        ConnectionInterface $origen,
        string $tabla,
        int $archivoId,
        string $dominio,
        string $rol,
        ?int $limite,
        bool $confirmar,
        array &$estado,
        array &$detectadas,
        array &$resultado
    ): void {
        $schema = $this->schema();
        $sql = $limite === null
            ? "SELECT DISTINCT cuenta FROM {$schema}.{$tabla} WHERE archivo_id = {$archivoId} ORDER BY cuenta"
            : "SELECT DISTINCT cuenta FROM (
                SELECT cuenta
                FROM {$schema}.{$tabla}
                WHERE archivo_id = {$archivoId}
                ORDER BY cuenta, fecha, codigo, numero
                LIMIT ".max(0, $limite)."
            ) limitados ORDER BY cuenta";
        foreach ($origen->select($sql) as $fila) {
            $cuenta = preg_replace('/\D+/', '', (string) $fila->cuenta) ?? '';
            if ($cuenta === '') {
                continue;
            }
            $relaciones = $estado['clientes_cuentas'][$rol.'|'.$cuenta] ?? [];
            $relacion = count($relaciones) === 1 ? $relaciones[0] : null;
            $contratos = $dominio === 'INQUILINO' ? ($estado['contratos'][$cuenta] ?? []) : [];
            $contratoId = count($contratos) === 1 ? $contratos[0] : null;
            $clave = $dominio.'|'.$cuenta;
            $existente = $estado['cuentas'][$clave] ?? null;
            $filaCuenta = [
                'dominio' => $dominio,
                'cuenta' => $cuenta,
                'cliente_id' => $relacion['cliente_id'] ?? null,
                'cliente_cuenta_id' => $relacion['id'] ?? null,
                'contrato_id' => $contratoId,
                'activo' => $relacion['activo'] ?? null,
                'origen' => 'COBOL',
            ];

            if ($existente === null) {
                $filaCuenta['created_at'] = now();
                $filaCuenta['updated_at'] = now();
                $filaCuenta['id'] = $confirmar
                    ? DB::table('cuentas_corrientes')->insertGetId($filaCuenta)
                    : $estado['proximo_cuenta_id']++;
                $resultado['cuentas_creadas']++;
            } else {
                $filaCuenta['id'] = (int) $existente['id'];
                $campos = ['cliente_id', 'cliente_cuenta_id', 'contrato_id', 'activo', 'origen'];
                if ($this->camposIguales($existente, $filaCuenta, $campos)) {
                    $filaCuenta['created_at'] = $existente['created_at'] ?? now();
                    $filaCuenta['updated_at'] = $existente['updated_at'] ?? now();
                    $resultado['cuentas_sin_cambios']++;
                } else {
                    if ($confirmar) {
                        DB::table('cuentas_corrientes')->where('id', $filaCuenta['id'])->update(
                            array_merge(array_intersect_key($filaCuenta, array_flip($campos)), ['updated_at' => now()])
                        );
                    }
                    $filaCuenta['created_at'] = $existente['created_at'] ?? now();
                    $filaCuenta['updated_at'] = now();
                    $resultado['cuentas_actualizadas']++;
                }
            }
            $estado['cuentas'][$clave] = $filaCuenta;

            if (count($relaciones) !== 1) {
                $motivo = $relaciones === [] ? 'CUENTA_CLIENTE_NO_ENCONTRADA' : 'CUENTA_CLIENTE_AMBIGUA';
                $firma = $this->registrarIncidencia(
                    $filaCuenta,
                    null,
                    $dominio,
                    $cuenta,
                    'CONFLICTO',
                    $motivo,
                    true,
                    ['coincidencias' => count($relaciones)],
                    $confirmar,
                    $estado,
                    $resultado
                );
                $detectadas[$firma] = true;
            }
        }
    }

    /**
     * @param list<array<string, mixed>> $lote
     * @param array<string, mixed> $estado
     * @param array<string, bool> $detectadas
     * @param array<string, int|bool> $resultado
     */
    private function procesarLote(
        array $lote,
        bool $confirmar,
        array &$estado,
        array &$detectadas,
        array &$resultado
    ): void {
        $claves = array_column($lote, 'clave_origen');
        $existentes = DB::table('cuentas_corrientes_movimientos')
            ->whereIn('clave_origen', $claves)
            ->get()
            ->keyBy('clave_origen')
            ->map(fn (object $fila): array => (array) $fila)
            ->all();
        $paraUpsert = [];

        foreach ($lote as $datos) {
            $resultado['procesados']++;
            if ($datos['cuenta'] === '' || $datos['codigo'] === '' || $datos['numero'] === '') {
                $resultado['omitidos']++;
                continue;
            }
            $resultado['registros_validos']++;
            if ($datos['tipo_movimiento'] === 'SIN_EFECTO') {
                $resultado['movimientos_sin_efecto']++;
            } elseif ($datos['tipo_movimiento'] === 'DEBITO') {
                $resultado['movimientos_debito']++;
            } else {
                $resultado['movimientos_credito']++;
            }

            $cuenta = $estado['cuentas'][$datos['dominio'].'|'.$datos['cuenta']] ?? null;
            if ($cuenta === null) {
                throw new RuntimeException('No se preparó la cuenta '.$datos['dominio'].' '.$datos['cuenta'].'.');
            }
            $existente = $existentes[$datos['clave_origen']] ?? null;
            $fila = $this->filaMovimiento($datos, (int) $cuenta['id']);
            // Un archivo acumulativo posterior puede repetir exactamente el mismo
            // registro con otro archivo_id o número de línea. Si el hash no cambió,
            // se conserva la primera trazabilidad y el movimiento queda sin cambios.
            if ($existente !== null && ($existente['hash_origen'] ?? null) === $datos['hash_origen']) {
                foreach (['archivo_origen_id', 'numero_linea', 'hash_origen'] as $campoOrigen) {
                    $fila[$campoOrigen] = $existente[$campoOrigen] ?? $fila[$campoOrigen];
                }
            }
            $campos = array_keys($fila);

            if ($existente === null) {
                $fila['created_at'] = now();
                $fila['updated_at'] = now();
                $resultado['movimientos_creados']++;
                $paraUpsert[] = $fila;
            } elseif ($this->camposIguales($existente, $fila, $campos)) {
                $resultado['movimientos_sin_cambios']++;
            } else {
                $fila['created_at'] = $existente['created_at'] ?? now();
                $fila['updated_at'] = now();
                $resultado['movimientos_actualizados']++;
                $paraUpsert[] = $fila;
            }

            foreach ($datos['advertencias'] as $motivo => $detalle) {
                $firma = $this->registrarIncidencia(
                    $cuenta,
                    null,
                    $datos['dominio'],
                    $datos['cuenta'],
                    'ADVERTENCIA',
                    $motivo,
                    false,
                    array_merge($detalle, ['clave_origen' => $datos['clave_origen']]),
                    $confirmar,
                    $estado,
                    $resultado
                );
                $detectadas[$firma] = true;
            }
        }

        if ($confirmar && $paraUpsert !== []) {
            DB::table('cuentas_corrientes_movimientos')->upsert(
                $paraUpsert,
                ['clave_origen'],
                array_values(array_diff(array_keys($paraUpsert[0]), ['clave_origen', 'created_at']))
            );
        }
    }

    /** @param array<string, mixed> $datos @return array<string, mixed> */
    private function filaMovimiento(array $datos, int $cuentaId): array
    {
        return [
            'cuenta_corriente_id' => $cuentaId,
            'clave_origen' => $datos['clave_origen'],
            'dominio' => $datos['dominio'],
            'cuenta' => $datos['cuenta'],
            'fecha' => $datos['fecha'],
            'fecha_origen' => $datos['fecha_origen'],
            'periodo' => $datos['periodo'],
            'codigo' => $datos['codigo'],
            'numero' => $datos['numero'],
            'fecha_vencimiento' => $datos['fecha_vencimiento'],
            'fecha_vencimiento_origen' => $datos['fecha_vencimiento_origen'],
            'importe' => $datos['importe'],
            'debe' => $datos['debe'],
            'haber' => $datos['haber'],
            'importe_penalidad' => $datos['importe_penalidad'],
            'importe_abonado' => $datos['importe_abonado'],
            'iva' => $datos['iva'],
            'no_gravado' => $datos['no_gravado'],
            'descripcion' => $datos['descripcion'],
            'cuenta_inquilino_referencia' => $datos['cuenta_inquilino_referencia'],
            'liquidado_origen' => $datos['liquidado_origen'],
            'afecta_saldo' => $datos['afecta_saldo'],
            'archivo_origen_id' => $datos['archivo_origen_id'],
            'numero_linea' => $datos['numero_linea'],
            'hash_origen' => $datos['hash_origen'],
            'datos_origen' => json_encode($datos['datos_origen'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * @param null|array<string, mixed> $cuenta
     * @param null|array<string, mixed> $movimiento
     * @param array<string, mixed> $detalle
     * @param array<string, mixed> $estado
     * @param array<string, int|bool> $resultado
     */
    private function registrarIncidencia(
        ?array $cuenta,
        ?array $movimiento,
        string $dominio,
        string $numeroCuenta,
        string $tipo,
        string $motivo,
        bool $bloqueante,
        array $detalle,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): string {
        $identificador = (string) ($detalle['clave_origen'] ?? $numeroCuenta);
        $firma = hash('sha256', implode('|', [$dominio, $numeroCuenta, $tipo, $motivo, $identificador]));
        $existente = $estado['incidencias'][$firma] ?? null;
        $fila = [
            'cuenta_corriente_id' => $cuenta['id'] ?? null,
            'movimiento_id' => $movimiento['id'] ?? null,
            'dominio' => $dominio,
            'cuenta' => $numeroCuenta,
            'tipo' => $tipo,
            'motivo' => $motivo,
            'bloqueante' => $bloqueante,
            'estado' => 'PENDIENTE',
            'firma' => $firma,
            'detalle' => json_encode($detalle, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'ultima_deteccion_at' => now(),
            'resuelto_at' => null,
        ];
        $clavesContador = $tipo === 'CONFLICTO'
            ? ['nuevo' => 'conflictos_nuevos', 'actualizado' => 'conflictos_actualizados', 'igual' => 'conflictos_sin_cambios']
            : ['nuevo' => 'advertencias_nuevas', 'actualizado' => 'advertencias_actualizadas', 'igual' => 'advertencias_sin_cambios'];

        if ($existente === null) {
            $fila['detectado_at'] = now();
            $fila['created_at'] = now();
            $fila['updated_at'] = now();
            if ($confirmar) {
                $fila['id'] = DB::table('cuentas_corrientes_incidencias')->insertGetId($fila);
            }
            $resultado[$clavesContador['nuevo']]++;
        } else {
            $fila['id'] = (int) $existente['id'];
            $fila['detectado_at'] = $existente['detectado_at'];
            $campos = ['cuenta_corriente_id', 'movimiento_id', 'tipo', 'motivo', 'bloqueante', 'estado', 'detalle'];
            if ($this->camposIguales($existente, $fila, $campos, true)) {
                $resultado[$clavesContador['igual']]++;
            } else {
                if ($confirmar) {
                    DB::table('cuentas_corrientes_incidencias')->where('firma', $firma)->update([
                        'cuenta_corriente_id' => $fila['cuenta_corriente_id'],
                        'movimiento_id' => $fila['movimiento_id'],
                        'tipo' => $tipo,
                        'motivo' => $motivo,
                        'bloqueante' => $bloqueante,
                        'estado' => 'PENDIENTE',
                        'detalle' => $fila['detalle'],
                        'ultima_deteccion_at' => now(),
                        'resuelto_at' => null,
                        'updated_at' => now(),
                    ]);
                }
                $resultado[$clavesContador['actualizado']]++;
            }
        }
        $fila['detalle'] = $detalle;
        $estado['incidencias'][$firma] = $fila;
        $this->registrarDetalle(array_merge($fila, ['detalle' => $detalle]));

        return $firma;
    }

    /** @param array<string, bool> $detectadas @param array<string, mixed> $estado @param array<string, int|bool> $resultado */
    private function resolverIncidenciasAusentes(
        array $detectadas,
        bool $confirmar,
        array &$estado,
        array &$resultado
    ): void {
        foreach ($estado['incidencias'] as $firma => $incidencia) {
            if (($incidencia['estado'] ?? null) !== 'PENDIENTE' || isset($detectadas[$firma])) {
                continue;
            }
            if ($confirmar) {
                DB::table('cuentas_corrientes_incidencias')->where('firma', $firma)->update([
                    'estado' => 'RESUELTO',
                    'resuelto_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $estado['incidencias'][$firma]['estado'] = 'RESUELTO';
            $resultado['incidencias_resueltas']++;
        }
    }

    /** @return iterable<object> */
    private function filasFuente(ConnectionInterface $origen, string $tabla, int $archivoId, ?int $limite): iterable
    {
        $schema = $this->schema();
        $sql = "SELECT *
            FROM {$schema}.{$tabla}
            WHERE archivo_id = {$archivoId}
            ORDER BY cuenta, fecha, codigo, numero, numero_linea";
        if ($limite !== null) {
            $sql .= ' LIMIT '.max(0, $limite);
        }

        return $origen->cursor($sql);
    }

    private function totalFuente(ConnectionInterface $origen, string $tabla, int $archivoId, ?int $limite): int
    {
        $schema = $this->schema();
        $fila = $origen->selectOne(
            "SELECT count(*) AS total FROM {$schema}.{$tabla} WHERE archivo_id = ?",
            [$archivoId]
        );
        $total = (int) ($fila->total ?? 0);

        return $limite === null ? $total : min($total, max(0, $limite));
    }

    private function archivoFuenteMasReciente(ConnectionInterface $origen, string $tabla): int
    {
        $schema = $this->schema();
        $fila = $origen->selectOne(
            "SELECT archivo_id
             FROM {$schema}.{$tabla}
             ORDER BY archivo_id DESC
             LIMIT 1"
        );
        $archivoId = (int) ($fila->archivo_id ?? 0);
        if ($archivoId <= 0) {
            throw new RuntimeException("No hay una fotografía COBOL cargada en {$schema}.{$tabla}.");
        }

        return $archivoId;
    }

    private function informarAvance(?Closure $avance, string $tabla, int $procesados, int $total, bool $forzar = false): void
    {
        if ($avance !== null && ($forzar || $procesados === 1 || $procesados % 10000 === 0)) {
            $avance(strtoupper($tabla), $procesados, $total);
        }
    }

    /** @param array<string, mixed> $actual @param array<string, mixed> $esperado @param list<string> $campos */
    private function camposIguales(array $actual, array $esperado, array $campos, bool $json = false): bool
    {
        foreach ($campos as $campo) {
            $a = $actual[$campo] ?? null;
            $e = $esperado[$campo] ?? null;
            if (($json && $campo === 'detalle') || $campo === 'datos_origen') {
                if ($this->decodificarJson($a) != $this->decodificarJson($e)) {
                    return false;
                }
            } elseif ($a != $e) {
                return false;
            }
        }

        return true;
    }

    private function decodificarJson(mixed $valor): mixed
    {
        if (is_string($valor)) {
            $decodificado = json_decode($valor, true);

            return json_last_error() === JSON_ERROR_NONE ? $decodificado : $valor;
        }

        return $valor ?? [];
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
        foreach (['ctactepro', 'inqctacte'] as $tabla) {
            $existe = $origen->selectOne('select to_regclass(?) as tabla', [$this->schema().'.'.$tabla]);
            if (($existe->tabla ?? null) === null) {
                throw new RuntimeException('No existe '.$this->schema().'.'.$tabla.' en gei_exploracion.');
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
