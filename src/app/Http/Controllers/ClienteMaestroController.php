<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\ClienteCuenta;
use App\Models\Usuario;
use App\Services\CuentaCorrienteMovimientosClienteService;
use App\Services\HistoricoCobolCuentaService;
use App\Services\ClienteMaestroDocumentosService;
use App\Services\ClienteMaestroRelacionesService;
use App\Services\ClienteMaestroFichaContratoCobolService;
use App\Services\ClienteActividadOperativaPeriodoService;
use App\Services\ComprobantesArcaService;
use App\Services\ImpuestosGarantizadosPdfService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Illuminate\Support\Facades\Storage;

/**
 * Vista preliminar, exclusivamente de lectura, del maestro operativo.
 *
 * Las cuentas COBOL son la frontera de visibilidad por sucursal mientras
 * clientes_cuentas.sucursal_id permanezca sin completar.
 */
final class ClienteMaestroController extends Controller
{
    public function index(Request $request, ClienteActividadOperativaPeriodoService $actividadPeriodo): View
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();
        abort_unless(
            $usuario instanceof Usuario
                && $usuario->activo
                && $usuario->perfil?->puedeVerModulo('ARCHIVO_CLIENTES'),
            403
        );

        $sedesPermitidas = array_values(array_intersect(
            ['SF', 'ST'],
            array_map('strtoupper', $usuario->codigosSucursales())
        ));
        abort_if($sedesPermitidas === [], 403, 'El usuario no tiene sucursales asignadas.');

        $sede = strtoupper(trim((string) $request->query('sede', 'TODAS')));
        if (count($sedesPermitidas) === 1) {
            $sede = $sedesPermitidas[0];
        } elseif (! in_array($sede, $sedesPermitidas, true)) {
            $sede = 'TODAS';
        }
        $sedesVisibles = $sede === 'TODAS' ? $sedesPermitidas : [$sede];

        // Permitir registros sin cuenta SOLO al administrador habilitado en ambas sedes
        // y sin un filtro de sede particular. En otros casos, exigir una cuenta visible.
        $esAdministradorGlobal = Gate::allows('administrar-unificaciones')
            && in_array('SF', $sedesPermitidas, true)
            && in_array('ST', $sedesPermitidas, true);
        $puedeVerSinCuenta = $esAdministradorGlobal && $sede === 'TODAS';

        $prefijos = [];
        foreach ($sedesVisibles as $codigoSede) {
            array_push($prefijos, ...match ($codigoSede) {
                'SF' => ['1103', '1202'],
                'ST' => ['2103', '2202'],
                default => [],
            });
        }
        abort_if($prefijos === [], 403);

        $rol = strtoupper(trim((string) $request->query('rol', 'TODOS')));
        if (! in_array($rol, ['TODOS', 'INQUILINO', 'PROPIETARIO'], true)) {
            $rol = 'TODOS';
        }
        $estado = strtolower(trim((string) $request->query('estado', 'activos')));
        if (! in_array($estado, ['todos', 'activos', 'inactivos'], true)) {
            $estado = 'todos';
        }
        $buscar = trim((string) $request->query('buscar', ''));
        $buscar = mb_substr($buscar, 0, 120);
        // GEI_CLIENTES_ACTIVIDAD_MATERIALIZADA_20261030
        // No usar el parámetro 'periodo': ya corresponde al histórico de las fichas.
        [$periodoActividad, $periodosActividad] = $actividadPeriodo->seleccionarPeriodo($request);

        // El mismo cliente canónico puede contener cuentas de identidades absorbidas.
        // Buscar y filtrar sobre TODAS esas cuentas, sin deshacer las unificaciones.
        $cuentasDelCanonico = static function (Builder $q, ?string $rolFiltro = null) use ($prefijos): void {
            $q->whereExists(static function ($sub) use ($prefijos, $rolFiltro): void {
                $sub->selectRaw('1')
                    ->from('clientes_cuentas as cc')
                    ->join('clientes as origen', 'origen.id', '=', 'cc.cliente_id')
                    ->whereRaw('COALESCE(origen.id_cliente_canonico, origen.id) = clientes.id')
                    ->whereIn(DB::raw('LEFT(cc.cuenta, 4)'), $prefijos);
                if ($rolFiltro !== null) {
                    $sub->where('cc.rol', $rolFiltro);
                }
            });
        };

        $consulta = Cliente::query()->whereNull('id_cliente_canonico');
        if (! $puedeVerSinCuenta) {
            $cuentasDelCanonico($consulta);
        }
        if ($rol !== 'TODOS') {
            $cuentasDelCanonico($consulta, $rol);
        }

        // Misma regla de Cuenta Corriente, por período, rol, sede y cuenta EXACTOS.
        // 'inactivos' = sin actividad; no equivale a baja contractual.
        $actividadPeriodo->filtrar($consulta, $estado, $periodoActividad, $prefijos, $rol);

        if ($buscar !== '') {
            $termino = '%'.$buscar.'%';
            $cuentaBuscada = preg_replace('/[^0-9]/', '', $buscar) ?: '';
            $consulta->where(static function (Builder $q) use ($termino, $cuentaBuscada, $prefijos): void {
                $q->where('nombre', 'ILIKE', $termino)
                    ->orWhere('email', 'ILIKE', $termino)
                    ->orWhere('cuit', 'ILIKE', $termino)
                    ->orWhere('numero_documento', 'ILIKE', $termino)
                    ->orWhereRaw('CAST(clientes.id AS TEXT) LIKE ?', [$termino]);
                if ($cuentaBuscada !== '') {
                    $q->orWhereExists(static function ($sub) use ($prefijos, $cuentaBuscada): void {
                        $sub->selectRaw('1')
                            ->from('clientes_cuentas as cc')
                            ->join('clientes as origen', 'origen.id', '=', 'cc.cliente_id')
                            ->whereRaw('COALESCE(origen.id_cliente_canonico, origen.id) = clientes.id')
                            ->whereIn(DB::raw('LEFT(cc.cuenta, 4)'), $prefijos)
                            ->where('cc.cuenta', 'LIKE', '%'.$cuentaBuscada.'%');
                    });
                }
            });
        }

        $clientes = $consulta
            ->orderByRaw("TRANSLATE(LOWER(TRIM(nombre)), 'áéíóúüñ', 'aeiouun')")
            ->orderBy('id')->paginate(40)->withQueryString();

        // Una única consulta adicional para mostrar también cuentas absorbidas.
        $ids = $clientes->getCollection()->pluck('id')->all();
        $cuentasPorCliente = $ids === [] ? collect() : ClienteCuenta::query()
            ->from('clientes_cuentas as cc')
            ->join('clientes as origen', 'origen.id', '=', 'cc.cliente_id')
            ->whereIn(DB::raw('COALESCE(origen.id_cliente_canonico, origen.id)'), $ids)
            ->whereIn(DB::raw('LEFT(cc.cuenta, 4)'), $prefijos)
            ->selectRaw('cc.*, COALESCE(origen.id_cliente_canonico, origen.id) as canonico_id')
            ->orderBy('cc.cuenta')
            ->get()->groupBy('canonico_id');
        foreach ($clientes->getCollection() as $fila) {
            $fila->setRelation('cuentas', $cuentasPorCliente->get($fila->id, collect()));
        }
        $actividadPeriodo->anotar($clientes->getCollection(), $periodoActividad, $rol);

        return view('clientes-maestro.index', [
            'periodoActividad' => $periodoActividad,
            'periodosActividad' => $periodosActividad,
            'clientes' => $clientes,
            'sedesPermitidas' => $sedesPermitidas,
            'sede' => $sede,
            'rol' => $rol,
            'estado' => $estado,
            'buscar' => $buscar,
            'puedeVerSinCuenta' => $puedeVerSinCuenta,
        ]);
    }

    /** Ficha completa operativa, siempre limitada por las cuentas autorizadas. */
    public function show(
        Request $request, CuentaCorrienteMovimientosClienteService $movimientosOperativos,
        ClienteMaestroDocumentosService $documentos,
        ClienteMaestroRelacionesService $relaciones,
        ClienteActividadOperativaPeriodoService $actividadPeriodo, int $cliente
    ): View
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();
        abort_unless($usuario instanceof Usuario
            && $usuario->activo
            && $usuario->perfil?->puedeVerModulo('ARCHIVO_CLIENTES'), 403);

        $sedes = array_values(array_intersect(['SF', 'ST'], array_map('strtoupper', $usuario->codigosSucursales())));
        abort_if($sedes === [], 403);
        // La sede del enlace nunca puede ampliar las autorizadas para el usuario.
        $sedeElegida = strtoupper(trim((string) $request->query('sede', 'TODAS')));
        if (count($sedes) === 1) {
            $sedeElegida = $sedes[0];
        } elseif (! in_array($sedeElegida, $sedes, true)) {
            $sedeElegida = 'TODAS';
        }
        $sedesVisibles = $sedeElegida === 'TODAS' ? $sedes : [$sedeElegida];
        $prefijos = [];
        foreach ($sedesVisibles as $codigoSede) {
            array_push($prefijos, ...($codigoSede === 'SF' ? ['1103', '1202'] : ['2103', '2202']));
        }
        $administrador = Gate::allows('administrar-unificaciones')
            && in_array('SF', $sedes, true) && in_array('ST', $sedes, true);

        $consulta = Cliente::query()->whereNull('id_cliente_canonico');
        if (! $administrador) {
            $consulta->whereExists(static function ($sub) use ($prefijos): void {
                $sub->selectRaw('1')->from('clientes_cuentas as cc')
                    ->join('clientes as origen', 'origen.id', '=', 'cc.cliente_id')
                    ->whereRaw('COALESCE(origen.id_cliente_canonico, origen.id) = clientes.id')
                    ->whereIn(DB::raw('LEFT(cc.cuenta, 4)'), $prefijos);
            });
        }
        $operativo = $consulta->findOrFail($cliente);
        $cuentas = ClienteCuenta::query()
            ->from('clientes_cuentas as cc')
            ->join('clientes as origen', 'origen.id', '=', 'cc.cliente_id')
            ->whereRaw('COALESCE(origen.id_cliente_canonico, origen.id) = ?', [$operativo->id])
            ->whereIn(DB::raw('LEFT(cc.cuenta, 4)'), $prefijos)
            ->select(['cc.id', 'cc.cliente_id', 'cc.cuenta', 'cc.rol', 'cc.activo'])
            ->orderBy('cc.rol')->orderBy('cc.cuenta')->get();
        abort_if($cuentas->isEmpty() && ! $administrador, 403);
        [$periodoActividad, $periodosActividad] = $actividadPeriodo->seleccionarPeriodo($request);
        $operativo->setRelation('cuentas', $cuentas); // sólo las cuentas visibles

        $tab = (string) $request->query('tab', 'datos');
        if (! in_array($tab, ['datos', 'facturacion', 'inmuebles', 'cuenta-corriente', 'liquidaciones', 'impuestos', 'facturas'], true)) {
            $tab = 'datos';
        }
        // Impedir también el acceso directo a pestañas no aplicables.
        if (! $cuentas->contains('rol', 'PROPIETARIO')
            && in_array($tab, ['liquidaciones', 'impuestos'], true)) {
            $tab = 'datos';
        }

        // La navegación mensual no necesita períodos de identidades reconstruidas.
        // Ninguna pestaña consulta identidades reconstruidas.
        $periodo = '';
        $solicitado = (string) $request->query('periodo', '');
        if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/D', $solicitado) === 1) {
            $periodo = $solicitado;
        }
        $periodos = [];
        $mes = preg_replace('/[^0-9]/', '', (string) $request->query('mes', now()->format('Ym'))) ?: '';
        if (! preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $mes)) {
            $mes = now()->format('Ym');
        }
        // En Cuenta corriente la consulta comienza en el mes en curso.
        // Si el usuario selecciona un mes anterior, se respeta su elección.
        // No se ofrecen meses futuros: no hay un cierre mensual verificado allí.
        if ($tab === 'cuenta-corriente' && $mes > now()->format('Ym')) {
            $mes = now()->format('Ym');
        }

        // Menú lateral: misma frontera de visibilidad que la ficha. 40 filas por
        // página y consultas livianas: no cargar documentos de toda la nómina.
        $filtroRol = strtoupper(trim((string) $request->query('rol', 'TODOS')));
        if (! in_array($filtroRol, ['TODOS', 'INQUILINO', 'PROPIETARIO'], true)) {
            $filtroRol = 'TODOS';
        }
        $actividadPeriodo->anotar(collect([$operativo]), $periodoActividad, $filtroRol);
        $filtroEstado = strtolower(trim((string) $request->query('estado', 'activos')));
        if (! in_array($filtroEstado, ['todos', 'activos', 'inactivos'], true)) {
            $filtroEstado = 'activos';
        }
        $buscarLateral = mb_substr(trim((string) $request->query('buscar', '')), 0, 120);
        $tieneCuentasVisibles = static function ($query) use ($prefijos, $filtroRol): void {
            $query->whereExists(static function ($sub) use ($prefijos, $filtroRol): void {
                $sub->selectRaw('1')
                    ->from('clientes_cuentas as cc')
                    ->join('clientes as origen', 'origen.id', '=', 'cc.cliente_id')
                    ->whereRaw('COALESCE(origen.id_cliente_canonico, origen.id) = clientes.id')
                    ->whereIn(DB::raw('LEFT(cc.cuenta, 4)'), $prefijos);
                if ($filtroRol !== 'TODOS') {
                    $sub->where('cc.rol', $filtroRol);
                }
            });
        };
        $listaLateral = Cliente::query()->whereNull('id_cliente_canonico');
        // Sólo el administrador de ambas sedes puede ver personas sin cuenta.
        if (! ($administrador && $sedeElegida === 'TODAS' && $filtroRol === 'TODOS')) {
            $tieneCuentasVisibles($listaLateral);
        }
        $actividadPeriodo->filtrar(
            $listaLateral, $filtroEstado, $periodoActividad, $prefijos, $filtroRol
        );
        if ($buscarLateral !== '') {
            $termino = '%'.$buscarLateral.'%';
            $digitos = preg_replace('/[^0-9]/', '', $buscarLateral) ?: '';
            $listaLateral->where(static function ($q) use ($termino, $digitos, $prefijos, $filtroRol): void {
                $q->where('nombre', 'ILIKE', $termino)
                    ->orWhere('cuit', 'ILIKE', $termino)
                    ->orWhere('numero_documento', 'ILIKE', $termino)
                    ->orWhere('email', 'ILIKE', $termino);
                if ($digitos !== '') {
                    $q->orWhereExists(static function ($sub) use ($prefijos, $digitos, $filtroRol): void {
                        $sub->selectRaw('1')->from('clientes_cuentas as cc')
                            ->join('clientes as origen', 'origen.id', '=', 'cc.cliente_id')
                            ->whereRaw('COALESCE(origen.id_cliente_canonico, origen.id) = clientes.id')
                            ->whereIn(DB::raw('LEFT(cc.cuenta, 4)'), $prefijos)
                            ->where('cc.cuenta', 'LIKE', '%'.$digitos.'%');
                        if ($filtroRol !== 'TODOS') {
                            $sub->where('cc.rol', $filtroRol);
                        }
                    });
                }
            });
        }
        $clientesLateral = $listaLateral
            ->orderByRaw("TRANSLATE(LOWER(TRIM(nombre)), 'áéíóúüñ', 'aeiouun')")
            ->orderBy('id')->paginate(40)->withQueryString();

        // Facturación operativa sólo de las cuentas COBOL que el usuario puede
        // consultar. Nunca se recupera por CUIT, nombre ni cliente_id aislado.
        $facturacionesVisibles = collect();
        $beneficiariosFacturacion = collect();
        if ($tab === 'facturacion' && $cuentas->isNotEmpty()) {
            // Las cuentas históricas pueden conservar cliente_id y
            // cliente_cuenta_id NULL. Los dos identificadores no sirven como
            // requisito de visibilidad. $cuentas ya fue restringida al
            // cliente canónico, su rol y las sucursales permitidas.
            // Se exige coincidencia EXACTA (dominio, cuenta); nunca CUIT.
            // La base garantiza unicidad de (dominio, cuenta).
            $facturacionesVisibles = DB::table('cuentas_corrientes')
                ->where(static function ($q) use ($cuentas): void {
                    foreach ($cuentas as $cuenta) {
                        $q->orWhere(static function ($sub) use ($cuenta): void {
                            $sub->where('cuenta', $cuenta->cuenta)->where('dominio', $cuenta->rol);
                        });
                    }
                })
                ->orderBy('cuenta')->get([
                    'id', 'cuenta', 'dominio', 'activo', 'facturable',
                    'modalidad_facturacion', 'destinatario_facturacion',
                    'agrupacion_facturacion', 'tratamiento_fiscal',
                    'observaciones_facturacion',
                ]);
            if ($facturacionesVisibles->isNotEmpty()) {
                $beneficiariosFacturacion = DB::table('cuentas_facturacion_beneficiarios as b')
                    ->leftJoin('clientes as c', 'c.id', '=', 'b.cliente_id')
                    ->whereIn('b.cuenta_corriente_id', $facturacionesVisibles->pluck('id')->all())
                    ->orderBy('b.cuenta_corriente_id')
                    ->get(['b.id_cuenta_facturacion_beneficiario', 'b.cuenta_corriente_id',
                           'b.cliente_id', 'b.identificador_origen', 'b.porcentaje',
                           'b.activo', 'c.nombre as beneficiario_nombre'])
                    ->groupBy('cuenta_corriente_id');
            }
        }

        // Las relaciones operativas se consultan por cuentas y roles autorizados,
        // no por el ID aislado del cliente ni por CUIT. Sólo al abrir esta pestaña.
        $vinculosOperativos = $tab === 'inmuebles'
            ? $relaciones->consultar($cuentas, $prefijos)
            : ['inmuebles' => collect(), 'repartos' => collect(),
               'inquilinos' => collect(), 'contratos' => collect()];

        // GEI_FICHA_CONTRATO_AMBOS_ROLES_20261030
        // Ficha COBOL por PAR de cuentas, sólo para vínculos operativos visibles.
        // No se ejecuta en el listado ni en otras pestañas.
        $fichasContratos = [];
        $fichasContratosError = null;
        if ($tab === 'inmuebles' && $cuentas->isNotEmpty()) {
            $datosFicha = app(ClienteMaestroFichaContratoCobolService::class)
                ->consultar($cuentas, $vinculosOperativos);
            $fichasContratos = $datosFicha['fichas'];
            $fichasContratosError = $datosFicha['error'];
        }

        // La identidad y la visibilidad provienen del maestro operativo.
        // Para la cuenta corriente se consultará por (dominio, cuenta) exactos.
        $actividad = collect();

        // Documentación operativa: no depende de que exista un snapshot Core.
        // Se consultan exclusivamente las cuentas COBOL del cliente y sede visibles.
        if (in_array($tab, ['liquidaciones', 'impuestos'], true)) {
            $cuentasProp = $cuentas->where('rol', 'PROPIETARIO')->pluck('cuenta')->unique()->values()->all();
            $actividad = $documentos->liquidaciones($cuentasProp, $mes, $tab === 'impuestos');
        } elseif ($tab === 'facturas') {
            $actividad = $documentos->comprobantes($cuentas->pluck('cuenta')->unique()->values()->all(), $mes);
        }


        // Reconstrucción CONTABLE GIMB68 del cierre PRUEBA COBOL para cuentas
        // inquilinas autorizadas. Misma fuente y regla que Autogestión.
        // Se conserva el circuito legado para cuentas no cubiertas.
        $cuentasCobolGimb68 = collect();
        $incidenciasCobolGimb68 = [];
        if ($tab === 'cuenta-corriente') {
            $lecturaCobolGimb68 = app(\App\Services\CuentaCorrienteCobolGimb68ConsultaService::class)
                ->consultar($cuentas, $mes);
            $cuentasCobolGimb68 = $lecturaCobolGimb68['cuentas'];
            $incidenciasCobolGimb68 = $lecturaCobolGimb68['incidencias'];
        }

        // Saldo anterior/acumulado de las cuentas OPERATIVAS autorizadas. No
        // recalcula cargos, intereses ni reemplaza el cierre real del COBOL.
        $resumenSaldosCuentas = collect();
        $diagnosticoIntereses = null;
        $errorIntereses = null;
        $fechaCalculoIntereses = '';
        $cuentaSeleccionadaIntereses = '';
        $cuentasInquilinasSimulables = collect();
        $mostrarInteresesPrueba = app()->environment('local', 'testing')
            && (bool) config('portal.cuenta_corriente.web_prueba', false);
        if ($tab === 'cuenta-corriente') {
            $resumenSaldosCuentas = app(\App\Services\CuentaCorrienteSaldosClienteService::class)
                ->consultar($cuentas, $mes);

            if ($mostrarInteresesPrueba) {
                // GIMB38 es para deudas del INQUILINO, no del PROPIETARIO.
                // Por ahora, el cierre PRUEBA sólo está habilitado en SF.
                $cuentasInquilinasSimulables = $cuentas
                    ->where('rol', 'INQUILINO')
                    ->pluck('cuenta')
                    ->filter(static fn ($c): bool => str_starts_with((string) $c, '1103'))
                    ->unique()->values();
                $solicitada = trim((string) $request->query('cuenta_intereses', ''));
                abort_if($solicitada !== '' && ! $cuentasInquilinasSimulables->contains($solicitada), 404);
                // La eleccion manual siempre prevalece. Si no se eligio
                // una cuenta, priorizar la que tiene actividad en el mes
                // consultado, desde el mismo cierre GIMB68 ya recuperado.
                $cuentaSeleccionadaIntereses = $solicitada !== ''
                    ? $solicitada
                    : \App\Services\SeleccionCuentaInteresesService::elegir(
                        $cuentasInquilinasSimulables, $cuentasCobolGimb68
                    );
                if ($cuentaSeleccionadaIntereses !== '') {
                    try {
                        $portalCta = app(\App\Services\Portal\PortalCuentaCorrienteService::class);
                        $snapshotIntereses = $portalCta->consultarEnPrueba($cuentaSeleccionadaIntereses, 'INQUILINO');
                        $fechaCalculoIntereses = trim((string) $request->query('fecha_calculo', ''));
                        if ($fechaCalculoIntereses === '') {
                            // Por defecto, liquidar la SIMULACION hasta ayer
                            // (día calendario de Santa Fe), no hasta el cierre.
                            $fechaCalculoIntereses = \Carbon\CarbonImmutable::now('America/Argentina/Cordoba')
                                ->subDay()->toDateString();
                        }
                        $diagnosticoIntereses = app(\App\Services\CuentaInteresesGimb38DiagnosticoService::class)
                            ->simular($portalCta, $snapshotIntereses, $cuentaSeleccionadaIntereses, $fechaCalculoIntereses);
                    } catch (\Throwable $error) {
                        report($error);
                        // No revelar SQL, rutas internas ni archivos de parámetros en pantalla.
                        $errorIntereses = 'no existe un cierre PRUEBA conciliado o faltan parámetros COBOL válidos';
                    }
                }
            }
        }

        if ($tab === 'cuenta-corriente') {
            // Sólo movimientos de cuentas sin panel del cierre COBOL: no duplicar
            // importes ni presentar dos saldos para un mismo rol + cuenta.
            $cuentasSinCierre = $cuentas->reject(
                static fn ($c): bool => $cuentasCobolGimb68->has($c->rol.'|'.$c->cuenta)
            )->values();
            $actividad = $movimientosOperativos->consultar($cuentasSinCierre, $mes);
        }

        return view('clientes-maestro.show', [
            'operativo' => $operativo,
            'clientesLateral' => $clientesLateral,
            'periodoActividad' => $periodoActividad,
            'periodosActividad' => $periodosActividad,
            'sedeElegida' => $sedeElegida,
            'sedesPermitidas' => $sedes,
            'filtroRol' => $filtroRol,
            'filtroEstado' => $filtroEstado,
            'buscarLateral' => $buscarLateral,
            'facturacionesVisibles' => $facturacionesVisibles,
            'beneficiariosFacturacion' => $beneficiariosFacturacion,
            'cuentas' => $cuentas,
            'vinculosOperativos' => $vinculosOperativos,
            'fichasContratos' => $fichasContratos,
            'fichasContratosError' => $fichasContratosError,
            'periodos' => $periodos,
            'periodo' => $periodo,
            'tab' => $tab,
            'mes' => $mes,
            'actividad' => $actividad,
            'resumenSaldosCuentas' => $resumenSaldosCuentas,
            'cuentasCobolGimb68' => $cuentasCobolGimb68,
            'incidenciasCobolGimb68' => $incidenciasCobolGimb68,
            'diagnosticoIntereses' => $diagnosticoIntereses,
            'errorIntereses' => $errorIntereses,
            'fechaCalculoIntereses' => $fechaCalculoIntereses,
            'cuentaSeleccionadaIntereses' => $cuentaSeleccionadaIntereses,
            'cuentasInquilinasSimulables' => $cuentasInquilinasSimulables,
            'mostrarInteresesPrueba' => $mostrarInteresesPrueba,
            'administrador' => $administrador,
            'prefijosVisibles' => $prefijos,
            'volver' => $request->only(['buscar', 'rol', 'estado', 'sede', 'page', 'periodo_actividad']),
        ]);
    }

    /** Editar facturación de una cuenta CANÓNICA autorizada por sede y rol. */
    public function actualizarFacturacion(Request $request, int $cliente, int $cuentaCorriente): RedirectResponse
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();
        abort_unless($usuario instanceof Usuario && $usuario->activo
            && $usuario->perfil?->puedeVerModulo('ARCHIVO_CLIENTES')
            && Gate::allows('administrar-unificaciones'), 403);
        abort_unless(Cliente::query()->whereNull('id_cliente_canonico')->whereKey($cliente)->exists(), 404);

        $cuenta = DB::table('cuentas_corrientes')->where('id', $cuentaCorriente)->first();
        abort_if($cuenta === null, 404);
        $cuentaCobol = (string) $cuenta->cuenta;
        $rolCuenta = (string) $cuenta->dominio;
        // La sede de la cuenta debe estar en el alcance del usuario, aun si
        // su cliente tiene otras cuentas en distintas sucursales.
        $sedeCuenta = match (substr($cuentaCobol, 0, 4)) {
            '1103', '1202' => 'SF',
            '2103', '2202' => 'ST',
            default => null,
        };
        abort_unless($sedeCuenta !== null
            && in_array($sedeCuenta, $usuario->codigosSucursales(), true), 403);
        $this->cuentaAutorizada($request, $cliente, $cuentaCobol, $rolCuenta);

        $data = $request->validate([
            'facturable' => ['required', 'boolean'],
            'modalidad_facturacion' => ['required', 'in:NORMAL,INDIVIDUAL'],
            'destinatario_facturacion' => ['required', 'in:PROPIETARIO,COPROPIETARIOS'],
            'agrupacion_facturacion' => ['required', 'in:CONSOLIDADA,POR_INMUEBLE,POR_MOVIMIENTO'],
            'tratamiento_fiscal' => ['required', 'in:AUTOMATICO,FORZAR_A,FORZAR_B,SIN_CATEGORIZAR'],
            'observaciones_facturacion' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['updated_at'] = now();
        DB::table('cuentas_corrientes')->where('id', $cuentaCorriente)
            ->where('cuenta', $cuentaCobol)->where('dominio', $rolCuenta)
            ->update($data);

        $retorno = ['cliente' => $cliente, 'tab' => 'facturacion'];
        foreach (['buscar', 'rol', 'estado', 'sede', 'page', 'mes'] as $campo) {
            $valor = $request->input('volver_'.$campo);
            if (is_scalar($valor) && (string) $valor !== '') {
                $retorno[$campo] = (string) $valor;
            }
        }
        return redirect()->route('clientes-maestro.show', $retorno)
            ->with('estado', 'Configuración de facturación actualizada.');
    }

    /** Actualiza un beneficiario sin aceptar otro cliente o sucursal. */
    public function actualizarBeneficiarioFacturacion(Request $request, int $cliente, int $beneficiario): RedirectResponse
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();
        abort_unless($usuario instanceof Usuario && $usuario->activo
            && $usuario->perfil?->puedeVerModulo('ARCHIVO_CLIENTES')
            && Gate::allows('administrar-unificaciones'), 403);
        abort_unless(Cliente::query()->whereNull('id_cliente_canonico')->whereKey($cliente)->exists(), 404);

        $registro = DB::table('cuentas_facturacion_beneficiarios as b')
            ->join('cuentas_corrientes as cc', 'cc.id', '=', 'b.cuenta_corriente_id')
            ->where('b.id_cuenta_facturacion_beneficiario', $beneficiario)
            ->select('cc.cuenta', 'cc.dominio')
            ->first();
        abort_if($registro === null, 404);
        $this->cuentaAutorizada($request, $cliente, (string)$registro->cuenta, (string)$registro->dominio);

        $data = $request->validate(['porcentaje' => ['required', 'numeric', 'gt:0', 'lte:100']]);
        DB::table('cuentas_facturacion_beneficiarios')
            ->where('id_cuenta_facturacion_beneficiario', $beneficiario)
            ->update(['porcentaje' => $data['porcentaje'],
                      'activo' => $request->boolean('activo'), 'updated_at' => now()]);

        $retorno = ['cliente' => $cliente, 'tab' => 'facturacion'];
        foreach (['buscar', 'rol', 'estado', 'sede', 'page', 'mes'] as $campo) {
            $valor = $request->input('volver_'.$campo);
            if (is_scalar($valor) && (string)$valor !== '') {
                $retorno[$campo] = (string)$valor;
            }
        }
        return redirect()->route('clientes-maestro.show', $retorno)
            ->with('estado', 'Beneficiario de facturación actualizado.');
    }

    /** Vuelve a validar cliente, cuenta y sucursal en CADA solicitud a un PDF. */
    private function cuentaAutorizada(Request $request, int $cliente, string $cuenta, string $rol): void
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();
        abort_unless($usuario instanceof Usuario && $usuario->activo
            && $usuario->perfil?->puedeVerModulo('ARCHIVO_CLIENTES'), 403);
        $sede = match (substr($cuenta, 0, 4)) {
            '1103', '1202' => 'SF',
            '2103', '2202' => 'ST',
            default => null,
        };
        $rolPorPrefijo = match (substr($cuenta, 0, 4)) {
            '1103', '2103' => 'INQUILINO',
            '1202', '2202' => 'PROPIETARIO',
            default => null,
        };
        abort_unless($sede !== null && $rol === $rolPorPrefijo
            && in_array($sede, $usuario->codigosSucursales(), true), 403);
        abort_unless(Cliente::query()->whereNull('id_cliente_canonico')->whereKey($cliente)->exists(), 404);
        abort_unless(DB::table('clientes_cuentas as cc')
            ->join('clientes as origen', 'origen.id', '=', 'cc.cliente_id')
            ->whereRaw('COALESCE(origen.id_cliente_canonico, origen.id) = ?', [$cliente])
            ->where('cc.cuenta', $cuenta)->where('cc.rol', $rol)->exists(), 403);
    }

    public function pdfLiquidacion(
        Request $request, ImpuestosGarantizadosPdfService $impuestos,
        int $cliente, int $liquidacion, string $tipo
    ): StreamedResponse {
        abort_unless(in_array($tipo, ['liquidacion', 'impuestos'], true), 404);
        $fila = DB::table('liquidaciones_propietarios')->where('id', $liquidacion)->first();
        abort_if($fila === null, 404);
        $cuenta = (string) $fila->cuenta;
        $this->cuentaAutorizada($request, $cliente, $cuenta, 'PROPIETARIO');
        // La sede impresa en el documento no debe contradecir la cuenta COBOL.
        $sede = str_starts_with($cuenta, '12') ? 'SF' : 'ST';
        abort_unless(trim((string) $fila->sede) === $sede, 404);
        if ($tipo === 'impuestos') {
            $cuentaImpresa = preg_replace('/[^0-9]/', '', (string) ($fila->cuenta_impresa ?: $cuenta));
            abort_unless($cuentaImpresa === $cuenta, 404);
            $ruta = $impuestos->rutaPdfParaLiquidacion(
                (string) $fila->periodo,
                $fila->pdf_ruta ? (string) $fila->pdf_ruta : null,
                (string) ($fila->cuenta_impresa ?: $cuenta)
            );
        } else {
            $ruta = $fila->estado === 'PDF_GENERADO' ? $fila->pdf_ruta : null;
        }
        abort_if($ruta === null || ! Storage::disk('liquidaciones')->exists((string) $ruta), 404);
        return Storage::disk('liquidaciones')->response(
            (string) $ruta, basename((string) $ruta), ['Content-Type' => 'application/pdf'], 'inline'
        );
    }

    /** PDF de KNG: cuenta destinataria exacta, cliente canónico, sede y registro fiscal. */
    public function pdfArca(
        Request $request, ComprobantesArcaService $arca,
        int $cliente, string $periodo, string $archivo
    ): BinaryFileResponse {
        abort_unless(
            preg_match('/^(FA|CA|FB|CB)-[0-9]{4}-[0-9]{8}-([0-9]{11})\.pdf$/i', $archivo, $partes) === 1,
            404
        );
        abort_unless(preg_match('/^(19|20)[0-9]{2}(0[1-9]|1[0-2])$/', $periodo) === 1, 404);

        $cuenta = $partes[2];
        $rol = match (substr($cuenta, 0, 4)) {
            '1103', '2103' => 'INQUILINO',
            '1202', '2202' => 'PROPIETARIO',
            default => null,
        };
        abort_if($rol === null, 403);
        $this->cuentaAutorizada($request, $cliente, $cuenta, $rol);

        // Verificar también que esa factura exista en la fuente fiscal KNG del
        // período y tenga esa cuenta como destinataria. Un PDF por nombre solo
        // o una coincidencia por CUIT no son autorización suficiente.
        $fila = $arca->paraCuentaPeriodo($cuenta, $periodo)
            ->first(static fn (object $f): bool =>
                (string) ($f->nombre_archivo ?? '') === $archivo
                && (bool) ($f->pdf_disponible ?? false)
                && ! empty($f->ruta_fisica)
            );
        abort_if($fila === null, 404);

        $ruta = realpath((string) $fila->ruta_fisica);
        abort_if($ruta === false || ! is_file($ruta) || ! is_readable($ruta), 404);

        return response()->file($ruta, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$archivo.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Consulta histórica por cuenta exacta, independiente de la ficha global Core. */
    public function historico(
        Request $request,
        HistoricoCobolCuentaService $historico,
        int $cliente,
        string $rol,
        string $cuenta
    ): View {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();
        abort_unless(
            $usuario instanceof Usuario
                && $usuario->activo
                && $usuario->perfil?->puedeVerModulo('ARCHIVO_CLIENTES'),
            403
        );

        $sedeCuenta = match (substr($cuenta, 0, 4)) {
            '1103', '1202' => 'SF',
            '2103', '2202' => 'ST',
            default => null,
        };
        $rolEsperado = match (substr($cuenta, 0, 4)) {
            '1103', '2103' => 'INQUILINO',
            '1202', '2202' => 'PROPIETARIO',
            default => null,
        };
        abort_unless(
            $sedeCuenta !== null
                && $rol === $rolEsperado
                && in_array($sedeCuenta, $usuario->codigosSucursales(), true),
            403
        );

        $clienteOperativo = Cliente::query()
            ->whereNull('id_cliente_canonico')
            ->findOrFail($cliente);

        // Se exige vínculo cliente canónico + rol + cuenta exacta ANTES de abrir
        // la conexión histórica. Ni el CUIT ni el nombre sirven como permiso.
        $pertenece = DB::table('clientes_cuentas as cc')
            ->join('clientes as c', 'c.id', '=', 'cc.cliente_id')
            ->whereRaw('COALESCE(c.id_cliente_canonico, c.id) = ?', [$cliente])
            ->where('cc.rol', $rol)
            ->where('cc.cuenta', $cuenta)
            ->exists();
        abort_unless($pertenece, 403);

        return view($request->boolean('fragmento')
            ? 'clientes-maestro.partials.historial-cuenta'
            : 'clientes-maestro.historico', [
            'clienteOperativo' => $clienteOperativo,
            'cuenta' => $cuenta,
            'rol' => $rol,
            'sedeCuenta' => $sedeCuenta,
            'registros' => $historico->consultar($rol, $cuenta),
        ]);
    }
}
