<?php

namespace App\Console\Commands;

use App\Services\ActividadOperativaCuentasPeriodoService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Materializa la actividad del mes en gei_db: nunca modifica el estado
 * administrativo ni autoriza usuarios de Autogestión.
 * Ejecutar después de importar/actualizar el período (incluidas sus facturas).
 */
final class ActualizarActividadCuentasPeriodoCommand extends Command
{
    protected $signature = 'gei:actualizar-actividad-cuentas
        {periodo : Período AAAAMM}
        {--confirmar : Escribe la clasificación en gei_db}';

    protected $description = 'Calcula una vez la actividad por cuenta, dominio y período y la guarda para consultas rápidas';

    public function handle(ActividadOperativaCuentasPeriodoService $calculador): int
    {
        $periodo = (string) $this->argument('periodo');
        if (preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            $this->error('El período debe tener formato AAAAMM.');
            return self::FAILURE;
        }
        if (! DB::getSchemaBuilder()->hasTable('cuentas_actividad_periodos_control')
            || ! DB::getSchemaBuilder()->hasTable('cuentas_actividad_periodos')) {
            $this->error('Falta ejecutar la migración de actividad por período.');
            return self::FAILURE;
        }

        $this->line("Calculando las cinco fuentes para {$periodo} (proceso fuera de HTTP)...");
        $actividad = $calculador->cuentas($periodo);
        $filas = [];
        $vistos = ['PROPIETARIO' => [], 'INQUILINO' => []];
        $ahora = now();
        $total = 0;
        $conteo = ['PROPIETARIO' => 0, 'INQUILINO' => 0];

        $cuentas = DB::table('cuentas_corrientes')
            ->whereIn('dominio', ['PROPIETARIO', 'INQUILINO'])
            ->select('dominio', 'cuenta')
            ->distinct()
            ->orderBy('dominio')->orderBy('cuenta')->cursor();

        foreach ($cuentas as $fila) {
            $dominio = (string) $fila->dominio;
            $cuenta = (string) $fila->cuenta;
            $normalizada = ActividadOperativaCuentasPeriodoService::normalizarCuenta($cuenta);
            $tiene = isset($actividad[$dominio][$normalizada]);
            if ($tiene) {
                $vistos[$dominio][$normalizada] = true;
                $conteo[$dominio]++;
            }
            $filas[] = [
                'periodo' => $periodo,
                'dominio' => $dominio,
                'cuenta' => $cuenta,
                'con_actividad' => $tiene,
                'calculado_en' => $ahora,
            ];
            $total++;
        }

        // Retener evidencia de cuentas todavía ausentes de cuentas_corrientes.
        // No aparecen en el listado hasta completar su importación, pero
        // tampoco se pierde la evidencia de actividad del mes.
        $sinCuenta = [];
        foreach (['PROPIETARIO', 'INQUILINO'] as $dominio) {
            $diferencia = array_diff_key($actividad[$dominio], $vistos[$dominio]);
            foreach (array_keys($diferencia) as $cuenta) {
                $sinCuenta[] = $dominio.':'.$cuenta;
                $filas[] = [
                    'periodo' => $periodo,
                    'dominio' => $dominio,
                    'cuenta' => (string) $cuenta,
                    'con_actividad' => true,
                    'calculado_en' => $ahora,
                ];
                $conteo[$dominio]++;
                $total++;
            }
        }

        $this->table(['Dominio', 'Con actividad'], [
            ['PROPIETARIO', $conteo['PROPIETARIO']],
            ['INQUILINO', $conteo['INQUILINO']],
        ]);
        $this->line("Filas de actividad por cuenta/período: {$total}");

        if ($sinCuenta !== []) {
            $this->warn('Atención: '.count($sinCuenta).' cuentas tienen actividad pero no existen en cuentas_corrientes.');
            $this->line('Conservadas en la tabla por período para revisión: '.implode(', ', array_slice($sinCuenta, 0, 25)));
        }
        if ($total === 0) {
            $this->error('No se graba: no hay cuentas operativas.');
            return self::FAILURE;
        }
        if (! $this->option('confirmar')) {
            $this->warn('Simulación: no se modificó PostgreSQL. Repetir con --confirmar para grabar.');
            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($periodo, $filas, $conteo, $total, $ahora): void {
                DB::table('cuentas_actividad_periodos')->where('periodo', $periodo)->delete();
                foreach (array_chunk($filas, 500) as $lote) {
                    DB::table('cuentas_actividad_periodos')->insert($lote);
                }
                DB::table('cuentas_actividad_periodos_control')->updateOrInsert(
                    ['periodo' => $periodo],
                    [
                        'calculado_en' => $ahora,
                        'total_cuentas' => $total,
                        'propietarios_activos' => $conteo['PROPIETARIO'],
                        'inquilinos_activos' => $conteo['INQUILINO'],
                    ]
                );
            }, 3);
        } catch (Throwable $e) {
            $this->error('No se grabó la clasificación: '.$e->getMessage());
            return self::FAILURE;
        }
        $this->info("OK: actividad {$periodo} materializada en gei_db. No se tocaron campos activo.");
        return self::SUCCESS;
    }
}
