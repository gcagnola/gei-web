<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Test sin dependencias de PostgreSQL: verifica que esta interfaz sea solo lectura. */
final class InmuebleConsolidacionLecturaTest extends TestCase
{
    public function test_ruta_de_comparacion_es_get_y_restringida(): void
    {
        $rutas = file_get_contents(__DIR__.'/../../routes/web.php');
        self::assertStringContainsString("Route::get('/archivo/inmuebles-maestro/consolidar/comparar'", $rutas);
        self::assertStringContainsString("->name('inmuebles.maestro.consolidar.comparar')", $rutas);
        self::assertStringContainsString("->middleware('can:administrar-unificaciones')", $rutas);
        self::assertStringNotContainsString("Route::post('/archivo/inmuebles-maestro/consolidar", $rutas);
    }

    public function test_controlador_exige_administrador_de_dos_sedes_y_solo_compara(): void
    {
        $controlador = file_get_contents(__DIR__.'/../../app/Http/Controllers/InmuebleConsolidacionConsultaController.php');
        self::assertStringContainsString("in_array('SF', \$permitidas, true) && in_array('ST', \$permitidas, true)", $controlador);
        self::assertStringContainsString('$service->comparar', $controlador);
        self::assertStringNotContainsString('$service->unificar', $controlador);
        self::assertStringNotContainsString('DB::', $controlador);
        self::assertStringNotContainsString('gei_core.', $controlador);
    }

    public function test_vista_no_tiene_confirmacion_ni_formulario_post(): void
    {
        $vista = file_get_contents(__DIR__.'/../../resources/views/inmuebles-maestro/comparar.blade.php');
        self::assertStringContainsString('SOLO CONSULTA', $vista);
        self::assertStringNotContainsString('method="POST"', $vista);
        self::assertStringNotContainsString('name="confirmacion"', $vista);
    }
}
