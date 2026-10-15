<?php

namespace Tests\Unit;

use App\Services\KngPdfSyncService;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class KngPdfSyncReferenciaTest extends TestCase
{
    public function test_construye_nombre_pdf_con_id_inq_y_lote(): void
    {
        $servicio = new KngPdfSyncService();
        $metodo = new ReflectionMethod(KngPdfSyncService::class, 'referenciaPdf');
        self::assertSame(
            ['FB-0038-00277178-11031906706.pdf', '1256'],
            $metodo->invoke($servicio, [
                'TIPO' => '6', 'P_VENTA' => '38', 'ID_FACTURA' => '277178',
                'ID_INQ' => '11031906706', 'CTA_ORIG' => '11039999999', 'LOTE' => '1256',
            ])
        );
    }

    public function test_usa_cta_orig_solo_si_falta_id_inq(): void
    {
        $servicio = new KngPdfSyncService();
        $metodo = new ReflectionMethod(KngPdfSyncService::class, 'referenciaPdf');
        self::assertSame(
            ['FA-0038-00066217-12020957110.pdf', '1230'],
            $metodo->invoke($servicio, [
                'TIPO' => '1', 'P_VENTA' => 38, 'ID_FACTURA' => 66217,
                'ID_INQ' => '0', 'CTA_ORIG' => '12020957110', 'LOTE' => 1230,
            ])
        );
    }

    public function test_filtra_el_ultimo_lote_sin_mezclar_otros(): void
    {
        $servicio = new KngPdfSyncService();
        $metodo = new ReflectionMethod(KngPdfSyncService::class, 'esDelLote');
        self::assertTrue($metodo->invoke($servicio, ['LOTE' => '001256'], 1256));
        self::assertFalse($metodo->invoke($servicio, ['LOTE' => '1255'], 1256));
        self::assertFalse($metodo->invoke($servicio, ['LOTE' => null], 1256));
    }

    public function test_descarta_referencias_incompletas(): void
    {
        $servicio = new KngPdfSyncService();
        $metodo = new ReflectionMethod(KngPdfSyncService::class, 'referenciaPdf');
        self::assertNull($metodo->invoke($servicio, [
            'TIPO' => '6', 'P_VENTA' => '38', 'ID_FACTURA' => '277178',
            'ID_INQ' => '11031906706', 'LOTE' => '',
        ]));
    }
}
