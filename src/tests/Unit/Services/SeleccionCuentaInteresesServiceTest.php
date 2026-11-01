<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SeleccionCuentaInteresesService;
use Tests\TestCase;

final class SeleccionCuentaInteresesServiceTest extends TestCase
{
    public function test_prioriza_la_cuenta_con_movimientos_del_mes(): void
    {
        $cuentas = collect(['11032276609', '11032286104']);
        $cierres = collect([
            'INQUILINO|11032276609' => ['saldos' => ['movimientos' => [], 'saldo_actual' => 0]],
            'INQUILINO|11032286104' => ['saldos' => ['movimientos' => [1, 2, 3, 4, 5], 'saldo_actual' => -463700]],
        ]);
        $this->assertSame('11032286104', SeleccionCuentaInteresesService::elegir($cuentas, $cierres));
    }

    public function test_sin_cierres_mantiene_la_primera_autorizada(): void
    {
        $this->assertSame('11032276609', SeleccionCuentaInteresesService::elegir(
            collect(['11032276609', '11032286104']), collect()
        ));
    }

    public function test_sin_movimientos_prioriza_el_saldo_existente(): void
    {
        $cierres = collect([
            'INQUILINO|11032276609' => ['saldos' => ['movimientos' => [], 'saldo_actual' => 0]],
            'INQUILINO|11032286104' => ['saldos' => ['movimientos' => [], 'saldo_actual' => -463700]],
        ]);
        $this->assertSame('11032286104', SeleccionCuentaInteresesService::elegir(
            collect(['11032276609', '11032286104']), $cierres
        ));
    }

    public function test_empate_conserva_orden_y_nunca_inventa_cuentas(): void
    {
        $cuentas = collect(['11032276609', '11032286104']);
        $cierres = collect([
            'INQUILINO|11032276609' => ['saldos' => ['movimientos' => [1], 'saldo_actual' => 100]],
            'INQUILINO|11032286104' => ['saldos' => ['movimientos' => [1], 'saldo_actual' => -100]],
            'INQUILINO|11039999999' => ['saldos' => ['movimientos' => [1,2,3], 'saldo_actual' => 10000000]],
        ]);
        $this->assertSame('11032276609', SeleccionCuentaInteresesService::elegir($cuentas, $cierres));
        $this->assertSame('', SeleccionCuentaInteresesService::elegir(collect(), $cierres));
    }
}
