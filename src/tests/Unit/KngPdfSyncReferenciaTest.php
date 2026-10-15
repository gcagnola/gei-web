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

    public function test_si_el_pdf_ya_existe_en_debian_no_consulta_windows(): void
    {
        $carpeta = sys_get_temp_dir().'/gei_kng_pdf_'.bin2hex(random_bytes(6));
        mkdir($carpeta, 0700, true);
        $archivo = $carpeta.'/factura.pdf';
        file_put_contents($archivo, '%PDF-1.4\ncontenido');

        try {
            $metodo = new ReflectionMethod(KngPdfSyncService::class, 'origenSoloSiFaltaLocal');
            // Simulamos un recurso externo: cualquier consulta de existencia
            // sobre él incrementaría el contador, aunque devuelva "no existe".
            KngOrigenProbe::$consultas = 0;
            self::assertTrue(stream_wrapper_register('geikngprobe', KngOrigenProbe::class));
            try {
                $resultado = $metodo->invoke(new KngPdfSyncService(),
                    $archivo, 'geikngprobe://windows', '1267', 'factura.pdf');
                self::assertSame([true, null, null], $resultado);
                self::assertSame(0, KngOrigenProbe::$consultas);
            } finally {
                stream_wrapper_unregister('geikngprobe');
            }
        } finally {
            @unlink($archivo);
            @rmdir($carpeta);
        }
    }

    public function test_solo_busca_el_original_si_falta_o_esta_vacio_el_local(): void
    {
        $carpeta = sys_get_temp_dir().'/gei_kng_pdf_'.bin2hex(random_bytes(6));
        mkdir($carpeta.'/windows', 0700, true);
        $archivoLocal = $carpeta.'/copia.pdf';
        $archivoOriginal = $carpeta.'/windows/copia.pdf';
        file_put_contents($archivoOriginal, '%PDF-1.4\noriginal');

        try {
            $metodo = new ReflectionMethod(KngPdfSyncService::class, 'origenSoloSiFaltaLocal');
            $resultado = $metodo->invoke(new KngPdfSyncService(),
                $archivoLocal, $carpeta.'/windows', '1267', 'copia.pdf');
            self::assertSame([false, $archivoOriginal, filesize($archivoOriginal)], $resultado);

            file_put_contents($archivoLocal, '');
            $resultado = $metodo->invoke(new KngPdfSyncService(),
                $archivoLocal, $carpeta.'/windows', '1267', 'copia.pdf');
            self::assertSame([false, $archivoOriginal, filesize($archivoOriginal)], $resultado);
        } finally {
            @unlink($archivoLocal);
            @unlink($archivoOriginal);
            @rmdir($carpeta.'/windows');
            @rmdir($carpeta);
        }
    }
}

/** Simula consultas a un origen remoto, sin ninguna conexión de red. */
final class KngOrigenProbe
{
    public static int $consultas = 0;

    public function url_stat(string $path, int $flags): array|false
    {
        self::$consultas++;
        return false;
    }
}
