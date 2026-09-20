<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class GenerarIncidenciasPeriodo extends Command
{
    protected $signature = 'gei:generar-incidencias-periodo
                            {periodo : Período YYYYMM}
                            {--force : Reemplaza incidencias_importacion.json si ya existe}';

    protected $description = 'Regenera las incidencias de fechas futuras de INQCTACTE para un período ya migrado';

    public function handle(): int
    {
        $periodo = trim((string) $this->argument('periodo'));

        if (preg_match('/^(19|20)\\d{2}(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            $this->error('Período inválido. Debe tener formato YYYYMM.');
            return self::FAILURE;
        }

        $ruta = "liquidaciones/periodos/{$periodo}/incidencias_importacion.json";

        if (Storage::exists($ruta) && ! $this->option('force')) {
            $this->error("Ya existe {$ruta}.");
            $this->line('Usá --force sólo si querés regenerarlo.');
            return self::FAILURE;
        }

        try {
            $db = $this->conexionExploracion();
            $schema = $this->schemaOrigen();
            $archivoId = $this->archivoInqctacteDelPeriodo($db, $schema, $periodo);
            $yaInformadas = $this->incidenciasYaInformadas($periodo);

            $filas = $db->select(
                "select
                    numero_linea,
                    btrim(cuenta::text) as cuenta,
                    btrim(fecha::text) as fecha,
                    btrim(codigo::text) as codigo,
                    btrim(numero::text) as numero,
                    btrim(fecha_vencimiento::text) as fecha_vencimiento
                 from {$schema}.inqctacte
                 where archivo_id = ?
                 order by numero_linea",
                [$archivoId]
            );

            $incidenciasDetectadas = [];
            $invalidasDetectadas = [];

            foreach ($filas as $fila) {
                $vencimiento = trim((string) ($fila->fecha_vencimiento ?? ''));

                if ($vencimiento === '' || $vencimiento === '00000000') {
                    continue;
                }

                if (! $this->esFechaCobolValida($vencimiento)) {
                    $invalidasDetectadas[] = [
                        'linea' => isset($fila->numero_linea) ? (int) $fila->numero_linea : null,
                        'cuenta_cobol' => trim((string) ($fila->cuenta ?? '')),
                        'fecha_movimiento' => trim((string) ($fila->fecha ?? '')),
                        'codigo' => trim((string) ($fila->codigo ?? '')),
                        'numero_cobol' => trim((string) ($fila->numero ?? '')),
                        'fecha_vencimiento' => $vencimiento,
                        'motivo' => $this->motivoFechaInvalida($vencimiento),
                    ];
                    continue;
                }

                $periodoVencimiento = substr($vencimiento, 0, 6);

                if ($periodoVencimiento <= $periodo) {
                    continue;
                }

                $incidenciasDetectadas[] = [
                    'linea' => isset($fila->numero_linea) ? (int) $fila->numero_linea : null,
                    'cuenta_cobol' => trim((string) ($fila->cuenta ?? '')),
                    'fecha_movimiento' => trim((string) ($fila->fecha ?? '')),
                    'codigo' => trim((string) ($fila->codigo ?? '')),
                    'numero_cobol' => trim((string) ($fila->numero ?? '')),
                    'fecha_vencimiento' => $vencimiento,
                    'periodo_vencimiento' => $periodoVencimiento,
                ];
            }

            $incidencias = array_values(array_filter(
                $incidenciasDetectadas,
                fn (array $fila): bool => ! isset(
                    $yaInformadas[$this->fingerprintIncidencia($fila)]
                )
            ));

            $invalidas = array_values(array_filter(
                $invalidasDetectadas,
                fn (array $fila): bool => ! isset(
                    $yaInformadas[$this->fingerprintIncidencia($fila)]
                )
            ));

            $cuentas = count(array_unique(array_filter(array_merge(
                array_column($incidencias, 'cuenta_cobol'),
                array_column($invalidas, 'cuenta_cobol')
            ))));

            $contenido = [
                'periodo' => $periodo,
                'generado_desde' => 'cobol_staging.inqctacte',
                'archivo_id' => $archivoId,
                'generado_en' => now()->toIso8601String(),
                'criterio' => 'Sólo incidencias nuevas respecto de períodos anteriores ya informados.',
                'resumen' => [
                    'fechas_futuras_detectadas' => count($incidenciasDetectadas),
                    'fechas_futuras_nuevas' => count($incidencias),
                    'fechas_futuras_repetidas_omitidas' =>
                        count($incidenciasDetectadas) - count($incidencias),
                    'fechas_invalidas_detectadas' => count($invalidasDetectadas),
                    'fechas_invalidas_nuevas' => count($invalidas),
                    'fechas_invalidas_repetidas_omitidas' =>
                        count($invalidasDetectadas) - count($invalidas),
                    'cuentas_con_incidencias_nuevas' => $cuentas,
                ],
                'fechas_futuras_inqctacte' => $incidencias,
                'fechas_invalidas_inqctacte' => $invalidas,
            ];

            Storage::makeDirectory("liquidaciones/periodos/{$periodo}");

            Storage::put(
                $ruta,
                json_encode(
                    $contenido,
                    JSON_PRETTY_PRINT
                    | JSON_UNESCAPED_UNICODE
                    | JSON_UNESCAPED_SLASHES
                    | JSON_THROW_ON_ERROR
                ).PHP_EOL
            );

            if (! Storage::exists($ruta)) {
                throw new RuntimeException("No se pudo verificar la creación de {$ruta}.");
            }

            $this->info("Incidencias regeneradas para {$periodo}.");
            $this->line("Archivo INQCTACTE: {$archivoId}");
            $this->line('Fechas futuras detectadas: '.count($incidenciasDetectadas));
            $this->line('Fechas futuras nuevas: '.count($incidencias));
            $this->line(
                'Fechas futuras repetidas omitidas: '.
                (count($incidenciasDetectadas) - count($incidencias))
            );
            $this->line('Fechas inválidas detectadas: '.count($invalidasDetectadas));
            $this->line('Fechas inválidas nuevas: '.count($invalidas));
            $this->line(
                'Fechas inválidas repetidas omitidas: '.
                (count($invalidasDetectadas) - count($invalidas))
            );
            $this->line("Cuentas con incidencias nuevas: {$cuentas}");
            $this->line('Destino: '.Storage::path($ruta));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            report($e);
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }

    private function conexionExploracion(): Connection
    {
        $base = config('database.connections.pgsql');

        if (! is_array($base)) {
            throw new RuntimeException('No existe la conexión PostgreSQL base.');
        }

        $base['host'] = config('gei.exploracion.host');
        $base['port'] = config('gei.exploracion.port');
        $base['database'] = config('gei.exploracion.database');
        $base['username'] = config('gei.exploracion.username');
        $base['password'] = config('gei.exploracion.password');
        $base['search_path'] = 'public';

        config(['database.connections.gei_exploracion' => $base]);

        DB::purge('gei_exploracion');

        return DB::connection('gei_exploracion');
    }

    private function schemaOrigen(): string
    {
        $schema = trim((string) config('gei.exploracion.schema', 'cobol_staging'));

        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $schema) !== 1) {
            throw new RuntimeException('El esquema de exploración configurado no es válido.');
        }

        return $schema;
    }

    private function archivoInqctacteDelPeriodo(
        Connection $db,
        string $schema,
        string $periodo
    ): int {
        $fila = $db->selectOne(
            "select mpa.archivo_id
               from {$schema}.migraciones_periodos mp
               join {$schema}.migraciones_periodos_archivos mpa
                 on mpa.migracion_id = mp.id
              where mp.periodo = ?
                and mp.estado = 'OK'
                and mpa.grupo = 'cobol'
                and mpa.procesado_en_tabla = 'inqctacte'
              order by mp.iniciada_en desc, mp.id desc
              limit 1",
            [$periodo]
        );

        $archivoId = (int) ($fila->archivo_id ?? 0);

        if ($archivoId <= 0) {
            throw new RuntimeException(
                "No se encontró INQCTACTE migrado correctamente para {$periodo}."
            );
        }

        return $archivoId;
    }


    /**
     * @return array<string,true>
     */
    private function incidenciasYaInformadas(string $periodo): array
    {
        $base = 'liquidaciones/periodos';
        $fingerprints = [];

        foreach (Storage::directories($base) as $directorio) {
            $periodoAnterior = basename($directorio);

            if (
                preg_match('/^(19|20)\d{2}(0[1-9]|1[0-2])$/', $periodoAnterior) !== 1
                || $periodoAnterior >= $periodo
            ) {
                continue;
            }

            $ruta = "{$directorio}/incidencias_importacion.json";

            if (! Storage::exists($ruta)) {
                continue;
            }

            try {
                $contenido = json_decode(
                    Storage::get($ruta),
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            } catch (\Throwable) {
                continue;
            }

            foreach (['fechas_futuras_inqctacte', 'fechas_invalidas_inqctacte'] as $clave) {
                $filas = $contenido[$clave] ?? [];

                if (! is_array($filas)) {
                    continue;
                }

                foreach ($filas as $fila) {
                    if (! is_array($fila)) {
                        continue;
                    }

                    $fingerprints[$this->fingerprintIncidencia($fila)] = true;
                }
            }
        }

        return $fingerprints;
    }

    private function fingerprintIncidencia(array $fila): string
    {
        return implode('|', [
            trim((string) ($fila['cuenta_cobol'] ?? '')),
            trim((string) ($fila['fecha_movimiento'] ?? '')),
            trim((string) ($fila['codigo'] ?? '')),
            trim((string) ($fila['numero_cobol'] ?? '')),
            trim((string) ($fila['fecha_vencimiento'] ?? '')),
        ]);
    }

    private function motivoFechaInvalida(string $fecha): string
    {
        if ($fecha === '22200612') {
            return 'Valor erróneo conocido en INQCTACTE';
        }

        if (preg_match('/^\d{8}$/', $fecha) !== 1) {
            return 'El valor no tiene formato YYYYMMDD';
        }

        $anio = (int) substr($fecha, 0, 4);
        $mes = (int) substr($fecha, 4, 2);
        $dia = (int) substr($fecha, 6, 2);

        if ($anio < 2000 || $anio > 2100) {
            return 'Año fuera de rango';
        }

        if (! checkdate($mes, $dia, $anio)) {
            return 'Fecha calendario inválida';
        }

        return 'Fecha inválida';
    }

    private function esFechaCobolValida(string $fecha): bool
    {
        if ($fecha === '22200612' || preg_match('/^\\d{8}$/', $fecha) !== 1) {
            return false;
        }

        $anio = (int) substr($fecha, 0, 4);
        $mes = (int) substr($fecha, 4, 2);
        $dia = (int) substr($fecha, 6, 2);

        return $anio >= 2000
            && $anio <= 2100
            && checkdate($mes, $dia, $anio);
    }
}
