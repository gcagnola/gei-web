<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class CamaraController extends Controller
{
    public function index(Request $request): View
    {
        $usuario = $request->user();

        if (! $usuario?->perfil?->puedeVerModulo('CAMARAS')) {
            abort(403, 'No tenés permiso para acceder a este módulo.');
        }

        $sitios = collect(config('camaras.sitios', []))
            ->filter(static fn (array $sitio): bool => (bool) ($sitio['activo'] ?? false))
            ->map(static function (array $sitio, string $codigo): array {
                $sitio['codigo'] = $codigo;
                $sitio['camaras'] = array_values($sitio['camaras'] ?? []);

                return $sitio;
            })
            ->values()
            ->all();

        return view('camaras.index', [
            'sitios' => $sitios,
            'go2rtcUrl' => rtrim((string) config('camaras.go2rtc_url'), '/'),
        ]);
    }
}
