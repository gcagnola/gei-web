<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

final class SincronizarPorcentajesPropietariosCommand extends Command
{
    protected $signature = 'gei:sincronizar-porcentajes-propietarios
        {--periodo= : Opción conservada por compatibilidad}
        {--confirmar : Opción conservada por compatibilidad}
        {--detalles : Opción conservada por compatibilidad}';

    protected $description = 'DESHABILITADO: el reparto de cobro ya no se guarda como porcentaje de titularidad.';

    public function handle(): int
    {
        $this->error('Comando deshabilitado: no modifica ningún dato.');
        $this->line('Usá: gei:sincronizar-repartos-propietarios --periodo=AAAAMM');

        return self::FAILURE;
    }
}
