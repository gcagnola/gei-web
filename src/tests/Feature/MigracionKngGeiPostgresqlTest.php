<?php

namespace Tests\Feature;

use App\Models\Usuario;
use App\Services\MigracionKngGeiPostgresqlService;
use App\Services\ValidacionKngGeiPostgresqlService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\CreatesClienteSchema;
use Tests\TestCase;

class MigracionKngGeiPostgresqlTest extends TestCase
{
    use CreatesClienteSchema;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createClienteSchema();
        $this->createMigracionSchema();
    }

    public function test_simulacion_revierte_la_persistencia(): void
    {
        $importacionId = $this->crearImportacionConRegistros();

        $this->assertDatabaseCount('web_importaciones_registros', 5);
        $resultado = app(MigracionKngGeiPostgresqlService::class)->aplicar($importacionId, confirmar: false);

        $this->assertFalse($resultado['confirmado']);
        $this->assertSame(1, $resultado['propietarios']['insertados'], json_encode($resultado, JSON_UNESCAPED_UNICODE));
        $this->assertDatabaseMissing('clientes', ['id_prop' => 12020055006]);
        $this->assertDatabaseCount('web_migraciones_aplicaciones', 0);
    }

    public function test_confirma_persistencia_de_maestros_movimientos_y_liquidaciones(): void
    {
        $importacionId = $this->crearImportacionConRegistros();

        $resultado = app(MigracionKngGeiPostgresqlService::class)
            ->aplicar($importacionId, confirmar: true, componentes: ['clientes', 'movimientos', 'liquidaciones']);

        $this->assertTrue($resultado['confirmado']);
        $this->assertSame(1, $resultado['propietarios']['insertados']);
        $this->assertSame(1, $resultado['inquilinos']['insertados']);
        $this->assertSame(1, $resultado['movimientos_propietarios']['insertados']);
        $this->assertSame(1, $resultado['movimientos_inquilinos']['insertados']);
        $this->assertSame(1, $resultado['liquidaciones']['insertadas']);

        $this->assertDatabaseHas('clientes', [
            'id_prop' => 12020055006,
            'apellidos' => 'PROPIETARIO UNO',
        ]);
        $this->assertDatabaseHas('clientes', [
            'id_inq' => 22020055006,
            'apellidos' => 'INQUILINO UNO',
        ]);
        $this->assertDatabaseHas('movimientos_de_cuentas', [
            'id_prop' => 12020055006,
            'tipo_de_movimiento' => 'DEBE',
            'total' => 1234.56,
        ]);
        $this->assertDatabaseHas('movimientos_de_cuentas', [
            'id_inq' => 22020055006,
            'tipo_de_movimiento' => 'HABER',
            'total' => -500,
        ]);
        $this->assertDatabaseHas('liquidaciones_de_clientes', [
            'nro_cuenta' => 12020055006,
            'numero' => 25193,
            'numero_de_comprobante' => 25193,
        ]);
        $this->assertDatabaseCount('web_migraciones_aplicaciones', 5);
    }

    public function test_preflight_completo_no_es_apto_si_faltan_items_y_dailoc(): void
    {
        $importacionId = $this->crearImportacionConRegistros();

        $resultado = app(MigracionKngGeiPostgresqlService::class)->preflight($importacionId);

        $this->assertSame('NO_APTO_PARA_CONFIRMAR', $resultado['estado']);
        $this->assertStringContainsString('items estructurados', implode(' ', $resultado['bloqueantes']));
        $this->assertStringContainsString('dailoc', implode(' ', $resultado['bloqueantes']));
    }

    public function test_preflight_por_etapas_permite_maestros_movimientos_y_cabeceras(): void
    {
        $importacionId = $this->crearImportacionConRegistros();

        $resultado = app(MigracionKngGeiPostgresqlService::class)
            ->preflight($importacionId, ['clientes', 'movimientos', 'liquidaciones']);

        $this->assertSame('APTO_CON_ADVERTENCIAS', $resultado['estado']);
        $this->assertSame([], $resultado['bloqueantes']);
    }

    public function test_confirma_items_si_el_payload_los_trae_estructurados(): void
    {
        $importacionId = $this->crearImportacionConRegistros(items: true);

        $resultado = app(MigracionKngGeiPostgresqlService::class)
            ->aplicar($importacionId, confirmar: true, componentes: ['clientes', 'liquidaciones', 'items']);

        $this->assertSame(2, $resultado['items_liquidaciones']['insertados']);
        $this->assertDatabaseHas('liquidaciones_de_clientes_items', [
            'numero' => 25193,
            'detalle' => 'ALQUILER (223768 - 18/06/2026)',
            'total' => 110000,
        ]);
        $this->assertDatabaseHas('liquidaciones_de_clientes_items', [
            'numero' => 25193,
            'detalle' => 'COMISION (223769 - 18/06/2026)',
            'total' => -11000,
        ]);
    }

    public function test_actualizacion_conservadora_no_pisa_datos_actuales_completos(): void
    {
        DB::table('clientes')->insert([
            'id_prop' => 12020055006,
            'id_inq' => 0,
            'apellidos' => 'NOMBRE ANTERIOR',
            'nombres' => '',
            'razon_social' => 'NOMBRE ANTERIOR',
            'domicilio' => 'DOMICILIO ACTUAL',
            'telefonos' => 'TELEFONO ACTUAL',
            'cuit' => '20111111112',
        ]);

        $importacionId = $this->crearImportacion();
        $this->insertarRegistro($importacionId, 'PROPIETAR.TXT', 1, 'propietario', 12020055006, [
            'cuenta' => 12020055006,
            'nombre' => 'NOMBRE ACTUALIZADO',
            'domicilio' => 'DOMICILIO HISTORICO',
            'codigo_postal' => 3000,
            'localidad' => 'Santa Fe',
            'provincia' => 'Santa Fe',
            'telefono' => '',
            'identificacion_fiscal' => '',
            'personeria_fiscal' => 1,
        ]);

        app(MigracionKngGeiPostgresqlService::class)->aplicar($importacionId, confirmar: true, componentes: ['clientes']);

        $this->assertDatabaseHas('clientes', [
            'id_prop' => 12020055006,
            'apellidos' => 'NOMBRE ACTUALIZADO',
            'domicilio' => 'DOMICILIO ACTUAL',
            'telefonos' => 'TELEFONO ACTUAL',
            'cuit' => '20111111112',
        ]);
    }

    public function test_simulacion_omite_propietario_con_liquidaciones_historicas_sin_id_prop_exacto(): void
    {
        DB::table('clientes')->insert([
            'codigo_cliente' => 10,
            'id_prop' => 12020047507,
            'id_inq' => 0,
            'apellidos' => 'COPROPIETARIO EXISTENTE',
            'nombres' => '',
            'razon_social' => 'COPROPIETARIO EXISTENTE',
        ]);
        DB::table('liquidaciones_de_clientes')->insert([
            'punto_venta' => 0,
            'numero' => 363083,
            'fecha' => '2026-06-19',
            'codigo_cliente' => 10,
            'nro_cuenta' => 12020785408,
            'periodo' => 'Junio/2026',
            'numero_de_comprobante' => 363083,
        ]);

        $importacionId = $this->crearImportacion();
        $this->insertarRegistro($importacionId, 'PROPIETAR.TXT', 1, 'propietario', 12020785408, [
            'cuenta' => 12020785408,
            'nombre' => 'CAPALBO COPROPIETARIOS',
            'domicilio' => 'SAN MARTIN 1000',
            'codigo_postal' => 3000,
            'localidad' => 'Santa Fe',
            'provincia' => 'Santa Fe',
            'telefono' => '',
            'identificacion_fiscal' => '',
            'personeria_fiscal' => 1,
        ]);

        $resultado = app(MigracionKngGeiPostgresqlService::class)
            ->aplicar($importacionId, confirmar: false, componentes: ['clientes']);

        $this->assertSame(0, $resultado['propietarios']['insertados'], json_encode($resultado, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $resultado['propietarios']['omitidos']);
    }

    public function test_confirma_omite_propietario_con_liquidaciones_historicas_sin_id_prop_exacto(): void
    {
        DB::table('clientes')->insert([
            'codigo_cliente' => 10,
            'id_prop' => 12020047507,
            'id_inq' => 0,
            'apellidos' => 'COPROPIETARIO EXISTENTE',
            'nombres' => '',
            'razon_social' => 'COPROPIETARIO EXISTENTE',
        ]);
        DB::table('liquidaciones_de_clientes')->insert([
            'punto_venta' => 0,
            'numero' => 363083,
            'fecha' => '2026-06-19',
            'codigo_cliente' => 10,
            'nro_cuenta' => 12020785408,
            'periodo' => 'Junio/2026',
            'numero_de_comprobante' => 363083,
        ]);

        $importacionId = $this->crearImportacion();
        $this->insertarRegistro($importacionId, 'PROPIETAR.TXT', 1, 'propietario', 12020785408, [
            'cuenta' => 12020785408,
            'nombre' => 'CAPALBO COPROPIETARIOS',
            'domicilio' => 'SAN MARTIN 1000',
            'codigo_postal' => 3000,
            'localidad' => 'Santa Fe',
            'provincia' => 'Santa Fe',
            'telefono' => '',
            'identificacion_fiscal' => '',
            'personeria_fiscal' => 1,
        ]);

        $resultado = app(MigracionKngGeiPostgresqlService::class)
            ->aplicar($importacionId, confirmar: true, componentes: ['clientes']);

        $this->assertSame(0, $resultado['propietarios']['insertados'], json_encode($resultado, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $resultado['propietarios']['omitidos']);
        $this->assertDatabaseMissing('clientes', ['id_prop' => 12020785408]);
        $this->assertDatabaseHas('web_migraciones_aplicaciones', [
            'web_tipo' => 'propietario',
            'web_accion' => 'omitido_conflicto',
            'web_clave_destino' => '12020785408',
        ]);
    }

    public function test_confirma_actualiza_propietario_existente_por_inmueble_unico(): void
    {
        DB::table('clientes')->insert([
            'codigo_cliente' => 33,
            'id_prop' => 0,
            'id_inq' => 0,
            'apellidos' => 'PROPIETARIO EXISTENTE',
            'nombres' => '',
            'razon_social' => 'PROPIETARIO EXISTENTE',
            'domicilio' => 'DOMICILIO ACTUAL',
        ]);
        DB::table('inmuebles_propietarios')->insert([
            'codigo_inmueble' => 500,
            'codigo_cliente' => 33,
            'porcentaje_titularidad' => 100,
            'id_prop' => 12020055006,
        ]);

        $importacionId = $this->crearImportacion();
        $this->insertarRegistro($importacionId, 'PROPIETAR.TXT', 1, 'propietario', 12020055006, [
            'cuenta' => 12020055006,
            'nombre' => 'PROPIETARIO ACTUALIZADO',
            'domicilio' => 'DOMICILIO HISTORICO',
            'codigo_postal' => 3000,
            'localidad' => 'Santa Fe',
            'provincia' => 'Santa Fe',
            'telefono' => '',
            'identificacion_fiscal' => '',
            'personeria_fiscal' => 1,
        ]);

        $resultado = app(MigracionKngGeiPostgresqlService::class)
            ->aplicar($importacionId, confirmar: true, componentes: ['clientes']);

        $this->assertSame(1, $resultado['propietarios']['actualizados'], json_encode($resultado, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $resultado['propietarios']['insertados']);
        $this->assertDatabaseHas('clientes', [
            'codigo_cliente' => 33,
            'id_prop' => 12020055006,
            'apellidos' => 'PROPIETARIO ACTUALIZADO',
            'domicilio' => 'DOMICILIO ACTUAL',
        ]);
        $this->assertDatabaseHas('web_migraciones_aplicaciones', [
            'web_tipo' => 'propietario',
            'web_accion' => 'actualizado_por_inmueble',
            'web_clave_destino' => '33',
        ]);
    }

    public function test_confirma_omite_propietario_con_inmuebles_multiples(): void
    {
        DB::table('clientes')->insert([
            ['codigo_cliente' => 33, 'id_prop' => 0, 'id_inq' => 0, 'apellidos' => 'UNO', 'nombres' => '', 'razon_social' => 'UNO'],
            ['codigo_cliente' => 34, 'id_prop' => 0, 'id_inq' => 0, 'apellidos' => 'DOS', 'nombres' => '', 'razon_social' => 'DOS'],
        ]);
        DB::table('inmuebles_propietarios')->insert([
            ['codigo_inmueble' => 500, 'codigo_cliente' => 33, 'porcentaje_titularidad' => 50, 'id_prop' => 12020055006],
            ['codigo_inmueble' => 501, 'codigo_cliente' => 34, 'porcentaje_titularidad' => 50, 'id_prop' => 12020055006],
        ]);

        $importacionId = $this->crearImportacion();
        $this->insertarRegistro($importacionId, 'PROPIETAR.TXT', 1, 'propietario', 12020055006, [
            'cuenta' => 12020055006,
            'nombre' => 'PROPIETARIO CONFLICTIVO',
            'domicilio' => '',
            'codigo_postal' => '',
            'localidad' => '',
            'provincia' => '',
            'telefono' => '',
            'identificacion_fiscal' => '',
            'personeria_fiscal' => 0,
        ]);

        $resultado = app(MigracionKngGeiPostgresqlService::class)
            ->aplicar($importacionId, confirmar: true, componentes: ['clientes']);

        $this->assertSame(0, $resultado['propietarios']['insertados'], json_encode($resultado, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $resultado['propietarios']['omitidos']);
        $this->assertDatabaseMissing('clientes', ['id_prop' => 12020055006]);
        $this->assertDatabaseHas('web_migraciones_aplicaciones', [
            'web_tipo' => 'propietario',
            'web_accion' => 'omitido_conflicto',
            'web_clave_destino' => '12020055006',
        ]);
    }

    public function test_confirma_actualiza_inquilino_existente_por_contrato_unico(): void
    {
        DB::table('clientes')->insert([
            'codigo_cliente' => 22,
            'id_prop' => 0,
            'id_inq' => 0,
            'apellidos' => 'INQUILINO EXISTENTE',
            'nombres' => '',
            'razon_social' => 'INQUILINO EXISTENTE',
            'domicilio' => 'DOMICILIO ACTUAL',
        ]);
        DB::table('contratos_inquilinos')->insert([
            'codigo_contrato' => 100,
            'codigo_cliente' => 22,
            'porcentaje_participacion' => 100,
            'id_inq' => 22020055006,
        ]);

        $importacionId = $this->crearImportacion();
        $this->insertarRegistro($importacionId, 'INQUILINO.TXT', 1, 'inquilino', 22020055006, [
            'cuenta' => 22020055006,
            'nombre' => 'INQUILINO ACTUALIZADO',
            'domicilio_legal' => 'DOMICILIO HISTORICO',
            'codigo_postal' => 3000,
            'localidad' => 'Santa Fe',
            'provincia' => 'Santa Fe',
            'telefono_particular' => '',
            'telefono_laboral' => '',
            'tipo_documento' => 1,
            'documento' => '',
            'identificacion_fiscal' => '',
            'personeria_fiscal' => 1,
            'fecha_inicio' => '2026-06-01',
            'omitido_por_baja_antigua' => false,
        ]);

        $resultado = app(MigracionKngGeiPostgresqlService::class)
            ->aplicar($importacionId, confirmar: true, componentes: ['clientes']);

        $this->assertSame(1, $resultado['inquilinos']['actualizados'], json_encode($resultado, JSON_UNESCAPED_UNICODE));
        $this->assertSame(0, $resultado['inquilinos']['insertados']);
        $this->assertDatabaseHas('clientes', [
            'codigo_cliente' => 22,
            'id_inq' => 22020055006,
            'apellidos' => 'INQUILINO ACTUALIZADO',
            'domicilio' => 'DOMICILIO ACTUAL',
        ]);
        $this->assertDatabaseHas('web_migraciones_aplicaciones', [
            'web_tipo' => 'inquilino',
            'web_accion' => 'actualizado_por_contrato',
            'web_clave_destino' => '22',
        ]);
    }

    public function test_confirma_omite_inquilino_con_contratos_multiples(): void
    {
        DB::table('clientes')->insert([
            ['codigo_cliente' => 22, 'id_prop' => 0, 'id_inq' => 0, 'apellidos' => 'UNO', 'nombres' => '', 'razon_social' => 'UNO'],
            ['codigo_cliente' => 23, 'id_prop' => 0, 'id_inq' => 0, 'apellidos' => 'DOS', 'nombres' => '', 'razon_social' => 'DOS'],
        ]);
        DB::table('contratos_inquilinos')->insert([
            ['codigo_contrato' => 100, 'codigo_cliente' => 22, 'porcentaje_participacion' => 50, 'id_inq' => 22020055006],
            ['codigo_contrato' => 101, 'codigo_cliente' => 23, 'porcentaje_participacion' => 50, 'id_inq' => 22020055006],
        ]);

        $importacionId = $this->crearImportacion();
        $this->insertarRegistro($importacionId, 'INQUILINO.TXT', 1, 'inquilino', 22020055006, [
            'cuenta' => 22020055006,
            'nombre' => 'INQUILINO CONFLICTIVO',
            'domicilio_legal' => '',
            'codigo_postal' => '',
            'localidad' => '',
            'provincia' => '',
            'telefono_particular' => '',
            'telefono_laboral' => '',
            'tipo_documento' => 0,
            'documento' => '',
            'identificacion_fiscal' => '',
            'personeria_fiscal' => 0,
            'fecha_inicio' => null,
            'omitido_por_baja_antigua' => false,
        ]);

        $resultado = app(MigracionKngGeiPostgresqlService::class)
            ->aplicar($importacionId, confirmar: true, componentes: ['clientes']);

        $this->assertSame(0, $resultado['inquilinos']['insertados'], json_encode($resultado, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $resultado['inquilinos']['omitidos']);
        $this->assertDatabaseMissing('clientes', ['id_inq' => 22020055006]);
        $this->assertDatabaseHas('web_migraciones_aplicaciones', [
            'web_tipo' => 'inquilino',
            'web_accion' => 'omitido_conflicto',
            'web_clave_destino' => '22020055006',
        ]);
    }

    public function test_reimportacion_no_duplica_registros_aplicados(): void
    {
        $importacionId = $this->crearImportacionConRegistros();
        $servicio = app(MigracionKngGeiPostgresqlService::class);

        $servicio->aplicar($importacionId, confirmar: true, componentes: ['clientes', 'movimientos', 'liquidaciones']);
        $resultado = $servicio->aplicar($importacionId, confirmar: true, componentes: ['clientes', 'movimientos', 'liquidaciones']);

        $this->assertSame(1, $resultado['propietarios']['omitidos']);
        $this->assertSame(1, $resultado['inquilinos']['omitidos']);
        $this->assertSame(1, $resultado['movimientos_propietarios']['omitidos']);
        $this->assertSame(1, $resultado['movimientos_inquilinos']['omitidos']);
        $this->assertSame(1, $resultado['liquidaciones']['omitidas']);
        $this->assertDatabaseCount('clientes', 2);
        $this->assertDatabaseCount('movimientos_de_cuentas', 2);
        $this->assertDatabaseCount('liquidaciones_de_clientes', 1);
    }

    public function test_actualiza_propietario_existente_por_id_prop(): void
    {
        DB::table('clientes')->insert([
            'id_prop' => 12020055006,
            'id_inq' => 0,
            'apellidos' => 'NOMBRE ANTERIOR',
            'nombres' => '',
            'razon_social' => 'NOMBRE ANTERIOR',
        ]);

        $importacionId = $this->crearImportacion();
        $this->insertarRegistro($importacionId, 'PROPIETAR.TXT', 1, 'propietario', 12020055006, [
            'cuenta' => 12020055006,
            'nombre' => 'NOMBRE ACTUALIZADO',
            'domicilio' => 'SAN MARTIN 1000',
            'codigo_postal' => 3000,
            'localidad' => 'Santa Fe',
            'provincia' => 'Santa Fe',
            'telefono' => '342000000',
            'identificacion_fiscal' => '20123456789',
            'personeria_fiscal' => 1,
        ]);

        $resultado = app(MigracionKngGeiPostgresqlService::class)
            ->aplicar($importacionId, confirmar: true, componentes: ['clientes']);

        $this->assertSame(1, $resultado['propietarios']['actualizados']);
        $this->assertDatabaseHas('clientes', [
            'id_prop' => 12020055006,
            'apellidos' => 'NOMBRE ACTUALIZADO',
        ]);
        $this->assertDatabaseCount('clientes', 1);
    }

    public function test_validador_contra_fox_no_escribe_tablas_heredadas(): void
    {
        DB::table('clientes')->insert([
            'id_prop' => 12020055006,
            'id_inq' => 0,
            'apellidos' => 'PROPIETARIO UNO',
            'nombres' => '',
            'razon_social' => 'PROPIETARIO UNO',
            'domicilio' => 'SAN MARTIN 1000',
            'localidad' => 'Santa Fe',
            'provincia' => 'Santa Fe',
        ]);

        $importacionId = $this->crearImportacion();
        $this->insertarRegistro($importacionId, 'PROPIETAR.TXT', 1, 'propietario', 12020055006, [
            'cuenta' => 12020055006,
            'nombre' => 'PROPIETARIO UNO',
            'domicilio' => 'SAN MARTIN 1000',
            'codigo_postal' => 3000,
            'localidad' => 'Santa Fe',
            'provincia' => 'Santa Fe',
            'telefono' => '',
            'identificacion_fiscal' => '',
            'personeria_fiscal' => 1,
        ]);

        $resultado = app(ValidacionKngGeiPostgresqlService::class)->validar($importacionId, ['clientes']);

        $this->assertSame(1, $resultado['componentes']['propietarios']['registros_fuente']);
        $this->assertSame(1, $resultado['componentes']['propietarios']['coincidencias_exactas']);
        $this->assertDatabaseCount('clientes', 1);
        $this->assertDatabaseHas('web_validaciones_kng_gei_detalles', [
            'web_componente' => 'propietarios',
            'web_estado_comparacion' => 'COINCIDE_EXACTAMENTE',
        ]);
    }

    public function test_pantalla_ejecuta_simulacion_de_persistencia(): void
    {
        $importacionId = $this->crearImportacionConRegistros();

        $this->actingAs($this->usuario())
            ->post(route('archivo.actualizar-db.simular-persistencia-postgresql'), [
                'componente' => 'clientes',
            ])
            ->assertRedirect(route('archivo.actualizar-db'))
            ->assertSessionHas('resultado_actualizar_db');

        $this->assertDatabaseMissing('clientes', ['id_prop' => 12020055006]);
        $this->assertDatabaseCount('web_migraciones_aplicaciones', 0);
        $this->assertNotNull($importacionId);
    }

    private function createMigracionSchema(): void
    {
        foreach ([
            'web_migraciones_aplicaciones',
            'web_importaciones_registros',
            'web_importaciones_eventos',
            'web_importaciones_archivos',
            'web_importaciones',
            'web_validaciones_kng_gei_detalles',
            'web_validaciones_kng_gei',
            'movimientos_de_cuentas',
            'liquidaciones_de_clientes_items',
            'liquidaciones_impuestos_servicios',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('web_importaciones', function (Blueprint $table) {
            $table->id('web_id');
            $table->string('web_tipo', 60);
            $table->string('web_lote_hash', 64)->nullable();
            $table->string('web_estado', 40)->nullable();
            $table->timestamps();
        });

        Schema::create('web_importaciones_registros', function (Blueprint $table) {
            $table->id('web_id');
            $table->unsignedBigInteger('web_importacion_id');
            $table->string('web_archivo', 120);
            $table->unsignedInteger('web_linea');
            $table->string('web_tipo', 60);
            $table->string('web_clave', 80)->nullable();
            $table->string('web_periodo', 30)->nullable();
            $table->json('web_payload');
            $table->timestamps();
        });

        Schema::create('web_migraciones_aplicaciones', function (Blueprint $table) {
            $table->id('web_id');
            $table->unsignedBigInteger('web_importacion_id');
            $table->unsignedBigInteger('web_registro_id')->nullable();
            $table->string('web_tipo', 60);
            $table->string('web_componente', 40)->default('');
            $table->string('web_estado', 30)->default('confirmado');
            $table->boolean('web_simulado')->default(false);
            $table->boolean('web_confirmado')->default(true);
            $table->unsignedInteger('web_registros_leidos')->default(1);
            $table->unsignedInteger('web_insertados')->default(0);
            $table->unsignedInteger('web_actualizados')->default(0);
            $table->unsignedInteger('web_omitidos')->default(0);
            $table->unsignedInteger('web_errores')->default(0);
            $table->string('web_mapping_version', 80)->default('');
            $table->timestamp('web_inicio_en')->nullable();
            $table->timestamp('web_fin_en')->nullable();
            $table->unsignedInteger('web_usuario_id')->nullable();
            $table->string('web_tabla_destino', 100);
            $table->string('web_clave_destino', 160);
            $table->string('web_hash_origen', 64)->unique();
            $table->string('web_accion', 30);
            $table->json('web_payload')->nullable();
            $table->text('web_mensaje')->nullable();
            $table->timestamps();
        });

        Schema::create('web_validaciones_kng_gei', function (Blueprint $table) {
            $table->id('web_id');
            $table->unsignedBigInteger('web_importacion_id');
            $table->string('web_estado', 50);
            $table->string('web_version_mapeo', 80);
            $table->timestamp('web_inicio_en')->nullable();
            $table->timestamp('web_fin_en')->nullable();
            $table->json('web_resumen')->nullable();
            $table->text('web_mensaje')->nullable();
            $table->timestamps();
        });

        Schema::create('web_validaciones_kng_gei_detalles', function (Blueprint $table) {
            $table->id('web_id');
            $table->unsignedBigInteger('web_validacion_id');
            $table->unsignedBigInteger('web_importacion_id');
            $table->string('web_componente', 60);
            $table->string('web_tipo_registro', 80);
            $table->unsignedBigInteger('web_registro_staging_id')->nullable();
            $table->string('web_archivo', 120)->nullable();
            $table->unsignedInteger('web_linea')->nullable();
            $table->string('web_clave_interpretada', 160)->nullable();
            $table->string('web_clave_postgresql', 160)->nullable();
            $table->string('web_estado_comparacion', 50);
            $table->json('web_campos_iguales')->nullable();
            $table->json('web_campos_diferentes')->nullable();
            $table->string('web_severidad', 30)->default('info');
            $table->text('web_mensaje')->nullable();
            $table->string('web_version_mapeo', 80);
            $table->timestamp('web_fecha_validacion');
            $table->timestamps();
        });

        Schema::create('movimientos_de_cuentas', function (Blueprint $table) {
            $table->increments('id_mov');
            $table->decimal('id_prop', 11)->default(0);
            $table->decimal('id_inq', 11)->default(0);
            $table->integer('codigo_concepto')->default(0);
            $table->string('detalle', 120)->default('');
            $table->date('fecha')->default('1900-01-01');
            $table->integer('numero_detalle')->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->string('tipo', 15)->default('');
            $table->string('tipo_de_movimiento', 20)->default('');
            $table->text('observacion')->nullable();
        });

        Schema::create('liquidaciones_de_clientes_items', function (Blueprint $table) {
            $table->increments('numero_de_item');
            $table->integer('numero_de_liquidacion')->default(0);
            $table->decimal('punto_venta', 4)->default(0);
            $table->decimal('numero', 8)->default(0);
            $table->date('fecha')->default('1900-01-01');
            $table->integer('codigo_concepto')->default(0);
            $table->decimal('id_concepto', 12)->default(0);
            $table->decimal('numero_detalle', 8)->default(0);
            $table->string('detalle', 100)->default('');
            $table->decimal('neto_no_gravado', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->string('tipo', 10)->default('');
            $table->integer('codigo_inmueble')->default(0);
            $table->integer('codigo_contrato')->default(0);
        });

        Schema::create('liquidaciones_impuestos_servicios', function (Blueprint $table) {
            $table->increments('id_mov_impserv');
            $table->integer('numero_de_liquidacion')->default(0);
            $table->integer('numero_de_item')->default(0);
            $table->integer('codigo_inmueble')->default(0);
            $table->decimal('total', 16, 2)->default(0);
        });
    }

    private function crearImportacionConRegistros(bool $items = false): int
    {
        $importacionId = $this->crearImportacion();

        $this->insertarRegistro($importacionId, 'PROPIETAR.TXT', 1, 'propietario', 12020055006, [
            'cuenta' => 12020055006,
            'nombre' => 'PROPIETARIO UNO',
            'domicilio' => 'SAN MARTIN 1000',
            'codigo_postal' => 3000,
            'localidad' => 'Santa Fe',
            'provincia' => 'Santa Fe',
            'telefono' => '342000000',
            'identificacion_fiscal' => '20123456789',
            'personeria_fiscal' => 1,
        ]);
        $this->insertarRegistro($importacionId, 'INQUILINO.TXT', 1, 'inquilino', 22020055006, [
            'cuenta' => 22020055006,
            'nombre' => 'INQUILINO UNO',
            'domicilio_legal' => '25 DE MAYO 500',
            'codigo_postal' => 3000,
            'localidad' => 'Santa Fe',
            'provincia' => 'Santa Fe',
            'telefono_particular' => '342111111',
            'telefono_laboral' => '',
            'tipo_documento' => 1,
            'documento' => '12345678',
            'identificacion_fiscal' => '20123456789',
            'personeria_fiscal' => 1,
            'fecha_inicio' => '2026-06-01',
            'omitido_por_baja_antigua' => false,
        ]);
        $this->insertarRegistro($importacionId, 'CTACTEPRO.TXT', 1, 'cuenta_propietario', 12020055006, [
            'cuenta' => 12020055006,
            'fecha' => '2026-06-19',
            'numero_movimiento' => '12001234',
            'concepto' => 'ALQUILER',
            'debe' => '1234.56',
            'haber' => '0',
            'periodo' => '202606',
        ]);
        $this->insertarRegistro($importacionId, 'INQCTACTE.TXT', 1, 'cuenta_inquilino', 22020055006, [
            'cuenta' => 22020055006,
            'fecha' => null,
            'numero_movimiento' => '21000500',
            'concepto' => 'PAGO',
            'debe' => '0',
            'haber' => '500',
            'periodo' => '202606',
        ]);
        $payloadLiquidacion = [
            'cuenta' => 12020055006,
            'fecha' => '2026-06-19',
            'periodo' => 'Junio/2026',
            'numero_de_comprobante' => '25193',
            'tipo' => 'liquida',
        ];

        if ($items) {
            $payloadLiquidacion['items'] = [
                [
                    'detalle' => 'ALQUILER',
                    'debe' => '110000',
                    'haber' => '0',
                    'referencia' => '223768 - 18/06/2026',
                ],
                [
                    'detalle' => 'COMISION',
                    'debe' => '0',
                    'haber' => '11000',
                    'referencia' => '223769 - 18/06/2026',
                ],
            ];
        }

        $this->insertarRegistro($importacionId, 'liquida.sf.txt', 1, 'liquidacion_liquida', 12020055006, $payloadLiquidacion);

        return $importacionId;
    }

    private function crearImportacion(): int
    {
        return (int) DB::table('web_importaciones')->insertGetId([
            'web_tipo' => 'kng_gei',
            'web_lote_hash' => hash('sha256', (string) microtime(true)),
            'web_estado' => 'importado',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertarRegistro(
        int $importacionId,
        string $archivo,
        int $linea,
        string $tipo,
        int $clave,
        array $payload
    ): void {
        DB::table('web_importaciones_registros')->insert([
            'web_importacion_id' => $importacionId,
            'web_archivo' => $archivo,
            'web_linea' => $linea,
            'web_tipo' => $tipo,
            'web_clave' => (string) $clave,
            'web_periodo' => $payload['periodo'] ?? null,
            'web_payload' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function usuario(): Usuario
    {
        $usuario = new Usuario;
        $usuario->forceFill([
            'cod_usuario' => 1,
            'nombre' => 'ADMIN',
            'tipo_de_usuario' => 'Administrador',
        ]);
        $usuario->exists = true;

        return $usuario;
    }
}
