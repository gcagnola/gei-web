<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('liquidaciones_propietarios', function (Blueprint $table): void {
            $table->dropUnique('liquidaciones_propietarios_numero_interno_unique');
            $table->unique(
                ['periodo', 'numero_interno'],
                'liquidaciones_propietarios_periodo_numero_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('liquidaciones_propietarios', function (Blueprint $table): void {
            $table->dropUnique('liquidaciones_propietarios_periodo_numero_unique');
            $table->unique('numero_interno');
        });
    }
};
