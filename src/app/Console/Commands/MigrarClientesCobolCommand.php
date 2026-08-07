<?php

namespace App\Console\Commands;

use App\Services\MigracionClientesCobolService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class MigrarClientesCobolCommand extends Command
{
    protected $signature = 'gei:migrar-clientes-cobol
        {--confirmar : Escribe los cambios en gei_db}
        {--limite= : Limita cada fuente para una prueba controlada}
        {--detalles : Muestra conflictos y genera un CSV de revisión}';

    protected $description = 'Migra clientes, roles y cuentas COBOL desde gei_exploracion.';

    public function handle(MigracionClientesCobolService $service): int
    {
        $confirmar = (bool) $this->option('confirmar');
        $limite = $this->option('limite');
        $limite = $limite === null ? null : max(0, (int) $limite);
        $detalles = (bool) $this->option('detalles');
        $incidencias = [];

        $this->warn($confirmar
            ? 'MODO CONFIRMAR: se escribirán datos en gei_db.'
            : 'MODO SIMULACIÓN: no se escribirá ningún dato.'
        );

        try {
            $resultado = $service->ejecutar(
                $confirmar,
                $limite,
                function (string $fuente, int $procesados, int $total): void {
                    if ($procesados === 1 || $procesados % 1000 === 0 || $procesados === $total) {
                        $this->line("{$fuente}: {$procesados}/{$total}");
                    }
                },
                $detalles
                    ? function (array $incidencia) use (&$incidencias): void {
                        $incidencias[] = $incidencia;
                    }
                    : null
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

    /**
     * @param list<array<string, mixed>> $incidencias
     */
    private function mostrarDetalles(array $incidencias): void
    {
        $conflictos = array_values(array_filter(
            $incidencias,
            fn (array $fila): bool => $fila['tipo'] === 'CONFLICTO'
        ));
        $diferenciasTributarias = array_values(array_filter(
            $incidencias,
            fn (array $fila): bool => $fila['tipo'] === 'DIFERENCIA_TRIBUTARIA'
        ));

        $this->newLine();
        $this->components->info('Muestra de cuentas enviadas a clientes_conflictos (primeras 20)');
        if ($conflictos === []) {
            $this->line('No hubo conflictos.');
        } else {
            $this->table(
                ['Fuente', 'Cuenta', 'Motivo', 'Candidatos', 'Nombre COBOL'],
                array_map(fn (array $fila): array => [
                    $fila['entidad'],
                    $fila['cuenta'],
                    $fila['motivo'],
                    $this->texto($fila['valor_actual'] ?? null),
                    $this->texto($fila['valor_origen'] ?? null),
                ], array_slice($conflictos, 0, 20))
            );
        }

        $ruta = $this->guardarCsv($incidencias);
        $this->newLine();
        $this->info('CSV completo: '.$ruta);
        $this->line(
            'Incluye '
            .number_format(count($conflictos), 0, ',', '.')
            .' conflictos de identidad y '
            .number_format(count($diferenciasTributarias), 0, ',', '.')
            .' diferencias tributarias.'
        );
    }

    /**
     * @param list<array<string, mixed>> $incidencias
     */
    private function guardarCsv(array $incidencias): string
    {
        $directorio = storage_path('app/reportes');
        File::ensureDirectoryExists($directorio);
        $ruta = $directorio.'/clientes_cobol_detalles_'.now()->format('Ymd_His').'.csv';
        $archivo = fopen($ruta, 'wb');

        if ($archivo === false) {
            throw new RuntimeException('No se pudo crear el informe '.$ruta);
        }

        try {
            fwrite($archivo, "\xEF\xBB\xBF");
            fputcsv($archivo, [
                'tipo',
                'motivo',
                'fuente',
                'cuenta',
                'cliente_id',
                'campo',
                'valor_actual',
                'valor_origen',
                'detalle',
            ], ';', '"', '');

            foreach ($incidencias as $fila) {
                fputcsv($archivo, [
                    $fila['tipo'],
                    $fila['motivo'],
                    $fila['entidad'],
                    $fila['cuenta'],
                    $fila['cliente_id'] ?? '',
                    $fila['campo'] ?? '',
                    $this->texto($fila['valor_actual'] ?? null),
                    $this->texto($fila['valor_origen'] ?? null),
                    json_encode(
                        $fila['detalle'] ?? [],
                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    ),
                ], ';', '"', '');
            }
        } finally {
            fclose($archivo);
        }

        return $ruta;
    }

    private function texto(mixed $valor): string
    {
        if (is_array($valor) || is_object($valor)) {
            return (string) json_encode(
                $valor,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            );
        }

        return (string) ($valor ?? '');
    }
}
