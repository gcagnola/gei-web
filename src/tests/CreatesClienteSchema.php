<?php

namespace Tests;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

trait CreatesClienteSchema
{
    protected function createClienteSchema(): void
    {
        foreach ([
            'web_envios_liquidaciones',
            'liquidaciones_de_clientes',
            'contratos_inmuebles',
            'contratos_inquilinos',
            'inmuebles_propietarios',
            'contratos',
            'inmuebles',
            'tipos_de_inmuebles',
            'localidades',
            'provincias',
            'clientes',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::create('clientes', function (Blueprint $table) {
            $table->increments('codigo_cliente');
            $table->string('doctipo', 15)->default('');
            $table->string('docnro', 12)->default('');
            $table->string('apellidos', 40)->default('');
            $table->string('nombres', 80)->default('');
            $table->string('domicilio', 100)->default('');
            $table->string('provincia', 30)->default('');
            $table->string('departamento', 30)->default('');
            $table->string('localidad', 50)->default('');
            $table->string('cp', 8)->default('');
            $table->string('caractel', 6)->default('');
            $table->string('telefonos', 50)->default('');
            $table->string('celular', 25)->default('');
            $table->string('fax', 25)->default('');
            $table->text('email')->default('');
            $table->string('nacionalidad', 40)->default('');
            $table->string('cuit', 13)->default('');
            $table->string('condicion_iva', 25)->default('');
            $table->string('personeria', 20)->default('');
            $table->decimal('id_prop')->default(0);
            $table->decimal('id_inq')->default(0);
            $table->string('profesion', 100)->default('');
            $table->string('lugar_de_trabajo', 100)->default('');
            $table->string('razon_social', 100)->default('');
            $table->decimal('saldo_inicial_inquilino')->default(0);
            $table->boolean('web_validada')->default(false);
            $table->boolean('web_operativo')->default(false);
        });

        Schema::create('provincias', function (Blueprint $table) {
            $table->string('nombre', 20)->primary();
            $table->string('pais', 35)->nullable();
            $table->decimal('codprov');
        });

        Schema::create('localidades', function (Blueprint $table) {
            $table->string('codpais', 5)->nullable();
            $table->string('pais', 50)->nullable();
            $table->string('provincia', 25)->nullable();
            $table->string('caractel', 8)->nullable();
            $table->string('nombre', 50)->nullable();
            $table->string('cp', 8)->nullable();
        });

        Schema::create('tipos_de_inmuebles', function (Blueprint $table) {
            $table->increments('cod_tipo_inmueble');
            $table->string('tipo_inmueble', 100)->default('');
        });

        Schema::create('inmuebles', function (Blueprint $table) {
            $table->increments('codigo_inmueble');
            $table->string('domicilio_calle', 80)->default('');
            $table->string('domicilio_nro', 10)->default('');
            $table->string('domicilio_edificio', 40)->default('');
            $table->string('domicilio_piso', 20)->default('');
            $table->string('domicilio_dpto', 20)->default('');
            $table->string('localidad', 80)->default('');
            $table->unsignedInteger('cod_tipo_inmueble')->nullable();
        });

        Schema::create('contratos', function (Blueprint $table) {
            $table->increments('codigo_contrato');
            $table->date('fecha_contrato')->default('1900-01-01');
            $table->decimal('plazo')->default(0);
            $table->date('fecha_fin')->default('1900-01-01');
            $table->decimal('importe_inicial')->default(0);
            $table->date('fecha_inicio')->default('1900-01-01');
            $table->text('archivo_contrato')->default('');
            $table->text('observaciones')->default('');
            $table->string('numero_de_contrato', 20)->default('');
            $table->decimal('cotizacion_dolar')->default(0);
        });

        Schema::create('contratos_inquilinos', function (Blueprint $table) {
            $table->unsignedInteger('codigo_contrato');
            $table->unsignedInteger('codigo_cliente');
            $table->decimal('porcentaje_participacion')->default(0);
            $table->decimal('id_inq')->default(0);
        });

        Schema::create('contratos_inmuebles', function (Blueprint $table) {
            $table->unsignedInteger('codigo_contrato');
            $table->unsignedInteger('codigo_inmueble');
        });

        Schema::create('inmuebles_propietarios', function (Blueprint $table) {
            $table->unsignedInteger('codigo_inmueble');
            $table->unsignedInteger('codigo_cliente');
            $table->decimal('porcentaje_titularidad')->default(0);
            $table->decimal('id_prop')->default(0);
        });

        Schema::create('liquidaciones_de_clientes', function (Blueprint $table) {
            $table->increments('numero_de_liquidacion');
            $table->decimal('punto_venta', 4)->default(0);
            $table->decimal('numero', 8)->default(0);
            $table->date('fecha');
            $table->unsignedInteger('codigo_cliente')->default(0);
            $table->decimal('nro_cuenta', 11)->default(0);
            $table->char('periodo', 25)->default('');
            $table->char('nombre', 100)->default('');
            $table->char('razon_social', 100)->default('');
            $table->date('fecha_desde')->nullable();
            $table->date('fecha_hasta')->nullable();
            $table->decimal('numero_de_comprobante', 8)->default(0);
            $table->decimal('total_liquidado', 16, 2)->default(0);
        });

        Schema::create('web_envios_liquidaciones', function (Blueprint $table) {
            $table->id('web_id');
            $table->unsignedInteger('web_codigo_cliente');
            $table->unsignedInteger('web_numero_de_liquidacion');
            $table->unsignedSmallInteger('web_punto_venta');
            $table->unsignedInteger('web_numero');
            $table->string('web_destinatario');
            $table->timestamp('web_intentado_en');
            $table->unsignedInteger('web_usuario_id')->nullable();
            $table->string('web_estado', 20);
            $table->string('web_mensaje_error', 500)->nullable();
            $table->string('web_ruta_relativa_pdf');
        });
    }
}
