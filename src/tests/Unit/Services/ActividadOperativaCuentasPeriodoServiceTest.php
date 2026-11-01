<?php

namespace Tests\Unit\Services;

use App\Services\ActividadOperativaCuentasPeriodoService;
use PHPUnit\Framework\TestCase;

final class ActividadOperativaCuentasPeriodoServiceTest extends TestCase
{
    public function test_cuenta_santa_fe_propietario(): void
    {
        $this->assertSame('PROPIETARIO', ActividadOperativaCuentasPeriodoService::dominioPorCuenta('12020466308'));
    }

    public function test_cuenta_santo_tome_inquilino(): void
    {
        $this->assertSame('INQUILINO', ActividadOperativaCuentasPeriodoService::dominioPorCuenta('21030313004'));
    }

    public function test_cuenta_desconocida_no_acredita_rol(): void
    {
        $this->assertNull(ActividadOperativaCuentasPeriodoService::dominioPorCuenta('99999999999'));
    }

    public function test_normalizar_preserva_ceros_y_descarta_caracteres(): void
    {
        $this->assertSame('012020466308', ActividadOperativaCuentasPeriodoService::normalizarCuenta('01-2020466308'));
    }
}
