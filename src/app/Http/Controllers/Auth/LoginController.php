<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Http\Controllers\PortalAuthController;
use App\Services\Portal\PortalAccesoService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function mostrar()
    {
        return view('auth.login');
    }

    public function ingresar(Request $request)
    {
        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:50'],
            'clave' => ['required', 'string'],
        ]);

        $identificador = trim($datos['nombre']);
        if (preg_match('/^[0-9.\-\s]+$/', $identificador) && preg_match('/[0-9]/', $identificador)) {
            $request->merge([
                'identificador' => $identificador,
                'password' => $datos['clave'],
            ]);
            $respuesta = app(PortalAuthController::class)->ingresar(
                $request, app(PortalAccesoService::class)
            );
            // Sólo después de autenticar correctamente en el portal se cierra el guard administrativo.
            Auth::guard('web')->logout();
            return $respuesta;
        }

        $usuario = Usuario::query()
            ->where('nombre_usuario', trim($datos['nombre']))
            ->first();

        if (
            !$usuario ||
            !$usuario->estaHabilitado() ||
            $this->estaBloqueado($usuario)
        ) {
            $this->credencialesIncorrectas();
        }

        if (!Hash::check($datos['clave'], $usuario->password)) {
            $this->registrarIntentoFallido($usuario);
            $this->credencialesIncorrectas();
        }

        $usuario->forceFill([
            'ultimo_acceso' => now(),
            'intentos_fallidos' => 0,
            'bloqueado_hasta' => null,
        ])->save();

        // Una sola identidad activa por sesión de navegador.
        Auth::guard('portal')->logout();
        Auth::login(
            $usuario,
            $request->boolean('recordarme')
        );

        $request->session()->regenerate();

        return redirect()->intended(route('inicio'));
    }

    public function salir(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function estaBloqueado(Usuario $usuario): bool
    {
        return $usuario->bloqueado_hasta !== null
            && $usuario->bloqueado_hasta->isFuture();
    }

    private function registrarIntentoFallido(Usuario $usuario): void
    {
        $intentos = $usuario->intentos_fallidos + 1;

        if ($intentos >= 5) {
            $usuario->forceFill([
                'intentos_fallidos' => 0,
                'bloqueado_hasta' => now()->addMinutes(15),
            ])->save();

            return;
        }

        $usuario->forceFill([
            'intentos_fallidos' => $intentos,
        ])->save();
    }

    private function credencialesIncorrectas(): never
    {
        throw ValidationException::withMessages([
            'nombre' => 'Usuario o contraseña incorrectos.',
        ]);
    }
}