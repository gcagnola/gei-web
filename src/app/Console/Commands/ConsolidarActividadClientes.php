<?php

namespace App\Console\Commands;

use App\Services\ActividadConsolidadaClientesService;
use Illuminate\Console\Command;
use Throwable;

class ConsolidarActividadClientes extends Command
{
    protected $signature = 'gei:actividad-clientes {periodo : Período AAAAMM} {--aplicar : Sincroniza todo, exige cero pendientes} {--solo-altas : Activa únicamente positivos identificados, sin bajas}';
    protected $description = 'Analiza actividad por COBOL, ARCA, liquidaciones e impuestos por separado; opcionalmente sincroniza clientes canónicos';

    public function handle(ActividadConsolidadaClientesService $servicio): int
    {
        if ($this->option('aplicar') && $this->option('solo-altas')) {
            $this->error('Elegir --aplicar O --solo-altas; nunca ambas opciones.');
            return self::FAILURE;
        }
        try {
            $resultado = $this->option('aplicar')
                ? $servicio->sincronizar((string) $this->argument('periodo'))
                : ($this->option('solo-altas')
                    ? $servicio->sincronizar((string) $this->argument('periodo'), true)
                    : $servicio->analizar((string) $this->argument('periodo')));
            $this->line(json_encode($resultado, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            if (($resultado['pendientes'] ?? 0) > 0 && ! $this->option('solo-altas')) {
                $this->warn('Hay referencias por revisar. No ejecutar la sincronización completa todavía.');
            }
            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
