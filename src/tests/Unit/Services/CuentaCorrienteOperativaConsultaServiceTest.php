<?php

namespace Tests\Unit\Services;

use App\Services\CuentaCorrienteOperativaConsultaService;
use PHPUnit\Framework\TestCase;

final class CuentaCorrienteOperativaConsultaServiceTest extends TestCase
{
    public function test_santa_fe_solo_tiene_los_prefijos_de_su_sede(): void
    {
        $this->assertSame(['1103', '1202'], CuentaCorrienteOperativaConsultaService::prefijosSedes(['SF']));
    }

    public function test_santo_tome_solo_tiene_los_prefijos_de_su_sede(): void
    {
        $this->assertSame(['2103', '2202'], CuentaCorrienteOperativaConsultaService::prefijosSedes(['ST']));
    }

    public function test_varias_sedes_no_duplican_prefijos(): void
    {
        $this->assertSame(
            ['1103', '1202', '2103', '2202'],
            CuentaCorrienteOperativaConsultaService::prefijosSedes(['SF', 'ST', 'SF'])
        );
    }

    public function test_sede_desconocida_no_habilita_cuentas(): void
    {
        $this->assertSame([], CuentaCorrienteOperativaConsultaService::prefijosSedes(['XX']));
    }

    public function test_tipo_invalido_no_amplia_permisos(): void
    {
        $this->assertSame('PROPIETARIO', CuentaCorrienteOperativaConsultaService::tipoValido('TODOS'));
        $this->assertSame('INQUILINO', CuentaCorrienteOperativaConsultaService::tipoValido('inquilino'));
    }
}
