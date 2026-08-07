<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();

            $table->foreignId('perfil_id')
                ->constrained('perfiles')
                ->restrictOnDelete()
                ->cascadeOnUpdate();

            $table->string('nombre_usuario', 50)->unique();
            $table->string('nombre', 150);
            $table->string('email', 255)->unique();
            $table->string('password');
            $table->boolean('activo')->default(true);

            $table->unsignedSmallInteger('intentos_fallidos')
                ->default(0);

            $table->timestamp('bloqueado_hasta')->nullable();
            $table->timestamp('ultimo_acceso')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index('perfil_id');
            $table->index('activo');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios');
    }
};