<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('garantes_registros', function (Blueprint $table): void {
            $table->string('nroreg', 8)->primary();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('cuenta_inquilino', 11)->index();
            $table->string('nombre', 180);
            $table->string('domicilio_inmueble', 180)->nullable();
            $table->string('codigo_postal', 12)->nullable();
            $table->string('localidad', 120)->nullable();
            $table->string('provincia', 120)->nullable();
            $table->string('telefono_1', 100)->nullable();
            $table->string('telefono_2', 100)->nullable();
            $table->boolean('excluido')->default(false)->index();
            $table->string('motivo_exclusion', 200)->nullable();
            $table->foreignId('inmueble_id')->nullable()->constrained('inmuebles')->nullOnDelete();
            $table->string('estado_vinculacion', 20)->default('PENDIENTE')->index();
            $table->boolean('resolucion_manual')->default(false);
            $table->timestampsTz();
            $table->index(['cuenta_inquilino', 'domicilio_inmueble'], 'garantes_cuenta_domicilio_idx');
            $table->index('cliente_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('garantes_registros');
    }
};
