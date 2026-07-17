<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('web_cuentas_corrientes', function (Blueprint $table): void {
            $table->id();
            $table->string('dominio', 30);
            $table->string('cuenta_origen', 20);
            $table->foreignId('persona_id')->nullable()->constrained('web_personas');
            $table->foreignId('propietario_id')->nullable()->constrained('web_propietarios');
            $table->foreignId('inquilino_id')->nullable()->constrained('web_inquilinos');
            $table->string('moneda', 10)->nullable();
            $table->string('origen', 30)->default('COBOL');
            $table->foreignId('lote_importacion_id')->nullable()->constrained('web_lotes_importacion');
            $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('ACTIVO');
            $table->timestampsTz();

            $table->unique(['dominio', 'cuenta_origen'], 'uq_web_cuentas_corrientes');
            $table->index('propietario_id');
            $table->index('inquilino_id');
        });

        Schema::create('web_conceptos_movimiento', function (Blueprint $table): void {
            $table->id();
            $table->string('dominio', 30);
            $table->string('codigo_origen', 20);
            $table->string('descripcion', 180);
            $table->string('afecta', 20)->nullable();
            $table->boolean('requiere_iva')->nullable();
            $table->boolean('genera_item_liquidacion')->nullable();
            $table->string('origen', 30)->default('COBOL');
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('ACTIVO');
            $table->timestampsTz();

            $table->unique(['dominio', 'codigo_origen'], 'uq_web_conceptos_movimiento');
        });

        Schema::create('web_movimientos_cuenta', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cuenta_corriente_id')->constrained('web_cuentas_corrientes');
            $table->string('dominio', 30);
            $table->string('cuenta_origen', 20);
            $table->foreignId('contrato_id')->nullable()->constrained('web_contratos');
            $table->foreignId('inmueble_id')->nullable()->constrained('web_inmuebles');
            $table->foreignId('propietario_id')->nullable()->constrained('web_propietarios');
            $table->foreignId('inquilino_id')->nullable()->constrained('web_inquilinos');
            $table->date('fecha')->nullable();
            $table->date('fecha_vencimiento')->nullable();
            $table->char('periodo', 6)->nullable();
            $table->string('codigo_concepto', 20);
            $table->foreignId('concepto_id')->nullable()->constrained('web_conceptos_movimiento');
            $table->string('numero_movimiento', 20);
            $table->string('numero_comprobante', 40)->nullable();
            $table->string('referencia', 80)->nullable();
            $table->string('descripcion', 240)->nullable();
            $table->decimal('importe', 16, 2)->default(0);
            $table->decimal('debe', 16, 2)->default(0);
            $table->decimal('haber', 16, 2)->default(0);
            $table->decimal('saldo', 16, 2)->nullable();
            $table->decimal('penalidad', 16, 2)->nullable();
            $table->decimal('abonado', 16, 2)->nullable();
            $table->decimal('iva', 16, 2)->nullable();
            $table->decimal('no_gravado', 16, 2)->nullable();
            $table->string('liquidado_origen', 5)->nullable();
            $table->foreignId('archivo_origen_id')->nullable()->constrained('web_archivos_importados');
            $table->foreignId('registro_origen_id')->nullable()->constrained('web_registros_origen');
            $table->char('hash_origen', 64);
            $table->string('origen', 30)->default('COBOL');
            $table->string('version_regla', 80);
            $table->string('estado', 40)->default('ACTIVO');
            $table->timestampsTz();

            $table->index(['cuenta_corriente_id', 'fecha'], 'ix_web_movimientos_cuenta_fecha');
            $table->index('periodo');
            $table->index('contrato_id');
        });

        DB::statement("CREATE UNIQUE INDEX uq_web_movimientos_cuenta_origen ON web_movimientos_cuenta (dominio, cuenta_origen, COALESCE(fecha, DATE '0001-01-01'), codigo_concepto, numero_movimiento, hash_origen)");

        $this->checks();
    }

    public function down(): void
    {
        Schema::dropIfExists('web_movimientos_cuenta');
        Schema::dropIfExists('web_conceptos_movimiento');
        Schema::dropIfExists('web_cuentas_corrientes');
    }

    private function checks(): void
    {
        DB::statement("ALTER TABLE web_cuentas_corrientes ADD CONSTRAINT ck_web_cuentas_dominio CHECK (dominio IN ('PROPIETARIO','INQUILINO'))");
        DB::statement("ALTER TABLE web_cuentas_corrientes ADD CONSTRAINT ck_web_cuentas_estado CHECK (estado IN ('ACTIVO','INACTIVO','HISTORICO','ANULADO'))");
        DB::statement("ALTER TABLE web_cuentas_corrientes ADD CONSTRAINT ck_web_cuentas_titular CHECK ((dominio = 'PROPIETARIO' AND propietario_id IS NOT NULL) OR (dominio = 'INQUILINO' AND inquilino_id IS NOT NULL))");

        DB::statement("ALTER TABLE web_conceptos_movimiento ADD CONSTRAINT ck_web_conceptos_dominio CHECK (dominio IN ('PROPIETARIO','INQUILINO','AMBOS'))");
        DB::statement("ALTER TABLE web_conceptos_movimiento ADD CONSTRAINT ck_web_conceptos_afecta CHECK (afecta IS NULL OR afecta IN ('DEBE','HABER','SEGUN_SIGNO','NO_APLICA'))");

        DB::statement("ALTER TABLE web_movimientos_cuenta ADD CONSTRAINT ck_web_movimientos_dominio CHECK (dominio IN ('PROPIETARIO','INQUILINO'))");
        DB::statement("ALTER TABLE web_movimientos_cuenta ADD CONSTRAINT ck_web_movimientos_periodo CHECK (periodo IS NULL OR periodo ~ '^[0-9]{6}$')");
        DB::statement("ALTER TABLE web_movimientos_cuenta ADD CONSTRAINT ck_web_movimientos_importes CHECK (debe >= 0 AND haber >= 0)");
    }
};
