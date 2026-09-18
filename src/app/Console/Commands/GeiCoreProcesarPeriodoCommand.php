<?php

namespace App\Console\Commands;

use App\Services\GeiCoreProcesarPeriodoService;
use Illuminate\Console\Command;
use Throwable;

final class GeiCoreProcesarPeriodoCommand extends Command
{
    protected $signature = 'gei:core-procesar-periodo
                            {periodo : Período COBOL en formato AAAAMM}';

    protected $description =
        'Procesa un período COBOL hacia el modelo definitivo gei_core.';

    public function handle(GeiCoreProcesarPeriodoService $service): int
    {
        $periodo = (string) $this->argument('periodo');

        $this->info("Procesando GeI-Core para {$periodo}...");
        $this->line(
            'Origen: gei_exploracion.cobol_staging · Destino: gei_exploracion.gei_core'
        );
        $this->line('gei_db no se modifica.');

        try {
            $r = $service->procesar($periodo);
        } catch (Throwable $e) {
            $this->newLine();
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Concepto', 'Cantidad / valor'],
            [
                ['Período', $r['periodo']],
                ['Estado', $r['estado']],
                ['Última liquidación detectada', $r['fecha_ultima_liquidacion']],
                ['Archivo PROPIETAR', $r['archivo_propietar']],
                ['Archivo INQUILINO', $r['archivo_inquilino']],
                ['Personas propietarias', number_format($r['personas_propietarias'], 0, ',', '.')],
                ['Cuentas propietario', number_format($r['cuentas_propietario'], 0, ',', '.')],
                ['Personas propietarias activas', number_format($r['personas_propietarias_activas'], 0, ',', '.')],
                ['Cuentas propietario activas', number_format($r['cuentas_propietario_activas'], 0, ',', '.')],
                ['Personas inquilinas', number_format($r['personas_inquilinas'], 0, ',', '.')],
                ['Cuentas inquilino', number_format($r['cuentas_inquilino'], 0, ',', '.')],
                ['Personas inquilinas activas', number_format($r['personas_inquilinas_activas'], 0, ',', '.')],
                ['Cuentas inquilino activas', number_format($r['cuentas_inquilino_activas'], 0, ',', '.')],
                ['Contratos', number_format($r['contratos'], 0, ',', '.')],
                ['Contratos activos', number_format($r['contratos_activos'], 0, ',', '.')],
                ['Inmuebles', number_format($r['inmuebles'], 0, ',', '.')],
                ['Inmuebles activos', number_format($r['inmuebles_activos'], 0, ',', '.')],
                ['Propietarios con contrato activo', number_format($r['cuentas_propietario_con_contrato_activo'], 0, ',', '.')],
                ['Archivo CTACTEPRO', $r['archivo_ctactepro']],
                ['Ctas. corrientes propietario', number_format($r['cuentas_corrientes_propietario'], 0, ',', '.')],
                ['Movimientos CTACTEPRO fuente', number_format($r['movimientos_ctactepro_fuente'], 0, ',', '.')],
                ['Archivo INQCTACTE', $r['archivo_inqctacte']],
                ['Ctas. corrientes inquilino', number_format($r['cuentas_corrientes_inquilino'], 0, ',', '.')],
                ['Movimientos INQCTACTE fuente', number_format($r['movimientos_inqctacte_fuente'], 0, ',', '.')],
                ['Conflictos pendientes', number_format($r['conflictos_pendientes'], 0, ',', '.')],
            ]
        );

        $this->newLine();
        $this->info('Fuentes COBOL del período:');
        $this->table(
            ['Archivo', 'Tabla staging'],
            array_map(
                static fn (array $f): array => [$f['archivo_id'], $f['tabla']],
                $r['fuentes_periodo']
            )
        );

        if ($r['ctacte_detectada'] !== []) {
            $this->newLine();
            $this->info(
                'Fuentes de cuenta corriente detectadas y utilizadas:'
            );

            foreach ($r['ctacte_detectada'] as $f) {
                $this->line(
                    '#'.$f['archivo_id'].' '.$f['tabla'].': '.implode(', ', $f['columnas'])
                );
            }
        } else {
            $this->newLine();
            $this->warn(
                'No se detectaron todavía tablas staging de cuenta corriente en este período.'
            );
        }

        $this->newLine();
        $this->info('Base GeI-Core del período procesada.');

        return self::SUCCESS;
    }
}
