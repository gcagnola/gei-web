<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

class SucursalController extends Controller
{
    public function index(): View
    {
        $sucursales = DB::table('sucursales as s')
            ->leftJoin('usuarios_sucursales as us', 'us.sucursal_id', '=', 's.id')
            ->select([
                's.id',
                's.codigo',
                's.nombre',
                's.activa',
                DB::raw('COUNT(us.usuario_id) AS usuarios_count'),
            ])
            ->groupBy('s.id', 's.codigo', 's.nombre', 's.activa')
            ->orderBy('s.codigo')
            ->get();

        return view('parametros.sedes.index', compact('sucursales'));
    }
}
