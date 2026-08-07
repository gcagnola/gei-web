<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidaciones_propietarios', function (Blueprint $table): void {
            $table->id();
            $table->char('clave_origen', 64)->unique();
            $table->char('contenido_hash', 64);
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('cuenta_corriente_id')->nullable()->constrained('cuentas_corrientes')->nullOnDelete();
            $table->char('periodo', 6)->index();
            $table->char('sede', 2);
            $table->char('tipo', 1);
            $table->date('fecha');
            $table->string('cuenta', 30)->index();
            $table->string('cuenta_impresa', 30);
            $table->string('comprobante', 20);
            $table->string('codigo_aux', 30)->nullable();
            $table->unsignedBigInteger('numero_interno')->unique();
            $table->string('propietario', 180);
            $table->string('domicilio', 180)->nullable();
            $table->string('codigo_postal', 12)->nullable();
            $table->string('localidad', 120)->nullable();
            $table->string('provincia', 120)->nullable();
            $table->string('condicion_iva', 40)->nullable();
            $table->string('cuit', 11)->nullable();
            $table->string('banco', 160)->nullable();
            $table->string('tipo_cuenta_banco', 160)->nullable();
            $table->string('copropietario', 180)->nullable();
            $table->string('porcentaje', 20)->nullable();
            $table->decimal('total', 16, 2);
            $table->decimal('total_bruto', 16, 2);
            $table->decimal('total_copropietario', 16, 2);
            $table->decimal('total_debe', 16, 2);
            $table->decimal('total_haber', 16, 2);
            $table->decimal('total_neto_gravado', 16, 2);
            $table->decimal('total_iva', 16, 2);
            $table->decimal('total_final', 16, 2);
            $table->string('archivo_origen', 80);
            $table->string('control_estado', 40);
            $table->jsonb('control_pliqloc')->nullable();
            $table->string('estado', 30)->default('IMPORTADA');
            $table->string('pdf_ruta', 500)->nullable();
            $table->unsignedBigInteger('pdf_bytes')->nullable();
            $table->timestampTz('pdf_generado_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['periodo', 'sede', 'tipo', 'cuenta', 'comprobante', 'copropietario'],
                'liquidaciones_propietarios_identidad_unique'
            );
            $table->index(['periodo', 'estado']);
            $table->index(['cliente_id', 'periodo']);
        });

        Schema::create('liquidaciones_propietarios_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('liquidacion_propietario_id')
                ->constrained('liquidaciones_propietarios')
                ->cascadeOnDelete();
            $table->unsignedInteger('orden');
            $table->string('nombre', 240)->nullable();
            $table->string('detalle', 500)->nullable();
            $table->string('vencimiento', 20)->nullable();
            $table->decimal('debe', 16, 2)->default(0);
            $table->decimal('haber', 16, 2)->default(0);
            $table->string('referencia', 120)->nullable();
            $table->string('numero_movimiento_origen', 40)->nullable();
            $table->string('fecha_movimiento_origen', 20)->nullable();
            $table->string('archivo_origen', 80)->nullable();
            $table->unsignedInteger('orden_origen')->nullable();
            $table->string('tipo_movimiento', 40)->nullable();
            $table->timestampsTz();

            $table->unique(
                ['liquidacion_propietario_id', 'orden'],
                'liquidaciones_propietarios_items_orden_unique'
            );
        });

        Schema::create('liquidaciones_propietarios_procesos', function (Blueprint $table): void {
            $table->id();
            $table->char('periodo', 6)->index();
            $table->string('estado', 30);
            $table->char('lote_hash', 64)->nullable();
            $table->unsignedInteger('detectadas')->default(0);
            $table->unsignedInteger('insertadas')->default(0);
            $table->unsignedInteger('actualizadas')->default(0);
            $table->unsignedInteger('omitidas')->default(0);
            $table->unsignedInteger('pdf_generados')->default(0);
            $table->unsignedInteger('errores')->default(0);
            $table->jsonb('resultado')->nullable();
            $table->text('mensaje_error')->nullable();
            $table->timestampTz('iniciado_at');
            $table->timestampTz('finalizado_at')->nullable();
            $table->timestampsTz();
        });

        DB::statement(
            "ALTER TABLE liquidaciones_propietarios
             ADD CONSTRAINT liquidaciones_propietarios_tipo_check CHECK (tipo IN ('A', 'B'))"
        );
        DB::statement(
            "ALTER TABLE liquidaciones_propietarios
             ADD CONSTRAINT liquidaciones_propietarios_sede_check CHECK (sede IN ('SF', 'ST'))"
        );
        DB::statement(
            "ALTER TABLE liquidaciones_propietarios
             ADD CONSTRAINT liquidaciones_propietarios_estado_check
             CHECK (estado IN ('IMPORTADA', 'PDF_GENERADO', 'ERROR'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidaciones_propietarios_procesos');
        Schema::dropIfExists('liquidaciones_propietarios_items');
        Schema::dropIfExists('liquidaciones_propietarios');
    }
};
