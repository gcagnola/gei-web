<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('liquidaciones_propietarios_envios', function (Blueprint $table): void {
            $table->string('documentos', 20)
                ->default('LIQUIDACION')
                ->after('tipo_envio');
        });

        DB::statement(
            "ALTER TABLE liquidaciones_propietarios_envios
             ADD CONSTRAINT liquidaciones_propietarios_envios_documentos_check
             CHECK (documentos IN ('LIQUIDACION', 'IMPUESTOS', 'AMBOS'))"
        );
    }

    public function down(): void
    {
        DB::statement(
            'ALTER TABLE liquidaciones_propietarios_envios
             DROP CONSTRAINT IF EXISTS liquidaciones_propietarios_envios_documentos_check'
        );

        Schema::table('liquidaciones_propietarios_envios', function (Blueprint $table): void {
            $table->dropColumn('documentos');
        });
    }
};
