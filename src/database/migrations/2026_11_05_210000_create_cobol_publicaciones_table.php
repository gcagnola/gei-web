<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cobol_publicaciones', function (Blueprint $table): void {
            $table->id();
            $table->date('fecha_cierre');
            $table->char('sha256_exportacion', 64)->unique();
            $table->string('estado', 15)->default('PUBLICADO');
            $table->jsonb('resumen');
            $table->timestampTz('publicado_en');
            $table->index('fecha_cierre');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cobol_publicaciones');
    }
};
