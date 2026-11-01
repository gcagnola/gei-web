<?php

namespace Tests\Unit\Services;

use App\Services\ClienteMaestroFichaContratoCobolService as Ficha;
use Tests\TestCase;

final class ClienteMaestroFichaContratoFechaClaveTest extends TestCase
{
    public function test_inicio_ortiz_del_dia_veinte_coincide_con_el_operativo(): void
    {
        $this->assertSame('20260820', Ficha::fechaClave('20082026'));
        $this->assertSame('20260820', Ficha::fechaClave('20260820'));
        $this->assertSame('20260820', Ficha::fechaClave('2026-08-20'));
    }

    public function test_otras_fechas_que_comienzan_con_diecinueve_o_veinte(): void
    {
        $this->assertSame('20260819', Ficha::fechaClave('19082026'));
        $this->assertSame('20260920', Ficha::fechaClave('20092026'));
        $this->assertSame('20260821', Ficha::fechaClave('21082026'));
        $this->assertSame('20260905', Ficha::fechaClave('05092026'));
    }

    public function test_fechas_invalidas_no_se_convierten_en_otro_contrato(): void
    {
        $this->assertNull(Ficha::fechaClave('31022026'));
        $this->assertNull(Ficha::fechaClave('20260230'));
        $this->assertNull(Ficha::fechaClave('00000000'));
        $this->assertNull(Ficha::fechaClave(''));
    }
}
