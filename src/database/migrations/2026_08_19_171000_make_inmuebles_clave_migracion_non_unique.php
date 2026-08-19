<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // clave_migracion = hash(cuenta_propietario|domicilio_normalizado) es
        // evidencia de similitud, no identidad real del inmueble.
        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM pg_constraint
        WHERE conname = 'inmuebles_clave_migracion_unique'
          AND conrelid = 'public.inmuebles'::regclass
    ) THEN
        ALTER TABLE public.inmuebles
            DROP CONSTRAINT inmuebles_clave_migracion_unique;
    END IF;
END $$
SQL);

        DB::statement(
            'CREATE INDEX IF NOT EXISTS inmuebles_clave_migracion_index ON public.inmuebles (clave_migracion)'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS public.inmuebles_clave_migracion_index');
        DB::statement(<<<'SQL'
DO $$
BEGIN
    IF EXISTS (
        SELECT clave_migracion
        FROM public.inmuebles
        GROUP BY clave_migracion
        HAVING count(*) > 1
    ) THEN
        RAISE EXCEPTION 'No se puede restaurar UNIQUE en inmuebles.clave_migracion: existen claves repetidas válidas.';
    END IF;

    ALTER TABLE public.inmuebles
        ADD CONSTRAINT inmuebles_clave_migracion_unique UNIQUE (clave_migracion);
END $$
SQL);
    }
};
