<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['clientes_cuentas', 'inmuebles', 'cuentas_corrientes', 'liquidaciones_propietarios', 'comprobantes_arca'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->foreignId('sucursal_id')
                    ->nullable()
                    ->constrained('sucursales')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();

                $table->index('sucursal_id');
            });
        }
    }

    public function down(): void
    {
        foreach (['comprobantes_arca', 'liquidaciones_propietarios', 'cuentas_corrientes', 'inmuebles', 'clientes_cuentas'] as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropForeign(['sucursal_id']);
                $table->dropIndex(['sucursal_id']);
                $table->dropColumn('sucursal_id');
            });
        }
    }
};
