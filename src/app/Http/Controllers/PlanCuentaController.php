<?php

namespace App\Http\Controllers;

use App\Models\CuentaCaja;
use App\Models\CuentaContable;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PlanCuentaController extends Controller
{
    public function index(Request $request): View
    {
        $buscar = trim((string) $request->query('buscar', ''));
        $naturaleza = strtoupper(trim((string) $request->query('naturaleza', '')));
        $imputable = trim((string) $request->query('imputable', ''));

        $cuentas = CuentaContable::query()
            ->with([
                'cuentasCaja' => static fn ($q) => $q
                    ->with('imputacionesConceptos.concepto')
                    ->orderBy('codigo_cobol'),
            ])
            ->when($buscar !== '', function ($q) use ($buscar): void {
                $q->where(function ($s) use ($buscar): void {
                    $s->where('codigo', 'like', '%'.$buscar.'%')
                        ->orWhere('descripcion', 'ilike', '%'.$buscar.'%')
                        ->orWhereHas('cuentasCaja', function ($c) use ($buscar): void {
                            $c->where('codigo_cobol', 'like', '%'.$buscar.'%')
                                ->orWhere('numero_contable', 'like', '%'.$buscar.'%')
                                ->orWhere('nombre', 'ilike', '%'.$buscar.'%')
                                ->orWhere('subcuenta', 'ilike', '%'.$buscar.'%');
                        });
                });
            })
            ->when(
                in_array($naturaleza, ['DEBE', 'HABER', 'VARIABLE'], true),
                fn ($q) => $q->where('naturaleza', $naturaleza)
            )
            ->when($imputable === '1', fn ($q) => $q->where('imputable', true))
            ->when($imputable === '0', fn ($q) => $q->where('imputable', false))
            ->orderBy('codigo')
            ->paginate(50, ['*'], 'pagina')
            ->withQueryString();

        $pendientes = CuentaCaja::query()
            ->whereNotNull('numero_contable')
            ->where('numero_contable', '<>', '')
            ->whereNull('cuenta_contable_id')
            ->with('imputacionesConceptos.concepto')
            ->orderBy('numero_contable')
            ->orderBy('codigo_cobol')
            ->get();

        $resumen = [
            'cuentas' => CuentaContable::count(),
            'imputables' => CuentaContable::where('imputable', true)->count(),
            'cajas_vinculadas' => CuentaCaja::whereNotNull('cuenta_contable_id')->count(),
            'referencias_pendientes' => $pendientes->count(),
        ];

        return view('contabilidad.plan-cuentas', compact(
            'cuentas',
            'pendientes',
            'resumen',
            'buscar',
            'naturaleza',
            'imputable'
        ));
    }
}
