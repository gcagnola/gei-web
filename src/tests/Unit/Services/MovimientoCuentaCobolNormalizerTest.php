<?php

namespace Tests\Unit\Services;

use App\Services\MovimientoCuentaCobolNormalizer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class MovimientoCuentaCobolNormalizerTest extends TestCase
{
    #[Test]
    public function interpreta_debito_de_inquilino_y_decimales_implicitos(): void
    {
        $datos = (new MovimientoCuentaCobolNormalizer())->inquilino((object) [
            'cuenta' => '11030873110',
            'fecha' => '20230102',
            'codigo' => '05',
            'numero' => '678650',
            'fecha_vencimiento' => '20230105',
            'importe' => '00003400000+',
            'importe_penalidad' => '00000000000+',
            'importe_abonado' => '00003400000+',
            'descripcion' => 'ALQUILER ENE. 2023',
            'liquidado' => 'N',
            'iva' => '0000000000',
            'no_iva' => '0000000000',
        ]);

        $this->assertSame('2023-01-02', $datos['fecha']);
        $this->assertSame('34000.00', ltrim($datos['importe'], '0'));
        $this->assertSame($datos['importe'], $datos['debe']);
        $this->assertSame('0.00', $datos['haber']);
        $this->assertSame('202301', $datos['periodo']);
    }

    #[Test]
    public function clasifica_movimientos_segun_los_limites_cobol(): void
    {
        $base = (object) [
            'cuenta' => '11030873110', 'fecha' => '20230102', 'codigo' => '90',
            'numero' => '000001', 'fecha_vencimiento' => '00000000',
            'importe' => '00000003000+', 'importe_penalidad' => '00000000000+',
            'importe_abonado' => '00000000000+', 'iva' => '0000000000', 'no_iva' => '0000000000',
        ];
        $inquilino = (new MovimientoCuentaCobolNormalizer())->inquilino($base);
        $this->assertSame('0.00', $inquilino['debe']);
        $this->assertSame($inquilino['importe'], $inquilino['haber']);

        $base->cuenta = '12020010006';
        $base->codigo = '29';
        $propietario = (new MovimientoCuentaCobolNormalizer())->propietario($base);
        $this->assertSame($propietario['importe'], $propietario['debe']);
        $this->assertSame('0.00', $propietario['haber']);

        $base->codigo = '20';
        $propietario = (new MovimientoCuentaCobolNormalizer())->propietario($base);
        $this->assertSame('0.00', $propietario['debe']);
        $this->assertSame($propietario['importe'], $propietario['haber']);

        $base->cuenta = '11030873110';
        $base->codigo = '50';
        $inquilino = (new MovimientoCuentaCobolNormalizer())->inquilino($base);
        $this->assertSame($inquilino['importe'], $inquilino['debe']);
        $this->assertSame('0.00', $inquilino['haber']);

        $base->cuenta = '12020010006';
        $base->codigo = '38';
        $propietario = (new MovimientoCuentaCobolNormalizer())->propietario($base);
        $this->assertSame('SIN_EFECTO', $propietario['tipo_movimiento']);
        $this->assertSame('0.00', $propietario['debe']);
        $this->assertSame('0.00', $propietario['haber']);
    }

    #[Test]
    public function conserva_fecha_invalida_como_advertencia(): void
    {
        $datos = (new MovimientoCuentaCobolNormalizer())->inquilino((object) [
            'cuenta' => '11032003307', 'fecha' => '06012220', 'codigo' => '48',
            'numero' => '000000', 'fecha_vencimiento' => '00000000',
            'importe' => '00000001000+', 'importe_penalidad' => '00000000000+',
            'importe_abonado' => '00000000000+', 'iva' => '0000000000', 'no_iva' => '0000000000',
        ]);

        $this->assertNull($datos['fecha']);
        $this->assertSame('06012220', $datos['fecha_origen']);
        $this->assertArrayHasKey('FECHA_MOVIMIENTO_INVALIDA', $datos['advertencias']);
    }
}
