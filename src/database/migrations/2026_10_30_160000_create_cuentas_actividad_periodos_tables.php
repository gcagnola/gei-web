<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_actividad_periodos', function (Blueprint $table): void {
            $table->string('periodo', 6);
            $table->string('dominio', 20);
            $table->string('cuenta', 20);
            $table->boolean('con_actividad');
            $table->timestampTz('calculado_en');
            $table->primary(['periodo', 'dominio', 'cuenta'], 'cc_actividad_periodo_pk');
            $table->index(
                ['periodo', 'dominio', 'con_actividad'],
                'cc_actividad_filtro_idx'
            );
        });

        Schema::create('cuentas_actividad_periodos_control', function (Blueprint $table): void {
            $table->string('periodo', 6)->primary();
            $table->timestampTz('calculado_en');
            $table->unsignedInteger('total_cuentas');
            $table->unsignedInteger('propietarios_activos');
            $table->unsignedInteger('inquilinos_activos');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas_actividad_periodos_control');
        Schema::dropIfExists('cuentas_actividad_periodos');
    }
};
