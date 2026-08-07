<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_procesos_transformacion_cobol', function (Blueprint $table): void {
            $table->bigIncrements('web_id');
            $table->char('web_periodo', 6)->index();
            $table->char('web_lote_hash', 64);
            $table->string('web_estado', 20)->index();
            $table->string('web_etapa', 40)->nullable();
            $table->jsonb('web_resultado')->nullable();
            $table->text('web_mensaje_error')->nullable();
            $table->timestampTz('web_iniciado_at');
            $table->timestampTz('web_finalizado_at')->nullable();
            $table->timestampTz('web_created_at')->nullable();
            $table->timestampTz('web_updated_at')->nullable();

            $table->index(['web_periodo', 'web_lote_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('web_procesos_transformacion_cobol');
    }
};
