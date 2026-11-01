<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\CuentaCorrienteOperativaConsultaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

final class CuentaCorrienteExploracionController extends Controller
{
    public function __construct(
        private readonly CuentaCorrienteOperativaConsultaService $service
    ) {
    }

    public function index(Request $request): View
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();
        abort_unless(
            $usuario instanceof Usuario
                && $usuario->activo
                && $usuario->perfil?->puedeVerModulo('ARCHIVO_CLIENTES'),
            403
        );

        $sedes = array_values(array_intersect(
            ['SF', 'ST'],
            array_map('strtoupper', $usuario->codigosSucursales())
        ));
        $prefijos = CuentaCorrienteOperativaConsultaService::prefijosSedes($sedes);
        abort_if($prefijos === [], 403, 'El usuario no tiene sedes autorizadas.');

        $tipo = CuentaCorrienteOperativaConsultaService::tipoValido(
            (string) $request->query('tipo', 'PROPIETARIO')
        );
        $estado = (string) $request->query('estado', 'todas');
        $estado = in_array($estado, ['activas', 'todas'], true) ? $estado : 'todas';
        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 120);
        $orden = strtolower((string) $request->query('orden', 'desc'));
        $orden = $orden === 'asc' ? 'asc' : 'desc';

        // GEI_ACTIVIDAD_CUENTAS_POR_PERIODO_20261030
        // Actividad real por período, separada de la baja administrativa.
        $periodoDefault = (string) (DB::table('liquidaciones_propietarios')->max('periodo') ?? now()->format('Ym'));
        $periodo = (string) $request->query('periodo', $periodoDefault);
        abort_unless(preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) === 1, 422);
        $periodos = DB::table('liquidaciones_propietarios')->distinct()
            ->orderByDesc('periodo')->pluck('periodo')->map(fn ($p) => (string) $p)->all();
        abort_unless(in_array($periodo, $periodos, true), 404, 'No hay liquidaciones para ese período.');
        // GEI_ACTIVIDAD_DB_PERIODO_20261030
        // Un registro de control implica recálculo COMPLETO para el período.
        // Nunca ejecutar el cálculo COBOL/ARCA durante la solicitud HTTP.
        abort_unless(DB::table('cuentas_actividad_periodos_control')
            ->where('periodo', $periodo)->exists(), 503,
            'Actividad sin calcular para este período. Ejecutar gei:actualizar-actividad-cuentas.');

        $detalle = null;
        $seleccionar = (int) $request->query('seleccionar', 0);
        if ($seleccionar > 0) {
            $detalle = $this->service->cuentaDetalle($seleccionar, $tipo, $prefijos, $orden);
            $detalle['cuenta']->activa_periodo = DB::table('cuentas_actividad_periodos')
                ->where('periodo', $periodo)
                ->where('dominio', $tipo)
                ->where('cuenta', $detalle['cuenta']->cuenta_cobol)
                ->where('con_actividad', true)
                ->exists();
        }

        return view('cuentas-corrientes.index', [
            'tipo' => $tipo,
            'estado' => $estado,
            'buscar' => $buscar,
            'orden' => $orden,
            'periodo' => $periodo,
            'periodos' => $periodos,
            'resumen' => $this->service->cuentasResumen($tipo, $prefijos, $periodo),
            'cuentas' => $this->service->cuentas($tipo, $estado, $buscar, $prefijos, $periodo),
            'detalle' => $detalle,
        ]);
    }
}
