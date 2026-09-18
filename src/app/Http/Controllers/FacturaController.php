<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FacturaController extends Controller
{
    public function verPdf(int $factura): StreamedResponse|Response
    {
        $registro = DB::table('facturas')
            ->where('id_factura', $factura)
            ->first(['id_factura', 'pdf_ruta']);

        abort_if(! $registro, 404);
        abort_if(empty($registro->pdf_ruta), 404);

        $ruta = (string) $registro->pdf_ruta;
        abort_unless(Storage::disk('arca_facturas')->exists($ruta), 404);

        return Storage::disk('arca_facturas')->response(
            $ruta,
            basename($ruta),
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="'.basename($ruta).'"',
            ]
        );
    }
}
