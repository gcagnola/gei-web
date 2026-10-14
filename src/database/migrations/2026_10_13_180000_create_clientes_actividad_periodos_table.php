<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('clientes_actividad_periodos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->string('periodo', 6);
            $table->boolean('activo_cobol')->default(false);
            $table->boolean('activo_arca')->default(false);
            $table->boolean('activo_liquidaciones')->default(false);
            $table->boolean('activo_impuestos')->default(false);
            $table->timestampsTz();
            $table->unique(['cliente_id', 'periodo'], 'clientes_actividad_periodos_cliente_periodo_unico');
            $table->index('periodo', 'clientes_actividad_periodos_periodo_idx');
        });

        // No se persiste activo: se deriva del OR de las cuatro evidencias.
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes_actividad_periodos');
    }
};
