<?php

namespace App\Http\Controllers;

use App\Services\GeiCoreClienteService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

final class GeiCoreClienteController extends Controller
{
    public function __construct(
        private readonly GeiCoreClienteService $service
    ) {
    }

    public function index(Request $request): View
    {
        $periodo = (string) $request->query('periodo', $this->service->ultimoPeriodo());
        $rol = strtoupper((string) $request->query('rol', 'TODOS'));
        $estado = (string) $request->query('estado', 'activos');
        $buscar = trim((string) $request->query('buscar', ''));
        $modo = (string) $request->query('modo', 'clientes');
        $modo = in_array($modo, ['clientes', 'duplicados'], true) ? $modo : 'clientes';

        $duplicados = $this->service->duplicadosActivos(
            $periodo,
            $modo === 'duplicados' ? $buscar : ''
        );

        return view('core-clientes.index', [
            'periodos' => $this->service->periodos(),
            'periodo' => $periodo,
            'rol' => $rol,
            'estado' => $estado,
            'buscar' => $buscar,
            'modo' => $modo,
            'resumen' => $this->service->resumen($periodo, $rol),
            'clientes' => $modo === 'clientes'
                ? $this->service->listar($periodo, $rol, $estado, $buscar)
                : null,
            'duplicados' => $duplicados,
        ]);
    }

    public function show(Request $request, int $persona): View
    {
        $periodo = (string) $request->query('periodo', $this->service->ultimoPeriodo());
        $tab = (string) $request->query('tab', 'datos');

        if (! in_array($tab, [
            'datos',
            'inmuebles',
            'cuenta-corriente',
            'liquidaciones',
            'impuestos',
            'facturas',
        ], true)) {
            $tab = 'datos';
        }

        $mesActividad = in_array($tab, ['cuenta-corriente', 'liquidaciones', 'impuestos', 'facturas'], true)
            ? $this->service->resolverMesActividad($persona, $tab, now()->format('Ym'))
            : now()->format('Ym');

        return view('core-clientes.show', [
            'periodos' => $this->service->periodos(),
            'periodo' => $periodo,
            'tab' => $tab,
            'mesActividad' => $mesActividad,
            'detalle' => $this->service->detalle($persona, $periodo, $tab, $mesActividad),
        ]);
    }

    public function actividad(Request $request, int $persona): Response
    {
        $tipo = (string) $request->query('tipo', 'cuenta-corriente');
        $mes = preg_replace('/[^0-9]/', '', (string) $request->query('mes', now()->format('Ym')));

        if (! in_array($tipo, ['cuenta-corriente', 'liquidaciones', 'impuestos', 'facturas'], true)) {
            abort(404);
        }

        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $mes)) {
            abort(422, 'Mes inválido.');
        }

        $data = $this->service->actividad($persona, $tipo, $mes);

        return response(
            view('core-clientes.partials.actividad', [
                'tipo' => $tipo,
                'mes' => $mes,
                'data' => $data,
            ])->render()
        );
    }
}
