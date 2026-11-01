<?php

namespace Tests\Unit;

use App\Http\Middleware\SoloAdministradorGlobal;
use App\Models\Perfil;
use App\Models\Usuario;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Estas pruebas usan el Gate real registrado por AppServiceProvider.
 * Sólo las sedes se simulan en memoria; no hay acceso a PostgreSQL.
 */
class SoloAdministradorGlobalTest extends TestCase
{
    private function peticion(?Usuario $usuario): Request
    {
        $request = Request::create('/facturas/1/pdf');
        $request->setUserResolver(static fn (?string $guard = null): ?Usuario => $usuario);

        return $request;
    }

    /** @param array<int, string> $sedes */
    private function usuarioConSedes(array $sedes, string $perfil = 'ADMINISTRADOR', bool $activo = true): Usuario
    {
        // No crear usuarios reales ni consultar usuarios_sucursales.
        $usuario = new class extends Usuario {
            /** @var array<int, string> */
            public array $sedesDePrueba = [];

            public function codigosSucursales(): array
            {
                return $this->sedesDePrueba;
            }
        };

        $usuario->forceFill(['activo' => $activo]);
        $usuario->setRelation('perfil', new Perfil([
            'codigo' => $perfil,
            'nombre' => $perfil,
            'activo' => true,
        ]));
        $usuario->sedesDePrueba = $sedes;

        return $usuario;
    }

    private function afirmarRechazo(Usuario $usuario): void
    {
        $request = $this->peticion($usuario);
        $this->assertSame($usuario, $request->user(), 'La prueba debe inyectar el usuario correctamente.');

        try {
            (new SoloAdministradorGlobal())->handle(
                $request,
                static fn () => response('No autorizado'),
            );
            $this->fail('Se esperaba un rechazo HTTP 403.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_rechaza_usuario_sin_permiso_administrativo(): void
    {
        $this->afirmarRechazo($this->usuarioConSedes(['SF', 'ST'], 'OPERADOR'));
    }

    public function test_rechaza_administrador_de_una_sola_sede(): void
    {
        $this->afirmarRechazo($this->usuarioConSedes(['ST']));
    }

    public function test_admite_administrador_con_ambas_sedes(): void
    {
        $usuario = $this->usuarioConSedes(['SF', 'ST']);
        $request = $this->peticion($usuario);
        $this->assertSame($usuario, $request->user());

        $respuesta = (new SoloAdministradorGlobal())->handle(
            $request,
            static fn () => response('Permitido'),
        );

        $this->assertSame(200, $respuesta->getStatusCode());
        $this->assertSame('Permitido', $respuesta->getContent());
    }

    public function test_rechaza_administrador_inactivo(): void
    {
        $this->afirmarRechazo($this->usuarioConSedes(['SF', 'ST'], 'ADMINISTRADOR', false));
    }

    public function test_rechaza_solicitud_sin_usuario(): void
    {
        try {
            (new SoloAdministradorGlobal())->handle(
                $this->peticion(null),
                static fn () => response('No autorizado'),
            );
            $this->fail('Se esperaba un rechazo HTTP 403.');
        } catch (HttpException $error) {
            $this->assertSame(403, $error->getStatusCode());
        }
    }

    public function test_rutas_legacy_y_escrituras_conceptos_tienen_control_explicito(): void
    {
        $rutas = [
            'facturas.pdf.ver',
            'comprobantes-arca.ver',
            'comprobantes-arca.lote.ver',
            'parametros.conceptos.store',
            'parametros.conceptos.update',
            'parametros.conceptos.destroy',
            'parametros.conceptos.caja.update',
        ];

        foreach ($rutas as $nombre) {
            $ruta = app('router')->getRoutes()->getByName($nombre);
            $this->assertNotNull($ruta, $nombre);
            $this->assertContains(SoloAdministradorGlobal::class, $ruta->gatherMiddleware(), $nombre);
        }
    }
}
