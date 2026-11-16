<?php

namespace App\Http\Controllers;

use App\Services\UnificacionClientesService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Auditoría administrativa sobre el maestro canónico de gei_db.
 */
final class UnificacionClientesAuditoriaController extends Controller
{
    public function __construct(private readonly UnificacionClientesService $unificacion)
    {
    }

    public function revisiones(Request $request): \Illuminate\Http\RedirectResponse
    {
        Gate::authorize('administrar-unificaciones');

        return redirect()->route(
            'archivo.unificacion.clientes.index',
            array_filter([
                'vista' => 'activos_revision',
                'q' => $request->query('buscar'),
                'estado_origen' => $request->query('estado_origen'),
                'entidad' => $request->query('entidad'),
            ], static fn ($valor): bool => $valor !== null && $valor !== '')
        );
    }

    public function historial(): View
    {
        Gate::authorize('administrar-unificaciones');

        return view('unificacion.clientes.auditoria', [
            'modo' => 'historial',
            'buscar' => '',
            'unificacionesPaginadas' => $this->unificacion->unificacionesPaginadas(20),
            'decisionesCobolPaginadas' => $this->unificacion->revisionesCobolResueltasPaginadas(20),
        ]);
    }
}
