<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CobolImpresion;
use App\Services\CobolImpresionParser;
use App\Services\CobolImpresionPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class CobolImpresionController extends Controller
{
    public function store(
        Request $request,
        CobolImpresionParser $parser,
        CobolImpresionPdfService $pdfService
    )
    {
        $tokenEsperado = (string) config('cobol.print_token', '');
        $tokenRecibido = (string) $request->header('X-Gei-Token', '');

        if ($tokenEsperado === '' || $tokenRecibido === '' || ! hash_equals($tokenEsperado, $tokenRecibido)) {
            return response()->json(['ok' => false, 'error' => 'No autorizado'], 401);
        }

        if (! $request->hasFile('archivo')) {
            return response()->json(['ok' => false, 'error' => 'No se recibió archivo'], 422);
        }

        $archivo = $request->file('archivo');

        if (! $archivo->isValid()) {
            return response()->json(['ok' => false, 'error' => 'Archivo inválido'], 422);
        }

        $contenido = file_get_contents($archivo->getRealPath());

        if ($contenido === false || $contenido === '') {
            return response()->json(['ok' => false, 'error' => 'Archivo vacío o ilegible'], 422);
        }

        $origen = Str::slug(trim((string) $request->header('X-Gei-Source', 'fedora-cobol')));
        if ($origen === '') {
            $origen = 'fedora-cobol';
        }

        $nombreOriginal = basename($archivo->getClientOriginalName());
        $nombreOriginal = preg_replace('/[^A-Za-z0-9._-]/', '_', $nombreOriginal) ?: 'impresion.raw';

        $ruta = 'cobol/impresiones/raw/'.$origen.'/'.$nombreOriginal;
        $hash = hash('sha256', $contenido);
        $bytes = strlen($contenido);

        $existente = CobolImpresion::query()
            ->where('origen', $origen)
            ->where('archivo_origen', $nombreOriginal)
            ->first();

        if ($existente !== null) {
            $pdfGenerado = $this->generarPdfSiCorresponde($existente, $pdfService);

            return response()->json([
                'ok' => true,
                'duplicado' => true,
                'id' => $existente->id,
                'archivo_guardado' => $existente->archivo_raw,
                'bytes' => $existente->bytes,
                'sha256' => $existente->sha256,
                'pdf_generado' => $pdfGenerado,
                'pdf_path' => $existente->fresh()->pdf_path,
            ]);
        }

        Storage::disk('local')->put($ruta, $contenido);
        $datos = $parser->parsear($contenido);

        try {
            $registro = CobolImpresion::create([
                'origen' => $origen,
                'archivo_origen' => $nombreOriginal,
                'archivo_raw' => $ruta,
                'sha256' => $hash,
                'bytes' => $bytes,
                'tipo_documento' => $datos['tipo_documento'],
                'cuenta' => $datos['cuenta'],
                'nombre_cliente' => $datos['nombre_cliente'],
                'fecha_documento' => $datos['fecha_documento'],
                'importe_total' => $datos['importe_total'],
                'estado' => $datos['estado'],
                'recibido_en' => now(),
            ]);
        } catch (Throwable $e) {
            Storage::disk('local')->delete($ruta);
            throw $e;
        }

        $pdfGenerado = $this->generarPdfSiCorresponde($registro, $pdfService);

        return response()->json([
            'ok' => true,
            'duplicado' => false,
            'id' => $registro->id,
            'tipo_documento' => $registro->tipo_documento,
            'cuenta' => $registro->cuenta,
            'importe_total' => $registro->importe_total,
            'pdf_generado' => $pdfGenerado,
            'pdf_path' => $registro->fresh()->pdf_path,
            'archivo_guardado' => $registro->archivo_raw,
            'bytes' => $registro->bytes,
            'sha256' => $registro->sha256,
        ]);
    }
    private function generarPdfSiCorresponde(
        CobolImpresion $impresion,
        CobolImpresionPdfService $pdfService
    ): bool {
        if ($impresion->pdf_path) {
            return true;
        }

        if (! in_array($impresion->tipo_documento, ['LIQUIDACION_DEUDA', 'RECIBO_LIQUIDACION'], true)) {
            return false;
        }

        try {
            $pdfService->generar($impresion);

            return true;
        } catch (Throwable $e) {
            // El RAW recibido sigue siendo válido aunque falle el render PDF.
            // La pantalla mantiene la acción manual como recuperación.
            report($e);

            return false;
        }
    }

}
