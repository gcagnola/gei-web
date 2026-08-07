<?php

namespace App\Console\Commands;

use App\Services\LiquidacionesPropietariosService;
use Illuminate\Console\Command;
use Throwable;

final class ProcesarLiquidacionesPropietariosCommand extends Command
{
    protected $signature = 'gei:procesar-liquidaciones-propietarios
        {periodo : Período AAAAMM previamente cargado}
        {--numero-inicial= : Primer número interno, usado únicamente si la tabla está vacía}
        {--confirmar : Confirma la escritura en tablas y la generación de PDF}';

    protected $description = 'Guarda liquidaciones de propietarios e ítems y genera PDF desde PostgreSQL.';

    public function handle(LiquidacionesPropietariosService $service): int
    {
        if (! $this->option('confirmar')) {
            $this->warn('No se escribió nada. Repetí el comando con --confirmar.');

            return self::SUCCESS;
        }

        try {
            $numeroInicial = $this->option('numero-inicial');
            $resultado = $service->procesar(
                (string) $this->argument('periodo'),
                $numeroInicial === null ? null : (int) $numeroInicial
            );
        } catch (Throwable $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode(
            $resultado,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));

        return self::SUCCESS;
    }
}
