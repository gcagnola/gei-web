<?php

namespace App\Http\Controllers;

use App\Models\Perfil;
use App\Models\Sucursal;
use App\Models\Usuario;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UsuarioController extends Controller
{
    public function index(): View
    {
        $usuarios = Usuario::query()
            ->with(['perfil', 'sucursales' => fn ($q) => $q->orderBy('codigo')])
            ->orderBy('nombre')
            ->orderBy('nombre_usuario')
            ->get();

        $sucursales = Sucursal::query()
            ->where('activa', true)
            ->orderBy('codigo')
            ->get();

        $perfiles = Perfil::query()
            ->where('activo', true)
            ->orderBy('nombre')
            ->get();

        return view('usuarios.index', compact('usuarios', 'sucursales', 'perfiles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $idsSucursalesValidos = $this->idsSucursalesActivas();
        $idsPerfilesValidos = $this->idsPerfilesActivos();

        $validator = Validator::make($request->all(), [
            'nombre_usuario' => ['required', 'string', 'max:50', 'alpha_dash', Rule::unique('usuarios', 'nombre_usuario')],
            'nombre' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255', Rule::unique('usuarios', 'email')],
            'perfil_id' => ['required', 'integer', 'in:'.implode(',', $idsPerfilesValidos)],
            'password' => ['required', 'string', 'min:6', 'confirmed'],
            'sucursales' => ['nullable', 'array'],
            'sucursales.*' => ['integer', 'distinct', 'in:'.implode(',', $idsSucursalesValidos)],
        ], [
            'nombre_usuario.alpha_dash' => 'El usuario sólo puede contener letras, números, guiones y guion bajo.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('usuarios.index')
                ->withErrors($validator)
                ->withInput()
                ->with('formulario_usuario', 'nuevo');
        }

        $datos = $validator->validated();

        DB::transaction(function () use ($datos): void {
            $usuario = Usuario::create([
                'perfil_id' => $datos['perfil_id'],
                'nombre_usuario' => trim($datos['nombre_usuario']),
                'nombre' => trim($datos['nombre']),
                'email' => trim($datos['email']),
                'password' => $datos['password'],
                'activo' => true,
            ]);

            $usuario->sucursales()->sync($datos['sucursales'] ?? []);
        });

        return redirect()
            ->route('usuarios.index')
            ->with('success', 'Usuario creado correctamente.');
    }

    public function update(Request $request, Usuario $usuario): RedirectResponse
    {
        $idsPerfilesValidos = $this->idsPerfilesActivos();
        $esUsuarioActual = (int) $request->user()->id === (int) $usuario->id;

        $validator = Validator::make($request->all(), [
            'nombre_usuario' => [
                'required', 'string', 'max:50', 'alpha_dash',
                Rule::unique('usuarios', 'nombre_usuario')->ignore($usuario->id),
            ],
            'nombre' => ['required', 'string', 'max:150'],
            'email' => [
                'required', 'email', 'max:255',
                Rule::unique('usuarios', 'email')->ignore($usuario->id),
            ],
            'perfil_id' => ['required', 'integer', 'in:'.implode(',', $idsPerfilesValidos)],
            'activo' => ['required', 'boolean'],
            'password' => ['nullable', 'string', 'min:6', 'confirmed'],
        ], [
            'nombre_usuario.alpha_dash' => 'El usuario sólo puede contener letras, números, guiones y guion bajo.',
            'password.confirmed' => 'La confirmación de contraseña no coincide.',
        ]);

        if ($validator->fails()) {
            return redirect()
                ->route('usuarios.index')
                ->withErrors($validator)
                ->withInput()
                ->with('formulario_usuario', 'editar')
                ->with('editar_usuario_id', $usuario->id);
        }

        $datos = $validator->validated();

        // Evitamos que el administrador conectado se quite a sí mismo el acceso administrativo
        // o se desactive accidentalmente desde esta misma pantalla.
        if ($esUsuarioActual) {
            $datos['perfil_id'] = $usuario->perfil_id;
            $datos['activo'] = true;
        }

        $actualizacion = [
            'perfil_id' => $datos['perfil_id'],
            'nombre_usuario' => trim($datos['nombre_usuario']),
            'nombre' => trim($datos['nombre']),
            'email' => trim($datos['email']),
            'activo' => (bool) $datos['activo'],
        ];

        if (! empty($datos['password'])) {
            $actualizacion['password'] = $datos['password'];
        }

        $usuario->update($actualizacion);

        return redirect()
            ->route('usuarios.index')
            ->with('success', 'Datos de '.$usuario->nombre_usuario.' actualizados correctamente.');
    }

    public function actualizarSucursales(Request $request, Usuario $usuario): RedirectResponse
    {
        $idsValidos = $this->idsSucursalesActivas();

        $datos = $request->validate([
            'sucursales' => ['nullable', 'array'],
            'sucursales.*' => ['integer', 'distinct', 'in:'.implode(',', $idsValidos)],
        ]);

        $usuario->sucursales()->sync($datos['sucursales'] ?? []);

        return redirect()
            ->route('usuarios.index')
            ->with('success', 'Sucursales de '.$usuario->nombre.' actualizadas correctamente.');
    }

    public function destroy(Request $request, Usuario $usuario): RedirectResponse
    {
        if ((int) $request->user()->id === (int) $usuario->id) {
            return redirect()
                ->route('usuarios.index')
                ->with('error', 'No podés eliminar el usuario con el que estás conectado.');
        }

        try {
            $nombre = $usuario->nombre_usuario;
            $usuario->delete();

            return redirect()
                ->route('usuarios.index')
                ->with('success', 'Usuario '.$nombre.' eliminado correctamente.');
        } catch (QueryException $e) {
            report($e);

            return redirect()
                ->route('usuarios.index')
                ->with('error', 'El usuario no se puede eliminar porque tiene información relacionada. Podés desactivarlo.');
        }
    }

    /** @return array<int, int> */
    private function idsSucursalesActivas(): array
    {
        return Sucursal::query()
            ->where('activa', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /** @return array<int, int> */
    private function idsPerfilesActivos(): array
    {
        return Perfil::query()
            ->where('activo', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
