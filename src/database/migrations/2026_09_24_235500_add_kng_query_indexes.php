<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('kng_facturas')) {
            DB::statement("CREATE INDEX IF NOT EXISTS kng_facturas_id_inq_idx ON kng_facturas ((datos->>'ID_INQ')) WHERE eliminado = false");
            DB::statement("CREATE INDEX IF NOT EXISTS kng_facturas_cta_orig_idx ON kng_facturas ((datos->>'CTA_ORIG')) WHERE eliminado = false");
            DB::statement("CREATE INDEX IF NOT EXISTS kng_facturas_fecha_idx ON kng_facturas ((datos->>'FECHA')) WHERE eliminado = false");
            DB::statement("CREATE INDEX IF NOT EXISTS kng_facturas_lote_idx ON kng_facturas ((datos->>'LOTE')) WHERE eliminado = false");
        }

        if (Schema::hasTable('kng_lotes')) {
            DB::statement("CREATE INDEX IF NOT EXISTS kng_lotes_id_lote_idx ON kng_lotes ((coalesce(datos->>'ID_LOTE', datos->>'LOTE'))) WHERE eliminado = false");
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS kng_facturas_id_inq_idx');
        DB::statement('DROP INDEX IF EXISTS kng_facturas_cta_orig_idx');
        DB::statement('DROP INDEX IF EXISTS kng_facturas_fecha_idx');
        DB::statement('DROP INDEX IF EXISTS kng_facturas_lote_idx');
        DB::statement('DROP INDEX IF EXISTS kng_lotes_id_lote_idx');
    }
};
