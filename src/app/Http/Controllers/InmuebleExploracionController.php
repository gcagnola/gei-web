<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\GeiCoreConsultaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

final class InmuebleExploracionController extends Controller
{
    public function __construct(
        private readonly GeiCoreConsultaService $service
    ) {
    }

    public function index(Request $request)
    {
        [$sedesPermitidas, $puedeRevisarSinSede] = $this->contextoSucursales($request);

        $periodo = (string) $request->query('periodo', $this->service->ultimoPeriodo());
        $estado = (string) $request->query('estado', 'activos');
        $estado = in_array($estado, ['activos', 'todos'], true) ? $estado : 'activos';

        $sede = $this->resolverFiltroSede((string) $request->query('sede', 'todas'), $sedesPermitidas, $puedeRevisarSinSede);

        $revision = (string) $request->query('revision', '');
        $modoDuplicados = $revision === 'duplicados';
        $buscar = trim((string) $request->query('buscar', ''));

        $detalle = null;
        $seleccionar = (int) $request->query('seleccionar', 0);
        if ($seleccionar > 0) {
            $detalle = $this->service->inmuebleDetalle($seleccionar, $periodo);
            $this->asegurarDetallePermitido($detalle, $sedesPermitidas, $puedeRevisarSinSede);
        }

        $duplicados = $modoDuplicados
            ? $this->service->inmueblesDuplicados($periodo, $estado, $sede, $buscar, $sedesPermitidas)
            : [];

        return view('inmuebles.index', [
            'periodos' => $this->service->periodos(),
            'periodo' => $periodo,
            'estado' => $estado,
            'sede' => $sede,
            'revision' => $revision,
            'buscar' => $buscar,
            'sedesPermitidas' => $sedesPermitidas,
            'puedeRevisarSinSede' => $puedeRevisarSinSede,
            'resumen' => $this->service->inmueblesResumen($periodo, $sedesPermitidas),
            'inmuebles' => $modoDuplicados
                ? null
                : $this->service->inmuebles($periodo, $estado, $sede, $buscar, 50, $sedesPermitidas),
            'duplicados' => $duplicados,
            'duplicadosResumen' => $this->service->inmueblesDuplicadosResumen(
                $periodo,
                $estado,
                $sede,
                $buscar,
                $sedesPermitidas
            ),
            'sinSedeResumen' => $puedeRevisarSinSede
                ? $this->service->inmueblesSinSedeResumen($periodo, $estado, $buscar)
                : 0,
            'modoDuplicados' => $modoDuplicados,
            'detalle' => $detalle,
        ]);
    }

    public function actualizarSede(Request $request, int $inmueble): RedirectResponse
    {
        [$sedesPermitidas, $puedeRevisarSinSede] = $this->contextoSucursales($request);

        $datos = $request->validate([
            'sede_codigo' => ['required', 'in:SF,ST'],
            'periodo' => ['required', 'regex:/^(19|20)\d{2}(0[1-9]|1[0-2])$/'],
            'estado' => ['nullable', 'in:activos,todos'],
            'sede' => ['nullable', 'in:todas,SF,ST,sin_sede'],
            'revision' => ['nullable', 'in:duplicados'],
            'buscar' => ['nullable', 'string', 'max:150'],
        ]);

        abort_unless(in_array($datos['sede_codigo'], $sedesPermitidas, true), 403);

        $detalle = $this->service->inmuebleDetalle($inmueble, (string) $datos['periodo']);
        $this->asegurarDetallePermitido($detalle, $sedesPermitidas, $puedeRevisarSinSede);

        try {
            $this->service->actualizarSedeInmueble(
                $inmueble,
                (string) $datos['sede_codigo'],
                auth()->id()
            );
        } catch (RuntimeException $e) {
            return redirect()->route('inmuebles.index', [
                'periodo' => $datos['periodo'],
                'estado' => $datos['estado'] ?? 'activos',
                'sede' => $this->resolverFiltroSede((string) ($datos['sede'] ?? 'todas'), $sedesPermitidas, $puedeRevisarSinSede),
                'revision' => $datos['revision'] ?? null,
                'buscar' => $datos['buscar'] ?? '',
                'seleccionar' => $inmueble,
            ])->with('error', $e->getMessage());
        }

        return redirect()->route('inmuebles.index', [
            'periodo' => $datos['periodo'],
            'estado' => $datos['estado'] ?? 'activos',
            'sede' => $this->resolverFiltroSede((string) ($datos['sede'] ?? 'todas'), $sedesPermitidas, $puedeRevisarSinSede),
            'revision' => $datos['revision'] ?? null,
            'buscar' => $datos['buscar'] ?? '',
            'seleccionar' => $inmueble,
        ])->with('success', 'Sede del inmueble actualizada correctamente.');
    }

    public function validarUnico(Request $request, int $inmueble): RedirectResponse
    {
        [$sedesPermitidas, $puedeRevisarSinSede] = $this->contextoSucursales($request);

        $datos = $request->validate([
            'periodo' => ['required', 'regex:/^(19|20)\d{2}(0[1-9]|1[0-2])$/'],
            'estado' => ['nullable', 'in:activos,todos'],
            'sede' => ['nullable', 'in:todas,SF,ST,sin_sede'],
            'buscar' => ['nullable', 'string', 'max:150'],
        ]);

        $detalle = $this->service->inmuebleDetalle($inmueble, (string) $datos['periodo']);
        $this->asegurarDetallePermitido($detalle, $sedesPermitidas, $puedeRevisarSinSede);

        try {
            $this->service->validarInmuebleComoUnico(
                $inmueble,
                (string) $datos['periodo'],
                auth()->id()
            );
        } catch (RuntimeException $e) {
            return redirect()->route('inmuebles.index', [
                'periodo' => $datos['periodo'],
                'estado' => $datos['estado'] ?? 'activos',
                'sede' => $this->resolverFiltroSede((string) ($datos['sede'] ?? 'todas'), $sedesPermitidas, $puedeRevisarSinSede),
                'revision' => 'duplicados',
                'buscar' => $datos['buscar'] ?? '',
            ])->with('error', $e->getMessage());
        }

        return redirect()->route('inmuebles.index', [
            'periodo' => $datos['periodo'],
            'estado' => $datos['estado'] ?? 'activos',
            'sede' => $this->resolverFiltroSede((string) ($datos['sede'] ?? 'todas'), $sedesPermitidas, $puedeRevisarSinSede),
            'revision' => 'duplicados',
            'buscar' => $datos['buscar'] ?? '',
        ])->with('success', 'Inmueble #'.$inmueble.' validado como inmueble único frente a los candidatos actuales.');
    }

    public function deshacerValidacion(Request $request, int $inmueble): RedirectResponse
    {
        [$sedesPermitidas, $puedeRevisarSinSede] = $this->contextoSucursales($request);

        $datos = $request->validate([
            'periodo' => ['required', 'regex:/^(19|20)\d{2}(0[1-9]|1[0-2])$/'],
            'estado' => ['nullable', 'in:activos,todos'],
            'sede' => ['nullable', 'in:todas,SF,ST,sin_sede'],
            'buscar' => ['nullable', 'string', 'max:150'],
        ]);

        $detalle = $this->service->inmuebleDetalle($inmueble, (string) $datos['periodo']);
        $this->asegurarDetallePermitido($detalle, $sedesPermitidas, $puedeRevisarSinSede);

        $this->service->deshacerValidacionInmueble($inmueble, (string) $datos['periodo']);

        return redirect()->route('inmuebles.index', [
            'periodo' => $datos['periodo'],
            'estado' => $datos['estado'] ?? 'activos',
            'sede' => $this->resolverFiltroSede((string) ($datos['sede'] ?? 'todas'), $sedesPermitidas, $puedeRevisarSinSede),
            'revision' => 'duplicados',
            'buscar' => $datos['buscar'] ?? '',
        ])->with('success', 'Se deshizo la validación del inmueble #'.$inmueble.'.');
    }

    public function show(Request $request, int $inmueble)
    {
        $query = $request->query();
        $query['seleccionar'] = $inmueble;

        return redirect()->route('inmuebles.index', $query);
    }

    /** @return array{0: array<int, string>, 1: bool} */
    private function contextoSucursales(Request $request): array
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();
        abort_unless($usuario instanceof Usuario, 401);

        $sedes = $usuario->codigosSucursales();
        abort_if($sedes === [], 403, 'El usuario no tiene sucursales asignadas.');

        return [$sedes, Gate::allows('administrar-unificaciones')];
    }

    /** @param array<int, string> $sedesPermitidas */
    private function resolverFiltroSede(string $sedeSolicitada, array $sedesPermitidas, bool $puedeRevisarSinSede): string
    {
        $sedeSolicitada = strtoupper(trim($sedeSolicitada));

        if (count($sedesPermitidas) === 1) {
            return $sedesPermitidas[0];
        }

        if ($sedeSolicitada === 'SIN_SEDE') {
            return $puedeRevisarSinSede ? 'sin_sede' : 'todas';
        }

        if (in_array($sedeSolicitada, $sedesPermitidas, true)) {
            return $sedeSolicitada;
        }

        return 'todas';
    }

    /** @param array<int, string> $sedesPermitidas */
    private function asegurarDetallePermitido(array $detalle, array $sedesPermitidas, bool $puedeRevisarSinSede): void
    {
        $codigo = strtoupper(trim((string) ($detalle['inmueble']->sede_periodo ?? '')));

        if ($codigo === '') {
            abort_unless($puedeRevisarSinSede, 403);

            return;
        }

        abort_unless(in_array($codigo, $sedesPermitidas, true), 403);
    }
}
