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

        try {
            $r = $service->importar();
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

        $pdf = $r['pdfs'] ?? [];
        $mensaje .= sprintf(
            ' Nuevas: %s facturas, %s lotes; actualizadas: %s facturas, %s lotes. PDFs: %s copiados, %s existentes, %s pendientes, %s conflictos, %s sin referencia.',
            number_format((int) ($r['facturas_nuevas'] ?? 0), 0, ',', '.'),
            number_format((int) ($r['lotes_nuevos'] ?? 0), 0, ',', '.'),
            number_format((int) ($r['facturas_actualizadas'] ?? 0), 0, ',', '.'),
            number_format((int) ($r['lotes_actualizados'] ?? 0), 0, ',', '.'),
            number_format((int) ($pdf['copiados'] ?? 0), 0, ',', '.'),
            number_format((int) ($pdf['ya_existentes'] ?? 0), 0, ',', '.'),
            number_format((int) ($pdf['pendientes_total'] ?? 0), 0, ',', '.'),
            number_format((int) ($pdf['conflictos'] ?? 0), 0, ',', '.'),
            number_format((int) ($pdf['sin_referencia'] ?? 0), 0, ',', '.')
        );

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $mensaje, 'result' => $r]);
        }

        return back()->with('ok', $mensaje);
    }
}
