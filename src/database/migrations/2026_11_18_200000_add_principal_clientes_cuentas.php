<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('clientes_cuentas', fn(Blueprint $t) => $t->boolean('principal')->default(false));
        DB::statement('CREATE UNIQUE INDEX clientes_cuentas_principal_unique ON clientes_cuentas (cliente_id) WHERE principal');
    }
    public function down(): void {
        DB::statement('DROP INDEX clientes_cuentas_principal_unique');
        Schema::table('clientes_cuentas', fn(Blueprint $t) => $t->dropColumn('principal'));
    }
};
