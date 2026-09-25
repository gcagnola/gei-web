<?php

namespace App\Http\Controllers;

use App\Services\ComprobantesArcaService;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class ComprobanteArcaController extends Controller
{
    public function ver(
        string $periodo,
        string $archivo,
        ComprobantesArcaService $service,
    ): BinaryFileResponse {
        $ruta = $service->rutaFisica($periodo, $archivo);

        abort_if($ruta === null, 404);

        return response()->file($ruta, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.basename($ruta).'"',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
