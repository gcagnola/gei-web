<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clientes', function (Blueprint $table): void {
            $table->id();
            $table->string('tipo_persona', 20)->default('DESCONOCIDA');
            $table->string('nombre', 180);
            $table->string('tipo_documento', 20)->nullable();
            $table->string('numero_documento', 30)->nullable();
            $table->string('cuit', 11)->nullable();
            $table->string('condicion_iva', 40)->nullable();
            $table->string('domicilio', 180)->nullable();
            $table->string('codigo_postal', 12)->nullable();
            $table->string('localidad', 120)->nullable();
            $table->string('provincia', 120)->nullable();
            $table->string('telefono', 100)->nullable();
            $table->string('telefono_alternativo', 100)->nullable();
            $table->string('email', 180)->nullable();
            $table->boolean('activo')->default(true);
            $table->timestampsTz();

            $table->index('cuit');
            $table->index(['tipo_documento', 'numero_documento'], 'clientes_documento_index');
            $table->index('nombre');
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 80);
            $table->text('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestampsTz();
        });

        Schema::create('clientes_roles', function (Blueprint $table): void {
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->foreignId('rol_id')->constrained('roles')->restrictOnDelete();
            $table->timestampsTz();

            $table->primary(['cliente_id', 'rol_id']);
            $table->index('rol_id');
        });

        Schema::create('clientes_origenes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')->constrained('clientes')->cascadeOnDelete();
            $table->string('sistema_origen', 30)->default('COBOL');
            $table->string('entidad_origen', 30);
            $table->string('clave_origen', 30);
            $table->unsignedBigInteger('archivo_origen_id')->nullable();
            $table->unsignedBigInteger('numero_linea')->nullable();
            $table->char('hash_origen', 64)->nullable();
            $table->jsonb('datos_origen')->nullable();
            $table->timestampTz('ultimo_importado_at')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['sistema_origen', 'entidad_origen', 'clave_origen'],
                'clientes_origenes_clave_unique'
            );
            $table->index('cliente_id');
        });

        Schema::create('clientes_migracion_conflictos', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->nullOnDelete();
            $table->string('sistema_origen', 30)->default('COBOL');
            $table->string('entidad_origen', 30);
            $table->string('clave_origen', 30);
            $table->string('campo', 50);
            $table->text('valor_actual')->nullable();
            $table->text('valor_origen')->nullable();
            $table->char('firma', 64)->unique();
            $table->string('estado', 20)->default('PENDIENTE');
            $table->jsonb('detalle')->nullable();
            $table->timestampsTz();

            $table->index(['estado', 'entidad_origen']);
        });

        $ahora = now();
        DB::table('roles')->insert([
            [
                'codigo' => 'PROPIETARIO',
                'nombre' => 'Propietario',
                'descripcion' => 'Cliente propietario de uno o más inmuebles.',
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'codigo' => 'INQUILINO',
                'nombre' => 'Inquilino',
                'descripcion' => 'Cliente vinculado como inquilino.',
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'codigo' => 'GARANTE',
                'nombre' => 'Garante',
                'descripcion' => 'Cliente vinculado como garante.',
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'codigo' => 'PROVEEDOR',
                'nombre' => 'Proveedor',
                'descripcion' => 'Cliente que también actúa como proveedor.',
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
            [
                'codigo' => 'OTRO',
                'nombre' => 'Otro',
                'descripcion' => 'Otro rol comercial.',
                'activo' => true,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('clientes_migracion_conflictos');
        Schema::dropIfExists('clientes_origenes');
        Schema::dropIfExists('clientes_roles');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('clientes');
    }
};
