<?php

namespace App\Http\Controllers;

use App\Models\Cliente;
use App\Models\PortalToken;
use App\Services\Portal\PortalAccesoService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

class ClientePortalAccesoController extends Controller
{
    public function enviar(Cliente $cliente, PortalAccesoService $acceso): RedirectResponse
    {
        $resultado = $acceso->enviarDesdeCliente($cliente, Auth::user());

        if (! $resultado['enviado']) {
            return back()->with(
                'estado',
                'Ya existe un enlace vigente. No se generó otro. Vence el '
                .$resultado['vence_at']->format('d/m/Y H:i').'.'
            );
        }

        $mensaje = $resultado['proposito'] === PortalToken::PROPOSITO_ACTIVACION
            ? 'Se envió el acceso de Autogestión al correo del cliente.'
            : 'Se envió un enlace para restablecer la contraseña de Autogestión.';

        return back()->with('estado', $mensaje);
    }
}
