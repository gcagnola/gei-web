<?php

namespace App\Http\Controllers;

use App\Models\TransferenciaInformada;
use App\Models\TransferenciaInformadaArchivo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransferenciaInformadaController extends Controller
{
    public function index(Request $request): View
    {
        $estado = strtoupper(trim((string) $request->query('estado', 'PENDIENTE')));
        $buscar = trim((string) $request->query('buscar', ''));

        $query = TransferenciaInformada::query()
            ->with(['cliente', 'clienteCuenta', 'archivos', 'verificadaPor'])
            ->orderByDesc('created_at');

        if ($estado !== 'TODAS') {
            $query->where('estado', $estado);
        }

        if ($buscar !== '') {
            $query->whereHas('cliente', function ($q) use ($buscar): void {
                $q->where('nombre', 'ilike', '%'.$buscar.'%')
                    ->orWhere('cuit', 'ilike', '%'.$buscar.'%')
                    ->orWhere('numero_documento', 'ilike', '%'.$buscar.'%');
            });
        }

        $transferencias = $query->paginate(30)->withQueryString();

        return view('transferencias-informadas.index', compact(
            'transferencias',
            'estado',
            'buscar'
        ));
    }

    public function show(TransferenciaInformada $transferencia): View
    {
        $transferencia->load(['cliente', 'clienteCuenta', 'archivos', 'verificadaPor']);

        return view('transferencias-informadas.show', compact('transferencia'));
    }

    public function verificar(TransferenciaInformada $transferencia): RedirectResponse
    {
        if (! $transferencia->estaPendiente()) {
            return back()->withErrors(['estado' => 'La transferencia ya no está pendiente.']);
        }

        $transferencia->update([
            'estado' => TransferenciaInformada::ESTADO_VERIFICADA,
            'verificada_por_usuario_id' => auth()->id(),
            'verificada_at' => now(),
            'rechazo_motivo' => null,
        ]);

        return back()->with('estado', 'La transferencia fue marcada como verificada. No se generó ningún movimiento financiero.');
    }

    public function rechazar(Request $request, TransferenciaInformada $transferencia): RedirectResponse
    {
        if (! $transferencia->estaPendiente()) {
            return back()->withErrors(['estado' => 'La transferencia ya no está pendiente.']);
        }

        $datos = $request->validate([
            'rechazo_motivo' => ['required', 'string', 'max:1000'],
        ]);

        $transferencia->update([
            'estado' => TransferenciaInformada::ESTADO_RECHAZADA,
            'verificada_por_usuario_id' => auth()->id(),
            'verificada_at' => now(),
            'rechazo_motivo' => trim($datos['rechazo_motivo']),
        ]);

        return back()->with('estado', 'La transferencia fue rechazada.');
    }

    public function archivo(TransferenciaInformada $transferencia, TransferenciaInformadaArchivo $archivo): StreamedResponse
    {
        abort_unless($archivo->transferencia_informada_id === $transferencia->id, 404);
        abort_unless(Storage::disk('local')->exists($archivo->archivo), 404);

        return Storage::disk('local')->download($archivo->archivo, $archivo->nombre_original);
    }
}
