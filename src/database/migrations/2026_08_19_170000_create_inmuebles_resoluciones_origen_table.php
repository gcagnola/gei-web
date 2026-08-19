<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inmuebles_resoluciones_origen')) {
            return;
        }

        Schema::create('inmuebles_resoluciones_origen', function (Blueprint $table): void {
            $table->bigIncrements('id_inmueble_resolucion_origen');
            $table->string('sistema_origen', 30)->default('COBOL');
            $table->string('entidad_origen', 40);
            $table->string('clave_origen', 120);
            $table->string('decision', 30);
            $table->unsignedBigInteger('inmueble_id')->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->jsonb('detalle_json')->nullable();
            $table->timestampsTz();

            $table->foreign('inmueble_id', 'inmuebles_resoluciones_origen_inmueble_foreign')
                ->references('id')->on('inmuebles')->restrictOnDelete();
            $table->foreign('usuario_id', 'inmuebles_resoluciones_origen_usuario_foreign')
                ->references('id')->on('usuarios')->nullOnDelete();
            $table->unique(
                ['sistema_origen', 'entidad_origen', 'clave_origen'],
                'inmuebles_resoluciones_origen_identidad_unique'
            );
            $table->index(['decision', 'inmueble_id'], 'inmuebles_resoluciones_origen_decision_index');
        });

        DB::statement(
            "ALTER TABLE inmuebles_resoluciones_origen
             ADD CONSTRAINT inmuebles_resoluciones_origen_decision_check
             CHECK (decision IN ('ASOCIAR_EXISTENTE', 'CREAR_SEPARADO'))"
        );
        DB::statement(
            "ALTER TABLE inmuebles_resoluciones_origen
             ADD CONSTRAINT inmuebles_resoluciones_origen_inmueble_check
             CHECK ((decision = 'ASOCIAR_EXISTENTE' AND inmueble_id IS NOT NULL)
                 OR (decision = 'CREAR_SEPARADO' AND inmueble_id IS NULL))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('inmuebles_resoluciones_origen');
    }
};
