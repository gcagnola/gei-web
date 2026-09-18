<?php

namespace App\Http\Controllers;

use App\Services\GeiCoreConsultaService;
use Illuminate\Http\Request;

final class PersonaExploracionController extends Controller
{
    public function __construct(
        private readonly GeiCoreConsultaService $service
    ) {
    }

    public function index(Request $request)
    {
        $periodo = (string) $request->query('periodo', $this->service->ultimoPeriodo());
        $rol = strtoupper((string) $request->query('rol', 'PROPIETARIO'));
        $estado = (string) $request->query('estado', 'activos');
        $buscar = trim((string) $request->query('buscar', ''));

        $detalle = null;
        $seleccionar = (int) $request->query('seleccionar', 0);
        if ($seleccionar > 0) {
            $detalle = $this->service->personaDetalle($seleccionar, $periodo);
        }

        return view('personas.index', [
            'periodos' => $this->service->periodos(),
            'periodo' => $periodo,
            'rol' => $rol,
            'estado' => $estado,
            'buscar' => $buscar,
            'resumen' => $this->service->personasResumen($periodo, $rol),
            'personas' => $this->service->personas($periodo, $rol, $estado, $buscar),
            'detalle' => $detalle,
        ]);
    }

    public function show(Request $request, int $persona)
    {
        $query = $request->query();
        $query['seleccionar'] = $persona;

        return redirect()->route('personas.index', $query);
    }
}
