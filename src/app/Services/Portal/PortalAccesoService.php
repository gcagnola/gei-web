<?php

namespace App\Services\Portal;

use App\Mail\PortalActivacionMail;
use App\Mail\PortalRecuperarClaveMail;
use App\Models\Cliente;
use App\Models\PortalToken;
use App\Models\PortalUsuario;
use App\Models\Usuario;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class PortalAccesoService
{
    /** @return array{portal_usuario: PortalUsuario, enviado: bool, proposito: string, vence_at: mixed} */
    public function enviarDesdeCliente(Cliente $cliente, ?Usuario $usuarioEnvio = null): array
    {
        $this->validarClienteHabilitable($cliente);

        $portalUsuario = PortalUsuario::query()->firstOrCreate(
            ['cliente_id' => $cliente->id],
            ['activo' => true]
        );

        $proposito = $portalUsuario->estaActivado()
            ? PortalToken::PROPOSITO_RECUPERACION
            : PortalToken::PROPOSITO_ACTIVACION;

        return $this->emitirToken($portalUsuario, $proposito, $usuarioEnvio?->id);
    }

    public function buscarPortalUsuarioPorIdentificador(string $identificador): ?PortalUsuario
    {
        $identificador = $this->normalizarIdentificador($identificador);

        if ($identificador === '') {
            return null;
        }

        $porCuit = PortalUsuario::query()
            ->with('cliente.roles')
            ->where('activo', true)
            ->whereHas('cliente', function ($query) use ($identificador): void {
                $query->where('activo', true)
                    ->whereNull('id_cliente_canonico')
                    ->where('cuit', $identificador);
            })
            ->first();

        if ($porCuit) {
            return $this->clienteConRolPortal($porCuit->cliente) ? $porCuit : null;
        }

        $candidatos = PortalUsuario::query()
            ->with('cliente.roles')
            ->where('activo', true)
            ->whereHas('cliente', function ($query) use ($identificador): void {
                $query->where('activo', true)
                    ->whereNull('id_cliente_canonico')
                    ->where(function ($clienteQuery): void {
                        $clienteQuery->whereNull('cuit')->orWhere('cuit', '');
                    })
                    ->where('numero_documento', $identificador);
            })
            ->get()
            ->filter(fn (PortalUsuario $usuario): bool => $this->clienteConRolPortal($usuario->cliente));

        return $candidatos->count() === 1 ? $candidatos->first() : null;
    }

    /** @return array{enviado: bool, vence_at: mixed}|null */
    public function enviarRecuperacion(string $identificador): ?array
    {
        $portalUsuario = $this->buscarPortalUsuarioPorIdentificador($identificador);

        if (! $portalUsuario || ! $portalUsuario->estaActivado()) {
            return null;
        }

        $resultado = $this->emitirToken(
            $portalUsuario,
            PortalToken::PROPOSITO_RECUPERACION,
            null
        );

        return [
            'enviado' => $resultado['enviado'],
            'vence_at' => $resultado['vence_at'],
        ];
    }

    public function buscarTokenVigente(string $tokenPlano, string $proposito): ?PortalToken
    {
        return PortalToken::query()
            ->with('portalUsuario.cliente.roles')
            ->where('proposito', $proposito)
            ->where('token_hash', hash('sha256', $tokenPlano))
            ->whereNull('usado_at')
            ->where('vence_at', '>', now())
            ->first();
    }

    public function identificadorCliente(Cliente $cliente): ?string
    {
        $cuit = $this->normalizarIdentificador((string) $cliente->cuit);
        if ($cuit !== '') {
            return $cuit;
        }

        $documento = $this->normalizarIdentificador((string) $cliente->numero_documento);

        return $documento !== '' ? $documento : null;
    }

    private function validarClienteHabilitable(Cliente $cliente): void
    {
        $cliente->loadMissing('roles');
        $errores = [];

        if (! $cliente->activo) {
            $errores[] = 'El cliente está inactivo.';
        }

        if ($cliente->id_cliente_canonico !== null) {
            $errores[] = 'El cliente fue absorbido por otro cliente canónico.';
        }

        if (! $this->clienteConRolPortal($cliente)) {
            $errores[] = 'El cliente debe tener rol Inquilino o Propietario.';
        }

        if ($this->identificadorCliente($cliente) === null) {
            $errores[] = 'El cliente debe tener CUIT o número de documento.';
        }

        if (! filter_var(trim((string) $cliente->email), FILTER_VALIDATE_EMAIL)) {
            $errores[] = 'El cliente debe tener un correo electrónico válido.';
        }

        if ($errores !== []) {
            throw ValidationException::withMessages([
                'autogestion' => implode(' ', $errores),
            ]);
        }
    }

    private function clienteConRolPortal(Cliente $cliente): bool
    {
        return $cliente->roles->contains(function ($rol): bool {
            return in_array(strtoupper(trim((string) $rol->codigo)), ['INQUILINO', 'PROPIETARIO'], true);
        });
    }

    /** @return array{portal_usuario: PortalUsuario, enviado: bool, proposito: string, vence_at: mixed} */
    private function emitirToken(PortalUsuario $portalUsuario, string $proposito, ?int $usuarioEnvioId): array
    {
        $portalUsuario->loadMissing('cliente');
        $email = trim((string) $portalUsuario->cliente->email);

        $tokenExistente = PortalToken::query()
            ->where('portal_usuario_id', $portalUsuario->id)
            ->where('proposito', $proposito)
            ->first();

        if ($tokenExistente?->estaVigente()) {
            return [
                'portal_usuario' => $portalUsuario,
                'enviado' => false,
                'proposito' => $proposito,
                'vence_at' => $tokenExistente->vence_at,
            ];
        }

        $tokenPlano = Str::random(80);
        $venceAt = $proposito === PortalToken::PROPOSITO_ACTIVACION
            ? now()->addHours(max(1, (int) config('portal.activation_hours', 24)))
            : now()->addMinutes(max(1, (int) config('portal.password_reset_minutes', 60)));

        $token = DB::transaction(function () use (
            $portalUsuario,
            $proposito,
            $tokenPlano,
            $email,
            $venceAt,
            $usuarioEnvioId
        ): PortalToken {
            return PortalToken::query()->updateOrCreate(
                [
                    'portal_usuario_id' => $portalUsuario->id,
                    'proposito' => $proposito,
                ],
                [
                    'token_hash' => hash('sha256', $tokenPlano),
                    'enviado_a' => $email,
                    'enviado_por_usuario_id' => $usuarioEnvioId,
                    'enviado_at' => now(),
                    'vence_at' => $venceAt,
                    'usado_at' => null,
                ]
            );
        });

        $ruta = $proposito === PortalToken::PROPOSITO_ACTIVACION
            ? route('portal.activacion.mostrar', ['token' => $tokenPlano], false)
            : route('portal.password.reset', ['token' => $tokenPlano], false);

        $url = rtrim((string) config('portal.base_url'), '/').$ruta;
        $identificador = $this->identificadorCliente($portalUsuario->cliente) ?? '';

        try {
            if ($proposito === PortalToken::PROPOSITO_ACTIVACION) {
                Mail::to($email)->send(new PortalActivacionMail(
                    nombreCliente: $portalUsuario->cliente->nombre_visible,
                    identificador: $identificador,
                    urlActivacion: $url,
                    venceAt: $venceAt,
                ));
            } else {
                Mail::to($email)->send(new PortalRecuperarClaveMail(
                    nombreCliente: $portalUsuario->cliente->nombre_visible,
                    identificador: $identificador,
                    urlRecuperacion: $url,
                    venceAt: $venceAt,
                ));
            }
        } catch (Throwable $e) {
            PortalToken::query()
                ->whereKey($token->id)
                ->where('token_hash', hash('sha256', $tokenPlano))
                ->delete();

            throw $e;
        }

        return [
            'portal_usuario' => $portalUsuario,
            'enviado' => true,
            'proposito' => $proposito,
            'vence_at' => $venceAt,
        ];
    }

    private function normalizarIdentificador(string $valor): string
    {
        return preg_replace('/\D+/', '', trim($valor)) ?: '';
    }
}
