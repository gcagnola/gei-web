<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();

        DB::table('modulos')->updateOrInsert(
            ['codigo' => 'CAMARAS'],
            [
                'seccion' => 'General',
                'nombre' => 'Cámaras',
                'orden' => 55,
                'activo' => true,
                'updated_at' => $ahora,
                'created_at' => $ahora,
            ]
        );

        $moduloId = DB::table('modulos')->where('codigo', 'CAMARAS')->value('id');
        $perfilId = DB::table('perfiles')->where('codigo', 'ADMINISTRADOR')->value('id');

        if ($moduloId && $perfilId) {
            DB::table('perfiles_modulos')->updateOrInsert(
                ['perfil_id' => $perfilId, 'modulo_id' => $moduloId],
                ['updated_at' => $ahora, 'created_at' => $ahora]
            );
        }
    }

    public function down(): void
    {
        $moduloId = DB::table('modulos')->where('codigo', 'CAMARAS')->value('id');

        if ($moduloId) {
            DB::table('perfiles_modulos')->where('modulo_id', $moduloId)->delete();
            DB::table('modulos')->where('id', $moduloId)->delete();
        }
    }
};
