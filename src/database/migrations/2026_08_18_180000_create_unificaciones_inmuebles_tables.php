<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inmuebles', function (Blueprint $table): void {
            $table->unsignedBigInteger('id_inmueble_canonico')->nullable()->after('id');
            $table->foreign('id_inmueble_canonico', 'inmuebles_canonico_foreign')
                ->references('id')
                ->on('inmuebles')
                ->restrictOnDelete();
            $table->index('id_inmueble_canonico', 'inmuebles_canonico_index');
        });

        DB::statement(
            'ALTER TABLE inmuebles
             ADD CONSTRAINT inmuebles_canonico_distinto_check
             CHECK (id_inmueble_canonico IS NULL OR id_inmueble_canonico <> id)'
        );

        Schema::create('unificaciones', function (Blueprint $table): void {
            $table->bigIncrements('id_unificacion');
            $table->string('tipo', 20);
            $table->unsignedBigInteger('id_registro_principal');
            $table->unsignedBigInteger('id_registro_absorbido');
            $table->unsignedBigInteger('id_usuario')->nullable();
            $table->string('estado', 20)->default('APLICADA');
            $table->jsonb('detalle_json')->nullable();
            $table->timestampTz('revertido_at')->nullable();
            $table->unsignedBigInteger('id_usuario_reversion')->nullable();
            $table->timestampsTz();

            $table->foreign('id_usuario', 'unificaciones_usuario_foreign')
                ->references('id')->on('usuarios')->nullOnDelete();
            $table->foreign('id_usuario_reversion', 'unificaciones_usuario_reversion_foreign')
                ->references('id')->on('usuarios')->nullOnDelete();

            $table->index(['tipo', 'id_registro_principal'], 'unificaciones_principal_index');
            $table->index(['tipo', 'id_registro_absorbido'], 'unificaciones_absorbido_index');
        });

        DB::statement(
            "ALTER TABLE unificaciones
             ADD CONSTRAINT unificaciones_tipo_check
             CHECK (tipo IN ('INMUEBLE', 'CLIENTE'))"
        );
        DB::statement(
            "ALTER TABLE unificaciones
             ADD CONSTRAINT unificaciones_estado_check
             CHECK (estado IN ('APLICADA', 'REVERTIDA'))"
        );
        DB::statement(
            'ALTER TABLE unificaciones
             ADD CONSTRAINT unificaciones_registros_distintos_check
             CHECK (id_registro_principal <> id_registro_absorbido)'
        );
        DB::statement(
            "CREATE UNIQUE INDEX unificaciones_absorbido_aplicada_unique
             ON unificaciones (tipo, id_registro_absorbido)
             WHERE estado = 'APLICADA'"
        );

        Schema::create('unificaciones_cambios', function (Blueprint $table): void {
            $table->bigIncrements('id_unificacion_cambio');
            $table->unsignedBigInteger('id_unificacion');
            $table->unsignedInteger('orden');
            $table->string('tabla', 80);
            $table->string('accion', 30);
            $table->unsignedBigInteger('id_registro')->nullable();
            $table->jsonb('datos_antes')->nullable();
            $table->jsonb('datos_despues')->nullable();
            $table->jsonb('detalle_json')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->foreign('id_unificacion', 'unificaciones_cambios_unificacion_foreign')
                ->references('id_unificacion')->on('unificaciones')->cascadeOnDelete();
            $table->index(['id_unificacion', 'orden'], 'unificaciones_cambios_orden_index');
            $table->index(['tabla', 'id_registro'], 'unificaciones_cambios_registro_index');
        });

        Schema::create('unificaciones_candidatos', function (Blueprint $table): void {
            $table->bigIncrements('id_unificacion_candidato');
            $table->string('tipo', 20);
            $table->unsignedBigInteger('id_registro_a');
            $table->unsignedBigInteger('id_registro_b');
            $table->string('confianza', 10)->nullable();
            $table->char('firma_evidencia', 64)->nullable();
            $table->jsonb('motivos_json')->nullable();
            $table->string('estado', 30)->default('PENDIENTE');
            $table->unsignedBigInteger('id_usuario_resolucion')->nullable();
            $table->timestampTz('detectado_at')->useCurrent();
            $table->timestampTz('ultima_deteccion_at')->useCurrent();
            $table->timestampTz('resuelto_at')->nullable();
            $table->jsonb('detalle_json')->nullable();
            $table->timestampsTz();

            $table->foreign('id_usuario_resolucion', 'unificaciones_candidatos_usuario_foreign')
                ->references('id')->on('usuarios')->nullOnDelete();
            $table->unique(
                ['tipo', 'id_registro_a', 'id_registro_b'],
                'unificaciones_candidatos_par_unique'
            );
            $table->index(['tipo', 'estado'], 'unificaciones_candidatos_estado_index');
        });

        DB::statement(
            "ALTER TABLE unificaciones_candidatos
             ADD CONSTRAINT unificaciones_candidatos_tipo_check
             CHECK (tipo IN ('INMUEBLE', 'CLIENTE'))"
        );
        DB::statement(
            "ALTER TABLE unificaciones_candidatos
             ADD CONSTRAINT unificaciones_candidatos_confianza_check
             CHECK (confianza IS NULL OR confianza IN ('ALTA', 'MEDIA', 'BAJA'))"
        );
        DB::statement(
            "ALTER TABLE unificaciones_candidatos
             ADD CONSTRAINT unificaciones_candidatos_estado_check
             CHECK (estado IN ('PENDIENTE', 'MANTENER_SEPARADOS', 'CONFLICTIVO', 'UNIFICADO'))"
        );
        DB::statement(
            'ALTER TABLE unificaciones_candidatos
             ADD CONSTRAINT unificaciones_candidatos_orden_check
             CHECK (id_registro_a < id_registro_b)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('unificaciones_candidatos');
        Schema::dropIfExists('unificaciones_cambios');
        Schema::dropIfExists('unificaciones');

        Schema::table('inmuebles', function (Blueprint $table): void {
            $table->dropForeign('inmuebles_canonico_foreign');
            $table->dropIndex('inmuebles_canonico_index');
            $table->dropColumn('id_inmueble_canonico');
        });
    }
};
