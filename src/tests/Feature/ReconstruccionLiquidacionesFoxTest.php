<?php

namespace Tests\Feature;

use App\Services\ReconstruccionLiquidacionesFoxService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReconstruccionLiquidacionesFoxTest extends TestCase
{
    private string $baseDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->baseDir = storage_path('framework/testing/gei-importador');
        @mkdir($this->baseDir.'/entrada/liquidaciones', 0777, true);
        config(['gei.importador.base_dir' => $this->baseDir]);

        $this->crearEsquema();
    }

    public function test_reconstruye_cabecera_desde_pliqloc_y_compara_numero_de_comprobante(): void
    {
        file_put_contents($this->baseDir.'/entrada/liquidaciones/pliqloc.sf.txt', "19/06/2026 A 00363083 1202/06829/01 ALEXAKIS NICOLAS 20108011433 931.577,55\n");
        file_put_contents($this->baseDir.'/entrada/liquidaciones/pliqloc.st.txt', '');

        DB::table('liquidaciones_de_clientes')->insert([
            'numero_de_liquidacion' => 1,
            'fecha' => '2026-06-19',
            'nro_cuenta' => 12020682901,
            'periodo' => 'Junio/2026',
            'numero_de_comprobante' => 363083,
            'total' => 931577.55,
            'subtotal' => 959677.71,
            'total_liquidado' => 931577.55,
        ]);

        $resultado = app(ReconstruccionLiquidacionesFoxService::class)->compararCabeceras();

        $this->assertSame(1, $resultado['fuente']);
        $this->assertSame(1, $resultado['coincidencias_exactas']);
        $this->assertSame(0, $resultado['no_encontradas']);
    }

    public function test_reconstruye_items_desde_liquida_y_respeta_numero_detalle(): void
    {
        file_put_contents($this->baseDir.'/entrada/liquidaciones/liquida.sf.txt', $this->listadoLiquidacion());
        file_put_contents($this->baseDir.'/entrada/liquidaciones/liquidb.sf.txt', '');
        file_put_contents($this->baseDir.'/entrada/liquidaciones/liquida.st.txt', '');
        file_put_contents($this->baseDir.'/entrada/liquidaciones/liquidb.st.txt', '');

        DB::table('liquidaciones_de_clientes')->insert([
            'numero_de_liquidacion' => 1,
            'fecha' => '2026-06-19',
            'nro_cuenta' => 12020682901,
            'periodo' => 'Junio/2026',
            'numero_de_comprobante' => 363083,
        ]);
        DB::table('liquidaciones_de_clientes_items')->insert([
            'numero_de_liquidacion' => 1,
            'numero_de_item' => 1,
            'fecha' => '2026-06-18',
            'numero_detalle' => 223768,
            'detalle' => 'H.IRIGOYEN 2210/16',
            'neto_no_gravado' => 1100000,
            'total' => 1100000,
            'tipo' => 'Crédito',
        ]);
        DB::table('liquidaciones_de_clientes_items')->insert([
            'numero_de_liquidacion' => 1,
            'numero_de_item' => 2,
            'fecha' => '2026-06-18',
            'numero_detalle' => 223769,
            'detalle' => '10,0% Comision p/Admin.Alquileres',
            'neto_gravado_21' => 110000,
            'total' => 133100,
            'tipo' => 'Débito',
        ]);

        $resultado = app(ReconstruccionLiquidacionesFoxService::class)->compararItems();

        $this->assertSame(2, $resultado['fuente']);
        $this->assertSame(2, $resultado['coincidencias_exactas'], json_encode($resultado['detalles'], JSON_UNESCAPED_UNICODE));
    }

    public function test_dailoc_usa_tercera_columna_del_total_para_item_pago_impuestos(): void
    {
        file_put_contents($this->baseDir.'/entrada/liquidaciones/dailoc.SF.txt', $this->listadoDailoc());

        DB::table('liquidaciones_de_clientes')->insert([
            'numero_de_liquidacion' => 1,
            'fecha' => '2026-06-19',
            'nro_cuenta' => 12020466308,
            'periodo' => 'Junio/2026',
            'numero_de_comprobante' => 363143,
        ]);
        DB::table('liquidaciones_de_clientes_items')->insert([
            'numero_de_liquidacion' => 1,
            'numero_de_item' => 1,
            'detalle' => 'Pago Imptos del mes s/detalle',
            'total' => 57055.50,
        ]);

        $resultado = app(ReconstruccionLiquidacionesFoxService::class)->compararDailoc('dailoc.SF.txt');

        $this->assertSame(1, $resultado['fuente']);
        $this->assertSame(1, $resultado['coincidencias_exactas']);
    }

    private function crearEsquema(): void
    {
        Schema::dropIfExists('liquidaciones_de_clientes_items');
        Schema::dropIfExists('liquidaciones_de_clientes');

        Schema::create('liquidaciones_de_clientes', function (Blueprint $table) {
            $table->integer('numero_de_liquidacion')->primary();
            $table->date('fecha')->nullable();
            $table->decimal('nro_cuenta', 11)->default(0);
            $table->string('periodo', 25)->default('');
            $table->decimal('numero_de_comprobante', 8)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('total_liquidado', 16, 2)->default(0);
        });

        Schema::create('liquidaciones_de_clientes_items', function (Blueprint $table) {
            $table->integer('numero_de_item')->primary();
            $table->integer('numero_de_liquidacion')->default(0);
            $table->date('fecha')->default('1900-01-01');
            $table->decimal('numero_detalle', 8)->default(0);
            $table->string('detalle', 100)->default('');
            $table->decimal('neto_no_gravado', 16, 2)->default(0);
            $table->decimal('neto_gravado_21', 16, 2)->default(0);
            $table->decimal('neto_gravado_105', 16, 2)->default(0);
            $table->decimal('neto_gravado_27', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->string('tipo', 10)->default('');
        });
    }

    private function listadoLiquidacion(): string
    {
        return implode("\n", [
            '                                           19/06/2026',
            '                     ALEXAKIS NICOLAS EUSTAQUIO',
            '        1202/06829/01                   JUNIO     2026',
            '                                           363083         **635',
            'EBERHARDT ANGEL LUIS Y ANGEL         H.IRIGOYEN 2210/16             31/03/27                          1.100.000,00',
            '                                     10,0% Comision p/Admin.Alquileres               110.000,00',
            '                          223768 18/06 223769 18/06',
            '',
        ]);
    }

    private function listadoDailoc(): string
    {
        return implode("\n", [
            '               120204663/08                                    *****1',
            '               ABALO HUGO SUC DE,BELLO H,FERNANDO              JUNIO     de 2026',
            '    SALV CAPUTO 3639 PB Y PA          AG.SANTAF  03/2026          21495/02 36.245,92              100,00 36.245,92',
            '                                                        TOTAL.........:    67.623,50    8.182,45         57.055,50',
            '',
        ]);
    }
}
