<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class FlujoLiquidacionesPropietariosService
{
    private const ARCHIVO_ESTADO = 'actualizacion_liquidaciones_propietarios.json';

    public function __construct(
        private readonly LiquidacionesPropietariosService $liquidaciones,
        private readonly MigracionGeiWebService $geiWeb,
    ) {
    }

    /** @return array<string, mixed> */
    public function estado(string $periodo): array
    {
        $gei = $this->geiWeb->estado($periodo);
        $geiVerificado = ($gei['estado'] ?? null) === 'VERIFICADO';

        $base = [
            'periodo' => $periodo,
            'estado' => $geiVerificado ? 'SIN_ANALIZAR' : 'BLOQUEADO',
            'etiqueta' => $geiVerificado ? 'Sin analizar' : 'Esperando GeI-Web',
            'mensaje' => $geiVerificado
                ? 'Las liquidaciones de propietarios todavía no fueron analizadas.'
                : 'Primero debe quedar verificada la actualización de GeI-Web.',
            'puede_analizar' => $geiVerificado,
            'puede_aplicar' => false,
            'ultimo_analisis' => null,
            'ultima_aplicacion' => null,
            'historial' => [],
            'numero_inicial_sugerido' => null,
            'lote_hash' => null,
        ];

        if (! $geiVerificado) {
            return $base;
        }

        try {
            $hash = $this->liquidaciones->loteHashPeriodo($periodo);
            $numeroSugerido = $this->liquidaciones->numeroInicialSugerido($periodo);
        } catch (Throwable $error) {
            return array_merge($base, [
                'estado' => 'NO_DISPONIBLE',
                'etiqueta' => 'No disponible',
                'mensaje' => $error->getMessage(),
                'puede_analizar' => false,
            ]);
        }

        $guardado = $this->leerEstado($periodo);
        if ($guardado === null) {
            return array_merge($base, [
                'lote_hash' => $hash,
                'numero_inicial_sugerido' => $numeroSugerido,
            ]);
        }

        if (($guardado['lote_hash'] ?? null) !== $hash) {
            return array_merge($base, $guardado, [
                'estado' => 'DESACTUALIZADO',
                'etiqueta' => 'Requiere nuevo análisis',
                'mensaje' => 'Los archivos de liquidaciones cambiaron desde el último análisis.',
                'puede_analizar' => true,
                'puede_aplicar' => false,
                'lote_hash' => $hash,
                'numero_inicial_sugerido' => $numeroSugerido,
            ]);
        }

        $estado = (string) ($guardado['estado'] ?? 'SIN_ANALIZAR');

        return array_merge($base, $guardado, [
            'lote_hash' => $hash,
            'numero_inicial_sugerido' => $numeroSugerido,
            'puede_analizar' => true,
            'puede_aplicar' => $estado === 'ANALIZADO',
        ]);
    }

    /** @return array<string, mixed> */
    public function analizar(string $periodo, ?int $numeroInicial, ?int $usuarioId): array
    {
        $estadoActual = $this->estado($periodo);
        if (! ($estadoActual['puede_analizar'] ?? false)) {
            throw new RuntimeException((string) ($estadoActual['mensaje'] ?? 'No se puede analizar este período.'));
        }

        $inicio = microtime(true);
        $resultado = $this->liquidaciones->analizar($periodo, $numeroInicial);
        $duracion = max(0, (int) round(microtime(true) - $inicio));
        $anterior = $this->leerEstado($periodo) ?? [];
        $ultimaAplicacion = $anterior['ultima_aplicacion'] ?? null;
        $impuestos = is_array($resultado['impuestos_garantizados'] ?? null)
            ? $resultado['impuestos_garantizados']
            : [];
        $impuestosValidos = (bool) ($impuestos['validacion_ok'] ?? false);
        $sinCambios = (int) ($resultado['insertadas'] ?? 0) === 0
            && (int) ($resultado['actualizadas'] ?? 0) === 0
            && (int) ($impuestos['pdf_faltantes'] ?? 0) === 0;
        $verificado = $impuestosValidos
            && is_array($ultimaAplicacion)
            && ($ultimaAplicacion['lote_hash'] ?? null) === ($resultado['lote_hash'] ?? null)
            && $sinCambios;
        $requiereRevision = ! $impuestosValidos;

        $nuevo = [
            'periodo' => $periodo,
            'lote_hash' => $resultado['lote_hash'] ?? null,
            'estado' => $requiereRevision
                ? 'REVISION_REQUERIDA'
                : ($verificado ? 'VERIFICADO' : 'ANALIZADO'),
            'etiqueta' => $requiereRevision
                ? 'Revisar DAILOC'
                : ($verificado ? 'Verificado' : 'Análisis listo'),
            'mensaje' => $requiereRevision
                ? sprintf(
                    'DAILOC tiene %d diferencia(s) de validación. No se habilita el procesamiento hasta revisarlas.',
                    (int) ($impuestos['validaciones_con_diferencia'] ?? 0),
                )
                : ($verificado
                    ? 'La verificación no detectó liquidaciones nuevas/modificadas y confirmó los PDF de impuestos garantizados.'
                    : 'El análisis terminó correctamente. No se grabaron liquidaciones ni se generaron PDF.'),
            'numero_inicial_sugerido' => (int) ($resultado['numero_inicial_periodo'] ?? 0),
            'ultimo_analisis' => [
                'fecha' => now()->toIso8601String(),
                'usuario_id' => $usuarioId,
                'duracion_segundos' => $duracion,
                'numero_inicial_solicitado' => $numeroInicial,
                'resultado' => $resultado,
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
    }

    /** @return array<string, mixed> */
    public function aplicar(string $periodo, ?int $numeroInicial, ?int $usuarioId): array
    {
        $estado = $this->estado($periodo);
        if (($estado['estado'] ?? null) !== 'ANALIZADO') {
            throw new RuntimeException('Primero debe existir un análisis vigente de las liquidaciones.');
        }

        $analisis = $estado['ultimo_analisis'] ?? null;
        if (! is_array($analisis)) {
            throw new RuntimeException('No se encontró el análisis previo de las liquidaciones.');
        }

        $numeroAnalizado = $analisis['numero_inicial_solicitado'] ?? null;
        if ($numeroAnalizado !== $numeroInicial) {
            throw new RuntimeException(
                'El número inicial cambió desde el análisis. Volvé a analizar antes de aplicar.'
            );
        }

        $hashActual = $this->liquidaciones->loteHashPeriodo($periodo);
        if (($estado['lote_hash'] ?? null) !== $hashActual) {
            throw new RuntimeException('Los archivos cambiaron desde el análisis. Volvé a analizar antes de aplicar.');
        }

        $anterior = $this->leerEstado($periodo) ?? [];
        $inicio = microtime(true);

        try {
            $resultado = $this->liquidaciones->procesar($periodo, $numeroInicial);
            $duracion = max(0, (int) round(microtime(true) - $inicio));

            $nuevo = [
                'periodo' => $periodo,
                'lote_hash' => $hashActual,
                'estado' => 'APLICADO',
                'etiqueta' => 'Aplicado',
                'mensaje' => 'Las liquidaciones y sus PDF, incluido el detalle de impuestos garantizados, se generaron correctamente.',
                'numero_inicial_sugerido' => $this->liquidaciones->numeroInicialSugerido($periodo),
                'ultimo_analisis' => $anterior['ultimo_analisis'] ?? null,
                'ultima_aplicacion' => [
                    'fecha' => now()->toIso8601String(),
                    'usuario_id' => $usuarioId,
                    'duracion_segundos' => $duracion,
                    'lote_hash' => $hashActual,
                    'numero_inicial_solicitado' => $numeroInicial,
                    'resultado' => $this->normalizarParaJson($resultado),
                ],
                'historial' => $this->agregarHistorial(
                    $anterior['historial'] ?? [],
                    [
                        'tipo' => 'APLICACION_LIQUIDACIONES',
                        'fecha' => now()->toIso8601String(),
                        'usuario_id' => $usuarioId,
                        'duracion_segundos' => $duracion,
                        'resultado' => 'OK',
                    ]
                ),
            ];
            $this->guardarEstado($periodo, $nuevo);
        } catch (Throwable $error) {
            $duracion = max(0, (int) round(microtime(true) - $inicio));
            $nuevo = array_merge($anterior, [
                'periodo' => $periodo,
                'lote_hash' => $hashActual,
                'estado' => 'ERROR_APLICACION',
                'etiqueta' => 'Error',
                'mensaje' => $error->getMessage(),
                'historial' => $this->agregarHistorial(
                    $anterior['historial'] ?? [],
                    [
                        'tipo' => 'APLICACION_LIQUIDACIONES',
                        'fecha' => now()->toIso8601String(),
                        'usuario_id' => $usuarioId,
                        'duracion_segundos' => $duracion,
                        'resultado' => 'ERROR',
                        'mensaje' => mb_substr($error->getMessage(), 0, 1000),
                    ]
                ),
            ]);
            $this->guardarEstado($periodo, $nuevo);
            throw $error;
        }

        return $this->estado($periodo);
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
            throw new RuntimeException('No se pudo guardar el estado web de las liquidaciones.');
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
        return $valor;
    }
}
