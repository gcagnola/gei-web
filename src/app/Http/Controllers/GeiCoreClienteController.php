<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\GeiCoreClienteService;
use Illuminate\Http\RedirectResponse;
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
        $sedesPermitidas = $this->sedesPermitidas($request);
        $periodo = (string) $request->query('periodo', $this->service->ultimoPeriodo());
        $rol = strtoupper((string) $request->query('rol', 'TODOS'));
        $estado = (string) $request->query('estado', 'activos');
        $buscar = trim((string) $request->query('buscar', ''));
        $modo = (string) $request->query('modo', 'clientes');
        $modo = in_array($modo, ['clientes', 'duplicados'], true) ? $modo : 'clientes';

        $duplicados = $this->service->duplicadosActivos(
            $periodo,
            $modo === 'duplicados' ? $buscar : '',
            $sedesPermitidas
        );

        return view('core-clientes.index', [
            'periodos' => $this->service->periodos(),
            'periodo' => $periodo,
            'rol' => $rol,
            'estado' => $estado,
            'buscar' => $buscar,
            'modo' => $modo,
            'resumen' => $this->service->resumen($periodo, $rol, $sedesPermitidas),
            'clientes' => $modo === 'clientes'
                ? $this->service->listar($periodo, $rol, $estado, $buscar, 50, $sedesPermitidas)
                : null,
            'duplicados' => $duplicados,
        ]);
    }

    public function show(Request $request, int $persona): View
    {
        $sedesPermitidas = $this->sedesPermitidas($request);
        $periodo = (string) $request->query('periodo', $this->service->ultimoPeriodo());

        abort_unless(
            $this->service->personaVisibleEnSedes($persona, $periodo, $sedesPermitidas),
            403
        );
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
            ? $this->service->resolverMesActividad($persona, $tab, now()->format('Ym'), $sedesPermitidas)
            : now()->format('Ym');

        return view('core-clientes.show', [
            'periodos' => $this->service->periodos(),
            'periodo' => $periodo,
            'tab' => $tab,
            'mesActividad' => $mesActividad,
            'detalle' => $this->service->detalle($persona, $periodo, $tab, $mesActividad, $sedesPermitidas),
        ]);
    }

    public function updateEmail(Request $request, int $persona): RedirectResponse
    {
        $sedesPermitidas = $this->sedesPermitidas($request);
        $periodo = (string) $request->input('periodo', $this->service->ultimoPeriodo());

        abort_unless(
            $this->service->personaVisibleEnSedes($persona, $periodo, $sedesPermitidas),
            403
        );

        $data = $request->validate([
            'email' => ['nullable', 'email:rfc', 'max:180'],
        ], [
            'email.email' => 'Ingrese un email válido.',
            'email.max' => 'El email no puede superar los 180 caracteres.',
        ]);

        $email = trim((string) ($data['email'] ?? ''));
        $this->service->actualizarEmail($persona, $email !== '' ? $email : null);

        return redirect()
            ->route('core-clientes.show', [
                'persona' => $persona,
                'periodo' => $periodo,
                'tab' => 'datos',
            ])
            ->with('estado', 'El email del cliente fue actualizado.');
    }

    public function actividad(Request $request, int $persona): Response
    {
        $sedesPermitidas = $this->sedesPermitidas($request);
        $periodo = (string) $request->query('periodo', $this->service->ultimoPeriodo());

        abort_unless(
            $this->service->personaVisibleEnSedes($persona, $periodo, $sedesPermitidas),
            403
        );

        $tipo = (string) $request->query('tipo', 'cuenta-corriente');
        $mes = preg_replace('/[^0-9]/', '', (string) $request->query('mes', now()->format('Ym')));

        if (! in_array($tipo, ['cuenta-corriente', 'liquidaciones', 'impuestos', 'facturas'], true)) {
            abort(404);
        }

        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $mes)) {
            abort(422, 'Mes inválido.');
        }

        $data = $this->service->actividad($persona, $tipo, $mes, $sedesPermitidas);
        $incidencias = $tipo === 'cuenta-corriente'
            ? $this->service->incidenciasFechasFuturasMes($persona, $mes, $sedesPermitidas)
            : collect();

        return response(
            view('core-clientes.partials.actividad', [
                'tipo' => $tipo,
                'mes' => $mes,
                'data' => $data,
                'incidencias' => $incidencias,
            ])->render()
        );
    }
    /** @return array<int, string> */
    private function sedesPermitidas(Request $request): array
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();
        abort_unless($usuario instanceof Usuario, 401);

        $sedes = $usuario->codigosSucursales();
        abort_if($sedes === [], 403, 'El usuario no tiene sucursales asignadas.');

        return $sedes;
    }

}
