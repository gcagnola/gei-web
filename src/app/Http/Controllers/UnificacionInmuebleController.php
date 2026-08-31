<?php

namespace App\Http\Controllers;

use App\Services\UnificacionInmueblesService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

final class UnificacionInmuebleController extends Controller
{
    public function __construct(
        private readonly UnificacionInmueblesService $service,
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

        $candidatosActivos = $this->service->candidatos();
        $idsActivosConCandidato = $candidatosActivos
            ->flatMap(fn ($fila): array => [(int) $fila->id_a, (int) $fila->id_b])
            ->unique()
            ->values()
            ->all();

        $resumen = $this->service->resumenClasificacion($idsActivosConCandidato);
        $resultados = $this->service->listarClasificados(
            $texto,
            $vista,
            $filtroInactivos,
            $idsActivosConCandidato
        );

        $coleccionResultados = $resultados->getCollection();
        $idsVisibles = $coleccionResultados->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $candidatosBusqueda = $texto !== ''
            ? $this->service->candidatosBusqueda($coleccionResultados)
            : collect();

        return view('unificacion.index', [
            'texto' => $texto,
            'vista' => $vista,
            'filtroInactivos' => $filtroInactivos,
            'resumen' => $resumen,
            'resultados' => $resultados,
            'candidatosBusqueda' => $candidatosBusqueda,
            'gruposCandidatosBusqueda' => $this->service->agruparCandidatosBusqueda($candidatosBusqueda),
            'candidatosActivos' => $vista === 'activos_revision' ? $candidatosActivos : collect(),
            'conflictosVisibles' => in_array($vista, ['activos_revision', 'inactivos'], true)
                ? $this->service->conflictosPendientesPorInmuebles($idsVisibles)
                : collect(),
            'conflictosSinInmueble' => $vista === 'activos_revision'
                ? $this->service->conflictosPendientesSinInmueble()
                : collect(),
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
            $comparacion = $this->service->comparar(
                (int) $datos['principal'],
                (int) $datos['secundario']
            );
        } catch (DomainException $error) {
            return redirect()
                ->route('archivo.unificacion.index')
                ->withErrors(['unificacion' => $error->getMessage()]);
        }

        return view('unificacion.comparar-inmuebles', $comparacion);
    }

    public function unificar(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'principal' => ['required', 'integer', 'min:1'],
            'secundario' => ['required', 'integer', 'min:1', 'different:principal'],
            'confirmacion' => ['required', 'in:UNIFICAR'],
        ]);

        try {
            $resultado = $this->service->unificar(
                (int) $datos['principal'],
                (int) $datos['secundario'],
                auth()->id() === null ? null : (int) auth()->id()
            );
        } catch (DomainException $error) {
            return redirect()
                ->route('archivo.unificacion.inmuebles.comparar', [
                    'principal' => $datos['principal'],
                    'secundario' => $datos['secundario'],
                ])
                ->withErrors(['unificacion' => $error->getMessage()]);
        } catch (Throwable $error) {
            report($error);

            return redirect()
                ->route('archivo.unificacion.inmuebles.comparar', [
                    'principal' => $datos['principal'],
                    'secundario' => $datos['secundario'],
                ])
                ->withErrors([
                    'unificacion' => 'La operación falló y fue revertida completamente. '.$error->getMessage(),
                ]);
        }

        return redirect()
            ->route('archivo.unificacion.index')
            ->with(
                'estado',
                'Unificación aplicada. Inmueble '.$datos['secundario'].
                ' absorbido por '.$datos['principal'].
                ' Auditoría #'.$resultado['id_unificacion'].'.'
            );
    }

    public function resolverConflicto(Request $request, int $conflicto): RedirectResponse
    {
        $datos = $request->validate([
            'decision' => ['required', 'in:ASOCIAR_EXISTENTE,CREAR_SEPARADO'],
            'inmueble_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $this->service->resolverConflictoImportacion(
                $conflicto,
                (string) $datos['decision'],
                isset($datos['inmueble_id']) ? (int) $datos['inmueble_id'] : null,
                auth()->id() === null ? null : (int) auth()->id()
            );
        } catch (DomainException $error) {
            return back()->withErrors(['unificacion' => $error->getMessage()]);
        } catch (Throwable $error) {
            report($error);

            return back()->withErrors(['unificacion' => 'No se pudo resolver el conflicto. '.$error->getMessage()]);
        }

        return back()->with(
            'estado',
            $datos['decision'] === 'ASOCIAR_EXISTENTE'
                ? 'Decisión guardada. La próxima importación asociará esa identidad COBOL al inmueble indicado.'
                : 'Decisión guardada. La próxima importación permitirá crear/mantener ese inmueble separado.'
        );
    }

    public function resolverCandidato(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'id_a' => ['required', 'integer', 'min:1'],
            'id_b' => ['required', 'integer', 'min:1', 'different:id_a'],
            'decision' => ['required', 'in:MANTENER_SEPARADOS,CONFLICTIVO'],
        ]);

        try {
            $this->service->resolverCandidato(
                (int) $datos['id_a'],
                (int) $datos['id_b'],
                (string) $datos['decision'],
                auth()->id() === null ? null : (int) auth()->id()
            );
        } catch (DomainException $error) {
            return back()->withErrors(['unificacion' => $error->getMessage()]);
        }

        $mensaje = $datos['decision'] === 'MANTENER_SEPARADOS'
            ? 'La pareja quedó marcada como inmuebles distintos.'
            : 'La pareja quedó marcada como conflictiva para revisión.';

        return back()->with('estado', $mensaje);
    }
}
