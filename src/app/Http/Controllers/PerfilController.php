<?php

namespace App\Http\Controllers;

use App\Models\Perfil;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class PerfilController extends Controller
{
    public function index(): View
    {
        $perfiles = Perfil::query()
            ->withCount('usuarios')
            ->orderBy('nombre')
            ->get();

        return view('perfiles.index', compact('perfiles'));
    }

    public function store(Request $request): RedirectResponse
    {
        $datos = $request->validate([
            'codigo' => ['required', 'string', 'max:30', 'alpha_dash', Rule::unique('perfiles', 'codigo')],
            'nombre' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'activo' => ['nullable', 'boolean'],
        ], [
            'codigo.alpha_dash' => 'El código sólo puede contener letras, números, guiones y guion bajo.',
        ]);

        Perfil::create([
            'codigo' => strtoupper(trim($datos['codigo'])),
            'nombre' => trim($datos['nombre']),
            'descripcion' => isset($datos['descripcion']) && trim($datos['descripcion']) !== ''
                ? trim($datos['descripcion'])
                : null,
            'activo' => $request->boolean('activo'),
        ]);

        return redirect()
            ->route('perfiles.index')
            ->with('success', 'Perfil creado correctamente.');
    }

    public function update(Request $request, Perfil $perfil): RedirectResponse
    {
        $esAdministrador = $perfil->codigo === 'ADMINISTRADOR';

        $reglasCodigo = ['required', 'string', 'max:30', 'alpha_dash'];
        if (! $esAdministrador) {
            $reglasCodigo[] = Rule::unique('perfiles', 'codigo')->ignore($perfil->id);
        }

        $datos = $request->validate([
            'codigo' => $reglasCodigo,
            'nombre' => ['required', 'string', 'max:100'],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'activo' => ['nullable', 'boolean'],
        ], [
            'codigo.alpha_dash' => 'El código sólo puede contener letras, números, guiones y guion bajo.',
        ]);

        $perfil->nombre = trim($datos['nombre']);
        $perfil->descripcion = isset($datos['descripcion']) && trim($datos['descripcion']) !== ''
            ? trim($datos['descripcion'])
            : null;

        if ($esAdministrador) {
            $perfil->codigo = 'ADMINISTRADOR';
            $perfil->activo = true;
        } else {
            $perfil->codigo = strtoupper(trim($datos['codigo']));
            $perfil->activo = $request->boolean('activo');
        }

        $perfil->save();

        return redirect()
            ->route('perfiles.index')
            ->with('success', 'Perfil actualizado correctamente.');
    }

    public function destroy(Perfil $perfil): RedirectResponse
    {
        if ($perfil->codigo === 'ADMINISTRADOR') {
            return redirect()
                ->route('perfiles.index')
                ->with('error', 'El perfil ADMINISTRADOR es un perfil del sistema y no se puede eliminar.');
        }

        if ($perfil->usuarios()->exists()) {
            return redirect()
                ->route('perfiles.index')
                ->with('error', 'El perfil no se puede eliminar porque tiene usuarios asignados.');
        }

        try {
            $nombre = $perfil->nombre;
            $perfil->delete();

            return redirect()
                ->route('perfiles.index')
                ->with('success', 'Perfil '.$nombre.' eliminado correctamente.');
        } catch (QueryException $e) {
            report($e);

            return redirect()
                ->route('perfiles.index')
                ->with('error', 'El perfil no se puede eliminar porque tiene información relacionada.');
        }
    }
}
