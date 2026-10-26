<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\GeiCoreClienteService;
use App\Services\UnificacionClientesService;
use Illuminate\Support\Facades\Gate;
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
        $modo = in_array($modo, ['clientes', 'duplicados', 'revisiones', 'historial'], true) ? $modo : 'clientes';

        // Sólo administradores pueden consultar conciliaciones y auditoría de identidades.
        if (in_array($modo, ['revisiones', 'historial'], true)) {
            Gate::authorize('administrar-unificaciones');
        }

        $administracion = [];
        if ($modo === 'revisiones') {
            $unificacion = app(UnificacionClientesService::class);
            $resultados = $unificacion->listarClasificados($buscar, 'activos_revision', 'todos', [], 50);
            $ids = $resultados->getCollection()->pluck('id')->map(fn ($id) => (int) $id)->all();
            $administracion = [
                'resumenRevision' => $unificacion->resumenClasificacion([]),
                'resultadosRevision' => $resultados,
                'conflictosRevision' => $unificacion->conflictosPendientesPorClientes($ids),
                'conflictosSinCliente' => $unificacion->conflictosPendientesSinCliente(),
                'conflictosSinClienteTotal' => $unificacion->conflictosPendientesSinClienteTotal(),
            ];
        } elseif ($modo === 'historial') {
            $unificacion = app(UnificacionClientesService::class);
            $administracion = [
                'unificacionesPaginadas' => $unificacion->unificacionesPaginadas(20),
                'decisionesCobolPaginadas' => $unificacion->revisionesCobolResueltasPaginadas(20),
            ];
        }

        $duplicados = $modo === 'duplicados'
            ? $this->service->duplicadosActivos($periodo, $buscar, $sedesPermitidas)
            : ['grupos' => [], 'total_grupos' => 0, 'total_personas' => 0];

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
            ...$administracion,
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

        $clienteCanonico = $request->integer('cliente');
        if ($clienteCanonico > 0) {
            abort_unless(in_array($persona, $this->service->snapshotsDelCliente(
                $clienteCanonico, $periodo, $sedesPermitidas
            ), true), 403);
        }
        $mesActividad = in_array($tab, ['cuenta-corriente', 'liquidaciones', 'impuestos', 'facturas'], true)
            ? ($clienteCanonico > 0
                ? $this->service->mesActividadCanonica($clienteCanonico, $periodo, $tab, now()->format('Ym'), $sedesPermitidas)
                : $this->service->resolverMesActividad($persona, $tab, now()->format('Ym'), $sedesPermitidas))
            : now()->format('Ym');

        $detalleCuentaCobol = $clienteCanonico > 0
            ? $this->service->detalleCanonico($clienteCanonico, $persona, $periodo, $tab, $mesActividad, $sedesPermitidas)
            : $this->service->detalle($persona, $periodo, $tab, $mesActividad, $sedesPermitidas);

        $cobolCuentasAdmin = null;

        if ($tab === 'cuenta-corriente'
            && app()->environment('local', 'testing')) {

            $consulta = app(\App\Services\CoreClienteCuentaCobolConsultaService::class);

            $cobolCuentasAdmin = $consulta->consultar(
                $detalleCuentaCobol['cuentasCorrientes'] ?? [],
                $mesActividad,
                $sedesPermitidas
            );

            // Seleccionar automáticamente el último mes disponible.
            if (! $request->exists('mes')) {
                $ultimo = collect($cobolCuentasAdmin)
                    ->pluck('ultimo_mes')
                    ->filter()
                    ->max();

                if ($ultimo !== null && $ultimo !== $mesActividad) {
                    $mesActividad = (string) $ultimo;

                    $cobolCuentasAdmin = $consulta->consultar(
                        $detalleCuentaCobol['cuentasCorrientes'] ?? [],
                        $mesActividad,
                        $sedesPermitidas
                    );
                }
            }
        }

        return view('core-clientes.show', [
            'periodos' => $this->service->periodos(),
            'periodo' => $periodo,
            'tab' => $tab,
            'mesActividad' => $mesActividad,
            'clienteCanonico' => $clienteCanonico > 0 ? $clienteCanonico : null,
            'detalle' => $detalleCuentaCobol,
            'cobolCuentasAdmin' => $cobolCuentasAdmin,
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

        $clienteCanonico = $request->integer('cliente');
        if ($clienteCanonico > 0) {
            abort_unless(in_array($persona, $this->service->snapshotsDelCliente(
                $clienteCanonico, $periodo, $sedesPermitidas
            ), true), 403);
        }
        $email = trim((string) ($data['email'] ?? ''));
        $this->service->actualizarEmail($persona, $email !== '' ? $email : null);

        return redirect()
            ->route('core-clientes.show', [
                'persona' => $persona,
                'periodo' => $periodo,
                'tab' => 'datos',
                'cliente' => $clienteCanonico ?: null,
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

        $clienteCanonico = $request->integer('cliente');
        if ($clienteCanonico > 0) {
            abort_unless(in_array($persona, $this->service->snapshotsDelCliente(
                $clienteCanonico, $periodo, $sedesPermitidas
            ), true), 403);
        }
        $data = $clienteCanonico > 0
            ? $this->service->actividadCanonica($clienteCanonico, $periodo, $tipo, $mes, $sedesPermitidas)
            : $this->service->actividad($persona, $tipo, $mes, $sedesPermitidas);
        $incidencias = $tipo === 'cuenta-corriente'
            ? ($clienteCanonico > 0
                ? $this->service->incidenciasCanonicas($clienteCanonico, $periodo, $mes, $sedesPermitidas)
                : $this->service->incidenciasFechasFuturasMes($persona, $mes, $sedesPermitidas))
            : collect();

        $cobolCuentasAdmin = null;

        if ($tipo === 'cuenta-corriente'
            && app()->environment('local', 'testing')) {

            $detalleAutorizado = $clienteCanonico > 0
                ? $this->service->detalleCanonico($clienteCanonico, $persona, $periodo, $tipo, $mes, $sedesPermitidas)
                : $this->service->detalle($persona, $periodo, $tipo, $mes, $sedesPermitidas);

            $cobolCuentasAdmin = app(\App\Services\CoreClienteCuentaCobolConsultaService::class)
                ->consultar(
                    $detalleAutorizado['cuentasCorrientes'] ?? [],
                    $mes,
                    $sedesPermitidas
                );
        }

        return response(
            view($cobolCuentasAdmin !== null
                ? 'core-clientes.partials.cuenta-cobol'
                : 'core-clientes.partials.actividad', [
                'tipo' => $tipo,
                'mes' => $mes,
                'data' => $data,
                'incidencias' => $incidencias,
                'cuentas' => $cobolCuentasAdmin,
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
