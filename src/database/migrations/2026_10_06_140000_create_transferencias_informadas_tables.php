<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transferencias_informadas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')
                ->constrained('clientes')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->foreignId('cliente_cuenta_id')
                ->nullable()
                ->constrained('clientes_cuentas')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->date('fecha_transferencia');
            $table->decimal('importe', 14, 2);
            $table->string('referencia', 120)->nullable();
            $table->text('observaciones')->nullable();
            $table->string('estado', 20)->default('PENDIENTE');
            $table->foreignId('verificada_por_usuario_id')
                ->nullable()
                ->constrained('usuarios')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->timestampTz('verificada_at')->nullable();
            $table->text('rechazo_motivo')->nullable();
            $table->timestampsTz();

            $table->index(['cliente_id', 'estado']);
            $table->index(['estado', 'fecha_transferencia']);
        });

        DB::statement(
            "ALTER TABLE transferencias_informadas
             ADD CONSTRAINT transferencias_informadas_estado_check
             CHECK (estado IN ('PENDIENTE','VERIFICADA','RECHAZADA','ANULADA'))"
        );

        Schema::create('transferencias_informadas_archivos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('transferencia_informada_id')
                ->constrained('transferencias_informadas')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('nombre_original', 255);
            $table->string('archivo', 500);
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('tamano')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transferencias_informadas_archivos');
        Schema::dropIfExists('transferencias_informadas');
    }
};
