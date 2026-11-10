<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Renombrar conserva valores, claves e índices existentes; no toca inmuebles.
        Schema::table('garantes_registros', function ($table): void {
            $table->renameColumn('domicilio_inmueble', 'domicilio_garante');
        });
    }

    public function down(): void
    {
        Schema::table('garantes_registros', function ($table): void {
            $table->renameColumn('domicilio_garante', 'domicilio_inmueble');
        });
    }
};
