<?php

namespace App\Http\Controllers;

use App\Models\CobolImpresion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class PortalReciboController extends Controller
{
    public function index(): View
    {
        $portalUsuario = Auth::guard('portal')->user();
        $cliente = $portalUsuario->cliente()->with(['roles', 'cuentas'])->firstOrFail();
        $cuentas = $this->cuentasNormalizadas($cliente->cuentas->pluck('cuenta')->all());

        $recibos = CobolImpresion::query()
            ->where('tipo_documento', 'RECIBO_LIQUIDACION')
            ->whereNotNull('pdf_path')
            ->when(
                $cuentas === [],
                fn ($query) => $query->whereRaw('1 = 0'),
                function ($query) use ($cuentas): void {
                    $placeholders = implode(',', array_fill(0, count($cuentas), '?'));
                    $query->whereRaw(
                        "regexp_replace(coalesce(cuenta, ''), '[^0-9]', '', 'g') in ({$placeholders})",
                        $cuentas
                    );
                }
            )
            ->orderByDesc('fecha_documento')
            ->orderByDesc('recibido_en')
            ->orderByDesc('id')
            ->paginate(25);

        return view('portal.recibos.index', compact('portalUsuario', 'cliente', 'recibos'));
    }

    public function pdf(Request $request, CobolImpresion $impresion): Response
    {
        $portalUsuario = Auth::guard('portal')->user();
        $cliente = $portalUsuario->cliente()->with('cuentas')->firstOrFail();

        abort_unless($impresion->tipo_documento === 'RECIBO_LIQUIDACION', 404);
        abort_unless(filled($impresion->pdf_path), 404);

        $cuentas = $this->cuentasNormalizadas($cliente->cuentas->pluck('cuenta')->all());
        $cuentaImpresion = $this->normalizarCuenta((string) $impresion->cuenta);

        abort_unless($cuentaImpresion !== '' && in_array($cuentaImpresion, $cuentas, true), 403);
        abort_unless(Storage::disk('local')->exists((string) $impresion->pdf_path), 404);

        $contenido = Storage::disk('local')->get((string) $impresion->pdf_path);
        $nombre = 'recibo-'.$impresion->id.'.pdf';

        return response($contenido, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$nombre.'"',
            'Cache-Control' => 'private, no-store, no-cache, must-revalidate',
        ]);
    }

    /** @param array<int, mixed> $cuentas */
    private function cuentasNormalizadas(array $cuentas): array
    {
        return collect($cuentas)
            ->map(fn ($cuenta): string => $this->normalizarCuenta((string) $cuenta))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    private function normalizarCuenta(string $cuenta): string
    {
        return preg_replace('/\D+/', '', $cuenta) ?? '';
    }
}
