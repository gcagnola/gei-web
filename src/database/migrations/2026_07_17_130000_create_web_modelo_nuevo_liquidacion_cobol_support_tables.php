<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_monedas', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 10);
            $table->string('codigo_cobol', 5)->nullable();
            $table->string('nombre', 80);
            $table->string('simbolo', 10)->nullable();
            $table->string('origen', 30)->default('COBOL');
            $table->string('estado', 40)->default('ACTIVO');
            $table->timestampsTz();

            $table->unique('codigo', 'uq_web_monedas_codigo');
            $table->unique('codigo_cobol', 'uq_web_monedas_codigo_cobol');
        });

        Schema::create('web_cotizaciones', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('moneda_id')->constrained('web_monedas');
            $table->date('fecha');
            $table->decimal('valor', 18, 6);
            $table->string('fuente', 80)->nullable();
            $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->string('origen', 30)->default('COBOL');
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('ACTIVO');
            $table->timestampsTz();

            $table->unique(['moneda_id', 'fecha', 'origen', 'version_regla'], 'uq_web_cotizaciones');
            $table->index('fecha');
        });

        Schema::create('web_corridas_liquidacion', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lote_importacion_id')->nullable()->constrained('web_lotes_importacion');
            $table->foreignId('periodo_id')->nullable()->constrained('web_periodos');
            $table->string('sede', 20)->nullable();
            $table->string('tipo_corrida', 40);
            $table->string('variante_cobol', 20);
            $table->string('programa_preparacion', 40)->nullable();
            $table->string('programa_generacion', 40)->default('GIMB23');
            $table->date('fecha_liquidacion')->nullable();
            $table->string('moneda_codigo', 10)->nullable();
            $table->foreignId('cotizacion_id')->nullable()->constrained('web_cotizaciones');
            $table->decimal('cotizacion_ingresada', 18, 6)->nullable();
            $table->string('mensual_quincenal', 20)->nullable();
            $table->string('forma_pago_codigo', 20)->nullable();
            $table->jsonb('cuentas_seleccionadas')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('parametros')->default(DB::raw("'{}'::jsonb"));
            $table->char('hash_parametros', 64);
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->timestampTz('fecha_inicio')->useCurrent();
            $table->timestampTz('fecha_fin')->nullable();
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('NUEVO');
            $table->timestampsTz();

            $table->unique('hash_parametros', 'uq_web_corridas_liquidacion_hash');
            $table->index('periodo_id');
            $table->index('estado');
        });

        Schema::create('web_ordenes_no_liquidar', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('periodo_id')->nullable()->constrained('web_periodos');
            $table->foreignId('propietario_id')->nullable()->constrained('web_propietarios');
            $table->string('cuenta_propietario', 20);
            $table->string('clave_movimiento', 80)->nullable();
            $table->date('fecha_desde')->nullable();
            $table->date('fecha_hasta')->nullable();
            $table->text('motivo')->nullable();
            $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->string('origen', 30)->default('COBOL');
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('ACTIVO');
            $table->timestampsTz();

            $table->index('cuenta_propietario');
        });

        DB::statement("CREATE UNIQUE INDEX uq_web_ordenes_no_liquidar ON web_ordenes_no_liquidar (cuenta_propietario, COALESCE(periodo_id, 0), COALESCE(clave_movimiento, ''))");

        Schema::create('web_correlativos', function (Blueprint $table): void {
            $table->id();
            $table->string('dominio', 40);
            $table->string('nombre', 80);
            $table->unsignedBigInteger('ultimo_numero')->default(0);
            $table->date('fecha_desde')->nullable();
            $table->date('fecha_hasta')->nullable();
            $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->string('origen', 30)->default('COBOL');
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('ACTIVO');
            $table->timestampsTz();
        });

        DB::statement("CREATE UNIQUE INDEX uq_web_correlativos ON web_correlativos (dominio, nombre, COALESCE(fecha_desde, DATE '0001-01-01'))");

        Schema::create('web_liquidaciones_movimientos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('liquidacion_id')->constrained('web_liquidaciones_propietarios');
            $table->foreignId('liquidacion_item_id')->nullable()->constrained('web_liquidaciones_propietarios_items');
            $table->foreignId('movimiento_cuenta_id')->constrained('web_movimientos_cuenta');
            $table->foreignId('corrida_liquidacion_id')->nullable()->constrained('web_corridas_liquidacion');
            $table->timestampTz('fecha_consumo')->useCurrent();
            $table->string('liquidado_origen_anterior', 5)->nullable();
            $table->string('liquidado_origen_nuevo', 5)->nullable();
            $table->timestampTz('reversado_en')->nullable();
            $table->text('motivo_reversion')->nullable();
            $table->string('origen', 30)->default('GEI_WEB');
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('CONSUMIDO');
            $table->timestampsTz();

            $table->unique(['movimiento_cuenta_id', 'estado'], 'uq_web_liq_movimiento_activo');
            $table->index('liquidacion_id');
            $table->index('corrida_liquidacion_id');
        });

        $this->checks();
    }

    public function down(): void
    {
        Schema::dropIfExists('web_liquidaciones_movimientos');
        Schema::dropIfExists('web_correlativos');
        Schema::dropIfExists('web_ordenes_no_liquidar');
        Schema::dropIfExists('web_corridas_liquidacion');
        Schema::dropIfExists('web_cotizaciones');
        Schema::dropIfExists('web_monedas');
    }

    private function checks(): void
    {
        DB::statement("ALTER TABLE web_monedas ADD CONSTRAINT ck_web_monedas_estado CHECK (estado IN ('ACTIVO','INACTIVO','HISTORICO','ANULADO'))");
        DB::statement("ALTER TABLE web_cotizaciones ADD CONSTRAINT ck_web_cotizaciones_valor CHECK (valor > 0)");
        DB::statement("ALTER TABLE web_cotizaciones ADD CONSTRAINT ck_web_cotizaciones_estado CHECK (estado IN ('ACTIVO','INACTIVO','HISTORICO','ANULADO'))");

        DB::statement("ALTER TABLE web_corridas_liquidacion ADD CONSTRAINT ck_web_corridas_tipo CHECK (tipo_corrida IN ('TODOS','FORMA_PAGO','CUENTAS'))");
        DB::statement("ALTER TABLE web_corridas_liquidacion ADD CONSTRAINT ck_web_corridas_variante CHECK (variante_cobol IN ('GIMB132','GIMB133','GIMB134','GEI_WEB'))");
        DB::statement("ALTER TABLE web_corridas_liquidacion ADD CONSTRAINT ck_web_corridas_mensual CHECK (mensual_quincenal IS NULL OR mensual_quincenal IN ('MENSUAL','QUINCENAL'))");
        DB::statement("ALTER TABLE web_corridas_liquidacion ADD CONSTRAINT ck_web_corridas_estado CHECK (estado IN ('NUEVO','PREPARANDO','PREPARADO','GENERANDO','GENERADO','ANULADO','ERROR_DB','ERROR_VALIDACION'))");
        DB::statement("ALTER TABLE web_corridas_liquidacion ADD CONSTRAINT ck_web_corridas_hash CHECK (hash_parametros ~ '^[0-9a-f]{64}$')");

        DB::statement("ALTER TABLE web_ordenes_no_liquidar ADD CONSTRAINT ck_web_ordenes_no_liquidar_estado CHECK (estado IN ('ACTIVO','INACTIVO','ANULADO','HISTORICO'))");
        DB::statement("ALTER TABLE web_ordenes_no_liquidar ADD CONSTRAINT ck_web_ordenes_no_liquidar_fechas CHECK (fecha_desde IS NULL OR fecha_hasta IS NULL OR fecha_desde <= fecha_hasta)");

        DB::statement("ALTER TABLE web_correlativos ADD CONSTRAINT ck_web_correlativos_estado CHECK (estado IN ('ACTIVO','INACTIVO','HISTORICO','ANULADO'))");
        DB::statement("ALTER TABLE web_correlativos ADD CONSTRAINT ck_web_correlativos_fechas CHECK (fecha_desde IS NULL OR fecha_hasta IS NULL OR fecha_desde <= fecha_hasta)");

        DB::statement("ALTER TABLE web_liquidaciones_movimientos ADD CONSTRAINT ck_web_liq_movimientos_estado CHECK (estado IN ('CONSUMIDO','REVERSADO','ANULADO','ERROR_DB'))");
    }
};

