<?php

namespace Tests\Unit\Services;

use App\Services\CuentaCorrienteSaldosClienteService;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Tests\TestCase;

final class CuentaCorrienteSaldosClienteServiceTest extends TestCase
{
    public function test_dos_roles_distintos_no_se_mezclan(): void
    {
        $cuentas = collect([
            (object) ['rol' => 'INQUILINO', 'cuenta' => '11032422707'],
            (object) ['rol' => 'PROPIETARIO', 'cuenta' => '12020046107'],
            (object) ['rol' => 'INQUILINO', 'cuenta' => '11032422707'],
        ]);
        $this->assertSame([
            ['rol' => 'INQUILINO', 'cuenta' => '11032422707'],
            ['rol' => 'PROPIETARIO', 'cuenta' => '12020046107'],
        ], CuentaCorrienteSaldosClienteService::clavesAutorizadas($cuentas));
    }

    public function test_ignora_garante(): void
    {
        $this->assertSame([], CuentaCorrienteSaldosClienteService::clavesAutorizadas(
            collect([(object) ['rol' => 'GARANTE', 'cuenta' => '11032422707']])
        ));
    }

    public function test_rechaza_sede_o_rol_incompatible(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CuentaCorrienteSaldosClienteService::clavesAutorizadas(
            collect([(object) ['rol' => 'PROPIETARIO', 'cuenta' => '11032422707']])
        );
    }

    public function test_rechaza_cuenta_malformada(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CuentaCorrienteSaldosClienteService::clavesAutorizadas(
            collect([(object) ['rol' => 'INQUILINO', 'cuenta' => '1103/24227/07']])
        );
    }

    public function test_consulta_sin_cuentas_no_accede_a_db(): void
    {
        $this->assertTrue(app(CuentaCorrienteSaldosClienteService::class)
            ->consultar(collect(), '202610')->isEmpty());
    }

    public function test_rechaza_mes_invalido_antes_de_consultar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(CuentaCorrienteSaldosClienteService::class)->consultar(collect(), '202613');
    }
}
