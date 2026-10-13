<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PortalController extends Controller
{
    public function inicio(): View
    {
        $portalUsuario = Auth::guard('portal')->user();
        $portalUsuario->load('cliente.roles', 'cliente.cuentas');

        return view('portal.inicio', compact('portalUsuario'));
    }

    public function actualizarDatos(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'telefono' => ['required', 'string', 'max:100'],
            'telefono_alternativo' => ['nullable', 'string', 'max:100'],
        ]);

        $portalUsuario = Auth::guard('portal')->user();
        $portalUsuario->cliente->update([
            'telefono' => trim($datos['telefono']),
            'telefono_alternativo' => filled($datos['telefono_alternativo'] ?? null)
                ? trim((string) $datos['telefono_alternativo'])
                : null,
        ]);

        $portalUsuario->forceFill(['datos_confirmados_at' => now()])->save();

        return back()->with('estado', 'Tus datos de contacto fueron actualizados.');
    }

    public function cambiarClave(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'password_actual' => ['required', 'string'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ]);

        $portalUsuario = Auth::guard('portal')->user();

        if (! Hash::check($datos['password_actual'], (string) $portalUsuario->password)) {
            throw ValidationException::withMessages([
                'password_actual' => 'La contraseña actual no es correcta.',
            ]);
        }

        $portalUsuario->forceFill(['password' => Hash::make($datos['password'])])->save();

        return back()->with('estado', 'La contraseña fue actualizada.');
    }
}
