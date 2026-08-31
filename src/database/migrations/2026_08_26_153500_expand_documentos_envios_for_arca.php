<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            ! Schema::hasTable('liquidaciones_propietarios_envios')
            || ! Schema::hasColumn('liquidaciones_propietarios_envios', 'documentos')
        ) {
            return;
        }

        DB::statement(
            'ALTER TABLE liquidaciones_propietarios_envios '
            .'DROP CONSTRAINT IF EXISTS liquidaciones_propietarios_envios_documentos_check'
        );

        DB::statement(
            "ALTER TABLE liquidaciones_propietarios_envios "
            ."ADD CONSTRAINT liquidaciones_propietarios_envios_documentos_check "
            ."CHECK (documentos IN ('LIQUIDACION', 'IMPUESTOS', 'AMBOS', 'ARCA', 'TODOS'))"
        );
    }

    public function down(): void
    {
        if (
            ! Schema::hasTable('liquidaciones_propietarios_envios')
            || ! Schema::hasColumn('liquidaciones_propietarios_envios', 'documentos')
        ) {
            return;
        }

        DB::statement(
            'ALTER TABLE liquidaciones_propietarios_envios '
            .'DROP CONSTRAINT IF EXISTS liquidaciones_propietarios_envios_documentos_check'
        );

        DB::statement(
            "ALTER TABLE liquidaciones_propietarios_envios "
            ."ADD CONSTRAINT liquidaciones_propietarios_envios_documentos_check "
            ."CHECK (documentos IN ('LIQUIDACION', 'IMPUESTOS', 'AMBOS'))"
        );
    }
};
