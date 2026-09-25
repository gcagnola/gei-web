<?php

namespace App\Console\Commands;

use App\Models\CobolImpresion;
use App\Services\CobolImpresionParser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ReprocesarCobolImpresionesCommand extends Command
{
    protected $signature = 'cobol:reprocesar-impresiones
                            {--all : Reprocesar también registros ya parseados}';

    protected $description = 'Reprocesa archivos RAW COBOL ya almacenados y actualiza su clasificación';

    public function handle(CobolImpresionParser $parser): int
    {
        $query = CobolImpresion::query()->orderBy('id');

        if (! $this->option('all')) {
            $query->where(function ($q): void {
                $q->whereNull('tipo_documento')
                    ->orWhere('tipo_documento', '')
                    ->orWhere('estado', 'DESCONOCIDO');
            });
        }

        $total = 0;
        $parseados = 0;
        $desconocidos = 0;
        $faltantes = 0;

        $query->chunkById(100, function ($impresiones) use ($parser, &$total, &$parseados, &$desconocidos, &$faltantes): void {
            foreach ($impresiones as $impresion) {
                $total++;

                if (! Storage::disk('local')->exists($impresion->archivo_raw)) {
                    $faltantes++;
                    $this->warn("#{$impresion->id}: RAW no encontrado: {$impresion->archivo_raw}");
                    continue;
                }

                $contenido = Storage::disk('local')->get($impresion->archivo_raw);
                $datos = $parser->parsear($contenido);

                $impresion->fill([
                    'tipo_documento' => $datos['tipo_documento'],
                    'cuenta' => $datos['cuenta'],
                    'nombre_cliente' => $datos['nombre_cliente'],
                    'fecha_documento' => $datos['fecha_documento'],
                    'estado' => $datos['estado'],
                ]);
                $impresion->save();

                if ($datos['estado'] === 'PARSEADO') {
                    $parseados++;
                    $this->line("#{$impresion->id} PARSEADO: ".($datos['tipo_documento'] ?? '-').' | '.($datos['cuenta'] ?? '-').' | '.($datos['nombre_cliente'] ?? '-'));
                } else {
                    $desconocidos++;
                    $this->line("#{$impresion->id} DESCONOCIDO: {$impresion->archivo_origen}");
                }
            }
        });

        $this->newLine();
        $this->info("Reprocesados: {$total}");
        $this->info("Parseados: {$parseados}");
        $this->info("Desconocidos: {$desconocidos}");

        if ($faltantes > 0) {
            $this->warn("RAW faltantes: {$faltantes}");
        }

        return self::SUCCESS;
    }
}
