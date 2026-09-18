<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private function conexion()
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

    public function up(): void
    {
        $db = $this->conexion();
        $db->statement('create schema if not exists gei_core');
        $db->statement(<<<'SQL'
create table if not exists gei_core.inmuebles_validaciones (
    id bigserial primary key,
    inmueble_id bigint not null references gei_core.inmuebles(id) on delete cascade,
    candidatos_hash char(64) not null,
    candidatos_ids jsonb not null,
    periodo_referencia char(6) not null,
    usuario_id bigint null,
    validado_at timestamptz not null default now(),
    unique (inmueble_id, candidatos_hash)
)
SQL);
        $db->statement('create index if not exists inmuebles_validaciones_inmueble_idx on gei_core.inmuebles_validaciones (inmueble_id)');
    }

    public function down(): void
    {
        $this->conexion()->statement('drop table if exists gei_core.inmuebles_validaciones');
    }
};
