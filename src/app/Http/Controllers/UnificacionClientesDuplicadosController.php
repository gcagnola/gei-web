<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\UnificacionClientesService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Candidatos de identidad del maestro operativo de gei_db.public.clientes.
 * La pantalla es informativa: nunca unifica automáticamente.
 */
final class UnificacionClientesDuplicadosController extends Controller
{
    public function __construct(private readonly UnificacionClientesService $unificacion)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('administrar-unificaciones');

        $usuario = $request->user();
        abort_unless($usuario instanceof Usuario, 401);
        $sedes = $usuario->codigosSucursales();
        abort_if($sedes === [], 403, 'El usuario no tiene sucursales asignadas.');

        $prefijos = [];
        if (in_array('SF', $sedes, true)) {
            array_push($prefijos, '1103', '1202');
        }
        if (in_array('ST', $sedes, true)) {
            array_push($prefijos, '2103', '2202');
        }
        abort_if($prefijos === [], 403);

        $datos = $request->validate([
            'buscar' => ['nullable', 'string', 'max:180'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $buscar = trim((string) ($datos['buscar'] ?? ''));

        // El detector existente ya excluye pares unificados o marcados
        // como personas diferentes en unificaciones_candidatos.
        $candidatos = $this->unificacion->candidatos();
        $ids = $candidatos->flatMap(
            static fn ($par): array => [(int) $par->id_a, (int) $par->id_b]
        )->unique()->values()->all();

        $clientes = collect();
        $cuentas = collect();
        foreach (array_chunk($ids, 1000) as $lote) {
            $clientes = $clientes->merge(
                DB::table('clientes')->whereIn('id', $lote)
                    ->whereNull('id_cliente_canonico')
                    ->get(['id', 'nombre', 'cuit', 'tipo_documento', 'numero_documento', 'email'])
            );
            $cuentas = $cuentas->merge(
                DB::table('clientes_cuentas as cc')
                    ->join('clientes as origen', 'origen.id', '=', 'cc.cliente_id')
                    ->whereIn(DB::raw('COALESCE(origen.id_cliente_canonico, origen.id)'), $lote)
                    ->whereIn(DB::raw('LEFT(cc.cuenta, 4)'), $prefijos)
                    ->whereIn('cc.rol', ['INQUILINO', 'PROPIETARIO'])
                    ->selectRaw('COALESCE(origen.id_cliente_canonico, origen.id) as cliente_canonico_id, cc.rol, cc.cuenta')
                    ->distinct()->get()
            );
        }

        $clientes = $clientes->keyBy('id');
        $cuentas = $cuentas->groupBy('cliente_canonico_id');
        $busqueda = mb_strtoupper($buscar, 'UTF-8');
        $pares = $candidatos->filter(function ($par) use ($clientes, $cuentas, $busqueda): bool {
            $a = $clientes->get((int) $par->id_a);
            $b = $clientes->get((int) $par->id_b);
            // La cuenta es evidencia y límite de visibilidad por sucursal.
            // Deben ser visibles AMBAS identidades para ofrecer la comparación.
            if (! $a || ! $b
                || $cuentas->get((int) $par->id_a, collect())->isEmpty()
                || $cuentas->get((int) $par->id_b, collect())->isEmpty()) {
                return false;
            }

            if ($busqueda === '') {
                return true;
            }
            $texto = implode(' ', [
                $par->id_a, $par->id_b,
                $a->nombre, $b->nombre,
                $a->cuit, $b->cuit,
                $a->numero_documento, $b->numero_documento,
                implode(' ', $par->motivos),
                $cuentas->get((int) $par->id_a)->pluck('cuenta')->implode(' '),
                $cuentas->get((int) $par->id_b)->pluck('cuenta')->implode(' '),
            ]);
            return str_contains(mb_strtoupper($texto, 'UTF-8'), $busqueda);
        })->values();

        $porPagina = 25;
        $pagina = LengthAwarePaginator::resolveCurrentPage();
        $paginado = new LengthAwarePaginator(
            $pares->forPage($pagina, $porPagina)->values(),
            $pares->count(),
            $porPagina,
            $pagina,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('unificacion.clientes.duplicados', [
            'pares' => $paginado,
            'clientes' => $clientes,
            'cuentas' => $cuentas,
            'buscar' => $buscar,
            'sedes' => $sedes,
        ]);
    }
}
