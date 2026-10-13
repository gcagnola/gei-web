<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_usuarios', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')
                ->unique()
                ->constrained('clientes')
                ->restrictOnDelete()
                ->cascadeOnUpdate();
            $table->string('password')->nullable();
            $table->boolean('activo')->default(true);
            $table->unsignedSmallInteger('intentos_fallidos')->default(0);
            $table->timestampTz('bloqueado_hasta')->nullable();
            $table->timestampTz('activado_at')->nullable();
            $table->timestampTz('datos_confirmados_at')->nullable();
            $table->timestampTz('ultimo_acceso_at')->nullable();
            $table->string('ultimo_acceso_ip', 45)->nullable();
            $table->rememberToken();
            $table->timestampsTz();

            $table->index('activo');
        });

        Schema::create('portal_tokens', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('portal_usuario_id')
                ->constrained('portal_usuarios')
                ->cascadeOnDelete()
                ->cascadeOnUpdate();
            $table->string('proposito', 40);
            $table->char('token_hash', 64);
            $table->string('enviado_a', 180);
            $table->foreignId('enviado_por_usuario_id')
                ->nullable()
                ->constrained('usuarios')
                ->nullOnDelete()
                ->cascadeOnUpdate();
            $table->timestampTz('enviado_at');
            $table->timestampTz('vence_at');
            $table->timestampTz('usado_at')->nullable();
            $table->timestampsTz();

            $table->unique(['portal_usuario_id', 'proposito']);
            $table->unique('token_hash');
            $table->index(['proposito', 'vence_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_tokens');
        Schema::dropIfExists('portal_usuarios');
    }
};
