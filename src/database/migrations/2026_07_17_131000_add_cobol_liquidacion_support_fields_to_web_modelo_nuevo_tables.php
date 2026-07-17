<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_liquidaciones_propietarios', function (Blueprint $table): void {
            $table->foreignId('corrida_liquidacion_id')->nullable()->after('lote_importacion_id')->constrained('web_corridas_liquidacion');
            $table->foreignId('cotizacion_id')->nullable()->after('moneda')->constrained('web_cotizaciones');
            $table->decimal('cotizacion_aplicada', 18, 6)->nullable()->after('cotizacion_id');
            $table->string('letra_fiscal', 5)->nullable()->after('numero_comprobante');
            $table->string('tipo_comprobante', 40)->nullable()->after('letra_fiscal');
            $table->string('orden_liquidacion_nombre', 180)->nullable()->after('forma_pago_codigo');
            $table->string('variante_cobol', 20)->nullable()->after('generado_por');
            $table->jsonb('parametros_corrida')->default(DB::raw("'{}'::jsonb"))->after('variante_cobol');
        });

        Schema::table('web_liquidaciones_propietarios_items', function (Blueprint $table): void {
            $table->unsignedInteger('orden_cobol')->nullable()->after('orden');
            $table->unsignedInteger('orden_liquidacion')->nullable()->after('orden_cobol');
            $table->unsignedInteger('orden_impresion')->nullable()->after('orden_liquidacion');
            $table->unsignedInteger('secuencia_item')->nullable()->after('orden_impresion');
            $table->string('moneda_origen', 10)->nullable()->after('importe_origen');
            $table->decimal('importe_moneda_origen', 16, 2)->nullable()->after('moneda_origen');
            $table->foreignId('cotizacion_id')->nullable()->after('importe_moneda_origen')->constrained('web_cotizaciones');
            $table->decimal('cotizacion_aplicada', 18, 6)->nullable()->after('cotizacion_id');
            $table->decimal('importe_convertido', 16, 2)->nullable()->after('cotizacion_aplicada');
            $table->decimal('diferencia_cotizacion', 16, 2)->nullable()->after('importe_convertido');
            $table->string('letra_fiscal', 5)->nullable()->after('diferencia_cotizacion');
        });

        Schema::table('web_movimientos_cuenta', function (Blueprint $table): void {
            $table->foreignId('liquidado_por_liquidacion_id')->nullable()->after('liquidado_origen')->constrained('web_liquidaciones_propietarios');
            $table->timestampTz('liquidado_en')->nullable()->after('liquidado_por_liquidacion_id');
            $table->foreignId('corrida_liquidacion_id')->nullable()->after('liquidado_en')->constrained('web_corridas_liquidacion');
            $table->unsignedInteger('orden_cobol')->nullable()->after('corrida_liquidacion_id');
        });

        Schema::table('web_liquidaciones_propietarios', function (Blueprint $table): void {
            $table->index('corrida_liquidacion_id', 'ix_web_liq_prop_corrida');
        });

        Schema::table('web_liquidaciones_propietarios_items', function (Blueprint $table): void {
            $table->index(['liquidacion_id', 'orden_cobol'], 'ix_web_liq_items_orden_cobol');
        });

        Schema::table('web_movimientos_cuenta', function (Blueprint $table): void {
            $table->index('liquidado_por_liquidacion_id', 'ix_web_movimientos_liquidado_por');
        });

        $this->checks();
    }

    public function down(): void
    {
        Schema::table('web_movimientos_cuenta', function (Blueprint $table): void {
            $table->dropIndex('ix_web_movimientos_liquidado_por');
            $table->dropForeign(['liquidado_por_liquidacion_id']);
            $table->dropForeign(['corrida_liquidacion_id']);
            $table->dropColumn([
                'liquidado_por_liquidacion_id',
                'liquidado_en',
                'corrida_liquidacion_id',
                'orden_cobol',
            ]);
        });

        Schema::table('web_liquidaciones_propietarios_items', function (Blueprint $table): void {
            $table->dropIndex('ix_web_liq_items_orden_cobol');
            $table->dropForeign(['cotizacion_id']);
            $table->dropColumn([
                'orden_cobol',
                'orden_liquidacion',
                'orden_impresion',
                'secuencia_item',
                'moneda_origen',
                'importe_moneda_origen',
                'cotizacion_id',
                'cotizacion_aplicada',
                'importe_convertido',
                'diferencia_cotizacion',
                'letra_fiscal',
            ]);
        });

        Schema::table('web_liquidaciones_propietarios', function (Blueprint $table): void {
            $table->dropIndex('ix_web_liq_prop_corrida');
            $table->dropForeign(['corrida_liquidacion_id']);
            $table->dropForeign(['cotizacion_id']);
            $table->dropColumn([
                'corrida_liquidacion_id',
                'cotizacion_id',
                'cotizacion_aplicada',
                'letra_fiscal',
                'tipo_comprobante',
                'orden_liquidacion_nombre',
                'variante_cobol',
                'parametros_corrida',
            ]);
        });
    }

    private function checks(): void
    {
        DB::statement("ALTER TABLE web_liquidaciones_propietarios ADD CONSTRAINT ck_web_liq_prop_letra_fiscal CHECK (letra_fiscal IS NULL OR letra_fiscal IN ('A','B','C','M','X'))");
        DB::statement("ALTER TABLE web_liquidaciones_propietarios ADD CONSTRAINT ck_web_liq_prop_variante_cobol CHECK (variante_cobol IS NULL OR variante_cobol IN ('GIMB132','GIMB133','GIMB134','GEI_WEB'))");
        DB::statement("ALTER TABLE web_liquidaciones_propietarios ADD CONSTRAINT ck_web_liq_prop_cotizacion CHECK (cotizacion_aplicada IS NULL OR cotizacion_aplicada > 0)");
        DB::statement("ALTER TABLE web_liquidaciones_propietarios_items ADD CONSTRAINT ck_web_liq_items_cotizacion CHECK (cotizacion_aplicada IS NULL OR cotizacion_aplicada > 0)");
    }
};

