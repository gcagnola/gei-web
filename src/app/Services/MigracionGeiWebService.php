<?php

namespace App\Services;

use Closure;
use DateTimeInterface;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class MigracionGeiWebService
{
    private const ARCHIVO_ESTADO = 'actualizacion_gei_web.json';
    private const LOCK = 'gei:transformacion-cobol';

    public function __construct(
        private readonly MigracionExploracionService $exploracion,
        private readonly MigracionClientesCobolService $clientes,
        private readonly MigracionInmueblesCobolService $inmuebles,
        private readonly MigracionContratosCobolService $contratos,
        private readonly MigracionCuentasCorrientesCobolService $cuentasCorrientes,
    ) {
    }

    /** @return array<string, mixed> */
    public function estado(string $periodo): array
    {
        $this->validarPeriodo($periodo);

        $staging = $this->exploracion->estado($periodo);
        $guardado = $this->leerEstado($periodo);

        $base = [
            'periodo' => $periodo,
            'staging' => $staging,
            'estado' => 'SIN_ANALIZAR',
            'etiqueta' => 'Sin analizar',
            'mensaje' => 'Todavía no se analizó la actualización de GeI-Web.',
            'ultimo_analisis' => null,
            'ultima_aplicacion' => null,
            'historial' => [],
            'puede_analizar' => ($staging['estado'] ?? null) === 'OK',
            'puede_aplicar' => false,
        ];

        if (($staging['estado'] ?? null) !== 'OK') {
            return array_merge($base, [
                'estado' => 'NO_DISPONIBLE',
                'etiqueta' => 'No disponible',
                'mensaje' => 'Primero debe completarse correctamente la migración del período a gei_exploracion.',
            ]);
        }

        if ($guardado === null) {
            return $base;
        }

        if (($guardado['hash_staging'] ?? null) !== ($staging['hash'] ?? null)) {
            return array_merge($base, $guardado, [
                'staging' => $staging,
                'estado' => 'DESACTUALIZADO',
                'etiqueta' => 'Requiere nuevo análisis',
                'mensaje' => 'Los archivos o la migración de staging cambiaron desde el último análisis.',
                'puede_analizar' => true,
                'puede_aplicar' => false,
            ]);
        }

        $estado = (string) ($guardado['estado'] ?? 'SIN_ANALIZAR');

        return array_merge($base, $guardado, [
            'staging' => $staging,
            'puede_analizar' => true,
            'puede_aplicar' => $estado === 'ANALIZADO',
        ]);
    }

    /** @return array<string, mixed> */
    public function analizar(string $periodo, ?int $usuarioId): array
    {
        $staging = $this->stagingValido($periodo);
        $lock = $this->obtenerLock();

        try {
            $inicio = microtime(true);
            $etapas = $this->ejecutarTodas(false);
            $duracion = max(0, (int) round(microtime(true) - $inicio));
            $anterior = $this->leerEstado($periodo) ?? [];
            $ultimaAplicacion = $anterior['ultima_aplicacion'] ?? null;
            $hayCambios = $this->hayCambiosPersistibles($etapas);

            $verificado = is_array($ultimaAplicacion)
                && ($ultimaAplicacion['hash_staging'] ?? null) === ($staging['hash'] ?? null)
                && ! $hayCambios;

            $nuevo = [
                'periodo' => $periodo,
                'hash_staging' => $staging['hash'] ?? null,
                'estado' => $verificado ? 'VERIFICADO' : 'ANALIZADO',
                'etiqueta' => $verificado ? 'Verificado' : 'Análisis listo',
                'mensaje' => $verificado
                    ? 'La verificación posterior no detectó cambios persistibles pendientes.'
                    : 'El análisis terminó correctamente. No se escribió ningún dato en GeI-Web.',
                'ultimo_analisis' => [
                    'fecha' => now()->toIso8601String(),
                    'usuario_id' => $usuarioId,
                    'duracion_segundos' => $duracion,
                    'hash_staging' => $staging['hash'] ?? null,
                    'hay_cambios_persistibles' => $hayCambios,
                    'etapas' => $etapas,
                ],
                'ultima_aplicacion' => $ultimaAplicacion,
                'historial' => $this->agregarHistorial(
                    $anterior['historial'] ?? [],
                    [
                        'tipo' => $verificado ? 'VERIFICACION' : 'ANALISIS',
                        'fecha' => now()->toIso8601String(),
                        'usuario_id' => $usuarioId,
                        'duracion_segundos' => $duracion,
                        'resultado' => 'OK',
                    ]
                ),
            ];

            $this->guardarEstado($periodo, $nuevo);

            return $this->estado($periodo);
        } catch (Throwable $error) {
            $this->guardarError($periodo, $staging, 'ANALISIS', $usuarioId, $error);
            throw $error;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    public function aplicar(string $periodo, ?int $usuarioId): array
    {
        $staging = $this->stagingValido($periodo);
        $estado = $this->estado($periodo);

        if (($estado['estado'] ?? null) !== 'ANALIZADO') {
            throw new RuntimeException(
                'La actualización no puede aplicarse sin un análisis vigente. Ejecutá "Analizar actualización" primero.'
            );
        }

        $analisis = $estado['ultimo_analisis'] ?? null;
        if (
            ! is_array($analisis)
            || ($analisis['hash_staging'] ?? null) !== ($staging['hash'] ?? null)
        ) {
            throw new RuntimeException(
                'El análisis ya no corresponde al staging actual. Volvé a analizar antes de aplicar.'
            );
        }

        $lock = $this->obtenerLock();
        $anterior = $this->leerEstado($periodo) ?? [];

        $aplicando = array_merge($anterior, [
            'periodo' => $periodo,
            'hash_staging' => $staging['hash'] ?? null,
            'estado' => 'APLICANDO',
            'etiqueta' => 'Aplicando',
            'mensaje' => 'La actualización de GeI-Web está en ejecución.',
        ]);
        $this->guardarEstado($periodo, $aplicando);

        try {
            $inicio = microtime(true);

            $etapas = DB::transaction(
                fn (): array => $this->ejecutarTodas(true),
                1
            );

            $duracion = max(0, (int) round(microtime(true) - $inicio));

            $nuevo = [
                'periodo' => $periodo,
                'hash_staging' => $staging['hash'] ?? null,
                'estado' => 'APLICADO',
                'etiqueta' => 'Aplicado',
                'mensaje' => 'La actualización se aplicó correctamente en GeI-Web.',
                'ultimo_analisis' => $anterior['ultimo_analisis'] ?? null,
                'ultima_aplicacion' => [
                    'fecha' => now()->toIso8601String(),
                    'usuario_id' => $usuarioId,
                    'duracion_segundos' => $duracion,
                    'hash_staging' => $staging['hash'] ?? null,
                    'etapas' => $etapas,
                ],
                'historial' => $this->agregarHistorial(
                    $anterior['historial'] ?? [],
                    [
                        'tipo' => 'APLICACION',
                        'fecha' => now()->toIso8601String(),
                        'usuario_id' => $usuarioId,
                        'duracion_segundos' => $duracion,
                        'resultado' => 'OK',
                    ]
                ),
            ];

            $this->guardarEstado($periodo, $nuevo);

            return $this->estado($periodo);
        } catch (Throwable $error) {
            $this->guardarError($periodo, $staging, 'APLICACION', $usuarioId, $error);
            throw $error;
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, mixed> */
    private function ejecutarTodas(bool $confirmar): array
    {
        return [
            'clientes' => $this->ejecutarClientes($confirmar),
            'inmuebles' => $this->ejecutarInmuebles($confirmar),
            'contratos' => $this->ejecutarContratos($confirmar),
            'cuentas_corrientes' => $this->ejecutarCuentasCorrientes($confirmar),
        ];
    }

    /** @return array<string, mixed> */
    private function ejecutarClientes(bool $confirmar): array
    {
        return $this->ejecutarConIncidencias(
            fn (Closure $incidencia): array => $this->clientes->ejecutar(
                $confirmar,
                null,
                null,
                $incidencia
            )
        );
    }

    /** @return array<string, mixed> */
    private function ejecutarInmuebles(bool $confirmar): array
    {
        $incidencias = 0;
        $muestra = [];
        $actualizaciones = 0;
        $muestraActualizaciones = [];

        $onIncidencia = function (array $fila) use (&$incidencias, &$muestra): void {
            $incidencias++;
            if (count($muestra) < 20) {
                $muestra[] = $this->normalizarParaJson($fila);
            }
        };

        $onActualizacion = function (array $fila) use (&$actualizaciones, &$muestraActualizaciones): void {
            $actualizaciones++;
            if (count($muestraActualizaciones) < 20) {
                $muestraActualizaciones[] = $this->normalizarParaJson($fila);
            }
        };

        $resultado = $this->inmuebles->ejecutar(
            $confirmar,
            null,
            null,
            $onIncidencia,
            $onActualizacion
        );

        return [
            'resultado' => $this->normalizarParaJson($resultado),
            'incidencias_total' => $incidencias,
            'incidencias_muestra' => $muestra,
            'actualizaciones_maestras_total' => $actualizaciones,
            'actualizaciones_maestras_muestra' => $muestraActualizaciones,
        ];
    }

    /** @return array<string, mixed> */
    private function ejecutarContratos(bool $confirmar): array
    {
        return $this->ejecutarConIncidencias(
            fn (Closure $incidencia): array => $this->contratos->ejecutar(
                $confirmar,
                null,
                null,
                $incidencia
            )
        );
    }

    /** @return array<string, mixed> */
    private function ejecutarCuentasCorrientes(bool $confirmar): array
    {
        return $this->ejecutarConIncidencias(
            fn (Closure $incidencia): array => $this->cuentasCorrientes->ejecutar(
                $confirmar,
                null,
                null,
                $incidencia
            )
        );
    }

    /**
     * @param Closure(Closure):array<string, int|bool> $ejecutor
     * @return array<string, mixed>
     */
    private function ejecutarConIncidencias(Closure $ejecutor): array
    {
        $cantidad = 0;
        $muestra = [];

        $incidencia = function (array $fila) use (&$cantidad, &$muestra): void {
            $cantidad++;
            if (count($muestra) < 20) {
                $muestra[] = $this->normalizarParaJson($fila);
            }
        };

        $resultado = $ejecutor($incidencia);

        return [
            'resultado' => $this->normalizarParaJson($resultado),
            'incidencias_total' => $cantidad,
            'incidencias_muestra' => $muestra,
        ];
    }

    /** @param array<string, mixed> $etapas */
    private function hayCambiosPersistibles(array $etapas): bool
    {
        foreach ($etapas as $etapa) {
            $resultado = is_array($etapa) ? ($etapa['resultado'] ?? []) : [];
            if (! is_array($resultado)) {
                continue;
            }

            foreach ($resultado as $clave => $valor) {
                if (! is_int($valor) || $valor <= 0) {
                    continue;
                }

                if (
                    str_ends_with((string) $clave, '_creados')
                    || str_ends_with((string) $clave, '_actualizados')
                    || str_ends_with((string) $clave, '_unificados')
                    || str_ends_with((string) $clave, '_asignados')
                    || str_ends_with((string) $clave, '_resueltos')
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function stagingValido(string $periodo): array
    {
        $this->validarPeriodo($periodo);
        $staging = $this->exploracion->estado($periodo);

        if (($staging['estado'] ?? null) !== 'OK') {
            throw new RuntimeException(
                'El período todavía no está migrado correctamente a gei_exploracion.'
            );
        }

        if (empty($staging['hash'])) {
            throw new RuntimeException(
                'No se pudo verificar la huella del lote migrado a gei_exploracion.'
            );
        }

        return $staging;
    }

    private function obtenerLock()
    {
        $lock = Cache::store((string) config('gei.exploracion.lock_store', 'file'))
            ->lock(self::LOCK, 7200);

        if (! $lock->get()) {
            throw new RuntimeException(
                'Hay otra transformación o una unificación COBOL en curso. Esperá a que termine e intentá nuevamente.'
            );
        }

        return $lock;
    }

    private function validarPeriodo(string $periodo): void
    {
        if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            throw new RuntimeException('El período indicado no es válido.');
        }
    }

    private function rutaEstado(string $periodo): string
    {
        return "liquidaciones/periodos/{$periodo}/".self::ARCHIVO_ESTADO;
    }

    /** @return array<string, mixed>|null */
    private function leerEstado(string $periodo): ?array
    {
        $ruta = $this->rutaEstado($periodo);
        if (! Storage::exists($ruta)) {
            return null;
        }

        try {
            $valor = json_decode(Storage::get($ruta), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($valor) ? $valor : null;
    }

    /** @param array<string, mixed> $estado */
    private function guardarEstado(string $periodo, array $estado): void
    {
        $estado['actualizado_at'] = now()->toIso8601String();

        $ok = Storage::put(
            $this->rutaEstado($periodo),
            json_encode(
                $this->normalizarParaJson($estado),
                JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ).PHP_EOL
        );

        if (! $ok) {
            throw new RuntimeException('No se pudo guardar el estado de la actualización web.');
        }
    }

    /** @param array<string, mixed> $staging */
    private function guardarError(
        string $periodo,
        array $staging,
        string $fase,
        ?int $usuarioId,
        Throwable $error
    ): void {
        try {
            $anterior = $this->leerEstado($periodo) ?? [];
            $nuevo = array_merge($anterior, [
                'periodo' => $periodo,
                'hash_staging' => $staging['hash'] ?? null,
                'estado' => 'ERROR_'.$fase,
                'etiqueta' => 'Error',
                'mensaje' => $error->getMessage(),
                'historial' => $this->agregarHistorial(
                    $anterior['historial'] ?? [],
                    [
                        'tipo' => $fase,
                        'fecha' => now()->toIso8601String(),
                        'usuario_id' => $usuarioId,
                        'resultado' => 'ERROR',
                        'mensaje' => mb_substr($error->getMessage(), 0, 1000),
                    ]
                ),
            ]);
            $this->guardarEstado($periodo, $nuevo);
        } catch (Throwable) {
            // El error original es el que debe llegar al controlador.
        }
    }

    /** @param mixed $historial @param array<string, mixed> $entrada */
    private function agregarHistorial(mixed $historial, array $entrada): array
    {
        $lista = is_array($historial) ? array_values($historial) : [];
        $lista[] = $entrada;

        return array_slice($lista, -50);
    }

    private function normalizarParaJson(mixed $valor): mixed
    {
        if ($valor instanceof DateTimeInterface) {
            return $valor->format(DATE_ATOM);
        }

        if (is_array($valor)) {
            $salida = [];
            foreach ($valor as $clave => $item) {
                $salida[$clave] = $this->normalizarParaJson($item);
            }
            return $salida;
        }

        if (is_object($valor)) {
            return $this->normalizarParaJson((array) $valor);
        }

        if (is_resource($valor)) {
            return '[resource]';
        }

        return $valor;
    }
}
