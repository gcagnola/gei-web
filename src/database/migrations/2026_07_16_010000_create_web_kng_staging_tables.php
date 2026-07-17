<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_kng_lotes', function (Blueprint $table) {
            $table->id('web_id');
            $table->unsignedBigInteger('web_importacion_id');
            $table->string('web_estado', 40);
            $table->string('web_version_mapeo', 80);
            $table->json('web_resumen')->nullable();
            $table->timestamps();

            $table->unique(['web_importacion_id', 'web_version_mapeo']);
        });

        $this->crearTablaComun('web_kng_propietarios', function (Blueprint $table): void {
            $table->decimal('web_cuenta', 11)->default(0);
            $table->string('web_nombre', 120)->default('');
            $table->string('web_domicilio', 120)->default('');
            $table->string('web_codigo_postal', 12)->default('');
            $table->string('web_localidad', 80)->default('');
            $table->string('web_provincia', 50)->default('');
            $table->string('web_telefono', 60)->default('');
            $table->string('web_cuit', 20)->default('');
            $table->unsignedSmallInteger('web_personeria_fiscal')->default(0);
        });

        $this->crearTablaComun('web_kng_inquilinos', function (Blueprint $table): void {
            $table->decimal('web_cuenta', 11)->default(0);
            $table->decimal('web_cuenta_propietario', 11)->default(0);
            $table->string('web_nombre', 120)->default('');
            $table->string('web_domicilio_inmueble', 160)->default('');
            $table->string('web_domicilio_legal', 160)->default('');
            $table->string('web_documento', 20)->default('');
            $table->string('web_cuit', 20)->default('');
            $table->date('web_fecha_contrato')->nullable();
            $table->date('web_fecha_vencimiento')->nullable();
            $table->date('web_fecha_baja')->nullable();
            $table->boolean('web_omitido_por_baja_antigua')->default(false);
        });

        $this->crearTablaComun('web_kng_cta_propietarios', function (Blueprint $table): void {
            $table->decimal('web_cuenta', 11)->default(0);
            $table->date('web_fecha')->nullable();
            $table->string('web_numero_movimiento', 30)->default('');
            $table->string('web_concepto', 160)->default('');
            $table->decimal('web_debe', 16, 2)->default(0);
            $table->decimal('web_haber', 16, 2)->default(0);
            $table->string('web_periodo', 30)->default('');
        });

        $this->crearTablaComun('web_kng_cta_inquilinos', function (Blueprint $table): void {
            $table->decimal('web_cuenta', 11)->default(0);
            $table->date('web_fecha')->nullable();
            $table->string('web_numero_movimiento', 30)->default('');
            $table->string('web_concepto', 160)->default('');
            $table->decimal('web_debe', 16, 2)->default(0);
            $table->decimal('web_haber', 16, 2)->default(0);
            $table->string('web_periodo', 30)->default('');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_kng_cta_inquilinos');
        Schema::dropIfExists('web_kng_cta_propietarios');
        Schema::dropIfExists('web_kng_inquilinos');
        Schema::dropIfExists('web_kng_propietarios');
        Schema::dropIfExists('web_kng_lotes');
    }

    private function crearTablaComun(string $nombre, callable $columnas): void
    {
        Schema::create($nombre, function (Blueprint $table) use ($columnas, $nombre): void {
            $table->id('web_id');
            $table->unsignedBigInteger('web_importacion_id');
            $table->unsignedBigInteger('web_registro_staging_id')->nullable();
            $table->string('web_archivo_origen', 120);
            $table->unsignedInteger('web_numero_linea');
            $table->string('web_hash_linea', 64);
            $table->string('web_clave_kng', 160)->default('');
            $table->unsignedInteger('web_orden_origen');
            $table->string('web_estado_parseo', 40)->default('interpretado');
            $table->string('web_version_mapeo', 80);
            $table->json('web_campos_interpretados')->nullable();
            $table->text('web_payload_original')->nullable();
            $columnas($table);
            $table->timestamps();

            $table->unique(['web_importacion_id', 'web_version_mapeo', 'web_hash_linea'], "ux_{$nombre}_hash");
            $table->index(['web_importacion_id', 'web_clave_kng'], "idx_{$nombre}_clave");
        });
    }
};
