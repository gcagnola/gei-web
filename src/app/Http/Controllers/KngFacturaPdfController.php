<?php

namespace App\Http\Controllers;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

final class KngFacturaPdfController extends Controller
{
    public function ver(int $lote, string $archivo): BinaryFileResponse
    {
        abort_if($lote <= 0, 404);
        abort_if($archivo !== basename($archivo), 404);
        abort_if(
            preg_match('/^[A-Z]{2}-\d{4}-\d{8}-\d{11}\.pdf$/i', $archivo) !== 1,
            404
        );

        $root = rtrim((string) config('kng.facturas_root'), '/');
        abort_if($root === '', 404);

        $ruta = $root.'/lote_'.$lote.'/'.$archivo;

        abort_unless(is_file($ruta) && is_readable($ruta), 404);

        $realRoot = realpath($root);
        $realFile = realpath($ruta);

        abort_if(
            $realRoot === false
            || $realFile === false
            || ! str_starts_with($realFile, $realRoot.DIRECTORY_SEPARATOR),
            404
        );

        return response()->file($realFile, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.basename($realFile).'"',
        ]);
    }
}
