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
    /**
     * Muestra el formulario donde el usuario ingresa su correo.
     */
    public function mostrarSolicitud()
    {
        return view('auth.clave-olvidada');
    }

    /**
     * Genera el token y envía el correo de recuperación.
     */
    public function enviarEnlace(Request $request)
    {
        $datos = $request->validate([
            'email' => ['required', 'email', 'max:255'],
        ]);

        $email = trim($datos['email']);

        $usuario = Usuario::query()
            ->whereRaw('LOWER(TRIM(web_email)) = LOWER(?)', [$email])
            ->where('habilitado', 1)
            ->first();

        /*
         * No informamos si el correo existe o no.
         * Esto evita revelar usuarios registrados.
         */
        if (!$usuario) {
            return back()->with(
                'estado',
                'Si el correo está registrado, recibirás un enlace para restablecer la contraseña.'
            );
        }

        $tokenPlano = Str::random(64);
        $tokenHash = hash('sha256', $tokenPlano);

        DB::table('web_password_reset_tokens')->updateOrInsert(
            ['email' => trim((string) $usuario->web_email)],
            [
                'token' => $tokenHash,
                'created_at' => now(),
            ]
        );

        $urlRecuperacion = route('password.reset', [
            'token' => $tokenPlano,
            'email' => trim((string) $usuario->web_email),
        ]);

        Mail::to(trim((string) $usuario->web_email))
            ->send(new RecuperarClaveMail(
                nombreUsuario: $usuario->nombre_limpio,
                urlRecuperacion: $urlRecuperacion
            ));

        return back()->with(
            'estado',
            'Si el correo está registrado, recibirás un enlace para restablecer la contraseña.'
        );
    }

    /**
     * Muestra el formulario para elegir una nueva contraseña.
     */
    public function mostrarRestablecimiento(
        Request $request,
        string $token
    ) {
        return view('auth.restablecer-clave', [
            'token' => $token,
            'email' => $request->query('email'),
        ]);
    }

    /**
     * Valida el token y actualiza únicamente la contraseña web.
     */
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

        $registroToken = DB::table('web_password_reset_tokens')
            ->whereRaw('LOWER(email) = LOWER(?)', [$email])
            ->where(
                'created_at',
                '>=',
                now()->subMinutes(60)
            )
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
            ->whereRaw('LOWER(TRIM(web_email)) = LOWER(?)', [$email])
            ->where('habilitado', 1)
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
                'web_clave_hash' => Hash::make($datos['password']),
                'web_clave_actualizada' => now(),
                'web_intentos_fallidos' => 0,
                'web_bloqueado_hasta' => null,
            ])->save();

            DB::table('web_password_reset_tokens')
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