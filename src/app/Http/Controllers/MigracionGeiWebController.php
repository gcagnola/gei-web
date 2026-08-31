<?php

namespace App\Http\Controllers;

use App\Services\FlujoLiquidacionesPropietariosService;
use App\Services\MigracionGeiWebService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class MigracionGeiWebController extends Controller
{
    public function __construct(
        private readonly MigracionGeiWebService $service,
        private readonly FlujoLiquidacionesPropietariosService $liquidaciones,
    ) {
    }

    public function show(string $periodo): View
    {
        return view('importaciones.actualizacion-gei', [
            'periodo' => $periodo,
            'etiquetaPeriodo' => $this->etiquetaPeriodo($periodo),
            'actualizacion' => $this->service->estado($periodo),
            'liquidacionesPropietarios' => $this->liquidaciones->estado($periodo),
        ]);
    }

    public function analizar(string $periodo): RedirectResponse
    {
        $this->prepararProcesoLargo();

        try {
            $estado = $this->service->analizar(
                $periodo,
                auth()->id() === null ? null : (int) auth()->id()
            );
        } catch (Throwable $error) {
            report($error);
            return redirect()
                ->route('archivo.importar.actualizar-gei', $periodo)
                ->withErrors(['actualizacion_gei' => 'El análisis no pudo completarse: '.$error->getMessage()]);
        }

        $duracion = (int) (($estado['ultimo_analisis']['duracion_segundos'] ?? 0));
        return redirect()
            ->route('archivo.importar.actualizar-gei', $periodo)
            ->with(
                'estado',
                'Análisis de '.$this->etiquetaPeriodo($periodo).' completado en '
                .$this->duracion($duracion).'. No se escribió ningún dato en GeI-Web.'
            );
    }

    public function aplicar(Request $request, string $periodo): RedirectResponse
    {
        $request->validate(
            ['confirmar' => ['accepted']],
            ['confirmar.accepted' => 'Debés confirmar explícitamente la actualización.']
        );
        $this->prepararProcesoLargo();

        try {
            $estado = $this->service->aplicar(
                $periodo,
                auth()->id() === null ? null : (int) auth()->id()
            );
        } catch (Throwable $error) {
            report($error);
            return redirect()
                ->route('archivo.importar.actualizar-gei', $periodo)
                ->withErrors([
                    'actualizacion_gei' => 'La actualización no pudo completarse. '
                        .'Los cambios de la operación fallida se revirtieron: '.$error->getMessage(),
                ]);
        }

        $duracion = (int) (($estado['ultima_aplicacion']['duracion_segundos'] ?? 0));
        return redirect()
            ->route('archivo.importar.actualizar-gei', $periodo)
            ->with(
                'estado',
                'GeI-Web actualizado para '.$this->etiquetaPeriodo($periodo).' en '
                .$this->duracion($duracion).'. Ejecutá la verificación antes de procesar liquidaciones.'
            );
    }

    public function analizarLiquidaciones(Request $request, string $periodo): RedirectResponse
    {
        $datos = $request->validate([
            'numero_inicial' => ['nullable', 'integer', 'min:1'],
        ]);
        $numeroInicial = isset($datos['numero_inicial']) && $datos['numero_inicial'] !== null
            ? (int) $datos['numero_inicial']
            : null;
        $this->prepararProcesoLargo();

        try {
            $estado = $this->liquidaciones->analizar(
                $periodo,
                $numeroInicial,
                auth()->id() === null ? null : (int) auth()->id()
            );
        } catch (Throwable $error) {
            report($error);
            return redirect()
                ->route('archivo.importar.actualizar-gei', $periodo)
                ->withErrors(['liquidaciones' => 'No se pudieron analizar las liquidaciones: '.$error->getMessage()]);
        }

        $duracion = (int) (($estado['ultimo_analisis']['duracion_segundos'] ?? 0));
        return redirect()
            ->route('archivo.importar.actualizar-gei', $periodo)
            ->with(
                'estado',
                'Liquidaciones de '.$this->etiquetaPeriodo($periodo).' analizadas en '
                .$this->duracion($duracion).'. No se grabaron liquidaciones ni se generaron PDF.'
            );
    }

    public function aplicarLiquidaciones(Request $request, string $periodo): RedirectResponse
    {
        $datos = $request->validate(
            [
                'confirmar_liquidaciones' => ['accepted'],
                'numero_inicial' => ['nullable', 'integer', 'min:1'],
            ],
            ['confirmar_liquidaciones.accepted' => 'Debés confirmar explícitamente el procesamiento de liquidaciones.']
        );
        $numeroInicial = isset($datos['numero_inicial']) && $datos['numero_inicial'] !== null
            ? (int) $datos['numero_inicial']
            : null;
        $this->prepararProcesoLargo();

        try {
            $estado = $this->liquidaciones->aplicar(
                $periodo,
                $numeroInicial,
                auth()->id() === null ? null : (int) auth()->id()
            );
        } catch (Throwable $error) {
            report($error);
            return redirect()
                ->route('archivo.importar.actualizar-gei', $periodo)
                ->withErrors([
                    'liquidaciones' => 'El procesamiento de liquidaciones no pudo completarse: '.$error->getMessage(),
                ]);
        }

        $resultado = $estado['ultima_aplicacion']['resultado'] ?? [];
        return redirect()
            ->route('archivo.importar.actualizar-gei', $periodo)
            ->with(
                'estado',
                sprintf(
                    'Liquidaciones %s procesadas: %d insertadas, %d actualizadas, %d omitidas, %d PDF de propietarios y %d PDF de impuestos garantizados.',
                    $this->etiquetaPeriodo($periodo),
                    (int) ($resultado['insertadas'] ?? 0),
                    (int) ($resultado['actualizadas'] ?? 0),
                    (int) ($resultado['omitidas'] ?? 0),
                    (int) ($resultado['pdf_generados'] ?? 0),
                    (int) (($resultado['impuestos_garantizados']['pdf_generados'] ?? 0)),
                )
            );
    }

    private function prepararProcesoLargo(): void
    {
        @set_time_limit(0);
        ignore_user_abort(true);
    }

    private function etiquetaPeriodo(string $periodo): string
    {
        $meses = [
            '01' => 'Enero', '02' => 'Febrero', '03' => 'Marzo', '04' => 'Abril',
            '05' => 'Mayo', '06' => 'Junio', '07' => 'Julio', '08' => 'Agosto',
            '09' => 'Septiembre', '10' => 'Octubre', '11' => 'Noviembre', '12' => 'Diciembre',
        ];
        $mes = substr($periodo, 4, 2);
        $anio = substr($periodo, 0, 4);
        return ($meses[$mes] ?? $mes).'/'.$anio;
    }

    private function duracion(int $segundos): string
    {
        if ($segundos < 60) {
            return $segundos.' s';
        }
        $minutos = intdiv($segundos, 60);
        $resto = $segundos % 60;
        return $minutos.' min '.str_pad((string) $resto, 2, '0', STR_PAD_LEFT).' s';
    }
}
