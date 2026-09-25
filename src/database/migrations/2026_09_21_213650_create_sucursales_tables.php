<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sucursales', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 10)->unique();
            $table->string('nombre', 100);
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::create('usuarios_sucursales', function (Blueprint $table) {
            $table->id();

            $table->foreignId('usuario_id')
                ->constrained('usuarios')
                ->cascadeOnUpdate()
                ->cascadeOnDelete();

            $table->foreignId('sucursal_id')
                ->constrained('sucursales')
                ->cascadeOnUpdate()
                ->restrictOnDelete();

            $table->timestamps();

            $table->unique(
                ['usuario_id', 'sucursal_id'],
                'usuarios_sucursales_usuario_sucursal_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios_sucursales');
        Schema::dropIfExists('sucursales');
    }
};
