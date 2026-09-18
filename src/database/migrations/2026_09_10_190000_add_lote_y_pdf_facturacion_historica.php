<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('facturaciones', function (Blueprint $table): void {
            $table->bigInteger('lote_origen')->nullable()->after('periodo')->index();
        });

        Schema::table('facturas', function (Blueprint $table): void {
            $table->string('pdf_ruta', 500)->nullable()->after('comprobante_arca_id');
        });

        // Backfill de las 10 corridas históricas de julio ya importadas.
        DB::statement("
            UPDATE facturaciones
            SET lote_origen = ((regexp_match(observaciones, 'Lote[[:space:]]+([0-9]+)'))[1])::bigint
            WHERE origen = 'MIGRADO_KNG'
              AND lote_origen IS NULL
              AND observaciones ~ 'Lote[[:space:]]+[0-9]+'
        ");
    }

    public function down(): void
    {
        Schema::table('facturas', function (Blueprint $table): void {
            $table->dropColumn('pdf_ruta');
        });

        Schema::table('facturaciones', function (Blueprint $table): void {
            $table->dropIndex(['lote_origen']);
            $table->dropColumn('lote_origen');
        });
    }
};
