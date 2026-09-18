<?php

namespace App\Http\Controllers;

use App\Services\GeiCoreConsultaService;
use Illuminate\Http\Request;

final class CuentaCorrienteExploracionController extends Controller
{
    public function __construct(
        private readonly GeiCoreConsultaService $service
    ) {
    }

    public function index(Request $request)
    {
        $tipo = strtoupper((string) $request->query('tipo', 'PROPIETARIO'));
        $estado = (string) $request->query('estado', 'activas');
        $buscar = trim((string) $request->query('buscar', ''));
        $orden = strtolower((string) $request->query('orden', 'desc'));
        $orden = $orden === 'asc' ? 'asc' : 'desc';

        $detalle = null;
        $seleccionar = (int) $request->query('seleccionar', 0);
        if ($seleccionar > 0) {
            $detalle = $this->service->cuentaDetalle($seleccionar, $orden);
        }

        return view('cuentas-corrientes.index', [
            'tipo' => $tipo,
            'estado' => $estado,
            'buscar' => $buscar,
            'orden' => $orden,
            'resumen' => $this->service->cuentasResumen($tipo),
            'cuentas' => $this->service->cuentas($tipo, $estado, $buscar),
            'detalle' => $detalle,
        ]);
    }
}
