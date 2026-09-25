<?php

namespace App\Http\Controllers;

use App\Services\KngDbfImportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

final class KngImportacionController extends Controller
{
    public function progreso(KngDbfImportService $service): JsonResponse
    {
        $response = response()->json($service->progreso());
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }

    public function importar(Request $request, KngDbfImportService $service): RedirectResponse|JsonResponse
    {
        @set_time_limit(0);

        $organizarPdfs = $request->boolean('organizar_pdfs');

        try {
            $r = $service->importar($organizarPdfs);
        } catch (Throwable $e) {
            report($e);

            $mensaje = 'No se pudo importar KNG: '.$e->getMessage();

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'message' => $mensaje], 500);
            }

            return back()->withErrors(['kng' => $mensaje]);
        }

        $mensaje = sprintf(
            'KNG importado: %s registros de FACTURAS y %s de LOTES.',
            number_format((int) $r['facturas'], 0, ',', '.'),
            number_format((int) $r['lotes'], 0, ',', '.')
        );

        if ($organizarPdfs) {
            $pdf = $r['pdfs'] ?? [];
            $mensaje .= sprintf(
                ' PDFs: %s movidos, %s ya ubicados, %s sin correspondencia, %s conflictos.',
                number_format((int) ($pdf['movidos'] ?? 0), 0, ',', '.'),
                number_format((int) ($pdf['ya_ubicados'] ?? 0), 0, ',', '.'),
                number_format((int) ($pdf['sin_correspondencia'] ?? 0), 0, ',', '.'),
                number_format((int) ($pdf['conflictos'] ?? 0), 0, ',', '.')
            );
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $mensaje, 'result' => $r]);
        }

        return back()->with('ok', $mensaje);
    }
}
