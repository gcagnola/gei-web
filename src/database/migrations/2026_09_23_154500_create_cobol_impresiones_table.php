<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cobol_impresiones') || Schema::hasTable('web_cobol_impresiones')) {
            return;
        }

        Schema::create('cobol_impresiones', function (Blueprint $table): void {
            $table->id();
            $table->string('origen', 80);
            $table->string('archivo_origen', 120);
            $table->string('archivo_raw', 500);
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->string('tipo_documento', 80)->nullable();
            $table->string('cuenta', 50)->nullable();
            $table->string('nombre_cliente', 255)->nullable();
            $table->date('fecha_documento')->nullable();
            $table->string('estado', 30)->default('RECIBIDO');
            $table->string('pdf_path', 500)->nullable();
            $table->timestampTz('recibido_en');
            $table->timestampsTz();

            $table->unique(['origen', 'archivo_origen'], 'cobol_impresiones_origen_archivo_unique');
            $table->index('recibido_en', 'cobol_impresiones_recibido_idx');
            $table->index('tipo_documento', 'cobol_impresiones_tipo_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobol_impresiones');
    }
};
