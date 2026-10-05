<?php
namespace App\Console\Commands;
use App\Models\CuentaCaja;
use App\Models\CuentaContable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;
class ImportarPlanContable extends Command
{
    protected $signature = 'gei:importar-plan-contable {--plan=/tmp/PLAN DE CUENTAS CON NROS CONTAB.csv} {--relaciones=/tmp/PLAN DE CUENTAS.csv} {--simular}';
    protected $description = 'Importa el plan contable del contador y vincula las cuentas Caja existentes';
    public function handle(): int
    {
        try { $plan=$this->leerPlan((string)$this->option('plan')); $rel=$this->leerRelaciones((string)$this->option('relaciones')); }
        catch (RuntimeException $e) { $this->error($e->getMessage()); return self::FAILURE; }
        $planSet=collect($plan)->pluck('codigo')->flip(); $cajaSet=CuentaCaja::query()->pluck('codigo_cobol')->flip();
        $sinPlan=collect($rel)->reject(fn($f)=>$planSet->has($f['numero_contable']))->values();
        $sinCaja=collect($rel)->reject(fn($f)=>$cajaSet->has($f['codigo_caja']))->values();
        $this->info('Auditoría del plan contable:');
        $this->line('  Cuentas contables del maestro: '.count($plan));
        $this->line('  Relaciones Caja -> Contabilidad: '.count($rel));
        $this->line('  Relaciones válidas por Nº contable: '.(count($rel)-$sinPlan->count()));
        $this->line('  Relaciones cuyo Nº contable falta en maestro: '.$sinPlan->count());
        $this->line('  Relaciones cuya cuenta Caja no existe en GeI: '.$sinCaja->count());
        if ($sinPlan->isNotEmpty()) $this->warn('Nros. contables faltantes: '.$sinPlan->pluck('numero_contable')->unique()->sort()->implode(', '));
        if ($sinCaja->isNotEmpty()) $this->warn('Cuentas Caja faltantes: '.$sinCaja->pluck('codigo_caja')->unique()->sort()->implode(', '));
        if ($this->option('simular')) { $this->comment('SIMULACIÓN: no se modificó la base de datos.'); return self::SUCCESS; }
        DB::transaction(function() use($plan,$rel): void {
            foreach($plan as $f) {
                $c=CuentaContable::query()->firstOrNew(['codigo'=>$f['codigo']]);
                $c->descripcion=$f['descripcion']; $c->imputable=$f['imputable']; $c->activo=true; $c->origen='CONTADOR_CSV';
                if(!$c->naturaleza_confirmada) $c->naturaleza=$this->inferirNaturaleza($f['codigo']);
                $c->save();
            }
            $ids=CuentaContable::query()->pluck('id','codigo');
            foreach($rel as $f) {
                $cc=CuentaCaja::query()->where('codigo_cobol',$f['codigo_caja'])->first(); if(!$cc) continue;
                $cc->numero_contable=$f['numero_contable']; $cc->cuenta_contable_id=$ids[$f['numero_contable']] ?? null;
                $cc->afecta_disponibilidad=$f['afecta_disponibilidad']; $cc->tipo_disponibilidad=$f['tipo_disponibilidad']; $cc->save();
            }
        });
        $this->info('Importación contable finalizada correctamente.');
        $this->line('Cuentas contables activas: '.CuentaContable::query()->where('activo',true)->count());
        $this->line('Cuentas Caja vinculadas: '.CuentaCaja::query()->whereNotNull('cuenta_contable_id')->count());
        $this->line('Cuentas Caja sin vínculo contable: '.CuentaCaja::query()->whereNull('cuenta_contable_id')->count());
        return self::SUCCESS;
    }
    private function leerPlan(string $ruta): array
    {
        $filas=$this->leerCsv($ruta); $r=[];
        foreach($filas as $i=>$f) { if($i===0) continue; $c=trim((string)($f[0]??'')); $d=trim((string)($f[1]??'')); $imp=strtoupper(trim((string)($f[2]??''))); if(!preg_match('/^\d{10}$/',$c)||$d==='') continue; $r[]=['codigo'=>$c,'descripcion'=>$d,'imputable'=>$imp==='S']; }
        return $r;
    }
    private function leerRelaciones(string $ruta): array
    {
        $filas=$this->leerCsv($ruta); $r=[];
        foreach($filas as $i=>$f) { if($i<2) continue; $raw=trim((string)($f[0]??'')); $n=trim((string)($f[1]??'')); if(!preg_match('/^\d+$/',$raw)||!preg_match('/^\d{10}$/',$n)) continue; $a=strtoupper(trim((string)($f[4]??''))); $t=trim((string)($f[5]??'')); $r[]=['codigo_caja'=>str_pad((string)((int)$raw),4,'0',STR_PAD_LEFT),'numero_contable'=>$n,'afecta_disponibilidad'=>$a===''?null:$a==='S','tipo_disponibilidad'=>$t===''?null:$t]; }
        return $r;
    }
    private function leerCsv(string $ruta): array
    {
        if(!is_file($ruta)||!is_readable($ruta)) throw new RuntimeException('No se puede leer el archivo: '.$ruta);
        $h=fopen($ruta,'rb'); if($h===false) throw new RuntimeException('No se pudo abrir el archivo: '.$ruta); $r=[];
        try { while(($f=fgetcsv($h,0,',','"','\\'))!==false) $r[]=$f; } finally { fclose($h); }
        return $r;
    }
    private function inferirNaturaleza(string $c): ?string
    {
        if(str_starts_with($c,'1')||str_starts_with($c,'2')) return 'DEBE';
        if(str_starts_with($c,'3')||str_starts_with($c,'4')||str_starts_with($c,'5')) return 'HABER';
        if(str_starts_with($c,'601')||str_starts_with($c,'701')||str_starts_with($c,'801')) return 'HABER';
        if(str_starts_with($c,'602')||str_starts_with($c,'702')||str_starts_with($c,'802')) return 'DEBE';
        if(str_starts_with($c,'703')) return 'VARIABLE';
        return null;
    }
}
