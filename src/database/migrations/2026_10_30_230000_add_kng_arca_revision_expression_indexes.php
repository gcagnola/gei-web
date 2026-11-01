<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * CREATE INDEX CONCURRENTLY debe ejecutarse fuera de una transacción.
     * No bloquea las escrituras ordinarias de la tabla mientras construye el índice.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        if (! Schema::hasTable('kng_facturas')) {
            throw new RuntimeException('No existe kng_facturas en la conexión operativa; no se crean índices.');
        }

        // Mismo CASE y mismo LEFT utilizados en
        // ComprobantesArcaService::consultarPeriodo().
        // El prefijo por mes evita recorrer todas las facturas; el segundo
        // campo localiza la cuenta COBOL efectiva (ID_INQ o CTA_ORIG).
        DB::statement(<<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS kng_facturas_arca_mes_cuenta_20261030_idx
ON public.kng_facturas (
    (left(coalesce(datos->>'FECHA', ''), 7)),
    (CASE
        WHEN regexp_replace(coalesce(datos->>'ID_INQ', ''), '[^0-9]', '', 'g') NOT IN ('', '0')
            THEN regexp_replace(datos->>'ID_INQ', '[^0-9]', '', 'g')
        ELSE regexp_replace(coalesce(datos->>'CTA_ORIG', ''), '[^0-9]', '', 'g')
    END)
)
WHERE eliminado = false
SQL);

        // Mismo DISTINCT + filtro usados en periodosDisponibles().
        // Índice parcial: conserva estrictamente el mismo predicado.
        DB::statement(<<<'SQL'
CREATE INDEX CONCURRENTLY IF NOT EXISTS kng_facturas_arca_periodos_20261030_idx
ON public.kng_facturas (
    (replace(left(datos->>'FECHA', 7), '-', ''))
)
WHERE eliminado = false
  AND coalesce(datos->>'FECHA', '') ~ '^[12][0-9]{3}-(0[1-9]|1[0-2])-'
SQL);

        // Actualizar estadísticas después de crear los índices.
        DB::statement('ANALYZE public.kng_facturas');

        // IF NOT EXISTS no valida un índice preexistente. Es importante
        // detectar índices INVALID producidos por operaciones interrumpidas.
        $indices = DB::select(<<<'SQL'
SELECT c.relname AS nombre,
       CASE WHEN i.indisvalid AND i.indisready THEN 1 ELSE 0 END AS valido
FROM pg_class c
JOIN pg_index i ON i.indexrelid = c.oid
JOIN pg_namespace n ON n.oid = c.relnamespace
WHERE n.nspname = 'public'
  AND c.relname IN (
    'kng_facturas_arca_mes_cuenta_20261030_idx',
    'kng_facturas_arca_periodos_20261030_idx'
  )
SQL);
        if (count($indices) !== 2) {
            throw new RuntimeException('No se encontraron los dos índices ARCA esperados.');
        }
        foreach ($indices as $indice) {
            if ((int) $indice->valido !== 1) {
                throw new RuntimeException('Índice ARCA no válido: '.$indice->nombre.'. Revisar antes de continuar.');
            }
        }
    }

    public function down(): void
    {
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.kng_facturas_arca_periodos_20261030_idx');
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS public.kng_facturas_arca_mes_cuenta_20261030_idx');
    }
};
