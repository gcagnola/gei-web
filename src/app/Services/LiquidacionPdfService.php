<?php

namespace App\Services;

use App\Models\LiquidacionCliente;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LiquidacionPdfService
{
    public function nombreArchivo(LiquidacionCliente $liquidacion): string
    {
        return sprintf(
            'l%04d-%08d.pdf',
            (int) $liquidacion->punto_venta,
            (int) $liquidacion->numero
        );
    }

    public function rutaRelativa(LiquidacionCliente $liquidacion): string
    {
        return sprintf(
            '%s/%s/%s',
            $liquidacion->fecha->format('Y'),
            $liquidacion->fecha->format('m'),
            $this->nombreArchivo($liquidacion)
        );
    }

    public function existe(LiquidacionCliente $liquidacion): bool
    {
        return $this->rutaRelativaExistente($liquidacion) !== null;
    }

    public function rutaRelativaExistente(
        LiquidacionCliente $liquidacion
    ): ?string {
        foreach ($this->rutasRelativasCandidatas($liquidacion) as $rutaRelativa) {
            if (Storage::disk('liquidaciones')->exists($rutaRelativa)) {
                return $rutaRelativa;
            }
        }

        return null;
    }

    public function respuestaInline(LiquidacionCliente $liquidacion): StreamedResponse
    {
        return $this->respuestaPdf($liquidacion, 'inline');
    }

    public function respuestaDescarga(LiquidacionCliente $liquidacion): StreamedResponse
    {
        return $this->respuestaPdf($liquidacion, 'attachment');
    }

    private function respuestaPdf(
        LiquidacionCliente $liquidacion,
        string $disposicion
    ): StreamedResponse {
        $rutaRelativa = $this->rutaRelativaExistente($liquidacion)
            ?? $this->rutaRelativa($liquidacion);
        $nombreArchivo = $this->nombreArchivo($liquidacion);

        return response()->stream(function () use ($rutaRelativa): void {
            $stream = Storage::disk('liquidaciones')->readStream($rutaRelativa);

            if (is_resource($stream)) {
                fpassthru($stream);
                fclose($stream);
            }
        }, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => sprintf(
                '%s; filename="%s"',
                $disposicion,
                $nombreArchivo
            ),
        ]);
    }

    private function rutasRelativasCandidatas(
        LiquidacionCliente $liquidacion
    ): array {
        $nombreArchivo = $this->nombreArchivo($liquidacion);

        return [
            $this->rutaRelativa($liquidacion),
            sprintf(
                '%s%s/%s',
                $liquidacion->fecha->format('Y'),
                $liquidacion->fecha->format('m'),
                $nombreArchivo
            ),
        ];
    }
}
