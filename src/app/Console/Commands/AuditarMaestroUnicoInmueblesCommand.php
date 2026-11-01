<?php

namespace App\Console\Commands;

use App\Services\AuditoriaIncrementalInmueblesService;
use Illuminate\Console\Command;
use Throwable;

final class AuditarMaestroUnicoInmueblesCommand extends Command
{
    protected $signature = 'gei:auditar-maestro-inmuebles
        {--desde=202607 : Primer período disponible para construir la base}
        {--hasta=202610 : Último período a comparar}';

    protected $description = 'Simula la identidad física estable de inmuebles por períodos. Solo lectura.';

    public function handle(AuditoriaIncrementalInmueblesService $servicio): int
    {
        $this->warn('MODO SOLO LECTURA: no se crearán, editarán ni unificarán inmuebles.');
        try {
            $resultado = $servicio->ejecutar((string) $this->option('desde'), (string) $this->option('hasta'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->table(
            ['Período', 'Archivo', 'Unidades', 'Nuevas', 'Existentes', 'Nuevas cuentas propietario', 'Sin clave', 'Revisar'],
            array_map(static fn (array $r): array => [
                $r['periodo'], $r['archivo_id'], $r['unidades_en_periodo'],
                $r['unidades_nuevas'], $r['unidades_existentes'], $r['cuentas_propietario_nuevas'],
                $r['registros_sin_identidad_inequivoca'], $r['unidades_a_revisar'],
            ], $resultado['periodos'])
        );
        $this->line('Unidades físicas del plan: '.$resultado['unidades_fisicas_en_plan']);
        $this->line('Con varias cuentas propietarias en su historial: '.$resultado['unidades_con_multiples_cuentas_propietarias']);
        $this->line('Inmuebles canónicos actualmente en public: '.$resultado['inmuebles_canonicos_actuales']);
        foreach ($resultado['periodos'] as $periodo) {
            foreach ($periodo['muestra_revisiones'] as $revision) {
                $this->warn('REVISAR '.$periodo['periodo'].': '.json_encode($revision, JSON_UNESCAPED_UNICODE));
            }
        }
        $this->info('Análisis terminado. Ninguna tabla fue modificada.');

        return self::SUCCESS;
    }
}
