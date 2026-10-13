<?php

namespace App\Http\Controllers;

use App\Models\PortalToken;
use App\Models\PortalUsuario;
use App\Services\Portal\PortalAccesoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PortalAuthController extends Controller
{
    public function login(): View|RedirectResponse
    {
        if (Auth::guard('portal')->check()) {
            return redirect()->route('portal.inicio');
        }

        return view('portal.auth.login');
    }

    public function ingresar(Request $request, PortalAccesoService $acceso): RedirectResponse
    {
        $datos = $request->validate([
            'identificador' => ['required', 'string', 'max:30'],
            'password' => ['required', 'string'],
        ]);

        $portalUsuario = $acceso->buscarPortalUsuarioPorIdentificador($datos['identificador']);

        if (! $portalUsuario
            || ! $portalUsuario->estaActivado()
            || $this->estaBloqueado($portalUsuario)
            || ! Hash::check($datos['password'], (string) $portalUsuario->password)) {
            if ($portalUsuario && $portalUsuario->estaActivado() && ! $this->estaBloqueado($portalUsuario)) {
                $this->registrarIntentoFallido($portalUsuario);
            }

            throw ValidationException::withMessages([
                'identificador' => 'Usuario o contraseña incorrectos.',
            ]);
        }

        $portalUsuario->forceFill([
            'ultimo_acceso_at' => now(),
            'ultimo_acceso_ip' => $request->ip(),
            'intentos_fallidos' => 0,
            'bloqueado_hasta' => null,
        ])->save();

        Auth::guard('portal')->login($portalUsuario, $request->boolean('recordarme'));
        $request->session()->regenerate();

        return redirect()->route('portal.inicio');
    }

    public function salir(Request $request): RedirectResponse
    {
        Auth::guard('portal')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('portal.login');
    }

    public function activacion(string $token, PortalAccesoService $acceso): View
    {
        $registro = $acceso->buscarTokenVigente($token, PortalToken::PROPOSITO_ACTIVACION);
        abort_if(! $registro, 404, 'El enlace de activación es inválido o ha vencido.');

        return view('portal.auth.activar', [
            'token' => $token,
            'portalUsuario' => $registro->portalUsuario,
        ]);
    }

    public function activar(Request $request, PortalAccesoService $acceso): RedirectResponse
    {
        $datos = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $registro = $acceso->buscarTokenVigente($datos['token'], PortalToken::PROPOSITO_ACTIVACION);
        if (! $registro) {
            return back()->withErrors(['password' => 'El enlace es inválido o ha vencido.']);
        }

        DB::transaction(function () use ($registro, $datos): void {
            $registro->portalUsuario->forceFill([
                'password' => Hash::make($datos['password']),
                'activo' => true,
                'activado_at' => now(),
                'intentos_fallidos' => 0,
                'bloqueado_hasta' => null,
            ])->save();

            $registro->forceFill(['usado_at' => now()])->save();
        });

        return redirect()->route('portal.login')
            ->with('estado', 'Tu acceso quedó activado. Ya podés iniciar sesión.');
    }

    public function solicitarRecuperacion(): View
    {
        return view('portal.auth.clave-olvidada');
    }

    public function enviarRecuperacion(Request $request, PortalAccesoService $acceso): RedirectResponse
    {
        $datos = $request->validate([
            'identificador' => ['required', 'string', 'max:30'],
        ]);

        $acceso->enviarRecuperacion($datos['identificador']);

        return back()->with(
            'estado',
            'Si el usuario está habilitado, recibirás un enlace en el correo registrado.'
        );
    }

    public function mostrarReset(string $token, PortalAccesoService $acceso): View
    {
        $registro = $acceso->buscarTokenVigente($token, PortalToken::PROPOSITO_RECUPERACION);
        abort_if(! $registro, 404, 'El enlace de recuperación es inválido o ha vencido.');

        return view('portal.auth.restablecer-clave', ['token' => $token]);
    }

    public function reset(Request $request, PortalAccesoService $acceso): RedirectResponse
    {
        $datos = $request->validate([
            'token' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $registro = $acceso->buscarTokenVigente($datos['token'], PortalToken::PROPOSITO_RECUPERACION);
        if (! $registro) {
            return back()->withErrors(['password' => 'El enlace es inválido o ha vencido.']);
        }

        DB::transaction(function () use ($registro, $datos): void {
            $registro->portalUsuario->forceFill([
                'password' => Hash::make($datos['password']),
                'intentos_fallidos' => 0,
                'bloqueado_hasta' => null,
            ])->save();

            $registro->forceFill(['usado_at' => now()])->save();
        });

        return redirect()->route('portal.login')
            ->with('estado', 'La contraseña fue actualizada. Ya podés iniciar sesión.');
    }

    private function estaBloqueado(PortalUsuario $usuario): bool
    {
        return $usuario->bloqueado_hasta !== null && $usuario->bloqueado_hasta->isFuture();
    }

    private function registrarIntentoFallido(PortalUsuario $usuario): void
    {
        $intentos = $usuario->intentos_fallidos + 1;

        if ($intentos >= 5) {
            $usuario->forceFill([
                'intentos_fallidos' => 0,
                'bloqueado_hasta' => now()->addMinutes(15),
            ])->save();
            return;
        }

        $usuario->forceFill(['intentos_fallidos' => $intentos])->save();
    }
}
