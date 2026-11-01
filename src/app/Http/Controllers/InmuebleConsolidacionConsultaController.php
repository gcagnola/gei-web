<?php

namespace App\Http\Controllers;

use App\Models\Usuario;
use App\Services\UnificacionInmueblesService;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Comparacion de inmuebles del maestro operativo. No ejecuta unificaciones.
 * Las relaciones se leen desde public.inmuebles y sus tablas operativas.
 */
final class InmuebleConsolidacionConsultaController extends Controller
{
    public function comparar(Request $request, UnificacionInmueblesService $service): View
    {
        $usuario = $request->user();
        abort_unless(
            $usuario instanceof Usuario
                && $usuario->activo
                && $usuario->perfil?->puedeVerModulo('ARCHIVO_INMUEBLES')
                && Gate::allows('administrar-unificaciones'),
            403
        );

        $permitidas = $usuario->codigosSucursales();
        abort_unless(
            in_array('SF', $permitidas, true) && in_array('ST', $permitidas, true),
            403
        );

        $datos = $request->validate([
            'principal' => ['required', 'integer', 'min:1'],
            'secundario' => ['required', 'integer', 'min:1', 'different:principal'],
        ]);

        try {
            $comparacion = $service->comparar((int) $datos['principal'], (int) $datos['secundario']);
        } catch (DomainException $error) {
            abort(404, $error->getMessage());
        }

        return view('inmuebles-maestro.comparar', $comparacion);
    }
}
