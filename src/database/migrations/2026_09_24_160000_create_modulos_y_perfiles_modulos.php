<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modulos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 60)->unique();
            $table->string('seccion', 80);
            $table->string('nombre', 120);
            $table->unsignedInteger('orden')->default(0);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::create('perfiles_modulos', function (Blueprint $table) {
            $table->foreignId('perfil_id')->constrained('perfiles')->cascadeOnDelete();
            $table->foreignId('modulo_id')->constrained('modulos')->cascadeOnDelete();
            $table->timestamps();

            $table->primary(['perfil_id', 'modulo_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('perfiles_modulos');
        Schema::dropIfExists('modulos');
    }
};
