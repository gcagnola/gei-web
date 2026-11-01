<?php

namespace Tests\Unit\Services;

use App\Services\ClienteMaestroFichaContratoCobolService as Ficha;
use Tests\TestCase;

final class ClienteMaestroFichaContratoCobolServiceTest extends TestCase
{
    public function test_valida_par_de_cuentas_y_sede(): void
    {
        $this->assertTrue(Ficha::parValido('11032286104', '12020466308'));
        $this->assertTrue(Ficha::parValido('21030313004', '22020123456'));
        $this->assertFalse(Ficha::parValido('11032286104', '22020123456'));
        $this->assertFalse(Ficha::parValido('12020466308', '11032286104'));
        $this->assertFalse(Ficha::parValido('1103228610', '12020466308'));
    }

    public function test_fecha_cobol_y_fecha_operativa_identifican_el_mismo_contrato(): void
    {
        $this->assertSame('20261008', Ficha::fechaClave('20261008'));
        $this->assertSame('20261008', Ficha::fechaClave('2026-10-08'));
        $this->assertSame('20261008', Ficha::fechaClave('08102026'));
        $this->assertNull(Ficha::fechaClave('00000000'));
    }

    public function test_importe_pic_v99(): void
    {
        $this->assertSame(1234.56, Ficha::numeroV99('00000123456'));
        $this->assertSame(1234.56, Ficha::numeroV99('1234,56'));
        $this->assertSame(0.0, Ficha::numeroV99('000000000'));
        $this->assertNull(Ficha::numeroV99(''));
        $this->assertNull(Ficha::numeroV99('texto'));
    }

    public function test_porcentaje_pic_v1_signo(): void
    {
        $this->assertSame(-12.5, Ficha::numeroV1ConSigno('0125-'));
        $this->assertSame(12.5, Ficha::numeroV1ConSigno('0125+'));
        $this->assertSame(12.5, Ficha::numeroV1ConSigno('125'));
        $this->assertNull(Ficha::numeroV1ConSigno(''));
    }
}
