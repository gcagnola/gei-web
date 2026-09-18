<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cuentas_corrientes', function (Blueprint $table): void {
            if (!Schema::hasColumn('cuentas_corrientes', 'destinatario_facturacion')) {
                $table->string('destinatario_facturacion', 30)
                    ->default('PROPIETARIO')
                    ->after('modalidad_facturacion');
            }

            if (!Schema::hasColumn('cuentas_corrientes', 'agrupacion_facturacion')) {
                $table->string('agrupacion_facturacion', 30)
                    ->default('CONSOLIDADA')
                    ->after('destinatario_facturacion');
            }

            if (!Schema::hasColumn('cuentas_corrientes', 'tratamiento_fiscal')) {
                $table->string('tratamiento_fiscal', 30)
                    ->default('AUTOMATICO')
                    ->after('agrupacion_facturacion');
            }

            if (!Schema::hasColumn('cuentas_corrientes', 'observaciones_facturacion')) {
                $table->text('observaciones_facturacion')
                    ->nullable()
                    ->after('tratamiento_fiscal');
            }
        });

        DB::statement("
            ALTER TABLE cuentas_corrientes
            ADD CONSTRAINT cuentas_corrientes_destinatario_facturacion_check
            CHECK (destinatario_facturacion IN ('PROPIETARIO','COPROPIETARIOS'))
        ");

        DB::statement("
            ALTER TABLE cuentas_corrientes
            ADD CONSTRAINT cuentas_corrientes_agrupacion_facturacion_check
            CHECK (agrupacion_facturacion IN ('CONSOLIDADA','POR_INMUEBLE','POR_MOVIMIENTO'))
        ");

        DB::statement("
            ALTER TABLE cuentas_corrientes
            ADD CONSTRAINT cuentas_corrientes_tratamiento_fiscal_check
            CHECK (tratamiento_fiscal IN ('AUTOMATICO','FORZAR_A','FORZAR_B','SIN_CATEGORIZAR'))
        ");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE cuentas_corrientes DROP CONSTRAINT IF EXISTS cuentas_corrientes_destinatario_facturacion_check');
        DB::statement('ALTER TABLE cuentas_corrientes DROP CONSTRAINT IF EXISTS cuentas_corrientes_agrupacion_facturacion_check');
        DB::statement('ALTER TABLE cuentas_corrientes DROP CONSTRAINT IF EXISTS cuentas_corrientes_tratamiento_fiscal_check');

        Schema::table('cuentas_corrientes', function (Blueprint $table): void {
            foreach ([
                'observaciones_facturacion',
                'tratamiento_fiscal',
                'agrupacion_facturacion',
                'destinatario_facturacion',
            ] as $column) {
                if (Schema::hasColumn('cuentas_corrientes', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
