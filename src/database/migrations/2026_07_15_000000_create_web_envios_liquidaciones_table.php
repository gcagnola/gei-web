<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_envios_liquidaciones', function (Blueprint $table) {
            $table->id('web_id');
            $table->unsignedInteger('web_codigo_cliente');
            $table->unsignedInteger('web_numero_de_liquidacion');
            $table->unsignedSmallInteger('web_punto_venta');
            $table->unsignedInteger('web_numero');
            $table->string('web_destinatario', 255);
            $table->timestamp('web_intentado_en');
            $table->unsignedInteger('web_usuario_id')->nullable();
            $table->string('web_estado', 20);
            $table->string('web_mensaje_error', 500)->nullable();
            $table->string('web_ruta_relativa_pdf', 255);

            $table->index('web_codigo_cliente');
            $table->index('web_numero_de_liquidacion');
            $table->index('web_estado');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_envios_liquidaciones');
    }
};
