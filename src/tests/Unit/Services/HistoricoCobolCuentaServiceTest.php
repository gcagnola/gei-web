<?php

namespace Tests\Unit\Services;

use App\Services\HistoricoCobolCuentaService;
use Tests\TestCase;

final class HistoricoCobolCuentaServiceTest extends TestCase
{
    public function test_rechaza_rol_que_no_corresponde_sin_abrir_conexion(): void
    {
        $this->assertCount(0, app(HistoricoCobolCuentaService::class)->consultar('GARANTE', '11030000001'));
    }

    public function test_rechaza_cuenta_malformada_sin_abrir_conexion(): void
    {
        $this->assertCount(0, app(HistoricoCobolCuentaService::class)->consultar('INQUILINO', '1103-0000001'));
    }
}
