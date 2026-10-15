<?php

namespace App\Console\Commands;

use App\Services\KngPdfSyncService;
use Illuminate\Console\Command;
use Throwable;

final class SincronizarKngPdfsCommand extends Command
{
    protected $signature = 'gei:kng-sincronizar-pdfs {--todos : Recuperar los PDF de todas las facturas históricas}';
    protected $description = 'Copia PDF KNG a sus lotes y recupera archivos pendientes, sin modificar los originales.';

    public function handle(KngPdfSyncService $service): int
    {
        @set_time_limit(0);
        try {
            $resultado = $service->sincronizar([], (bool) $this->option('todos'));
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
        foreach ($resultado as $nombre => $cantidad) {
            $this->line("{$nombre}: {$cantidad}");
        }
        return self::SUCCESS;
    }
}
