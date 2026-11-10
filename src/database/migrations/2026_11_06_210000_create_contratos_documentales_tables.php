<?php

/**
 * GeI — Expedientes documentales históricos (PDF, RELI y planillas).
 * Migración ADITIVA. No modifica `contratos` ni las tablas operativas COBOL.
 * No copia archivos: el proceso de importación documental será independiente.
 * Solo aplicar inicialmente en GeI-Desarrollo.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Cada archivo XLS/ODS original se conserva en almacenamiento privado.
        // La clave de origen se obtiene de la ruta RELATIVA original normalizada;
        // el SHA-256 se obtiene del contenido. No confundir ambos identificadores.
        Schema::create('contratos_planillas', function (Blueprint $table): void {
            $table->id();
            $table->char('clave_origen', 64)->unique();
            $table->string('nombre_original');
            $table->text('ruta_origen');
            $table->text('ruta_privada')->nullable();
            $table->char('sha256', 64);
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->string('formato', 12); // xls, xlsx, ods, etc.
            $table->jsonb('metadatos')->nullable();
            $table->timestampTz('importado_at')->nullable();
            $table->timestampsTz();
        });

        // Una fila por hoja y número de fila; las celdas guardan posición y valor.
        // Incluye DETALLE, TOTALES, SELLADOS, AFIP y cualquier otra hoja.
        Schema::create('contratos_planillas_filas', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('planilla_id')->constrained('contratos_planillas')->restrictOnDelete();
            $table->string('nombre_hoja', 100);
            $table->unsignedInteger('numero_fila'); // Fila original (1-indexada)
            $table->jsonb('celdas'); // Valores + índices de columna originales
            $table->jsonb('datos_interpretados')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['planilla_id', 'nombre_hoja', 'numero_fila'],
                'contratos_planillas_filas_ubicacion_unique'
            );
        });

        // Identidad documental separada de `contratos` (operativo COBOL).
        // `numero_documental` NO es UNIQUE: los números históricos se repiten.
        // `clave_origen` proporciona idempotencia independiente del número.
        Schema::create('contratos_expedientes', function (Blueprint $table): void {
            $table->id();
            $table->char('clave_origen', 64)->unique();
            $table->string('numero_documental', 40)->nullable()->index();
            $table->unsignedSmallInteger('anio_archivo')->nullable();
            $table->unsignedTinyInteger('mes_archivo')->nullable();
            $table->unsignedBigInteger('sucursal_id')->nullable()->index();

            $table->date('fecha_celebracion')->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            $table->date('fecha_baja')->nullable();

            // Guardar la lectura original de la planilla y el valor validado.
            $table->string('cuenta_inquilino_planilla', 40)->nullable();
            $table->string('cuenta_inquilino', 11)->nullable()->index();
            $table->string('cuenta_propietario', 11)->nullable()->index();
            $table->string('cuit_propietario', 20)->nullable();
            $table->string('seguro_marca_original', 20)->nullable();
            $table->boolean('seguro_marcado')->default(false);

            // Identificador fiscal independiente del número documental GeI.
            $table->string('reli_cic', 40)->nullable()->index();
            $table->dateTimeTz('reli_registrado_at')->nullable();

            // Todas las columnas originales relevantes al expediente quedan
            // disponibles aun cuando alguna no tenga mapeo a campos tipados.
            $table->jsonb('datos_excel_originales')->nullable();
            $table->jsonb('datos_reli_originales')->nullable();
            $table->text('ruta_carpeta_origen')->nullable();
            // Ej.: 2026/08/4332/11032390603 (11 dígitos completos).
            // NULL hasta conocer la cuenta; no se inventan prefijos COBOL.
            $table->text('ruta_relativa_normalizada')->nullable();

            // Enlaces OPCIONALES, sólo después de validar cuentas, sede e inmueble.
            $table->foreignId('planilla_fila_id')->nullable()
                ->constrained('contratos_planillas_filas')->nullOnDelete();
            $table->foreignId('contrato_id')->nullable()
                ->constrained('contratos')->nullOnDelete();
            $table->foreignId('cliente_cuenta_inquilino_id')->nullable()
                ->constrained('clientes_cuentas')->nullOnDelete();
            $table->foreignId('cliente_cuenta_propietario_id')->nullable()
                ->constrained('clientes_cuentas')->nullOnDelete();
            $table->foreignId('inmueble_id')->nullable()
                ->constrained('inmuebles')->nullOnDelete();

            $table->string('estado_vinculacion', 24)->default('PENDIENTE');
            $table->text('observaciones_revision')->nullable();
            $table->timestampTz('validado_at')->nullable();
            $table->timestampTz('importado_at')->nullable();
            $table->timestampsTz();

            $table->index(['cuenta_inquilino', 'fecha_inicio'], 'c_exp_inq_inicio_idx');
            $table->index(['estado_vinculacion', 'fecha_fin'], 'c_exp_estado_fin_idx');
            $table->index(['anio_archivo', 'mes_archivo', 'numero_documental'], 'c_exp_periodo_num_idx');
        });

        Schema::create('contratos_documentos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('expediente_id')->constrained('contratos_expedientes')->restrictOnDelete();
            $table->char('clave_origen', 64)->unique();
            // CONTRATO, AUT, HONOR, PLANILLA, RELI, ANEXO, OTRO.
            $table->string('tipo', 30)->default('OTRO')->index();
            $table->string('nombre_original');
            $table->text('ruta_origen');
            $table->text('ruta_privada')->nullable();
            $table->char('sha256', 64);
            $table->unsignedBigInteger('tamano_bytes')->nullable();
            $table->string('mime', 120)->nullable();
            $table->unsignedSmallInteger('orden')->default(0);

            // Visibilidad cerrada por defecto; se habilita tras validar el vínculo.
            $table->boolean('visible_inquilino')->default(false);
            $table->boolean('visible_propietario')->default(false);
            $table->string('estado_verificacion', 24)->default('PENDIENTE');
            $table->jsonb('metadatos')->nullable();
            $table->timestampsTz();

            $table->index(['expediente_id', 'tipo'], 'c_docs_expediente_tipo_idx');
            $table->index('sha256', 'c_docs_sha256_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contratos_documentos');
        Schema::dropIfExists('contratos_expedientes');
        Schema::dropIfExists('contratos_planillas_filas');
        Schema::dropIfExists('contratos_planillas');
    }
};
