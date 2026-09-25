<?php

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $db = $this->core();
        $existe = $db->selectOne("select to_regclass('gei_core.personas') as tabla");

        if (($existe->tabla ?? null) !== null) {
            $db->statement('alter table gei_core.personas add column if not exists email varchar(180)');
        }
    }

    public function down(): void
    {
        $db = $this->core();
        $existe = $db->selectOne("select to_regclass('gei_core.personas') as tabla");

        if (($existe->tabla ?? null) !== null) {
            $db->statement('alter table gei_core.personas drop column if exists email');
        }
    }

    private function core(): Connection
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
