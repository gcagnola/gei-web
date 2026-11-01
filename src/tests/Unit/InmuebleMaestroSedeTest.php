<?php

namespace Tests\Unit;

use App\Services\InmuebleMaestroConsultaService;
use PHPUnit\Framework\TestCase;

final class InmuebleMaestroSedeTest extends TestCase
{
    public function test_prefijos_unicos_para_cada_sucursal(): void
    {
        $s = new InmuebleMaestroConsultaService();
        self::assertSame(['1103', '1202'], $s->prefijosDeSedes(['SF']));
        self::assertSame(['2103', '2202'], $s->prefijosDeSedes(['ST']));
        self::assertSame(['1103', '1202', '2103', '2202'], $s->prefijosDeSedes(['SF', 'ST']));
    }

    public function test_sedes_desconocidas_no_generan_prefijos(): void
    {
        $s = new InmuebleMaestroConsultaService();
        self::assertSame([], $s->prefijosDeSedes(['DESCONOCIDA']));
    }

    public function test_fuentes_operativas_no_consultan_gei_core(): void
    {
        foreach ([
            __DIR__.'/../../app/Http/Controllers/InmuebleMaestroController.php',
            __DIR__.'/../../app/Services/InmuebleMaestroConsultaService.php',
        ] as $archivo) {
            self::assertStringNotContainsString('gei_core.', file_get_contents($archivo));
        }
    }
    public function test_rutas_de_inmuebles_no_invocan_el_controlador_historico(): void
    {
        $rutas = file_get_contents(__DIR__.'/../../routes/web.php');
        $inicio = strpos($rutas, "Route::get('/archivo/inmuebles-maestro'");
        $fin = strpos($rutas, "Route::get('/archivo/cuentas-corrientes'", $inicio);
        self::assertNotFalse($inicio);
        self::assertNotFalse($fin);
        $bloque = substr($rutas, $inicio, $fin - $inicio);
        self::assertStringNotContainsString('InmuebleExploracionController::class', $bloque);
        self::assertStringContainsString('inmuebles.maestro.index', $bloque);
        self::assertStringContainsString("abort(403, 'Cambio de sede heredado deshabilitado.')", $bloque);
    }

    public function test_revision_de_duplicados_no_unifica_datos(): void
    {
        $controller = file_get_contents(__DIR__.'/../../app/Http/Controllers/InmuebleMaestroController.php');
        $service = file_get_contents(__DIR__.'/../../app/Services/InmuebleMaestroConsultaService.php');
        self::assertStringContainsString('abort_unless($administradorGlobal, 403)', $controller);
        self::assertStringContainsString('public function posiblesDuplicados', $service);
        self::assertStringNotContainsString("DB::table('gei_core.", $service);
    }

    public function test_igual_domicilio_y_misma_cuenta_cobol_no_requiere_revision(): void
    {
        self::assertFalse(InmuebleMaestroConsultaService::requiereRevisionPorCuentas([
            ['12020821907'], ['1202/08219/07'], ['12020821907'],
        ]));
    }

    public function test_inmuebles_historicos_y_activos_con_misma_cuenta_no_generan_aviso(): void
    {
        self::assertFalse(InmuebleMaestroConsultaService::requiereRevisionPorCuentas([
            ['12020958205', '12020963401'], ['12020958205'],
        ]));
    }

    public function test_cuentas_distintas_requieren_revision(): void
    {
        self::assertTrue(InmuebleMaestroConsultaService::requiereRevisionPorCuentas([
            ['12020821907'], ['12020958205'],
        ]));
    }

    public function test_sin_evidencia_de_cuenta_no_se_oculta_el_aviso(): void
    {
        self::assertTrue(InmuebleMaestroConsultaService::requiereRevisionPorCuentas([
            ['12020821907'], [],
        ]));
    }

    public function test_no_se_comparan_cuit_ni_nombres_para_omitir_revisiones(): void
    {
        self::assertTrue(InmuebleMaestroConsultaService::requiereRevisionPorCuentas([
            ['12020821907'], ['22020092707'],
        ]));
        self::assertFalse(InmuebleMaestroConsultaService::requiereRevisionPorCuentas([
            ['12020821907'], ['12020821907'],
        ]));
    }
}
