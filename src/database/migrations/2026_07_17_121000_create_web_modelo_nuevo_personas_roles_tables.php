<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_personas', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo_persona', 20)->default('DESCONOCIDA');
            $table->string('nombre', 180);
            $table->string('apellido', 120)->nullable();
            $table->string('razon_social', 180)->nullable();
            $table->string('tipo_documento', 20)->nullable();
            $table->string('numero_documento', 30)->nullable();
            $table->string('cuit', 20)->nullable();
            $table->string('condicion_fiscal', 50)->nullable();
            $table->string('personeria_fiscal', 50)->nullable();
            $table->string('email', 180)->nullable();
            $table->string('telefono', 100)->nullable();
            $table->text('domicilio_principal')->nullable();
            $table->string('codigo_postal', 12)->nullable();
            $table->string('localidad', 120)->nullable();
            $table->string('provincia', 120)->nullable();
            $table->string('pais', 80)->default('ARGENTINA');
            $table->string('origen', 30)->default('COBOL');
            $table->foreignId('lote_importacion_id')->nullable()->constrained('web_lotes_importacion');
            $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->char('hash_origen', 64)->nullable();
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('ACTIVO');
            $table->timestampTz('fecha_importacion')->useCurrent();
            $table->timestampsTz();

            $table->index('cuit');
            $table->index(['tipo_documento', 'numero_documento'], 'ix_web_personas_documento');
            $table->index('nombre');
        });

        Schema::create('web_propietarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('persona_id')->constrained('web_personas');
            $table->string('cuenta_propietario', 20)->unique();
            $table->string('forma_pago_codigo', 10)->nullable();
            $table->string('subforma_pago_codigo', 10)->nullable();
            $table->string('cuenta_deposito', 40)->nullable();
            $table->boolean('liquidar')->nullable();
            $table->boolean('liquidacion_sin_reserva')->nullable();
            $table->decimal('comision_administracion', 8, 3)->nullable();
            $table->decimal('comision_impuestos', 8, 3)->nullable();
            $table->integer('nro_ultima_liquidacion')->nullable();
            $table->date('fecha_ultima_liquidacion')->nullable();
            $table->string('marca_sucursal', 5)->nullable();
            $table->string('origen', 30)->default('COBOL');
            $table->foreignId('lote_importacion_id')->nullable()->constrained('web_lotes_importacion');
            $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->char('hash_origen', 64)->nullable();
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('ACTIVO');
            $table->timestampTz('fecha_importacion')->useCurrent();
            $table->timestampsTz();

            $table->index('persona_id');
        });

        Schema::create('web_inquilinos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('persona_id')->constrained('web_personas');
            $table->string('cuenta_inquilino', 20)->unique();
            $table->string('telefono_particular', 100)->nullable();
            $table->string('telefono_laboral', 100)->nullable();
            $table->string('marca_baja', 5)->nullable();
            $table->date('fecha_baja')->nullable();
            $table->string('origen', 30)->default('COBOL');
            $table->foreignId('lote_importacion_id')->nullable()->constrained('web_lotes_importacion');
            $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->char('hash_origen', 64)->nullable();
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('ACTIVO');
            $table->timestampTz('fecha_importacion')->useCurrent();
            $table->timestampsTz();

            $table->index('persona_id');
        });

        $this->checks();
    }

    public function down(): void
    {
        Schema::dropIfExists('web_inquilinos');
        Schema::dropIfExists('web_propietarios');
        Schema::dropIfExists('web_personas');
    }

    private function checks(): void
    {
        DB::statement("ALTER TABLE web_personas ADD CONSTRAINT ck_web_personas_tipo CHECK (tipo_persona IN ('FISICA','JURIDICA','DESCONOCIDA'))");
        DB::statement("ALTER TABLE web_personas ADD CONSTRAINT ck_web_personas_origen CHECK (origen IN ('COBOL','FOX','DB_GEI','GEI_WEB','MANUAL','SISTEMA'))");
        DB::statement("ALTER TABLE web_personas ADD CONSTRAINT ck_web_personas_estado CHECK (estado IN ('ACTIVO','INACTIVO','HISTORICO','ANULADO'))");
        DB::statement("ALTER TABLE web_propietarios ADD CONSTRAINT ck_web_propietarios_estado CHECK (estado IN ('ACTIVO','INACTIVO','HISTORICO','ANULADO'))");
        DB::statement("ALTER TABLE web_inquilinos ADD CONSTRAINT ck_web_inquilinos_estado CHECK (estado IN ('ACTIVO','INACTIVO','BAJA','HISTORICO','ANULADO'))");
    }
};
