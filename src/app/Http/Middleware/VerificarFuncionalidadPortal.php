<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Impide acceder por URL directa a funciones de Autogestión no publicadas.
 * También se aplica al listado administrativo de transferencias informadas.
 */
class VerificarFuncionalidadPortal
{
    public function handle(Request $request, Closure $next, string $funcionalidad): Response
    {
        abort_unless(config('portal.funcionalidades.'.$funcionalidad, false) === true, 404);

        return $next($request);
    }
}
