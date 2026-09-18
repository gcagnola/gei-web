<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('comprobantes_arca')) {
            return;
        }

        Schema::table('comprobantes_arca', function (Blueprint $table): void {
            if (! Schema::hasColumn('comprobantes_arca', 'lote')) {
                $table->unsignedInteger('lote')->nullable()->after('periodo');
                $table->index(['lote', 'cuenta_cobol'], 'comprobantes_arca_lote_cuenta_idx');
            }

            if (! Schema::hasColumn('comprobantes_arca', 'ruta_relativa')) {
                $table->string('ruta_relativa', 500)->nullable()->after('nombre_archivo');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('comprobantes_arca')) {
            return;
        }

        Schema::table('comprobantes_arca', function (Blueprint $table): void {
            if (Schema::hasColumn('comprobantes_arca', 'ruta_relativa')) {
                $table->dropColumn('ruta_relativa');
            }

            if (Schema::hasColumn('comprobantes_arca', 'lote')) {
                try {
                    $table->dropIndex('comprobantes_arca_lote_cuenta_idx');
                } catch (\Throwable) {
                    //
                }
                $table->dropColumn('lote');
            }
        });
    }
};
