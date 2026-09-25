<?php

namespace App\Http\Controllers;

use App\Models\Modulo;
use App\Models\Perfil;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class PermisoPerfilController extends Controller
{
    public function index(): View
    {
        $perfiles = Perfil::query()
            ->with('modulos:id,codigo')
            ->orderByRaw("CASE WHEN codigo = 'ADMINISTRADOR' THEN 0 ELSE 1 END")
            ->orderBy('nombre')
            ->get();

        $modulos = Modulo::query()
            ->where('activo', true)
            ->orderBy('orden')
            ->orderBy('seccion')
            ->orderBy('nombre')
            ->get();

        return view('permisos.index', compact('perfiles', 'modulos'));
    }

    public function update(Request $request): RedirectResponse
    {
        $request->validate([
            'permisos' => ['nullable', 'array'],
            'permisos.*' => ['nullable', 'array'],
            'permisos.*.*' => ['integer', 'exists:modulos,id'],
        ]);

        $perfiles = Perfil::query()->get();
        $modulosActivos = Modulo::query()->where('activo', true)->pluck('id')->map(fn ($id) => (int) $id);
        $permisos = $request->input('permisos', []);

        DB::transaction(function () use ($perfiles, $modulosActivos, $permisos): void {
            foreach ($perfiles as $perfil) {
                if ($perfil->codigo === 'ADMINISTRADOR') {
                    $perfil->modulos()->sync($modulosActivos->all());
                    continue;
                }

                $seleccionados = collect($permisos[$perfil->id] ?? [])
                    ->map(fn ($id) => (int) $id)
                    ->intersect($modulosActivos)
                    ->values()
                    ->all();

                $perfil->modulos()->sync($seleccionados);
            }
        });

        return redirect()
            ->route('permisos.index')
            ->with('success', 'Permisos actualizados correctamente.');
    }
}
