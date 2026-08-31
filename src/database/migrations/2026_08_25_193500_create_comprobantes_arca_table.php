<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comprobantes_arca', function (Blueprint $table): void {
            $table->bigIncrements('id_comprobante_arca');
            $table->string('cuenta_cobol', 20);
            $table->string('tipo_codigo', 4);
            $table->string('punto_venta', 8);
            $table->string('numero_comprobante', 20);
            $table->string('nombre_archivo', 255)->unique();
            $table->char('periodo', 6);
            $table->timestamp('fecha_archivo');
            $table->unsignedBigInteger('tamano_bytes')->default(0);
            $table->boolean('valido')->default(true);
            $table->timestamps();

            $table->index(
                ['periodo', 'cuenta_cobol', 'valido'],
                'comprobantes_arca_periodo_cuenta_valido_idx'
            );
            $table->index(
                ['cuenta_cobol', 'fecha_archivo'],
                'comprobantes_arca_cuenta_fecha_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comprobantes_arca');
    }
};
