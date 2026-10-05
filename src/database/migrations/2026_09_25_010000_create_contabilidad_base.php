<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void
    {
        Schema::create('cuentas_contables', function (Blueprint $table): void {
            $table->id(); $table->string('codigo',10)->unique(); $table->string('descripcion',160);
            $table->boolean('imputable')->default(false); $table->string('naturaleza',10)->nullable();
            $table->boolean('naturaleza_confirmada')->default(false); $table->boolean('activo')->default(true);
            $table->string('origen',30)->nullable(); $table->timestamps();
            $table->index(['activo','codigo']); $table->index(['naturaleza','imputable']);
        });
        Schema::table('cuentas_caja', function (Blueprint $table): void {
            $table->foreignId('cuenta_contable_id')->nullable()->after('numero_contable')->constrained('cuentas_contables')->nullOnDelete();
            $table->boolean('afecta_disponibilidad')->nullable()->after('subcuenta');
            $table->string('tipo_disponibilidad',10)->nullable()->after('afecta_disponibilidad');
            $table->index('numero_contable','cuentas_caja_numero_contable_index');
        });
        DB::table('modulos')->updateOrInsert(['codigo'=>'CONTABILIDAD'],[
            'seccion'=>'Contabilidad','nombre'=>'Contabilidad','orden'=>400,'activo'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $contador = DB::table('perfiles')->where('codigo','CONTADOR')->value('id');
        if (!$contador) $contador = DB::table('perfiles')->insertGetId([
            'codigo'=>'CONTADOR','nombre'=>'Contador','descripcion'=>'Acceso exclusivo al módulo Contabilidad.','activo'=>true,'created_at'=>now(),'updated_at'=>now(),
        ]);
        $modulo = DB::table('modulos')->where('codigo','CONTABILIDAD')->value('id');
        if ($contador && $modulo) DB::table('perfiles_modulos')->updateOrInsert(['perfil_id'=>$contador,'modulo_id'=>$modulo],['created_at'=>now(),'updated_at'=>now()]);
        $admin = DB::table('perfiles')->where('codigo','ADMINISTRADOR')->value('id');
        if ($admin && $modulo) DB::table('perfiles_modulos')->updateOrInsert(['perfil_id'=>$admin,'modulo_id'=>$modulo],['created_at'=>now(),'updated_at'=>now()]);
    }
    public function down(): void
    {
        $modulo = DB::table('modulos')->where('codigo','CONTABILIDAD')->value('id');
        $contador = DB::table('perfiles')->where('codigo','CONTADOR')->value('id');
        if ($modulo) { DB::table('perfiles_modulos')->where('modulo_id',$modulo)->delete(); DB::table('modulos')->where('id',$modulo)->delete(); }
        if ($contador && !DB::table('usuarios')->where('perfil_id',$contador)->exists()) { DB::table('perfiles_modulos')->where('perfil_id',$contador)->delete(); DB::table('perfiles')->where('id',$contador)->delete(); }
        Schema::table('cuentas_caja', function (Blueprint $table): void {
            $table->dropIndex('cuentas_caja_numero_contable_index'); $table->dropConstrainedForeignId('cuenta_contable_id'); $table->dropColumn(['afecta_disponibilidad','tipo_disponibilidad']);
        });
        Schema::dropIfExists('cuentas_contables');
    }
};
