<?php

namespace App\Http\Middleware;

use App\Models\PortalUsuario;
use App\Services\Portal\PortalAccesoService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AutenticarPortal
{
    public function __construct(private readonly PortalAccesoService $acceso)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $guard = Auth::guard('portal');
        if (! $guard->check()) {
            return redirect()->route('portal.login');
        }

        // Cada petición vuelve a consultar el estado actual: una sesión anterior
        // no puede sobrevivir a una baja, absorción o inhabilitación del portal.
        $usuario = $guard->user();
        $vigente = $usuario instanceof PortalUsuario
            ? $usuario->fresh(['cliente.roles'])
            : null;

        if ($vigente === null || ! $this->acceso->usuarioHabilitado($vigente)) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('portal.login')
                ->with('estado', 'El acceso a Autogestión ya no está habilitado.');
        }

        // El controlador también debe usar el cliente recargado, no uno anterior.
        $guard->setUser($vigente);

        return $next($request);
    }
}
