<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cobol_impresiones') || Schema::hasColumn('cobol_impresiones', 'importe_total')) {
            return;
        }

        Schema::table('cobol_impresiones', function (Blueprint $table): void {
            $table->decimal('importe_total', 15, 2)->nullable()->after('fecha_documento');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('cobol_impresiones') || ! Schema::hasColumn('cobol_impresiones', 'importe_total')) {
            return;
        }

        Schema::table('cobol_impresiones', function (Blueprint $table): void {
            $table->dropColumn('importe_total');
        });
    }
};
