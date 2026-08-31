<?php

namespace App\Console\Commands;

use App\Services\MigracionInmueblesCobolService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Throwable;

final class MigrarInmueblesCobolCommand extends Command
{
    protected $signature = 'gei:migrar-inmuebles-cobol
        {--confirmar : Escribe los cambios en gei_db}
        {--limite= : Limita la fuente para una prueba controlada}
        {--detalles : Muestra conflictos y genera un CSV de revisión}
        {--auditar-actualizaciones : Detalla qué campos cambiarían en los inmuebles marcados como actualizados}';

    protected $description = 'Reconstruye inmuebles y propietarios desde INQUILINO en gei_exploracion.';

    public function handle(MigracionInmueblesCobolService $service): int
    {
        $confirmar = (bool) $this->option('confirmar');
        $limite = $this->option('limite');
        $limite = $limite === null ? null : max(0, (int) $limite);
        $detalles = (bool) $this->option('detalles');
        $auditarActualizaciones = (bool) $this->option('auditar-actualizaciones');
        $incidencias = [];
        $actualizaciones = [];

        $this->warn($confirmar
            ? 'MODO CONFIRMAR: se escribirán datos en gei_db.'
            : 'MODO SIMULACIÓN: no se escribirá ningún dato.'
        );

        try {
            $resultado = $service->ejecutar(
                $confirmar,
                $limite,
                function (int $procesados, int $total): void {
                    $this->line("INQUILINO: {$procesados}/{$total}");
                },
                $detalles
                    ? function (array $incidencia) use (&$incidencias): void {
                        $incidencias[] = $incidencia;
                    }
                    : null,
                $auditarActualizaciones
                    ? function (array $actualizacion) use (&$actualizaciones): void {
                        $actualizaciones[] = $actualizacion;
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

        if ($auditarActualizaciones) {
            $this->mostrarActualizaciones($actualizaciones);
        }

        if (! $confirmar) {
            $this->info('Simulación terminada. Para escribir, repetí con --confirmar.');
        }

        return self::SUCCESS;
    }

    /** @param list<array<string, mixed>> $actualizaciones */
    private function mostrarActualizaciones(array $actualizaciones): void
    {
        $this->newLine();
        $this->components->info('Auditoría de inmuebles que serían actualizados');

        if ($actualizaciones === []) {
            $this->line('No hay inmuebles con cambios de datos maestros/estado.');
            return;
        }

        $porCampo = [];
        foreach ($actualizaciones as $fila) {
            foreach (array_keys($fila['cambios'] ?? []) as $campo) {
                $porCampo[$campo] = ($porCampo[$campo] ?? 0) + 1;
            }
        }
        arsort($porCampo);

        $this->table(
            ['Campo que cambiaría', 'Inmuebles'],
            array_map(
                fn (string $campo, int $cantidad): array => [$campo, number_format($cantidad, 0, ',', '.')],
                array_keys($porCampo),
                array_values($porCampo)
            )
        );

        $this->newLine();
        $this->line('Muestra de los primeros 20:');
        $this->table(
            ['ID', 'Cta. inquilino', 'Cta. propietario', 'Campos', 'Dirección fuente'],
            array_map(fn (array $fila): array => [
                $fila['inmueble_id'] ?? '',
                $fila['cuenta_inquilino'] ?? '',
                $fila['cuenta_propietario'] ?? '',
                implode(', ', array_keys($fila['cambios'] ?? [])),
                $fila['direccion_fuente'] ?? '',
            ], array_slice($actualizaciones, 0, 20))
        );

        $ruta = $this->guardarCsvActualizaciones($actualizaciones);
        $this->newLine();
        $this->info('CSV de actualizaciones: '.$ruta);
        $this->line('Incluye '.number_format(count($actualizaciones), 0, ',', '.').' inmueble(s).');
    }

    /** @param list<array<string, mixed>> $actualizaciones */
    private function guardarCsvActualizaciones(array $actualizaciones): string
    {
        $directorio = storage_path('app/reportes');
        File::ensureDirectoryExists($directorio);
        $ruta = $directorio.'/inmuebles_cobol_actualizaciones_'.now()->format('Ymd_His').'.csv';
        $archivo = fopen($ruta, 'wb');

        if ($archivo === false) {
            throw new RuntimeException('No se pudo crear el informe '.$ruta);
        }

        try {
            fwrite($archivo, "\xEF\xBB\xBF");
            fputcsv($archivo, [
                'inmueble_id',
                'cuenta_inquilino',
                'cuenta_propietario',
                'direccion_fuente',
                'actualiza_datos_maestros',
                'campos_modificados',
                'cambios_json',
            ], ';', '"', '');

            foreach ($actualizaciones as $fila) {
                fputcsv($archivo, [
                    $fila['inmueble_id'] ?? '',
                    $fila['cuenta_inquilino'] ?? '',
                    $fila['cuenta_propietario'] ?? '',
                    $fila['direccion_fuente'] ?? '',
                    ! empty($fila['actualiza_datos_maestros']) ? 'SI' : 'NO',
                    implode(',', array_keys($fila['cambios'] ?? [])),
                    json_encode($fila['cambios'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ], ';', '"', '');
            }
        } finally {
            fclose($archivo);
        }

        return $ruta;
    }

    /** @param list<array<string, mixed>> $incidencias */
    private function mostrarDetalles(array $incidencias): void
    {
        $this->newLine();
        $this->components->info('Muestra de conflictos de inmuebles (primeros 20)');

        if ($incidencias === []) {
            $this->line('No hubo conflictos.');
        } else {
            $this->table(
                ['Motivo', 'Cta. propietario', 'Cta. inquilino', 'Dirección'],
                array_map(fn (array $fila): array => [
                    $fila['motivo'],
                    $fila['cuenta_propietario'] ?? '',
                    $fila['cuenta_inquilino'] ?? '',
                    $fila['direccion'] ?? '',
                ], array_slice($incidencias, 0, 20))
            );
        }

        $ruta = $this->guardarCsv($incidencias);
        $this->newLine();
        $this->info('CSV completo: '.$ruta);
        $this->line('Incluye '.number_format(count($incidencias), 0, ',', '.').' conflictos.');
    }

    /** @param list<array<string, mixed>> $incidencias */
    private function guardarCsv(array $incidencias): string
    {
        $directorio = storage_path('app/reportes');
        File::ensureDirectoryExists($directorio);
        $ruta = $directorio.'/inmuebles_cobol_detalles_'.now()->format('Ymd_His').'.csv';
        $archivo = fopen($ruta, 'wb');

        if ($archivo === false) {
            throw new RuntimeException('No se pudo crear el informe '.$ruta);
        }

        try {
            fwrite($archivo, "\xEF\xBB\xBF");
            fputcsv($archivo, [
                'tipo',
                'motivo',
                'cuenta_propietario',
                'cuenta_inquilino',
                'direccion',
                'clave_inmueble',
                'detalle',
            ], ';', '"', '');

            foreach ($incidencias as $fila) {
                fputcsv($archivo, [
                    $fila['tipo'] ?? 'CONFLICTO',
                    $fila['motivo'] ?? '',
                    $fila['cuenta_propietario'] ?? '',
                    $fila['cuenta_inquilino'] ?? '',
                    $fila['direccion'] ?? '',
                    $fila['clave_inmueble'] ?? '',
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
}
