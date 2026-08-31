<?php

namespace App\Http\Controllers;

use App\Services\UnificacionClientesService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class UnificacionClienteController extends Controller
{
    public function __construct(
        private readonly UnificacionClientesService $service,
    ) {
    }

    public function index(Request $request): View
    {
        $datos = $request->validate([
            'q' => ['nullable', 'string', 'max:180'],
            'vista' => ['nullable', 'in:activos_ok,activos_revision,inactivos'],
            'conflicto' => ['nullable', 'in:todos,con_conflicto,sin_conflicto'],
        ]);

        $texto = trim((string) ($datos['q'] ?? ''));
        $vista = (string) ($datos['vista'] ?? 'activos_ok');
        $filtroInactivos = (string) ($datos['conflicto'] ?? 'todos');

        $candidatos = $this->service->candidatos();
        $idsRevision = $candidatos
            ->flatMap(fn ($fila): array => [(int) $fila->id_a, (int) $fila->id_b])
            ->unique()->values()->all();

        $resumen = $this->service->resumenClasificacion($idsRevision);
        $resultados = $this->service->listarClasificados($texto, $vista, $filtroInactivos, $idsRevision);
        $candidatosBusqueda = $texto !== ''
            ? $this->service->candidatosBusqueda($resultados->getCollection())
            : collect();

        return view('unificacion.clientes.index', [
            'texto' => $texto,
            'vista' => $vista,
            'filtroInactivos' => $filtroInactivos,
            'resumen' => $resumen,
            'resultados' => $resultados,
            'candidatosBusqueda' => $candidatosBusqueda,
            'candidatosActivos' => $vista === 'activos_revision' ? $candidatos : collect(),
            'conflictosPendientes' => $vista === 'activos_revision' ? $this->service->conflictosPendientes() : collect(),
            'ultimasUnificaciones' => $this->service->ultimasUnificaciones(),
        ]);
    }

    public function comparar(Request $request): View|RedirectResponse
    {
        $datos = $request->validate([
            'principal' => ['required', 'integer', 'min:1'],
            'secundario' => ['required', 'integer', 'min:1', 'different:principal'],
        ]);
        try {
            $comparacion = $this->service->comparar((int) $datos['principal'], (int) $datos['secundario']);
        } catch (DomainException $error) {
            return redirect()->route('archivo.unificacion.clientes.index')->withErrors(['unificacion' => $error->getMessage()]);
        }

        return view('unificacion.clientes.comparar', $comparacion);
    }


    public function revisarConflicto(int $conflicto): View|RedirectResponse
    {
        try {
            $revision = $this->service->revisionCobol($conflicto);
        } catch (DomainException $error) {
            return redirect()
                ->route('archivo.unificacion.clientes.index', ['vista' => 'activos_revision'])
                ->withErrors(['unificacion' => $error->getMessage()]);
        }

        return view('unificacion.clientes.revision-cobol', $revision);
    }

    public function unificar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'principal' => ['required', 'integer', 'min:1'],
            'secundario' => ['required', 'integer', 'min:1', 'different:principal'],
            'confirmacion' => ['required', 'in:UNIFICAR'],
        ]);
        try {
            $resultado = $this->service->unificar((int) $datos['principal'], (int) $datos['secundario'], auth()->id() === null ? null : (int) auth()->id());
        } catch (DomainException $error) {
            return redirect()->route('archivo.unificacion.clientes.comparar', ['principal' => $datos['principal'], 'secundario' => $datos['secundario']])->withErrors(['unificacion' => $error->getMessage()]);
        } catch (Throwable $error) {
            report($error);
            return redirect()->route('archivo.unificacion.clientes.comparar', ['principal' => $datos['principal'], 'secundario' => $datos['secundario']])->withErrors(['unificacion' => 'La operación falló y fue revertida completamente. '.$error->getMessage()]);
        }

        return redirect()->route('archivo.unificacion.clientes.index')->with('estado', 'Unificación aplicada. Cliente '.$datos['secundario'].' absorbido por '.$datos['principal'].'. Auditoría #'.$resultado['id_unificacion'].'.');
    }

    public function resolverCandidato(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'id_a' => ['required', 'integer', 'min:1'],
            'id_b' => ['required', 'integer', 'min:1', 'different:id_a'],
            'decision' => ['required', 'in:MANTENER_SEPARADOS,CONFLICTIVO'],
        ]);
        try {
            $this->service->resolverCandidato((int) $datos['id_a'], (int) $datos['id_b'], (string) $datos['decision'], auth()->id() === null ? null : (int) auth()->id());
        } catch (DomainException $error) {
            return back()->withErrors(['unificacion' => $error->getMessage()]);
        }

        return back()->with('estado', $datos['decision'] === 'MANTENER_SEPARADOS' ? 'Los clientes quedaron marcados como personas distintas.' : 'El par quedó marcado como conflictivo para revisión.');
    }

    public function resolverConflicto(Request $request, int $conflicto): RedirectResponse
    {
        $datos = $request->validate([
            'decision' => ['required', 'in:ASOCIAR_EXISTENTE,CREAR_SEPARADO'],
            'cliente_id' => ['nullable', 'integer', 'min:1'],
        ]);
        try {
            $this->service->resolverConflictoImportacion($conflicto, (string) $datos['decision'], isset($datos['cliente_id']) ? (int) $datos['cliente_id'] : null, auth()->id() === null ? null : (int) auth()->id());
        } catch (DomainException $error) {
            return back()->withErrors(['unificacion' => $error->getMessage()]);
        } catch (Throwable $error) {
            report($error);
            return back()->withErrors(['unificacion' => 'No se pudo resolver el conflicto. '.$error->getMessage()]);
        }

        return back()->with('estado', $datos['decision'] === 'ASOCIAR_EXISTENTE' ? 'Decisión guardada. La próxima importación asociará esa identidad COBOL al cliente indicado.' : 'Decisión guardada. La próxima importación creará/mantendrá una persona separada.');
    }
}
