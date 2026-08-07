<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes_cuentas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')
                ->constrained('clientes')
                ->cascadeOnDelete();
            $table->string('cuenta', 30);
            $table->string('rol', 30);
            $table->boolean('activo')->nullable();
            $table->jsonb('datos_origen')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['cliente_id', 'cuenta', 'rol'],
                'clientes_cuentas_cliente_cuenta_rol_unique'
            );
            $table->index('cuenta');
            $table->index(['rol', 'cuenta']);
            $table->index(['cliente_id', 'rol']);
        });

        DB::statement(
            "ALTER TABLE clientes_cuentas
             ADD CONSTRAINT clientes_cuentas_rol_check
             CHECK (rol IN ('PROPIETARIO', 'INQUILINO'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes_cuentas');
    }
};
