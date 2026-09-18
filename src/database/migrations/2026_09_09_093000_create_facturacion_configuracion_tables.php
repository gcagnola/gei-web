<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Puntos de venta.
         *
         * KNG actual:
         *   38 Santa Fe    - facturación general
         *   39 Santo Tomé - facturación general
         *   40 Santa Fe    - facturación manual histórica
         *   41 Santo Tomé - facturación manual histórica
         */
        Schema::create('puntos_venta', function (Blueprint $table) {
            $table->bigIncrements('id_punto_venta');

            $table->unsignedInteger('numero')->unique();

            $table->string('nombre', 120);
            $table->string('localidad', 80)->nullable();

            // GENERAL / MANUAL
            $table->string('modalidad', 20)->default('GENERAL');

            $table->boolean('activo')->default(true);
            $table->boolean('historico')->default(false);

            $table->timestampsTz();

            $table->index(
                ['modalidad', 'activo'],
                'puntos_venta_modalidad_activo_idx'
            );
        });


        /*
         * Correlatividad fiscal.
         *
         * Un contador independiente por:
         *
         *   punto de venta + tipo de comprobante
         *
         * Tipos:
         *   1 = Factura A
         *   3 = Nota de Crédito A
         *   6 = Factura B
         *   8 = Nota de Crédito B
         *
         * proximo_numero tiene la misma semántica que KNG:
         * ES EL NUMERO QUE SE UTILIZARA EN EL PROXIMO COMPROBANTE.
         */
        Schema::create('numeraciones_comprobantes', function (Blueprint $table) {
            $table->bigIncrements('id_numeracion_comprobante');

            $table->unsignedBigInteger('id_punto_venta');

            $table->unsignedSmallInteger('tipo_comprobante');

            $table->unsignedBigInteger('proximo_numero');

            $table->boolean('activo')->default(true);

            /*
             * Se usarán cuando pasemos de RECE a ARCA WSFE.
             * Por ahora quedan NULL.
             */
            $table->unsignedBigInteger('ultimo_numero_arca')->nullable();
            $table->timestampTz('ultima_sincronizacion_arca_at')->nullable();

            $table->timestampsTz();

            $table->foreign('id_punto_venta')
                ->references('id_punto_venta')
                ->on('puntos_venta')
                ->onDelete('restrict');

            $table->unique(
                ['id_punto_venta', 'tipo_comprobante'],
                'numeraciones_pv_tipo_unique'
            );
        });


        /*
         * Parámetros generales equivalentes al SETEOS.DBF.
         *
         * No guardamos "próximo cliente": PostgreSQL ya maneja sus PK
         * mediante secuencias.
         */
        Schema::create('configuraciones_facturacion', function (Blueprint $table) {
            $table->bigIncrements('id_configuracion_facturacion');

            $table->unsignedBigInteger('proximo_numero_lote');

            /*
             * Se conserva para compatibilidad/regresión KNG.
             * Luego revisaremos la regla fiscal vigente antes de
             * utilizarlo en producción.
             */
            $table->decimal('tope_no_gravado', 16, 2)->nullable();

            $table->decimal('alicuota_iva_general', 7, 4)
                ->default(21);

            $table->unsignedSmallInteger('decimales_redondeo')
                ->default(2);

            // SETEOS.HAGO_NC
            $table->boolean('emitir_nc_locadores')
                ->default(false);

            /*
             * Etapa actual: RECE
             * Futuro: ARCA
             */
            $table->string('modo_emision', 20)
                ->default('RECE');

            $table->string('ambiente_arca', 20)
                ->default('HOMOLOGACION');

            $table->string('cuit_emisor', 20)->nullable();

            $table->timestampsTz();
        });


        /*
         * Tabla equivalente a las alícuotas parametrizables de KNG.
         */
        Schema::create('alicuotas_iva', function (Blueprint $table) {
            $table->bigIncrements('id_alicuota_iva');

            $table->unsignedSmallInteger('codigo')->unique();

            $table->string('nombre', 100);

            $table->decimal('porcentaje', 7, 4);

            $table->boolean('activo')->default(true);

            $table->timestampsTz();
        });


        /*
         * Estado operativo de facturación de cada cuenta.
         *
         * facturable reemplaza:
         *   INQUILINOS.FACTURABLE
         *   PROPIETARIOS.FACTURAR
         *
         * modalidad_facturacion reemplaza FACT_IND:
         *   NORMAL
         *   INDIVIDUAL
         *
         * Nullable en facturable es intencional durante la migración:
         * NULL = todavía no fue determinada/migrada.
         */
        Schema::table('cuentas_corrientes', function (Blueprint $table) {
            $table->boolean('facturable')
                ->nullable()
                ->after('activo');

            $table->string('modalidad_facturacion', 20)
                ->default('NORMAL')
                ->after('facturable');

            $table->index(
                [
                    'dominio',
                    'facturable',
                    'modalidad_facturacion',
                ],
                'cc_facturacion_idx'
            );
        });


        /*
         * Equivalente funcional a COPROP.DBF cuando la cuenta
         * está configurada para facturación INDIVIDUAL.
         *
         * cuenta_corriente_id = cuenta propietaria origen
         * cliente_id          = receptor en GeI-Web
         * identificador_origen = ID_CLIENTE histórico KNG
         */
        Schema::create('cuentas_facturacion_beneficiarios', function (Blueprint $table) {
            $table->bigIncrements(
                'id_cuenta_facturacion_beneficiario'
            );

            $table->unsignedBigInteger('cuenta_corriente_id');

            $table->unsignedBigInteger('cliente_id')->nullable();

            $table->string('identificador_origen', 30)->nullable();

            $table->decimal('porcentaje', 9, 6);

            $table->boolean('activo')->default(true);

            $table->string('origen', 30)
                ->default('MIGRACION_KNG');

            $table->jsonb('datos_origen')->nullable();

            $table->timestampsTz();

            $table->foreign('cuenta_corriente_id')
                ->references('id')
                ->on('cuentas_corrientes')
                ->onDelete('cascade');

            $table->foreign('cliente_id')
                ->references('id')
                ->on('clientes')
                ->onDelete('set null');

            $table->unique(
                [
                    'cuenta_corriente_id',
                    'identificador_origen',
                ],
                'cc_fact_benef_origen_unique'
            );

            $table->index(
                ['cuenta_corriente_id', 'activo'],
                'cc_fact_benef_activo_idx'
            );
        });
    }


    public function down(): void
    {
        Schema::dropIfExists(
            'cuentas_facturacion_beneficiarios'
        );

        Schema::table('cuentas_corrientes', function (Blueprint $table) {
            $table->dropIndex('cc_facturacion_idx');

            $table->dropColumn([
                'facturable',
                'modalidad_facturacion',
            ]);
        });

        Schema::dropIfExists('alicuotas_iva');
        Schema::dropIfExists('configuraciones_facturacion');
        Schema::dropIfExists('numeraciones_comprobantes');
        Schema::dropIfExists('puntos_venta');
    }
};
