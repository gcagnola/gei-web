<?php

namespace App\Http\Controllers;

use App\Models\CobolImpresion;
use App\Services\CobolImpresionPdfService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CobolImpresionListadoController extends Controller
{
    public function index(Request $request)
    {
        $fechaConsulta = $this->resolverFecha($request->query('fecha'));

        return view('cobol-impresiones.index', [
            'impresiones' => $this->impresionesDeFecha($fechaConsulta),
            'fecha' => $fechaConsulta->format('d/m/Y'),
            'fechaInput' => $fechaConsulta->format('Y-m-d'),
            'esHoy' => $fechaConsulta->isSameDay(now()),
        ]);
    }

    public function datos(Request $request): JsonResponse
    {
        $fechaConsulta = $this->resolverFecha($request->query('fecha'));

        $impresiones = $this->impresionesDeFecha($fechaConsulta)->map(fn (CobolImpresion $impresion) => [
            'id' => $impresion->id,
            'hora' => optional($impresion->recibido_en)->format('H:i:s'),
            'tipo' => $this->etiquetaTipo($impresion->tipo_documento),
            'tipo_documento' => $impresion->tipo_documento,
            'cuenta' => $impresion->cuenta,
            'cliente' => $impresion->nombre_cliente,
            'archivo' => $impresion->archivo_origen,
            'estado' => $impresion->estado,
            'raw_url' => route('archivo.impresiones-cobol.raw', $impresion),
            'generar_pdf_url' => route('archivo.impresiones-cobol.pdf.generar', $impresion),
            'pdf_url' => $impresion->pdf_path ? route('archivo.impresiones-cobol.pdf', $impresion) : null,
            'puede_generar_pdf' => in_array($impresion->tipo_documento, ['LIQUIDACION_DEUDA', 'RECIBO_LIQUIDACION'], true),
        ]);

        return response()->json([
            'ok' => true,
            'fecha' => $fechaConsulta->format('d/m/Y'),
            'fecha_input' => $fechaConsulta->format('Y-m-d'),
            'es_hoy' => $fechaConsulta->isSameDay(now()),
            'total' => $impresiones->count(),
            'impresiones' => $impresiones,
        ]);
    }

    public function raw(CobolImpresion $impresion): Response
    {
        if (! Storage::disk('local')->exists($impresion->archivo_raw)) {
            abort(404, 'No se encontró el archivo RAW.');
        }

        $contenido = Storage::disk('local')->get($impresion->archivo_raw);
        $texto = preg_replace('/[^\x09\x0A\x0C\x0D\x20-\x7E\xA0-\xFF]/', '', $contenido) ?? $contenido;

        return response($texto, 200, [
            'Content-Type' => 'text/plain; charset=ISO-8859-1',
            'Content-Disposition' => 'inline; filename="'.$impresion->archivo_origen.'.txt"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    public function generarPdf(CobolImpresion $impresion, CobolImpresionPdfService $service): JsonResponse
    {
        try {
            $service->generar($impresion);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'ok' => false,
                'error' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'pdf_url' => route('archivo.impresiones-cobol.pdf', $impresion->fresh()),
        ]);
    }

    public function pdf(CobolImpresion $impresion): Response
    {
        if (! $impresion->pdf_path || ! Storage::disk('local')->exists($impresion->pdf_path)) {
            abort(404, 'El PDF todavía no fue generado.');
        }

        $contenido = Storage::disk('local')->get($impresion->pdf_path);
        $nombre = pathinfo($impresion->archivo_origen, PATHINFO_FILENAME).'.pdf';

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nombre.'"',
            'Cache-Control' => 'no-store, no-cache, must-revalidate',
        ]);
    }

    private function impresionesDeFecha(CarbonImmutable $fecha)
    {
        return CobolImpresion::query()
            ->whereDate('recibido_en', $fecha->toDateString())
            ->orderByDesc('recibido_en')
            ->orderByDesc('id')
            ->get();
    }

    private function resolverFecha(?string $fecha): CarbonImmutable
    {
        if ($fecha === null || trim($fecha) === '') {
            return CarbonImmutable::today();
        }

        try {
            return CarbonImmutable::createFromFormat('Y-m-d', $fecha)->startOfDay();
        } catch (Throwable) {
            return CarbonImmutable::today();
        }
    }

    private function etiquetaTipo(?string $tipo): string
    {
        return match ($tipo) {
            'LIQUIDACION_DEUDA' => 'Liquidación de deuda',
            'RECIBO_LIQUIDACION' => 'Recibo de liquidación',
            null, '' => 'Desconocido',
            default => str_replace('_', ' ', ucfirst(strtolower($tipo))),
        };
    }
}
