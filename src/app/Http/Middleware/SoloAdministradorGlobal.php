<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierre temporal de rutas heredadas que no comprueban el alcance por
 * cuenta/rol y sucursal. NO sustituye la autorización documental por cuenta.
 * Sólo administradores habilitados para ambas sedes pueden usarlas.
 */
final class SoloAdministradorGlobal
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        abort_unless(
            $usuario && Gate::forUser($usuario)->allows('administrar-unificaciones'),
            403,
        );

        $sedes = $usuario->codigosSucursales();
        abort_unless(in_array('SF', $sedes, true) && in_array('ST', $sedes, true), 403);

        return $next($request);
    }
}
