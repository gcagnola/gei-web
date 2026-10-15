<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        // PostgreSQL: permite comparar sólo los registros nuevos/modificados
        // sin recorrer toda la tabla por cada tanda del DBF.
        DB::statement('CREATE INDEX IF NOT EXISTS idx_gei_kng_facturas_registro ON kng_facturas (registro)');
        DB::statement('CREATE INDEX IF NOT EXISTS idx_gei_kng_lotes_registro ON kng_lotes (registro)');
        Schema::create('kng_pdfs_pendientes', function (Blueprint $table): void {
            $table->unsignedBigInteger('registro')->primary();
            $table->string('nombre_pdf', 80);
            $table->string('lote', 32);
            $table->string('estado', 32);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kng_pdfs_pendientes');
        DB::statement('DROP INDEX IF EXISTS idx_gei_kng_facturas_registro');
        DB::statement('DROP INDEX IF EXISTS idx_gei_kng_lotes_registro');
    }
};
