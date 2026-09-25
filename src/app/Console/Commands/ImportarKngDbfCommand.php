<?php

namespace App\Console\Commands;

use App\Services\KngDbfImportService;
use Illuminate\Console\Command;
use Throwable;

final class ImportarKngDbfCommand extends Command
{
    protected $signature = 'gei:kng-importar-dbf';

    protected $description = 'Importa FACTURAS.DBF y LOTES.DBF del recurso KNG a PostgreSQL.';

    public function handle(KngDbfImportService $service): int
    {
        $estado = $service->estado();
        $this->line('Origen: '.$estado['root']);

        foreach (['facturas', 'lotes'] as $nombre) {
            $archivo = $estado[$nombre];
            $this->line(sprintf(
                '%s: %s',
                strtoupper($nombre),
                $archivo['legible'] ? $archivo['ruta'] : 'NO DISPONIBLE'
            ));
        }

        try {
            $r = $service->importar();
        } catch (Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Importación KNG completada.');
        $this->table(
            ['Tabla', 'Registros', 'Campos DBF'],
            [
                ['FACTURAS', number_format((int) $r['facturas'], 0, ',', '.'), $r['facturas_campos']],
                ['LOTES', number_format((int) $r['lotes'], 0, ',', '.'), $r['lotes_campos']],
            ]
        );

        return self::SUCCESS;
    }
}
