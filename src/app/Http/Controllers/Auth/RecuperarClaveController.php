<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\RecuperarClaveMail;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class RecuperarClaveController extends Controller
{
    public function mostrarSolicitud()
    {
        return view('auth.clave-olvidada');
    }

    public function enviarEnlace(Request $request)
    {
        $datos = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = trim($datos['email']);

        $usuario = Usuario::query()
            ->whereRaw('LOWER(email) = LOWER(?)', [$email])
            ->where('activo', true)
            ->first();

        $mensaje = 'Si el correo está registrado, recibirás un enlace para restablecer la contraseña.';

        if (!$usuario) {
            return back()->with('estado', $mensaje);
        }

        $tokenPlano = Str::random(64);

        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $usuario->email],
            [
                'token' => hash('sha256', $tokenPlano),
                'created_at' => now(),
            ]
        );

        $urlRecuperacion = route('password.reset', [
            'token' => $tokenPlano,
            'email' => $usuario->email,
        ]);

        Mail::to($usuario->email)->send(
            new RecuperarClaveMail(
                nombreUsuario: $usuario->nombre_limpio,
                urlRecuperacion: $urlRecuperacion
            )
        );

        return back()->with('estado', $mensaje);
    }

    public function mostrarRestablecimiento(
        Request $request,
        string $token
    ) {
        return view('auth.restablecer-clave', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    public function restablecer(Request $request)
    {
        $datos = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email', 'max:255'],
            'password' => [
                'required',
                'string',
                'min:8',
                'confirmed',
            ],
        ]);

        $email = trim($datos['email']);

        $registroToken = DB::table('password_reset_tokens')
            ->whereRaw('LOWER(email) = LOWER(?)', [$email])
            ->where('created_at', '>=', now()->subMinutes(60))
            ->first();

        if (
            !$registroToken ||
            !hash_equals(
                (string) $registroToken->token,
                hash('sha256', $datos['token'])
            )
        ) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => 'El enlace es inválido o ha vencido.',
                ]);
        }

        $usuario = Usuario::query()
            ->whereRaw('LOWER(email) = LOWER(?)', [$email])
            ->where('activo', true)
            ->first();

        if (!$usuario) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors([
                    'email' => 'No fue posible restablecer la contraseña.',
                ]);
        }

        DB::transaction(function () use ($usuario, $datos, $email) {
            $usuario->forceFill([
                'password' => Hash::make($datos['password']),
                'intentos_fallidos' => 0,
                'bloqueado_hasta' => null,
            ])->save();

            DB::table('password_reset_tokens')
                ->whereRaw('LOWER(email) = LOWER(?)', [$email])
                ->delete();
        });

        return redirect()
            ->route('login')
            ->with(
                'estado',
                'La contraseña fue actualizada. Ya podés iniciar sesión.'
            );
    }
}