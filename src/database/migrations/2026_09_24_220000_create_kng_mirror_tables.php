<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kng_facturas', function (Blueprint $table): void {
            $table->bigIncrements('id_kng_factura');
            $table->unsignedInteger('registro')->unique();
            $table->boolean('eliminado')->default(false)->index();
            $table->jsonb('datos');
            $table->text('texto_busqueda')->nullable();
            $table->timestamp('importado_at');
            $table->timestamps();
        });

        Schema::create('kng_lotes', function (Blueprint $table): void {
            $table->bigIncrements('id_kng_lote');
            $table->unsignedInteger('registro')->unique();
            $table->boolean('eliminado')->default(false)->index();
            $table->jsonb('datos');
            $table->text('texto_busqueda')->nullable();
            $table->timestamp('importado_at');
            $table->timestamps();
        });

        Schema::create('kng_importaciones', function (Blueprint $table): void {
            $table->bigIncrements('id_kng_importacion');
            $table->string('estado', 20);
            $table->unsignedInteger('facturas_registros')->default(0);
            $table->unsignedInteger('lotes_registros')->default(0);
            $table->jsonb('facturas_metadata')->nullable();
            $table->jsonb('lotes_metadata')->nullable();
            $table->timestamp('iniciado_at')->nullable();
            $table->timestamp('finalizado_at')->nullable();
            $table->timestamps();
        });

        DB::statement('CREATE INDEX kng_facturas_datos_gin_idx ON kng_facturas USING GIN (datos)');
        DB::statement("CREATE INDEX kng_facturas_busqueda_gin_idx ON kng_facturas USING GIN (to_tsvector('simple', coalesce(texto_busqueda, '')))");
        DB::statement('CREATE INDEX kng_lotes_datos_gin_idx ON kng_lotes USING GIN (datos)');
        DB::statement("CREATE INDEX kng_lotes_busqueda_gin_idx ON kng_lotes USING GIN (to_tsvector('simple', coalesce(texto_busqueda, '')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('kng_importaciones');
        Schema::dropIfExists('kng_lotes');
        Schema::dropIfExists('kng_facturas');
    }
};
