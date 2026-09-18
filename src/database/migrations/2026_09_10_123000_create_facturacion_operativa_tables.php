<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facturaciones', function (Blueprint $table): void {
            $table->bigIncrements('id_facturacion');

            $table->char('periodo', 6);
            $table->date('fecha_desde');
            $table->date('fecha_hasta');

            $table->string('origen', 20);
            $table->string('estado', 20);

            $table->string('modo_emision', 20)->nullable();
            $table->string('ambiente_arca', 20)->nullable();

            $table->timestampTz('fecha_proceso')->nullable();

            $table->unsignedInteger('cantidad_facturas')->default(0);
            $table->decimal('importe_total', 15, 2)->default(0);

            $table->text('observaciones')->nullable();

            $table->timestampsTz();

            $table->index(['periodo', 'estado'], 'facturaciones_periodo_estado_idx');
            $table->index(['origen', 'periodo'], 'facturaciones_origen_periodo_idx');

        });

        Schema::create('facturas', function (Blueprint $table): void {
            $table->bigIncrements('id_factura');

            $table->unsignedBigInteger('facturacion_id');
            $table->unsignedBigInteger('punto_venta_id')->nullable();
            $table->unsignedBigInteger('cliente_id')->nullable();

            $table->smallInteger('tipo_comprobante');
            $table->bigInteger('numero_comprobante')->nullable();
            $table->date('fecha_comprobante');

            /*
             * Snapshot fiscal del receptor.
             *
             * No se reconstruye desde clientes al consultar una factura histórica:
             * estos valores son los que efectivamente se usaron al generar/importar
             * el comprobante.
             */
            $table->string('cliente_nombre', 255)->nullable();
            $table->string('condicion_iva', 80)->nullable();
            $table->string('tipo_documento', 30)->nullable();
            $table->string('numero_documento', 30)->nullable();
            $table->string('cuit', 20)->nullable();

            $table->decimal('neto_gravado', 15, 2)->default(0);
            $table->decimal('iva', 15, 2)->default(0);
            $table->decimal('no_gravado', 15, 2)->default(0);
            $table->decimal('otros_tributos', 15, 2)->default(0);
            $table->decimal('total', 15, 2);

            $table->string('estado', 20);
            $table->string('origen', 20);

            $table->string('cae', 30)->nullable();
            $table->date('vencimiento_cae')->nullable();

            /*
             * Referencia al catálogo histórico de comprobantes ARCA.
             * Se deja sin FK por ahora porque comprobantes_arca ya existe y su
             * PK exacta no forma parte de esta migración.
             */
            $table->unsignedBigInteger('comprobante_arca_id')->nullable();

            /*
             * Clave externa/original para trazabilidad de migraciones.
             * Ej.: identificador KNG/archivo histórico. No interviene en emisión.
             */
            $table->string('clave_origen', 150)->nullable();

            $table->timestampsTz();

            $table->foreign('facturacion_id')
                ->references('id_facturacion')
                ->on('facturaciones')
                ->cascadeOnDelete();

            $table->foreign('punto_venta_id')
                ->references('id_punto_venta')
                ->on('puntos_venta')
                ->restrictOnDelete();

            /*
             * En este proyecto clientes usa PK "id".
             */
            $table->foreign('cliente_id')
                ->references('id')
                ->on('clientes')
                ->nullOnDelete();

            $table->index(['facturacion_id', 'estado'], 'facturas_facturacion_estado_idx');
            $table->index(['cliente_id', 'fecha_comprobante'], 'facturas_cliente_fecha_idx');
            $table->index(['punto_venta_id', 'tipo_comprobante', 'numero_comprobante'], 'facturas_pv_tipo_numero_idx');
            $table->index(['origen', 'clave_origen'], 'facturas_origen_clave_idx');
            $table->index('comprobante_arca_id', 'facturas_comprobante_arca_idx');

            /*
             * Un número fiscal, cuando existe, no puede repetirse dentro
             * del mismo punto de venta y tipo de comprobante.
             *
             * PostgreSQL permite múltiples NULL, útil para simulaciones/históricos
             * aún sin número asignado.
             */
            $table->unique(
                ['punto_venta_id', 'tipo_comprobante', 'numero_comprobante'],
                'facturas_pv_tipo_numero_uq'
            );

        });

        Schema::create('facturas_cuentas', function (Blueprint $table): void {
            $table->bigIncrements('id_factura_cuenta');

            $table->unsignedBigInteger('factura_id');
            $table->unsignedBigInteger('cuenta_corriente_id')->nullable();

            /*
             * Snapshot de la cuenta/dominio para conservar trazabilidad aunque
             * luego cambie su vinculación operativa.
             */
            $table->string('cuenta', 40);
            $table->string('dominio', 30);

            $table->decimal('importe', 15, 2);

            $table->timestampsTz();

            $table->foreign('factura_id')
                ->references('id_factura')
                ->on('facturas')
                ->cascadeOnDelete();

            $table->foreign('cuenta_corriente_id')
                ->references('id')
                ->on('cuentas_corrientes')
                ->nullOnDelete();

            $table->index(['factura_id', 'cuenta'], 'facturas_cuentas_factura_cuenta_idx');
            $table->index(['cuenta', 'dominio'], 'facturas_cuentas_cuenta_dominio_idx');

            /*
             * La misma cuenta aparece una sola vez por factura; su importe
             * representa el acumulado de los items de esa cuenta.
             */
            $table->unique(
                ['factura_id', 'cuenta'],
                'facturas_cuentas_factura_cuenta_uq'
            );
        });

        Schema::create('facturas_items', function (Blueprint $table): void {
            $table->bigIncrements('id_factura_item');

            $table->unsignedBigInteger('factura_id');
            $table->unsignedBigInteger('cuenta_corriente_movimiento_id')->nullable();

            $table->string('codigo', 30)->nullable();
            $table->string('descripcion', 500)->nullable();

            /*
             * importe_origen:
             *   importe total del movimiento antes de repartirlo.
             *
             * porcentaje_aplicado:
             *   100 para facturación normal.
             *   porcentaje del beneficiario para copropietarios.
             *
             * importe:
             *   importe efectivo que aporta este item a esta factura.
             */
            $table->decimal('importe_origen', 15, 2);
            $table->decimal('porcentaje_aplicado', 9, 6)->default(100);
            $table->decimal('importe', 15, 2);

            $table->decimal('neto_gravado', 15, 2)->default(0);
            $table->decimal('iva', 15, 2)->default(0);
            $table->decimal('no_gravado', 15, 2)->default(0);
            $table->decimal('alicuota_iva', 7, 4)->nullable();

            /*
             * Identifica de forma explícita al destinatario del reparto que
             * originó el item. Es una foto histórica; puede ser NULL para
             * facturación normal.
             */
            $table->unsignedBigInteger('beneficiario_cliente_id')->nullable();
            $table->string('beneficiario_identificador_origen', 80)->nullable();

            $table->timestampsTz();

            $table->foreign('factura_id')
                ->references('id_factura')
                ->on('facturas')
                ->cascadeOnDelete();

            $table->foreign('cuenta_corriente_movimiento_id')
                ->references('id')
                ->on('cuentas_corrientes_movimientos')
                ->nullOnDelete();

            $table->foreign('beneficiario_cliente_id')
                ->references('id')
                ->on('clientes')
                ->nullOnDelete();

            $table->index(
                ['cuenta_corriente_movimiento_id', 'factura_id'],
                'facturas_items_movimiento_factura_idx'
            );

            $table->index(
                ['beneficiario_cliente_id', 'factura_id'],
                'facturas_items_beneficiario_factura_idx'
            );

            /*
             * NO se crea UNIQUE(cuenta_corriente_movimiento_id):
             * un mismo movimiento puede repartirse legítimamente entre varios
             * beneficiarios/facturas.
             */
        });

        /*
         * Laravel Blueprint no expone un método check() en esta versión.
         * Las restricciones CHECK se agregan explícitamente para PostgreSQL.
         */
        DB::statement("
            ALTER TABLE facturaciones
            ADD CONSTRAINT facturaciones_periodo_chk
            CHECK (periodo ~ '^(19|20)[0-9]{2}(0[1-9]|1[0-2])$')
        ");

        DB::statement("
            ALTER TABLE facturaciones
            ADD CONSTRAINT facturaciones_origen_chk
            CHECK (origen IN ('MIGRADO_KNG', 'GEI_WEB'))
        ");

        DB::statement("
            ALTER TABLE facturaciones
            ADD CONSTRAINT facturaciones_estado_chk
            CHECK (estado IN ('BORRADOR', 'SIMULADA', 'VALIDADA', 'EMITIDA', 'ANULADA'))
        ");

        DB::statement("
            ALTER TABLE facturaciones
            ADD CONSTRAINT facturaciones_modo_emision_chk
            CHECK (modo_emision IS NULL OR modo_emision IN ('RECE', 'WSFE'))
        ");

        DB::statement("
            ALTER TABLE facturaciones
            ADD CONSTRAINT facturaciones_ambiente_arca_chk
            CHECK (ambiente_arca IS NULL OR ambiente_arca IN ('HOMOLOGACION', 'PRODUCCION'))
        ");

        DB::statement("
            ALTER TABLE facturaciones
            ADD CONSTRAINT facturaciones_fechas_chk
            CHECK (fecha_hasta >= fecha_desde)
        ");

        DB::statement("
            ALTER TABLE facturaciones
            ADD CONSTRAINT facturaciones_cantidad_facturas_chk
            CHECK (cantidad_facturas >= 0)
        ");

        DB::statement("
            ALTER TABLE facturas
            ADD CONSTRAINT facturas_estado_chk
            CHECK (estado IN ('BORRADOR', 'SIMULADA', 'VALIDADA', 'AUTORIZADA', 'RECHAZADA', 'ANULADA'))
        ");

        DB::statement("
            ALTER TABLE facturas
            ADD CONSTRAINT facturas_origen_chk
            CHECK (origen IN ('MIGRADO_KNG', 'GEI_WEB'))
        ");

        DB::statement("
            ALTER TABLE facturas
            ADD CONSTRAINT facturas_numero_comprobante_chk
            CHECK (numero_comprobante IS NULL OR numero_comprobante > 0)
        ");

        DB::statement("
            ALTER TABLE facturas_items
            ADD CONSTRAINT facturas_items_porcentaje_chk
            CHECK (porcentaje_aplicado > 0 AND porcentaje_aplicado <= 100)
        ");
    }

    public function down(): void
    {
        Schema::dropIfExists('facturas_items');
        Schema::dropIfExists('facturas_cuentas');
        Schema::dropIfExists('facturas');
        Schema::dropIfExists('facturaciones');
    }
};
