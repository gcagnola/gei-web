<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_caja', function (Blueprint $table) {
            $table->id();
            $table->string('codigo_cobol', 4)->unique();
            $table->string('numero_contable', 10)->nullable();
            $table->string('nombre', 100)->nullable();
            $table->string('subcuenta', 100)->nullable();
            $table->boolean('activo')->default(true);
            $table->string('origen_cobol', 20)->nullable();
            $table->timestamps();

            $table->index(['activo', 'codigo_cobol']);
        });

        Schema::table('conceptos_imputaciones_caja', function (Blueprint $table) {
            $table->foreignId('cuenta_caja_id')
                ->nullable()
                ->after('cuenta_caja_codigo')
                ->constrained('cuentas_caja')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('conceptos_imputaciones_caja', function (Blueprint $table) {
            $table->dropConstrainedForeignId('cuenta_caja_id');
        });

        Schema::dropIfExists('cuentas_caja');
    }
};
