<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_inmuebles', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo_origen', 120)->nullable();
            $table->string('domicilio', 220);
            $table->string('domicilio_normalizado', 220)->nullable();
            $table->string('codigo_postal', 12)->nullable();
            $table->string('localidad', 120)->nullable();
            $table->string('provincia', 120)->nullable();
            $table->string('pais', 80)->default('ARGENTINA');
            $table->string('destino_codigo', 20)->nullable();
            $table->string('cochera_codigo', 20)->nullable();
            $table->jsonb('partidas')->default(DB::raw("'[]'::jsonb"));
            $table->jsonb('identificadores_servicios')->default(DB::raw("'{}'::jsonb"));
            $table->string('origen', 30)->default('COBOL');
            $table->foreignId('lote_importacion_id')->nullable()->constrained('web_lotes_importacion');
            $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->char('hash_origen', 64)->nullable();
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('ACTIVO');
            $table->timestampTz('fecha_importacion')->useCurrent();
            $table->timestampsTz();

            $table->index('domicilio_normalizado');
            $table->index('localidad');
        });

        DB::statement("CREATE UNIQUE INDEX uq_web_inmuebles_codigo_origen ON web_inmuebles (codigo_origen) WHERE codigo_origen IS NOT NULL AND codigo_origen <> ''");

        Schema::create('web_contratos', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo_origen', 180)->unique();
            $table->string('cuenta_inquilino_origen', 20)->nullable();
            $table->string('cuenta_propietario_origen', 20)->nullable();
            $table->date('fecha_contrato')->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->date('fecha_baja')->nullable();
            $table->string('marca_baja', 5)->nullable();
            $table->integer('plazo_meses')->nullable();
            $table->integer('plazo_dias')->nullable();
            $table->string('indice_ajuste', 20)->nullable();
            $table->string('tipo_ajuste', 20)->nullable();
            $table->date('fecha_primer_ajuste')->nullable();
            $table->jsonb('ajustes_adicionales')->default(DB::raw("'[]'::jsonb"));
            $table->decimal('cuota_1', 14, 2)->nullable();
            $table->decimal('cuota_2', 14, 2)->nullable();
            $table->decimal('cuota_2_dolar', 14, 2)->nullable();
            $table->decimal('alquiler_inicial', 14, 2)->nullable();
            $table->decimal('penalidad_porcentaje', 7, 3)->nullable();
            $table->decimal('penalidad_importe', 14, 2)->nullable();
            $table->decimal('comision_anterior', 7, 3)->nullable();
            $table->decimal('comision_impuestos', 7, 3)->nullable();
            $table->boolean('reparacion')->nullable();
            $table->integer('dias_reparacion')->nullable();
            $table->date('fecha_juicio')->nullable();
            $table->string('abogado_codigo', 20)->nullable();
            $table->string('origen', 30)->default('COBOL');
            $table->foreignId('lote_importacion_id')->nullable()->constrained('web_lotes_importacion');
            $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->char('hash_origen', 64)->nullable();
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('ACTIVO');
            $table->timestampTz('fecha_importacion')->useCurrent();
            $table->timestampsTz();

            $table->index(['cuenta_inquilino_origen', 'cuenta_propietario_origen'], 'ix_web_contratos_cuentas');
            $table->index(['fecha_inicio', 'fecha_fin'], 'ix_web_contratos_fechas');
        });

        Schema::create('web_contrato_inquilinos', function (Blueprint $table): void {
            $this->relacionBase($table);
            $table->foreignId('contrato_id')->constrained('web_contratos');
            $table->foreignId('inquilino_id')->constrained('web_inquilinos');
            $table->string('rol', 40)->default('TITULAR');
            $table->decimal('participacion', 9, 6)->nullable();
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();

            $table->unique(['contrato_id', 'inquilino_id', 'rol'], 'uq_web_contrato_inquilinos');
            $table->index('inquilino_id');
        });

        Schema::create('web_contrato_propietarios', function (Blueprint $table): void {
            $this->relacionBase($table);
            $table->foreignId('contrato_id')->constrained('web_contratos');
            $table->foreignId('propietario_id')->constrained('web_propietarios');
            $table->decimal('porcentaje', 9, 6)->nullable();
            $table->string('forma_pago_codigo', 20)->nullable();
            $table->string('cuenta_deposito', 40)->nullable();
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();

            $table->unique(['contrato_id', 'propietario_id'], 'uq_web_contrato_propietarios');
            $table->index('propietario_id');
        });

        Schema::create('web_contrato_inmuebles', function (Blueprint $table): void {
            $this->relacionBase($table);
            $table->foreignId('contrato_id')->constrained('web_contratos');
            $table->foreignId('inmueble_id')->constrained('web_inmuebles');
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();

            $table->unique(['contrato_id', 'inmueble_id'], 'uq_web_contrato_inmuebles');
            $table->index('inmueble_id');
        });

        Schema::create('web_inmuebles_propietarios', function (Blueprint $table): void {
            $this->relacionBase($table);
            $table->foreignId('inmueble_id')->constrained('web_inmuebles');
            $table->foreignId('propietario_id')->constrained('web_propietarios');
            $table->decimal('porcentaje', 9, 6)->nullable();
            $table->date('desde')->nullable();
            $table->date('hasta')->nullable();

            $table->unique(['inmueble_id', 'propietario_id', 'desde'], 'uq_web_inmuebles_propietarios');
            $table->index('propietario_id');
        });

        $this->checks();
    }

    public function down(): void
    {
        Schema::dropIfExists('web_inmuebles_propietarios');
        Schema::dropIfExists('web_contrato_inmuebles');
        Schema::dropIfExists('web_contrato_propietarios');
        Schema::dropIfExists('web_contrato_inquilinos');
        Schema::dropIfExists('web_contratos');
        Schema::dropIfExists('web_inmuebles');
    }

    private function relacionBase(Blueprint $table): void
    {
        $table->id();
        $table->string('origen', 30)->default('COBOL');
        $table->foreignId('lote_importacion_id')->nullable()->constrained('web_lotes_importacion');
        $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
        $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
        $table->char('hash_origen', 64)->nullable();
        $table->string('version_regla', 80);
        $table->string('estado', 40)->default('ACTIVO');
        $table->timestampsTz();
    }

    private function checks(): void
    {
        DB::statement("ALTER TABLE web_inmuebles ADD CONSTRAINT ck_web_inmuebles_estado CHECK (estado IN ('ACTIVO','INACTIVO','HISTORICO','ANULADO'))");
        DB::statement("ALTER TABLE web_contratos ADD CONSTRAINT ck_web_contratos_estado CHECK (estado IN ('ACTIVO','VIGENTE','VENCIDO','BAJA','HISTORICO','ANULADO'))");
        DB::statement("ALTER TABLE web_contratos ADD CONSTRAINT ck_web_contratos_fechas CHECK (fecha_inicio IS NULL OR fecha_fin IS NULL OR fecha_inicio <= fecha_fin)");

        foreach (['web_contrato_inquilinos', 'web_contrato_propietarios', 'web_contrato_inmuebles', 'web_inmuebles_propietarios'] as $tabla) {
            DB::statement("ALTER TABLE {$tabla} ADD CONSTRAINT ck_{$tabla}_estado CHECK (estado IN ('ACTIVO','INACTIVO','HISTORICO','ANULADO'))");
        }

        DB::statement("ALTER TABLE web_contrato_inquilinos ADD CONSTRAINT ck_web_contrato_inquilinos_participacion CHECK (participacion IS NULL OR (participacion >= 0 AND participacion <= 100))");
        DB::statement("ALTER TABLE web_contrato_propietarios ADD CONSTRAINT ck_web_contrato_propietarios_porcentaje CHECK (porcentaje IS NULL OR (porcentaje >= 0 AND porcentaje <= 100))");
        DB::statement("ALTER TABLE web_inmuebles_propietarios ADD CONSTRAINT ck_web_inmuebles_propietarios_porcentaje CHECK (porcentaje IS NULL OR (porcentaje >= 0 AND porcentaje <= 100))");
    }
};
