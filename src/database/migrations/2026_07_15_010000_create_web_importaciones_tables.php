<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_importaciones', function (Blueprint $table) {
            $table->id('web_id');
            $table->string('web_tipo', 50);
            $table->string('web_periodo_detectado', 20)->nullable();
            $table->string('web_estado', 40);
            $table->timestamp('web_inicio_en');
            $table->timestamp('web_finalizacion_en')->nullable();
            $table->string('web_ejecutor', 100)->nullable();
            $table->unsignedInteger('web_cantidad_archivos')->default(0);
            $table->unsignedInteger('web_registros_leidos')->default(0);
            $table->unsignedInteger('web_registros_validos')->default(0);
            $table->unsignedInteger('web_insertados')->default(0);
            $table->unsignedInteger('web_actualizados')->default(0);
            $table->unsignedInteger('web_omitidos')->default(0);
            $table->unsignedInteger('web_advertencias')->default(0);
            $table->unsignedInteger('web_errores')->default(0);
            $table->text('web_mensaje')->nullable();
            $table->timestamps();

            $table->index('web_tipo');
            $table->index('web_estado');
            $table->index('web_periodo_detectado');
        });

        Schema::create('web_importaciones_archivos', function (Blueprint $table) {
            $table->id('web_id');
            $table->foreignId('web_importacion_id')
                ->constrained('web_importaciones', 'web_id')
                ->cascadeOnDelete();
            $table->string('web_nombre', 150);
            $table->string('web_tipo', 50);
            $table->string('web_hash_sha256', 64);
            $table->unsignedBigInteger('web_tamano')->default(0);
            $table->timestamp('web_fecha_archivo')->nullable();
            $table->unsignedInteger('web_lineas')->default(0);
            $table->unsignedInteger('web_procesadas')->default(0);
            $table->unsignedInteger('web_rechazadas')->default(0);
            $table->string('web_estado', 40);
            $table->string('web_periodo_detectado', 20)->nullable();
            $table->string('web_localidad_detectada', 80)->nullable();
            $table->timestamps();

            $table->unique(['web_tipo', 'web_hash_sha256']);
            $table->index('web_importacion_id');
            $table->index('web_nombre');
        });

        Schema::create('web_importaciones_eventos', function (Blueprint $table) {
            $table->id('web_id');
            $table->foreignId('web_importacion_id')
                ->constrained('web_importaciones', 'web_id')
                ->cascadeOnDelete();
            $table->string('web_archivo', 150)->nullable();
            $table->unsignedInteger('web_linea')->nullable();
            $table->string('web_tipo', 50);
            $table->string('web_severidad', 20);
            $table->string('web_codigo', 80);
            $table->text('web_mensaje');
            $table->text('web_contenido')->nullable();
            $table->json('web_datos')->nullable();
            $table->timestamps();

            $table->index('web_importacion_id');
            $table->index('web_severidad');
            $table->index('web_codigo');
        });

        Schema::create('web_importaciones_registros', function (Blueprint $table) {
            $table->id('web_id');
            $table->foreignId('web_importacion_id')
                ->constrained('web_importaciones', 'web_id')
                ->cascadeOnDelete();
            $table->string('web_archivo', 150);
            $table->unsignedInteger('web_linea');
            $table->string('web_tipo', 50);
            $table->string('web_clave', 120)->nullable();
            $table->string('web_periodo', 20)->nullable();
            $table->json('web_payload');
            $table->timestamps();

            $table->unique([
                'web_importacion_id',
                'web_archivo',
                'web_linea',
                'web_tipo',
            ]);
            $table->index('web_clave');
            $table->index('web_periodo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_importaciones_registros');
        Schema::dropIfExists('web_importaciones_eventos');
        Schema::dropIfExists('web_importaciones_archivos');
        Schema::dropIfExists('web_importaciones');
    }
};
