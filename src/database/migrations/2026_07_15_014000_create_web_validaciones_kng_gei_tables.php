<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

            $table->index(['web_importacion_id', 'web_estado']);
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

            $table->index(['web_validacion_id', 'web_componente']);
            $table->index(['web_importacion_id', 'web_estado_comparacion']);
            $table->index(['web_registro_staging_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_validaciones_kng_gei_detalles');
        Schema::dropIfExists('web_validaciones_kng_gei');
    }
};
