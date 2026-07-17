<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('web_migraciones_aplicaciones', function (Blueprint $table) {
            $table->string('web_componente', 40)->default('')->after('web_tipo');
            $table->string('web_estado', 30)->default('confirmado')->after('web_componente');
            $table->boolean('web_simulado')->default(false)->after('web_estado');
            $table->boolean('web_confirmado')->default(true)->after('web_simulado');
            $table->unsignedInteger('web_registros_leidos')->default(1)->after('web_confirmado');
            $table->unsignedInteger('web_insertados')->default(0)->after('web_registros_leidos');
            $table->unsignedInteger('web_actualizados')->default(0)->after('web_insertados');
            $table->unsignedInteger('web_omitidos')->default(0)->after('web_actualizados');
            $table->unsignedInteger('web_errores')->default(0)->after('web_omitidos');
            $table->string('web_mapping_version', 80)->default('')->after('web_errores');
            $table->timestamp('web_inicio_en')->nullable()->after('web_mapping_version');
            $table->timestamp('web_fin_en')->nullable()->after('web_inicio_en');
            $table->unsignedInteger('web_usuario_id')->nullable()->after('web_fin_en');

            $table->index(['web_importacion_id', 'web_componente', 'web_estado']);
            $table->index('web_mapping_version');
        });
    }

    public function down(): void
    {
        Schema::table('web_migraciones_aplicaciones', function (Blueprint $table) {
            $table->dropIndex(['web_importacion_id', 'web_componente', 'web_estado']);
            $table->dropIndex(['web_mapping_version']);
            $table->dropColumn([
                'web_componente',
                'web_estado',
                'web_simulado',
                'web_confirmado',
                'web_registros_leidos',
                'web_insertados',
                'web_actualizados',
                'web_omitidos',
                'web_errores',
                'web_mapping_version',
                'web_inicio_en',
                'web_fin_en',
                'web_usuario_id',
            ]);
        });
    }
};
