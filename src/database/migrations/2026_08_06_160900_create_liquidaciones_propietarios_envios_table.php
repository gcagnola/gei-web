<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('liquidaciones_propietarios_envios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('liquidacion_propietario_id')
                ->constrained('liquidaciones_propietarios')
                ->cascadeOnDelete();
            $table->foreignId('cliente_id')
                ->nullable()
                ->constrained('clientes')
                ->nullOnDelete();
            $table->foreignId('usuario_id')
                ->nullable()
                ->constrained('usuarios')
                ->nullOnDelete();
            $table->string('email_destino', 255);
            $table->string('tipo_envio', 20);
            $table->string('estado', 20)->default('PENDIENTE');
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->text('mensaje_error')->nullable();
            $table->timestampTz('intentado_at')->nullable();
            $table->timestampTz('enviado_at')->nullable();
            $table->timestampsTz();

            $table->index(['liquidacion_propietario_id', 'estado']);
            $table->index(['estado', 'created_at']);
            $table->index('email_destino');
        });

        Schema::create('liquidaciones_email_jobs', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('liquidaciones_email_failed_jobs', function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        DB::statement(
            "CREATE UNIQUE INDEX liquidaciones_propietarios_envios_pendiente_unique
             ON liquidaciones_propietarios_envios (liquidacion_propietario_id)
             WHERE estado IN ('PENDIENTE', 'PROCESANDO')"
        );

        DB::statement(
            "ALTER TABLE liquidaciones_propietarios_envios
             ADD CONSTRAINT liquidaciones_propietarios_envios_tipo_check
             CHECK (tipo_envio IN ('INDIVIDUAL', 'MASIVO'))"
        );
        DB::statement(
            "ALTER TABLE liquidaciones_propietarios_envios
             ADD CONSTRAINT liquidaciones_propietarios_envios_estado_check
             CHECK (estado IN ('PENDIENTE', 'PROCESANDO', 'ENVIADO', 'ERROR'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('liquidaciones_email_failed_jobs');
        Schema::dropIfExists('liquidaciones_email_jobs');
        Schema::dropIfExists('liquidaciones_propietarios_envios');
    }
};
