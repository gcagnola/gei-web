<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CuentaCorrientePropietarioSaldoService;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

final class CuentaCorrientePropietarioSaldoServiceTest extends TestCase
{
    public function test_saldo_anterior_y_saldo_acreedor_sin_aplicar_intereses_de_inquilino(): void
    {
        $movimientos = [
            ['fecha'=>'2026-09-19','debe'=>'80.00','haber'=>'20.00','afecta_saldo'=>true,'numero'=>'1'],
            ['fecha'=>'2026-10-19','debe'=>'0.00','haber'=>'435748.00','afecta_saldo'=>true,'numero'=>'242758'],
            ['fecha'=>'2026-10-01','debe'=>'12936.93','haber'=>'0.00','afecta_saldo'=>true,'numero'=>'240799'],
            ['fecha'=>'2026-10-01','debe'=>'1358.37','haber'=>'0.00','afecta_saldo'=>true,'numero'=>'240800'],
            ['fecha'=>'2026-10-01','debe'=>'65827.80','haber'=>'0.00','afecta_saldo'=>true,'numero'=>'240798'],
            ['fecha'=>'2026-10-16','debe'=>'1804.00','haber'=>'0.00','afecta_saldo'=>true,'numero'=>'242313'],
            ['fecha'=>'2026-10-19','debe'=>'52725.51','haber'=>'0.00','afecta_saldo'=>true,'numero'=>'242759'],
            ['fecha'=>'2026-10-19','debe'=>'5536.17','haber'=>'0.00','afecta_saldo'=>true,'numero'=>'242760'],
            ['fecha'=>'2026-11-01','debe'=>'99999.00','haber'=>'0.00','afecta_saldo'=>true,'numero'=>'posterior'],
            ['fecha'=>'2026-10-11','debe'=>'250.00','haber'=>'0.00','afecta_saldo'=>false,'numero'=>'sin_saldo'],
        ];
        $r = CuentaCorrientePropietarioSaldoService::calcular($movimientos, '202610');
        $this->assertSame(8000, $r['debitos_anteriores']);
        $this->assertSame(2000, $r['creditos_anteriores']);
        $this->assertSame(6000, $r['saldo_anterior']);
        $this->assertCount(7, $r['movimientos']);
        $this->assertSame('240799', $r['movimientos'][0]['numero']);
        $this->assertSame('242758', $r['movimientos'][4]['numero']);
        $this->assertSame(6000 - 29555922, $r['saldo_actual']);
        $this->assertSame($r['saldo_actual'], $r['movimientos'][6]['saldo_centavos']);
    }

    public function test_sin_movimientos_del_mes_conserva_arrastre(): void
    {
        $r = CuentaCorrientePropietarioSaldoService::calcular([
            ['fecha'=>'2026-09-01','debe'=>'0.00','haber'=>'20.00'],
        ], '202610');
        $this->assertSame(-2000, $r['saldo_anterior']);
        $this->assertSame(-2000, $r['saldo_actual']);
        $this->assertSame([], $r['movimientos']);
    }

    public function test_rechaza_fecha_invalida_y_no_imputa_automaticamente(): void
    {
        $this->expectException(RuntimeException::class);
        CuentaCorrientePropietarioSaldoService::calcular([
            ['fecha'=>null,'debe'=>'10.00','haber'=>'0.00'],
        ], '202610');
    }

    public function test_rechaza_importe_malformado(): void
    {
        $this->expectException(RuntimeException::class);
        CuentaCorrientePropietarioSaldoService::calcular([
            ['fecha'=>'2026-10-01','debe'=>'10,00','haber'=>'0.00'],
        ], '202610');
    }

    public function test_rechaza_periodo_invalido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        CuentaCorrientePropietarioSaldoService::calcular([], '202613');
    }
}
