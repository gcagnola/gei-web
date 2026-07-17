<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_lotes_importacion', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo_lote', 80)->unique();
            $table->unsignedBigInteger('repositorio_id')->nullable();
            $table->char('periodo_detectado', 6)->nullable();
            $table->string('origen', 30)->default('COBOL');
            $table->string('estado', 40)->default('NUEVO');
            $table->timestampTz('fecha_inicio')->useCurrent();
            $table->timestampTz('fecha_fin')->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('version_importador', 80);
            $table->string('version_parser', 80);
            $table->string('version_regla', 80);
            $table->jsonb('resumen')->default(DB::raw("'{}'::jsonb"));
            $table->text('observaciones')->nullable();
            $table->timestampsTz();

            $table->index('periodo_detectado');
            $table->index('estado');
        });

        Schema::create('web_archivos_importados', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lote_importacion_id')->constrained('web_lotes_importacion');
            $table->string('nombre_original', 255);
            $table->string('ruta_logica', 500)->nullable();
            $table->string('tipo_archivo', 50);
            $table->string('sede', 20)->nullable();
            $table->char('periodo_detectado', 6)->nullable();
            $table->char('hash_archivo', 64);
            $table->unsignedBigInteger('tamano_bytes')->default(0);
            $table->timestampTz('fecha_archivo')->nullable();
            $table->string('encoding_detectado', 40)->nullable();
            $table->unsignedInteger('cantidad_lineas')->default(0);
            $table->string('estado', 40)->default('NUEVO');
            $table->jsonb('resumen')->default(DB::raw("'{}'::jsonb"));
            $table->timestampsTz();

            $table->unique(['lote_importacion_id', 'tipo_archivo', 'nombre_original'], 'uq_web_archivos_lote_tipo_nombre');
            $table->index(['lote_importacion_id', 'tipo_archivo'], 'ix_web_archivos_lote_tipo');
            $table->index(['tipo_archivo', 'periodo_detectado'], 'ix_web_archivos_tipo_periodo');
            $table->index('hash_archivo');
        });

        Schema::create('web_registros_origen', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('lote_importacion_id')->constrained('web_lotes_importacion');
            $table->foreignId('archivo_importado_id')->constrained('web_archivos_importados');
            $table->string('archivo_origen', 255);
            $table->string('tipo_archivo', 50);
            $table->string('tipo_registro', 60);
            $table->unsignedInteger('numero_linea');
            $table->unsignedBigInteger('orden_origen')->nullable();
            $table->string('clave_origen', 240);
            $table->char('hash_registro', 64);
            $table->text('contenido_original');
            $table->jsonb('payload_normalizado')->default(DB::raw("'{}'::jsonb"));
            $table->string('version_parser', 80);
            $table->string('version_regla', 80);
            $table->string('origen', 30)->default('COBOL');
            $table->string('estado', 50)->default('NUEVO');
            $table->string('entidad_destino', 80)->nullable();
            $table->unsignedBigInteger('id_destino')->nullable();
            $table->text('motivo')->nullable();
            $table->timestampTz('fecha_importacion')->useCurrent();
            $table->timestampsTz();

            $table->unique(['archivo_importado_id', 'numero_linea'], 'uq_web_registros_linea');
            $table->index(['tipo_archivo', 'tipo_registro', 'clave_origen'], 'ix_web_registros_tipo_clave');
            $table->index(['tipo_archivo', 'tipo_registro', 'clave_origen', 'hash_registro'], 'ix_web_registros_clave_hash');
            $table->index('hash_registro');
            $table->index('archivo_importado_id');
        });

        DB::statement('CREATE INDEX ix_web_registros_payload_gin ON web_registros_origen USING gin (payload_normalizado)');

        $this->checks();
    }

    public function down(): void
    {
        Schema::dropIfExists('web_registros_origen');
        Schema::dropIfExists('web_archivos_importados');
        Schema::dropIfExists('web_lotes_importacion');
    }

    private function checks(): void
    {
        DB::statement("ALTER TABLE web_lotes_importacion ADD CONSTRAINT ck_web_lotes_origen CHECK (origen IN ('COBOL','FOX','DB_GEI','GEI_WEB','MANUAL','SISTEMA'))");
        DB::statement("ALTER TABLE web_lotes_importacion ADD CONSTRAINT ck_web_lotes_estado CHECK (estado IN ('NUEVO','VALIDANDO','VALIDADO','IMPORTANDO','IMPORTADO','CONFLICTO_CAMBIO_ORIGEN','ERROR_FORMATO','ERROR_DB','GENERADO','ANULADO'))");
        DB::statement("ALTER TABLE web_lotes_importacion ADD CONSTRAINT ck_web_lotes_periodo CHECK (periodo_detectado IS NULL OR periodo_detectado ~ '^[0-9]{6}$')");

        DB::statement("ALTER TABLE web_archivos_importados ADD CONSTRAINT ck_web_archivos_tipo CHECK (tipo_archivo IN ('PROPIETAR','INQUILINO','CTACTEPRO','INQCTACTE','LIQUIDA','LIQUIDB','DAILOC','DAILOC2','PLIQLOC','OTRO'))");
        DB::statement("ALTER TABLE web_archivos_importados ADD CONSTRAINT ck_web_archivos_estado CHECK (estado IN ('NUEVO','OMITIDO_YA_IMPORTADO','ACTUALIZADO','CONFLICTO_CAMBIO_ORIGEN','ERROR_FORMATO','ERROR_DB','VALIDADO','IMPORTADO','ANULADO'))");
        DB::statement("ALTER TABLE web_archivos_importados ADD CONSTRAINT ck_web_archivos_hash CHECK (hash_archivo ~ '^[0-9a-f]{64}$')");
        DB::statement("ALTER TABLE web_archivos_importados ADD CONSTRAINT ck_web_archivos_periodo CHECK (periodo_detectado IS NULL OR periodo_detectado ~ '^[0-9]{6}$')");

        DB::statement("ALTER TABLE web_registros_origen ADD CONSTRAINT ck_web_registros_estado CHECK (estado IN ('NUEVO','OMITIDO_YA_IMPORTADO','ACTUALIZADO','CONFLICTO_CAMBIO_ORIGEN','ERROR_FORMATO','ERROR_DB','GENERADO','ANULADO'))");
        DB::statement("ALTER TABLE web_registros_origen ADD CONSTRAINT ck_web_registros_origen CHECK (origen IN ('COBOL','FOX','DB_GEI','GEI_WEB','MANUAL','SISTEMA'))");
        DB::statement("ALTER TABLE web_registros_origen ADD CONSTRAINT ck_web_registros_hash CHECK (hash_registro ~ '^[0-9a-f]{64}$')");
    }
};
