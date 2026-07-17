<?php

namespace App\Http\Controllers;

use App\Mail\LiquidacionPropietarioMail;
use App\Models\Cliente;
use App\Models\LiquidacionCliente;
use App\Services\LiquidacionPdfService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpFoundation\Response;

class ClienteLiquidacionController extends Controller
{
    public function ver(
        Cliente $cliente,
        LiquidacionCliente $liquidacion,
        LiquidacionPdfService $pdfService
    ): Response {
        $this->validarPertenencia($cliente, $liquidacion);
        $this->abortSiFaltaPdf($liquidacion, $pdfService);

        return $pdfService->respuestaInline($liquidacion);
    }

    public function descargar(
        Cliente $cliente,
        LiquidacionCliente $liquidacion,
        LiquidacionPdfService $pdfService
    ): Response {
        $this->validarPertenencia($cliente, $liquidacion);
        $this->abortSiFaltaPdf($liquidacion, $pdfService);

        return $pdfService->respuestaDescarga($liquidacion);
    }

    public function enviar(
        Request $request,
        Cliente $cliente,
        LiquidacionCliente $liquidacion,
        LiquidacionPdfService $pdfService
    ): RedirectResponse {
        $this->validarPertenencia($cliente, $liquidacion);

        $datos = $request->validate([
            'destinatario' => ['required', 'email'],
        ]);

        $rutaRelativa = $pdfService->rutaRelativaExistente($liquidacion)
            ?? $pdfService->rutaRelativa($liquidacion);
        $nombreArchivo = $pdfService->nombreArchivo($liquidacion);

        if (! $pdfService->existe($liquidacion)) {
            $this->registrarEnvio(
                $request,
                $cliente,
                $liquidacion,
                $datos['destinatario'],
                $rutaRelativa,
                'error',
                'PDF no encontrado'
            );

            Log::warning('PDF de liquidacion no encontrado para envio', [
                'numero_de_liquidacion' => $liquidacion->numero_de_liquidacion,
                'punto_venta' => $liquidacion->punto_venta,
                'numero' => $liquidacion->numero,
                'ruta_relativa' => $rutaRelativa,
            ]);

            return back()->withErrors([
                'liquidacion' => 'No se pudo enviar: el PDF no fue encontrado.',
            ]);
        }

        try {
            Mail::to($datos['destinatario'])->send(
                new LiquidacionPropietarioMail(
                    $cliente,
                    $liquidacion,
                    $rutaRelativa,
                    $nombreArchivo
                )
            );

            $this->registrarEnvio(
                $request,
                $cliente,
                $liquidacion,
                $datos['destinatario'],
                $rutaRelativa,
                'enviado'
            );

            return back()->with('estado', 'La liquidación fue enviada correctamente.');
        } catch (\Throwable $exception) {
            $this->registrarEnvio(
                $request,
                $cliente,
                $liquidacion,
                $datos['destinatario'],
                $rutaRelativa,
                'error',
                mb_substr($exception->getMessage(), 0, 500)
            );

            Log::error('Error enviando liquidacion de propietario', [
                'numero_de_liquidacion' => $liquidacion->numero_de_liquidacion,
                'punto_venta' => $liquidacion->punto_venta,
                'numero' => $liquidacion->numero,
                'ruta_relativa' => $rutaRelativa,
                'error' => $exception->getMessage(),
            ]);

            return back()->withErrors([
                'liquidacion' => 'No se pudo enviar la liquidación. Revisá el log del sistema.',
            ]);
        }
    }

    private function validarPertenencia(
        Cliente $cliente,
        LiquidacionCliente $liquidacion
    ): void {
        abort_unless(
            (int) $cliente->id_prop !== 0
                && (int) $cliente->id_prop === (int) $liquidacion->nro_cuenta,
            404
        );
    }

    private function abortSiFaltaPdf(
        LiquidacionCliente $liquidacion,
        LiquidacionPdfService $pdfService
    ): void {
        if ($pdfService->existe($liquidacion)) {
            return;
        }

        Log::warning('PDF de liquidacion no encontrado', [
            'numero_de_liquidacion' => $liquidacion->numero_de_liquidacion,
            'punto_venta' => $liquidacion->punto_venta,
            'numero' => $liquidacion->numero,
            'ruta_relativa' => $pdfService->rutaRelativa($liquidacion),
        ]);

        abort(404, 'PDF no encontrado.');
    }

    private function registrarEnvio(
        Request $request,
        Cliente $cliente,
        LiquidacionCliente $liquidacion,
        string $destinatario,
        string $rutaRelativa,
        string $estado,
        ?string $mensajeError = null
    ): void {
        DB::table('web_envios_liquidaciones')->insert([
            'web_codigo_cliente' => $cliente->codigo_cliente,
            'web_numero_de_liquidacion' => $liquidacion->numero_de_liquidacion,
            'web_punto_venta' => $liquidacion->punto_venta,
            'web_numero' => $liquidacion->numero,
            'web_destinatario' => $destinatario,
            'web_intentado_en' => now(),
            'web_usuario_id' => $request->user()?->getAuthIdentifier(),
            'web_estado' => $estado,
            'web_mensaje_error' => $mensajeError,
            'web_ruta_relativa_pdf' => $rutaRelativa,
        ]);
    }
}
