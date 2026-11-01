<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\InmuebleMaestroConsultaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Solo lectura. El maestro operativo es gei_db.public.inmuebles.
 * Las sedes sin asignación NO se infieren silenciosamente.
 */
final class InmuebleMaestroController extends Controller
{
    public function __construct(
        private readonly InmuebleMaestroConsultaService $consulta,
    ) {
    }

    public function index(Request $request): View
    {
        /** @var Usuario|null $usuario */
        $usuario = $request->user();
        abort_unless(
            $usuario instanceof Usuario
                && $usuario->activo
                && $usuario->perfil?->puedeVerModulo('ARCHIVO_INMUEBLES'),
            403
        );

        $permitidas = array_values(array_intersect(
            ['SF', 'ST'],
            array_map('strtoupper', $usuario->codigosSucursales())
        ));
        abort_if($permitidas === [], 403);

        $administradorGlobal = Gate::allows('administrar-unificaciones')
            && in_array('SF', $permitidas, true)
            && in_array('ST', $permitidas, true);

        $sede = strtoupper(trim((string) $request->query('sede', 'TODAS')));
        if (count($permitidas) === 1) {
            $sede = $permitidas[0];
        } elseif ($sede === 'SIN_SEDE' && ! $administradorGlobal) {
            abort(403);
        } elseif (! in_array($sede, [...$permitidas, 'TODAS', 'SIN_SEDE'], true)) {
            $sede = 'TODAS';
        }

        $visibles = $sede === 'TODAS' || $sede === 'SIN_SEDE' ? $permitidas : [$sede];
        $prefijos = $this->consulta->prefijosDeSedes($visibles);

        $estado = strtoupper(trim((string) $request->query('estado', 'ACTIVO')));
        if (! in_array($estado, ['ACTIVO', 'INACTIVO', 'DESCONOCIDO', 'TODOS'], true)) {
            $estado = 'ACTIVO';
        }

        $revision = strtolower(trim((string) $request->query('revision', '')));
        if ($revision === 'duplicados') {
            // El cotejo entre fichas de diferentes cuentas queda sólo para auditoría.
            abort_unless($administradorGlobal, 403);
        } elseif ($revision !== '') {
            $revision = '';
        }

        $buscar = mb_substr(trim((string) $request->query('buscar', '')), 0, 120);
        $seleccionar = (int) $request->query('seleccionar', 0);

        // El detalle vuelve a aplicar el mismo alcance: no se autoriza por ID solo.
        $resultado = $this->consulta->consultar(
            $visibles, $prefijos, $sede, $estado, $buscar, $seleccionar,
            $administradorGlobal
        );

        // Se consultan únicamente tablas public; nunca se resuelve identidad
        // de un inmueble por similitud de domicilio sin revisión humana.
        $coincidencias = $revision === 'duplicados'
            ? $this->consulta->posiblesDuplicados($visibles, $sede, $administradorGlobal)
            : [];

        return view('inmuebles-maestro.index', [
            ...$resultado,
            'sedesPermitidas' => $permitidas,
            'sede' => $sede,
            'estado' => $estado,
            'buscar' => $buscar,
            'administradorGlobal' => $administradorGlobal,
            'revision' => $revision,
            'coincidencias' => $coincidencias,
        ]);
    }
}
