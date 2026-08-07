<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cuentas_corrientes', function (Blueprint $table): void {
            $table->id();
            $table->string('dominio', 20);
            $table->string('cuenta', 30);
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->foreignId('cliente_cuenta_id')->nullable()->constrained('clientes_cuentas')->nullOnDelete();
            $table->foreignId('contrato_id')->nullable()->constrained('contratos')->nullOnDelete();
            $table->boolean('activo')->nullable();
            $table->string('origen', 30)->default('COBOL');
            $table->timestampsTz();

            $table->unique(['dominio', 'cuenta'], 'cuentas_corrientes_dominio_cuenta_unique');
            $table->index('cliente_id');
            $table->index('contrato_id');
        });

        Schema::create('cuentas_corrientes_movimientos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cuenta_corriente_id')->constrained('cuentas_corrientes')->cascadeOnDelete();
            $table->char('clave_origen', 64)->unique();
            $table->string('dominio', 20);
            $table->string('cuenta', 30);
            $table->date('fecha')->nullable();
            $table->char('fecha_origen', 8);
            $table->char('periodo', 6)->nullable()->index();
            $table->string('codigo', 10);
            $table->string('numero', 20);
            $table->date('fecha_vencimiento')->nullable();
            $table->char('fecha_vencimiento_origen', 8)->nullable();
            $table->decimal('importe', 16, 2);
            $table->decimal('debe', 16, 2)->default(0);
            $table->decimal('haber', 16, 2)->default(0);
            $table->decimal('importe_penalidad', 16, 2)->nullable();
            $table->decimal('importe_abonado', 16, 2)->nullable();
            $table->decimal('iva', 16, 2)->nullable();
            $table->decimal('no_gravado', 16, 2)->nullable();
            $table->string('descripcion', 240)->nullable();
            $table->string('cuenta_inquilino_referencia', 30)->nullable()->index();
            $table->string('liquidado_origen', 5)->nullable();
            $table->boolean('afecta_saldo')->default(true);
            $table->unsignedBigInteger('archivo_origen_id')->nullable();
            $table->unsignedBigInteger('numero_linea')->nullable();
            $table->char('hash_origen', 64)->nullable();
            $table->jsonb('datos_origen')->nullable();
            $table->timestampsTz();

            $table->index(['dominio', 'cuenta', 'fecha'], 'cc_movimientos_cuenta_fecha_index');
            $table->index(['dominio', 'codigo'], 'cc_movimientos_dominio_codigo_index');
        });

        Schema::create('cuentas_corrientes_incidencias', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cuenta_corriente_id')->nullable()->constrained('cuentas_corrientes')->cascadeOnDelete();
            $table->foreignId('movimiento_id')->nullable()->constrained('cuentas_corrientes_movimientos')->cascadeOnDelete();
            $table->string('dominio', 20);
            $table->string('cuenta', 30);
            $table->string('tipo', 20);
            $table->string('motivo', 80);
            $table->boolean('bloqueante')->default(false);
            $table->string('estado', 20)->default('PENDIENTE');
            $table->char('firma', 64)->unique();
            $table->jsonb('detalle')->nullable();
            $table->timestampTz('detectado_at');
            $table->timestampTz('ultima_deteccion_at');
            $table->timestampTz('resuelto_at')->nullable();
            $table->timestampsTz();

            $table->index(['estado', 'tipo', 'motivo'], 'cc_incidencias_estado_tipo_index');
            $table->index(['dominio', 'cuenta'], 'cc_incidencias_cuenta_index');
        });

        DB::statement("ALTER TABLE cuentas_corrientes ADD CONSTRAINT cuentas_corrientes_dominio_check CHECK (dominio IN ('PROPIETARIO', 'INQUILINO'))");
        DB::statement("ALTER TABLE cuentas_corrientes_movimientos ADD CONSTRAINT cc_movimientos_dominio_check CHECK (dominio IN ('PROPIETARIO', 'INQUILINO'))");
        DB::statement("ALTER TABLE cuentas_corrientes_incidencias ADD CONSTRAINT cc_incidencias_tipo_check CHECK (tipo IN ('CONFLICTO', 'ADVERTENCIA'))");
        DB::statement("ALTER TABLE cuentas_corrientes_incidencias ADD CONSTRAINT cc_incidencias_estado_check CHECK (estado IN ('PENDIENTE', 'RESUELTO'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('cuentas_corrientes_incidencias');
        Schema::dropIfExists('cuentas_corrientes_movimientos');
        Schema::dropIfExists('cuentas_corrientes');
    }
};
