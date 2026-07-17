<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach ([
            'web_kng_propietarios' => ['web_cuenta'],
            'web_kng_inquilinos' => ['web_cuenta', 'web_cuenta_propietario'],
            'web_kng_cta_propietarios' => ['web_cuenta'],
            'web_kng_cta_inquilinos' => ['web_cuenta'],
        ] as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                DB::statement("ALTER TABLE {$tabla} ALTER COLUMN {$columna} TYPE numeric(11,0)");
            }
        }
    }

    public function down(): void
    {
        foreach ([
            'web_kng_propietarios' => ['web_cuenta'],
            'web_kng_inquilinos' => ['web_cuenta', 'web_cuenta_propietario'],
            'web_kng_cta_propietarios' => ['web_cuenta'],
            'web_kng_cta_inquilinos' => ['web_cuenta'],
        ] as $tabla => $columnas) {
            foreach ($columnas as $columna) {
                DB::statement("ALTER TABLE {$tabla} ALTER COLUMN {$columna} TYPE numeric(11,2)");
            }
        }
    }
};
