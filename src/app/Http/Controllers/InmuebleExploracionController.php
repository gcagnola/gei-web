<?php

namespace App\Http\Controllers;

use App\Services\GeiCoreConsultaService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use RuntimeException;

final class InmuebleExploracionController extends Controller
{
    public function __construct(
        private readonly GeiCoreConsultaService $service
    ) {
    }

    public function index(Request $request)
    {
        $periodo = (string) $request->query('periodo', $this->service->ultimoPeriodo());
        $estado = (string) $request->query('estado', 'activos');
        $modoDuplicados = $estado === 'duplicados';
        $buscar = trim((string) $request->query('buscar', ''));

        $detalle = null;
        $seleccionar = (int) $request->query('seleccionar', 0);
        if ($seleccionar > 0) {
            $detalle = $this->service->inmuebleDetalle($seleccionar, $periodo);
        }

        $duplicados = $modoDuplicados
            ? $this->service->inmueblesDuplicados($periodo, $buscar)
            : [];

        return view('inmuebles.index', [
            'periodos' => $this->service->periodos(),
            'periodo' => $periodo,
            'estado' => $estado,
            'buscar' => $buscar,
            'resumen' => $this->service->inmueblesResumen($periodo),
            'inmuebles' => $modoDuplicados ? null : $this->service->inmuebles($periodo, $estado, $buscar),
            'duplicados' => $duplicados,
            'duplicadosResumen' => $this->service->inmueblesDuplicadosResumen($periodo),
            'modoDuplicados' => $modoDuplicados,
            'detalle' => $detalle,
        ]);
    }


    public function validarUnico(Request $request, int $inmueble): RedirectResponse
    {
        $datos = $request->validate([
            'periodo' => ['required', 'regex:/^(19|20)\d{2}(0[1-9]|1[0-2])$/'],
            'buscar' => ['nullable', 'string', 'max:150'],
        ]);

        try {
            $this->service->validarInmuebleComoUnico(
                $inmueble,
                (string) $datos['periodo'],
                auth()->id()
            );
        } catch (RuntimeException $e) {
            return redirect()->route('inmuebles.index', [
                'periodo' => $datos['periodo'],
                'estado' => 'duplicados',
                'buscar' => $datos['buscar'] ?? '',
            ])->with('error', $e->getMessage());
        }

        return redirect()->route('inmuebles.index', [
            'periodo' => $datos['periodo'],
            'estado' => 'duplicados',
            'buscar' => $datos['buscar'] ?? '',
        ])->with('success', 'Inmueble #'.$inmueble.' validado como inmueble único frente a los candidatos actuales.');
    }

    public function deshacerValidacion(Request $request, int $inmueble): RedirectResponse
    {
        $datos = $request->validate([
            'periodo' => ['required', 'regex:/^(19|20)\d{2}(0[1-9]|1[0-2])$/'],
            'buscar' => ['nullable', 'string', 'max:150'],
        ]);

        $this->service->deshacerValidacionInmueble($inmueble, (string) $datos['periodo']);

        return redirect()->route('inmuebles.index', [
            'periodo' => $datos['periodo'],
            'estado' => 'duplicados',
            'buscar' => $datos['buscar'] ?? '',
        ])->with('success', 'Se deshizo la validación del inmueble #'.$inmueble.'.');
    }

    public function show(Request $request, int $inmueble)
    {
        $query = $request->query();
        $query['seleccionar'] = $inmueble;

        return redirect()->route('inmuebles.index', $query);
    }
}
