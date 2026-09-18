<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportarFlagsFacturacionKng extends Command
{
    protected $signature = 'gei:facturacion-importar-flags
                            {--simular : No modifica la base}
                            {--dir=storage/app/private : Directorio de los CSV}';

    protected $description =
        'Importa FACTURABLE/FACTURAR/FACT_IND desde snapshots KNG a cuentas_corrientes';

    public function handle(): int
    {
        $simular = (bool) $this->option('simular');
        $dir = base_path($this->option('dir'));

        $archivoInq = $dir . '/inquilinos_facturacion.csv';
        $archivoProp = $dir . '/propietarios_facturacion.csv';

        foreach ([$archivoInq, $archivoProp] as $archivo) {
            if (!is_file($archivo)) {
                $this->error("No existe: {$archivo}");
                return self::FAILURE;
            }
        }

        $this->info($simular ? 'MODO SIMULACION' : 'MODO APLICACION');

        $totales = [
            'INQUILINO' => $this->procesarInquilinos($archivoInq, $simular),
            'PROPIETARIO' => $this->procesarPropietarios($archivoProp, $simular),
        ];

        $this->newLine();

        foreach ($totales as $dominio => $t) {
            $this->line("=== {$dominio} ===");
            $this->line("CSV                    : {$t['csv']}");
            $this->line("Encontradas PostgreSQL : {$t['encontradas']}");
            $this->line("No encontradas         : {$t['no_encontradas']}");
            $this->line("A actualizar           : {$t['a_actualizar']}");
            $this->line("Sin cambios            : {$t['sin_cambios']}");

            if ($dominio === 'PROPIETARIO') {
                $this->line("Individual             : {$t['individual']}");
                $this->line("Normal                 : {$t['normal']}");
            }

            if (!empty($t['ejemplos_no_encontradas'])) {
                $this->warn('Ejemplos no encontradas:');
                foreach ($t['ejemplos_no_encontradas'] as $cuenta) {
                    $this->line("  - {$cuenta}");
                }
            }

            $this->newLine();
        }

        if ($simular) {
            $this->warn('No se modificó la base.');
        } else {
            $this->info('Importación aplicada.');
        }

        return self::SUCCESS;
    }

    private function procesarInquilinos(string $archivo, bool $simular): array
    {
        $stats = $this->statsBase();

        $fh = fopen($archivo, 'r');
        $header = fgetcsv($fh);

        while (($fila = fgetcsv($fh)) !== false) {
            $stats['csv']++;

            $r = array_combine($header, $fila);

            $cuenta = trim((string) $r['cuenta']);
            $facturable = $this->boolCsv($r['facturable']);

            $cc = DB::table('cuentas_corrientes')
                ->where('dominio', 'INQUILINO')
                ->where('cuenta', $cuenta)
                ->first();

            if (!$cc) {
                $stats['no_encontradas']++;
                $this->agregarEjemplo($stats, $cuenta);
                continue;
            }

            $stats['encontradas']++;

            $cambia =
                $cc->facturable !== $facturable
                || $cc->modalidad_facturacion !== 'NORMAL';

            if ($cambia) {
                $stats['a_actualizar']++;

                if (!$simular) {
                    DB::table('cuentas_corrientes')
                        ->where('id', $cc->id)
                        ->update([
                            'facturable' => $facturable,
                            'modalidad_facturacion' => 'NORMAL',
                            'updated_at' => now(),
                        ]);
                }
            } else {
                $stats['sin_cambios']++;
            }
        }

        fclose($fh);

        return $stats;
    }

    private function procesarPropietarios(string $archivo, bool $simular): array
    {
        $stats = $this->statsBase();
        $stats['individual'] = 0;
        $stats['normal'] = 0;

        $fh = fopen($archivo, 'r');
        $header = fgetcsv($fh);

        while (($fila = fgetcsv($fh)) !== false) {
            $stats['csv']++;

            $r = array_combine($header, $fila);

            $cuenta = trim((string) $r['cuenta']);
            $facturar = $this->boolCsv($r['facturar']);
            $factInd = $this->boolCsv($r['fact_ind']);

            $modalidad = $factInd === true
                ? 'INDIVIDUAL'
                : 'NORMAL';

            if ($modalidad === 'INDIVIDUAL') {
                $stats['individual']++;
            } else {
                $stats['normal']++;
            }

            $cc = DB::table('cuentas_corrientes')
                ->where('dominio', 'PROPIETARIO')
                ->where('cuenta', $cuenta)
                ->first();

            if (!$cc) {
                $stats['no_encontradas']++;
                $this->agregarEjemplo($stats, $cuenta);
                continue;
            }

            $stats['encontradas']++;

            $cambia =
                $cc->facturable !== $facturar
                || $cc->modalidad_facturacion !== $modalidad;

            if ($cambia) {
                $stats['a_actualizar']++;

                if (!$simular) {
                    DB::table('cuentas_corrientes')
                        ->where('id', $cc->id)
                        ->update([
                            'facturable' => $facturar,
                            'modalidad_facturacion' => $modalidad,
                            'updated_at' => now(),
                        ]);
                }
            } else {
                $stats['sin_cambios']++;
            }
        }

        fclose($fh);

        return $stats;
    }

    private function boolCsv(mixed $valor): ?bool
    {
        $v = strtolower(trim((string) $valor));

        if (in_array($v, ['true', 't', '1', 'yes', 'si', 'sí'], true)) {
            return true;
        }

        if (in_array($v, ['false', 'f', '0', 'no'], true)) {
            return false;
        }

        return null;
    }

    private function statsBase(): array
    {
        return [
            'csv' => 0,
            'encontradas' => 0,
            'no_encontradas' => 0,
            'a_actualizar' => 0,
            'sin_cambios' => 0,
            'ejemplos_no_encontradas' => [],
        ];
    }

    private function agregarEjemplo(array &$stats, string $cuenta): void
    {
        if (count($stats['ejemplos_no_encontradas']) < 20) {
            $stats['ejemplos_no_encontradas'][] = $cuenta;
        }
    }
}
