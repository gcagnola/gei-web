<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerificarAccesoModulo
{
    /**
     * Rutas exactas que pertenecen a un módulo.
     *
     * Se declaran explícitamente cuando un prefijo amplio podría alcanzar
     * rutas de otro módulo (por ejemplo, Comprobantes ARCA dentro de clientes).
     */
    private const RUTAS_EXACTAS = [
        'clientes.index' => 'ARCHIVO_CLIENTES',
        'clientes.show' => 'ARCHIVO_CLIENTES',
        'clientes.create' => 'ARCHIVO_CLIENTES',
        'clientes.store' => 'ARCHIVO_CLIENTES',
        'clientes.edit' => 'ARCHIVO_CLIENTES',
        'clientes.update' => 'ARCHIVO_CLIENTES',
    ];

    /**
     * Prefijos de rutas protegidos por cada módulo funcional.
     * La primera coincidencia gana.
     *
     * Comprobantes ARCA queda deliberadamente fuera hasta terminar ese módulo.
     */
    private const PREFIJOS_RUTAS = [
        'archivo.importar' => 'ARCHIVO_IMPORTAR',
        'inmuebles.' => 'ARCHIVO_INMUEBLES',
        'cuentas-corrientes.' => 'ARCHIVO_INMUEBLES',
        'core-clientes.' => 'ARCHIVO_CLIENTES',
        'personas.' => 'ARCHIVO_CLIENTES',
        'clientes.facturacion.' => 'ARCHIVO_CLIENTES',
        'archivo.impresiones-cobol.' => 'ARCHIVO_IMPRESIONES_COBOL',
        'propietarios.liquidaciones.' => 'PROPIETARIOS_LIQUIDACIONES',
        'usuarios.' => 'OPCIONES_USUARIOS',
        'perfiles.' => 'OPCIONES_PERFILES',
        'permisos.' => 'OPCIONES_PERMISOS',
        'parametros.sedes.' => 'PARAMETROS_SEDES',
        'seteos.' => 'OPCIONES_SETEOS',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        $nombreRuta = $request->route()?->getName();

        if (! $usuario || ! $nombreRuta) {
            return $next($request);
        }

        $codigoModulo = $this->codigoModuloParaRuta($nombreRuta);

        if ($codigoModulo === null) {
            return $next($request);
        }

        if (! $usuario->perfil || ! $usuario->perfil->puedeVerModulo($codigoModulo)) {
            abort(403, 'No tenés permiso para acceder a este módulo.');
        }

        return $next($request);
    }

    private function codigoModuloParaRuta(string $nombreRuta): ?string
    {
        if (isset(self::RUTAS_EXACTAS[$nombreRuta])) {
            return self::RUTAS_EXACTAS[$nombreRuta];
        }

        foreach (self::PREFIJOS_RUTAS as $prefijo => $codigoModulo) {
            if ($nombreRuta === $prefijo || str_starts_with($nombreRuta, $prefijo)) {
                return $codigoModulo;
            }
        }

        return null;
    }
}
