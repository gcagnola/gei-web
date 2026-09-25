<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conceptos_imputaciones_caja', function (Blueprint $table) {
            $table->id();
            $table->foreignId('concepto_id')
                ->constrained('conceptos')
                ->cascadeOnDelete();
            $table->string('sede', 2);
            $table->string('moneda', 3);
            $table->boolean('judicial')->default(false);
            $table->string('cuenta_caja_codigo', 4)->nullable();
            $table->string('origen_cobol', 20)->nullable();
            $table->timestamps();

            $table->unique(
                ['concepto_id', 'sede', 'moneda', 'judicial'],
                'conceptos_imputaciones_caja_unique'
            );
            $table->index('cuenta_caja_codigo', 'conceptos_imputaciones_caja_codigo_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conceptos_imputaciones_caja');
    }
};
