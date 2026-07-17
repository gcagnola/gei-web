<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_periodos', function (Blueprint $table): void {
            $table->id();
            $table->char('periodo', 6)->unique();
            $table->date('fecha_desde')->nullable();
            $table->date('fecha_hasta')->nullable();
            $table->string('estado', 40)->default('ABIERTO');
            $table->timestampsTz();
        });

        Schema::create('web_liquidaciones_propietarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('periodo_id')->constrained('web_periodos');
            $table->foreignId('propietario_id')->constrained('web_propietarios');
            $table->string('cuenta_propietario', 20);
            $table->string('sede', 20)->nullable();
            $table->string('tipo_liquidacion', 40)->default('PROPIETARIO');
            $table->integer('punto_venta')->nullable();
            $table->unsignedBigInteger('numero')->nullable();
            $table->string('numero_comprobante', 40)->nullable();
            $table->string('numero_interno', 80)->nullable();
            $table->string('referencia', 80)->nullable();
            $table->date('fecha');
            $table->date('fecha_desde')->nullable();
            $table->date('fecha_hasta')->nullable();
            $table->string('moneda', 10)->default('ARS');
            $table->decimal('subtotal', 16, 2)->default(0);
            $table->decimal('total_debe', 16, 2)->default(0);
            $table->decimal('total_haber', 16, 2)->default(0);
            $table->decimal('neto_gravado', 16, 2)->default(0);
            $table->decimal('neto_no_gravado', 16, 2)->default(0);
            $table->decimal('iva', 16, 2)->default(0);
            $table->decimal('descuentos', 16, 2)->default(0);
            $table->decimal('recargos', 16, 2)->default(0);
            $table->decimal('total_final', 16, 2)->default(0);
            $table->string('forma_pago_codigo', 20)->nullable();
            $table->string('cuenta_deposito', 40)->nullable();
            $table->string('copropietario_nombre', 180)->nullable();
            $table->decimal('copropietario_porcentaje', 9, 6)->nullable();
            $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->char('hash_funcional', 64)->unique();
            $table->string('origen', 30)->default('COBOL');
            $table->foreignId('lote_importacion_id')->nullable()->constrained('web_lotes_importacion');
            $table->string('generado_por', 80)->nullable();
            $table->timestampTz('fecha_generacion')->nullable();
            $table->string('version_regla_calculo', 80);
            $table->string('estado', 40)->default('GENERADO');
            $table->timestampsTz();

            $table->index('periodo_id');
            $table->index('propietario_id');
            $table->index('fecha');
        });

        DB::statement("CREATE UNIQUE INDEX uq_web_liq_prop_numero ON web_liquidaciones_propietarios (periodo_id, propietario_id, COALESCE(sede, ''), numero) WHERE numero IS NOT NULL");

        Schema::create('web_liquidaciones_impuestos_servicios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('periodo_id')->nullable()->constrained('web_periodos');
            $table->string('sede', 20)->nullable();
            $table->string('cuenta_propietario', 20)->nullable();
            $table->string('cuenta_inquilino', 20)->nullable();
            $table->foreignId('propietario_id')->nullable()->constrained('web_propietarios');
            $table->foreignId('inquilino_id')->nullable()->constrained('web_inquilinos');
            $table->foreignId('contrato_id')->nullable()->constrained('web_contratos');
            $table->foreignId('inmueble_id')->nullable()->constrained('web_inmuebles');
            $table->string('tipo_servicio', 80);
            $table->string('concepto', 180);
            $table->text('detalle')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->decimal('importe', 16, 2)->default(0);
            $table->integer('orden_origen')->nullable();
            $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
            $table->foreignId('registro_origen_id')->constrained('web_registros_origen');
            $table->string('origen', 30)->default('COBOL');
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('NUEVO');
            $table->timestampsTz();

            $table->unique(['periodo_id', 'sede', 'registro_origen_id'], 'uq_web_liq_imp_serv_origen');
            $table->index(['cuenta_propietario', 'cuenta_inquilino'], 'ix_web_liq_imp_serv_cuentas');
            $table->index('inmueble_id');
        });

        Schema::create('web_liquidaciones_propietarios_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('liquidacion_id')->constrained('web_liquidaciones_propietarios');
            $table->unsignedInteger('orden');
            $table->string('tipo_item', 40);
            $table->foreignId('movimiento_cuenta_id')->nullable()->constrained('web_movimientos_cuenta');
            $table->foreignId('impuesto_servicio_id')->nullable()->constrained('web_liquidaciones_impuestos_servicios');
            $table->unsignedBigInteger('pago_id')->nullable();
            $table->unsignedBigInteger('factura_id')->nullable();
            $table->foreignId('contrato_id')->nullable()->constrained('web_contratos');
            $table->foreignId('inquilino_id')->nullable()->constrained('web_inquilinos');
            $table->foreignId('inmueble_id')->nullable()->constrained('web_inmuebles');
            $table->string('codigo_concepto', 20)->nullable();
            $table->string('concepto', 180);
            $table->text('detalle')->nullable();
            $table->string('referencia', 100)->nullable();
            $table->string('numero_movimiento', 20)->nullable();
            $table->string('numero_comprobante', 40)->nullable();
            $table->date('fecha')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->decimal('importe_origen', 16, 2)->nullable();
            $table->decimal('debe', 16, 2)->default(0);
            $table->decimal('haber', 16, 2)->default(0);
            $table->decimal('saldo', 16, 2)->nullable();
            $table->decimal('neto_gravado', 16, 2)->default(0);
            $table->decimal('neto_no_gravado', 16, 2)->default(0);
            $table->decimal('iva', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->char('hash_funcional', 64);
            $table->string('origen', 30)->default('COBOL');
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('GENERADO');
            $table->timestampsTz();

            $table->unique(['liquidacion_id', 'orden'], 'uq_web_liq_prop_items_orden');
            $table->unique(['liquidacion_id', 'hash_funcional'], 'uq_web_liq_prop_items_hash');
            $table->index('movimiento_cuenta_id');
            $table->index('contrato_id');
            $table->index('inmueble_id');
        });

        Schema::create('web_liquidaciones_pdfs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('liquidacion_id')->constrained('web_liquidaciones_propietarios');
            $table->string('nombre_archivo', 160);
            $table->string('ruta_pdf', 500);
            $table->char('hash_pdf', 64)->nullable();
            $table->timestampTz('fecha_generacion')->useCurrent();
            $table->string('version_generador', 80);
            $table->string('generado_por', 80)->nullable();
            $table->string('estado_pdf', 40)->default('GENERADO');
            $table->timestampsTz();

            $table->unique(['liquidacion_id', 'version_generador'], 'uq_web_liquidaciones_pdfs_version');
        });

        $this->checks();
    }

    public function down(): void
    {
        Schema::dropIfExists('web_liquidaciones_pdfs');
        Schema::dropIfExists('web_liquidaciones_propietarios_items');
        Schema::dropIfExists('web_liquidaciones_impuestos_servicios');
        Schema::dropIfExists('web_liquidaciones_propietarios');
        Schema::dropIfExists('web_periodos');
    }

    private function checks(): void
    {
        DB::statement("ALTER TABLE web_periodos ADD CONSTRAINT ck_web_periodos_periodo CHECK (periodo ~ '^[0-9]{6}$')");
        DB::statement("ALTER TABLE web_periodos ADD CONSTRAINT ck_web_periodos_estado CHECK (estado IN ('ABIERTO','CERRADO','HISTORICO','ANULADO'))");
        DB::statement("ALTER TABLE web_periodos ADD CONSTRAINT ck_web_periodos_fechas CHECK (fecha_desde IS NULL OR fecha_hasta IS NULL OR fecha_desde <= fecha_hasta)");

        DB::statement("ALTER TABLE web_liquidaciones_propietarios ADD CONSTRAINT ck_web_liq_prop_estado CHECK (estado IN ('NUEVO','GENERADO','EMITIDO','ANULADO','ERROR_DB'))");
        DB::statement("ALTER TABLE web_liquidaciones_propietarios ADD CONSTRAINT ck_web_liq_prop_origen CHECK (origen IN ('COBOL','FOX','DB_GEI','GEI_WEB','MANUAL','SISTEMA'))");
        DB::statement("ALTER TABLE web_liquidaciones_propietarios ADD CONSTRAINT ck_web_liq_prop_totales CHECK (total_debe >= 0 AND total_haber >= 0)");
        DB::statement("ALTER TABLE web_liquidaciones_propietarios ADD CONSTRAINT ck_web_liq_prop_copropietario CHECK (copropietario_porcentaje IS NULL OR (copropietario_porcentaje >= 0 AND copropietario_porcentaje <= 100))");

        DB::statement("ALTER TABLE web_liquidaciones_impuestos_servicios ADD CONSTRAINT ck_web_liq_imp_serv_estado CHECK (estado IN ('NUEVO','CONVERTIDO_EN_ITEM','USADO_SOLO_EN_PDF','DESCARTADO_POR_REGLA','SIN_RELACION','AMBIGUO','ANULADO'))");

        DB::statement("ALTER TABLE web_liquidaciones_propietarios_items ADD CONSTRAINT ck_web_liq_prop_items_importes CHECK (debe >= 0 AND haber >= 0)");
        DB::statement("ALTER TABLE web_liquidaciones_propietarios_items ADD CONSTRAINT ck_web_liq_prop_items_estado CHECK (estado IN ('NUEVO','GENERADO','ANULADO','ERROR_DB'))");

        DB::statement("ALTER TABLE web_liquidaciones_pdfs ADD CONSTRAINT ck_web_liquidaciones_pdfs_estado CHECK (estado_pdf IN ('GENERADO','ERROR_DB','ANULADO'))");
        DB::statement("ALTER TABLE web_liquidaciones_pdfs ADD CONSTRAINT ck_web_liquidaciones_pdfs_hash CHECK (hash_pdf IS NULL OR hash_pdf ~ '^[0-9a-f]{64}$')");
    }
};
