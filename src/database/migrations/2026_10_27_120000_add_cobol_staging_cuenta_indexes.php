<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Los índices pertenecen a gei_exploracion, no a gei_db.
     * Laravel registra la ejecución de esta migración en la conexión principal.
     */
    public function up(): void
    {
        $db = $this->exploracion();

        foreach (['cobol_staging.ctactepro', 'cobol_staging.inqctacte'] as $tabla) {
            $existe = $db->selectOne('select to_regclass(?) as tabla', [$tabla]);
            if (($existe->tabla ?? null) === null) {
                throw new \RuntimeException("No existe la tabla {$tabla} en gei_exploracion; no se crean índices.");
            }
        }

        $db->statement(
            'create index if not exists ctactepro_archivo_cuenta_idx '
            .'on cobol_staging.ctactepro (archivo_id, cuenta)'
        );

        $db->statement(
            'create index if not exists inqctacte_archivo_cuenta_idx '
            .'on cobol_staging.inqctacte (archivo_id, cuenta)'
        );
    }

    public function down(): void
    {
        // Conservador por diseño: ambos índices existían en Desarrollo antes de
        // incorporar esta migración. Un rollback no debe borrar objetos previos.
        // Si alguna vez es necesario quitarlos, hacerlo en una migración explícita.
    }

    private function exploracion(): Connection
    {
        $base = config('database.connections.pgsql');
        if (! is_array($base)) {
            throw new \RuntimeException('No existe la conexión PostgreSQL base.');
        }

        $base['host'] = config('gei.exploracion.host');
        $base['port'] = config('gei.exploracion.port');
        $base['database'] = config('gei.exploracion.database');
        $base['username'] = config('gei.exploracion.username');
        $base['password'] = config('gei.exploracion.password');
        $base['search_path'] = 'public';

        config(['database.connections.gei_exploracion' => $base]);
        DB::purge('gei_exploracion');

        return DB::connection('gei_exploracion');
    }
};
