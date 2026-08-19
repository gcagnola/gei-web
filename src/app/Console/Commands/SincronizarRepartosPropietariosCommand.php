<?php

namespace App\Console\Commands;

use App\Services\SincronizacionRepartosPropietariosService;
use Illuminate\Console\Command;
use Throwable;

final class SincronizarRepartosPropietariosCommand extends Command
{
    protected $signature = 'gei:sincronizar-repartos-propietarios
        {--periodo= : Período AAAAMM; por defecto usa el último importado}
        {--confirmar : Escribe el maestro vigente de repartos}
        {--detalles : Muestra las primeras incidencias}';

    protected $description = 'Sincroniza el reparto de cobro vigente desde las liquidaciones de propietarios importadas.';

    public function handle(SincronizacionRepartosPropietariosService $service): int
    {
        $confirmar = (bool) $this->option('confirmar');
        $periodo = trim((string) ($this->option('periodo') ?? '')) ?: null;
        $detalles = (bool) $this->option('detalles');
        $incidencias = [];

        $this->warn($confirmar
            ? 'MODO CONFIRMAR: se escribirá repartos_propietarios. No se modifica inmuebles_propietarios.'
            : 'MODO SIMULACIÓN: no se modificará la base.'
        );

        try {
            $resultado = $service->sincronizar(
                $periodo,
                $confirmar,
                $detalles
                    ? function (array $incidencia) use (&$incidencias): void {
                        $incidencias[] = $incidencia;
                    }
                    : null
            );
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Resultado', 'Valor'],
            [
                ['Período', (string) $resultado['periodo']],
                ['Liquidaciones fuente', number_format((int) $resultado['liquidaciones_fuente'], 0, ',', '.')],
                ['Cuentas fuente', number_format((int) $resultado['cuentas_fuente'], 0, ',', '.')],
                ['Cuentas válidas', number_format((int) $resultado['cuentas_validas'], 0, ',', '.')],
                ['Cuentas omitidas', number_format((int) $resultado['cuentas_omitidas'], 0, ',', '.')],
                ['Cuentas más antiguas omitidas', number_format((int) $resultado['cuentas_historicas_omitidas'], 0, ',', '.')],
                ['Beneficiarios válidos', number_format((int) $resultado['beneficiarios_fuente'], 0, ',', '.')],
                ['Repartos a crear / creados', number_format((int) $resultado['repartos_creados'], 0, ',', '.')],
                ['Repartos a actualizar / actualizados', number_format((int) $resultado['repartos_actualizados'], 0, ',', '.')],
                ['Repartos a desactivar / desactivados', number_format((int) $resultado['repartos_desactivados'], 0, ',', '.')],
                ['Repartos sin cambios', number_format((int) $resultado['repartos_sin_cambios'], 0, ',', '.')],
                ['Incidencias', number_format((int) $resultado['incidencias'], 0, ',', '.')],
            ]
        );

        if ($detalles && $incidencias !== []) {
            $this->newLine();
            $this->components->info('Primeras incidencias');
            $this->table(
                ['Tipo', 'Cuenta', 'Beneficiario', 'Detalle'],
                array_map(
                    fn (array $fila): array => [
                        (string) ($fila['tipo'] ?? ''),
                        (string) ($fila['cuenta'] ?? ''),
                        (string) ($fila['beneficiario'] ?? ''),
                        (string) ($fila['detalle'] ?? ''),
                    ],
                    array_slice($incidencias, 0, 50)
                )
            );
        }

        if (! $confirmar) {
            $this->newLine();
            $this->info('Simulación terminada. Si el resultado es correcto, repetí con --confirmar.');
        }

        return self::SUCCESS;
    }
}
