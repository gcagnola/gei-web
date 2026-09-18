<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class VincularPdfsFacturacionHistorica extends Command
{
    protected $signature = 'gei:facturacion-vincular-pdfs
                            {--periodo=202607}
                            {--aplicar : Guarda pdf_ruta en facturas}';

    protected $description =
        'Vincula facturas históricas con PDFs ordenados en lote_<nro>, sin depender de carpetas AAAA/MM';

    public function handle(): int
    {
        $periodo = trim((string) $this->option('periodo'));

        if (! preg_match('/^(19|20)\d{4}$/', $periodo)) {
            $this->error("Período inválido: {$periodo}");
            return self::FAILURE;
        }

        $facturas = DB::table('facturas as f')
            ->join('facturaciones as fg', 'fg.id_facturacion', '=', 'f.facturacion_id')
            ->leftJoin('puntos_venta as pv', 'pv.id_punto_venta', '=', 'f.punto_venta_id')
            ->where('fg.periodo', $periodo)
            ->whereNotNull('fg.lote_origen')
            ->select([
                'f.id_factura',
                'f.tipo_comprobante',
                'f.numero_comprobante',
                'f.pdf_ruta',
                'fg.lote_origen',
                'pv.numero as punto_venta',
            ])
            ->orderBy('fg.lote_origen')
            ->orderBy('f.id_factura')
            ->get();

        if ($facturas->isEmpty()) {
            $this->warn("No hay facturas históricas para {$periodo} con lote_origen.");
            return self::SUCCESS;
        }

        $porLote = $facturas->groupBy('lote_origen');

        $encontradas = 0;
        $faltantes = 0;
        $ambiguas = 0;
        $yaVinculadas = 0;
        $actualizaciones = [];

        foreach ($porLote as $lote => $facturasLote) {
            $dir = "lote_{$lote}";

            if (! Storage::disk('arca_facturas')->exists($dir)) {
                $this->warn("No existe {$dir}");
                $faltantes += $facturasLote->count();
                continue;
            }

            $archivos = collect(Storage::disk('arca_facturas')->files($dir))
                ->filter(fn (string $ruta): bool => str_ends_with(strtolower($ruta), '.pdf'))
                ->values();

            // Índice por PV + número, independientemente de prefijo/cuenta.
            $indice = [];

            foreach ($archivos as $ruta) {
                $nombre = basename($ruta);

                if (! preg_match(
                    '/^[A-Za-z]+-(\d+)-(\d+)-\d+\.pdf$/i',
                    $nombre,
                    $m
                )) {
                    continue;
                }

                $clave = ((int) $m[1]).'|'.((int) $m[2]);
                $indice[$clave][] = $ruta;
            }

            foreach ($facturasLote as $factura) {
                if (! empty($factura->pdf_ruta)
                    && Storage::disk('arca_facturas')->exists((string) $factura->pdf_ruta)) {
                    $yaVinculadas++;
                    continue;
                }

                $clave = ((int) $factura->punto_venta).'|'.((int) $factura->numero_comprobante);
                $candidatos = $indice[$clave] ?? [];

                if (count($candidatos) === 1) {
                    $encontradas++;
                    $actualizaciones[(int) $factura->id_factura] = $candidatos[0];
                } elseif (count($candidatos) > 1) {
                    $ambiguas++;
                    $this->warn(
                        "Ambigua factura #{$factura->id_factura}: ".
                        "PV {$factura->punto_venta} Nro {$factura->numero_comprobante} ".
                        "en {$dir} (".count($candidatos)." PDFs)"
                    );
                } else {
                    $faltantes++;
                }
            }
        }

        $this->newLine();
        $this->line("=== VINCULACIÓN PDF {$periodo} ===");
        $this->line('Facturas             : '.$facturas->count());
        $this->line('Ya vinculadas         : '.$yaVinculadas);
        $this->line('PDF encontrados       : '.$encontradas);
        $this->line('PDF faltantes         : '.$faltantes);
        $this->line('Coincidencias ambiguas: '.$ambiguas);

        if (! $this->option('aplicar')) {
            $this->newLine();
            $this->warn('SIMULACIÓN: no se modificó la base.');
            return self::SUCCESS;
        }

        DB::transaction(function () use ($actualizaciones): void {
            foreach ($actualizaciones as $idFactura => $ruta) {
                DB::table('facturas')
                    ->where('id_factura', $idFactura)
                    ->update([
                        'pdf_ruta' => $ruta,
                        'updated_at' => now(),
                    ]);
            }
        });

        $this->newLine();
        $this->info('Vinculaciones guardadas: '.count($actualizaciones));

        return self::SUCCESS;
    }
}
