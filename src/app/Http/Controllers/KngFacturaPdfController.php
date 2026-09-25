<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class KngFacturaPdfController extends Controller
{
    public function ver(int $lote, string $archivo): BinaryFileResponse
    {
        abort_unless(
            preg_match('/^[A-Z]{2}-\d{4}-\d{8}-\d{11}\.pdf$/i', $archivo) === 1
            && $archivo === basename($archivo),
            404
        );

        $root = rtrim((string) config('gei.kng.root', '/archivo-kng'), DIRECTORY_SEPARATOR);
        $dir = trim((string) config('gei.kng.facturas_dir', 'Facturas'), DIRECTORY_SEPARATOR);
        $base = $root.DIRECTORY_SEPARATOR.$dir;

        $candidatas = [
            $base.DIRECTORY_SEPARATOR.'lote_'.$lote.DIRECTORY_SEPARATOR.$archivo,
            $base.DIRECTORY_SEPARATOR.$archivo,
        ];

        foreach ($candidatas as $ruta) {
            if (is_file($ruta) && is_readable($ruta)) {
                return response()->file($ruta, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="'.$archivo.'"',
                    'Cache-Control' => 'private, max-age=300',
                ]);
            }
        }

        abort(404, 'No se encontró el PDF de KNG.');
    }
}
