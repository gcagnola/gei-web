<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_migraciones_aplicaciones', function (Blueprint $table) {
            $table->id('web_id');
            $table->foreignId('web_importacion_id')
                ->constrained('web_importaciones', 'web_id')
                ->cascadeOnDelete();
            $table->foreignId('web_registro_id')
                ->nullable()
                ->constrained('web_importaciones_registros', 'web_id')
                ->nullOnDelete();
            $table->string('web_tipo', 60);
            $table->string('web_tabla_destino', 100);
            $table->string('web_clave_destino', 160);
            $table->string('web_hash_origen', 64);
            $table->string('web_accion', 30);
            $table->json('web_payload')->nullable();
            $table->text('web_mensaje')->nullable();
            $table->timestamps();

            $table->unique('web_hash_origen');
            $table->index(['web_importacion_id', 'web_tipo']);
            $table->index(['web_tabla_destino', 'web_clave_destino']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_migraciones_aplicaciones');
    }
};
