<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conceptos', function (Blueprint $table) {
            $table->id();
            $table->string('dominio', 4);
            $table->string('codigo', 2);
            $table->string('descripcion', 120)->nullable();
            $table->boolean('activo')->default(true);
            $table->string('origen_cobol', 20)->nullable();
            $table->timestamps();

            $table->unique(['dominio', 'codigo'], 'conceptos_dominio_codigo_unique');
            $table->index(['dominio', 'activo'], 'conceptos_dominio_activo_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('conceptos');
    }
};
