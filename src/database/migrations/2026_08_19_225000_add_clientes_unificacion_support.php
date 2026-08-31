<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('clientes', 'id_cliente_canonico')) {
            Schema::table('clientes', function (Blueprint $table): void {
                $table->unsignedBigInteger('id_cliente_canonico')->nullable()->after('id');
                $table->foreign('id_cliente_canonico', 'clientes_canonico_foreign')
                    ->references('id')->on('clientes')->restrictOnDelete();
                $table->index('id_cliente_canonico', 'clientes_canonico_index');
            });

            DB::statement(
                'ALTER TABLE clientes
                 ADD CONSTRAINT clientes_canonico_distinto_check
                 CHECK (id_cliente_canonico IS NULL OR id_cliente_canonico <> id)'
            );
        }

        if (! Schema::hasTable('clientes_resoluciones_origen')) {
            Schema::create('clientes_resoluciones_origen', function (Blueprint $table): void {
                $table->bigIncrements('id_cliente_resolucion_origen');
                $table->string('sistema_origen', 30)->default('COBOL');
                $table->string('entidad_origen', 40);
                $table->string('clave_origen', 120);
                $table->string('decision', 30);
                $table->unsignedBigInteger('cliente_id')->nullable();
                $table->unsignedBigInteger('usuario_id')->nullable();
                $table->jsonb('detalle_json')->nullable();
                $table->timestampsTz();

                $table->foreign('cliente_id', 'clientes_resoluciones_origen_cliente_foreign')
                    ->references('id')->on('clientes')->restrictOnDelete();
                $table->foreign('usuario_id', 'clientes_resoluciones_origen_usuario_foreign')
                    ->references('id')->on('usuarios')->nullOnDelete();
                $table->unique(
                    ['sistema_origen', 'entidad_origen', 'clave_origen'],
                    'clientes_resoluciones_origen_identidad_unique'
                );
                $table->index(['decision', 'cliente_id'], 'clientes_resoluciones_origen_decision_index');
            });

            DB::statement(
                "ALTER TABLE clientes_resoluciones_origen
                 ADD CONSTRAINT clientes_resoluciones_origen_decision_check
                 CHECK (decision IN ('ASOCIAR_EXISTENTE', 'CREAR_SEPARADO'))"
            );
            DB::statement(
                "ALTER TABLE clientes_resoluciones_origen
                 ADD CONSTRAINT clientes_resoluciones_origen_cliente_check
                 CHECK ((decision = 'ASOCIAR_EXISTENTE' AND cliente_id IS NOT NULL)
                     OR decision = 'CREAR_SEPARADO')"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes_resoluciones_origen');

        if (Schema::hasColumn('clientes', 'id_cliente_canonico')) {
            DB::statement('ALTER TABLE clientes DROP CONSTRAINT IF EXISTS clientes_canonico_distinto_check');
            Schema::table('clientes', function (Blueprint $table): void {
                $table->dropForeign('clientes_canonico_foreign');
                $table->dropIndex('clientes_canonico_index');
                $table->dropColumn('id_cliente_canonico');
            });
        }
    }
};
