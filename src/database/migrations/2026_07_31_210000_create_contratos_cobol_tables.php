<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contratos', function (Blueprint $table): void {
            $table->id();
            $table->char('clave_migracion', 64)->unique();
            $table->string('codigo_origen', 30)->unique();
            $table->string('cuenta_inquilino', 30)->index();
            $table->string('cuenta_propietario', 30)->index();
            $table->date('fecha_contrato')->nullable();
            $table->date('fecha_celebracion')->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->date('fecha_primer_ajuste')->nullable();
            $table->date('fecha_baja')->nullable();
            $table->unsignedSmallInteger('plazo_meses')->nullable();
            $table->unsignedSmallInteger('plazo_dias')->nullable();
            $table->string('indice_ajuste', 10)->nullable();
            $table->string('tipo_ajuste', 10)->nullable();
            $table->decimal('cuota_1', 14, 2)->nullable();
            $table->decimal('cuota_2', 14, 2)->nullable();
            $table->decimal('cuota_2_dolar', 14, 2)->nullable();
            $table->decimal('alquiler_inicial', 14, 2)->nullable();
            $table->decimal('cotizacion_dolar', 14, 2)->nullable();
            $table->boolean('administracion_responsable')->nullable();
            $table->string('destino_codigo', 20)->nullable();
            $table->decimal('penalidad_porcentaje', 7, 3)->nullable();
            $table->decimal('penalidad_importe', 14, 2)->nullable();
            $table->decimal('acumulado_penalidad', 14, 2)->nullable();
            $table->decimal('comision_anterior', 7, 3)->nullable();
            $table->decimal('comision_impuestos', 7, 3)->nullable();
            $table->boolean('reparacion')->nullable();
            $table->unsignedSmallInteger('dias_reparacion')->nullable();
            $table->date('fecha_juicio')->nullable();
            $table->string('abogado_codigo', 20)->nullable();
            $table->string('marca_intimacion', 5)->nullable();
            $table->string('estado', 20);
            $table->text('observaciones')->nullable();
            $table->timestampsTz();

            $table->index(['estado', 'fecha_fin']);
        });

        Schema::create('contratos_origenes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->string('sistema_origen', 30)->default('COBOL');
            $table->string('entidad_origen', 30);
            $table->string('clave_origen', 30);
            $table->unsignedBigInteger('archivo_origen_id')->nullable();
            $table->unsignedBigInteger('numero_linea')->nullable();
            $table->char('hash_origen', 64)->nullable();
            $table->jsonb('datos_origen')->nullable();
            $table->timestampTz('ultimo_importado_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['sistema_origen', 'entidad_origen', 'clave_origen'],
                'contratos_origenes_clave_unique'
            );
        });

        Schema::create('contratos_inquilinos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->foreignId('cliente_id')->constrained('clientes')->restrictOnDelete();
            $table->foreignId('cliente_cuenta_id')->constrained('clientes_cuentas')->restrictOnDelete();
            $table->string('rol', 20)->default('TITULAR');
            $table->date('vigencia_desde')->nullable();
            $table->date('vigencia_hasta')->nullable();
            $table->boolean('activo')->default(true);
            $table->string('origen', 30)->default('COBOL');
            $table->jsonb('datos_origen')->nullable();
            $table->timestampsTz();

            $table->unique(['contrato_id', 'cliente_cuenta_id', 'rol'], 'contratos_inquilinos_unique');
            $table->index('cliente_id');
        });

        Schema::create('contratos_inmuebles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contrato_id')->constrained('contratos')->cascadeOnDelete();
            $table->foreignId('inmueble_id')->constrained('inmuebles')->restrictOnDelete();
            $table->date('vigencia_desde')->nullable();
            $table->date('vigencia_hasta')->nullable();
            $table->boolean('activo')->default(true);
            $table->string('origen', 30)->default('COBOL');
            $table->jsonb('datos_origen')->nullable();
            $table->timestampsTz();

            $table->unique(['contrato_id', 'inmueble_id'], 'contratos_inmuebles_unique');
            $table->index('inmueble_id');
        });

        Schema::create('contratos_conflictos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->nullOnDelete();
            $table->string('cuenta_inquilino', 30)->nullable();
            $table->string('cuenta_propietario', 30)->nullable();
            $table->string('tipo', 20);
            $table->string('motivo', 80);
            $table->boolean('bloqueante')->default(true);
            $table->string('estado', 20)->default('PENDIENTE');
            $table->char('firma', 64)->unique();
            $table->jsonb('detalle')->nullable();
            $table->timestampTz('detectado_at');
            $table->timestampTz('ultima_deteccion_at');
            $table->timestampTz('resuelto_at')->nullable();
            $table->timestampsTz();

            $table->index(['estado', 'tipo', 'motivo']);
            $table->index('cuenta_inquilino');
        });

        DB::statement(
            "ALTER TABLE contratos ADD CONSTRAINT contratos_estado_check
             CHECK (estado IN ('ACTIVO', 'BAJA'))"
        );
        DB::statement(
            "ALTER TABLE contratos_conflictos ADD CONSTRAINT contratos_conflictos_tipo_check
             CHECK (tipo IN ('CONFLICTO', 'ADVERTENCIA'))"
        );
        DB::statement(
            "ALTER TABLE contratos_conflictos ADD CONSTRAINT contratos_conflictos_estado_check
             CHECK (estado IN ('PENDIENTE', 'RESUELTO'))"
        );
        DB::statement(
            'ALTER TABLE contratos ADD CONSTRAINT contratos_fechas_check
             CHECK (fecha_inicio IS NULL OR fecha_fin IS NULL OR fecha_inicio <= fecha_fin)'
        );
        DB::statement(
            'ALTER TABLE contratos_inquilinos ADD CONSTRAINT contratos_inquilinos_vigencia_check
             CHECK (vigencia_desde IS NULL OR vigencia_hasta IS NULL OR vigencia_desde <= vigencia_hasta)'
        );
        DB::statement(
            'ALTER TABLE contratos_inmuebles ADD CONSTRAINT contratos_inmuebles_vigencia_check
             CHECK (vigencia_desde IS NULL OR vigencia_hasta IS NULL OR vigencia_desde <= vigencia_hasta)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos_conflictos');
        Schema::dropIfExists('contratos_inmuebles');
        Schema::dropIfExists('contratos_inquilinos');
        Schema::dropIfExists('contratos_origenes');
        Schema::dropIfExists('contratos');
    }
};
