<?php

namespace App\Http\Controllers;

use App\Models\TransferenciaInformada;
use App\Models\TransferenciaInformadaArchivo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortalTransferenciaController extends Controller
{
    public function index(): View
    {
        $portalUsuario = Auth::guard('portal')->user();
        $cliente = $portalUsuario->cliente()->with('cuentas')->firstOrFail();

        $transferencias = TransferenciaInformada::query()
            ->with(['clienteCuenta', 'archivos'])
            ->where('cliente_id', $cliente->id)
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('portal.transferencias.index', compact(
            'portalUsuario',
            'cliente',
            'transferencias'
        ));
    }

    public function create(): View
    {
        $portalUsuario = Auth::guard('portal')->user();
        $cliente = $portalUsuario->cliente()->with(['cuentas' => function ($query): void {
            $query->where(function ($q): void {
                $q->whereNull('activo')->orWhere('activo', true);
            })->orderBy('rol')->orderBy('cuenta');
        }])->firstOrFail();

        return view('portal.transferencias.create', compact('portalUsuario', 'cliente'));
    }

    public function store(Request $request): RedirectResponse
    {
        $portalUsuario = Auth::guard('portal')->user();
        $cliente = $portalUsuario->cliente;

        $datos = $request->validate([
            'cliente_cuenta_id' => ['nullable', 'integer'],
            'fecha_transferencia' => ['required', 'date', 'before_or_equal:today'],
            'importe' => ['required', 'numeric', 'gt:0', 'max:999999999999.99'],
            'referencia' => ['nullable', 'string', 'max:120'],
            'observaciones' => ['nullable', 'string', 'max:2000'],
            'comprobante' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240'],
        ]);

        $clienteCuentaId = null;
        if (filled($datos['cliente_cuenta_id'] ?? null)) {
            $clienteCuentaId = $cliente->cuentas()
                ->whereKey((int) $datos['cliente_cuenta_id'])
                ->value('id');

            if (! $clienteCuentaId) {
                return back()
                    ->withErrors(['cliente_cuenta_id' => 'La cuenta seleccionada no pertenece al cliente.'])
                    ->withInput();
            }
        }

        $archivo = $request->file('comprobante');
        $ruta = null;

        try {
            DB::beginTransaction();

            $transferencia = TransferenciaInformada::create([
                'cliente_id' => $cliente->id,
                'cliente_cuenta_id' => $clienteCuentaId,
                'fecha_transferencia' => $datos['fecha_transferencia'],
                'importe' => $datos['importe'],
                'referencia' => filled($datos['referencia'] ?? null)
                    ? trim((string) $datos['referencia'])
                    : null,
                'observaciones' => filled($datos['observaciones'] ?? null)
                    ? trim((string) $datos['observaciones'])
                    : null,
                'estado' => TransferenciaInformada::ESTADO_PENDIENTE,
            ]);

            $extension = strtolower($archivo->getClientOriginalExtension() ?: 'bin');
            $nombreInterno = Str::uuid().'.'.$extension;
            $ruta = 'autogestion/transferencias/'.$cliente->id.'/'.$transferencia->id.'/'.$nombreInterno;

            if (! Storage::disk('local')->putFileAs(
                dirname($ruta),
                $archivo,
                basename($ruta)
            )) {
                throw new \RuntimeException('No se pudo guardar el comprobante adjunto.');
            }

            TransferenciaInformadaArchivo::create([
                'transferencia_informada_id' => $transferencia->id,
                'nombre_original' => $archivo->getClientOriginalName(),
                'archivo' => $ruta,
                'mime_type' => $archivo->getMimeType(),
                'tamano' => $archivo->getSize(),
            ]);

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            if ($ruta) {
                Storage::disk('local')->delete($ruta);
            }

            throw $e;
        }

        return redirect()
            ->route('portal.transferencias.index')
            ->with('estado', 'La transferencia fue informada y quedó pendiente de verificación.');
    }

    public function archivo(TransferenciaInformada $transferencia, TransferenciaInformadaArchivo $archivo): StreamedResponse
    {
        $portalUsuario = Auth::guard('portal')->user();

        abort_unless($transferencia->cliente_id === $portalUsuario->cliente_id, 403);
        abort_unless($archivo->transferencia_informada_id === $transferencia->id, 404);
        abort_unless(Storage::disk('local')->exists($archivo->archivo), 404);

        return Storage::disk('local')->download($archivo->archivo, $archivo->nombre_original);
    }
}
