<?php

namespace Tests\Unit;

use App\Models\Cliente;
use App\Models\PortalUsuario;
use App\Services\Portal\PortalAccesoService;
use Illuminate\Database\Eloquent\Collection;
use Tests\TestCase;

final class PortalHabilitacionTest extends TestCase
{
    private function usuario(
        bool $clienteActivo = true,
        ?int $absorbidoEn = null,
        bool $portalActivo = true,
        bool $activado = true,
        string $rol = 'PROPIETARIO',
    ): PortalUsuario {
        $cliente = new Cliente();
        $cliente->setRawAttributes([
            'id' => 10,
            'activo' => $clienteActivo,
            'id_cliente_canonico' => $absorbidoEn,
        ], true);
        $cliente->setRelation('roles', new Collection([
            (object) ['codigo' => $rol],
        ]));

        $usuario = new PortalUsuario();
        $usuario->setRawAttributes([
            'id' => 20,
            'cliente_id' => 10,
            'activo' => $portalActivo,
            'activado_at' => $activado ? '2026-10-28 12:00:00' : null,
            'password' => $activado ? 'hash-de-prueba' : null,
        ], true);
        $usuario->setRelation('cliente', $cliente);

        return $usuario;
    }

    public function test_propietario_activo_puede_ingresar(): void
    {
        self::assertTrue((new PortalAccesoService())->usuarioHabilitado($this->usuario()));
    }

    public function test_inquilino_activo_puede_ingresar(): void
    {
        self::assertTrue((new PortalAccesoService())->usuarioHabilitado($this->usuario(rol: 'INQUILINO')));
    }

    public function test_cliente_inactivo_no_puede_ingresar(): void
    {
        self::assertFalse((new PortalAccesoService())->usuarioHabilitado($this->usuario(clienteActivo: false)));
    }

    public function test_cliente_absorbido_no_puede_ingresar(): void
    {
        self::assertFalse((new PortalAccesoService())->usuarioHabilitado($this->usuario(absorbidoEn: 99)));
    }

    public function test_portal_deshabilitado_no_puede_ingresar(): void
    {
        self::assertFalse((new PortalAccesoService())->usuarioHabilitado($this->usuario(portalActivo: false)));
    }

    public function test_usuario_no_activado_no_puede_ingresar(): void
    {
        self::assertFalse((new PortalAccesoService())->usuarioHabilitado($this->usuario(activado: false)));
    }

    public function test_token_de_activacion_puede_habilitar_usuario_aun_sin_clave(): void
    {
        self::assertTrue((new PortalAccesoService())->usuarioHabilitado($this->usuario(activado: false), false));
    }

    public function test_token_de_activacion_no_habilita_un_cliente_absorbido(): void
    {
        self::assertFalse((new PortalAccesoService())->usuarioHabilitado($this->usuario(absorbidoEn: 50, activado: false), false));
    }

    public function test_token_de_activacion_no_habilita_un_portal_deshabilitado(): void
    {
        self::assertFalse((new PortalAccesoService())->usuarioHabilitado($this->usuario(portalActivo: false, activado: false), false));
    }

    public function test_token_de_recuperacion_requiere_usuario_activado(): void
    {
        self::assertFalse((new PortalAccesoService())->usuarioHabilitado($this->usuario(activado: false), true));
    }

    public function test_rol_administrativo_no_habilita_autogestion(): void
    {
        self::assertFalse((new PortalAccesoService())->usuarioHabilitado($this->usuario(rol: 'ADMINISTRADOR')));
    }

    public function test_cliente_sin_relacion_canonica_no_es_inferido_por_cuit(): void
    {
        $usuario = $this->usuario();
        $usuario->setRelation('cliente', null);
        self::assertFalse((new PortalAccesoService())->usuarioHabilitado($usuario));
    }

    public function test_flujo_de_unificacion_sigue_bloqueando_usuario_del_absorbido(): void
    {
        $fuente = file_get_contents(__DIR__.'/../../app/Services/UnificacionClientesService.php');
        self::assertStringContainsString("DB::table('portal_usuarios')->where('cliente_id', (int) \$s->id)->exists()", $fuente);
    }
}
