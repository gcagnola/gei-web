<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class UsuariosSucursalesSeeder extends Seeder
{
    public function run(): void
    {
        $sucursales = DB::table('sucursales')
            ->whereIn('codigo', ['SF', 'ST'])
            ->pluck('id', 'codigo');

        $usuarios = DB::table('usuarios')
            ->whereIn('nombre_usuario', ['gcagnola', 'camila'])
            ->pluck('id', 'nombre_usuario');

        foreach (['gcagnola', 'camila'] as $nombreUsuario) {
            if (! isset($usuarios[$nombreUsuario])) {
                continue;
            }

            foreach (['SF', 'ST'] as $codigoSucursal) {
                if (! isset($sucursales[$codigoSucursal])) {
                    continue;
                }

                DB::table('usuarios_sucursales')->updateOrInsert(
                    [
                        'usuario_id' => $usuarios[$nombreUsuario],
                        'sucursal_id' => $sucursales[$codigoSucursal],
                    ],
                    [
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }
        }
    }
}
