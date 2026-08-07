<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE clientes ALTER COLUMN activo DROP DEFAULT');
        DB::statement('ALTER TABLE clientes ALTER COLUMN activo DROP NOT NULL');

        Schema::table('clientes_origenes', function (Blueprint $table): void {
            $table->string('estado_origen', 20)
                ->default('DESCONOCIDO')
                ->after('clave_origen');
            $table->index('estado_origen');
        });

        Schema::create('clientes_conflictos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_resuelto_id')
                ->nullable()
                ->constrained('clientes')
                ->nullOnDelete();
            $table->string('sistema_origen', 30)->default('COBOL');
            $table->string('entidad_origen', 30);
            $table->string('clave_origen', 30);
            $table->string('motivo', 80);
            $table->string('estado', 20)->default('PENDIENTE');
            $table->string('estado_origen', 20)->default('DESCONOCIDO');
            $table->unsignedBigInteger('archivo_origen_id')->nullable();
            $table->unsignedBigInteger('numero_linea')->nullable();
            $table->char('hash_origen', 64)->nullable();
            $table->jsonb('datos_origen');
            $table->jsonb('clientes_candidatos')->nullable();
            $table->jsonb('detalle')->nullable();
            $table->timestampTz('detectado_at');
            $table->timestampTz('ultima_deteccion_at');
            $table->timestampTz('resuelto_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['sistema_origen', 'entidad_origen', 'clave_origen'],
                'clientes_conflictos_origen_unique'
            );
            $table->index(['estado', 'entidad_origen']);
            $table->index('cliente_resuelto_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes_conflictos');

        Schema::table('clientes_origenes', function (Blueprint $table): void {
            $table->dropIndex(['estado_origen']);
            $table->dropColumn('estado_origen');
        });

        DB::table('clientes')->whereNull('activo')->update(['activo' => true]);
        DB::statement('ALTER TABLE clientes ALTER COLUMN activo SET NOT NULL');
        DB::statement('ALTER TABLE clientes ALTER COLUMN activo SET DEFAULT true');
    }
};
