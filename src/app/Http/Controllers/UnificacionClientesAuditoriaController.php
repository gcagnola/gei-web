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

    public function revisiones(Request $request): View
    {
        Gate::authorize('administrar-unificaciones');

        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 180);
        $resultadosRevision = $this->unificacion->listarClasificados(
            $buscar, 'activos_revision', 'todos', [], 50
        );
        $ids = $resultadosRevision->getCollection()->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();

        return view('unificacion.clientes.auditoria', [
            'modo' => 'revisiones',
            'buscar' => $buscar,
            'resumenRevision' => $this->unificacion->resumenClasificacion([]),
            'resultadosRevision' => $resultadosRevision,
            'conflictosRevision' => $this->unificacion->conflictosPendientesPorClientes($ids),
            'conflictosSinCliente' => $this->unificacion->conflictosPendientesSinCliente(),
            'conflictosSinClienteTotal' => $this->unificacion->conflictosPendientesSinClienteTotal(),
        ]);
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
