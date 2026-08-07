<?php

namespace Tests\Unit\Services;

use App\Services\ContratoCobolNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContratoCobolNormalizerTest extends TestCase
{
    #[Test]
    public function interpreta_fechas_importes_y_estado_de_baja(): void
    {
        $normalizado = (new ContratoCobolNormalizer())->inquilino((object) [
            'cta_inquilino' => '11030013206',
            'cta_propietario' => '12020347808',
            'fecha_contrato' => '01101989',
            'fecha_vencimiento' => '30091990',
            'fecha_inicio' => '01101989',
            'fecha_primer_ajuste' => '01111989',
            'fecha_baja' => '09081990',
            'marca_baja' => 'B',
            'cuota_1' => '0000004900',
            'cuota_2' => '0000005600',
            'comision_importe' => '1000',
            'filler' => '00200',
        ]);

        self::assertSame('1989-10-01', $normalizado['fecha_contrato']);
        self::assertSame('1990-09-30', $normalizado['fecha_fin']);
        self::assertSame('49.00', $normalizado['cuota_1']);
        self::assertSame('56.00', $normalizado['cuota_2']);
        self::assertSame('10.00', $normalizado['comision_impuestos']);
        self::assertSame('2.00', $normalizado['cotizacion_dolar']);
        self::assertSame('BAJA', $normalizado['estado']);
        self::assertFalse($normalizado['activo']);
    }

    #[Test]
    public function distingue_la_redefinicion_fecha_de_los_porcentajes_de_impuestos(): void
    {
        $normalizer = new ContratoCobolNormalizer();
        $conFecha = $normalizer->inquilino((object) [
            'cta_inquilino' => '11030000001',
            'cta_propietario' => '12020000001',
            'fecha_celebracion_redefine' => '15062024',
            'copias_contrato_redefine' => '03',
            'impuesto_porcentaje_1' => '150',
        ]);
        $conImpuestos = $normalizer->inquilino((object) [
            'cta_inquilino' => '11030000002',
            'cta_propietario' => '12020000002',
            'fecha_celebracion_redefine' => '01002003',
            'impuesto_porcentaje_1' => '010',
            'impuesto_porcentaje_2' => '020',
        ]);

        self::assertSame('2024-06-15', $conFecha['fecha_celebracion']);
        self::assertSame(3, $conFecha['copias_contrato']);
        self::assertSame([], $conFecha['impuestos_porcentajes']);
        self::assertNull($conImpuestos['fecha_celebracion']);
        self::assertSame([1 => 10, 2 => 20], $conImpuestos['impuestos_porcentajes']);
    }

    #[Test]
    public function conserva_el_contrato_y_advierte_una_fecha_imposible(): void
    {
        $normalizado = (new ContratoCobolNormalizer())->inquilino((object) [
            'cta_inquilino' => '11031244404',
            'cta_propietario' => '12020250002',
            'fecha_contrato' => '10111988',
            'fecha_vencimiento' => '31111990',
        ]);

        self::assertNull($normalizado['fecha_fin']);
        self::assertArrayHasKey('FECHA_VENCIMIENTO_INVALIDA', $normalizado['advertencias']);
        self::assertNotSame('', $normalizado['clave_contrato']);
    }

    #[Test]
    public function interpreta_porcentaje_con_signo_final(): void
    {
        $normalizado = (new ContratoCobolNormalizer())->inquilino((object) [
            'cta_inquilino' => '11030000003',
            'cta_propietario' => '12020000003',
            'ajuste_1_fecha' => '01012025',
            'ajuste_1_porcentaje' => '0250+',
        ]);

        self::assertSame('2025-01-01', $normalizado['ajustes_adicionales'][0]['fecha']);
        self::assertSame('25.0', $normalizado['ajustes_adicionales'][0]['porcentaje']);
    }
}
