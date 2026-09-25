<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SucursalesSeeder extends Seeder
{
    public function run(): void
    {
        DB::table('sucursales')->updateOrInsert(
            ['codigo' => 'SF'],
            [
                'nombre' => 'Santa Fe',
                'activa' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );

        DB::table('sucursales')->updateOrInsert(
            ['codigo' => 'ST'],
            [
                'nombre' => 'Santo Tomé',
                'activa' => true,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }
}
