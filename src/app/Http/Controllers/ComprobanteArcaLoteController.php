<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ComprobanteArcaLoteController extends Controller
{
    private const PATRON = '/^([A-Z]{2})-(\d{4})-(\d{8})-(\d{11})\.pdf$/i';

    public function ver(int $lote, string $archivo): StreamedResponse
    {
        abort_if($lote <= 0, 404);
        abort_if($archivo !== basename($archivo), 404);
        abort_if(preg_match(self::PATRON, $archivo) !== 1, 404);

        $ruta = 'lote_'.$lote.'/'.$archivo;

        abort_unless(Storage::disk('arca_facturas')->exists($ruta), 404);
        abort_unless((int) Storage::disk('arca_facturas')->size($ruta) > 0, 404);

        return Storage::disk('arca_facturas')->response(
            $ruta,
            $archivo,
            ['Content-Type' => 'application/pdf'],
            'inline'
        );
    }
}
