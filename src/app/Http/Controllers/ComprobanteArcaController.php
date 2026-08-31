<?php

namespace App\Http\Controllers;

use App\Services\ComprobantesArcaService;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ComprobanteArcaController extends Controller
{
    public function ver(
        string $periodo,
        string $archivo,
        ComprobantesArcaService $service,
    ): StreamedResponse {
        $ruta = $service->rutaRelativa($periodo, $archivo);

        abort_if($ruta === null, 404);
        abort_unless($service->archivoDisponible($periodo, $archivo), 404);

        return Storage::disk('arca_facturas')->response(
            $ruta,
            $archivo,
            ['Content-Type' => 'application/pdf'],
            'inline'
        );
    }
}
