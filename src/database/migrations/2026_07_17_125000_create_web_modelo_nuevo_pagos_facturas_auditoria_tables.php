<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_facturas', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo', 20);
            $table->integer('punto_venta')->nullable();
            $table->unsignedBigInteger('numero')->nullable();
            $table->date('fecha');
            $table->foreignId('persona_id')->nullable()->constrained('web_personas');
            $table->foreignId('liquidacion_id')->nullable()->constrained('web_liquidaciones_propietarios');
            $table->decimal('neto_gravado', 16, 2)->default(0);
            $table->decimal('neto_no_gravado', 16, 2)->default(0);
            $table->decimal('iva', 16, 2)->default(0);
            $table->decimal('total', 16, 2)->default(0);
            $table->string('origen', 30)->default('GEI_WEB');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('GENERADO');
            $table->timestampsTz();

            $table->unique(['tipo', 'punto_venta', 'numero'], 'uq_web_facturas_numero');
            $table->index('liquidacion_id');
        });

        Schema::create('web_pagos', function (Blueprint $table): void {
            $table->id();
            $table->string('dominio', 30);
            $table->foreignId('persona_id')->nullable()->constrained('web_personas');
            $table->foreignId('propietario_id')->nullable()->constrained('web_propietarios');
            $table->foreignId('inquilino_id')->nullable()->constrained('web_inquilinos');
            $table->foreignId('liquidacion_id')->nullable()->constrained('web_liquidaciones_propietarios');
            $table->date('fecha');
            $table->string('forma_pago', 80)->nullable();
            $table->string('cuenta_deposito', 80)->nullable();
            $table->decimal('importe', 16, 2);
            $table->string('referencia', 120)->nullable();
            $table->string('origen', 30)->default('GEI_WEB');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('GENERADO');
            $table->timestampsTz();

            $table->index(['persona_id', 'fecha'], 'ix_web_pagos_persona_fecha');
            $table->index('liquidacion_id');
        });

        Schema::create('web_auditoria_procesos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lote_importacion_id')->nullable()->constrained('web_lotes_importacion');
            $table->string('tipo_proceso', 80);
            $table->string('componente', 80)->nullable();
            $table->string('estado', 40);
            $table->timestampTz('fecha_inicio')->useCurrent();
            $table->timestampTz('fecha_fin')->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('version_regla', 80)->nullable();
            $table->jsonb('resumen')->default(DB::raw("'{}'::jsonb"));
            $table->jsonb('advertencias')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('errores')->default(DB::raw("'[]'::jsonb"));
            $table->text('mensaje')->nullable();
            $table->timestampsTz();

            $table->index('lote_importacion_id');
            $table->index(['tipo_proceso', 'estado'], 'ix_web_auditoria_tipo_estado');
        });

        Schema::table('web_liquidaciones_propietarios_items', function (Blueprint $table): void {
            $table->foreign('factura_id', 'fk_web_liq_prop_items_factura')
                ->references('id')
                ->on('web_facturas');
            $table->foreign('pago_id', 'fk_web_liq_prop_items_pago')
                ->references('id')
                ->on('web_pagos');
        });

        $this->checks();
    }

    public function down(): void
    {
        Schema::table('web_liquidaciones_propietarios_items', function (Blueprint $table): void {
            $table->dropForeign('fk_web_liq_prop_items_factura');
            $table->dropForeign('fk_web_liq_prop_items_pago');
        });

        Schema::dropIfExists('web_auditoria_procesos');
        Schema::dropIfExists('web_pagos');
        Schema::dropIfExists('web_facturas');
    }

    private function checks(): void
    {
        DB::statement("ALTER TABLE web_facturas ADD CONSTRAINT ck_web_facturas_estado CHECK (estado IN ('GENERADO','EMITIDO','ANULADO','ERROR_DB'))");
        DB::statement("ALTER TABLE web_pagos ADD CONSTRAINT ck_web_pagos_dominio CHECK (dominio IN ('PROPIETARIO','INQUILINO','OTRO'))");
        DB::statement("ALTER TABLE web_pagos ADD CONSTRAINT ck_web_pagos_importe CHECK (importe <> 0)");
        DB::statement("ALTER TABLE web_pagos ADD CONSTRAINT ck_web_pagos_estado CHECK (estado IN ('GENERADO','CONFIRMADO','ANULADO','ERROR_DB'))");
        DB::statement("ALTER TABLE web_auditoria_procesos ADD CONSTRAINT ck_web_auditoria_estado CHECK (estado IN ('NUEVO','VALIDANDO','VALIDADO','IMPORTANDO','IMPORTADO','GENERADO','ANULADO','ERROR_DB','ERROR_FORMATO','CONFLICTO_CAMBIO_ORIGEN'))");
    }
};
