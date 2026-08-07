<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inmuebles', function (Blueprint $table): void {
            $table->id();
            $table->char('clave_migracion', 64)->unique();
            $table->string('codigo_origen', 120)->nullable();
            $table->string('domicilio', 220);
            $table->string('domicilio_normalizado', 220);
            $table->string('destino_codigo', 20)->nullable();
            $table->string('identificador_cochera', 20)->nullable();
            $table->string('estado', 20)->default('DESCONOCIDO');
            $table->text('observaciones')->nullable();
            $table->timestampsTz();

            $table->index('domicilio_normalizado');
            $table->index('estado');
        });

        Schema::create('inmuebles_origenes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inmueble_id')->constrained('inmuebles')->cascadeOnDelete();
            $table->string('sistema_origen', 30)->default('COBOL');
            $table->string('entidad_origen', 30);
            $table->string('clave_origen', 30);
            $table->string('cuenta_propietario', 30);
            $table->string('direccion_finca', 220);
            $table->string('direccion_normalizada', 220);
            $table->char('clave_inmueble', 64);
            $table->string('estado_origen', 20)->default('DESCONOCIDO');
            $table->unsignedBigInteger('archivo_origen_id')->nullable();
            $table->unsignedBigInteger('numero_linea')->nullable();
            $table->char('hash_origen', 64)->nullable();
            $table->jsonb('datos_origen')->nullable();
            $table->timestampTz('ultimo_importado_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['sistema_origen', 'entidad_origen', 'clave_origen'],
                'inmuebles_origenes_clave_unique'
            );
            $table->index('clave_inmueble');
            $table->index('cuenta_propietario');
        });

        Schema::create('inmuebles_propietarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inmueble_id')->constrained('inmuebles')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('cliente_cuenta_id')->constrained('clientes_cuentas')->restrictOnDelete();
            $table->decimal('porcentaje', 9, 6)->nullable();
            $table->date('vigencia_desde')->nullable();
            $table->date('vigencia_hasta')->nullable();
            $table->boolean('activo')->default(true);
            $table->string('origen', 30)->default('COBOL');
            $table->jsonb('datos_origen')->nullable();
            $table->timestampsTz();

            $table->index('cliente_id');
            $table->index('cliente_cuenta_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX inmuebles_propietarios_vigente_unique
             ON inmuebles_propietarios (inmueble_id, cliente_cuenta_id)
             WHERE vigencia_hasta IS NULL'
        );

        Schema::create('inmuebles_partidas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inmueble_id')->constrained('inmuebles')->cascadeOnDelete();
            $table->string('partida', 40);
            $table->date('vigencia_desde')->nullable();
            $table->date('vigencia_hasta')->nullable();
            $table->boolean('activo')->default(true);
            $table->string('origen', 30)->default('COBOL');
            $table->jsonb('datos_origen')->nullable();
            $table->timestampsTz();

            $table->index('partida');
        });

        DB::statement(
            'CREATE UNIQUE INDEX inmuebles_partidas_vigente_unique
             ON inmuebles_partidas (inmueble_id, partida)
             WHERE vigencia_hasta IS NULL'
        );

        Schema::create('inmuebles_conflictos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('inmueble_id')->nullable()->constrained('inmuebles')->nullOnDelete();
            $table->string('cuenta_inquilino', 30)->nullable();
            $table->string('cuenta_propietario', 30)->nullable();
            $table->char('clave_inmueble', 64)->nullable();
            $table->string('motivo', 80);
            $table->string('estado', 20)->default('PENDIENTE');
            $table->char('firma', 64)->unique();
            $table->jsonb('detalle')->nullable();
            $table->timestampTz('detectado_at');
            $table->timestampTz('ultima_deteccion_at');
            $table->timestampTz('resuelto_at')->nullable();
            $table->timestampsTz();

            $table->index(['estado', 'motivo']);
            $table->index('cuenta_propietario');
        });

        DB::statement(
            "ALTER TABLE inmuebles ADD CONSTRAINT inmuebles_estado_check
             CHECK (estado IN ('ACTIVO', 'INACTIVO', 'DESCONOCIDO'))"
        );
        DB::statement(
            "ALTER TABLE inmuebles_origenes ADD CONSTRAINT inmuebles_origenes_estado_check
             CHECK (estado_origen IN ('ACTIVO', 'BAJA', 'DESCONOCIDO'))"
        );
        DB::statement(
            "ALTER TABLE inmuebles_conflictos ADD CONSTRAINT inmuebles_conflictos_estado_check
             CHECK (estado IN ('PENDIENTE', 'RESUELTO'))"
        );
        DB::statement(
            'ALTER TABLE inmuebles_propietarios ADD CONSTRAINT inmuebles_propietarios_vigencia_check
             CHECK (vigencia_desde IS NULL OR vigencia_hasta IS NULL OR vigencia_desde <= vigencia_hasta)'
        );
        DB::statement(
            'ALTER TABLE inmuebles_propietarios ADD CONSTRAINT inmuebles_propietarios_porcentaje_check
             CHECK (porcentaje IS NULL OR (porcentaje >= 0 AND porcentaje <= 100))'
        );
        DB::statement(
            'ALTER TABLE inmuebles_partidas ADD CONSTRAINT inmuebles_partidas_vigencia_check
             CHECK (vigencia_desde IS NULL OR vigencia_hasta IS NULL OR vigencia_desde <= vigencia_hasta)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('inmuebles_conflictos');
        Schema::dropIfExists('inmuebles_partidas');
        Schema::dropIfExists('inmuebles_propietarios');
        Schema::dropIfExists('inmuebles_origenes');
        Schema::dropIfExists('inmuebles');
    }
};
