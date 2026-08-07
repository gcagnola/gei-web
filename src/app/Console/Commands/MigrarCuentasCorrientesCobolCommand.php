<?php

namespace App\Console\Commands;

use App\Services\MigracionCuentasCorrientesCobolService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class MigrarCuentasCorrientesCobolCommand extends Command
{
    protected $signature = 'gei:migrar-cuentas-corrientes-cobol
        {--confirmar : Escribe los cambios en gei_db}
        {--reiniciar : Elimina y reconstruye solamente las tres tablas nuevas de Cuenta Corriente; requiere --confirmar}
        {--limite= : Limita cada fuente para una prueba controlada}
        {--detalles : Muestra incidencias y genera un CSV de revisión}';

    protected $description = 'Importa la fotografía más reciente de CTACTEPRO e INQCTACTE de forma idempotente.';

    public function handle(MigracionCuentasCorrientesCobolService $service): int
    {
        $confirmar = (bool) $this->option('confirmar');
        $reiniciar = (bool) $this->option('reiniciar');
        $limite = $this->option('limite');
        $limite = $limite === null ? null : max(0, (int) $limite);
        $detalles = (bool) $this->option('detalles');
        $incidencias = [];

        $this->warn($confirmar
            ? ($reiniciar
                ? 'MODO REINICIAR: se eliminará y reconstruirá solamente Cuenta Corriente en gei_db.'
                : 'MODO CONFIRMAR: se escribirán datos en gei_db.')
            : 'MODO SIMULACIÓN: no se escribirá ningún dato.'
        );

        try {
            $resultado = $service->ejecutar(
                $confirmar,
                $limite,
                function (string $fuente, int $procesados, int $total): void {
                    $this->line("{$fuente}: {$procesados}/{$total}");
                },
                $detalles
                    ? function (array $incidencia) use (&$incidencias): void {
                        $incidencias[] = $incidencia;
                    }
                    : null,
                $reiniciar
            );
        } catch (Throwable $error) {
            $this->newLine();
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Resultado', 'Cantidad'],
            collect($resultado)
                ->map(fn (int|bool $valor, string $clave): array => [
                    str_replace('_', ' ', ucfirst($clave)),
                    is_bool($valor) ? ($valor ? 'Sí' : 'No') : number_format($valor, 0, ',', '.'),
                ])
                ->values()
                ->all()
        );

        if ($detalles) {
            $this->mostrarDetalles($incidencias);
        }
        if (! $confirmar) {
            $this->info('Simulación terminada. Para escribir, repetí con --confirmar.');
        }

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $incidencias */
    private function mostrarDetalles(array $incidencias): void
    {
        $this->newLine();
        $this->components->info('Muestra de incidencias de cuentas corrientes (primeras 20)');

        if ($incidencias === []) {
            $this->line('No hubo incidencias.');
        } else {
            $this->table(
                ['Tipo', 'Motivo', 'Dominio', 'Cuenta', 'Detalle'],
                array_map(fn (array $fila): array => [
                    $fila['tipo'] ?? '',
                    $fila['motivo'] ?? '',
                    $fila['dominio'] ?? '',
                    $fila['cuenta'] ?? '',
                    json_encode($fila['detalle'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ], array_slice($incidencias, 0, 20))
            );
        }

        $ruta = $this->guardarCsv($incidencias);
        $this->newLine();
        $this->info('CSV completo: '.$ruta);
        $this->line('Incluye '.number_format(count($incidencias), 0, ',', '.').' incidencias.');
    }

    /** @param list<array<string, mixed>> $incidencias */
    private function guardarCsv(array $incidencias): string
    {
        $directorio = storage_path('app/reportes');
        File::ensureDirectoryExists($directorio);
        $ruta = $directorio.'/cuentas_corrientes_cobol_detalles_'.now()->format('Ymd_His').'.csv';
        $archivo = fopen($ruta, 'wb');
        if ($archivo === false) {
            throw new RuntimeException('No se pudo crear el informe '.$ruta);
        }

        try {
            fwrite($archivo, "\xEF\xBB\xBF");
            fputcsv($archivo, ['tipo', 'motivo', 'bloqueante', 'dominio', 'cuenta', 'detalle'], ';', '"', '');
            foreach ($incidencias as $fila) {
                fputcsv($archivo, [
                    $fila['tipo'] ?? '',
                    $fila['motivo'] ?? '',
                    ! empty($fila['bloqueante']) ? 'SI' : 'NO',
                    $fila['dominio'] ?? '',
                    $fila['cuenta'] ?? '',
                    json_encode($fila['detalle'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ], ';', '"', '');
            }
        } finally {
            fclose($archivo);
        }

        return $ruta;
    }
}
