<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
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
            'nombre' => ['required', 'string', 'max:25'],
            'clave' => ['required', 'string'],
        ]);

        $usuario = Usuario::query()
            ->whereRaw('TRIM(nombre) = ?', [
                trim($datos['nombre']),
            ])
            ->first();

        if (
            !$usuario ||
            !$usuario->estaHabilitado() ||
            $this->estaBloqueado($usuario) ||
            !$this->validarClave($usuario, $datos['clave'])
        ) {
            if ($usuario && !$this->estaBloqueado($usuario)) {
                $this->registrarIntentoFallido($usuario);
            }

            throw ValidationException::withMessages([
                'nombre' => 'Usuario o contraseña incorrectos.',
            ]);
        }

        $usuario->forceFill([
            'web_ultimo_acceso' => now(),
            'web_intentos_fallidos' => 0,
            'web_bloqueado_hasta' => null,
        ])->save();

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

    private function validarClave(
        Usuario $usuario,
        string $claveIngresada
    ): bool {
        $hashWeb = trim((string) $usuario->web_clave_hash);

        if ($hashWeb !== '') {
            if (! $this->esHashBcrypt($hashWeb)) {
                return false;
            }

            return Hash::check($claveIngresada, $hashWeb);
        }

        $md5Almacenado = strtolower(
            trim((string) $usuario->clave)
        );

        $md5Ingresado = md5($claveIngresada);

        if (!hash_equals($md5Almacenado, $md5Ingresado)) {
            return false;
        }

        $usuario->forceFill([
            'web_clave_hash' => Hash::make($claveIngresada),
            'web_clave_actualizada' => now(),
        ])->save();

        return true;
    }

    private function esHashBcrypt(string $hash): bool
    {
        return password_get_info($hash)['algoName'] === 'bcrypt';
    }

    private function estaBloqueado(Usuario $usuario): bool
    {
        return $usuario->web_bloqueado_hasta !== null
            && $usuario->web_bloqueado_hasta->isFuture();
    }

    private function registrarIntentoFallido(Usuario $usuario): void
    {
        $intentos = (int) $usuario->web_intentos_fallidos + 1;

        $datos = [
            'web_intentos_fallidos' => $intentos,
        ];

        if ($intentos >= 5) {
            $datos['web_bloqueado_hasta'] = now()->addMinutes(15);
            $datos['web_intentos_fallidos'] = 0;
        }

        $usuario->forceFill($datos)->save();
    }
}
