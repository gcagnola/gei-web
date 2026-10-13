<?php

namespace App\View\Components;

use App\Models\Cliente;
use App\Models\PortalToken;
use App\Models\PortalUsuario;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class PortalEstadoCliente extends Component
{
    public ?Cliente $cliente = null;
    public ?PortalUsuario $portalUsuario = null;
    public ?PortalToken $tokenActivacion = null;
    public string $estado = 'NO_HABILITADA';
    public ?string $identificador = null;

    public function __construct(public ?int $clienteId = null)
    {
        if (! $clienteId) {
            return;
        }

        $this->cliente = Cliente::query()
            ->with('roles')
            ->find($clienteId);

        if (! $this->cliente) {
            return;
        }

        $this->identificador = $this->resolverIdentificador($this->cliente);

        $this->portalUsuario = PortalUsuario::query()
            ->where('cliente_id', $this->cliente->id)
            ->first();

        if (! $this->portalUsuario) {
            return;
        }

        if (! $this->portalUsuario->activo) {
            $this->estado = 'DESHABILITADA';
            return;
        }

        if ($this->portalUsuario->estaActivado()) {
            $this->estado = 'ACTIVA';
            return;
        }

        $this->tokenActivacion = PortalToken::query()
            ->where('portal_usuario_id', $this->portalUsuario->id)
            ->where('proposito', PortalToken::PROPOSITO_ACTIVACION)
            ->first();

        if (! $this->tokenActivacion) {
            return;
        }

        $this->estado = $this->tokenActivacion->estaVigente()
            ? 'PENDIENTE'
            : 'VENCIDA';
    }

    public function render(): View
    {
        return view('components.portal-estado-cliente');
    }

    private function resolverIdentificador(Cliente $cliente): ?string
    {
        $cuit = preg_replace('/\D+/', '', trim((string) $cliente->cuit)) ?: '';

        if ($cuit !== '') {
            return $cuit;
        }

        $documento = preg_replace('/\D+/', '', trim((string) $cliente->numero_documento)) ?: '';

        return $documento !== '' ? $documento : null;
    }
}
