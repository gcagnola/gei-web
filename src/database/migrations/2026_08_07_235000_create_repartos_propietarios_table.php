<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repartos_propietarios', function (Blueprint $table): void {
            $table->id();
            $table->string('cuenta', 30);
            $table->string('cuenta_impresa', 30)->nullable();
            $table->string('propietario', 180)->nullable();
            $table->string('beneficiario', 180);
            $table->string('beneficiario_normalizado', 180);
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->decimal('porcentaje', 9, 6);
            $table->char('periodo_desde', 6);
            $table->char('ultimo_periodo', 6);
            $table->char('periodo_baja', 6)->nullable();
            $table->boolean('activo')->default(true);
            $table->string('origen', 30)->default('LIQUIDACION');
            $table->foreignId('ultima_liquidacion_id')
                ->nullable()
                ->constrained('liquidaciones_propietarios')
                ->nullOnDelete();
            $table->jsonb('datos_origen')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['cuenta', 'beneficiario_normalizado'],
                'repartos_prop_cuenta_benef_unique'
            );
            $table->index(['cuenta', 'activo'], 'repartos_prop_cuenta_activo_idx');
            $table->index(['cliente_id', 'activo'], 'repartos_prop_cliente_activo_idx');
            $table->index('ultimo_periodo', 'repartos_prop_ultimo_periodo_idx');
        });

        DB::statement(
            'ALTER TABLE repartos_propietarios
             ADD CONSTRAINT repartos_prop_porcentaje_check
             CHECK (porcentaje >= 0 AND porcentaje <= 100)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('repartos_propietarios');
    }
};
