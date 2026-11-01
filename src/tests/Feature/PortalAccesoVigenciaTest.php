<?php

namespace Tests\Feature;

use App\Http\Middleware\AutenticarPortal;
use App\Models\PortalUsuario;
use App\Services\Portal\PortalAccesoService;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Tests\TestCase;

final class PortalAccesoVigenciaTest extends TestCase
{
    public function test_sesion_vigente_con_cliente_habilitado_continua(): void
    {
        $guard = Mockery::mock(StatefulGuard::class);
        $guard->shouldReceive('check')->once()->andReturn(true);
        $usuario = Mockery::mock(PortalUsuario::class)->makePartial();
        $vigente = new PortalUsuario();
        $usuario->shouldReceive('fresh')->once()->with(['cliente.roles'])->andReturn($vigente);
        $guard->shouldReceive('user')->once()->andReturn($usuario);
        $guard->shouldReceive('setUser')->once()->with($vigente);
        $guard->shouldNotReceive('logout');
        Auth::shouldReceive('guard')->once()->with('portal')->andReturn($guard);

        $servicio = Mockery::mock(PortalAccesoService::class)->makePartial();
        $servicio->shouldReceive('usuarioHabilitado')->once()->with($vigente)->andReturn(true);
        $request = Request::create('/autogestion', 'GET');

        $respuesta = (new AutenticarPortal($servicio))->handle($request, static fn () => response('correcto'));
        self::assertSame(200, $respuesta->getStatusCode());
        self::assertSame('correcto', $respuesta->getContent());
    }

    public function test_cliente_dado_de_baja_expulsa_sesion_preexistente(): void
    {
        $guard = Mockery::mock(StatefulGuard::class);
        $guard->shouldReceive('check')->once()->andReturn(true);
        $usuario = Mockery::mock(PortalUsuario::class)->makePartial();
        $vigente = new PortalUsuario();
        $usuario->shouldReceive('fresh')->once()->with(['cliente.roles'])->andReturn($vigente);
        $guard->shouldReceive('user')->once()->andReturn($usuario);
        $guard->shouldReceive('logout')->once();
        $guard->shouldNotReceive('setUser');
        Auth::shouldReceive('guard')->once()->with('portal')->andReturn($guard);

        $servicio = Mockery::mock(PortalAccesoService::class)->makePartial();
        $servicio->shouldReceive('usuarioHabilitado')->once()->with($vigente)->andReturn(false);
        $session = Mockery::mock(Store::class);
        $session->shouldReceive('invalidate')->once();
        $session->shouldReceive('regenerateToken')->once();

        $request = Request::create('/autogestion', 'GET');
        $request->setLaravelSession($session);

        $respuesta = (new AutenticarPortal($servicio))->handle(
            $request,
            static fn () => throw new \LogicException('No debe pasar al controlador')
        );
        self::assertSame(302, $respuesta->getStatusCode());
        self::assertStringContainsString('/login', $respuesta->headers->get('Location'));
    }

    public function test_sin_sesion_no_accede(): void
    {
        $guard = Mockery::mock(StatefulGuard::class);
        $guard->shouldReceive('check')->once()->andReturn(false);
        $guard->shouldNotReceive('user');
        Auth::shouldReceive('guard')->once()->with('portal')->andReturn($guard);

        $servicio = Mockery::mock(PortalAccesoService::class)->makePartial();
        $servicio->shouldNotReceive('usuarioHabilitado');
        $request = Request::create('/autogestion', 'GET');

        $respuesta = (new AutenticarPortal($servicio))->handle(
            $request,
            static fn () => throw new \LogicException('No debe pasar al controlador')
        );
        self::assertSame(302, $respuesta->getStatusCode());
    }
}
